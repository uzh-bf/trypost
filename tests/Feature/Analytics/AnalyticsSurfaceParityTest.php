<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Analytics\GetAnalyticsPublicationTool;
use App\Mcp\Tools\Analytics\GetAnalyticsReportTool;
use App\Mcp\Tools\Analytics\GetChannelInsightsTool;
use App\Mcp\Tools\Analytics\ListChannelPublicationsTool;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

test('web API and MCP expose the same workspace analytics report and date bounds', function () {
    $access = createApiTestToken();
    $workspace = $access['workspace'];
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'date' => '2026-09-20',
        'followers_count' => 123,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'origin' => PublicationOrigin::External,
        'provider_published_at' => '2026-09-20 10:00:00',
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-20',
        'reactions_count' => 7,
        'comments_count' => 2,
    ]);
    $foreign = AnalyticsPublication::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'platform' => Platform::Instagram,
        'provider_published_at' => '2026-09-20 10:00:00',
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $foreign->id,
        'date' => '2026-09-20',
        'reactions_count' => 999,
    ]);
    Http::fake();
    $selected = ['start' => '2026-09-01', 'end' => '2026-09-30'];

    $this->actingAs($access['user'])
        ->get(route('app.insights', $selected))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/Index')
            ->where('report.range.start', '2026-09-20')
            ->where('report.range.end', '2026-09-20')
            ->where('report.summary.followers.value', 123)
            ->where('report.summary.posts.value', 1)
            ->where('report.top_posts.reactions.0.id', $publication->id)
            ->etc());

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.index', $selected))
        ->assertOk()
        ->assertJsonPath('bounds.min', '2026-09-20')
        ->assertJsonPath('range.start', '2026-09-20')
        ->assertJsonPath('range.end', '2026-09-20')
        ->assertJsonPath('summary.followers.value', 123)
        ->assertJsonPath('summary.posts.value', 1)
        ->assertJsonPath('summary.reactions.value', 7)
        ->assertJsonPath('summary.comments.value', 2)
        ->assertJsonPath('top_posts.reactions.0.id', $publication->id)
        ->assertJsonCount(1, 'performance')
        ->assertJsonStructure(['bounds', 'range', 'previous_range', 'summary', 'followers', 'posts', 'top_posts', 'performance', 'coverage']);

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsReportTool::class, $selected)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('range.start', '2026-09-20')
            ->where('summary.followers.value', 123)
            ->where('summary.posts.value', 1)
            ->where('top_posts.reactions.0.id', $publication->id)
            ->has('bounds')
            ->has('followers')
            ->has('posts')
            ->has('performance')
            ->has('coverage')
            ->etc());

    Http::assertNothingSent();
});

