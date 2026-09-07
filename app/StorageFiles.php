<?php

declare(strict_types=1);

namespace Adelia;

final class StorageFiles
{
    public static function read(string $path, int $maximum): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('A required storage file is missing or invalid: ' . basename($path));
        }
        $contents = file_get_contents($path, length: $maximum + 1);
        if ($contents === false || $contents === '' || strlen($contents) > $maximum) {
            throw new \RuntimeException('Storage is empty, unreadable or oversized: ' . basename($path));
        }
        return $contents;
    }

    public static function replace(string $path, string $contents, int $mode = 0o600): void
    {
        if (is_link($path) || is_link(dirname($path))) {
            throw new \RuntimeException('Storage paths must not be symbolic links.');
        }
        $temporary = dirname($path) . '/.adelia-write-' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create a storage temporary file.');
        }
        try {
            if (PHP_OS_FAMILY !== 'Windows' && !chmod($temporary, $mode)) {
                throw new \RuntimeException('Cannot protect the storage temporary file.');
            }
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset, 1_048_576));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Storage write was incomplete.');
                }
                $offset += $written;
            }
            if (!fsync($handle)) {
                throw new \RuntimeException('Cannot flush the completed storage file.');
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Cannot publish the completed storage file.');
            }
            self::syncDirectory(dirname($path));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function syncDirectory(string $directory): void
    {
        // PHP on Windows cannot open directory handles for FlushFileBuffers.
        // NTFS and the device's power-loss behavior remain part of the durability boundary.
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $handle = fopen($directory, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the storage directory for synchronization.');
        }
        try {
            if (!fsync($handle)) {
                throw new \RuntimeException('Cannot synchronize the storage directory.');
            }
        } finally {
            fclose($handle);
        }
    }
}
