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
use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class BackupCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('app:backup')
            ->setDescription('Backup all data')
            ->addArgument('path', InputArgument::REQUIRED, 'path to backup tar.gz file');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tmpFilename = \uniqid();
        $tmpArchiveFilepath = '/tmp/'.$tmpFilename.'.tar';
        $tmpDatabaseDump = '/tmp/backup.sql';

        $targetFilename = $input->getArgument('path');
        if(\is_dir($targetFilename) || \substr($targetFilename, -1) === \DIRECTORY_SEPARATOR) {
            if(\substr($targetFilename, -1) !== \DIRECTORY_SEPARATOR) {
                $targetFilename .= \DIRECTORY_SEPARATOR;
            }
            $targetFilename .= date('YmdHi').'.tar.gz';
        }

        $steps = [
            [
                'description' => 'create an archive of the entire project root, excluding temporary files / dump database (parallel jobs)',
                'cmd' => new ParallelProcessComposite(
                    new Process('tar --exclude=web/var/tmp --exclude=var/tmp --exclude=var/logs --exclude=var/cache --exclude=var/sessions -cf '.$tmpArchiveFilepath.' -C '.PIMCORE_PROJECT_ROOT.' .'),
                    new Process('mysqldump -u '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.username').' --password='.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.password').' -h '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.host').' '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.dbname').' -r '.$tmpDatabaseDump)
                )
            ],
            [
                'description' => 'put the dump into the tar archive',
                'cmd' => new Process('tar -rf '.$tmpArchiveFilepath.' -C '.dirname($tmpDatabaseDump).' backup.sql')
            ],
            [
                'description' => 'zip the archive',
                'cmd' => new Process('gzip -c '.$tmpArchiveFilepath.' > "'.$targetFilename.'"')
            ],
            [
                'description' => 'Remove temporary files',
                'cmd' => new Process('rm '.$tmpArchiveFilepath.' '.$tmpDatabaseDump)
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

