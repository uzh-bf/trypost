<?php

declare(strict_types=1);

use App\Support\Media\AzureUserDelegationSas;
use AzureOss\Identity\AccessToken;
use AzureOss\Identity\TokenCredential;
use AzureOss\Identity\TokenRequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

final class AzureUserDelegationSasFakeCredential implements TokenCredential
{
    public int $calls = 0;

    /** @var list<list<string>> */
    public array $scopes = [];

    public function __construct(private readonly AccessToken $accessToken) {}

    public function getToken(TokenRequestContext $context): AccessToken
    {
        $this->calls++;
        $this->scopes[] = $context->scopes;

        return $this->accessToken;
    }
}

final class AzureUserDelegationSasTrackingStore extends ArrayStore
{
    public int $lockCalls = 0;

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->lockCalls++;

        return parent::lock($name, $seconds, $owner);
    }
}

/**
 * @return array<string, mixed>
 */
function azureUserDelegationFixture(): array
{
    $contents = file_get_contents(base_path('tests/fixtures/media/user-delegation-sas.json'));
    if ($contents === false) {
        throw new RuntimeException('Azure user delegation SAS fixture is missing.');
    }

    return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, string>  $overrides
 */
function azureUserDelegationKeyXml(array $fixture, array $overrides = []): string
{
    $key = array_replace(data_get($fixture, 'fixed.delegation_key', []), $overrides);
    $element = static fn (string $name, string $value): string => sprintf(
        '<%s>%s</%s>',
        $name,
        htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        $name,
    );

    return '<?xml version="1.0" encoding="utf-8"?><UserDelegationKey>'
        .$element('SignedOid', data_get($key, 'signed_oid'))
        .$element('SignedTid', data_get($key, 'signed_tid'))
        .$element('SignedStart', data_get($key, 'signed_start'))
        .$element('SignedExpiry', data_get($key, 'signed_expiry'))
        .$element('SignedService', data_get($key, 'signed_service'))
        .$element('SignedVersion', data_get($key, 'signed_version'))
        .$element('Value', data_get($key, 'value'))
        .'</UserDelegationKey>';
}

function azureUserDelegationFakeCredential(): AzureUserDelegationSasFakeCredential
{
    return new AzureUserDelegationSasFakeCredential(new AccessToken(
        'entra-access-token-secret',
        CarbonImmutable::parse('2026-10-25T11:00:00Z'),
        'Bearer',
    ));
}

/**
 * @param  array<string, mixed>  $fixture
 */
function azureUserDelegationSigner(
    array $fixture,
    TokenCredential $credential,
    Repository $cache,
): AzureUserDelegationSas {
    return new AzureUserDelegationSas(
        $credential,
        data_get($fixture, 'fixed.account_name'),
        data_get($fixture, 'fixed.container_name'),
        $cache,
    );
}

function azureUserDelegationExpiry(array $fixture): CarbonImmutable
{
    return CarbonImmutable::parse(data_get($fixture, 'fixed.expiry'));
}

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-25T10:00:00Z'));
});

test('it matches deterministic official sdk signing vectors', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $store = new AzureUserDelegationSasTrackingStore;
    $cache = new Repository($store);
    Http::fake([
        'https://trypostsynthetic.blob.core.windows.net/*' => Http::response(
            azureUserDelegationKeyXml($fixture),
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $signer = azureUserDelegationSigner($fixture, $credential, $cache);

    foreach (data_get($fixture, 'vectors') as $vector) {
        expect($signer->url(data_get($vector, 'blob_name'), azureUserDelegationExpiry($fixture)))
            ->toBe(data_get($vector, 'url'));
    }

    expect($credential->calls)->toBe(1)
        ->and($credential->scopes)->toBe([['https://storage.azure.com/.default']])
        ->and($store->lockCalls)->toBe(1);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://trypostsynthetic.blob.core.windows.net/?restype=service&comp=userdelegationkey'
            && $request->hasHeader('Authorization', 'Bearer entra-access-token-secret')
            && $request->hasHeader('x-ms-version', '2023-11-03')
            && $request->body() === '<?xml version="1.0" encoding="utf-8"?><KeyInfo><Start>2026-10-25T09:55:00Z</Start><Expiry>2026-10-27T10:00:00Z</Expiry></KeyInfo>';
    });
});

