<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Throwable;

final class AiModelCatalog
{
    public function defaultId(): string
    {
        return filled(config('ai_models.default')) ? (string) config('ai_models.default') : 'legacy';
    }

    /** @return array<string, mixed> */
    public function resolve(?string $id = null, string $capability = 'text'): array
    {
        $id ??= $this->defaultId();
        $selection = $this->selection($id);

        if ($selection === null || ! in_array($capability, $selection['capabilities'], true)) {
            throw ValidationException::withMessages(['model_id' => __('posts.composer.ai_model_unavailable')]);
        }

        return $selection;
    }

    /** @return array{default: string, options: list<array<string, mixed>>} */
    public function forFrontend(): array
    {
        $ids = $this->defaultId() === 'legacy' ? ['legacy'] : array_keys(config('ai_models.profiles', []));
        $options = [];

        foreach ($ids as $id) {
            $selection = $this->selection($id);

            if ($selection !== null) {
                $options[] = Arr::only($selection, ['id', 'label', 'destination', 'capabilities']);
            }
        }

        return ['default' => $this->defaultId(), 'options' => $options];
    }

    /** @return array<string, mixed>|null */
    private function selection(string $id): ?array
    {
        $legacy = $this->defaultId() === 'legacy';

        if ($legacy !== ($id === 'legacy')) {
            return null;
        }

        if ($legacy) {
            $provider = config('ai.default');
            $provider = $provider instanceof Lab ? $provider->value : $provider;

            if (! is_string($provider)) {
                return null;
            }

            try {
                $model = Ai::textProvider($provider)->defaultTextModel();
            } catch (Throwable) {
                return null;
            }

            $profile = [
                'provider' => $provider, 'model' => $model, 'enabled' => true,
                'label' => 'AI', 'destination' => 'configured',
                'temperature' => true, 'capabilities' => ['text', 'structured', 'vision'],
            ];
        } else {
            $profile = config('ai_models.profiles', [])[$id] ?? null;
        }

        if (! is_array($profile) || ! data_get($profile, 'enabled', false)) {
            return null;
        }

        $provider = data_get($profile, 'provider');
        $model = data_get($profile, 'model');
        $connection = is_string($provider) ? config('ai.providers.'.$provider) : null;

        if (! is_array($connection) || ! is_string($model) || blank($model)) {
            return null;
        }

        $driver = data_get($connection, 'driver');
        $keyless = in_array($driver, ['ollama', 'openai-compatible'], true);
        $legacyBedrockCredentials = $legacy && $driver === 'bedrock' && (
            ($connection['use_default_credential_provider'] ?? true)
            || (filled(data_get($connection, 'access_key_id')) && filled(data_get($connection, 'secret_access_key')))
        );

        if ((! $keyless && ! $legacyBedrockCredentials && blank(data_get($connection, 'key')))
            || (($provider === 'litellm' || $keyless) && blank(data_get($connection, 'url')))) {
            return null;
        }

        // Only policy options may supplement the SDK-owned request fields.
        $options = Arr::only(data_get($profile, 'options', []), ['reasoning', 'provider', 'num_retries', 'disable_fallbacks']);

        return [
            'id' => $id,
            'label' => (string) data_get($profile, 'label', $id),
            'destination' => (string) data_get($profile, 'destination', 'configured'),
            'provider' => $provider,
            'driver' => $driver instanceof Lab ? $driver->value : $driver,
            'model' => $model,
            'capabilities' => data_get($profile, 'capabilities', []),
            'temperature' => (bool) data_get($profile, 'temperature', false),
            'options' => $options,
            'max_tokens' => max(1, min(4096, (int) config('ai_models.max_tokens', 4096))),
            'timeout' => max(1, min(50, (int) config('ai_models.timeout', 50))),
            'fitting_timeout' => max(1, min(40, (int) config('ai_models.fitting_timeout', 40))),
        ];
    }
}
