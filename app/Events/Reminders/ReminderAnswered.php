<?php

namespace App\Events\Reminders;

use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * They said yes, or they said no.
 *
 * Goes back to whoever set the reminder, so the "assigned by me" list settles
 * while they are looking at it. One event for both answers, with a boolean —
 * the client's job either way is to replace the reminder it is holding, and
 * two handlers would be two chances to forget one.
 */
class ReminderAnswered implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Reminder $reminder,
        public readonly User $owner,
        public readonly User $assignee,
        public readonly bool $accepted,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->owner->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'reminder.answered';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $reminder = $this->reminder;
        $reminder->setRelation('owner', $this->owner);
        $reminder->setRelation('assignee', $this->assignee);

        return [
            'reminder' => app(ReminderService::class)->present($reminder, $this->owner),
            'accepted' => $this->accepted,
            'by' => [
                'id' => $this->assignee->uuid,
                'name' => $this->assignee->name,
                'avatar_url' => $this->assignee->avatar_url,
            ],
        ];
    }
}
