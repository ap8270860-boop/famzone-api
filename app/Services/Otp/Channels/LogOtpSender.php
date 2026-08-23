<?php

namespace App\Services\Otp\Channels;

use App\Services\Otp\Contracts\OtpSender;
use Illuminate\Support\Facades\Log;

/**
 * Development channel: writes the code to the log instead of sending an SMS.
 *
 * Paired with `otp.expose_in_response`, this lets the app display the code on
 * screen until MSG91 is connected.
 */
class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $code): void
    {
        Log::info('[OTP] code issued', [
            'phone' => $phone,
            'code' => $code,
        ]);
    }

    public function delivers(): bool
    {
        return false;
    }
}
