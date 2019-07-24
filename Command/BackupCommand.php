<?php
/**
 *
 *
 * @author    Patrick Bitzer <patrick.bitzer@blackbit.de>
 * @copyright Blackbit digital Commerce GmbH, https://www.blackbit.de/
 */
namespace blackbit\BackupBundle\Command;

use blackbit\BackupBundle\Tools\ParallelProcess;
use Graze\ParallelProcess\Pool;
use League\Flysystem\Filesystem;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Pimcore\Tool\Console;

class BackupCommand extends StorageCommand
{
    protected function configure()
    {
        $this
            ->setName('backup:backup')
            ->setDescription('Backup all data')
            ->addArgument('filename', InputArgument::OPTIONAL, 'file name');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tmpFilename = \uniqid('', true);
        $tmpArchiveFilepath = PIMCORE_SYSTEM_TEMP_DIRECTORY.'/'.$tmpFilename.'.tar';
        $tmpDatabaseDump = PIMCORE_SYSTEM_TEMP_DIRECTORY.'/'.$tmpFilename.'.sql';

        $targetFilename = $input->getArgument('filename');
        if(empty($targetFilename)) {
            $targetFilename = 'backup_pimcore-'.date('YmdHi').'.tar.gz';
        }

        $steps = [
            [
                'description' => 'create an archive of the entire project root, excluding temporary files / dump database (parallel jobs)',
                'cmd' => new ParallelProcess(
                    new Process('tar --exclude=web/var/tmp --exclude=web/var/tmp --exclude=var/tmp --exclude=var/logs --exclude=var/cache --exclude=var/sessions -cf '.$tmpArchiveFilepath.' -C '.PIMCORE_PROJECT_ROOT.' .'),
                    new Process('mysqldump -u '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.username').' --password='.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.password').' -h '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.host').' '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.dbname').' -r '.$tmpDatabaseDump)
                )
            ],
            [
                'description' => 'put the dump into the tar archive',
                'cmd' => new Process('tar -rf '.$tmpArchiveFilepath.' -C '.dirname($tmpDatabaseDump).' '.$tmpFilename.'.sql --transform s/'.$tmpFilename.'.sql/backup.sql/')
            ],
            [
                'description' => 'zip the archive',
                'cmd' => new Process('gzip '.$tmpArchiveFilepath)
            ],
            [
                'description' => 'save backup to '.$targetFilename,
                'cmd' => new class($this->filesystem, $targetFilename, $tmpArchiveFilepath.'.gz') {
                    /** @var Filesystem */
                    private $fileSystem;

                    private $targetFilename;

                    private $archiveFilePath;

                    private $successful = false;

                    public function __construct(Filesystem $fileSystem, $targetFilename, $tmpArchiveFilepath)
                    {
                        $this->fileSystem = $fileSystem;
                        $this->archiveFilePath = $tmpArchiveFilepath;
                        $this->targetFilename = $targetFilename;
                    }

                    public function run($callback = null/*, array $env = array()*/)
                    {
                        $stream = fopen($this->archiveFilePath, 'rb');
                        $this->successful = $this->fileSystem->writeStream($this->targetFilename, $stream);
                    }

                    public function isSuccessful() {
                        return $this->successful;
                    }
                }
            ],
            [
                'description' => 'Remove temporary files',
                'cmd' => new Process('rm '.$tmpDatabaseDump.' '.$tmpArchiveFilepath.'.gz')
            ]
        ];

        $progressBar = new ProgressBar($output, \count($steps));
        $progressBar->setMessage('Starting ...');
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%%, %message%');
        $progressBar->start();
        foreach ($steps as $step) {
            $progressBar->setMessage($step['description'].' ...');
            $progressBar->advance();

            /** @var Process|ParallelProcess $command */
            $command = $step['cmd'];
            $command->run();

            if (!$command->isSuccessful()) {
                throw new ProcessFailedException($step['cmd']);
            }
        }

        $progressBar->finish();
    }
}

