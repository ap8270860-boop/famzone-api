<?php

namespace App\Console\Commands;

use App\Models\LocationPing;
use App\Models\PlaceVisit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete location data nobody is allowed to look at any more.
 *
 * `location_pings` is the highest-write table in the system by an order of
 * magnitude — a phone in a car writes one row every five seconds — and until
 * now nothing has ever deleted from it. A family of five, tracked through a
 * working week, is on the order of a hundred thousand rows. That is fine for
 * a month and it is not fine for a year, and the failure mode is not a
 * dramatic one: the table simply gets slower until the ping endpoint starts
 * timing out, which is the one endpoint that must never fail.
 *
 * ## Deleted in batches
 *
 * A single `DELETE WHERE recorded_at < ...` on a table this size locks it for
 * as long as the delete takes, and the thing being locked is the write path
 * of the live map. Batches of a few thousand, with a breath between them,
 * take longer in total and are invisible to everybody using the app — which
 * is the trade a maintenance job should always make.
 *
 * ## Retention
 *
 * Ninety days for pings. Longer than the thirty a person can browse, because
 * "where was my daughter three weeks ago" is a question a family may need
 * answered by a human being at some point, and a trail that has already been
 * shredded cannot answer it.
 *
 * Closed place visits are kept a year: they are a few rows a day rather than
 * thousands, and they are what a long-range "how often is he at the gym"
 * would be built from.
 *
 * Register in routes/console.php:
 *
 *     Schedule::command('location:prune')->dailyAt('03:20');
 */
class PruneLocationData extends Command
{
    protected $signature = 'location:prune
        {--days=90 : Delete pings older than this}
        {--visit-days=365 : Delete closed place visits older than this}
        {--batch=2000 : Rows per statement}
        {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete location pings and place visits past their retention window';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $visitDays = max(30, (int) $this->option('visit-days'));
        $batch = max(100, min(10000, (int) $this->option('batch')));
        $dry = (bool) $this->option('dry-run');

        $pingFloor = now()->subDays($days);
        $visitFloor = now()->subDays($visitDays);

        if ($dry) {
            $this->line(sprintf(
                'pings older than %s: %s',
                $pingFloor->toDateString(),
                number_format(
                    LocationPing::where('recorded_at', '<', $pingFloor)->count(),
                ),
            ));

            $this->line(sprintf(
                'closed visits older than %s: %s',
                $visitFloor->toDateString(),
                number_format(
                    PlaceVisit::whereNotNull('left_at')
                        ->where('left_at', '<', $visitFloor)
                        ->count(),
                ),
            ));

            $this->comment('dry run — nothing deleted');

            return self::SUCCESS;
        }

        $pings = $this->sweep(
            'location_pings',
            fn () => LocationPing::where('recorded_at', '<', $pingFloor)
                ->limit($batch)
                ->delete(),
        );

        $visits = $this->sweep(
            'place_visits',
            fn () => PlaceVisit::whereNotNull('left_at')
                ->where('left_at', '<', $visitFloor)
                ->limit($batch)
                ->delete(),
        );

        $this->info(sprintf(
            'Pruned %s pings and %s visits.',
            number_format($pings),
            number_format($visits),
        ));

        return self::SUCCESS;
    }

    /**
     * Delete in batches until a pass removes nothing.
     *
     * The sleep is doing real work: it gives the ping endpoint's own writes a
     * window between statements, so a prune running at three in the morning
     * cannot queue behind a family driving home from a wedding.
     *
     * The iteration ceiling is a guard against a clock going backwards or a
     * retention window being set to something absurd — it caps one run's
     * damage rather than trusting the loop condition alone.
     *
     * @param  callable(): int  $delete
     */
    private function sweep(string $table, callable $delete): int
    {
        $total = 0;

        for ($pass = 0; $pass < 500; $pass++) {
            $deleted = $delete();

            $total += $deleted;

            if ($deleted === 0) {
                break;
            }

            $this->output->write('.');

            // A tenth of a second between batches. Long enough to matter to
            // the database, short enough that a million rows still finishes
            // inside a maintenance window.
            usleep(100000);
        }

        if ($total > 0) {
            $this->newLine();
        }

        // Reclaim the space rather than leaving holes. InnoDB will not return
        // it to the filesystem, but it will reuse it — and without this the
        // table's own statistics stay wrong enough to spoil query plans on
        // the index the ping path depends on.
        if ($total > 0) {
            try {
                DB::statement("ANALYZE TABLE {$table}");
            } catch (\Throwable $e) {
                // Not fatal, and not worth failing a prune over. Some managed
                // MySQL hosts refuse it outright.
                $this->warn("ANALYZE TABLE {$table} skipped: ".$e->getMessage());
            }
        }

        return $total;
    }
}
