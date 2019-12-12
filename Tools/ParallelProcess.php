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

use Symfony\Component\Process\Process;

class ParallelProcess
{
    /** @var Process[] */
    private $processes;

    private $exitCode = 0;
    private $error;

    public function __construct(Process ...$processes) {
        $this->processes = $processes;
    }

    public function run()
    {
        foreach ($this->processes as $process) {
            $process->start();
        }

        do {
            $minOneIsRunning = false;
            foreach($this->processes as $index => $process) {
                if(!$process->isRunning() && $process->getExitCode() > 0) {
                    $this->exitCode = $process->getExitCode();
                    throw new \Exception($process->getErrorOutput());
                }

                if(!$minOneIsRunning && $process->isRunning()) {
                    $minOneIsRunning = true;
                }
            }
        } while($minOneIsRunning);
    }

    public function getExitCode()
    {
        return $this->exitCode;
    }

    public function isSuccessful()
    {
        return 0 === $this->getExitCode();
    }
}