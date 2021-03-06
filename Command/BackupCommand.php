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

use blackbit\BackupBundle\Tools\ParallelProcess;
use League\Flysystem\Filesystem;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class BackupCommand extends StorageCommand
{
    protected function configure()
    {
        $this
            ->setName('backup:backup')
            ->setDescription('Backup all data')
            ->addArgument('filename', InputArgument::OPTIONAL, 'file name')
            ->addOption('skip-versions', null, InputOption::VALUE_NONE, 'Skip version files')
            ->addOption('skip-assets', null, InputOption::VALUE_NONE, 'Skip asset files');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        set_time_limit(0);
        $tmpFilename = \uniqid('', true);

        $tmpDirectory = PIMCORE_SYSTEM_TEMP_DIRECTORY;
        if(disk_free_space($tmpDirectory) < disk_free_space(sys_get_temp_dir())) {
            $tmpDirectory = sys_get_temp_dir();
        }

        $tmpArchiveFilepath = $tmpDirectory.'/'.$tmpFilename.'.tar';
        $tmpDatabaseDump = $tmpDirectory.'/'.$tmpFilename.'.sql';

        $targetFilename = $input->getArgument('filename');
        if(empty($targetFilename)) {
            $targetFilename = 'backup_pimcore-'.date('YmdHi').'.tar.gz';
        }

        $command = 'mysqldump --help';
        $columnStatisticsSupportedCommand = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($command) : new Process($command);
        $columnStatisticsSupportedCommand->run();
        $columnStatisticsSupported = strpos($columnStatisticsSupportedCommand->getOutput(), '--column-statistics') !== false;

        $dumpDatabaseStructureCommand = 'mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -e \'SHOW TABLES WHERE `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs_%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "PLEASE_DELETE%"\' | grep -v Tables_in | xargs mysqldump'.($columnStatisticsSupported ? ' --column-statistics=0' : '').' --no-data --routines -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -r '.$tmpDatabaseDump;

        $dumpDatabaseDataCommand = 'mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -e \'SHOW TABLES WHERE `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs_%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "PLEASE_DELETE%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "cache" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "cache_tags" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "email_log" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "http_error_log" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "search_backend_data" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "tmp_store"\' | grep -v Tables_in | xargs mysqldump'.($columnStatisticsSupported ? ' --column-statistics=0' : '').' --no-create-info --skip-triggers -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' >> '.$tmpDatabaseDump;

        $addDumpToTarCommand = 'mv '.$tmpDatabaseDump.' '.PIMCORE_PROJECT_ROOT.'/backup.sql';

        $tarFilesCommand = 'tar --exclude=web/var/tmp --exclude=var/tmp --exclude=var/logs --exclude=var/cache --exclude=var/sessions --exclude=var/application_logger'.($input->getOption('skip-versions') ? ' --exclude=var/versions' : '').($input->getOption('skip-assets') ? ' --exclude=var/assets' : '').' --warning=no-file-changed -czf '.$tmpArchiveFilepath.'.gz -C '.PIMCORE_PROJECT_ROOT.' .';

        $steps = [
            [
                'description' => 'dump database structure',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($dumpDatabaseStructureCommand, null, null, null, null) : new Process($dumpDatabaseStructureCommand, null, null, null, null)
            ],
            [
                'description' => 'dump database content',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($dumpDatabaseDataCommand, null, null, null, null) : new Process($dumpDatabaseDataCommand, null, null, null, null)
            ],
            [
                'description' => 'put the dump into the tar archive',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($addDumpToTarCommand, null, null, null, null) : new Process($addDumpToTarCommand, null, null, null, null)

            ],
            [
                'description' => 'backup files of entire project root, excluding temporary files',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($tarFilesCommand, null, null, null, null) : new Process($tarFilesCommand, null, null, null, null)
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
                        $this->successful = $this->fileSystem->putStream($this->targetFilename, $stream);
                    }

                    public function isSuccessful() {
                        return $this->successful;
                    }
                }
            ]
        ];

        $progressBar = new ProgressBar($output, \count($steps) + 1); // +1 because of cleanup step in finally block
        $progressBar->setMessage('Starting ...');
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%%, %message%');
        $progressBar->start();
        try {
            foreach ($steps as $step) {
                $progressBar->setMessage($step['description'].' ...');
                $progressBar->advance();

                /** @var Process|ParallelProcess $command */
                $command = $step['cmd'];
                $command->run();

                if (!$command->isSuccessful() && (strpos($step['cmd']->getCommandLine(), 'tar') !== 0 || $command->getExitCode() !== 1)) {
                    if ($step['cmd'] instanceof Process) {
                        throw new ProcessFailedException($step['cmd']);
                    }

                    throw new \Exception($step['description'].' failed');
                }
            }
        } finally {
            $progressBar->setMessage('Remove temporary files ...');
            $progressBar->advance();

            $command = 'rm -f '.$tmpDatabaseDump.' '.$tmpArchiveFilepath.' '.$tmpArchiveFilepath.'.gz '.PIMCORE_PROJECT_ROOT.'/backup.sql';
            $cleanupProcess = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($command, null, null, null, null) : new Process($command, null, null, null, null);
            $cleanupProcess->run();
        }

        $progressBar->finish();

        $output->writeln('Backup successfully created');
        return 0;
    }
}

