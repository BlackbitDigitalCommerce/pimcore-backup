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