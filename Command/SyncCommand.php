<?php


namespace blackbit\BackupBundle\Command;


use blackbit\BackupBundle\Tools\ParallelProcess;
use Pimcore\Console\AbstractCommand;
use Pimcore\Tool\Console;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SyncCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('backup:sync')
            ->setDescription('Sync data from another Pimcore system')
            ->addArgument('ssh-handle', InputArgument::REQUIRED, 'SSH handle to connect to other Pimcore system, e.g. user@domain.com - you have to be able to connect from here via ssh user@domain.com')
            ->addArgument('remote-root-path', InputArgument::REQUIRED, 'Pimcore root path on remote system, e.g. /var/www/html'
            );;

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        set_time_limit(0);

        $tmpDirectory = PIMCORE_SYSTEM_TEMP_DIRECTORY;
        if (disk_free_space($tmpDirectory) < disk_free_space(sys_get_temp_dir())) {
            $tmpDirectory = sys_get_temp_dir();
        }

        $sshHandle = $input->getArgument('ssh-handle');
        if(strpos($sshHandle, 'ssh ') === 0) {
            $sshHandle = substr($sshHandle, strlen('ssh '));
        }
        $createBackupCommand = 'ssh '.$sshHandle.' "'.rtrim($input->getArgument('remote-root-path'), '/').'/bin/console backup:backup /tmp/pimcore-backup-sync.tar.gz --only-database"';
        $copyFilesCommand = 'rsync -avz --delete --exclude="app/config/local" --exclude="var/cache" --exclude="web/var/tmp" --exclude="var/tmp" --exclude="var/sessions" '.$sshHandle.':'.$input->getArgument('remote-root-path').' '.PIMCORE_PROJECT_ROOT;

        $steps = [
            [
                'description' => 'fetch database dump from source system',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($createBackupCommand, null, null, null, null) : new Process($createBackupCommand, null, null, null, null)
            ],
            [
                'description' => 'fetch files from remote system',
                'cmd' => method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($copyFilesCommand, null, null, null, null) : new Process($copyFilesCommand, null, null, null, null)
            ],
            [
                'description' => 'clear cache',
                'cmd' => new ParallelProcess(
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console cache:clear', null, null, null, null) : new Process(
                        Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console cache:clear', null, null, null, null
                    ),
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:cache:clear', null, null, null, null) : new Process(
                        Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:cache:clear', null, null, null, null
                    )

                )
            ],
            [
                'description' => 'rebuild backend search index',
                'cmd' =>
                    method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline(Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:search-backend-reindex', null, null, null, null) : new Process(
                        Console::getExecutable('php').' '.PIMCORE_PROJECT_ROOT.'/bin/console pimcore:search-backend-reindex', null, null, null, null
                    )
            ],
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
            $progressBar->setMessage('Clean up ...');
            $progressBar->advance();

            $cleanupCommand = 'rm -r '.$tmpDirectory.'/pimcore-tmp';
            $cleanupProcess = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($cleanupCommand, null, null, null, null) : new Process($cleanupCommand, null, null, null, null);
            $cleanupProcess->run();

            $cleanupCommand = 'ssh '.$sshHandle.' rm /tmp/pimcore-backup-sync.tar.gz';
            $cleanupProcess = method_exists(Process::class, 'fromShellCommandline') ? Process::fromShellCommandline($cleanupCommand, null, null, null, null) : new Process($cleanupCommand, null, null, null, null);
            $cleanupProcess->run();
        }

        $progressBar->finish();

        $output->writeln('Backup successfully created');
        return 0;
    }
}