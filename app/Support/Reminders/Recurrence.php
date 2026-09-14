<?php

namespace App\Support\Reminders;

use App\Models\Reminder;
use Carbon\CarbonImmutable;

/**
 * A repeat rule, and the ability to say when it lands.
 *
 * Deliberately not RRULE. The full iCalendar grammar covers "the last weekday
 * before the third Tuesday" and costs a parser, a library and a class of bugs
 * nobody on this app will ever benefit from. Four modes cover every item on
 * the brief — wake up daily, gym on weekdays, rent on the 31st, a doctor's
 * appointment once — and each one is a line of arithmetic you can read.
 *
 * Everything here works in the reminder's own timezone and in wall-clock
 * terms, because that is what a person means. "Seven o'clock" survives a
 * daylight-saving change; an instant does not.
 */
final class Recurrence
{
    public const ONCE = 'once';
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';

    /** @var list<string> */
    public const MODES = [self::ONCE, self::DAILY, self::WEEKLY, self::MONTHLY];

    /** Monday through Sunday, the whole week. */
    public const MASK_EVERY_DAY = 0b1111111;

    /** Monday to Friday. */
    public const MASK_WEEKDAYS = 0b0011111;

    /** Saturday and Sunday. */
    public const MASK_WEEKEND = 0b1100000;

    /**
     * The most occurrences any single expansion will return.
     *
     * A guard rail, not a feature. Asking a daily rule for a decade is a bug
     * in the caller, and the right response is to answer with a sane prefix
     * rather than to build a hundred thousand Carbon objects and time out.
     */
    public const MAX_OCCURRENCES = 500;

    public function __construct(
        public readonly string $mode,
        public readonly int $hour,
        public readonly int $minute,
        public readonly int $weekdayMask,
        public readonly ?int $dayOfMonth,
        public readonly CarbonImmutable $startsOn,
        public readonly ?CarbonImmutable $endsOn,
        public readonly string $timezone,
    ) {
    }

    public static function fromReminder(Reminder $reminder): self
    {
        $zone = self::safeZone($reminder->timezone);

        [$hour, $minute] = self::splitTime((string) $reminder->time_of_day);

        return new self(
            mode: in_array($reminder->repeat_mode, self::MODES, true)
                ? $reminder->repeat_mode
                : self::DAILY,
            hour: $hour,
            minute: $minute,
            weekdayMask: (int) $reminder->weekday_mask,
            dayOfMonth: $reminder->day_of_month === null
                ? null
                : (int) $reminder->day_of_month,
            startsOn: CarbonImmutable::parse(
                $reminder->starts_on->toDateString(),
                $zone,
            )->startOfDay(),
            endsOn: $reminder->ends_on === null
                ? null
                : CarbonImmutable::parse(
                    $reminder->ends_on->toDateString(),
                    $zone,
                )->startOfDay(),
            timezone: $zone,
        );
    }

    /**
     * Every moment this rule lands between two instants, inclusive.
     *
     * Returned in the rule's own zone so callers can read the wall clock off
     * them directly; converting to UTC is the caller's job and only the
     * database needs it.
     *
     * @return list<CarbonImmutable>
     */
    public function between(
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $cap = self::MAX_OCCURRENCES,
    ): array {
        if ($to->lessThan($from)) {
            return [];
        }

        $from = $from->setTimezone($this->timezone);
        $to = $to->setTimezone($this->timezone);

        // Start from the later of "what was asked for" and "when this rule
        // began", so a rule that starts next month costs nothing to ask about
        // today.
        $day = $from->startOfDay();

        if ($day->lessThan($this->startsOn)) {
            $day = $this->startsOn;
        }

        $last = $to->startOfDay();

        if ($this->endsOn !== null && $this->endsOn->lessThan($last)) {
            $last = $this->endsOn;
        }

        $out = [];

        while ($day->lessThanOrEqualTo($last) && count($out) < $cap) {
            if ($this->occursOn($day)) {
                /*
                 | setTime rather than arithmetic on the start of the day.
                 |
                 | Adding seven hours to midnight is wrong on the two days a
                 | year a zone shifts — the result lands at 06:00 or 08:00.
                 | Setting the wall clock is what "seven o'clock" means, and
                 | PHP resolves the one local time that does not exist on a
                 | spring-forward morning by moving it forward, which is also
                 | what an alarm should do.
                 */
                $at = $day->setTime($this->hour, $this->minute, 0);

                if ($at->greaterThanOrEqualTo($from) && $at->lessThanOrEqualTo($to)) {
                    $out[] = $at;
                }
            }

            // A `once` rule has exactly one candidate day; walking further is
            // pure waste on a rule that will never match again.
            if ($this->mode === self::ONCE) {
                break;
            }

            $day = $day->addDay();
        }

        return $out;
    }

