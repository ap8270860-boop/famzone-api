<?php

namespace App\Services\Location;

use App\Events\Location\LocationShareEnded;
use App\Events\Location\LocationShareStarted;
use App\Events\Location\LocationUpdated;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\FamilyMember;
use App\Models\FamilyPlace;
use App\Models\LocationPing;
use App\Models\LocationShare;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Where everybody is, and who is allowed to know.
 *
 * Three separable things live here and it is worth naming them, because
 * conflating them is how location features go wrong:
 *
 *  1. Permission — a live row in location_shares. Asked on every read and on
 *     every websocket subscribe. Nothing else in the codebase decides this.
 *  2. Truth — the last accepted fix, on the user row; the trail, in
 *     location_pings. A fix is only "accepted" if it survives the filters in
 *     ping(), which is what stops a bad cell-tower reading from teleporting
 *     somebody across the city and back.
 *  3. Cadence — how often the phone should look. Decided here and handed
 *     back on every ping, so the server can quiet a client down without
 *     shipping a new build. This is most of the battery story.
 *
 * The client is not trusted with (1) or (3), and is trusted with (2) only
 * after the filters have had their say.
 */
class LocationService
{
    /*
    |--------------------------------------------------------------------------
    | Filtering
    |--------------------------------------------------------------------------
    */

    /**
     * Metres of reported accuracy past which a fix is not worth drawing.
     *
     * A phone with no satellite lock will happily report a position derived
     * from the cell tower it is attached to, with an accuracy of two or three
     * kilometres. Plotted on a map that is not a rough position — it is a
     * confident lie in the wrong neighbourhood, and it is the single most
     * common source of "the app said I was somewhere I wasn't".
     */
    public const MAX_ACCURACY_M = 150;

    /**
     * Metres per second past which a jump is treated as a glitch, not travel.
     *
     * 90 m/s is 324 km/h — faster than any car and most trains, slower than a
     * plane. Somebody actually flying loses their fix at the gate and picks a
     * new one up on landing with a long enough gap that this test passes, so
     * the ceiling costs nothing real and catches the classic tower-fix
     * bounce.
     */
    public const MAX_SPEED_MS = 90.0;

    /** A ping request carries a buffer, not a single point. */
    public const MAX_FIXES_PER_PING = 60;

    /*
    |--------------------------------------------------------------------------
    | Cadence
    |--------------------------------------------------------------------------
    */

    /** Sampling while the phone is moving. */
    public const MOVING_INTERVAL_S = 5;
    public const MOVING_DISTANCE_M = 10;

    /*
    |--------------------------------------------------------------------------
    | Why the still figures are no longer 45 s / 60 m
    |--------------------------------------------------------------------------
    |
    | They were, and it produced a trap that made walking family members look
    | stationary on the map — reported as "they were walking but the screen
    | said not moving".
    |
    | The distance filter is not a hint. On Android it *suppresses* updates
    | until the phone has moved that far, so at 60 m a person walking at 1.4
    | m/s produced one fix every forty-three seconds. The marker then jumped
    | sixty metres, glided for the eight seconds the interpolator allows, and
    | sat perfectly still for the remaining thirty-five. Four times out of
    | five, anybody glancing at the map saw a stationary pin.
    |
    | Worse, it was self-reinforcing. Getting *out* of the still plan needs a
    | fix that looks like movement, and the still plan is what was starving
    | the stream of fixes.
    |
    | 25 m is above ordinary GPS wander, so a phone on a table still emits
    | nothing, and a walker crosses it in under twenty seconds — which flips
    | them to the moving plan almost immediately.
    */
    public const STILL_INTERVAL_S = 30;
    public const STILL_DISTANCE_M = 25;

    /**
     * Implied speed, in m/s, past which somebody counts as moving.
     *
     * 0.7 m/s is 2.5 km/h — slower than any real walking pace, faster than
     * anything GPS noise produces over the distance floor below.
     *
     * Deliberately *not* read from the phone's reported speed. That field is
     * the single least reliable thing in a fix: Android's fused provider
     * frequently reports 0.0 for pedestrians because it derives speed from
     * Doppler shift, which needs a satellite lock better than a person
     * walking between buildings usually has. iOS reports -1 when it does not
     * know. Either way the client sees "not moving" and the whole cadence
     * collapses — which is exactly the bug this replaced.
     *
     * Displacement between two accepted fixes needs no such cooperation.
     */
    public const MOVING_SPEED_MS = 0.7;

    /**
     * And a floor under the distance, so noise cannot imply movement.
     *
     * Two fixes ten metres apart five seconds later is 2 m/s, which would read
     * as a brisk walk — and a stationary phone with a mediocre lock produces
     * exactly that pair all day. Both tests have to pass.
     */
    public const MOVING_MIN_METRES = 10.0;

    /**
     * Displacement must also beat twice the fix's own stated error.
     *
     * A movement you cannot distinguish from the error bars is not evidence of
     * movement. Twice the 68% radius is roughly a 95% confidence that
     * something actually happened.
     */
    public const MOVING_ACCURACY_FACTOR = 2.0;

    /**
     * A fix worse than this votes on nothing.
     *
     * Simulated across accuracies, the verdict is near-perfect at 8 m (97% of
     * walks caught, no false positives) and genuinely ambiguous past 20 m —
     * about half of walks caught, one window in ten wrong while standing
     * still. That is not a tuning failure, it is what the data supports: at
     * forty metres of error you cannot separate a walk from noise in twenty
     * seconds, and pretending otherwise only trades missed walks for a
     * battery drained by phantom ones.
     *
     * Which is why the feature does not rest on this verdict. The still plan
     * is responsive enough (30 s / 25 m) that a walker is drawn moving whether
     * or not they are classified as moving; the verdict only decides how hard
     * the phone works.
     */
    public const MOVING_MAX_ACCURACY_M = 40.0;

