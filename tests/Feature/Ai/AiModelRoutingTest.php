<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentShortener;
use App\Ai\Agents\PostWritingAssistant;
use App\Enums\SocialAccount\Platform;
use App\Models\Idea;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Repurpose\CaptionAdapter;
use App\Support\Ai\AiModelCatalog;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    config([
        'trypost.self_hosted' => true,
        'ai_models.default' => 'sol',
        'ai_models.profiles.sol.model' => 'test-sol-low',
        'ai_models.profiles.opus.model' => 'test-opus',
        'ai_models.profiles.gemini.model' => 'test-gemini',
        'ai.providers.litellm.url' => 'https://litellm.test/v1',
        'ai.providers.litellm.key' => 'test-litellm-key',
        'ai.providers.openrouter.key' => 'test-openrouter-key',
        'ai.providers.openai.key' => null,
    ]);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    Storage::fake();
});

function modelRoutingReply(Request $request, string $text, string $finish = 'stop'): array
{
    if (str_ends_with($request->url(), '/responses')) {
        return [
            'id' => 'test-response', 'model' => $request['model'],
            'status' => $finish === 'length' ? 'incomplete' : 'completed',
            'incomplete_details' => $finish === 'length' ? ['reason' => 'max_output_tokens'] : null,
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ];
    }

    return [
        'model' => $request['model'],
        'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => $finish]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ];
}

function modelRoutingImage(Workspace $workspace): Media
{
    $asset = Media::factory()->temporaryUpload($workspace)->create(['size' => 1]);
    Storage::put($asset->path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));

    return $asset;
}

test('each selection binds the native protocol credential and bounded policy', function (string $id) {
    Http::fake(function (Request $request, array $options) {
        expect($options['timeout'])->toBe(50);

        return Http::response(modelRoutingReply($request, 'A suggestion.'));
    });
    $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
        'model_id' => $id, 'mode' => 'rephrase', 'current_content' => 'Original caption',
        'provider' => 'untrusted', 'model' => 'untrusted', 'url' => 'https://untrusted.test',
        'key' => 'untrusted', 'options' => ['model' => 'untrusted'],
    ])->assertOk()->assertJsonPath('content', 'A suggestion.');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($id): bool {
        $body = $request->data();
        expect($body['model'])->toBe(config("ai_models.profiles.{$id}.model"))
            ->and($body['reasoning'])->toBe(['effort' => 'low'])
            ->and($body)->not->toHaveKeys(['key', 'url', 'options']);
        if ($id === 'sol') {
            expect($request->url())->toBe('https://litellm.test/v1/responses')
                ->and($request->hasHeader('Authorization', 'Bearer test-litellm-key'))->toBeTrue()
                ->and($body['store'])->toBeFalse()
                ->and($body['max_output_tokens'])->toBe(4096)
                ->and($body['num_retries'])->toBe(0)
                ->and($body['disable_fallbacks'])->toBeTrue()
                ->and($body)->toHaveKey('input')->not->toHaveKeys(['temperature', 'max_tokens', 'messages']);
        } else {
            expect($request->url())->toBe('https://openrouter.ai/api/v1/chat/completions')
                ->and($request->hasHeader('Authorization', 'Bearer test-openrouter-key'))->toBeTrue()
                ->and($body['max_tokens'])->toBe(4096)
                ->and($body['provider'])->toBe(['data_collection' => 'deny', 'allow_fallbacks' => false, 'require_parameters' => true])
                ->and($body)->toHaveKey('messages')->not->toHaveKeys(['input', 'max_output_tokens']);
            expect(array_key_exists('temperature', $body))->toBe($id === 'gemini');
            if ($id === 'gemini') {
                expect($body['temperature'])->toBe(0.7);
            }
        }

        return true;
    });
})->with(['sol', 'opus', 'gemini']);

test('omitted and null selection use the configured default', function (array $choice) {
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, 'Default suggestion.')));
    $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
        ...$choice, 'mode' => 'rephrase', 'current_content' => 'Original',
    ])->assertOk();
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'test-sol-low');
})->with([[[]], [['model_id' => null]]]);

