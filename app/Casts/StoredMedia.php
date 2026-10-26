<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Media\MediaUrl;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<array|null, array|null> */
final class StoredMedia implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return $key === 'meta' ? MediaUrl::meta($decoded) : MediaUrl::canonicalItems($decoded);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($key === 'meta' ? MediaUrl::meta($value) : MediaUrl::canonicalItems($value), JSON_THROW_ON_ERROR);
    }
}
