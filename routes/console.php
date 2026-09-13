<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Everything below needs one line in the server's crontab and nothing else:
|
|   * * * * * cd /var/www/famzone && php artisan schedule:run >> /dev/null 2>&1
|
| Without it none of this runs, and the failure is silent — chains sit at
| their first contact forever and location data grows without bound. Worth
| checking with `php artisan schedule:list` after any server rebuild.
|
*/

/*
 | Advance check-in chains whose current person has run out of time.
 |
 | Every minute, because the deadline it enforces is thirty and a
 | coarser tick would round somebody's wait up to the next slot. It is
 | a range scan over an index that is almost always empty — the
 | cheapest thing in the schedule by a wide margin.
 |
 | withoutOverlapping, because a slow pass must not have a second copy
 | started on top of it.
 */
Schedule::command('check-ins:escalate')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
 | Trim location history.
 |
 | 03:20 rather than 03:00: the hour is for backups and every other
 | system on earth also picked the top of it. Twenty past is empty.
 */
Schedule::command('location:prune')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();
