<?php

namespace App\Console\Commands;

use App\Services\Safety\CheckInEscalationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Move every overdue check-in chain along one place.
 *
 * Runs once a minute. That is the entire timer: there is no delayed job, no
 * in-memory schedule and nothing to lose when a worker restarts. The deadline
 * lives in a column, and this reads it.
 *
 * The resolution is therefore a minute, on a wait of thirty. Nobody will ever
 * notice — and the trade is worth naming, because the alternative buys seconds
 * of precision at the cost of a timer that silently evaporates if the queue is
 * drained, the worker is redeployed, or the process is killed while holding
 * it. A person waiting to hear that their mother is safe is better served by a
 * reminder that is a minute late than by one that never comes.
 *
 * withoutOverlapping is what makes running it every minute safe. A sweep that
 * takes longer than sixty seconds — a hundred chains after an outage, each
 * broadcasting — does not get a second copy of itself started on top of it.
 * The service locks each row as well, so an overlap would be survivable
 * anyway; this just stops the pile-up before it starts.
 */
class EscalateCheckIns extends Command
{
    protected $signature = 'check-ins:escalate {--limit=200 : Most chains to advance in one pass}';

    protected $description = 'Advance safety check-in chains whose current step has timed out';

    public function handle(CheckInEscalationService $escalations): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $result = $escalations->sweep($limit);

        if ($result['swept'] === 0 && $result['failed'] === 0) {
            // The normal case, every minute of every day. Silent on purpose:
            // a log line per minute saying nothing happened is how a log
            // becomes something nobody reads.
            return self::SUCCESS;
        }

        $this->info("Advanced {$result['swept']} check-in chains.");

        if ($result['failed'] > 0) {
            $this->warn("{$result['failed']} failed — see the log.");

            Log::warning('check-in escalation sweep had failures', $result);
        }

        return self::SUCCESS;
    }
}
