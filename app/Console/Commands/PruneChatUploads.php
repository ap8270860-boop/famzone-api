<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use App\Services\Chat\AttachmentService;
use Illuminate\Console\Command;

/**
 * Delete uploads that were never sent.
 *
 * Uploading and sending are two steps, so every abandoned compose leaves a
 * file on disk with no message pointing at it: the app was closed, the send
 * failed, somebody changed their mind. Without a sweep these accumulate
 * silently and the first anyone hears about it is a full disk.
 *
 * Safe by construction — it only ever touches rows with no message_id, and a
 * row gains one the instant it is sent.
 *
 * Schedule it daily in routes/console.php:
 *
 *     Schedule::command('chat:prune-uploads')->dailyAt('03:30');
 */
class PruneChatUploads extends Command
{
    protected $signature = 'chat:prune-uploads
                            {--hours= : How old an orphan must be}
                            {--dry-run : List what would go, delete nothing}';

    protected $description = 'Delete chat uploads that were never attached to a message';

    public function handle(AttachmentService $attachments): int
    {
        $hours = (int) ($this->option('hours') ?: AttachmentService::ORPHAN_HOURS);
        $dry = (bool) $this->option('dry-run');

        $cutoff = now()->subHours($hours);

        $orphans = MessageAttachment::query()
            ->whereNull('message_id')
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $bytes = $orphans->sum('size_bytes');

        foreach ($orphans as $orphan) {
            $this->line(($dry ? '[dry] ' : '').$orphan->path);

            if (! $dry) {
                $attachments->discard($orphan);
            }
        }

        $this->info(sprintf(
            '%s %d upload%s (%s MB) older than %dh.',
            $dry ? 'Would prune' : 'Pruned',
            $orphans->count(),
            $orphans->count() === 1 ? '' : 's',
            number_format($bytes / 1048576, 1),
            $hours,
        ));

        return self::SUCCESS;
    }
}
