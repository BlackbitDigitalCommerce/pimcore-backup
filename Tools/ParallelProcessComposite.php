<?php

namespace blackbit\BackupBundle\Tools;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class ParallelProcessComposite extends Process
{
    /** @var Process[] */
    private $processes;

    public function __construct(Process ...$processes) {
        $this->processes = $processes;
    }

    public function run($callback = null/*, array $env = array()*/)
    {
        foreach ($this->processes as $process) {
            $process->start();
        }

        foreach($this->processes as $index => $process) {
            if(!$process->isRunning() && $process->getExitCode() !== 0) {
                throw new RuntimeException($process->getErrorOutput(), $process->getExitCode());
            }
        }
    }
}