    /**
     * The next landing strictly after a moment, or null if there is none.
     *
     * Bounded rather than open-ended: a weekly rule needs at most a week, a
     * monthly one at most two months to be sure of finding the next hit (two,
     * not one, because a rule on the 31st can skip February entirely under
     * clamping — it lands on the 28th — and the window has to survive the
     * short month regardless).
     */
    public function nextAfter(CarbonImmutable $moment): ?CarbonImmutable
    {
        $horizon = match ($this->mode) {
            self::MONTHLY => $moment->addMonths(2),
            self::WEEKLY => $moment->addDays(8),
            self::ONCE => $this->startsOn->addDay(),
            default => $moment->addDays(2),
        };

        $found = $this->between($moment->addSecond(), $horizon, cap: 1);

        return $found[0] ?? null;
    }

    /** Whether this rule lands on a given local day. */
    public function occursOn(CarbonImmutable $day): bool
    {
        $day = $day->startOfDay();

        if ($day->lessThan($this->startsOn)) {
            return false;
        }

        if ($this->endsOn !== null && $day->greaterThan($this->endsOn)) {
            return false;
        }

        return match ($this->mode) {
            self::ONCE => $day->isSameDay($this->startsOn),
            self::DAILY => true,

            /*
             | Bit 0 is Monday, so ISO weekday 1–7 maps to bits 0–6.
             |
             | A zero mask is treated as every day rather than as no day. It
             | should never reach here — saving a weekly reminder validates
             | against it — but a rule that silently never fires is the worst
             | possible way to be wrong about an alarm.
             */
            self::WEEKLY => $this->weekdayMask === 0
                || ($this->weekdayMask & (1 << ($day->dayOfWeekIso - 1))) !== 0,

            self::MONTHLY => $day->day === $this->clampedDayFor($day),
            default => false,
        };
    }

    /**
     * The day of the month this rule lands on, within a given month.
     *
     * Clamped, so "the 31st" means the 30th in September and the 28th in an
     * ordinary February. The alternative is to skip those months, which would
     * quietly drop four rent reminders a year — and somebody who pays on the
     * 31st has to be able to say so.
     */
    public function clampedDayFor(CarbonImmutable $day): int
    {
        $wanted = $this->dayOfMonth ?? $this->startsOn->day;

        return min(max(1, $wanted), $day->daysInMonth);
    }

    /**
     * Human wording, owned by the server so the app and any dashboard read
     * the same sentence.
     */
    public function describe(): string
    {
        $clock = sprintf('%02d:%02d', $this->hour, $this->minute);

        return match ($this->mode) {
            self::ONCE => 'Once on '.$this->startsOn->format('j M').' at '.$clock,
            self::DAILY => 'Every day at '.$clock,
            self::WEEKLY => $this->weekdayWords().' at '.$clock,
            self::MONTHLY => 'Monthly on the '.self::ordinal(
                $this->dayOfMonth ?? $this->startsOn->day
            ).' at '.$clock,
            default => 'At '.$clock,
        };
    }

    private function weekdayWords(): string
    {
        if ($this->weekdayMask === 0 || $this->weekdayMask === self::MASK_EVERY_DAY) {
            return 'Every day';
        }

        if ($this->weekdayMask === self::MASK_WEEKDAYS) {
            return 'Weekdays';
        }

        if ($this->weekdayMask === self::MASK_WEEKEND) {
            return 'Weekends';
        }

        $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $picked = [];

        for ($bit = 0; $bit < 7; $bit++) {
            if (($this->weekdayMask & (1 << $bit)) !== 0) {
                $picked[] = $names[$bit];
            }
        }

        return implode(', ', $picked);
    }

    private static function ordinal(int $n): string
    {
        // 11th, 12th and 13th break the last-digit rule, which is the whole
        // reason this is not a one-liner.
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return $n.'th';
        }

        return $n.match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * @return array{int, int}
     */
    private static function splitTime(string $time): array
    {
        $parts = explode(':', $time);

        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return [
            max(0, min(23, $hour)),
            max(0, min(59, $minute)),
        ];
    }

    /**
     * A bad zone string must never throw from inside a list endpoint, so it is
     * validated here and falls back to the app default.
     */
    public static function safeZone(?string $zone): string
    {
        try {
            new \DateTimeZone((string) $zone);

            return (string) $zone;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }
}
