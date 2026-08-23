<?php

namespace App\Services\Otp\Exceptions;

use Exception;

/**
 * Anything that stops an OTP being issued or accepted.
 *
 * Carries an HTTP status and a machine-readable reason so the controller
 * does not have to string-match on the message.
 */
class OtpException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function tooSoon(int $secondsLeft): self
    {
        return new self(
            "Please wait {$secondsLeft} seconds before requesting another code.",
            'resend_cooldown',
            429,
            ['retry_after' => $secondsLeft],
        );
    }

    public static function hourlyLimit(): self
    {
        return new self(
            'Too many codes requested for this number. Try again later.',
            'hourly_limit',
            429,
        );
    }

    public static function notFound(): self
    {
        return new self(
            'No active code for this number. Request a new one.',
            'no_active_code',
            422,
        );
    }

    public static function expired(): self
    {
        return new self(
            'That code has expired. Request a new one.',
            'expired',
            422,
        );
    }

    public static function incorrect(int $attemptsLeft): self
    {
        return new self(
            'That code is not correct.',
            'incorrect',
            422,
            ['attempts_remaining' => $attemptsLeft],
        );
    }

    public static function attemptsExhausted(): self
    {
        return new self(
            'Too many incorrect attempts. Request a new code.',
            'attempts_exhausted',
            429,
        );
    }
}