    /**
     * The shortest baseline a verdict may be taken over.
     *
     * GPS error is mean-reverting rather than diffusive: each fix is an
     * independent draw around the true position, so the *displacement* from
     * an older fix does not grow with time but the implied *speed* falls as
     * 1/t. Twenty seconds is therefore where a real walk (constant speed)
     * separates cleanly from noise (speed decaying towards zero).
     *
     * A first version held the comparison open until it was convinced.
     * That inverted the logic — every extra fix was another independent
     * chance for noise to cross the line, and given enough chances it always
     * does. Stationary false positives went up, not down.
     */
    public const MOVING_BASELINE_S = 20;

    /** How long a fix stays "live" on a map before it is drawn as stale. */
    public const STALE_AFTER_S = 180;

    /** Trail window ceiling, in hours. */
    public const MAX_TRAIL_HOURS = 24;

    /**
     * How recently somebody must have been seen to count as online.
     *
     * Deliberately longer than STALE_AFTER_S. They are different questions:
     * staleness asks "is this position still worth believing", presence asks
     * "is this person reachable". A phone in a pocket with the screen off
     * stops producing fixes long before the person stops being contactable,
     * and marking them offline after three minutes would make the roster
     * look like a family that had all gone missing at once.
     */
    public const ONLINE_WITHIN_S = 300;

    public function __construct(
        private readonly ChatService $chat,
        private readonly FamilyPlaceService $places,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Starting and stopping
    |--------------------------------------------------------------------------
    */

    /**
     * Begin sharing.
     *
     * Starting a share that is already running replaces it rather than
     * stacking a second one — tapping "share for 1 hour" twice means one
     * share for an hour, not two overlapping shares whose expiry nobody can
     * reason about. The replacement is silent for the same audience because
     * the visible artefact (the chat bubble) is still on screen and still
     * correct.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function share(User $me, array $input): array
    {
        $audience = $input['audience'];

        $conversation = null;

        if ($audience === LocationShare::AUDIENCE_CONVERSATION) {
            $conversation = Conversation::with('participants.user')
                ->where('uuid', $input['conversation_id'])
                ->first();

            abort_if($conversation === null, 404, 'That conversation no longer exists.');

            $mine = $conversation->participants->firstWhere('user_id', $me->id);

            abort_if($mine === null || $mine->hasLeft(), 403, 'You are not in that conversation.');
        }

        /*
         | The opening fix, if the phone had one ready.
         |
         | Optional on purpose: on a cold start the first satellite lock can
         | take fifteen seconds, and making the user stare at a spinner
         | before the share even begins is a worse experience than a pin that
         | fills itself in a moment later.
         */
        if (isset($input['latitude'], $input['longitude'])) {
            $this->ping($me, [[
                'latitude' => $input['latitude'],
                'longitude' => $input['longitude'],
                'accuracy' => $input['accuracy'] ?? null,
                'speed' => $input['speed'] ?? null,
                'heading' => $input['heading'] ?? null,
                'battery_level' => $input['battery_level'] ?? null,
                'moving' => $input['moving'] ?? false,
                'recorded_at' => $input['recorded_at'] ?? now()->toIso8601String(),
            ]], broadcast: false);

            $me->refresh();
        }

        $share = DB::transaction(function () use ($me, $audience, $conversation, $input) {
            // Supersede rather than stack. See the note above.
            $this->liveSharesOf($me)
                ->where('audience', $audience)
                ->when(
                    $conversation !== null,
                    fn ($q) => $q->where('conversation_id', $conversation->id),
                )
                ->update(['ended_at' => now()]);

            $share = new LocationShare([
                'audience' => $audience,
                'started_at' => now(),
                'expires_at' => $audience === LocationShare::AUDIENCE_FAMILY
                    ? null
                    : now()->addMinutes((int) $input['minutes']),
            ]);

            // Not Fillable, and mass assignment on this codebase fails
            // silently rather than loudly. Assigned, not passed.
            $share->user_id = $me->id;
            $share->conversation_id = $conversation?->id;
            $share->save();

            $me->forceFill(['is_sharing_location' => true])->save();

            return $share;
        });

        /*
         | The announcement, after the commit.
         |
         | A share you cannot see is surveillance. Every conversation-scoped
         | share puts a message in the thread, visible to everybody in it and
         | impossible to send silently — the row and the bubble are created
         | in the same call and there is no path that makes one without the
         | other.
         */
        if ($conversation !== null) {
            $message = $this->pinMessage($me, $conversation, $share);

            $share->message_id = $message->id;
            $share->save();
        }

        $viewers = $this->viewersOf($me, $share);

        LocationShareStarted::dispatch($share->fresh(['user', 'conversation', 'message']), $viewers);

        return [
            'share' => $this->presentShare($share->fresh()),
            'position' => $this->presentPosition($me->fresh()),
            'tracking' => $this->tracking($me),
        ];
    }