test('analytics API and MCP sign owned media while web and provider thumbnails keep stable URLs', function () {
    $access = createApiTestToken();
    $workspace = $access['workspace'];
    config(['filesystems.default' => 'azure', 'filesystems.disks.azure.driver' => 'azure-flat']);
    Storage::fake('azure');
    Storage::disk('azure')->buildTemporaryUrlsUsing(
        fn (string $path): string => 'https://example.blob.core.windows.net/media/'.rawurlencode($path).'?sp=r&sig=synthetic',
    );

    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $media = Media::factory()->temporaryUpload($workspace)->create([
        'path' => 'medias/analytics.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    Storage::put($media->path, 'synthetic-image');
    $post = Post::factory()->forAccount($account)->published()->create([
        'user_id' => $access['user']->id,
        'media' => [MediaItem::fromMedia($media)->toArray()],
    ]);
    $providerThumbnail = 'https://scontent.cdninstagram.com/analytics.jpg?oe=6ACC450F';
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'post_id' => $post->id,
        'origin' => PublicationOrigin::TryPost,
        'provider_published_at' => '2026-09-20 10:00:00',
        'preview_metadata' => ['thumbnail_url' => $providerThumbnail],
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-20',
        'reactions_count' => 8,
        'comments_count' => 2,
    ]);
    $externalProviderThumbnail = 'https://scontent.cdninstagram.com/external.jpg?oe=6ACC4510';
    $externalPublication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'post_id' => null,
        'origin' => PublicationOrigin::External,
        'provider_published_at' => '2026-09-19 10:00:00',
        'preview_metadata' => ['thumbnail_url' => $externalProviderThumbnail],
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $externalPublication->id,
        'date' => '2026-09-19',
        'reactions_count' => 1,
        'comments_count' => 1,
    ]);

    $range = ['start' => '2026-09-01', 'end' => '2026-09-30'];
    $webUrl = route('app.media.view', $media);
    $signedUrl = 'https://example.blob.core.windows.net/media/'.rawurlencode($media->path).'?sp=r&sig=synthetic';

    $this->actingAs($access['user'])
        ->get(route('app.insights', $range))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.top_posts.reactions.0.media.url', $webUrl)
            ->where('report.top_posts.reactions.0.preview_metadata.thumbnail_url', $providerThumbnail)
            ->where('report.top_posts.reactions.1.media', null)
            ->where('report.top_posts.reactions.1.preview_metadata.thumbnail_url', $externalProviderThumbnail)
            ->etc());
    $this->actingAs($access['user'])
        ->get(route('app.channels.insights', ['account' => $account, ...$range]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('publications.data.0.media.url', $webUrl)
            ->where('publications.data.0.thumbnail_url', $webUrl)
            ->where('publications.data.1.media', null)
            ->where('publications.data.1.thumbnail_url', $externalProviderThumbnail)
            ->etc());
    auth()->forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.index', $range))
        ->assertOk()
        ->assertJsonPath('top_posts.reactions.0.media.url', $signedUrl)
        ->assertJsonPath('top_posts.reactions.0.preview_metadata.thumbnail_url', $providerThumbnail)
        ->assertJsonPath('top_posts.reactions.1.media', null)
        ->assertJsonPath('top_posts.reactions.1.preview_metadata.thumbnail_url', $externalProviderThumbnail);
    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.channels.insights.show', [$account, ...$range]))
        ->assertOk()
        ->assertJsonPath('summary.top_posts.reactions.0.media.url', $signedUrl)
        ->assertJsonPath('summary.top_posts.reactions.1.media', null)
        ->assertJsonPath('summary.top_posts.reactions.1.preview_metadata.thumbnail_url', $externalProviderThumbnail);
    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.channels.insights.publications', [$account, ...$range]))
        ->assertOk()
        ->assertJsonPath('data.0.media.url', $signedUrl)
        ->assertJsonPath('data.0.thumbnail_url', $signedUrl)
        ->assertJsonPath('data.1.media', null)
        ->assertJsonPath('data.1.thumbnail_url', $externalProviderThumbnail);

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsReportTool::class, $range)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('top_posts.reactions.0.media.url', $signedUrl)
            ->where('top_posts.reactions.0.preview_metadata.thumbnail_url', $providerThumbnail)
            ->where('top_posts.reactions.1.media', null)
            ->where('top_posts.reactions.1.preview_metadata.thumbnail_url', $externalProviderThumbnail)
            ->etc());
    TryPostServer::actingAs($access['user'])
        ->tool(GetChannelInsightsTool::class, ['account_id' => $account->id, ...$range])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('summary.top_posts.reactions.0.media.url', $signedUrl)
            ->where('summary.top_posts.reactions.1.media', null)
            ->where('summary.top_posts.reactions.1.preview_metadata.thumbnail_url', $externalProviderThumbnail)
            ->etc());
    TryPostServer::actingAs($access['user'])
        ->tool(ListChannelPublicationsTool::class, ['account_id' => $account->id, ...$range])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('publications.0.media.url', $signedUrl)
            ->where('publications.0.thumbnail_url', $signedUrl)
            ->where('publications.1.media', null)
            ->where('publications.1.thumbnail_url', $externalProviderThumbnail)
            ->etc());
});

test('API and MCP validate analytics date selections like the web', function () {
    $access = createApiTestToken();

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.index', ['start' => 'not-a-date']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start');

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.index', ['start' => '2026-09-21', 'end' => '2026-09-20']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end');

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsReportTool::class, ['start' => 'not-a-date'])
        ->assertHasErrors();

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsReportTool::class, ['start' => '2026-09-21', 'end' => '2026-09-20'])
        ->assertHasErrors();
});

