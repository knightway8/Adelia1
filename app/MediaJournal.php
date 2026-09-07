<?php

declare(strict_types=1);

namespace Adelia;

final readonly class MediaJournal
{
    private string $root;
    private string $path;

    public function __construct()
    {
        $root = getcwd();
        if ($root === false) {
            throw new \RuntimeException('Cannot resolve the board directory.');
        }
        $this->root = str_replace('\\', '/', $root);
        $this->path = $this->root . '/.adelia-uploads.json';
    }

    public static function validatePath(string $path): void
    {
        if (!preg_match('~^(?:src|thumb)/[a-zA-Z0-9](?:[a-zA-Z0-9_.-]*[a-zA-Z0-9])?$~D', $path)) {
            throw new \RuntimeException('Invalid media cleanup path.');
        }
    }

    private function read(): array
    {
        if (!file_exists($this->path) && !is_link($this->path)) {
            return [];
        }
        $envelope = json_decode(StorageFiles::read($this->path, 1_048_576), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || count($envelope) !== 2 || !is_string($envelope['payload'] ?? null) || !is_string($envelope['sha256'] ?? null) || !hash_equals(hash('sha256', $envelope['payload']), $envelope['sha256'])) {
            throw new \RuntimeException('The pending upload journal is damaged. Inspect it before cleaning media.');
        }
        $paths = json_decode($envelope['payload'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($paths) || !array_is_list($paths) || count($paths) > 4096) {
            throw new \RuntimeException('Invalid pending upload journal.');
        }
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new \RuntimeException('Invalid pending upload path.');
            }
            self::validatePath($path);
        }
        return $paths;
    }

    private function write(array $paths): void
    {
        if (count($paths) > 4096) {
            throw new \RuntimeException('Too many pending uploads. Complete media recovery first.');
        }
        $payload = json_encode(array_values($paths), JSON_THROW_ON_ERROR);
        StorageFiles::replace($this->path, json_encode(['payload' => $payload, 'sha256' => hash('sha256', $payload)], JSON_THROW_ON_ERROR));
    }

    // The Application holds the board lock before reservations, commits and cleanup.
    public function reserve(string $path): void
    {
        self::validatePath($path);
        $paths = $this->read();
        if (!in_array($path, $paths, true)) {
            $paths[] = $path;
            $this->write($paths);
        }
    }

    public function prepare(array $references): void
    {
        $directories = [];
        foreach ($this->read() as $path) {
            if (!isset($references[$path])) {
                continue;
            }
            $file = $this->root . '/' . $path;
            $parent = dirname($file);
            $resolved = realpath($parent);
            if ($resolved === false || str_replace('\\', '/', $resolved) !== $parent || is_link($parent) || is_link($file) || !is_file($file) || filesize($file) === 0) {
                throw new \RuntimeException('A new attachment is missing or invalid. The post was not saved.');
            }
            $handle = fopen($file, 'r+b');
            if ($handle === false) {
                throw new \RuntimeException('Cannot open the new attachment for synchronization.');
            }
            try {
                if (!fsync($handle)) {
                    throw new \RuntimeException('Cannot flush a new attachment. The post was not saved.');
                }
            } finally {
                fclose($handle);
            }
            $directories[$parent] = true;
        }
        foreach (array_keys($directories) as $directory) {
            StorageFiles::syncDirectory($directory);
        }
    }

    public function removeUnreferenced(string $path, array $references): void
    {
        self::validatePath($path);
        if (isset($references[$path])) {
            return;
        }
        $parent = $this->root . '/' . dirname($path);
        $resolved = realpath($parent);
        if ($resolved === false || str_replace('\\', '/', $resolved) !== $parent || is_link($parent) || is_link($this->root . '/' . $path)) {
            throw new \RuntimeException('Media cleanup encountered a redirected path.');
        }
        $file = $this->root . '/' . $path;
        if (is_file($file) && !unlink($file)) {
            throw new \RuntimeException('Media cleanup could not remove an unused file.');
        }
        StorageFiles::syncDirectory($parent);
    }

    public function recover(array $references): void
    {
        $paths = $this->read();
        if ($paths === []) {
            return;
        }
        foreach ($paths as $path) {
            $this->removeUnreferenced($path, $references);
        }
        $this->write([]);
    }
}
