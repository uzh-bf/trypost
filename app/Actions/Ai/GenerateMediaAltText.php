<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Ai\Agents\MediaAltTextGenerator;
use App\Models\Media;
use App\Models\User;
use App\Support\PostMediaRules;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Image;
use Throwable;

final class GenerateMediaAltText
{
    public static function execute(User $user, Media $asset, array $selection): string
    {
        $disk = Storage::disk();

        try {
            if (! $disk->exists($asset->path)) {
                throw new \RuntimeException('Missing image.');
            }

            $size = $disk->size($asset->path);
            $mime = $disk->mimeType($asset->path);
        } catch (Throwable) {
            throw ValidationException::withMessages(['media_id' => __('posts.composer.media_editor.alt_image_missing')]);
        }

        if ($size > 3 * 1024 * 1024) {
            throw ValidationException::withMessages(['media_id' => __('posts.composer.media_editor.alt_image_too_large')]);
        }

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['media_id' => __('posts.composer.media_editor.alt_image_format')]);
        }

        $response = (new MediaAltTextGenerator($user->locale))->withModelSelection($selection)->prompt(
            'Write the alt text for this image.',
            attachments: [Image::fromStorage($asset->path)],
            provider: $selection['provider'],
            model: $selection['model'],
        );

        if (trim((string) $response) === '') {
            throw ValidationException::withMessages(['media_id' => __('posts.composer.media_editor.alt_generate_error')]);
        }

        return mb_substr(trim((string) $response), 0, PostMediaRules::ALT_TEXT_MAX_LENGTH);
    }
}
