<?php

namespace App\Domain\Werk;

use Illuminate\Support\Carbon;

/**
 * The days Werk asks for, in the site's time zone (Werk counts in Europe/Vienna, as werk-connect
 * does), both ends included. `since` is the first day the lists of the overview cover.
 */
final class Range
{
    public const TIMEZONE = 'Europe/Vienna';

    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $since,
    ) {}

    /**
     * Null when a date is not a real Y-m-d, the order is wrong, or the range is longer than allowed.
     * Without from and to the range is the last `$defaultDays` days up to today.
     */
    public static function parse(?string $from, ?string $to, ?string $since, int $maxDays, ?int $defaultDays = null): ?self
    {
        if ($defaultDays !== null && ($from === null || $from === '') && ($to === null || $to === '')) {
            $today = Carbon::now(self::TIMEZONE);
            $to = $today->toDateString();
            $from = $today->copy()->subDays($defaultDays - 1)->toDateString();
        }
        $since = $since === null || $since === '' ? $from : $since;
        foreach ([$from, $to, $since] as $day) {
            if (! self::validDay($day)) {
                return null;
            }
        }
        if ($from > $to || $since < $from || $since > $to) {
            return null;
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > $maxDays) {
            return null;
        }

        return new self($from, $to, $since);
    }

    private static function validDay(?string $day): bool
    {
        if (! is_string($day) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** The first moment of a day in the site's zone, in UTC as the database keeps it. */
    public static function start(string $day): Carbon
    {
        return Carbon::parse($day, self::TIMEZONE)->startOfDay()->utc();
    }

    public static function end(string $day): Carbon
    {
        return Carbon::parse($day, self::TIMEZONE)->endOfDay()->utc();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function bounds(): array
    {
        return [self::start($this->from), self::end($this->to)];
    }

    /** @return list<string> every day from..to */
    public function days(): array
    {
        $out = [];
        for ($d = Carbon::parse($this->from); $d->toDateString() <= $this->to; $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** The day of a stored timestamp in the site's zone. */
    public static function dayOf(\DateTimeInterface|string $at): string
    {
        return Carbon::parse($at, 'UTC')->setTimezone(self::TIMEZONE)->toDateString();
    }
}
