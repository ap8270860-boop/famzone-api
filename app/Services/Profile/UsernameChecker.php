<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Username validity, availability and suggestions.
 *
 * Kept out of the controller because the same rules apply in three places:
 * the live check while typing, the save, and any future admin tooling. One
 * copy means they cannot drift apart and let an invalid name through.
 */
class UsernameChecker
{
    public const MIN = 3;
    public const MAX = 32;

    /**
     * Names nobody may take: routes we serve, and anything that could be
     * mistaken for an official account.
     */
    private const RESERVED = [
        'admin', 'administrator', 'root', 'support', 'help', 'sfamily',
        'famzone', 'official', 'team', 'staff', 'security', 'moderator',
        'api', 'app', 'www', 'mail', 'me', 'you', 'user', 'users', 'null',
        'undefined', 'settings', 'profile', 'login', 'signup', 'register',
        'sos', 'emergency', 'police', 'ambulance',
    ];

    /**
     * Why a username cannot be used, or null when it is fine.
     *
     * @return array{reason: string, message: string}|null
     */
    public function reject(string $username): ?array
    {
        $length = mb_strlen($username);

        if ($length < self::MIN) {
            return ['too_short', 'Usernames need at least '.self::MIN.' characters.'];
        }

        if ($length > self::MAX) {
            return ['too_long', 'Usernames can be at most '.self::MAX.' characters.'];
        }

        if (! preg_match('/^[a-z][a-z0-9._]*$/', $username)) {
            return [
                'invalid_characters',
                'Start with a letter, then letters, numbers, dots or underscores.',
            ];
        }

        if (str_contains($username, '..') || str_contains($username, '__')) {
            return ['invalid_characters', 'No repeated dots or underscores.'];
        }

        if (str_ends_with($username, '.') || str_ends_with($username, '_')) {
            return ['invalid_characters', 'Cannot end with a dot or underscore.'];
        }

        if (in_array($username, self::RESERVED, true)) {
            return ['reserved', 'That username is reserved.'];
        }

        return null;
    }

    /** Whether the name is free, ignoring the user who already holds it. */
    public function isAvailable(string $username, ?int $exceptUserId = null): bool
    {
        return ! User::withTrashed()
            ->where('username', $username)
            ->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))
            ->exists();
    }

    /**
     * Nearby names that are actually free.
     *
     * Offering alternatives turns a dead end into a choice, which is the
     * difference between a user picking a name and abandoning the field.
     *
     * @return list<string>
     */
    public function suggest(string $username, int $limit = 3): array
    {
        $base = Str::of($username)->lower()->replaceMatches('/[^a-z0-9._]/', '');
        $base = rtrim((string) $base, '._');

        if ($base === '' || mb_strlen($base) < self::MIN) {
            return [];
        }

        $candidates = [];

        for ($i = 1; $i <= 99 && count($candidates) < $limit; $i++) {
            foreach (["{$base}{$i}", "{$base}_{$i}", "{$base}.{$i}"] as $candidate) {
                if (count($candidates) >= $limit) {
                    break;
                }
                if (mb_strlen($candidate) > self::MAX) {
                    continue;
                }
                if ($this->reject($candidate) === null
                    && $this->isAvailable($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /** Lowercase and trim, so "Abhi " and "abhi" are the same name. */
    public function normalise(string $username): string
    {
        return mb_strtolower(trim($username));
    }
}
