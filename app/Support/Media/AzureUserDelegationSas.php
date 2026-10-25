<?php

declare(strict_types=1);

namespace App\Support\Media;

use AzureOss\Identity\TokenCredential;
use AzureOss\Identity\TokenRequestContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class AzureUserDelegationSas
{
    private const string ACCOUNT_HOST_SUFFIX = '.blob.core.windows.net';

    private const string API_VERSION = '2023-11-03';

    private const string DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    private const string SAS_VERSION = '2023-11-03';

    private const string TOKEN_SCOPE = 'https://storage.azure.com/.default';

    private const int CACHE_TTL_SECONDS = 300;

    private const int KEY_FETCH_LOCK_SECONDS = 30;

    private const int KEY_FETCH_WAIT_SECONDS = 10;

    /**
     * @var array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}|null
     */
    private ?array $processDelegationKey = null;

    private ?int $processDelegationKeyFetchedAt = null;

    public function __construct(
        private readonly TokenCredential $credential,
        private readonly string $accountName,
        private readonly string $containerName,
        private readonly ?CacheRepository $cache = null,
    ) {
        if (preg_match('/^[a-z0-9]{3,24}$/D', $accountName) !== 1) {
            throw new InvalidArgumentException('Azure storage account name is invalid.');
        }

        if (
            strlen($containerName) < 3
            || strlen($containerName) > 63
            || preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/D', $containerName) !== 1
            || str_contains($containerName, '--')
        ) {
            throw new InvalidArgumentException('Azure blob container name is invalid.');
        }
    }

    public function url(string $physicalBlobName, DateTimeInterface $expiresAt): string
    {
        $this->assertPhysicalBlobName($physicalBlobName);

        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $expiry = CarbonImmutable::instance($expiresAt)->utc()->startOfSecond();
        $latestExpiry = $now->addHours(26);

        if ($expiry->lessThanOrEqualTo($now) || $expiry->greaterThan($latestExpiry)) {
            throw new InvalidArgumentException('Azure SAS expiry must be in the future and at most 26 hours away.');
        }

        $start = $now->subMinutes(5);
        $delegationKey = $this->delegationKey($start, $expiry, $now);
        $canonicalizedResource = "/blob/{$this->accountName}/{$this->containerName}/{$physicalBlobName}";
        $stringToSign = implode("\n", [
            'r',
            $start->format(self::DATE_FORMAT),
            $expiry->format(self::DATE_FORMAT),
            $canonicalizedResource,
            $delegationKey['signed_oid'],
            $delegationKey['signed_tid'],
            $delegationKey['signed_start'],
            $delegationKey['signed_expiry'],
            $delegationKey['signed_service'],
            $delegationKey['signed_version'],
            '',
            '',
            '',
            '',
            'https',
            self::SAS_VERSION,
            'b',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $this->decodedKey($delegationKey), true));
        $query = $this->queryString($start, $expiry, $delegationKey, $signature);

        return sprintf(
            'https://%s%s/%s/%s?%s',
            $this->accountName,
            self::ACCOUNT_HOST_SUFFIX,
            $this->containerName,
            rawurlencode($physicalBlobName),
            $query,
        );
    }

    private function assertPhysicalBlobName(string $physicalBlobName): void
    {
        if (
            $physicalBlobName === ''
            || trim($physicalBlobName) === ''
            || $physicalBlobName === '.'
            || $physicalBlobName === '..'
            || str_contains($physicalBlobName, '/')
            || str_contains($physicalBlobName, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $physicalBlobName) === 1
        ) {
            throw new InvalidArgumentException('Azure blob name must be a flat, non-empty path without traversal.');
        }
    }

    /**
     * @return array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}
     */
    private function delegationKey(
        CarbonImmutable $sasStart,
        CarbonImmutable $sasExpiry,
        CarbonImmutable $now,
    ): array {
        if ($this->processKeyCovers($sasStart, $sasExpiry, $now)) {
            /** @var array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string} $processDelegationKey */
            $processDelegationKey = $this->processDelegationKey;

            return $processDelegationKey;
        }

        $cache = $this->cache ?? Cache::store();
        $cacheKey = $this->cacheKey();
        $cached = $this->cachedDelegationKey($cache, $cacheKey, $sasStart, $sasExpiry, $now);
        if ($cached !== null) {
            return $cached;
        }

        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Azure user delegation SAS caching requires a cache store with lock support.');
        }

        try {
            $delegationKey = $store
                ->lock("{$cacheKey}:refresh", self::KEY_FETCH_LOCK_SECONDS)
                ->block(self::KEY_FETCH_WAIT_SECONDS, function () use ($cache, $cacheKey, $sasStart, $sasExpiry, $now): array {
                    $cached = $this->cachedDelegationKey($cache, $cacheKey, $sasStart, $sasExpiry, $now);
                    if ($cached !== null) {
                        return $cached;
                    }

                    $delegationKey = $this->fetchDelegationKey($sasStart, $sasExpiry, $now);
                    $this->storeDelegationKey($cache, $cacheKey, $delegationKey, $now);

                    return $delegationKey;
                });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Timed out waiting for the Azure user delegation key.');
        }

        if (! is_array($delegationKey)) {
            throw new RuntimeException('Azure user delegation key could not be resolved.');
        }

        return $delegationKey;
    }

    /**
     * @return array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}
     */
    private function cachedDelegationKey(
        CacheRepository $cache,
        string $cacheKey,
        CarbonImmutable $sasStart,
        CarbonImmutable $sasExpiry,
        CarbonImmutable $now,
    ): ?array {
        $encrypted = $cache->get($cacheKey);
        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $cache->forget($cacheKey);

            return null;
        }

        $fetchedAt = data_get($payload, 'fetched_at');
        if (
            ! is_array($payload)
            || data_get($payload, 'schema') !== 1
            || ! is_int($fetchedAt)
        ) {
            $cache->forget($cacheKey);

            return null;
        }

        $delegationKey = $this->normaliseDelegationKey(data_get($payload, 'key'));
        if (
            $delegationKey === null
            || $now->getTimestamp() >= $fetchedAt + self::CACHE_TTL_SECONDS
            || ! $this->keyCovers($delegationKey, $sasStart, $sasExpiry, $now)
        ) {
            $cache->forget($cacheKey);

            return null;
        }

        $this->rememberProcessDelegationKey($delegationKey, $fetchedAt);

        return $delegationKey;
    }

    private function processKeyCovers(
        CarbonImmutable $sasStart,
        CarbonImmutable $sasExpiry,
        CarbonImmutable $now,
    ): bool {
        if ($this->processDelegationKey === null || $this->processDelegationKeyFetchedAt === null) {
            return false;
        }

        if ($now->getTimestamp() >= $this->processDelegationKeyFetchedAt + self::CACHE_TTL_SECONDS) {
            $this->forgetProcessDelegationKey();

            return false;
        }

        return $this->keyCovers($this->processDelegationKey, $sasStart, $sasExpiry, $now);
    }

    /**
     * @return array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}
     */
    private function fetchDelegationKey(
        CarbonImmutable $sasStart,
        CarbonImmutable $sasExpiry,
        CarbonImmutable $now,
    ): array {
        try {
            $token = $this->credential->getToken(new TokenRequestContext([self::TOKEN_SCOPE]));
        } catch (Throwable) {
            throw new RuntimeException('Azure storage access token could not be acquired.');
        }

        try {
            $response = Http::withToken($token->token, 'Bearer')
                ->accept('application/xml')
                ->withHeaders(['x-ms-version' => self::API_VERSION])
                ->timeout(10)
                ->connectTimeout(5)
                ->withBody($this->delegationKeyRequest($now), 'application/xml')
                ->post($this->userDelegationKeyUrl());
        } catch (ConnectionException) {
            throw new RuntimeException('Azure user delegation key request failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("Azure user delegation key request failed with HTTP {$response->status()}.");
        }

        $body = $response->body();
        if (strlen($body) > 65536) {
            throw new RuntimeException('Azure returned an invalid user delegation key.');
        }

        $delegationKey = $this->parseDelegationKey($body);
        if (! $this->keyCovers($delegationKey, $sasStart, $sasExpiry, $now)) {
            throw new RuntimeException('Azure returned a user delegation key outside the requested bounds.');
        }

        return $delegationKey;
    }

    private function delegationKeyRequest(CarbonImmutable $now): string
    {
        $start = $now->subMinutes(5)->format(self::DATE_FORMAT);
        $expiry = $now->addHours(48)->format(self::DATE_FORMAT);

        return "<?xml version=\"1.0\" encoding=\"utf-8\"?><KeyInfo><Start>{$start}</Start><Expiry>{$expiry}</Expiry></KeyInfo>";
    }

    private function userDelegationKeyUrl(): string
    {
        return sprintf(
            'https://%s%s/?restype=service&comp=userdelegationkey',
            $this->accountName,
            self::ACCOUNT_HOST_SUFFIX,
        );
    }

    /**
     * @return array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}
     */
    private function parseDelegationKey(string $body): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? $document->documentElement : null;
        if (! $root instanceof DOMElement || $root->localName !== 'UserDelegationKey') {
            throw new RuntimeException('Azure returned an invalid user delegation key.');
        }

        $values = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $values[$child->localName] = trim($child->textContent);
            }
        }

        $delegationKey = $this->normaliseDelegationKey([
            'signed_oid' => $values['SignedOid'] ?? null,
            'signed_tid' => $values['SignedTid'] ?? null,
            'signed_start' => $values['SignedStart'] ?? null,
            'signed_expiry' => $values['SignedExpiry'] ?? null,
            'signed_service' => $values['SignedService'] ?? null,
            'signed_version' => $values['SignedVersion'] ?? null,
            'value' => $values['Value'] ?? null,
        ]);

        if ($delegationKey === null) {
            throw new RuntimeException('Azure returned an invalid user delegation key.');
        }

        return $delegationKey;
    }

    private function normaliseDelegationKey(mixed $key): ?array
    {
        if (! is_array($key)) {
            return null;
        }

        $normalised = [];
        foreach (['signed_oid', 'signed_tid', 'signed_start', 'signed_expiry', 'signed_service', 'signed_version', 'value'] as $field) {
            $value = data_get($key, $field);
            if (! is_string($value) || $value === '') {
                return null;
            }

            $normalised[$field] = $value;
        }

        if ($normalised['signed_service'] !== 'b' || $normalised['signed_version'] !== self::API_VERSION) {
            return null;
        }

        if (
            $this->parseDate($normalised['signed_start']) === null
            || $this->parseDate($normalised['signed_expiry']) === null
            || $this->decodeKeyValue($normalised['value']) === null
        ) {
            return null;
        }

        return $normalised;
    }

    /**
     * @param  array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}  $delegationKey
     */
    private function keyCovers(
        array $delegationKey,
        CarbonImmutable $sasStart,
        CarbonImmutable $sasExpiry,
        CarbonImmutable $now,
    ): bool {
        $keyStart = $this->parseDate($delegationKey['signed_start']);
        $keyExpiry = $this->parseDate($delegationKey['signed_expiry']);

        return $keyStart !== null
            && $keyExpiry !== null
            && $keyStart->lessThanOrEqualTo($sasStart)
            && $keyExpiry->greaterThanOrEqualTo($sasExpiry)
            && $keyExpiry->greaterThan($now);
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}  $delegationKey
     */
    private function decodedKey(array $delegationKey): string
    {
        $decoded = $this->decodeKeyValue($delegationKey['value']);
        if ($decoded === null) {
            throw new RuntimeException('Azure returned an invalid user delegation key.');
        }

        return $decoded;
    }

    private function decodeKeyValue(string $value): ?string
    {
        $decoded = base64_decode($value, true);

        return is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
    }

    /**
     * @param  array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}  $delegationKey
     */
    private function storeDelegationKey(
        CacheRepository $cache,
        string $cacheKey,
        array $delegationKey,
        CarbonImmutable $now,
    ): void {
        $fetchedAt = $now->getTimestamp();

        try {
            $json = json_encode([
                'schema' => 1,
                'fetched_at' => $fetchedAt,
                'key' => $delegationKey,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Azure user delegation key could not be cached.');
        }

        $cache->put($cacheKey, Crypt::encryptString($json), self::CACHE_TTL_SECONDS);
        $this->rememberProcessDelegationKey($delegationKey, $fetchedAt);
    }

    /**
     * @param  array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}  $delegationKey
     */
    private function rememberProcessDelegationKey(array $delegationKey, int $fetchedAt): void
    {
        $this->processDelegationKey = $delegationKey;
        $this->processDelegationKeyFetchedAt = $fetchedAt;
    }

    private function forgetProcessDelegationKey(): void
    {
        $this->processDelegationKey = null;
        $this->processDelegationKeyFetchedAt = null;
    }

    private function cacheKey(): string
    {
        $tenantId = getenv('AZURE_TENANT_ID');
        $clientId = getenv('AZURE_CLIENT_ID');

        return 'azure-user-delegation-key:v1:'.hash('sha256', implode("\0", [
            $this->accountName,
            is_string($tenantId) ? $tenantId : '',
            is_string($clientId) ? $clientId : '',
        ]));
    }

    /**
     * @param  array{signed_oid: string, signed_tid: string, signed_start: string, signed_expiry: string, signed_service: string, signed_version: string, value: string}  $delegationKey
     */
    private function queryString(
        CarbonImmutable $start,
        CarbonImmutable $expiry,
        array $delegationKey,
        string $signature,
    ): string {
        $values = [
            'st' => $start->format(self::DATE_FORMAT),
            'se' => $expiry->format(self::DATE_FORMAT),
            'sp' => 'r',
            'spr' => 'https',
            'sv' => self::SAS_VERSION,
            'sr' => 'b',
            'skoid' => $delegationKey['signed_oid'],
            'sktid' => $delegationKey['signed_tid'],
            'skt' => $delegationKey['signed_start'],
            'ske' => $delegationKey['signed_expiry'],
            'sks' => $delegationKey['signed_service'],
            'skv' => $delegationKey['signed_version'],
            'sig' => $signature,
        ];

        $parts = [];
        foreach ($values as $name => $value) {
            $parts[] = $name.'='.str_replace('%2F', '/', rawurlencode($value));
        }

        return implode('&', $parts);
    }
}
