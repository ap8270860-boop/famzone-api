#!/usr/bin/env python3
"""Add Chat, Realtime and Posts to the Postman collection.

Run from the famzone-api repo root. Idempotent.
"""

import io
import json
import sys

P = 'docs/postman/SFamily-API-v1.postman_collection.json'

collection = json.load(io.open(P, encoding='utf-8'))

if any(f['name'] == 'Chat' for f in collection['item']):
    sys.exit(f'{P}: already has a Chat folder')

JSON_HEADERS = [
    {'key': 'Accept', 'value': 'application/json'},
    {'key': 'Content-Type', 'value': 'application/json'},
]

ACCEPT_ONLY = [{'key': 'Accept', 'value': 'application/json'}]


def url(path, query=None):
    """Build a Postman url object from a slash path."""
    segments = [s for s in path.split('/') if s]
    raw = '{{base_url}}/' + '/'.join(segments)

    if query:
        raw += '?' + '&'.join(f'{k}={v}' for k, v in query)

    built = {'raw': raw, 'host': ['{{base_url}}'], 'path': segments}

    if query:
        built['query'] = [{'key': k, 'value': str(v)} for k, v in query]

    return built


def req(name, method, path, description, body=None, query=None, save=None):
    item = {
        'name': name,
        'request': {
            'method': method,
            'header': JSON_HEADERS if body else ACCEPT_ONLY,
            'url': url(path, query),
            'description': description,
        },
        'response': [],
    }

    if body is not None:
        item['request']['body'] = {
            'mode': 'raw',
            'raw': json.dumps(body, indent=2),
            'options': {'raw': {'language': 'json'}},
        }

    # A test script that stashes an id, so the next request in the folder
    # works without copying anything by hand.
    if save:
        variable, pointer = save
        item['event'] = [{
            'listen': 'test',
            'script': {
                'type': 'text/javascript',
                'exec': [
                    'const body = pm.response.json();',
                    '',
                    f'if (body.success && {pointer}) {{',
                    f"  pm.collectionVariables.set('{variable}', {pointer});",
                    f"  console.log('{variable} =', {pointer});",
                    '}',
                ],
            },
        }]

    return item


# ============================================================== Realtime

REALTIME = {
    'name': 'Realtime',
    'description': (
        'Signing a websocket subscription.\n\n'
        'The one endpoint in the API that does **not** use the standard\n'
        'envelope — Laravel replies with a bare `{"auth": "key:signature"}`,\n'
        'because the Pusher protocol defines the shape, not us.\n\n'
        'See `docs/postman/WEBSOCKET-TESTING.md` for the full flow.'
    ),
    'item': [
        req(
            'Broadcasting auth',
            'POST',
            'broadcasting/auth',
            'Signs one channel subscription for one socket.\n\n'
            '**Order matters.** Open the websocket first, read the `socket_id`\n'
            'out of the `pusher:connection_established` frame, set it as the\n'
            '`socket_id` collection variable, then run this. A signature is\n'
            'bound to a single socket id and is worthless on any other\n'
            'connection.\n\n'
            'Set `channel_name` to the channel you want, with its prefix:\n\n'
            '- `private-user.{your uuid}` — your mailbox\n'
            '- `private-conversation.{conversation uuid}` — one thread\n'
            '- `presence-room.{conversation uuid}` — who is in a thread\n\n'
            '**403 here is the answer, not an error.** It means the channel\n'
            'authorisation callback in `routes/channels.php` said no — you are\n'
            'not in that conversation, or a block sits between you and the\n'
            'other person.\n\n'
            'The test script saves the signature to `channel_auth`, which is\n'
            'what goes into the `pusher:subscribe` frame.',
            body={'socket_id': '{{socket_id}}', 'channel_name': '{{channel_name}}'},
            save=('channel_auth', 'body.auth'),
        ),
        req(
            'Presence ping',
            'POST',
            'presence/ping',
            'The heartbeat behind "online" and "last seen".\n\n'
            'Sent every 45 seconds by a foregrounded app. Writes one indexed\n'
            'column and answers with the interval the client should use, so\n'
            'the window can be widened later without shipping a new build.\n\n'
            'Somebody counts as online for 75 seconds after a ping — wider\n'
            'than the interval on purpose, so one dropped request does not\n'
            'flicker them offline and back.',
        ),
    ],
}


