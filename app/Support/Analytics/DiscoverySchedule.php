<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Illuminate\Support\Str;

class DiscoverySchedule
{
    /** When global discovery is off, a deployment may opt in one channel. */
    public static function accountId(): ?string
    {
        $id = config('trypost.analytics.publication_history_account_id');

        return is_string($id) && Str::isUuid($id) ? strtolower($id) : null;
    }

    public static function enabledFor(string $accountId): bool
    {
        return config('trypost.analytics.publication_history_enabled') || self::accountId() === $accountId;
    }

    public static function cron(int $hours): string
    {
        $hours = max(1, min(24, $hours));

        return "0 */{$hours} * * *";
    }
}
