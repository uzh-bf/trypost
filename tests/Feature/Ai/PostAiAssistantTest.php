<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentShortener;
use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ContentSanitizer;
use App\Support\AiPromptRules;
use Illuminate\Auth\Access\Response as AuthResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Prompts\AgentPrompt;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config(['ai_models.default' => null, 'ai.default' => 'openai', 'ai.providers.openai.key' => 'test-key']);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('legacy creation and progress endpoints are retired', function () {
    expect(Route::has('app.posts.ai.create'))->toBeFalse();
    expect(Route::has('app.posts.ai.loading'))->toBeFalse();
    expect(Route::has('api.posts.ai.assist'))->toBeFalse();
    expect(Route::has('api.posts.ai.alt-text'))->toBeFalse();
    expect(Route::has('app.posts.ai.generate'))->toBeFalse();
    expect(Route::has('app.posts.ai.review'))->toBeFalse();
    expect(Route::has('app.posts.ai.regenerate-media'))->toBeFalse();
});

test('legacy AI creation link opens the assistant in the composer', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.create', ['ai' => 1, 'templates' => 1]))
        ->assertRedirect(route('app.posts.index'))
        ->assertSessionHas('flash.openPostComposer.assistant', true);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['compose' => 1, 'assistant' => 1]))
        ->assertRedirect(route('app.posts.index'))
        ->assertSessionHas('flash.openPostComposer.assistant', true);
});

test('assistant requires authentication', function () {
    $this->postJson(route('app.posts.ai.assist'), [
        'mode' => 'generate',
        'prompt' => 'A post about our new product',
    ])->assertStatus(Response::HTTP_UNAUTHORIZED);
});

test('assistant rejects the retired write_more mode', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'write_more', 'prompt' => 'A post about our new product'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mode']);
});

test('assistant validates the prompt, content and platform', function (array $payload, string $field) {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'generate without prompt' => [['mode' => 'generate'], 'prompt'],
    'rephrase without content' => [['mode' => 'rephrase'], 'current_content'],
    'unknown platform' => [['mode' => 'generate', 'prompt' => 'announce our summer launch today', 'platform' => 'myspace'], 'platform'],
    'prompt under four words' => [['mode' => 'generate', 'prompt' => 'make it pop'], 'prompt'],
    'prompt over the length limit' => [['mode' => 'generate', 'prompt' => str_repeat('word ', 2000).'x'], 'prompt'],
]);

test('a short prompt is told how many words it needs', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'make it pop'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt' => __('posts.composer.assistant_prompt_min_words', ['count' => AiPromptRules::PROMPT_MIN_WORDS])]);
});

test('assistant accepts prompts that meet the word and length rules', function (string $prompt) {
    PostWritingAssistant::fake(['ok']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => $prompt])
        ->assertOk();
})->with([
    'japanese prompt' => ['新商品を紹介'],
    'prompt at the length limit' => [str_repeat('word ', 1999).'words'],
]);

test('web assistant suggests a caption without creating a post', function () {
    PostWritingAssistant::fake(['A fresh caption']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'generate',
            'prompt' => 'Introduce our new product',
        ])
        ->assertOk()
        ->assertJsonPath('content', 'A fresh caption');

    PostWritingAssistant::assertPrompted('Introduce our new product');
    $this->assertDatabaseCount('posts', 0);
});

test('assistant writes in the requesting user locale', function () {
    PostWritingAssistant::fake(['Olá mundo']);
    $this->user->update(['locale' => Locale::PortugueseBrazil]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'rephrase',
            'current_content' => 'Hello world',
        ])
        ->assertOk();

    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->locale === Locale::PortugueseBrazil);
});

test('a suggestion for X is shortened to fit what X counts', function () {
    config()->set('trypost.self_hosted', true);
    PostWritingAssistant::fake([str_repeat('Great news for everyone. ', 16)]);
    PostContentShortener::fake(['Great news, short and sweet.']);

    $content = $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'generate',
            'prompt' => 'announce our summer launch today',
            'platform' => Platform::X->value,
        ])
        ->assertOk()
        ->json('content');

    expect($content)->toBe('Great news, short and sweet.')
        ->and(Platform::X->contentOverflow(app(ContentSanitizer::class)->displayText($content, Platform::X)))->toBe(0);

    PostContentShortener::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Great news for everyone.'));
});

test('the assistant writes for the account limit of the card it serves', function (array $meta, int $limit, bool $shortened) {
    config()->set('trypost.self_hosted', true);
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'meta' => $meta]);
    $long = trim(str_repeat('Great news for everyone. ', 16));
    PostWritingAssistant::fake([$long]);
    PostContentShortener::fake(['Great news, short and sweet.']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'generate',
            'prompt' => 'announce our summer launch today',
            'platform' => Platform::X->value,
            'social_account_id' => $account->id,
        ])
        ->assertOk()
        ->assertJsonPath('content', $shortened ? 'Great news, short and sweet.' : $long);

    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->hardMaxChars === $limit);
})->with([
    'premium account' => [['x_subscription_type' => 'Premium'], 25000, false],
    'free account' => [[], 280, true],
]);

test('the assistant rewrites a post as long as the longest post', function () {
    PostWritingAssistant::fake(['Shorter']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'shorten',
            'current_content' => str_repeat('a', 20000),
            'previous_content' => str_repeat('b', 20000),
        ])
        ->assertOk();
});

test('the assistant only accepts an account of the current workspace', function () {
    $foreign = SocialAccount::factory()->x()->create();

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'generate',
            'prompt' => 'announce our summer launch today',
            'social_account_id' => $foreign->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['social_account_id']);
});

