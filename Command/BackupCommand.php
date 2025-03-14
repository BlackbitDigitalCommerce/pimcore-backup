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
use Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
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
            ->addArgument('filename', InputArgument::OPTIONAL, 'File name. If you provide an absolute path here (beginning with /) then the configured Flysystem adapter in service "blackbit.backup.adapter" get bypassed and instead the file gets created in the given directory. If you omit the file name, it will get automatically generated.')
            ->addOption('skip-versions', null, InputOption::VALUE_NONE, 'Skip version files')
            ->addOption('skip-assets', null, InputOption::VALUE_NONE, 'Skip asset files')
            ->addOption('only-database', null, InputOption::VALUE_NONE, 'Only create database dump')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'files and directories to be ignored, you can provide multiple paths to be ignored with --exclude path/1 --exclude path/2');
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

        if(strpos($targetFilename, '/') === 0) {
            try {
                $this->filesystem = new Filesystem(new LocalFilesystemAdapter(dirname($targetFilename)));
            } catch(\Throwable $e) {
                $this->filesystem = new Filesystem(new \League\Flysystem\Adapter\Local(dirname($targetFilename)));
            }
            $targetFilename = basename($targetFilename);
        }

        if(empty($targetFilename) || substr($targetFilename, -1) === '/') {
            $targetFilename = 'backup_pimcore-'.date('YmdHi').'.tar.gz';
        } elseif(substr($targetFilename, -strlen('.tar.gz')) !== '.tar.gz') {
            $targetFilename .= '.tar.gz';
        }

        $command = 'mysqldump --help';
        $columnStatisticsSupportedCommand = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($command) : new Process($command);
        $columnStatisticsSupportedCommand->run();
        $columnStatisticsSupported = strpos($columnStatisticsSupportedCommand->getOutput(), '--column-statistics') !== false;
        $purgeIdSupported = strpos($columnStatisticsSupportedCommand->getOutput(), '--set-gtid-purged') !== false;

        $dbParams = $this->connection->getParams();

        $dumpDatabaseStructureCommand = 'mysql -u '.$dbParams['user'].' --password='.$dbParams['password'].' -h '.$dbParams['host'].' '.$dbParams['dbname'].' -e \'SHOW TABLES WHERE `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "application_logs_%" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "PLEASE_DELETE%"\' | grep -v Tables_in | xargs mysqldump'.($columnStatisticsSupported ? ' --column-statistics=0' : '').' --no-data '.($purgeIdSupported ? '--set-gtid-purged=OFF' : '').' --skip-triggers --routines -u '.$dbParams['user'].' --password='.$dbParams['password'].' -h '.$dbParams['host'].' '.$dbParams['dbname'].' -r '.$tmpDatabaseDump;

        $dumpDatabaseDataCommand = 'mysql -u '.$dbParams['user'].' --password='.$dbParams['password'].' -h '.$dbParams['host'].' '.$dbParams['dbname'].' -e \'SHOW TABLES WHERE `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "application_logs_%" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "PLEASE_DELETE%" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "application_logs" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "edit_lock" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "cache" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "cache_tags" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "email_log" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "http_error_log" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "search_backend_data" AND `Tables_in_'.$dbParams['dbname'].'` NOT LIKE "tmp_store"\' | grep -v Tables_in | xargs mysqldump'.($columnStatisticsSupported ? ' --column-statistics=0' : '').' --no-create-info --skip-triggers '.($purgeIdSupported ? '--set-gtid-purged=OFF' : '').' -u '.$dbParams['user'].' --password='.$dbParams['password'].' -h '.$dbParams['host'].' '.$dbParams['dbname'].' >> '.$tmpDatabaseDump.' && sed -i \'/^\/\*!50013 /d\' '.$tmpDatabaseDump.' && sed -i \'s/ALGORITHM.*VIEW/VIEW/g\' '.$tmpDatabaseDump.' && sed -i \'s/\sDEFINER=`[^`]*`@`[^`]*`//g\' '.$tmpDatabaseDump;

        $addDumpToTarCommand = 'mv '.$tmpDatabaseDump.' '.PIMCORE_PROJECT_ROOT.'/backup.sql';

        $ignoreFilesOption = array_filter($input->getOption('exclude'));
        $ignoreFiles = [];

        array_map(static function($path) use (&$ignoreFiles) {
            $ignoreFiles = array_merge($ignoreFiles, explode(',', $path));
        }, $ignoreFilesOption);

        $ignoreFiles[] = 'app/config/local';
        $ignoreFiles[] = str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_CACHE_DIRECTORY);
        $ignoreFiles[] = str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_SYMFONY_CACHE_DIRECTORY);
        $ignoreFiles[] = str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_WEB_ROOT).'/var/tmp';
        $ignoreFiles[] = str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_SYSTEM_TEMP_DIRECTORY);
        $ignoreFiles[] = str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_PRIVATE_VAR).'/sessions';
        $ignoreFiles[] = str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_LOG_FILEOBJECT_DIRECTORY);
        $ignoreFiles[] = str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_LOG_DIRECTORY);
        $ignoreFiles = array_map(static function($path) {
            return '--exclude "'.$path.'"';
        }, $ignoreFiles);

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
            ]
        ];

        if(!$input->getOption('only-database')) {
            $tarFilesCommand = 'tar '.implode(' ', $ignoreFiles).' '.($input->getOption('skip-versions') ? ' --exclude='.str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_PRIVATE_VAR).'/versions' : '').($input->getOption('skip-assets') ? ' --exclude='.str_replace(PIMCORE_PROJECT_ROOT.'/', '', PIMCORE_PRIVATE_VAR).'/assets' : '').' --warning=no-file-changed -czf '.$tmpArchiveFilepath.'.gz -C '.PIMCORE_PROJECT_ROOT.' .';
            $steps[] = [
                'description' => 'backup files of entire project root, excluding temporary files',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($tarFilesCommand, null, null, null, null) : new Process($tarFilesCommand, null, null, null, null)
            ];
        } else {
            $tarFilesCommand = 'tar --warning=no-file-changed -czf '.$tmpArchiveFilepath.'.gz -C '.PIMCORE_PROJECT_ROOT.' backup.sql';
            $steps[] = [
                'description' => 'zip database dump',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($tarFilesCommand, null, null, null, null) : new Process($tarFilesCommand, null, null, null, null)
            ];
        }

        $steps[] = [
            'description' => 'save backup to '.$targetFilename,
            'cmd' => new class($this->filesystem, $targetFilename, $tmpArchiveFilepath.'.gz', $output) {
                /** @var Filesystem */
                private $fileSystem;

                private $targetFilename;

                private $archiveFilePath;

                private $successful = false;

                private $output;

                public function __construct(Filesystem $fileSystem, $targetFilename, $tmpArchiveFilepath, $output)
                {
                    $this->fileSystem = $fileSystem;
                    $this->archiveFilePath = $tmpArchiveFilepath;
                    $this->targetFilename = $targetFilename;
                    $this->output = $output;
                }

                public function run($callback = null/*, array $env = array()*/)
                {
                    $stream = fopen($this->archiveFilePath, 'rb');
                    try {
                        $this->successful = true;

                        try {
                            $this->fileSystem->writeStream($this->targetFilename, $stream);
                        } catch(\Exception $e) {
                            if(method_exists($this->fileSystem, 'putStream')) {
                                $this->fileSystem->putStream($this->targetFilename, $stream);
                            } else {
                                throw $e;
                            }
                        }
                    } catch (Exception $e) {
                        $this->output->writeln($e->getMessage());
                        $this->successful = false;
                    }
                }

                public function isSuccessful()
                {
                    return $this->successful;
                }

                public function getCommandLine() {
                    return '';
                }
            }
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

