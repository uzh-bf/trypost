<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\GetPostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaUrl;
use App\Support\Media\RedactStorageCredentials;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);
    config(['filesystems.default' => 'azure', 'filesystems.disks.azure.driver' => 'azure-flat']);
    Storage::fake('azure');
    $this->signing = (object) ['requests' => []];
    $signing = $this->signing;
    Storage::disk('azure')->buildTemporaryUrlsUsing(function ($path, $expiry) use ($signing) {
        $signing->requests[] = [$path, $expiry];

        return 'https://example.blob.core.windows.net/media/'.rawurlencode($path).'?sp=r&sig=synthetic';
    });
    $this->media = Media::factory()->temporaryUpload($this->workspace)->create(['path' => 'medias/test.jpg', 'mime_type' => 'image/jpeg']);
    Storage::put($this->media->path, 'synthetic-image');
});

test('azure browser media uses stable authorized redirects rather than stored bearer links', function () {
    expect($this->media->url)->toBe(route('app.media.view', $this->media));
    expect($this->signing->requests)->toBeEmpty();
    $this->actingAs($this->user)->get($this->media->url)
        ->assertRedirect('https://example.blob.core.windows.net/media/medias%2Ftest.jpg?sp=r&sig=synthetic')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($this->signing->requests)->toHaveCount(1)
        ->and($this->signing->requests[0][1]->getTimestamp())->toBe(now()->addMinutes(15)->getTimestamp());
});

test('preview authorization follows membership rather than the selected workspace', function () {
    $other = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->user->update(['current_workspace_id' => $other->id]);
    $this->actingAs($this->user)->get($this->media->url)->assertRedirect();
    expect($this->signing->requests)->toHaveCount(1);
});

test('another account and guests cannot mint a media link', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id, 'account_id' => $otherUser->account_id]);
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);
    subscribeAccount($otherUser->account);
    $this->actingAs($otherUser)->get($this->media->url)->assertNotFound();
    expect($this->signing->requests)->toBeEmpty();
    auth()->logout();
    $this->get($this->media->url)->assertRedirect();
    expect($this->signing->requests)->toBeEmpty();
});

test('missing old staging objects fail before any link is signed', function () {
    Storage::delete($this->media->path);
    $this->actingAs($this->user)->get($this->media->url)->assertNotFound();
    try {
        MediaUrl::apiItems([MediaItem::fromMedia($this->media)->toArray()]);
        test()->fail('Missing media must not be signed.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);
    }
    expect($this->signing->requests)->toBeEmpty();
});

test('post and reply persistence discard signed urls while api output obtains fresh links', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $item = MediaItem::fromMedia($this->media)->toArray();
    $item['url'] = 'https://example.blob.core.windows.net/media/a?sig=synthetic';
    $post->update(['media' => [$item], 'meta' => ['thread_replies' => [['text' => 'fixture', 'media' => [$item]]]]]);
    $raw = $post->fresh();
    expect($raw->getRawOriginal('media'))->not->toContain('sig=')
        ->and($raw->getRawOriginal('meta'))->not->toContain('sig=')
        ->and($raw->media[0]['url'])->toBe(route('app.media.view', $this->media))
        ->and($this->signing->requests)->toBeEmpty();
    $output = (new PostResource($raw))->resolve();
    expect(parse_url($output['media'][0]['url'], PHP_URL_HOST))->toBe('example.blob.core.windows.net')
        ->and($this->signing->requests)->toHaveCount(2);
});

test('every preview fetch renews the access window after time passes', function () {
    $url = $this->media->url;
    $this->actingAs($this->user)->get($url)->assertRedirect();
    $firstExpiry = $this->signing->requests[0][1]->getTimestamp();
    $this->travel(20)->minutes();
    $this->get($url)->assertRedirect();
    expect($this->signing->requests[1][1]->getTimestamp())->toBeGreaterThan($firstExpiry);
});

test('sas data is removed from nested logs and exceptions', function () {
    $url = 'https://example.blob.core.windows.net/media/a?sv=2023-11-03&sig=synthetic';
    $record = RedactStorageCredentials::clean(['nested' => ['uri' => $url], 'exception' => new RuntimeException($url)]);
    expect(json_encode($record))->not->toContain('sig=', 'synthetic')
        ->and($record['nested']['uri'])->toBe('https://example.blob.core.windows.net/media/a?[redacted]');
});

