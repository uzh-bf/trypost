<?php

declare(strict_types=1);

use App\Support\Media\RotatingWorkloadIdentityCredential;
use AzureOss\Identity\AccessToken;
use AzureOss\Identity\CredentialUnavailableException;
use AzureOss\Identity\TokenCredential;
use AzureOss\Identity\TokenRequestContext;
use AzureOss\Identity\WorkloadIdentityCredential;
use AzureOss\Identity\WorkloadIdentityCredentialOptions;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

test('the application retains one rotation-aware credential', function () {
    $credential = app(TokenCredential::class);

    expect($credential)->toBeInstanceOf(RotatingWorkloadIdentityCredential::class)
        ->and(app(TokenCredential::class))->toBe($credential);
});

test('credential delegation preserves context, result and exceptions', function () {
    $context = new TokenRequestContext(['https://storage.azure.com/.default']);
    $token = new AccessToken('synthetic-access-token', new DateTimeImmutable('+1 hour'), 'Bearer');
    $failure = new CredentialUnavailableException('Synthetic credential failure');
    $inner = Mockery::mock(TokenCredential::class);
    $inner->shouldReceive('getToken')->once()->with($context)->andReturn($token);
    $inner->shouldReceive('getToken')->once()->with($context)->andThrow($failure);
    $credential = new RotatingWorkloadIdentityCredential($inner);

    expect($credential->getToken($context))->toBe($token);

    try {
        $credential->getToken($context);
        $this->fail('The credential failure must propagate.');
    } catch (CredentialUnavailableException $caught) {
        expect($caught)->toBe($failure);
    }
});

test('the same credential reads the current assertion after repeated projected token rotations', function () {
    expect(ini_get('realpath_cache_size'))->not->toBe('0')
        ->and((int) ini_get('realpath_cache_ttl'))->toBeGreaterThan(0);
    $root = sys_get_temp_dir().'/trypost-token-rotation-'.bin2hex(random_bytes(8));
    mkdir($root);
    mkdir($root.'/v1');
    file_put_contents($root.'/v1/token', 'sample-1');
    symlink('v1', $root.'/..data');
    symlink('..data/token', $root.'/token');
    $history = [];
    $response = fn () => new Response(200, [], json_encode([
        'access_token' => 'synthetic-access-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ], JSON_THROW_ON_ERROR));
    $handler = HandlerStack::create(new MockHandler([$response(), $response(), $response()]));
    $handler->push(Middleware::history($history));
    $credential = new RotatingWorkloadIdentityCredential(new WorkloadIdentityCredential(
        new WorkloadIdentityCredentialOptions(
            clientId: 'synthetic-client',
            tenantId: 'synthetic-tenant',
            tokenFilePath: $root.'/token',
            httpClient: new Client(['handler' => $handler]),
        ),
    ));
    $context = new TokenRequestContext(['https://storage.azure.com/.default']);

    try {
        $credential->getToken($context);

        foreach ([2, 3] as $generation) {
            (new Process([PHP_BINARY, '-r', <<<'PHP'
                $root = $argv[1];
                $generation = (int) $argv[2];
                mkdir($root.'/v'.$generation);
                file_put_contents($root.'/v'.$generation.'/token', 'sample-'.$generation);
                symlink('v'.$generation, $root.'/..next');
                rename($root.'/..next', $root.'/..data');
                unlink($root.'/v'.($generation - 1).'/token');
                rmdir($root.'/v'.($generation - 1));
                PHP, $root, (string) $generation]))->mustRun();

            $credential->getToken($context);
        }

        expect($history)->toHaveCount(3);

        foreach ($history as $index => $exchange) {
            parse_str((string) $exchange['request']->getBody(), $payload);
            expect($payload['client_assertion'])->toBe('sample-'.($index + 1))
                ->and($payload['scope'])->toBe('https://storage.azure.com/.default');
        }
    } finally {
        clearstatcache(true);
        File::deleteDirectory($root);
    }
});

test('missing and unreadable tokens fail before a token exchange', function (bool $exists) {
    $path = sys_get_temp_dir().'/trypost-unreadable-token-'.bin2hex(random_bytes(8));
    $handler = new MockHandler;
    $credential = new RotatingWorkloadIdentityCredential(new WorkloadIdentityCredential(
        new WorkloadIdentityCredentialOptions(
            clientId: 'synthetic-client',
            tenantId: 'synthetic-tenant',
            tokenFilePath: $path,
            httpClient: new Client(['handler' => HandlerStack::create($handler)]),
        ),
    ));

    try {
        if ($exists) {
            file_put_contents($path, 'synthetic-assertion');
            chmod($path, 0000);
            expect(is_readable($path))->toBeFalse();
        }

        expect(fn () => $credential->getToken(new TokenRequestContext(['https://storage.azure.com/.default'])))
            ->toThrow(CredentialUnavailableException::class)
            ->and($handler->getLastRequest())->toBeNull();
    } finally {
        if ($exists) {
            chmod($path, 0600);
            unlink($path);
        }
    }
})->with(['missing' => false, 'unreadable' => true]);
