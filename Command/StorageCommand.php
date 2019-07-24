<?php

namespace blackbit\BackupBundle\Command;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use Pimcore\Console\AbstractCommand;
use Pimcore\Db\Connection;

class StorageCommand extends AbstractCommand
{
    /** @var Filesystem */
    protected $filesystem;

    /** @var Connection */
    protected $connection;

    public function __construct(AdapterInterface $filesystemAdapter, Connection $connection)
    {
        parent::__construct();
        $this->filesystem = new Filesystem($filesystemAdapter);

        $this->connection = $connection;
    }
}