test('analytics API requires authentication and MCP denies a foreign workspace', function () {
    $access = createApiTestToken();
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $access['workspace']->id]);

    $this->getJson(route('api.analytics.index'))->assertUnauthorized();
    $this->getJson(route('api.analytics.publications.show', $publication))->assertUnauthorized();

    $outsider = User::factory()->create(['current_workspace_id' => $access['workspace']->id]);

    TryPostServer::actingAs($outsider)
        ->tool(GetAnalyticsReportTool::class, [])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => $publication->id])
        ->assertHasErrors(['This action is unauthorized.']);
});

test('API and MCP expose imported publication metrics and isolate workspaces', function () {
    $access = createApiTestToken();
    $workspace = $access['workspace'];
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
        'origin' => PublicationOrigin::External,
        'content_type' => PublicationContentType::Reel,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'saves_count' => 6,
        'watch_time_milliseconds' => 185000,
        'metrics' => [
            'saves' => ['value' => 6, 'unit' => 'count', 'availability' => 'available'],
            'watch_time_milliseconds' => ['value' => 185000, 'unit' => 'milliseconds', 'availability' => 'available'],
        ],
    ]);
    $foreign = AnalyticsPublication::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $excluded = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'network' => Platform::LinkedIn->network(),
    ]);
    Http::fake();

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.publications.show', $publication))
        ->assertOk()
        ->assertJsonPath('publication.id', $publication->id)
        ->assertJsonPath('publication.origin', 'external')
        ->assertJsonPath('snapshot.saves_count', 6)
        ->assertJsonPath('snapshot.watch_time_milliseconds', 185000)
        ->assertJsonPath('metrics.saves.value', 6)
        ->assertJsonPath('metrics.watch_time_milliseconds.value', 185000);

    foreach ([$foreign, $excluded] as $hidden) {
        $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
            ->getJson(route('api.analytics.publications.show', $hidden))
            ->assertNotFound();
    }

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => $publication->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('publication.id', $publication->id)
            ->where('publication.origin', 'external')
            ->where('snapshot.saves_count', 6)
            ->where('metrics.watch_time_milliseconds.value', 185000)
            ->etc());

    foreach ([$foreign, $excluded] as $hidden) {
        TryPostServer::actingAs($access['user'])
            ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => $hidden->id])
            ->assertHasErrors(['Publication not found.']);
    }

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => 'invalid'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

test('the analytics API resolves a range preset in the user time zone without clamping', function () {
    $this->travelTo('2026-09-30 12:00 UTC');
    $access = createApiTestToken();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $access['workspace']->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $access['workspace']->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'date' => '2026-09-28',
        'followers_count' => 50,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$access['plain_token']}"])
        ->getJson(route('api.analytics.index', ['range' => '7d', 'start' => '', 'end' => '']))
        ->assertOk()
        ->assertJsonPath('range.start', '2026-09-24')
        ->assertJsonPath('range.end', '2026-09-30')
        ->assertJsonPath('previous_range.start', '2026-09-17')
        ->assertJsonPath('bounds.max', '2026-09-28');
});

test('the analytics API reports the last 30 days like the web when no range is sent', function () {
    $this->travelTo('2026-09-30 12:00 UTC');
    $access = createApiTestToken();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $access['workspace']->id]);

    foreach (['2026-07-01', '2026-08-10'] as $date) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $access['workspace']->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => $account->platform,
            'date' => $date,
            'followers_count' => 50,
        ]);
    }

    $this->withHeaders(['Authorization' => "Bearer {$access['plain_token']}"])
        ->getJson(route('api.analytics.index'))
        ->assertOk()
        ->assertJsonPath('range.start', '2026-09-01')
        ->assertJsonPath('range.end', '2026-09-30');

    $this->withHeaders(['Authorization' => "Bearer {$access['plain_token']}"])
        ->getJson(route('api.analytics.index', ['start' => '2026-07-12', 'end' => '2026-08-10']))
        ->assertOk()
        ->assertJsonPath('range.start', '2026-07-12')
        ->assertJsonPath('range.end', '2026-08-10');

    $this->withHeaders(['Authorization' => "Bearer {$access['plain_token']}"])
        ->getJson(route('api.analytics.index', ['range' => '30d']))
        ->assertOk()
        ->assertJsonPath('range.start', '2026-09-01')
        ->assertJsonPath('range.end', '2026-09-30');
});
