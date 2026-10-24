<?php

declare(strict_types=1);

namespace App\Ai\Agents\Concerns;

use Laravel\Ai\Enums\Lab;

trait UsesAiModelSelection
{
    /** @var array<string, mixed>|null */
    private ?array $aiModelSelection = null;

    public function withModelSelection(array $selection, bool $fitting = false): static
    {
        $this->aiModelSelection = $selection;

        if ($fitting) {
            $this->aiModelSelection['timeout'] = $selection['fitting_timeout'];
        }

        return $this;
    }

    public function maxTokens(): int
    {
        return $this->aiModelSelection['max_tokens'] ?? 4096;
    }

    public function timeout(): int
    {
        return $this->aiModelSelection['timeout'] ?? 50;
    }

    public function temperature(): ?float
    {
        return ($this->aiModelSelection['temperature'] ?? true) ? self::TEMPERATURE : null;
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;

        return $driver === ($this->aiModelSelection['driver'] ?? null)
            ? $this->aiModelSelection['options']
            : [];
    }
}
