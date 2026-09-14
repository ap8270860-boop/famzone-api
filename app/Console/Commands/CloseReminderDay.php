<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Write off yesterday's unanswered reminders as missed.
 *
 * The job that makes the score cheap and history immutable. Until an
 * occurrence has a row it is only a calculation, and a calculation changes
 * when the rule underneath it changes — move your 8am dose to 9am and every
 * past day would quietly claim it was always 9am. Closing the day freezes it.
 *
 * Runs hourly rather than once at midnight, and the reason is timezones. There
 * is no single midnight: a user in Auckland and one in Los Angeles are twenty-
 * one hours apart, so a nightly job on server time closes one of them a day
 * early and the other most of a day late. Hourly with a six-hour grace period
 * lets every zone's day end on its own schedule, and the unique index makes
 * the repeated passes free.
 */
class CloseReminderDay extends Command
{
    protected $signature = 'reminders:close-day';

    protected $description = 'Record missed reminders once their occurrence has passed';

    public function handle(ReminderService $reminders): int
    {
        $result = $reminders->closeOut();

        if ($result['closed'] === 0) {
            // The common case. Silent, because a log line every hour saying
            // nothing happened is how a log becomes something nobody reads.
            return self::SUCCESS;
        }

        $this->info("Closed {$result['closed']} missed reminders "
            ."across {$result['scanned']} rules.");

        Log::info('reminder close-out', $result);

        return self::SUCCESS;
    }
}
