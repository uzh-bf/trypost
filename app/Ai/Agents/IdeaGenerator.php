<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\UsesAiModelSelection;
use App\Enums\User\Locale;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Strict]
class IdeaGenerator implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;
    use UsesAiModelSelection;

    private const float TEMPERATURE = 0.8;

    public function __construct(
        public Locale $locale,
        public string $business,
        public string $audience,
        public ?string $notes = null,
        public readonly ?string $writingStyleInstructions = null,
    ) {}

    public function instructions(): string
    {
        return view('prompts.ideas.generator', [
            'business' => $this->business,
            'audience' => $this->audience,
            'notes' => $this->notes,
            'language' => $this->locale->promptLanguage(),
            'writing_style_instructions' => $this->writingStyleInstructions,
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'body' => $schema->string()->required(),
        ];
    }
}
