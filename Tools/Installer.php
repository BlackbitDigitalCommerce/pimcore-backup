<?php
namespace blackbit\BackupBundle\Tools;

use Blackbit\PimBundle\model\ImportStatus;
use Doctrine\DBAL\DBALException;
use Pimcore\Db;
use Pimcore\Extension\Bundle\Installer\AbstractInstaller;
use Pimcore\Model\User\Permission\Definition;
use Psr\Log\LoggerInterface;

/**
 * Created by JetBrains PhpStorm.
 * User: Dennis
 * Date: 18.07.12
 * Time: 13:51
 * To change this template use File | Settings | File Templates.
 */
class Installer extends AbstractInstaller {
	public function canBeInstalled()
    {
        return false;
    }

    public function canBeUninstalled()
    {
        return false;
    }

	/**
	 * @return boolean
	 */
	public function isInstalled() {
        return \is_writable(PIMCORE_SYSTEM_TEMP_DIRECTORY);
	}

	public function needsReloadAfterInstall(){
		return false;
	}
}
