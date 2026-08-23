<?php

namespace App\Services\Otp\Contracts;

/**
 * How a code reaches the user.
 *
 * Swapping SMS providers, or adding WhatsApp or voice later, means writing a
 * new implementation and changing one config value — nothing above this
 * interface has to know.
 */
interface OtpSender
{
    /**
     * Deliver the code. Throws on failure; returning normally means sent.
     */
    public function send(string $phone, string $code): void;

    /**
     * Whether this channel actually delivers anywhere the user can see.
     * False for the log driver, which is why the API echoes the code back
     * during development.
     */
    public function delivers(): bool;
}