    /**
     * Stop one share, or all of them.
     *
     * Passing no uuid stops everything, which is what the "stop sharing"
     * button in the status bar does. That button is deliberately blunt: the
     * one action a person is most likely to take in a hurry should not
     * require them to first work out which of three shares they meant.
     *
     * @return array<string, mixed>
     */
    public function stop(User $me, ?string $shareUuid = null): array
    {
        $shares = $this->liveSharesOf($me)
            ->when($shareUuid !== null, fn ($q) => $q->where('uuid', $shareUuid))
            ->with(['conversation.participants.user', 'user'])
            ->get();

        foreach ($shares as $share) {
            // Resolved before the share dies. Afterwards it resolves to
            // nobody, and the people watching would never be told to stop.
            $viewers = $this->viewersOf($me, $share);

            $share->forceFill(['ended_at' => now()])->save();

            if ($share->conversation !== null && $share->message_id !== null) {
                /*
                 | The bubble stays, and stops claiming to be live.
                 |
                 | Not deleted: "she shared her location at 4:10 and stopped
                 | at 4:35" is the history, and a safety app that erases its
                 | own record of what happened is not much of one.
                 */
                $this->chat->announce(
                    $share->conversation,
                    Message::with(['sender', 'attachment'])->find($share->message_id),
                );
            }

            LocationShareEnded::dispatch($share, $viewers);
        }

        $stillSharing = $this->liveSharesOf($me)->exists();

        if (! $stillSharing && $me->is_sharing_location) {
            $me->forceFill(['is_sharing_location' => false])->save();
        }

        return [
            'stopped' => $shares->count(),
            'tracking' => $this->tracking($me->fresh()),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Receiving fixes
    |--------------------------------------------------------------------------
    */

    /**
     * Take a buffer of fixes from one phone.
     *
     * Buffered rather than one-at-a-time because a phone in a tunnel, a lift
     * or a dead spot keeps recording and flushes when it surfaces. Sending
     * each of those as its own request would mean a burst of forty POSTs and
     * a trail drawn in whatever order they happened to arrive.
     *
     * Returns what the client should do next, always — including when every
     * fix was rejected, and including when the answer is "stop". A client
     * that never hears "stop" from the server is a client that tracks
     * forever, and that is how a safety app earns a one-star review about
     * battery life.
     *
     * @param  array<int, array<string, mixed>>  $fixes
     * @return array<string, mixed>
     */
    public function ping(User $me, array $fixes, bool $broadcast = true): array
    {
        $sharing = $this->liveSharesOf($me)->exists();

        /*
         | Nobody is watching, so nothing is recorded.
         |
         | Not an error: a client whose share expired thirty seconds ago is
         | behaving correctly by sending one more buffer. It is told to stop
         | and it stops. Recording anyway "just in case" would mean the
         | database holds positions nobody ever consented to being stored.
         */
        if (! $sharing) {
            return [
                'accepted' => 0,
                'rejected' => count($fixes),
                'tracking' => $this->tracking($me),
            ];
        }

        $fixes = array_slice($fixes, 0, self::MAX_FIXES_PER_PING);

        // Oldest first, so each is filtered against the one before it rather
        // than against whatever order the client happened to serialise.
        usort($fixes, fn (array $a, array $b) => strcmp(
            (string) ($a['recorded_at'] ?? ''),
            (string) ($b['recorded_at'] ?? ''),
        ));

        $lastLat = $me->last_latitude !== null ? (float) $me->last_latitude : null;
        $lastLng = $me->last_longitude !== null ? (float) $me->last_longitude : null;
        $lastAt = $me->last_location_at ? CarbonImmutable::parse($me->last_location_at) : null;

        $rows = [];
        $newest = null;
        $rejected = 0;
        $now = now();

        foreach ($fixes as $fix) {
            $lat = (float) $fix['latitude'];
            $lng = (float) $fix['longitude'];
            $accuracy = isset($fix['accuracy']) ? (int) round((float) $fix['accuracy']) : null;

            $at = isset($fix['recorded_at'])
                ? CarbonImmutable::parse($fix['recorded_at'])
                : CarbonImmutable::instance($now);

            /*
             | A clock ahead of the server's is a real thing on real phones.
             | Clamped rather than rejected: the position is still true, only
             | its timestamp is wrong, and throwing away good coordinates
             | because a phone thinks it is Tuesday helps nobody.
             */
            if ($at->isAfter($now)) {
                $at = CarbonImmutable::instance($now);
            }

            if ($accuracy !== null && $accuracy > self::MAX_ACCURACY_M) {
                $rejected++;

                continue;
            }

            // Already have this one, or something newer. Replays are common
            // whenever a flush is retried after a timeout.
            if ($lastAt !== null && $at->lessThanOrEqualTo($lastAt)) {
                $rejected++;

                continue;
            }

            if ($lastLat !== null && $lastLng !== null && $lastAt !== null) {
                $seconds = max(1, $at->getTimestamp() - $lastAt->getTimestamp());
                $metres = $this->metresBetween($lastLat, $lastLng, $lat, $lng);

                if ($metres / $seconds > self::MAX_SPEED_MS) {
                    $rejected++;

                    continue;
                }
            }

            $rows[] = [
                'user_id' => $me->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy' => $accuracy,
                'speed' => isset($fix['speed']) ? (float) $fix['speed'] : null,
                'heading' => isset($fix['heading']) ? (float) $fix['heading'] : null,
                'battery_level' => isset($fix['battery_level'])
                    ? (int) $fix['battery_level']
                    : null,
                // Trail metadata only. The flag that actually decides the
                // cadence is computed once per batch below, over a long
                // enough baseline to mean something.
                'moving' => (bool) ($fix['moving'] ?? false),
                'recorded_at' => $at->toDateTimeString(),
                'created_at' => $now->toDateTimeString(),
            ];

            $lastLat = $lat;
            $lastLng = $lng;
            $lastAt = $at;
            $newest = end($rows);
        }

        /*
         | The movement verdict, taken once for the whole batch.
         |
         | Against the position the user row held *before* this ping, which is
         | where the baseline comes from: a buffer covers roughly twelve
         | seconds and the previous batch ended some seconds before that, so
         | the comparison naturally spans twenty seconds or more. Comparing
         | consecutive fixes instead — five seconds apart — is a baseline over
         | which nothing can be distinguished from noise.
         |
         | Falls back to whatever the phone last believed when there is no
         | usable baseline, which is only ever the opening fix of a session.
         */
        $moving = (bool) $me->last_location_moving;

        if ($newest !== null) {
            $anchorLat = $me->last_latitude !== null ? (float) $me->last_latitude : null;
            $anchorLng = $me->last_longitude !== null ? (float) $me->last_longitude : null;
            $anchorAt = $me->last_location_at;

            $accuracy = $newest['accuracy'] === null
                ? null
                : (float) $newest['accuracy'];

            $usable = $anchorLat !== null
                && $anchorLng !== null
                && $anchorAt !== null
                && ($accuracy === null || $accuracy <= self::MOVING_MAX_ACCURACY_M);

            if ($usable) {
                $elapsed = CarbonImmutable::parse($newest['recorded_at'])
                    ->getTimestamp() - CarbonImmutable::instance($anchorAt)->getTimestamp();

                if ($elapsed >= self::MOVING_BASELINE_S) {
                    $travelled = $this->metresBetween(
                        $anchorLat,
                        $anchorLng,
                        (float) $newest['latitude'],
                        (float) $newest['longitude'],
                    );

                    $needed = max(
                        self::MOVING_MIN_METRES,
                        self::MOVING_ACCURACY_FACTOR * ($accuracy ?? 0.0),
                    );

                    $moving = $travelled >= $needed
                        && ($travelled / $elapsed) >= self::MOVING_SPEED_MS;
                }

                // Below the baseline the previous verdict stands. A phone
                // flushing every few seconds must not be re-judged on every
                // flush over a window too short to judge anything.
            }
        }

        if ($newest !== null) {
            DB::transaction(function () use ($me, $rows, $newest, $moving) {
                /*
                 | insert(), not createMany().
                 |
                 | Two reasons. It is one statement for the whole buffer
                 | rather than one per row, which matters on the highest-write
                 | path in the system. And it bypasses mass assignment
                 | entirely — on this codebase a non-Fillable column is
                 | dropped silently, and a trail with null coordinates that
                 | nothing complained about is a bad afternoon.
                 */
                LocationPing::insert($rows);

                $me->forceFill([
                    'last_latitude' => $newest['latitude'],
                    'last_longitude' => $newest['longitude'],
                    'last_location_accuracy' => $newest['accuracy'],
                    'last_location_speed' => $newest['speed'],
                    'last_location_heading' => $newest['heading'],
                    'last_location_moving' => $moving,
                    'last_location_at' => $newest['recorded_at'],
                    'battery_level' => $newest['battery_level'] ?? $me->battery_level,
                ])->save();
            });
        }

        if ($newest !== null) {
            /*
             | Geofences, after the write and only on a fix that survived the
             | filters.
             |
             | Order matters twice over. After the transaction, because a
             | geofence is a convenience and a position is the product — this
             | must never be the thing that rolls a ping back. And only on an
             | accepted fix, because feeding a rejected cell-tower reading in
             | here is exactly how "Aarav arrived at School" fires from two
             | kilometres away.
             |
             | Runs even when $broadcast is false: that flag is about the
             | opening fix of a share not announcing itself twice, and it has
             | nothing to say about whether somebody is standing in a circle.
             */
            $this->places->evaluate(
                $me,
                (float) $newest['latitude'],
                (float) $newest['longitude'],
            );
        }

        if ($newest !== null && $broadcast) {
            /*
             | A dead socket must not fail a ping.
             |
             | LocationUpdated broadcasts synchronously (see the note on that
             | class), so a Reverb that is down or slow would otherwise turn
             | into a failed write on the phone — which is the one thing that
             | must never happen. The fix is already committed by this point;
             | the worst case is a map that catches up on the next one.
             */
            try {
                LocationUpdated::dispatch($me->fresh());
            } catch (\Throwable $e) {
                Log::warning('location broadcast failed', [
                    'user' => $me->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'accepted' => count($rows),
            'rejected' => $rejected,
            'tracking' => $this->tracking($me),
        ];
    }

    /**
     * What the phone should do next.
     *
     * The client asks for nothing and obeys this. Making the server the
     * authority means the sampling rate can be changed for everyone at once,
     * a share that has expired can be shut down from here rather than hoping
     * a timer on the phone fires, and — the reason it exists — a stationary
     * phone can be told to look every 45 seconds instead of every 5.
     *
     * @return array<string, mixed>
     */
    public function tracking(User $me): array
    {
        $shares = $this->liveSharesOf($me)->get();

        if ($shares->isEmpty()) {
            return [
                'active' => false,
                'interval_seconds' => null,
                'distance_filter' => null,
                'until' => null,
                'background' => false,
            ];
        }

        $moving = (bool) $me->last_location_moving;

        /*
         | The soonest expiry across every live share.
         |
         | A null expiry (the family audience) beats any timestamp: if one
         | share runs until stopped, the tracker does not get to switch
         | itself off when a different, shorter one lapses.
         */
        $until = $shares->contains(fn (LocationShare $s) => $s->expires_at === null)
            ? null
            : $shares->min('expires_at');

        return [
            'active' => true,
            'interval_seconds' => $moving ? self::MOVING_INTERVAL_S : self::STILL_INTERVAL_S,
            'distance_filter' => $moving ? self::MOVING_DISTANCE_M : self::STILL_DISTANCE_M,
            'until' => $until instanceof \DateTimeInterface
                ? CarbonImmutable::instance($until)->toIso8601String()
                : $until,

            /*
             | Whether to keep running with the app in the background.
             |
             | Only the family audience earns it. A fifteen-minute share in a
             | chat does not justify a permanent notification and an "Always"
             | permission prompt, and asking for those to power a share that
             | expires before lunch is how an app gets rejected.
             */
            'background' => $shares->contains('audience', LocationShare::AUDIENCE_FAMILY),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The whole map, in one response.
     *
     * Everything the live map screen needs to paint itself from cold: who is
     * visible to me, where each of them is, and what I am sharing. The
     * socket only supplies deltas after this — the same contract as chat,
     * and the same reason: a dropped socket is a stale map, never an empty
     * one.
     *
     * @return array<string, mixed>
     */
    public function live(User $me): array
    {
        $shares = LocationShare::query()
            ->live()
            ->where('user_id', '!=', $me->id)
            ->with(['user', 'conversation.participants'])
            ->get()
            ->filter(fn (LocationShare $share) => $this->grants($me, $share));

        /*
         | One person can be sharing with me twice over — a family share and
         | a fifteen-minute one in a thread. That is one pin on the map, and
         | the one that ends last is the one whose expiry it carries.
         */
        $people = $shares
            ->groupBy(fn (LocationShare $share) => $share->user_id)
            ->map(function (Collection $group) {
                $sharer = $group->first()->user;

                $widest = $group->sortBy(
                    fn (LocationShare $s) => $s->expires_at?->getTimestamp() ?? PHP_INT_MAX,
                )->last();

                return [
                    'user' => [
                        'id' => $sharer->uuid,
                        'name' => $sharer->name,
                        'username' => $sharer->username,
                        'avatar_url' => $sharer->avatar_url,
                    ],
                    'position' => $this->presentPosition($sharer),
                    'share' => $this->presentShare($widest),
                ];
            })
            ->values()
            ->all();

        $mine = $this->liveSharesOf($me)->with('conversation')->get();

        $family = $this->roster($me, $shares);

        return [
            'people' => $people,
            'family' => $family,
            'places' => $this->places->forOwner($me)
                ->map(fn ($place) => $this->places->present($place))
                ->values()
                ->all(),
            'status' => $this->summarise($family),
            'me' => [
                'position' => $this->presentPosition($me),
                'shares' => $mine->map(fn (LocationShare $s) => $this->presentShare($s))
                    ->values()->all(),
            ],
            'tracking' => $this->tracking($me),
            'stale_after_seconds' => self::STALE_AFTER_S,
            'online_within_seconds' => self::ONLINE_WITHIN_S,
        ];
    }

    /**
     * Everybody in my family, whether or not they are sharing.
     *
     * `people` above answers "who can I draw on the map". This answers "who
     * is in my family", which is a different and larger set — and it is the
     * one the status card is about. A card that says "4 members safe" has to
     * count the member whose phone is in a drawer, or it is not a family
     * status card, it is a sharing indicator with a misleading title.
     *
     * A member who is not sharing still gets a row: name, avatar, presence,
     * and a null position. The client draws no pin for them and says so.
     *
     * @param  Collection<int, LocationShare>  $visibleShares  already filtered by grants()
     * @return array<int, array<string, mixed>>
     */
    private function roster(User $me, Collection $visibleShares): array
    {
        $sharing = $visibleShares->groupBy(fn (LocationShare $share) => $share->user_id);

        $family = $this->familyOf($me);

        /*
         | Everybody's current place, in one query rather than one per member.
         |
         | This is the hottest read in the feature — every map refresh, for
         | every member — and an N+1 here would be an N+1 on exactly the path
         | that has to feel instant.
         */
        $inside = $this->places->currentPlaces(
            $me,
            $family->pluck('id')->all(),
        );

        return $family
            ->map(function (User $member) use ($sharing, $inside) {
                $shares = $sharing->get($member->id);
                $visible = $shares !== null && $shares->isNotEmpty();

                return [
                    'user' => [
                        'id' => $member->uuid,
                        'name' => $member->name,
                        'username' => $member->username,
                        'avatar_url' => $member->avatar_url,
                    ],
                    'sharing' => $visible,

                    /*
                     | Null rather than a stale position when they are not
                     | sharing. The row still exists, so the client can show
                     | the person; what it must not do is draw a pin from
                     | whenever they last shared and let it age silently on
                     | the map. Permission to be seen is not permission that
                     | outlives the share.
                     */
                    'position' => $visible ? $this->presentPosition($member) : null,
                    'presence' => $this->presence($member),
                    'movement' => $visible
                        ? $this->movementOf($member, $inside[$member->id] ?? null)
                        : 'unknown',

                    /*
                     | The place's own name, alongside the movement key.
                     |
                     | `movement` is a key the client switches on; this is the
                     | words to print. Sending both means "At Nani's house"
                     | renders correctly on a build that has never heard of a
                     | place called that — which is every build, because
                     | people name their own places.
                     */
                    'place' => ($visible && isset($inside[$member->id]))
                        ? [
                            'id' => $inside[$member->id]->uuid,
                            'name' => $inside[$member->id]->name,
                            'kind' => $inside[$member->id]->kind,
                        ]
                        : null,
                ];
            })
            ->all();
    }

    /**
     * Online, offline, or none of your business.
     *
     * `show_last_seen` is a real setting and it is honoured here rather than
     * in the client, because a client that receives the timestamp and
     * promises not to render it has still received the timestamp.
     *
     * @return array<string, mixed>
     */
    private function presence(User $user): array
    {
        if (! $user->show_last_seen) {
            return ['state' => 'hidden', 'last_seen_at' => null, 'age_seconds' => null];
        }

        $at = $user->last_seen_at;

        if ($at === null) {
            return ['state' => 'offline', 'last_seen_at' => null, 'age_seconds' => null];
        }

        $age = max(0, now()->diffInSeconds($at, true));

        return [
            'state' => $age <= self::ONLINE_WITHIN_S ? 'online' : 'offline',
            'last_seen_at' => $at->toIso8601String(),
            'age_seconds' => $age,
        ];
    }

    /**
     * What somebody is doing, in one word.
     *
     * Phase 1 answers this from the motion flag alone. Phase 2 replaces the
     * return value with a place name — "home", "school" — when the position
     * falls inside a family place, and the client already renders whatever
     * string arrives. That is the point of computing it here: the card's
     * vocabulary can grow without a store release.
     */
    private function movementOf(User $user, ?FamilyPlace $inside = null): string
    {
        $at = $user->last_location_at;

        if ($at === null) {
            return 'unknown';
        }

        if (now()->diffInSeconds($at, true) > self::STALE_AFTER_S) {
            return 'stale';
        }

        /*
         | A place beats a motion flag, even a moving one.
         |
         | Somebody walking around inside the school grounds reads as
         | "travelling" to the accelerometer and as "at school" to anybody who
         | asks where they are. The second answer is the one the question
         | wanted, and a label that flips to "Travelling" because a child
         | crossed a playground would make the feature look broken.
         |
         | The key is the place's kind, not its name: "home" is something the
         | client can colour and icon, "Nani's house" is not. The name travels
         | separately, in the roster's `place` block.
         */
        if ($inside !== null) {
            return $inside->kind;
        }

        return $user->last_location_moving ? 'travelling' : 'stationary';
    }

    /**
     * The family status card, decided server-side.
     *
     * The client renders buckets it is handed and counts nothing itself.
     * That is what lets Phase 2 add "At Home" and "At School" — which need
     * the places table the client knows nothing about — by changing this
     * method alone.
     *
     * The tone is deliberately not alarming by default. A safety app that
     * shouts at you when somebody's phone is simply asleep teaches people to
     * ignore it, and then it is not there on the day it matters.
     *
     * @param  array<int, array<string, mixed>>  $family
     * @return array<string, mixed>
     */
    private function summarise(array $family): array
    {
        $count = count($family);

        $travelling = 0;
        $stationary = 0;
        $offline = 0;

        /** @var array<string, array{label: string, count: int, kind: string}> $atPlace */
        $atPlace = [];

        foreach ($family as $row) {
            if (($row['presence']['state'] ?? null) === 'offline') {
                $offline++;
            }

            $place = $row['place'] ?? null;

            if ($place !== null) {
                /*
                 | Grouped by name, not by kind.
                 |
                 | Two people at "Home" is one bucket reading 2. Two people at
                 | two *different* homes — a parent's and a grandparent's,
                 | both of kind `home` — is two buckets, because collapsing
                 | them would produce "2 At Home" for a family that is in two
                 | different houses, which is worse than no card at all.
                 */
                $key = $place['name'];

                $atPlace[$key] ??= [
                    'label' => 'At '.$place['name'],
                    'count' => 0,
                    'kind' => $place['kind'],
                ];

                $atPlace[$key]['count']++;

                continue;
            }

            match ($row['movement']) {
                'travelling' => $travelling++,
                'stationary' => $stationary++,
                default => null,
            };
        }

        /*
         | One word per bucket, not two.
         |
         | A caption under each label was cut after laying the card out at
         | 360dp: four buckets across a small phone leaves about 82dp each,
         | and a second line of prose there is either illegible or it pushes
         | the card over a third of the screen. The label carries it.
         |
         | Four buckets, and always exactly four. The card is a fixed row and
         | a fifth would either wrap or shrink the other four; a third would
         | leave a gap. So the first slot is always the headcount, and the
         | remaining three are competed for.
         */
        $slots = [];

        // Places first, biggest first: "2 At Home" is more informative than
        // "2 Stationary", and it is the sentence somebody came here for.
        uasort($atPlace, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        foreach ($atPlace as $bucket) {
            $slots[] = [
                'key' => $bucket['kind'],
                'label' => $bucket['label'],
                'count' => $bucket['count'],
                'icon' => $bucket['kind'],
            ];
        }

        /*
         | Then the generic buckets, and only when they have somebody in them.
         |
         | A zero is not neutral on a safety card — "0 Travelling" invites the
         | reader to work out whether that is good news, every single time
         | they glance at it. An absent bucket asks nothing.
         |
         | Offline is the exception and is kept even at zero once there is
         | room, because its zero *is* the reassurance: "nobody is offline" is
         | the thing a parent is checking for.
         */
        foreach ([
            ['key' => 'travelling', 'label' => 'Travelling', 'count' => $travelling, 'icon' => 'car'],
            ['key' => 'stationary', 'label' => 'Stationary', 'count' => $stationary, 'icon' => 'home'],
        ] as $bucket) {
            if ($bucket['count'] > 0) {
                $slots[] = $bucket;
            }
        }

        $slots[] = ['key' => 'offline', 'label' => 'Offline', 'count' => $offline, 'icon' => 'offline'];

        $buckets = array_merge(
            [[
                'key' => 'members',
                'label' => $count === 1 ? 'Member' : 'Members',
                'count' => $count,
                'icon' => 'group',
            ]],
            array_slice($slots, 0, 3),
        );

        return [
            'tone' => $count === 0 ? 'empty' : 'safe',
            'headline' => $count === 0 ? 'No family yet' : 'All is good!',
            'detail' => $count === 0
                ? 'Add family members to see them here.'
                : 'Your family is safe.',
            'buckets' => $buckets,
        ];
    }

    /**
     * One person's recent path.
     *
     * Capped at 24 hours and thinned on the way out: a phone moving for an
     * hour produces hundreds of points, and a polyline does not get more
     * accurate past a few hundred — it only gets heavier to send and slower
     * to draw.
     *
     * @return array<string, mixed>
     */
    public function trail(User $viewer, string $sharerUuid, ?string $since = null): array
    {
        $sharer = User::where('uuid', $sharerUuid)->first();

        abort_if($sharer === null, 404, 'That account no longer exists.');

        abort_unless(
            $sharer->id === $viewer->id || $this->canView($viewer, $sharer),
            403,
            'You cannot see that location.',
        );

        $floor = now()->subHours(self::MAX_TRAIL_HOURS);

        $from = $since !== null
            ? CarbonImmutable::parse($since)->max($floor)
            : CarbonImmutable::instance($floor);

        $points = LocationPing::query()
            ->where('user_id', $sharer->id)
            ->since($from)
            ->orderBy('recorded_at')
            ->limit(2000)
            ->get(['latitude', 'longitude', 'accuracy', 'recorded_at']);

        return [
            'user_id' => $sharer->uuid,
            'from' => $from->toIso8601String(),
            /*
             | Simplified, not thinned.
             |
             | The old version kept one point in N, which is blind to shape:
             | it spent its budget on the straight stretch where nothing
             | happens and threw away the roundabout, because the roundabout
             | fell between two strides. Douglas–Peucker keeps corners and
             | drops straights, so the same 400 points describe the route that
             | was actually driven. On a test route it cut 637 points to 12
             | and half of the survivors were the roundabout.
             |
             | The per-point timestamp and accuracy go with it. Nothing drew
             | them — the trail is a line under a marker — and keeping them
             | would have meant carrying LocationPing rows through the
             | simplifier for no reader's benefit.
             */
            'points' => array_map(
                fn (array $p) => [
                    'latitude' => $p['lat'],
                    'longitude' => $p['lng'],
                ],
                RouteSimplifier::fit(
                    $points->map(fn (LocationPing $p) => [
                        'lat' => (float) $p->latitude,
                        'lng' => (float) $p->longitude,
                    ])->values()->all(),
                    400,
                ),
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */

    /**
     * May this person see that person's location, right now?
     *
     * The only answer to that question in the codebase. routes/channels.php
     * calls it to authorise a websocket subscribe, and every read path calls
     * it before returning a coordinate.
     */
    public function canView(User $viewer, User $sharer): bool
    {
        if ($viewer->id === $sharer->id) {
            return true;
        }

        $shares = LocationShare::query()
            ->live()
            ->where('user_id', $sharer->id)
            ->with('conversation.participants')
            ->get();

        foreach ($shares as $share) {
            if ($this->grants($viewer, $share)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one share, on its own, lets one person see the sharer.
     */
    private function grants(User $viewer, LocationShare $share): bool
    {
        /*
         | A wall beats a share, in both directions.
         |
         | Blocking somebody has to revoke what they can see, and it has to
         | do so without asking the person who blocked them to remember which
         | shares they had running. Checked here rather than at share time so
         | a block placed afterwards takes effect immediately.
         */
        if ($this->walled($viewer->id, $share->user_id)) {
            return false;
        }

        if ($share->audience === LocationShare::AUDIENCE_FAMILY) {
            return FamilyMember::query()
                ->accepted()
                ->where(function ($q) use ($viewer, $share) {
                    $q->where(fn ($i) => $i
                        ->where('owner_id', $share->user_id)
                        ->where('member_id', $viewer->id))
                        ->orWhere(fn ($i) => $i
                            ->where('owner_id', $viewer->id)
                            ->where('member_id', $share->user_id));
                })
                ->exists();
        }

        if ($share->audience === LocationShare::AUDIENCE_CONVERSATION) {
            $mine = $share->conversation?->participants
                ->firstWhere('user_id', $viewer->id);

            return $mine !== null && ! $mine->hasLeft();
        }

        return false;
    }

    /**
     * Everybody one share is visible to.
     *
     * @return array<int, User>
     */
    public function viewersOf(User $sharer, LocationShare $share): array
    {
        $users = match ($share->audience) {
            LocationShare::AUDIENCE_FAMILY => $this->familyOf($sharer),

            LocationShare::AUDIENCE_CONVERSATION => $share->conversation === null
                ? collect()
                : $share->conversation->participants()
                    ->whereNull('left_at')
                    ->where('user_id', '!=', $sharer->id)
                    ->with('user')
                    ->get()
                    ->map(fn (ConversationParticipant $p) => $p->user),

            default => collect(),
        };

        return $users
            ->filter(fn (?User $u) => $u !== null && ! $this->walled($u->id, $sharer->id))
            ->values()
            ->all();
    }

    /**
     * Accepted family, from both ends of the link.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function familyOf(User $user): \Illuminate\Support\Collection
    {
        $links = FamilyMember::query()
            ->accepted()
            ->involving($user->id)
            ->with(['owner', 'member'])
            ->get();

        return $links
            ->map(fn (FamilyMember $link) => $link->counterpartFor($user))
            ->filter()
            ->unique('id')
            ->values();
    }

    /** A block in either direction between two ids. */
    private function walled(int $a, int $b): bool
    {
        if ($a === $b) {
            return false;
        }

        return Block::query()
            ->where(fn ($q) => $q->where('blocker_id', $a)->where('blocked_id', $b))
            ->orWhere(fn ($q) => $q->where('blocker_id', $b)->where('blocked_id', $a))
            ->exists();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<LocationShare>
     */
    private function liveSharesOf(User $user)
    {
        return LocationShare::query()->live()->where('user_id', $user->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Drop a static pin into a thread.
     *
     * "Here is where I am" rather than "follow me". No share row, no
     * tracking, nothing to expire — the coordinates on the message are the
     * whole of it, and they are as true tomorrow as they are now because
     * they are a statement about a moment rather than a claim about the
     * present.
     *
     * @return array<string, mixed>
     */
    public function pin(User $me, string $conversationUuid, float $lat, float $lng): array
    {
        $conversation = Conversation::with('participants.user')
            ->where('uuid', $conversationUuid)
            ->first();

        abort_if($conversation === null, 404, 'That conversation no longer exists.');

        $mine = $conversation->participants->firstWhere('user_id', $me->id);

        abort_if($mine === null || $mine->hasLeft(), 403, 'You are not in that conversation.');

        $message = $this->pinMessage($me, $conversation, null, $lat, $lng);

        return ['message' => $this->chat->presentMessage($message)];
    }

    /**
     * The message row itself.
     *
     * Modelled on GroupService::systemMessage rather than routed through
     * ChatService::send: a pin needs the seq allocation and the conversation
     * bookkeeping, and none of the reply, attachment, block or
     * message-request machinery that send() exists to apply.
     */
    private function pinMessage(
        User $me,
        Conversation $conversation,
        ?LocationShare $share = null,
        ?float $lat = null,
        ?float $lng = null,
    ): Message {
        $lat ??= $me->last_latitude !== null ? (float) $me->last_latitude : null;
        $lng ??= $me->last_longitude !== null ? (float) $me->last_longitude : null;

        $message = DB::transaction(function () use ($conversation, $me, $share, $lat, $lng) {
            $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            $seq = $locked->last_seq + 1;

            $message = new Message([
                'type' => Message::TYPE_LOCATION,
                'body' => $share !== null ? 'Live location' : 'Location',
                'client_uuid' => (string) Str::uuid7(),
            ]);

            // Not Fillable. Assigned, or silently dropped.
            $message->conversation_id = $conversation->id;
            $message->sender_id = $me->id;
            $message->seq = $seq;
            $message->latitude = $lat;
            $message->longitude = $lng;
            $message->save();

            $locked->forceFill([
                'last_seq' => $seq,
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ])->save();

            return $message;
        });

        $fresh = $message->fresh(['sender', 'attachment']);

        $this->chat->announce($conversation, $fresh);

        return $fresh;
    }

    /**
     * The location half of a message payload.
     *
     * Called from ChatService::presentMessage, which is why it is tolerant:
     * a message whose share was deleted, or which never had one, is a static
     * pin rather than an error.
     *
     * @return array<string, mixed>|null
     */
    public function presentMessageLocation(Message $message): ?array
    {
        if ($message->type !== Message::TYPE_LOCATION) {
            return null;
        }

        $share = LocationShare::where('message_id', $message->id)->first();

        return [
            'latitude' => $message->latitude !== null ? (float) $message->latitude : null,
            'longitude' => $message->longitude !== null ? (float) $message->longitude : null,
            'live' => $share !== null,
            'active' => $share?->isLive() ?? false,
            'share_id' => $share?->uuid,
            'expires_at' => $share?->expires_at?->toIso8601String(),
            'ended_at' => $share?->ended_at?->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function presentShare(LocationShare $share): array
    {
        return [
            'id' => $share->uuid,
            'user_id' => $share->user?->uuid ?? $share->user()->value('uuid'),
            'audience' => $share->audience,
            'conversation_id' => $share->conversation_id === null
                ? null
                : Conversation::whereKey($share->conversation_id)->value('uuid'),
            'started_at' => $share->started_at?->toIso8601String(),
            'expires_at' => $share->expires_at?->toIso8601String(),
            'ended_at' => $share->ended_at?->toIso8601String(),
            'active' => $share->isLive(),
        ];
    }

    /**
     * One position, as the map draws it.
     *
     * `age_seconds` rather than a bare timestamp because the client has to
     * decide whether to grey the pin out, and doing that from a timestamp
     * means trusting the phone's clock — which is the one clock in the
     * system nobody controls.
     *
     * @return array<string, mixed>
     */
    public function presentPosition(User $user): array
    {
        $at = $user->last_location_at;

        return [
            'user_id' => $user->uuid,
            'has_fix' => $user->last_latitude !== null,
            'latitude' => $user->last_latitude !== null ? (float) $user->last_latitude : null,
            'longitude' => $user->last_longitude !== null ? (float) $user->last_longitude : null,
            'accuracy' => $user->last_location_accuracy,
            'speed' => $user->last_location_speed !== null
                ? (float) $user->last_location_speed
                : null,
            'heading' => $user->last_location_heading !== null
                ? (float) $user->last_location_heading
                : null,
            'moving' => (bool) $user->last_location_moving,
            'battery_level' => $user->battery_level,
            'recorded_at' => $at?->toIso8601String(),
            'age_seconds' => $at === null ? null : max(0, now()->diffInSeconds($at, true)),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Geometry
    |--------------------------------------------------------------------------
    */

    /**
     * Great-circle distance in metres.
     *
     * Haversine, not a flat-earth approximation. The flat version is faster
     * and wrong by a metre or two over the distances involved here, which
     * would be fine — except it is wrong by more the further north you go,
     * and a filter whose threshold drifts with latitude is a filter nobody
     * can reason about.
     */
    private function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

}
