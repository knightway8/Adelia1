<?php

declare(strict_types=1);

namespace Adelia;

final class Process
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): string
    {
        $output = tempnam(sys_get_temp_dir(), 'adelia-out-');
        $errors = tempnam(sys_get_temp_dir(), 'adelia-err-');
        if ($output === false || $errors === false) {
            throw new \RuntimeException('Cannot allocate command output files.');
        }
        $process = null;
        try {
            $process = proc_open($arguments, [['pipe', 'r'], ['file', $output, 'w'], ['file', $errors, 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new BoardMessage('The configured media program could not start.');
            }
            fclose($pipes[0]);
            $deadline = microtime(true) + 30;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process);
                    throw new BoardMessage('Media processing exceeded its time limit.');
                }
                usleep(10_000);
            } while (true);
            if ($status['exitcode'] !== 0) {
                throw new BoardMessage('The configured media program failed. Verify it is installed and the file is valid.');
            }
            return trim((string) file_get_contents($output));
        } finally {
            if (is_resource($process)) {
                proc_close($process);
            }
            if (is_file($output)) {
                unlink($output);
            }
            if (is_file($errors)) {
                unlink($errors);
            }
        }
    }
}
