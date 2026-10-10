<?php

namespace LiveQuery\Support;

use InvalidArgumentException;

final class ChannelName
{
    public const EVENT = 'livequery.invalidated';

    public static function validateCollection(string $collection): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $collection)) {
            throw new InvalidArgumentException('Collection names must start with a letter and contain only lowercase letters, numbers, or underscores (max 64).');
        }
    }

    public static function normalizeTenant(int|string|null $tenant): ?string
    {
        if ($tenant === null || $tenant === '') {
            return null;
        }

        $tenant = (string) $tenant;

        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $tenant)) {
            throw new InvalidArgumentException('Tenant keys must be 1–64 ASCII letters, numbers, underscores or hyphens.');
        }

        return $tenant;
    }

    public static function for(string $collection, int|string $tenant): string
    {
        self::validateCollection($collection);
        $key = self::normalizeTenant($tenant);

        if ($key === null) {
            throw new InvalidArgumentException('A tenant key is required.');
        }

        return "livequery.{$collection}.{$key}";
    }
}