test('it caches only the encrypted delegation key and reuses it across processes', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $store = new AzureUserDelegationSasTrackingStore;
    $cache = new Repository($store);
    Http::fake([
        'https://trypostsynthetic.blob.core.windows.net/*' => Http::response(azureUserDelegationKeyXml($fixture)),
    ]);

    $signer = azureUserDelegationSigner($fixture, $credential, $cache);
    $signer->url(data_get($fixture, 'vectors.0.blob_name'), azureUserDelegationExpiry($fixture));

    $entries = $store->all();
    expect($entries)->toHaveCount(1);

    $encrypted = data_get(array_values($entries)[0], 'value');
    expect($encrypted)->toBeString()
        ->not->toContain(data_get($fixture, 'fixed.delegation_key.value'))
        ->not->toContain('entra-access-token-secret')
        ->not->toContain('sig=');

    $payload = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
    expect(data_get($payload, 'key.value'))->toBe(data_get($fixture, 'fixed.delegation_key.value'))
        ->and(data_get($payload, 'fetched_at'))->toBe(CarbonImmutable::now()->getTimestamp());

    $signer->url(data_get($fixture, 'vectors.1.blob_name'), azureUserDelegationExpiry($fixture));
    $secondSigner = azureUserDelegationSigner($fixture, $credential, $cache);
    $secondSigner->url(data_get($fixture, 'vectors.2.blob_name'), azureUserDelegationExpiry($fixture));

    expect($credential->calls)->toBe(1)
        ->and($store->lockCalls)->toBe(1);

    $this->travel(299)->seconds();
    $cachedSigner = azureUserDelegationSigner($fixture, $credential, $cache);
    $cachedSigner->url(data_get($fixture, 'vectors.0.blob_name'), azureUserDelegationExpiry($fixture));
    expect($credential->calls)->toBe(1);

    $this->travel(1)->seconds();
    $refreshedSigner = azureUserDelegationSigner($fixture, $credential, $cache);
    $refreshedSigner->url(data_get($fixture, 'vectors.0.blob_name'), azureUserDelegationExpiry($fixture));

    expect($credential->calls)->toBe(2)
        ->and($store->lockCalls)->toBe(2);
    Http::assertSentCount(2);
});

test('it namespaces the delegation key cache by workload identity', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $cache = new Repository(new AzureUserDelegationSasTrackingStore);
    Http::fake([
        'https://trypostsynthetic.blob.core.windows.net/*' => Http::response(azureUserDelegationKeyXml($fixture)),
    ]);
    $tenantId = getenv('AZURE_TENANT_ID');
    $clientId = getenv('AZURE_CLIENT_ID');

    try {
        putenv('AZURE_TENANT_ID=synthetic-tenant-a');
        putenv('AZURE_CLIENT_ID=synthetic-client-a');
        azureUserDelegationSigner($fixture, $credential, $cache)
            ->url(data_get($fixture, 'vectors.0.blob_name'), azureUserDelegationExpiry($fixture));

        putenv('AZURE_TENANT_ID=synthetic-tenant-b');
        putenv('AZURE_CLIENT_ID=synthetic-client-b');
        azureUserDelegationSigner($fixture, $credential, $cache)
            ->url(data_get($fixture, 'vectors.0.blob_name'), azureUserDelegationExpiry($fixture));
    } finally {
        $tenantId === false ? putenv('AZURE_TENANT_ID') : putenv("AZURE_TENANT_ID={$tenantId}");
        $clientId === false ? putenv('AZURE_CLIENT_ID') : putenv("AZURE_CLIENT_ID={$clientId}");
    }

    expect($credential->calls)->toBe(2)
        ->and(count($cache->getStore()->all()))->toBe(2);
});

test('it rejects expired and overlong urls before resolving a key', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $signer = azureUserDelegationSigner($fixture, $credential, new Repository(new AzureUserDelegationSasTrackingStore));

    expect(fn () => $signer->url('avatar.webp', CarbonImmutable::now()))
        ->toThrow(InvalidArgumentException::class, 'Azure SAS expiry must be in the future and at most 26 hours away.')
        ->and(fn () => $signer->url('avatar.webp', CarbonImmutable::now()->addHours(26)->addSecond()))
        ->toThrow(InvalidArgumentException::class, 'Azure SAS expiry must be in the future and at most 26 hours away.')
        ->and($credential->calls)->toBe(0);
});

test('it rejects path-like and traversing physical blob names', function (string $physicalBlobName) {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $signer = azureUserDelegationSigner($fixture, $credential, new Repository(new AzureUserDelegationSasTrackingStore));

    expect(fn () => $signer->url($physicalBlobName, azureUserDelegationExpiry($fixture)))
        ->toThrow(InvalidArgumentException::class, 'Azure blob name must be a flat, non-empty path without traversal.')
        ->and($credential->calls)->toBe(0);
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'dot' => ['.'],
    'double dot' => ['..'],
    'slash' => ['folder/avatar.webp'],
    'backslash' => ['folder\\avatar.webp'],
    'control character' => ["avatar\n.webp"],
]);

