<?php

namespace App\Services\Otp\Channels;

use App\Services\Otp\Contracts\OtpSender;
use App\Services\Otp\Exceptions\OtpException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MSG91 SMS delivery.
 *
 * Not exercised yet — flip `OTP_DRIVER=msg91` once the account and template
 * exist. Written now so the interface has a real second implementation and
 * the seams are proven rather than assumed.
 */
class Msg91OtpSender implements OtpSender
{
    private const ENDPOINT = 'https://control.msg91.com/api/v5/flow/';

    public function send(string $phone, string $code): void
    {
        $authKey = config('otp.msg91.auth_key');
        $templateId = config('otp.msg91.template_id');

        if (blank($authKey) || blank($templateId)) {
            throw new OtpException(
                'SMS delivery is not configured.',
                'provider_not_configured',
                500,
            );
        }

        $response = Http::withHeaders([
            'authkey' => $authKey,
            'accept' => 'application/json',
        ])->timeout(15)->post(self::ENDPOINT, [
            'template_id' => $templateId,
            'sender' => config('otp.msg91.sender_id'),
            // MSG91 wants the number without a leading "+".
            'mobiles' => ltrim($phone, '+'),
            'otp' => $code,
        ]);

        if ($response->failed()) {
            Log::error('[OTP] MSG91 delivery failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new OtpException(
                'Could not send the code right now. Please try again.',
                'delivery_failed',
                502,
            );
        }
    }

    public function delivers(): bool
    {
        return true;
    }
}
