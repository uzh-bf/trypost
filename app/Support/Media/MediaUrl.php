<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class MediaUrl
{
    public static function isAzure(): bool
    {
        return config('filesystems.disks.'.config('filesystems.default').'.driver') === 'azure-flat';
    }

    public static function verifiesStorage(): bool
    {
        return self::isAzure() || (bool) config('filesystems.verify_media');
    }

    public static function canonical(string $path, ?string $id = null): string
    {
        if (! self::isAzure()) {
            return Storage::url($path);
        }

        if ($id !== null && $id !== '') {
            return route('app.media.view', ['media' => $id]);
        }

        $disk = config('filesystems.disks.'.config('filesystems.default'));

        return 'https://'.$disk['account_name'].'.blob.core.windows.net/'.$disk['container'].'/'.rawurlencode(FlatBlobAdapter::encodePath($path));
    }

    public static function browser(Media $media): string
    {
        if (self::isAzure() && $media->workspace_id !== null) {
            return self::canonical($media->path, $media->id);
        }

        return self::isAzure() ? (self::avatar($media->path) ?? '') : Storage::url($media->path);
    }

    public static function exists(string $path): bool
    {
        try {
            return $path !== '' && Storage::exists($path);
        } catch (Throwable) {
            throw new RuntimeException('Media storage is unavailable.');
        }
    }

    public static function temporary(string $path, int $minutes = 15): string
    {
        if (! self::exists($path)) {
            abort(404);
        }

        try {
            return self::isAzure()
                ? Storage::temporaryUrl($path, now()->addMinutes($minutes))
                : Storage::url($path);
        } catch (Throwable) {
            throw new RuntimeException('Unable to grant media access.');
        }
    }

    public static function publishing(string $path, string $fallback = ''): string
    {
        if ($path === '') {
            return $fallback;
        }
        if (! self::verifiesStorage()) {
            return $fallback !== '' ? $fallback : Storage::url($path);
        }

        return self::temporary($path, 26 * 60);
    }

    public static function avatar(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (! self::isAzure()) {
            return Storage::url($path);
        }

        try {
            return self::temporary($path, 60);
        } catch (Throwable) {
            return null;
        }
    }

    /** Rebuild only owned file references; external URLs and prose are data. */
    public static function canonicalItems(array $value): array
    {
        if (! self::verifiesStorage()) {
            return $value;
        }

        if (isset($value['path']) && is_string($value['path']) && $value['path'] !== '' && isset($value['id'])) {
            $value['url'] = self::canonical($value['path'], (string) $value['id']);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalItems($item);
            }
        }

        return $value;
    }

    /** Called only by resources after their existing workspace authorization. */
    public static function apiItems(array $value, ?string $workspaceId = null): array
    {
        if (! self::verifiesStorage()) {
            return $value;
        }

        if (isset($value['path']) && is_string($value['path']) && $value['path'] !== '' && isset($value['id'])) {
            $owned = Media::query()->whereKey($value['id'])->where('path', $value['path'])
                ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))->exists();
            $value['url'] = null;
            if ($owned) {
                try {
                    $value['url'] = self::temporary($value['path']);
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 404) {
                        throw $exception;
                    }
                }
            }
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::apiItems($item, $workspaceId);
            }
        }

        return $value;
    }

    public static function meta(array $value, bool $forApi = false, ?string $workspaceId = null): array
    {
        foreach ($value['thread_replies'] ?? [] as $index => $reply) {
            if (is_array($reply) && is_array($reply['media'] ?? null)) {
                $value['thread_replies'][$index]['media'] = $forApi
                    ? self::apiItems($reply['media'], $workspaceId)
                    : self::canonicalItems($reply['media']);
            }
        }

        return $value;
    }
}
