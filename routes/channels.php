<?php

use App\Models\Block;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Authorisation for every websocket subscription. This file is the whole of
| the answer to "the second user must not be affected": a client can only
| receive frames on a channel it was allowed to subscribe to, and these
| callbacks are what decide that. A third party never receives the frame at
| all — there is no client-side filtering to get wrong.
|
| Registered by bootstrap/app.php at POST /api/v1/broadcasting/auth, behind
| auth:sanctum, so the mobile app authorises with the same bearer token it
| uses for everything else.
|
| Note the names carry no `private-` or `presence-` prefix: Laravel strips
| those before matching. That is also why the presence channel below is
| called `room` rather than `conversation` — two definitions with the same
| name would collide, and the return type would silently decide which one
| worked.
|
*/

/**
 * Your own mailbox.
 *
 * Subscribed from sign-in to sign-out, and the reason the unread badge is
 * correct whether or not a chat screen is open.
 */
Broadcast::channel('user.{uuid}', fn (User $user, string $uuid) => $user->uuid === $uuid);

/**
 * One conversation.
 *
 * Subscribed only while its screen is open, so a message in another thread
 * cannot reach a screen that is not showing it.
 */
Broadcast::channel('conversation.{uuid}', function (User $user, string $uuid) {
    $conversation = Conversation::with('participants')->where('uuid', $uuid)->first();

    if ($conversation === null) {
        return false;
    }

    $mine = $conversation->participants->firstWhere('user_id', $user->id);

    if ($mine === null || $mine->hasLeft()) {
        return false;
    }

    /*
     | Blocking is re-checked here, not just at send time.
     |
     | Authorisation runs once per subscribe, so this closes the window where
     | someone authorised before a block would keep receiving messages after
     | it: they cannot resubscribe, and the server tells their client to drop
     | the channel. Cheap, because it only runs on subscribe.
     */
    $others = $conversation->participants
        ->where('user_id', '!=', $user->id)
        ->pluck('user_id');

    $walled = Block::query()
        ->whereIn('blocker_id', $others)->where('blocked_id', $user->id)
        ->orWhere(fn (Builder $q) => $q
            ->where('blocker_id', $user->id)->whereIn('blocked_id', $others))
        ->exists();

    return ! $walled;
});

/**
 * Who is looking at a thread right now, and where typing is whispered.
 *
 * Not used until the typing-and-presence phase. Defined here with the others
 * because channel names are a contract, and settling all three at once means
 * the client never has to be taught a new one mid-flight.
 *
 * Returning an array joins the presence set; null or false denies. Whatever
 * is returned is visible to every other member, so it carries only what a
 * chat header needs.
 */
Broadcast::channel('room.{uuid}', function (User $user, string $uuid) {
    $conversation = Conversation::with('participants')->where('uuid', $uuid)->first();

    $mine = $conversation?->participants->firstWhere('user_id', $user->id);

    if ($mine === null || $mine->hasLeft()) {
        return null;
    }

    return [
        'uuid' => $user->uuid,
        'name' => $user->name,
    ];
});