test('unknown disabled missing or incompatible selections fail before a request', function (string $case) {
    $id = 'opus';
    match ($case) {
        'unknown' => $id = 'unknown',
        'disabled' => config(['ai_models.profiles.opus.enabled' => false]),
        'model' => config(['ai_models.profiles.opus.model' => null]),
        'key' => config(['ai.providers.openrouter.key' => null]),
        'capability' => config(['ai_models.profiles.opus.capabilities' => ['text']]),
        'default' => config(['ai_models.profiles.sol.enabled' => false]),
    };
    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), [
        ...($case === 'default' ? [] : ['model_id' => $id]), 'business' => 'A bakery', 'audience' => 'Neighbours',
    ])->assertUnprocessable()->assertJsonValidationErrors('model_id');
    Http::assertNothingSent();
})->with(['unknown', 'disabled', 'model', 'key', 'capability', 'default']);

test('legacy installations retain their configured single provider and model', function () {
    config([
        'ai_models.default' => null, 'ai.default' => 'openrouter',
        'ai.providers.openrouter.models.text.default' => 'legacy-model',
    ]);
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, 'Legacy suggestion.')));
    $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
        'mode' => 'rephrase', 'current_content' => 'Original',
    ])->assertOk();
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'legacy-model');
    expect(app(AiModelCatalog::class)->forFrontend()['options'])->toHaveCount(1)
        ->and(app(AiModelCatalog::class)->forFrontend()['default'])->toBe('legacy');
});

test('structured idea requests work on every selected native transport', function (string $id) {
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, json_encode(['title' => 'A title', 'body' => 'A useful idea']))));
    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), [
        'model_id' => $id, 'business' => 'A bakery', 'audience' => 'Neighbours',
    ])->assertOk()->assertJsonPath('title', 'A title')->assertJsonPath('body', 'A useful idea');
    Http::assertSent(function (Request $request) use ($id): bool {
        $format = $id === 'sol' ? $request['text']['format'] : $request['response_format']['json_schema'];
        expect($format['strict'])->toBeTrue()
            ->and($format['schema']['required'])->toContain('title', 'body');

        return true;
    });
})->with(['sol', 'opus', 'gemini']);

test('invalid incomplete or empty structured ideas return a sanitized failure', function (string $id, string $text, string $finish) {
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id]);
    $before = $idea->fresh()->getAttributes();
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, $text, $finish)));
    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), [
        'model_id' => $id, 'business' => 'A bakery', 'audience' => 'Neighbours',
    ])->assertStatus(502)->assertJsonPath('message', __('create.ideas.errors.generate_failed'));
    expect($idea->fresh()->getAttributes())->toBe($before);
})->with([
    ['opus', '{"title":', 'stop'], ['sol', '{"title":', 'stop'],
    ['opus', '{"title":"ok","body":"ok"}', 'length'], ['sol', '{"title":"ok","body":"ok"}', 'length'],
    ['opus', '{"title":"ok","body":"  "}', 'stop'], ['opus', '{"title":3,"body":[]}', 'stop'], ['opus', '{"title":" ","body":"ok"}', 'stop'],
]);

test('owned image bytes use the selected vision model on every transport', function (string $id) {
    $asset = modelRoutingImage($this->workspace);
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, 'An accessible description.')));
    $this->actingAs($this->user)->postJson(route('app.posts.ai.alt-text'), ['model_id' => $id, 'media_id' => $asset->id])
        ->assertOk()->assertJsonPath('alt_text', 'An accessible description.');
    Http::assertSent(function (Request $request) use ($asset, $id): bool {
        expect($request['model'])->toBe(config("ai_models.profiles.{$id}.model"));
        $encoded = base64_encode(Storage::get($asset->path));
        expect(json_encode($request->data(), JSON_UNESCAPED_SLASHES))->toContain('data:image/png;base64,'.$encoded);

        return true;
    });
})->with(['sol', 'opus', 'gemini']);

