<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Media\Type as MediaType;
use App\Support\Media\FlatBlobAdapter;
use AzureOss\Storage\Blob\BlobClient;
use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\Models\BlobHttpHeaders;
use AzureOss\Storage\Blob\Models\CommitBlockListOptions;
use AzureOss\Storage\Blob\Specialized\BlockBlobClient;
use AzureOss\Storage\Common\Middleware\ClientFactory;
use Closure;
use finfo;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AzureBlockUploader
{
    private const CACHE_PREFIX = 'chunked-cloud-upload:';

    private const CACHE_TTL_HOURS = 6;

    private const MIME_SNIFF_BYTES = 8192;

    /** @var (Closure(string): string)|null */
    private readonly ?Closure $encodePath;

    /** @var (Closure(BlobClient): ClientInterface)|null */
    private readonly ?Closure $httpClientFactory;

    /**
     * @param  (Closure(string): string)|null  $encodePath
     * @param  (Closure(BlobClient): ClientInterface)|null  $httpClientFactory
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly BlobContainerClient $container,
        ?Closure $encodePath = null,
        ?Closure $httpClientFactory = null,
    ) {
        $this->encodePath = $encodePath;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * @return array{done: bool, progress: int, path?: string, size?: int, mime_type?: string}
     */
    public function receiveChunk(
        string $identifier,
        string $fileName,
        string $chunk,
        int $rangeStart,
        int $rangeEnd,
        int $totalSize,
    ): array {
        $cacheKey = self::CACHE_PREFIX.$identifier;

        return $this->withSessionLock(
            $cacheKey,
            fn (): array => $this->receiveChunkLocked(
                $cacheKey,
                $fileName,
                $chunk,
                $rangeStart,
                $rangeEnd,
                $totalSize,
            ),
        );
    }

    public function readRange(string $key, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1) {
            throw new InvalidArgumentException('The requested byte range is invalid.');
        }

        $client = $this->container->getBlobClient($this->encodedBlobName($key));

        return $this->downloadRange($client, $offset, $length);
    }

    /**
     * @return array{done: bool, progress: int, path?: string, size?: int, mime_type?: string}
     */
    private function receiveChunkLocked(
        string $cacheKey,
        string $fileName,
        string $chunk,
        int $rangeStart,
        int $rangeEnd,
        int $totalSize,
    ): array {
        $chunkSize = strlen($chunk);
        $isLastChunk = ($rangeEnd + 1) >= $totalSize;

        if (
            $totalSize < 1
            || $rangeStart < 0
            || $rangeEnd < $rangeStart
            || $rangeEnd >= $totalSize
            || $chunkSize !== ($rangeEnd - $rangeStart + 1)
        ) {
            throw new InvalidArgumentException('Chunk bytes do not match Content-Range.');
        }

        if (! $isLastChunk && $chunkSize < ChunkedCloudUploader::MIN_PART_BYTES) {
            throw new InvalidArgumentException(
                'Non-final multipart parts must be at least '.ChunkedCloudUploader::MIN_PART_BYTES.' bytes.'
            );
        }

        $storedState = $this->cache->get($cacheKey);
        $state = is_array($storedState) ? $storedState : null;

        if ($state !== null && (int) data_get($state, 'total_size') !== $totalSize) {
            throw new InvalidArgumentException('Chunk bytes do not match the upload size.');
        }

        if ($rangeStart === 0) {
            if ($state === null) {
                $state = $this->startUpload($fileName, $chunk, $totalSize);
                $this->cache->put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
            }
        } elseif ($state === null) {
            throw new RuntimeException('Chunked upload session expired or missing.');
        }

        $nextOffset = (int) data_get($state, 'next_offset', 0);

        if ($rangeStart < $nextOffset) {
            if ($rangeEnd >= $nextOffset) {
                throw new InvalidArgumentException('Chunk overlaps already accepted bytes.');
            }

            return $this->status($state, completed: $nextOffset >= $totalSize);
        }

        if ($rangeStart !== $nextOffset) {
            throw new InvalidArgumentException('Chunk is out of order.');
        }

        $blockNumber = count(data_get($state, 'block_ids', [])) + 1;

        if ($blockNumber > 99_999_999) {
            throw new InvalidArgumentException('The upload has too many parts.');
        }

        $blockId = $this->blockId($blockNumber);
        $client = $this->container->getBlockBlobClient((string) data_get($state, 'blob_name'));

        $this->stageBlock($client, $blockId, $chunk);

        $state['block_ids'][] = $blockId;
        $state['next_offset'] = $rangeEnd + 1;
        $state['bytes_received'] = (int) data_get($state, 'bytes_received', 0) + $chunkSize;

        if (! $isLastChunk) {
            $this->cache->put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

            return $this->status($state, completed: false);
        }

        $mimeType = $this->detectMimeType(
            (string) data_get($state, 'header'),
            MediaType::extensionOf($fileName),
        );

        $this->commitBlocks($client, data_get($state, 'block_ids'), $mimeType);
        $state['mime_type'] = $mimeType;
        $this->cache->put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

        return $this->status($state, completed: true);
    }

    /**
     * @return array{
     *     path: string,
     *     blob_name: string,
     *     mime_type: string,
     *     header: string,
     *     block_ids: array<int, string>,
     *     next_offset: int,
     *     bytes_received: int,
     *     total_size: int
     * }
     */
    private function startUpload(string $fileName, string $chunk, int $totalSize): array
    {
        $extension = MediaType::extensionOf($fileName);
        $suffix = $extension === '' ? '' : ".{$extension}";
        $path = 'medias/'.Str::uuid().$suffix;
        $header = substr($chunk, 0, self::MIME_SNIFF_BYTES);

        return [
            'path' => $path,
            'blob_name' => $this->encodedBlobName($path),
            'mime_type' => $this->detectMimeType($header, $extension),
            'header' => $header,
            'block_ids' => [],
            'next_offset' => 0,
            'bytes_received' => 0,
            'total_size' => $totalSize,
        ];
    }

    private function blockId(int $blockNumber): string
    {
        return base64_encode(sprintf('%08d', $blockNumber));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{done: bool, progress: int, path?: string, size?: int, mime_type?: string}
     */
    private function status(array $state, bool $completed): array
    {
        $totalSize = (int) data_get($state, 'total_size', 0);
        $received = (int) data_get($state, 'bytes_received', 0);
        $progress = $totalSize > 0
            ? (int) round(min($received, $totalSize) / $totalSize * 100)
            : 0;

        if (! $completed) {
            return [
                'done' => false,
                'progress' => $progress,
            ];
        }

        return [
            'done' => true,
            'progress' => 100,
            'path' => (string) data_get($state, 'path'),
            'size' => $received,
            'mime_type' => (string) data_get($state, 'mime_type'),
        ];
    }

    private function detectMimeType(string $header, string $extension): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($header) ?: null;

        if (is_string($detected) && $detected !== 'application/octet-stream') {
            return $detected;
        }

        return MediaType::mimeTypeFromExtension($extension) ?? 'application/octet-stream';
    }

    private function encodedBlobName(string $path): string
    {
        return $this->encodePath === null
            ? FlatBlobAdapter::encodePath($path)
            : ($this->encodePath)($path);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withSessionLock(string $cacheKey, Closure $callback): mixed
    {
        if (! $this->cache instanceof Repository || ! $this->cache->getStore() instanceof LockProvider) {
            return $callback();
        }

        return $this->cache->lock($cacheKey.':lock', 30)->block(10, $callback);
    }

    protected function stageBlock(BlockBlobClient $client, string $blockId, string $chunk): void
    {
        $client->stageBlock($blockId, $chunk);
    }

    /**
     * @param  array<int, string>  $blockIds
     */
    protected function commitBlocks(BlockBlobClient $client, array $blockIds, string $mimeType): void
    {
        $client->commitBlockList($blockIds, new CommitBlockListOptions(
            httpHeaders: new BlobHttpHeaders(contentType: $mimeType),
        ));
    }

    private function downloadRange(
        BlobClient $client,
        int $offset,
        int $length,
    ): string {
        $lastByte = $offset + $length - 1;
        $response = $this->httpClient($client)->get($client->uri, [
            RequestOptions::HEADERS => [
                'Range' => "bytes={$offset}-{$lastByte}",
            ],
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::STREAM => true,
        ]);

        $contentRange = $response->getHeaderLine('Content-Range');

        if (
            $response->getStatusCode() !== 206
            || preg_match('~^bytes\s+'.preg_quote("{$offset}-{$lastByte}", '~').'/\d+$~i', $contentRange) !== 1
        ) {
            throw new RuntimeException('Azure stored object returned an invalid byte range.');
        }

        $stream = $response->getBody();
        $contents = '';

        while (strlen($contents) <= $length && ! $stream->eof()) {
            $next = $stream->read(min(8192, $length + 1 - strlen($contents)));

            if ($next === '') {
                break;
            }

            $contents .= $next;
        }

        if (strlen($contents) !== $length) {
            throw new RuntimeException('Azure stored object returned an invalid byte range.');
        }

        return $contents;
    }

    private function httpClient(BlobClient $client): ClientInterface
    {
        if ($this->httpClientFactory !== null) {
            return ($this->httpClientFactory)($client);
        }

        // The installed SDK has no ranged download option, so reuse its authenticated transport.
        return (new ClientFactory)->create($client->uri, $client->credential);
    }
}
