<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\UsesAiModelSelection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Promptable;

class PostContentShortener implements Agent, HasProviderOptions
{
    use Promptable;
    use UsesAiModelSelection;

    private const float TEMPERATURE = 0.2;

    public function __construct(
        public string $platformLabel,
        public int $limit,
    ) {}

    public function instructions(): string
    {
        return view('prompts.post_content.shortener', [
            'platform_label' => $this->platformLabel,
            'limit' => $this->limit,
            'target' => max(1, (int) floor($this->limit * 0.95)),
        ])->render();
    }
}
