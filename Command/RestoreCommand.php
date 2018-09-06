<?php
/**
 *
 *
 * @author    Patrick Bitzer <patrick.bitzer@blackbit.de>
 * @copyright Blackbit digital Commerce GmbH, https://www.blackbit.de/
 */
namespace blackbit\BackupBundle\Command;

use AppBundle\Model\Object\Person;
use blackbit\BackupBundle\Tools\ParallelProcessComposite;
use League\Flysystem\Filesystem;
use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RestoreCommand extends StorageCommand
{
    protected function configure()
    {
        $this
            ->setName('app:restore')
            ->setDescription('Restore backup')
            ->addArgument('filename', InputArgument::REQUIRED, 'path to backup archive file created by app:backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tmpFileName = \uniqid('', true);
        $tmpArchiveFilepath = PIMCORE_SYSTEM_TEMP_DIRECTORY.'/'.$tmpFileName.'.tar.gz';

        $fileName = $input->getArgument('filename');

        $steps = [
            [
                'description' => 'downloading backup to '.$tmpArchiveFilepath,
                'cmd' => new class($this->filesystem, $fileName, $tmpArchiveFilepath) {
                    /** @var Filesystem */
                    private $fileSystem;

                    private $sourceFilename;

                    private $archiveFilePath;

                    public function __construct(Filesystem $fileSystem, $sourceFilename, $tmpArchiveFilepath)
                    {
                        $this->fileSystem = $fileSystem;
                        $this->archiveFilePath = $tmpArchiveFilepath;
                        $this->sourceFilename = $sourceFilename;
                    }

                    public function run()
                    {
                        $stream = $this->fileSystem->readStream($this->sourceFilename);
                        $this->fileSystem->putStream($this->archiveFilePath, $stream);
                    }
                }
            ],
            [
                'description' => 'unzip backup to '.PIMCORE_PROJECT_ROOT.' / restore database (in parallel)',
                'cmd' => new ParallelProcessComposite(
                    new Process('tar -xzf "'.$tmpArchiveFilepath.'" -C '.PIMCORE_PROJECT_ROOT),
                    new Process('mysql -u '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.username').' --password='.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.password').' -h '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.host').' '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.dbname').' < '.PIMCORE_PROJECT_ROOT.'/backup.sql')
                )
            ],
            [
                'description' => 'remove database dump file',
                'cmd' => new Process('rm '.PIMCORE_PROJECT_ROOT.'/backup.sql')
            ],
            [
                'description' => 'clear cache',
                'cmd' => new Process(PIMCORE_PROJECT_ROOT.'/bin/console cache:clear')
            ],
            [
                'description' => 'remove temporary file',
                'cmd' => new Process('rm '.$tmpArchiveFilepath)
            ]
        ];

        $progressBar = new ProgressBar($output, \count($steps));
        $progressBar->setMessage('Starting ...');
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%%, %message%');
        $progressBar->start();
        foreach ($steps as $step) {
            $progressBar->setMessage($step['description'].' ...');
            $progressBar->advance();

            /** @var Process $command */
            $command = $step['cmd'];
            $command->run();

            if (!$command->isSuccessful()) {
                throw new ProcessFailedException($command);
            }
        }

        $progressBar->finish();
    }
}

