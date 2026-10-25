<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Validation\ValidationException;

final class WritingStyleCatalog
{
    public function resolve(?string $id = null): ?string
    {
        if ($id === null || $id === 'default') {
            return null;
        }

        $path = config('ai_writing.df_style_path');
        if ($id !== 'df' || ! is_string($path) || ! str_starts_with($path, '/')
            || str_contains($path, '://') || str_contains($path, "\0")) {
            $this->unavailable();
        }

        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            $this->unavailable();
        }

        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            $this->unavailable();
        }

        try {
            $instructions = stream_get_contents($handle, 8193);
        } finally {
            fclose($handle);
        }

        if ($instructions === false || strlen($instructions) > 8192
            || trim($instructions) === '' || ! mb_check_encoding($instructions, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $instructions)) {
            $this->unavailable();
        }

        return trim($instructions);
    }

    /** @return array{default: string, options: list<string>} */
    public function forFrontend(): array
    {
        return [
            'default' => 'default',
            'options' => filled(config('ai_writing.df_style_path')) ? ['default', 'df'] : ['default'],
        ];
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['style_id' => __('posts.composer.ai_style_unavailable')]);
    }
}