# ================================================================== Chat

ENVELOPE = (
    '\n---\n**Envelope.** Every response uses the same shape:\n\n'
    '```json\n'
    '{ "success": true,  "message": "...", "data": { } }\n'
    '{ "success": false, "message": "...", "errors": { } }\n'
    '```\n'
)

CHAT = {
    'name': 'Chat',
    'description': (
        'One-to-one messaging.\n\n'
        '**Messages travel over HTTP; the websocket only announces that one\n'
        'arrived.** Every screen can rebuild itself from these endpoints\n'
        'alone, which is what makes a dropped socket a delay rather than a\n'
        'lost message — and why they are all testable here without a socket\n'
        'in sight.\n\n'
        '**Sequence numbers.** Messages are ordered by `seq`, a counter that\n'
        'starts at 1 in each conversation. It is the sort key, the pagination\n'
        'cursor, and the unit both read receipts are measured in. It is not a\n'
        'database id and reveals nothing about the rest of the system.'
    ),
    'item': [
        req(
            'Inbox',
            'GET',
            'conversations',
            'Threads, newest activity first.\n\n'
            '`state=pending` is the Requests tab — the same query and the same\n'
            'shape, a different set of threads. A message from somebody you do\n'
            'not follow lands there instead of the inbox.\n\n'
            'Threads that have never been written in are omitted: tapping\n'
            'Message creates a conversation so the composer has somewhere to\n'
            'put a draft, but it is not a conversation until something is\n'
            'actually said.' + ENVELOPE,
            query=[('state', 'accepted'), ('page', 1)],
        ),
        req(
            'Unread count',
            'GET',
            'conversations/unread-count',
            'Badge numbers, for a cold start.\n\n'
            'Two counts, not one: `unread` is conversations waiting, `requests`\n'
            'is decisions waiting. Different kinds of attention, so collapsing\n'
            'them into one number makes the badge mean nothing precise.\n\n'
            'One grouped query over an indexed column — cheap enough to call\n'
            'on every foreground.' + ENVELOPE,
        ),
        req(
            'Start conversation',
            'POST',
            'conversations',
            'Open the thread with somebody, creating it only if there is not\n'
            'one already.\n\n'
            '**Safe to call every time.** Direct threads are keyed on a hash\n'
            'of both user ids, so a second call returns the first call\'s\n'
            'thread rather than a duplicate — including when both people tap\n'
            'Message in the same instant.\n\n'
            '403 means a block sits between the two of you, in either\n'
            'direction.' + ENVELOPE,
            body={'user_id': '{{user_id}}'},
            save=('conversation_id', 'body.data.id'),
        ),
        req(
            'Get conversation',
            'GET',
            'conversations/{{conversation_id}}',
            'The thread, both participants, and both sets of watermarks.\n\n'
            '`other.last_read_seq` and `other.last_delivered_seq` are what the\n'
            'ticks are drawn from: a message is read when its `seq` is at or\n'
            'below their read watermark. Two integers decide the state of\n'
            'every bubble in the thread, which is why a whole run of ticks\n'
            'turns blue at once rather than one at a time.\n\n'
            '404 rather than 403 when you are not in it — whether a\n'
            'conversation exists between two other people is not your\n'
            'business.' + ENVELOPE,
        ),
        req(
            'Messages',
            'GET',
            'conversations/{{conversation_id}}/messages',
            'Scrollback.\n\n'
            '`before=<seq>` walks back through history as the user scrolls up.\n'
            '`after=<seq>` fills the gap left by a dropped connection — pass\n'
            'the newest sequence number you already hold and get everything\n'
            'since.\n\n'
            'Both return messages in ascending order. A list that changes\n'
            'direction depending on which parameter was used is a bug waiting\n'
            'to be written.' + ENVELOPE,
            query=[('limit', 40)],
        ),
        req(
            'Send message',
            'POST',
            'conversations/{{conversation_id}}/messages',
            'Send.\n\n'
            '**`client_uuid` is required, and it is what makes a retry safe.**\n'
            'Send the same one twice and the second call returns the original\n'
            'message with `200` instead of creating a duplicate with `201` —\n'
            'from the sender\'s point of view the message was sent, which is\n'
            'true. Try it: run this request twice with the raw body edited so\n'
            '`client_uuid` is a fixed string rather than `{{$guid}}`.\n\n'
            'Broadcasts on two channels after the transaction commits: the\n'
            'conversation channel repaints an open chat screen, and each\n'
            'recipient\'s own channel keeps their inbox and badge right while\n'
            'no chat screen is open.\n\n'
            '403 with a message about accepting means the request cap — three\n'
            'messages before the other person accepts.' + ENVELOPE,
            body={
                'client_uuid': '{{$guid}}',
                'type': 'text',
                'body': 'Testing from Postman.',
            },
            save=('message_id', 'body.data.id'),
        ),
        req(
            'Mark read',
            'POST',
            'conversations/{{conversation_id}}/read',
            'Move the read watermark — the blue ticks.\n\n'
            'Monotonic: passing an older message is a no-op rather than a\n'
            'regression, so this is safe to fire on every scroll without any\n'
            'ordering guarantees. Reading also implies delivery, so the\n'
            'delivered watermark is dragged along with it.\n\n'
            'Recomputes `unread_count` exactly rather than decrementing it,\n'
            'which means a badge that has drifted wrong anywhere in the system\n'
            'heals itself the next time the thread is opened.' + ENVELOPE,
            body={'message_id': '{{message_id}}'},
        ),
        req(
            'Mark delivered',
            'POST',
            'conversations/{{conversation_id}}/delivered',
            'Move the delivered watermark — the second grey tick.\n\n'
            'Reported by the recipient\'s inbox, not by their chat screen:\n'
            '"delivered" means the message reached the device, which happens\n'
            'whether or not they opened the thread.\n\n'
            'Best effort by definition — a device that is off cannot report\n'
            'anything — so it gates nothing.' + ENVELOPE,
            body={'message_id': '{{message_id}}'},
        ),
        req(
            'Accept request',
            'POST',
            'conversations/{{conversation_id}}/accept',
            'Accept a message request.\n\n'
            'Moves your side of the thread from `pending` to `accepted`: it\n'
            'leaves the Requests tab, counts toward the unread badge, and\n'
            'raises notifications from then on.' + ENVELOPE,
        ),
        req(
            'Leave / decline',
            'DELETE',
            'conversations/{{conversation_id}}',
            'Leave a thread, or decline a request.\n\n'
            'The membership row survives with `left_at` set rather than being\n'
            'deleted, so a later message drops you back into the same thread\n'
            'instead of starting a second one beside it with half the history\n'
            'missing.' + ENVELOPE,
        ),
        req(
            'Delete message',
            'DELETE',
            'messages/{{message_id}}',
            'Delete for everyone.\n\n'
            'Soft, so the other person\'s client can swap the bubble for a\n'
            'tombstone. Removing the row outright takes a line out of the\n'
            'middle of somebody else\'s conversation with no explanation,\n'
            'which reads as a bug.\n\n'
            'The body is never sent again afterwards, not even to the author.\n'
            '"Deleted" has to mean deleted.' + ENVELOPE,
        ),
    ],
}


