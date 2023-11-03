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
class Installer extends AbstractInstaller
{
	public function canBeInstalled(): bool
    {
        return false;
    }

    public function canBeUninstalled(): bool
    {
        return false;
    }

	public function isInstalled(): bool
    {
        return \is_writable(PIMCORE_SYSTEM_TEMP_DIRECTORY);
	}

	public function needsReloadAfterInstall(): bool
    {
		return false;
	}
}
