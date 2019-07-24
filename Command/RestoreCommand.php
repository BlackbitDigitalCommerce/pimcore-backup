<?php
namespace blackbit\BackupBundle\Command;

use AppBundle\Model\Object\Person;
use blackbit\BackupBundle\Tools\ParallelProcess;
use League\Flysystem\Filesystem;
use Pimcore\Console\AbstractCommand;
use Pimcore\Tool\Console;
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
            ->setName('backup:restore')
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
                    private $successful = false;

                    public function __construct(Filesystem $fileSystem, $sourceFilename, $tmpArchiveFilepath)
                    {
                        $this->fileSystem = $fileSystem;
                        $this->archiveFilePath = $tmpArchiveFilepath;
                        $this->sourceFilename = $sourceFilename;
                    }

                    public function run()
                    {
                        $stream = $this->fileSystem->readStream($this->sourceFilename);
                        $this->successful = (bool) \file_put_contents($this->archiveFilePath, $stream);
                    }

                    public function isSuccessful(): bool
                    {
                        return $this->successful;
                    }
                }
            ],
            [
                'description' => 'unzip backup to '.PIMCORE_PROJECT_ROOT,
                'cmd' => Process::fromShellCommandline('tar -xzf "'.$tmpArchiveFilepath.'" -C '.PIMCORE_PROJECT_ROOT)
            ],
            [
                'description' => 'restore database',
                'cmd' => Process::fromShellCommandline('mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' < '.PIMCORE_PROJECT_ROOT.'/backup.sql')
            ],
            [
                'description' => 'remove temporary files / clear cache',
                'cmd' => new ParallelProcess(
                    Process::fromShellCommandline('rm '.PIMCORE_PROJECT_ROOT.'/backup.sql '.$tmpArchiveFilepath),
                    Process::fromShellCommandline(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console cache:clear'),
                    Process::fromShellCommandline(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:cache:clear')
                )
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
                throw new ProcessFailedException($step['cmd']);
            }
        }

        $progressBar->finish();

        $output->writeln('Backup successfully restored');
    }
}