test('token API responses mint file links without a browser session and enforce media workspace', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $post->update(['media' => [MediaItem::fromMedia($this->media)->toArray()]]);
    $token = passportToken($this->user, $this->workspace);
    $this->withToken($token)->getJson(route('api.posts.show', $post))
        ->assertOk()->assertJsonPath('media.0.url', 'https://example.blob.core.windows.net/media/medias%2Ftest.jpg?sp=r&sig=synthetic');
    expect($this->signing->requests)->toHaveCount(1);
    $foreign = Media::factory()->temporaryUpload(Workspace::factory()->create())->create(['path' => 'medias/foreign.jpg']);
    Storage::put($foreign->path, 'fixture');
    $post->update(['media' => [MediaItem::fromMedia($foreign)->toArray()]]);
    $this->withToken($token)->getJson(route('api.posts.show', $post))->assertNotFound();
    expect($this->signing->requests)->toHaveCount(1);
});

test('config-only recovery checks missing files in API and browser without minting links', function () {
    config(['filesystems.default' => 's3', 'filesystems.verify_media' => true]);
    Storage::fake('s3');
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $post->update(['media' => [MediaItem::fromMedia($this->media)->toArray()]]);
    $this->actingAs($this->user)->get(route('app.media.view', $this->media))->assertNotFound();
    auth()->logout();
    $this->withToken(passportToken($this->user, $this->workspace))->getJson(route('api.posts.show', $post))->assertNotFound();
    expect($this->signing->requests)->toBeEmpty();
});

test('MCP post output uses the same authorized expiring media contract', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $post->update(['media' => [MediaItem::fromMedia($this->media)->toArray()]]);
    TryPostServer::actingAs($this->user)
        ->tool(GetPostTool::class, ['post_id' => $post->id])
        ->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('media.0.url', 'https://example.blob.core.windows.net/media/medias%2Ftest.jpg?sp=r&sig=synthetic')->etc());
    expect($this->signing->requests)->toHaveCount(1);
});

test('the publishing consumer resolves media access when it executes', function () {
    $item = MediaItem::fromMedia($this->media);
    expect($this->signing->requests)->toBeEmpty();
    $this->travel(2)->days();
    expect($item->publishUrl())->toContain('sig=synthetic');
    expect($this->signing->requests)->toHaveCount(1)
        ->and($this->signing->requests[0][1]->getTimestamp())->toBe(now()->addHours(26)->getTimestamp());
});


test('webhook retries renew file links and sign the wire body while logs keep canonical references', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $post->update(['media' => [MediaItem::fromMedia($this->media)->toArray()], 'platform_avatar' => 'avatars/test.png']);
    Storage::put('avatars/test.png', 'synthetic-avatar');
    $webhook = \App\Models\Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'endpoint' => 'https://8.8.8.8/webhook']);
    $service = app(\App\Services\WebhookService::class);
    $payload = $service->postPayload($post);
    expect(json_encode($payload))->not->toContain('sig=');
    $job = new \App\Jobs\DispatchWebhook($webhook, 'post.created', $payload);
    \Illuminate\Support\Facades\Http::fake(fn () => \Illuminate\Support\Facades\Http::response('https://example.blob.core.windows.net/media/file?sig=synthetic', 200));
    $job->handle(app(\App\Services\Http\SafeHttpFetcher::class), $service);
    $this->travel(20)->minutes();
    $job->handle(app(\App\Services\Http\SafeHttpFetcher::class), $service);
    \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($service, $webhook) {
        return str_contains($request->data()['data']['media'][0]['url'], 'sig=synthetic')
            && $request->hasHeader('X-Webhook-Signature', $service->signature($request->data(), $webhook->signing_secret));
    });
    $log = \App\Models\WebhookLog::findOrFail($job->logId);
    expect(json_encode([$job->payload, $log->payload, $log->response_body]))->not->toContain('sig=')
        ->and($this->signing->requests)->toHaveCount(4)
        ->and($this->signing->requests[2][1]->getTimestamp())->toBeGreaterThan($this->signing->requests[0][1]->getTimestamp());
});
