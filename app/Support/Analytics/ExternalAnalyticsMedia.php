<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Support\Media\MediaUrl;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * API and MCP clients cannot follow the browser-authorized media routes used
 * by the Insights pages. Project only owned post media to short-lived links;
 * network thumbnails remain their provider URLs.
 */
final class ExternalAnalyticsMedia
{
    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function report(array $report, string $workspaceId): array
    {
        foreach (['reactions', 'comments'] as $ranking) {
            $rows = data_get($report, "top_posts.{$ranking}");

            if (! is_array($rows)) {
                continue;
            }

            $report['top_posts'][$ranking] = array_map(
                fn (mixed $row): mixed => is_array($row) ? self::publication($row, $workspaceId) : $row,
                $rows,
            );
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $insights
     * @return array<string, mixed>
     */
    public static function channelInsights(array $insights, string $workspaceId): array
    {
        $summary = data_get($insights, 'summary');

        if (is_array($summary)) {
            $insights['summary'] = self::report($summary, $workspaceId);
        }

        return $insights;
    }

    /**
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $publications
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function publications(LengthAwarePaginator $publications, string $workspaceId): LengthAwarePaginator
    {
        return $publications->through(
            fn (array $publication): array => self::publication($publication, $workspaceId),
        );
    }

    /**
     * @param  array<string, mixed>  $publication
     * @return array<string, mixed>
     */
    private static function publication(array $publication, string $workspaceId): array
    {
        $media = data_get($publication, 'media');

        if (! is_array($media)) {
            return $publication;
        }

        $browserUrl = data_get($media, 'url');
        $publication['media'] = MediaUrl::apiItems($media, $workspaceId);

        // An image thumbnail repeats media.url; a video or imported post keeps its network preview.
        if (is_string($browserUrl) && data_get($publication, 'thumbnail_url') === $browserUrl) {
            $publication['thumbnail_url'] = data_get($publication, 'media.url');
        }

        return $publication;
    }
}
