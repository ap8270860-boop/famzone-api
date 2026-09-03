#!/usr/bin/env python3
"""Editing a group, and removing somebody from one.

Renaming and changing the photo is open to any member; removing a person is
admin-only. See GroupService::update for why the line is drawn there.

Run from the famzone-api repo root. Idempotent, and every anchor is guarded.
"""

import io
import sys

changed = []


def read(p):
    return io.open(p, encoding='utf-8').read()


def write(p, s):
    io.open(p, 'w', encoding='utf-8', newline='').write(s)


def patch(path, pairs, marker):
    s = read(path)

    if marker in s:
        print(f'{path}: already patched')

        return

    for old, new in pairs:
        if old not in s:
            sys.exit(f'{path}: anchor missing ->\n{old[:240]}')

        s = s.replace(old, new, 1)

    write(path, s)
    changed.append(path)
    print(f'{path}: patched')


# ================================================================ routes

patch('routes/api.php', [
    (
        """            Route::post('archive', [V1Controller::class, 'archiveConversation'])""",
        """            /*
             | Group housekeeping.
             |
             | Renaming and the photo are open to any member; removing a
             | person is admin-only, and the service enforces that rather
             | than the route.
             */
            Route::post('group', [V1Controller::class, 'updateGroup'])
                ->middleware('throttle:30,1')
                ->name('group.update');

            Route::delete('members/{member}', [V1Controller::class, 'removeGroupMember'])
                ->middleware('throttle:60,1')
                ->name('group.members.destroy');

            Route::post('archive', [V1Controller::class, 'archiveConversation'])""",
    ),
], marker='group.update')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        "use App\\Http\\Requests\\Api\\V1\\Chat\\CreateGroupRequest;",
        "use App\\Http\\Requests\\Api\\V1\\Chat\\CreateGroupRequest;\n"
        "use App\\Http\\Requests\\Api\\V1\\Chat\\UpdateGroupRequest;",
    ),
    (
        """    /**
     * GET /api/v1/media/group/{uuid}   (signed)""",
        """    /**
     * POST /api/v1/conversations/{uuid}/group   (multipart)
     *
     * {title?, avatar?}
     *
     * Any member, not only an admin: a group's name and face are how the
     * room describes itself, and being the person who created it is not a
     * rank.
     */
    public function updateGroup(UpdateGroupRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $conversation = $this->groups->update(
            $me,
            $this->chat->findConversation($me, $uuid),
            $request->title(),
            $request->file('avatar'),
        );

        return $this->ok(
            $this->chat->presentConversation($me, $conversation, withMembers: true),
            'Group updated.',
        );
    }

    /**
     * DELETE /api/v1/conversations/{uuid}/members/{member}
     *
     * Admins only. Removing somebody is done to them rather than to the
     * room, which is where the line between this and renaming sits.
     */
    public function removeGroupMember(
        Request $request,
        string $uuid,
        string $member,
    ): JsonResponse {
        $me = $request->user();

        $this->groups->removeMember(
            $me,
            $this->chat->findConversation($me, $uuid),
            $this->findUser($member),
        );

        return $this->ok(null, 'Removed from the group.');
    }

    /**
     * GET /api/v1/media/group/{uuid}   (signed)""",
    ),
], marker='removeGroupMember')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
