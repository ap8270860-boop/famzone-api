#!/usr/bin/env python3
"""Fix: createMany() drops user_id, which is not fillable.

Run from the famzone-api repo root. Idempotent.
"""

import io
import sys

P = 'app/Services/Chat/ChatService.php'
s = io.open(P, encoding='utf-8').read()

if 'private function addParticipant' in s:
    sys.exit(f'{P}: already patched')

OLD = """                // The opener has accepted by definition — they started it.
                $conversation->participants()->createMany([
                    ['user_id' => $me->id, 'state' => ConversationParticipant::STATE_ACCEPTED],
                    ['user_id' => $other->id, 'state' => $this->stateFor($other, $me)],
                ]);"""

NEW = """                // The opener has accepted by definition — they started it.
                $this->addParticipant(
                    $conversation,
                    $me,
                    ConversationParticipant::STATE_ACCEPTED,
                );

                $this->addParticipant(
                    $conversation,
                    $other,
                    $this->stateFor($other, $me),
                );"""

if OLD not in s:
    sys.exit(f'{P}: createMany block not found')

s = s.replace(OLD, NEW, 1)

# --------------------------------------------------------------- helper

OLD_HELPER = """    /**
     * Someone's inbox."""

NEW_HELPER = """    /**
     * Put one person in a conversation.
     *
     * The ids are assigned as properties rather than passed to the
     * constructor, because they are deliberately not in the model's Fillable
     * list. Which user a participant row belongs to is exactly the kind of
     * column that must never be settable from request data — the guard is
     * doing its job, so the service works with it rather than widening it.
     *
     * Mass assignment fails silently: createMany() dropped user_id without a
     * word and left MySQL to complain about a column with no default. Worth
     * remembering the next time a model refuses to save something obvious.
     */
    private function addParticipant(
        Conversation $conversation,
        User $user,
        string $state,
    ): ConversationParticipant {
        $participant = new ConversationParticipant(['state' => $state]);

        $participant->conversation_id = $conversation->id;
        $participant->user_id = $user->id;
        $participant->save();

        return $participant;
    }

    /**
     * Someone's inbox."""

if OLD_HELPER not in s:
    sys.exit(f'{P}: inbox docblock anchor not found')

s = s.replace(OLD_HELPER, NEW_HELPER, 1)

io.open(P, 'w', encoding='utf-8', newline='').write(s)
print(f'{P}: participants created with explicit ids')
