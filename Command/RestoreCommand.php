<?php
/**
 *
 *
 * @author    Patrick Bitzer <patrick.bitzer@blackbit.de>
 * @copyright Blackbit digital Commerce GmbH, https://www.blackbit.de/
 */
namespace blackbit\BackupBundle\Command;

use AppBundle\Model\Object\Person;
use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Command\Command;
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
            ->setName('app:restore')
            ->setDescription('Restore backup')
            ->addArgument('file', InputArgument::REQUIRED, 'path to backup archive file created by app:backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var Command $clearCacheCommand */
        $clearCacheCommand = $this->getApplication()->find('clear:cache');
        $steps = [
            [
                'description' => 'copy backup file to /tmp',
                'cmd' => new Process('cp "'.$input->getArgument('file').'" /tmp/backup.tar.gz')
            ],
            [
                'description' => 'unzip backup to '.\realpath(dirname(__DIR__,4)),
                'cmd' => new Process('tar -xzf /tmp/backup.tar.gz -C '.\realpath(dirname(__DIR__,4)))
            ],
            [
                'description' => 'restore database',
                'cmd' => new Process('mysql -u '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.username').' --password='.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.password').' -h '.\Pimcore::getContainer()->getParameter('pimcore_system_config.database.params.host').' '.\Pimcore::getContainer('pimcore_system_config.database.params.dbname').' < '.\realpath(dirname(__DIR__,4)).'/backup.sql')
            ],
            [
                'description' => 'clear cache',
                'cmd' => $clearCacheCommand
            ]
        ];

        $progressBar = new ProgressBar($output, \count($steps));
        $progressBar->start();
        foreach ($steps as $step) {
            $progressBar->setMessage($step['description'].' ...');

            /** @var Process|Command $command */
            $command = $step['cmd'];
            $command->run();

            if (!$command->isSuccessful()) {
                throw new ProcessFailedException($command);
            }
            $progressBar->advance();
        }

        $progressBar->finish();
    }
}

