<?php

declare(strict_types=1);

namespace App\Support\Media;

use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

/**
 * Keeps logical directory paths while storing every Blob under a flat name.
 *
 * The separator must not occur in a logical path, otherwise the mapping would
 * not be reversible. Azure block helpers and URL signers use encodePath() so
 * they address the same Blob as this adapter.
 */
final class FlatBlobAdapter implements FilesystemAdapter
{
    private const SEPARATOR = '~';

    public function __construct(private readonly FilesystemAdapter $adapter) {}

    public static function encodePath(string $path): string
    {
        return implode(self::SEPARATOR, self::logicalSegments($path));
    }

    public static function decodePath(string $path): string
    {
        if ($path === '' || str_contains($path, '/') || str_contains($path, '\\')) {
            throw self::invalidPath($path, 'physical Blob paths must be non-empty and flat');
        }

        $segments = explode(self::SEPARATOR, $path);

        foreach ($segments as $segment) {
            self::assertSegment($segment, $path);
        }

        return implode('/', $segments);
    }

    public function fileExists(string $path): bool
    {
        return $this->adapter->fileExists(self::encodePath($path));
    }

    public function directoryExists(string $path): bool
    {
        $directory = self::normalizeDirectoryPath($path);

        if ($directory === '') {
            return true;
        }

        $prefix = self::encodePath($directory).self::SEPARATOR;

        foreach ($this->filesWithPrefix($prefix) as $item) {
            return true;
        }

        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->adapter->write(self::encodePath($path), $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->adapter->writeStream(self::encodePath($path), $contents, $config);
    }

    public function read(string $path): string
    {
        return $this->adapter->read(self::encodePath($path));
    }

    public function readStream(string $path)
    {
        return $this->adapter->readStream(self::encodePath($path));
    }

    public function delete(string $path): void
    {
        $this->adapter->delete(self::encodePath($path));
    }

    public function deleteDirectory(string $path): void
    {
        $directory = self::normalizeDirectoryPath($path);

        if ($directory === '') {
            throw new InvalidArgumentException('Deleting the flat Blob root is not allowed.');
        }

        $prefix = self::encodePath($directory).self::SEPARATOR;
        $physicalPaths = [];

        foreach ($this->filesWithPrefix($prefix) as $item) {
            $physicalPaths[] = $item->path();
        }

        foreach ($physicalPaths as $physicalPath) {
            $this->adapter->delete($physicalPath);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        self::normalizeDirectoryPath($path);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->adapter->setVisibility(self::encodePath($path), $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->withLogicalPath($this->adapter->visibility(self::encodePath($path)), $path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->withLogicalPath($this->adapter->mimeType(self::encodePath($path)), $path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->withLogicalPath($this->adapter->lastModified(self::encodePath($path)), $path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->withLogicalPath($this->adapter->fileSize(self::encodePath($path)), $path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $directory = self::normalizeDirectoryPath($path);
        $directorySegments = $directory === '' ? [] : explode('/', $directory);
        $prefix = $directory === '' ? '' : self::encodePath($directory).self::SEPARATOR;
        $directories = [];

        foreach ($this->filesWithPrefix($prefix) as $item) {
            $relativePath = substr($item->path(), strlen($prefix));
            $relativeSegments = explode(self::SEPARATOR, $relativePath);
            $fileSegment = array_pop($relativeSegments);

            if ($fileSegment === null || $fileSegment === '') {
                throw self::invalidPath($item->path(), 'physical Blob paths must include a file name');
            }

            $parentLimit = $deep ? count($relativeSegments) : min(1, count($relativeSegments));
            $logicalParent = $directorySegments;

            for ($index = 0; $index < $parentLimit; $index++) {
                $logicalParent[] = $relativeSegments[$index];
                $logicalDirectory = implode('/', $logicalParent);

                if (! isset($directories[$logicalDirectory])) {
                    $directories[$logicalDirectory] = true;

                    yield new DirectoryAttributes($logicalDirectory);
                }
            }

            if (! $deep && $relativeSegments !== []) {
                continue;
            }

            $logicalPath = implode('/', [...$directorySegments, ...$relativeSegments, $fileSegment]);

            yield self::withLogicalPath($item, $logicalPath);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $source = self::encodePath($source);
        $destination = self::encodePath($destination);
        $stream = $this->adapter->readStream($source);

        try {
            $headers = $config->get('httpHeaders', []);
            $headers['contentType'] = $this->adapter->mimeType($source)->mimeType() ?? 'application/octet-stream';
            $this->adapter->writeStream($destination, $stream, $config->extend([
                'httpHeaders' => $headers,
                'initialTransferSize' => 8 * 1024 * 1024,
                'maximumTransferSize' => 8 * 1024 * 1024,
                'maximumConcurrency' => 1,
            ]));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function logicalSegments(string $path): array
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '') {
                continue;
            }

            self::assertSegment($segment, $path);
            $segments[] = $segment;
        }

        if ($segments === []) {
            throw self::invalidPath($path, 'file paths must not be empty');
        }

        return $segments;
    }

    private static function normalizeDirectoryPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (trim($path, '/') === '') {
            return '';
        }

        return implode('/', self::logicalSegments($path));
    }

    private static function assertSegment(string $segment, string $path): void
    {
        if ($segment === '') {
            throw self::invalidPath($path, 'path segments must not be empty');
        }

        if ($segment === '.' || $segment === '..') {
            throw self::invalidPath($path, 'dot and traversal segments are not allowed');
        }

        if (str_contains($segment, self::SEPARATOR)) {
            throw self::invalidPath($path, "path segments must not contain '".self::SEPARATOR."'");
        }

        if (preg_match('/\p{C}/u', $segment) === 1) {
            throw self::invalidPath($path, 'control characters are not allowed');
        }
    }

    /** @return iterable<FileAttributes> */
    private function filesWithPrefix(string $prefix): iterable
    {
        foreach ($this->adapter->listContents('', true) as $item) {
            if ($item instanceof FileAttributes && str_starts_with($item->path(), $prefix)) {
                yield $item;
            }
        }
    }

    private static function withLogicalPath(FileAttributes $attributes, string $path): FileAttributes
    {
        return new FileAttributes(
            self::decodePath(self::encodePath($path)),
            $attributes->fileSize(),
            $attributes->visibility(),
            $attributes->lastModified(),
            $attributes->mimeType(),
            $attributes->extraMetadata(),
        );
    }

    private static function invalidPath(string $path, string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException("Invalid flat Blob path '{$path}': {$reason}.");
    }
}
