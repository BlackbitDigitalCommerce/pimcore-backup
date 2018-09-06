<?php

namespace blackbit\BackupBundle\Command;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use Pimcore\Console\AbstractCommand;

class StorageCommand extends AbstractCommand
{
    /** @var Filesystem */
    protected $filesystem;

    public function __construct(AdapterInterface $filesystemAdapter)
    {
        $this->filesystem = new Filesystem($filesystemAdapter);
    }
}