test('a suggestion without a platform is returned unchanged', function () {
    config()->set('trypost.self_hosted', true);
    $long = trim(str_repeat('Great news for everyone. ', 16));
    PostWritingAssistant::fake([$long]);
    PostContentShortener::fake(['unused']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'generate',
            'prompt' => 'announce our summer launch today',
        ])
        ->assertOk()
        ->assertJsonPath('content', $long);

    PostContentShortener::assertNeverPrompted();
});

test('assistant denied by the AI gate never calls the model', function () {
    PostWritingAssistant::fake(['unused']);
    Gate::before(fn (User $user, string $ability): ?AuthResponse => $ability === 'useAi'
        ? AuthResponse::deny(__('billing.flash.subscription_required'))
        : null);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'generate',
            'prompt' => 'announce our summer launch today',
        ])
        ->assertStatus(Response::HTTP_PAYMENT_REQUIRED)
        ->assertJsonPath('message', __('billing.flash.subscription_required'));

    PostWritingAssistant::assertNeverPrompted();
});

test('an unsubscribed account is stopped before the model is called', function () {
    config()->set('trypost.self_hosted', false);
    PostWritingAssistant::fake(['unused']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'generate',
            'prompt' => 'announce our summer launch today',
        ])
        ->assertRedirect(route('app.welcome.persona'));

    PostWritingAssistant::assertNeverPrompted();
});

test('an empty model response is a validation error on the prompt', function () {
    PostWritingAssistant::fake(['   ']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'generate',
            'prompt' => 'announce our summer launch today',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt']);
});

test('regenerate sends the previous suggestion to the agent', function () {
    PostWritingAssistant::fake(['A different take']);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), [
            'mode' => 'regenerate',
            'prompt' => 'announce our summer launch today',
            'previous_content' => 'Old version',
        ])
        ->assertOk()
        ->assertJsonPath('content', 'A different take');

    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->previousContent === 'Old version'
        && str_contains($prompt->agent->instructions(), 'Old version'));
});

test('assistant route keeps its rate limit', function () {
    expect(collect(Route::getRoutes()->getByName('app.posts.ai.assist')->gatherMiddleware()))
        ->toContain('throttle:10,1');
});

test('a failing provider returns a translated bad gateway', function () {
    PostWritingAssistant::fake([fn () => throw new ConnectionException('timeout')]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.ai.assist'), ['mode' => PostAssistantMode::Generate->value, 'current_content' => '', 'prompt' => 'Write a short post please'])
        ->assertStatus(Response::HTTP_BAD_GATEWAY)
        ->assertJsonPath('message', __('posts.composer.assistant_error'));
});

test('default style works when the optional guide is missing', function (array $choice) {
    config(['ai_writing.df_style_path' => '/missing/synthetic-style.md']);
    PostWritingAssistant::fake(['Synthetic response']);

    $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
        ...$choice, 'mode' => 'generate', 'prompt' => 'Write a synthetic test announcement',
    ])->assertOk();

    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->writingStyleInstructions === null);
})->with([[[]], [['style_id' => null]], [['style_id' => 'default']]]);

test('invalid styles fail before generation with a sanitized field error', function (string $case) {
    if ($case === 'unreadable' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Root can read files without read permission.');
    }
    $path = tempnam(sys_get_temp_dir(), 'style-test-');
    file_put_contents($path, 'Synthetic guide');
    $id = 'df';
    $configured = $path;
    match ($case) {
        'unknown' => $id = 'unknown',
        'unconfigured' => $configured = null,
        'missing' => $configured = $path.'-missing',
        'relative' => $configured = 'relative.md',
        'wrapper' => $configured = 'file://'.$path,
        'directory' => $configured = sys_get_temp_dir(),
        'empty' => file_put_contents($path, " \n"),
        'oversize' => file_put_contents($path, str_repeat('x', 8193)),
        'encoding' => file_put_contents($path, "\xFF"),
        'binary' => file_put_contents($path, "test\0guide"),
        'unreadable' => chmod($path, 0000),
    };
    config(['ai_writing.df_style_path' => $configured]);
    PostWritingAssistant::fake();

    try {
        $response = $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
            'style_id' => $id, 'mode' => 'generate', 'prompt' => 'Write a synthetic test announcement',
        ])->assertUnprocessable()->assertJsonValidationErrors('style_id');
        expect($response->getContent())->not->toContain($path);
        PostWritingAssistant::assertNeverPrompted();
    } finally {
        chmod($path, 0600);
        unlink($path);
    }
})->with(['unknown', 'unconfigured', 'missing', 'relative', 'wrapper', 'directory', 'empty', 'oversize', 'encoding', 'binary', 'unreadable']);

test('DF resolves a bounded symlinked UTF8 guide once and isolates later requests', function () {
    $path = tempnam(sys_get_temp_dir(), 'style-test-');
    $link = $path.'-link';
    $guide = str_pad('Synthetic & <brand> guide ä', 8192, 'x');
    file_put_contents($path, $guide);
    symlink($path, $link);
    config(['ai_writing.df_style_path' => $link]);
    $calls = 0;
    PostWritingAssistant::fake(function () use ($path, &$calls): string {
        $calls++;
        file_put_contents($path, 'Changed after resolution');

        return 'Synthetic result';
    });

    try {
        foreach (['df', 'default'] as $id) {
            $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
                'style_id' => $id, 'mode' => 'generate', 'prompt' => 'Write a synthetic test announcement',
            ])->assertOk();
            PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->writingStyleInstructions === ($id === 'df' ? $guide : null));
        }
        expect($calls)->toBe(2);
    } finally {
        unlink($link);
        unlink($path);
    }
});
