<?php
/**
 * Copyright Blackbit digital Commerce GmbH <info@blackbit.de>
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA
 */

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
        set_time_limit(0);
        $tmpFileName = \uniqid('', true);

        $tmpDirectory = PIMCORE_SYSTEM_TEMP_DIRECTORY;
        if (disk_free_space($tmpDirectory) < disk_free_space(sys_get_temp_dir())) {
            $tmpDirectory = sys_get_temp_dir();
        }

        $tmpArchiveFilepath = $tmpDirectory.'/'.$tmpFileName.'.tar.gz';

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
                'cmd' =>
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('tar -xzf "'.$tmpArchiveFilepath.'" -C "'.PIMCORE_PROJECT_ROOT.'"', null, null, null, null) : new Process('tar -xzf "'.$tmpArchiveFilepath.'" -C '.PIMCORE_PROJECT_ROOT, null, null, null, null),

            ],
            [
                'description' => 'restore database',
                'cmd' =>
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' < '.PIMCORE_PROJECT_ROOT.'/backup.sql', null, null, null, null) : new Process('mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' < '.PIMCORE_PROJECT_ROOT.'/backup.sql', null, null, null, null)
            ],
            [
                'description' => 'remove temporary files / clear cache',
                'cmd' => new ParallelProcess(
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('rm '.PIMCORE_PROJECT_ROOT.'/backup.sql '.$tmpArchiveFilepath, null, null, null, null) : new Process('rm '.PIMCORE_PROJECT_ROOT.'/backup.sql '.$tmpArchiveFilepath, null, null, null, null),
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console cache:clear', null, null, null, null) : new Process(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console cache:clear', null, null, null, null),
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:cache:clear', null, null, null, null) : new Process(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:cache:clear', null, null, null, null)

                )
            ],
            [
                'description' => 'rebuild backend search index',
                'cmd' =>
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:search-backend-reindex', null, null, null, null) : new Process(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:search-backend-reindex', null, null, null, null)
            ],
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

        return 0;
    }
}

