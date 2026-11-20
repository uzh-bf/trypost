<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\BuildChannelInsights;
use App\Http\Requests\Api\Channel\ChannelInsightsRequest;
use App\Http\Resources\Api\ChannelInsightsResource;
use App\Http\Resources\Api\ChannelPublicationPerformanceResource;
use App\Models\SocialAccount;
use App\Support\Analytics\ExternalAnalyticsMedia;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ChannelInsightsController extends Controller
{
    public function show(ChannelInsightsRequest $request, SocialAccount $account, BuildChannelInsights $insights): ChannelInsightsResource
    {
        abort_unless($account->platform->isIncludedInAnalytics(), Response::HTTP_NOT_FOUND);

        return new ChannelInsightsResource(ExternalAnalyticsMedia::channelInsights(
            $insights->handle($account, $request->user(), $request->validated()),
            $account->workspace_id,
        ));
    }

    public function publications(ChannelInsightsRequest $request, SocialAccount $account, BuildChannelInsights $insights): AnonymousResourceCollection
    {
        abort_unless($account->platform->isIncludedInAnalytics(), Response::HTTP_NOT_FOUND);

        ['filters' => $filters, 'publications' => $publications] = $insights->publications($account, $request->user(), $request->validated());

        return ChannelPublicationPerformanceResource::collection(
            ExternalAnalyticsMedia::publications($publications, $account->workspace_id),
        )->additional(['filters' => $filters]);
    }
}
