<?php

namespace USIPS\NCMEC\Util;

class TimeLimit
{
    /**
     * Returns the configured default timespan in hours.
     */
    public static function getDefaultHours(): int
    {
        $option = \XF::options()->usipsNcmecDefaultTimespan ?? 0;

        $value = (int) $option;
        if ($value < 0)
        {
            $value = 0;
        }

        return $value;
    }

    /**
     * Returns the configured default timespan in seconds.
     */
    public static function getDefaultSeconds(): int
    {
        return self::hoursToSeconds(self::getDefaultHours());
    }

    /**
     * Resolves a requested timespan expressed in seconds, honoring special values:
     *   -1 => use the configured default
     *   0 => unlimited / all time
     */
    public static function resolve(?int $value): int
    {
        if ($value === null || $value === -1)
        {
            return self::getDefaultSeconds();
        }

        if ($value <= 0)
        {
            return 0;
        }

        return (int) $value;
    }

    /**
     * Normalizes a submitted selection so we only persist allowed sentinel values.
     */
    public static function normalizeSelection(?int $value): int
    {
        if ($value === null)
        {
            return -1;
        }

        if ($value < -1)
        {
            return -1;
        }

        return (int) $value;
    }

    /**
     * Formats the default timespan into a human-readable string for UI usage.
     */
    public static function describeDefault(): string
    {
        $hours = self::getDefaultHours();
        if ($hours === 0)
        {
            return (string) \XF::phrase('all_time');
        }

        if ($hours % 24 === 0)
        {
            $days = (int) ($hours / 24);
            return (string) \XF::phrase('x_days', ['days' => $days]);
        }

        return (string) \XF::phrase('x_hours', ['count' => $hours]);
    }

    protected static function hoursToSeconds(int $hours): int
    {
        if ($hours <= 0)
        {
            return 0;
        }

        return $hours * 3600;
    }
}
