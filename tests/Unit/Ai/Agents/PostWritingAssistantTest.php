<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\User\Locale;

test('caption inputs and selected guidance reach every action literally', function (PostAssistantMode $mode) {
    $caption = 'Original & brand <caption> https://example.test/?a=1&b=2';
    $previous = 'Previous <suggestion> & details';
    $guide = 'Synthetic style <marker> & literal guidance';
    $agent = new PostWritingAssistant($mode, $caption, Locale::English, previousContent: $previous, writingStyleInstructions: $guide);

    expect($agent->instructions())->toContain($caption, $previous, $guide)
        ->and($agent->writingStyleInstructions)->toBe($guide);
})->with(PostAssistantMode::cases());

test('a default agent does not inherit another agents selected guidance', function () {
    $guide = 'Synthetic isolation marker';
    $styled = new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::English, writingStyleInstructions: $guide);
    $default = new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::English);

    expect($styled->instructions())->toContain($guide)
        ->and($default->writingStyleInstructions)->toBeNull()
        ->and($default->instructions())->not->toContain($guide);
});