test('image admission rejects actual size format and missing objects before reading bytes', function (string $case) {
    $asset = modelRoutingImage($this->workspace);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->with($asset->path)->andReturn($case !== 'missing');
    if ($case !== 'missing') {
        $disk->shouldReceive('size')->with($asset->path)->andReturn($case === 'large' ? 3 * 1024 * 1024 + 1 : 100);
        $disk->shouldReceive('mimeType')->with($asset->path)->andReturn($case === 'gif' ? 'image/gif' : ($case === 'format' ? 'text/plain' : 'image/png'));
    }
    $disk->shouldNotReceive('get');
    Storage::shouldReceive('disk')->withNoArgs()->andReturn($disk);
    $this->actingAs($this->user)->postJson(route('app.posts.ai.alt-text'), ['model_id' => 'opus', 'media_id' => $asset->id])
        ->assertUnprocessable()->assertJsonValidationErrors('media_id');
    Http::assertNothingSent();
})->with(['large', 'gif', 'format', 'missing']);

test('empty alt text cannot replace the existing description', function () {
    $asset = modelRoutingImage($this->workspace);
    $before = $asset->fresh()->getAttributes();
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, '   ')));
    $this->actingAs($this->user)->postJson(route('app.posts.ai.alt-text'), ['media_id' => $asset->id])
        ->assertUnprocessable()->assertJsonValidationErrors('media_id');
    expect($asset->fresh()->getAttributes())->toBe($before);
});

test('the nested fitting request retains the primary route and fitting budget', function (string $id) {
    $calls = 0;
    Http::fake(function (Request $request, array $options) use (&$calls) {
        expect($options['timeout'])->toBe($calls === 0 ? 50 : 40);

        return Http::response(modelRoutingReply($request, $calls++ === 0 ? str_repeat('long caption ', 60) : 'Fits.'));
    });
    $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
        'model_id' => $id, 'mode' => 'rephrase', 'current_content' => 'Original', 'platform' => 'bluesky',
    ])->assertOk()->assertJsonPath('content', 'Fits.');
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request['model'])->toBe(config("ai_models.profiles.{$id}.model"));
    }
    $last = Http::recorded()->last()[0]->data();
    expect(array_key_exists('temperature', $last))->toBe($id === 'gemini');
    if ($id === 'gemini') {
        expect($last['temperature'])->toBe(0.2);
    }
})->with(['sol', 'opus', 'gemini']);

test('caption cache keeps equal text and limits separate for different selections', function () {
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, $request['model'])));
    $adapter = app(CaptionAdapter::class);
    $caption = str_repeat('long caption ', 100);
    $one = $adapter->adapt($this->workspace, $this->user, $caption, Platform::Bluesky, null, app(AiModelCatalog::class)->resolve('sol'));
    $two = $adapter->adapt($this->workspace, $this->user, $caption, Platform::Bluesky, null, app(AiModelCatalog::class)->resolve('opus'));
    $adapter->adapt($this->workspace, $this->user, $caption, Platform::Bluesky, null, app(AiModelCatalog::class)->resolve('sol'));
    expect($one)->toBe('test-sol-low')->and($two)->toBe('test-opus');
    Http::assertSentCount(2);
});

test('background fitting uses its default and safely truncates when unavailable', function () {
    $caption = str_repeat('long caption ', 100);
    config(['ai_models.default' => 'opus']);
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, 'Fits.')));
    expect(app(CaptionAdapter::class)->adapt($this->workspace, $this->user, $caption, Platform::Bluesky))->toBe('Fits.');
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'test-opus');
    config(['ai_models.profiles.opus.enabled' => false]);
    $fallback = app(CaptionAdapter::class)->adapt($this->workspace, $this->user, $caption, Platform::Bluesky);
    expect($fallback)->not->toBe('Fits.')->and(mb_strlen($fallback))->toBeLessThanOrEqual(300);
    Http::assertSentCount(1);
});

