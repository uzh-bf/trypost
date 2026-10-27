<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Ai\Agents\IdeaGenerator;
use App\Models\User;
use App\Support\AiPromptRules;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\Data\FinishReason;

class GenerateIdea
{
    /**
     * @return array{title: string, body: string}|null
     */
    public static function execute(User $user, string $business, string $audience, ?string $notes, array $selection, ?string $writingStyleInstructions = null): ?array
    {
        $response = (new IdeaGenerator($user->locale, $business, $audience, $notes, $writingStyleInstructions))
            ->withModelSelection($selection)
            ->prompt('Suggest one content idea.', provider: $selection['provider'], model: $selection['model']);

        if ($response->steps->contains(fn ($step): bool => $step->finishReason === FinishReason::Length)) {
            throw new AiException('Incomplete idea response.');
        }

        if (! is_string(data_get($response, 'title')) || ! is_string(data_get($response, 'body'))) {
            return null;
        }

        $title = Str::substr(trim($response['title']), 0, 255);

        $body = trim((string) data_get($response, 'body'));

        if ($title === '' || $body === '') {
            return null;
        }

        return [
            'title' => $title,
            'body' => Str::substr($body, 0, AiPromptRules::PROMPT_MAX_LENGTH),
        ];
    }
}