# ================================================================= Posts

POSTS = {
    'name': 'Posts',
    'description': 'Photo posts, likes and tags.',
    'item': [
        {
            'name': 'Create post',
            'request': {
                'method': 'POST',
                'header': ACCEPT_ONLY,
                'url': url('posts'),
                'description': (
                    'Publishes a photo. **Multipart, not JSON.**\n\n'
                    'The client crops before uploading; the real dimensions are\n'
                    'recorded either way so a grid can reserve the right box\n'
                    'before the bytes arrive.\n\n'
                    'Tagging is limited to people you follow or who follow you —\n'
                    'otherwise a post is a way to attach somebody\'s name to a\n'
                    'stranger\'s photo. Multipart has no list type, so tags go as\n'
                    'indexed keys: `tagged[0]`, `tagged[1]`.\n\n'
                    'Throttled 20/min.' + ENVELOPE
                ),
                'body': {
                    'mode': 'formdata',
                    'formdata': [
                        {'key': 'image', 'type': 'file', 'src': []},
                        {'key': 'caption', 'value': 'From Postman.', 'type': 'text'},
                        {'key': 'tagged[0]', 'value': '{{user_id}}', 'type': 'text',
                         'disabled': True},
                    ],
                },
            },
            'response': [],
            'event': [{
                'listen': 'test',
                'script': {
                    'type': 'text/javascript',
                    'exec': [
                        'const body = pm.response.json();',
                        '',
                        'if (body.success && body.data.id) {',
                        "  pm.collectionVariables.set('post_id', body.data.id);",
                        '}',
                    ],
                },
            }],
        },
        req('Show post', 'GET', 'posts/{{post_id}}',
            'One post with its like count, tags and a preview of who liked it.'
            + ENVELOPE),
        req('Like', 'POST', 'posts/{{post_id}}/like',
            'Idempotent — liking twice is not an error, because the caller\n'
            'asked for a state rather than an increment.' + ENVELOPE),
        req('Unlike', 'DELETE', 'posts/{{post_id}}/like',
            'Also idempotent.' + ENVELOPE),
        req('Post likes', 'GET', 'posts/{{post_id}}/likes',
            'Who liked it.' + ENVELOPE),
        req('User posts', 'GET', 'users/{{user_id}}/posts',
            'Somebody\'s grid, newest first.\n\n'
            'Answers `can_view: false` with an empty list rather than a 403\n'
            'when the account is private and you are not an accepted follower\n'
            '— the profile screen needs to draw a "private account" panel, and\n'
            'an error would make that look like a failure instead of a state.'
            + ENVELOPE,
            query=[('page', 1)]),
        req('Tagged posts', 'GET', 'users/{{user_id}}/tagged-posts',
            'Posts somebody has been tagged in.\n\n'
            'Two visibility rules apply, not one: you must be allowed to see\n'
            'the tagged person, and then each post is filtered by whether you\n'
            'may see its author. Otherwise tagging would be a hole straight\n'
            'through a private account.' + ENVELOPE,
            query=[('page', 1)]),
        req('Delete post', 'DELETE', 'posts/{{post_id}}', 'Soft delete.' + ENVELOPE),
    ],
}


