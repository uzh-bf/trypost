<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

final class RedactStorageCredentials
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record) => $record->with(
            message: self::clean($record->message),
            context: self::clean($record->context),
            extra: self::clean($record->extra),
        ));
    }

    public static function clean(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return [
                'type' => $value::class,
                'message' => self::clean($value->getMessage()),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
                'trace' => array_map(fn (array $frame): array => self::clean(array_intersect_key($frame, array_flip(['file', 'line', 'class', 'type', 'function']))), $value->getTrace()),
                'previous' => self::clean($value->getPrevious()),
            ];
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = preg_match('/^(authorization|access_token|client_assertion|sig|delegation_key)$/i', (string) $key)
                    ? '[redacted]'
                    : self::clean($item);
            }

            return $value;
        }
        if (is_string($value)) {
            return preg_replace_callback('~https?://[^\s<>"\x27]+~i', function (array $match): string {
                $url = $match[0];

                return preg_match('/[?&](sig|skoid|X-Amz-Signature|sv)=/i', $url)
                    ? explode('?', $url, 2)[0].'?[redacted]'
                    : $url;
            }, $value);
        }

        return $value;
    }
}
