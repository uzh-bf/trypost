<?php

declare(strict_types=1);

namespace App\Support\Media;

use AzureOss\Identity\AccessToken;
use AzureOss\Identity\TokenCredential;
use AzureOss\Identity\TokenRequestContext;

final readonly class RotatingWorkloadIdentityCredential implements TokenCredential
{
    public function __construct(private TokenCredential $credential) {}

    public function getToken(TokenRequestContext $context): AccessToken
    {
        // Projected tokens rotate through directory symlinks, so clearing only the filename leaves stale targets cached.
        clearstatcache(true);

        return $this->credential->getToken($context);
    }
}
