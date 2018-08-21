<?php

namespace blackbit\BackupBundle\Tools;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class ParallelProcessComposite extends Process
{
    /** @var Process[] */
    private $processes;

    private $exitCode = 0;

    public function __construct(Process ...$processes) {
        $this->processes = $processes;
    }

    public function run($callback = null/*, array $env = array()*/)
    {
        foreach ($this->processes as $process) {
            $process->start();
        }

        do {
            $minOneIsRunning = false;
            foreach($this->processes as $index => $process) {
                if(!$process->isRunning() && $process->getExitCode() > 0) {
                    $this->exitCode = $process->getExitCode();
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
}