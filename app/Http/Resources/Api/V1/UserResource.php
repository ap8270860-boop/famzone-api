<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\Social\RelationshipService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // The public identifier. The auto-increment id never leaves the
            // server, so user counts stay private and ids are not guessable.
            'id' => $this->uuid,

            'name' => $this->name,
            'username' => $this->username,
            'phone' => $this->full_phone_number,
            'phone_verified' => $this->phone_verified_at !== null,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,

            'avatar_url' => $this->avatar_url,
            'alternate_avatar_url' => $this->alternate_avatar_url,
            'use_alternate_avatar' => $this->use_alternate_avatar,
            'about' => $this->about,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'blood_group' => $this->blood_group,

            'user_type' => $this->user_type,
            'education_stage' => $this->education_stage,
            'needs_guardian' => $this->needsGuardian(),

            'locale' => $this->locale,
            'timezone' => $this->timezone,

            'privacy' => [
                'show_last_seen' => $this->show_last_seen,
                'show_online_status' => $this->show_online_status,
                'show_read_receipts' => $this->show_read_receipts,
                'allow_group_invites' => $this->allow_group_invites,

                // Private accounts turn follows into requests. Public
                // ones let anybody follow instantly and see the profile.
                'is_private' => $this->is_private,
                'emergency_message' => $this->emergency_message,
            ],

            // Followers, following and family. Three indexed counts, resolved
            // through the same service the public profile uses so the number
            // on your own profile can never disagree with the one somebody
            // else sees.
            //
            // This resource only ever represents the signed-in user, so there
            // is no per-row cost here — unlike the list endpoints, which
            // deliberately omit counts.
            'counts' => app(RelationshipService::class)->counts($this->resource),

            'subscription' => [
                'is_premium' => $this->hasActiveSubscription(),
                'plan' => $this->subscription_plan,
                'expires_at' => $this->subscription_expires_at?->toIso8601String(),
                'ai_messages_used' => $this->ai_messages_used,
                'ai_messages_limit' => User::AI_FREE_MESSAGE_LIMIT,
            ],

            'has_password' => filled($this->password),
            'referral_code' => $this->referral_code,
            'onboarding_completed' => $this->onboarding_completed_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
