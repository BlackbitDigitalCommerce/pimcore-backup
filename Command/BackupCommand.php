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
            ->addArgument('filename', InputArgument::OPTIONAL, 'file name');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        set_time_limit(0);
        $tmpFilename = \uniqid('', true);
        $tmpArchiveFilepath = PIMCORE_SYSTEM_TEMP_DIRECTORY.'/'.$tmpFilename.'.tar';
        $tmpDatabaseDump = PIMCORE_SYSTEM_TEMP_DIRECTORY.'/'.$tmpFilename.'.sql';

        $targetFilename = $input->getArgument('filename');
        if(empty($targetFilename)) {
            $targetFilename = 'backup_pimcore-'.date('YmdHi').'.tar.gz';
        }

        $addColumnStatisticOption = false;
        try {
            $this->connection->fetchOne('SELECT 1 FROM information_schema.COLUMN_STATISTICS WHERE SCHEMA_NAME = ? LIMIT 1', [$this->connection->getDatabase()]);
        } catch(\Exception $e) {
            $addColumnStatisticOption = true;
        }

        $steps = [
            [
                'description' => 'dump database structure',
                'cmd' => new ParallelProcess(
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -e \'SHOW TABLES WHERE `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs_%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "PLEASE_DELETE%"\' | grep -v Tables_in | xargs mysqldump'.($addColumnStatisticOption?' --column-statistics=0':'').' --no-data --routines -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -r '.$tmpDatabaseDump, null, null, null, null) : new Process('mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -e \'SHOW TABLES WHERE `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs_%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "PLEASE_DELETE%"\' | grep -v Tables_in | xargs mysqldump'.($addColumnStatisticOption ? ' --column-statistics=0' : '').' --no-data --routines -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -r '.$tmpDatabaseDump, null, null, null, null)
                )
            ],
            [
                'description' => 'dump database content / create an archive of the entire project root, excluding temporary files (parallel jobs)',
                'cmd' => new ParallelProcess(
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -e \'SHOW TABLES WHERE `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs_%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "PLEASE_DELETE%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "cache" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "cache_tags" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "email_log" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "http_error_log" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "search_backend_data" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "tmp_store"\' | grep -v Tables_in | xargs mysqldump'.($addColumnStatisticOption ? ' --column-statistics=0' : '').' --no-create-info --skip-triggers -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' >> '.$tmpDatabaseDump, null, null, null, null) : new Process('mysql -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' -e \'SHOW TABLES WHERE `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs_%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "PLEASE_DELETE%" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "application_logs" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "cache" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "cache_tags" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "email_log" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "http_error_log" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "search_backend_data" AND `Tables_in_'.$this->connection->getDatabase().'` NOT LIKE "tmp_store"\' | grep -v Tables_in | xargs mysqldump'.($addColumnStatisticOption ? ' --column-statistics=0' : '').' --no-create-info --skip-triggers -u '.$this->connection->getUsername().' --password='.$this->connection->getPassword().' -h '.$this->connection->getHost().' '.$this->connection->getDatabase().' >> '.$tmpDatabaseDump, null, null, null, null),
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('tar --exclude=web/var/tmp --exclude=web/var/tmp --exclude=var/tmp --exclude=var/logs --exclude=var/cache --exclude=var/sessions -cf '.$tmpArchiveFilepath.' -C '.PIMCORE_PROJECT_ROOT.' .', null, null, null, null) : new Process('tar --exclude=web/var/tmp --exclude=web/var/tmp --exclude=var/tmp --exclude=var/logs --exclude=var/cache --exclude=var/sessions -cf '.$tmpArchiveFilepath.' -C '.PIMCORE_PROJECT_ROOT.' .', null, null, null, null)
                )
            ],
            [
                'description' => 'put the dump into the tar archive',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('tar -rf '.$tmpArchiveFilepath.' -C '.dirname($tmpDatabaseDump).' '.$tmpFilename.'.sql --transform s/'.$tmpFilename.'.sql/backup.sql/', null, null, null, null) : new Process('tar -rf '.$tmpArchiveFilepath.' -C '.dirname($tmpDatabaseDump).' '.$tmpFilename.'.sql --transform s/'.$tmpFilename.'.sql/backup.sql/', null, null, null, null)

            ],
            [
                'description' => 'zip the archive',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('gzip '.$tmpArchiveFilepath, null, null, null, null) : new Process('gzip '.$tmpArchiveFilepath, null, null, null, null)
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
            ],
            [
                'description' => 'Remove temporary files',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline('rm '.$tmpDatabaseDump.' '.$tmpArchiveFilepath.'.gz', null, null, null, null) : new Process('rm '.$tmpDatabaseDump.' '.$tmpArchiveFilepath.'.gz', null, null, null, null)
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
                if($step['cmd'] instanceof Process) {
                    throw new ProcessFailedException($step['cmd']);
                }

                throw new \Exception($step['description'].' failed');
            }
        }

        $progressBar->finish();

        $output->writeln('Backup successfully created');
        return 0;
    }
}