test('it pins account and container names to an exact azure host', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $cache = new Repository(new AzureUserDelegationSasTrackingStore);

    foreach (['', 'ab', 'trypost-synthetic', 'trypost.example', 'TryPostSynthetic', str_repeat('a', 25)] as $accountName) {
        expect(fn () => new AzureUserDelegationSas($credential, $accountName, 'media', $cache))
            ->toThrow(InvalidArgumentException::class, 'Azure storage account name is invalid.');
    }

    foreach (['', 'ab', 'Media', 'media_name', 'media--one', str_repeat('a', 64)] as $containerName) {
        expect(fn () => new AzureUserDelegationSas($credential, 'trypostsynthetic', $containerName, $cache))
            ->toThrow(InvalidArgumentException::class, 'Azure blob container name is invalid.');
    }
});

test('it refuses to fetch without a lock-capable cache', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $store = Mockery::mock(Store::class);
    $store->shouldReceive('get')->once()->andReturnNull();
    $signer = azureUserDelegationSigner($fixture, $credential, new Repository($store));

    expect(fn () => $signer->url('avatar.webp', azureUserDelegationExpiry($fixture)))
        ->toThrow(RuntimeException::class, 'Azure user delegation SAS caching requires a cache store with lock support.')
        ->and($credential->calls)->toBe(0);
});

test('it rejects a key that does not cover the requested url bounds', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $cache = new Repository(new AzureUserDelegationSasTrackingStore);
    Http::fake([
        'https://trypostsynthetic.blob.core.windows.net/*' => Http::response(
            azureUserDelegationKeyXml($fixture, ['signed_expiry' => '2026-10-26T11:59:59Z']),
        ),
    ]);

    $signer = azureUserDelegationSigner($fixture, $credential, $cache);

    expect(fn () => $signer->url('avatar.webp', azureUserDelegationExpiry($fixture)))
        ->toThrow(RuntimeException::class, 'Azure returned a user delegation key outside the requested bounds.');

    expect(collect($cache->getStore()->all()))->toBeEmpty();
});

test('it produces a different deterministic signature when the delegation key changes', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $cache = new Repository(new AzureUserDelegationSasTrackingStore);
    $key = base64_decode(data_get($fixture, 'fixed.delegation_key.value'), true);
    $key[0] = chr(ord($key[0]) ^ 1);
    Http::fake([
        'https://trypostsynthetic.blob.core.windows.net/*' => Http::response(
            azureUserDelegationKeyXml($fixture, ['value' => base64_encode($key)]),
        ),
    ]);

    $signer = azureUserDelegationSigner($fixture, $credential, $cache);
    $tamperedUrl = $signer->url(data_get($fixture, 'vectors.0.blob_name'), azureUserDelegationExpiry($fixture));

    parse_str((string) parse_url($tamperedUrl, PHP_URL_QUERY), $tamperedQuery);
    parse_str((string) parse_url(data_get($fixture, 'vectors.0.url'), PHP_URL_QUERY), $expectedQuery);

    expect(data_get($tamperedQuery, 'sig'))
        ->not->toBe(data_get($expectedQuery, 'sig'))
        ->and(collect($tamperedQuery)->except('sig')->all())
        ->toBe(collect($expectedQuery)->except('sig')->all());
});

test('it sanitizes failed key responses instead of exposing their bodies', function () {
    $fixture = azureUserDelegationFixture();
    $credential = azureUserDelegationFakeCredential();
    $cache = new Repository(new AzureUserDelegationSasTrackingStore);
    Http::fake([
        'https://trypostsynthetic.blob.core.windows.net/*' => Http::response(
            '<UserDelegationKey><Value>delegation-key-secret entra-access-token-secret</Value></UserDelegationKey>',
            403,
        ),
    ]);

    $signer = azureUserDelegationSigner($fixture, $credential, $cache);

    try {
        $signer->url('avatar.webp', azureUserDelegationExpiry($fixture));
        $this->fail('The signer accepted a failed delegation key response.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Azure user delegation key request failed with HTTP 403.')
            ->not->toContain('delegation-key-secret')
            ->not->toContain('entra-access-token-secret')
            ->and($exception->getPrevious())->toBeNull();
    }
});
