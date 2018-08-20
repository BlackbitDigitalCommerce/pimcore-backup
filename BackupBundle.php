<?php

namespace blackbit\BackupBundle;

use blackbit\BackupBundle\Tools\Installer;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Installer\InstallerInterface;
use Pimcore\Model\User\Permission\Definition;

class BackupBundle extends AbstractPimcoreBundle {
    public function getInstaller() {
        return new Installer();
    }
}