# =============================================================== assemble

collection['item'].extend([CHAT, REALTIME, POSTS])

existing = {v['key'] for v in collection.get('variable', [])}

NEW_VARS = [
    ('conversation_id', '', 'Set automatically by "Start conversation".'),
    ('message_id', '', 'Set automatically by "Send message".'),
    ('post_id', '', 'Set automatically by "Create post".'),
    ('socket_id', '', 'From the pusher:connection_established frame.'),
    ('channel_name', 'private-user.', 'Channel to sign, with its prefix.'),
    ('channel_auth', '', 'Set automatically by "Broadcasting auth".'),
    ('ws_url', 'wss://ws.sfamily.co/app/lrqwccbcdgoprday7bub?protocol=7',
     'Paste into a Postman WebSocket request.'),
]

collection.setdefault('variable', [])

for key, value, description in NEW_VARS:
    if key in existing:
        continue

    collection['variable'].append({
        'key': key,
        'value': value,
        'type': 'string',
        'description': description,
    })

io.open(P, 'w', encoding='utf-8', newline='').write(
    json.dumps(collection, indent=2, ensure_ascii=False)
)

total = sum(len(f.get('item', [])) for f in collection['item'])

print(f'{P}: added Chat ({len(CHAT["item"])}), '
      f'Realtime ({len(REALTIME["item"])}), Posts ({len(POSTS["item"])})')
print(f'{P}: {len(collection["item"])} folders, {total} requests')