test('server policy options cannot replace sdk owned fields and caps', function () {
    config([
        'ai_models.max_tokens' => 999999, 'ai_models.timeout' => 9999, 'ai_models.fitting_timeout' => 9999,
        'ai_models.profiles.sol.options.model' => 'untrusted',
        'ai_models.profiles.sol.options.input' => 'untrusted',
        'ai_models.profiles.sol.options.max_output_tokens' => 999999,
        'ai_models.profiles.sol.options.temperature' => 0.9,
        'ai_models.profiles.sol.options.text' => ['format' => 'untrusted'],
    ]);
    $selection = app(AiModelCatalog::class)->resolve();
    expect($selection['timeout'])->toBe(50)->and($selection['fitting_timeout'])->toBe(40);
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, 'ok')));
    $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), ['mode' => 'rephrase', 'current_content' => 'Original'])->assertOk();
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'test-sol-low'
        && $request['max_output_tokens'] === 4096 && ! isset($request['temperature']) && is_array($request['input']));
});

test('safe catalog availability is independent of the unused openai key', function () {
    config(['ai_models.profiles.sol.enabled' => false]);
    $this->actingAs($this->user)->get(route('app.posts.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('aiEnabled', true)
        ->where('aiModels.default', 'sol')
        ->has('aiModels.options', 2)
        ->where('aiModels.options.0.id', 'opus')
        ->missing('aiModels.options.0.provider')
        ->missing('aiModels.options.0.model')
        ->missing('aiModels.options.0.key')
        ->missing('aiModels.options.0.options'));
});

test('provider failures are sanitized and do not try another route', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'private provider details']], 429)]);
    $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
        'model_id' => 'opus', 'mode' => 'rephrase', 'current_content' => 'Original',
    ])->assertStatus(502)->assertJsonPath('message', __('posts.composer.assistant_error'));
    Http::assertSentCount(1);
});

test('the raw image boundary is admitted using storage metadata rather than row size', function () {
    $asset = modelRoutingImage($this->workspace);
    $bytes = Storage::get($asset->path);
    Storage::put($asset->path, $bytes.str_repeat("\0", 3 * 1024 * 1024 - strlen($bytes)));
    Http::fake(fn (Request $request) => Http::response(modelRoutingReply($request, 'A description.')));
    $this->actingAs($this->user)->postJson(route('app.posts.ai.alt-text'), ['media_id' => $asset->id])->assertOk();
    Http::assertSentCount(1);
});

test('catalog metadata is not shared with guests', function () {
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->missing('aiModels')->where('aiEnabled', true));
});

test('selected policy options match the sdk driver rather than the configured provider name', function () {
    $selection = app(AiModelCatalog::class)->resolve('sol');
    $agent = (new PostContentShortener('Bluesky', 300))->withModelSelection($selection);
    expect($agent->providerOptions('openai'))->toBe($selection['options'])
        ->and($agent->providerOptions('litellm'))->toBe([])
        ->and($agent->providerOptions('openrouter'))->toBe([]);
});

test('legacy provider and credential handling remain with the native sdk', function (string $provider) {
    config([
        'ai_models.default' => null,
        'ai.default' => $provider,
        "ai.providers.{$provider}.key" => null,
        "ai.providers.{$provider}.url" => null,
        "ai.providers.{$provider}.models.text.default" => 'test-legacy-model',
        'ai.providers.bedrock.use_default_credential_provider' => true,
        'ai.providers.bedrock.access_key_id' => null,
        'ai.providers.bedrock.secret_access_key' => null,
        'ai.providers.bedrock.session_token' => null,
    ]);
    PostWritingAssistant::fake(['Legacy suggestion.']);
    $this->actingAs($this->user)->postJson(route('app.posts.ai.assist'), [
        'mode' => 'rephrase', 'current_content' => 'Original',
    ])->assertOk()->assertJsonPath('content', 'Legacy suggestion.');

    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === $provider && $prompt->model === 'test-legacy-model');
    Http::assertNothingSent();
})->with(['bedrock', 'openai']);
