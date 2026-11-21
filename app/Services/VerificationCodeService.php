<?php

namespace App\Services;

use App\Models\VerificationCode;
use Illuminate\Support\Facades\Hash;

class VerificationCodeService
{
    private const PURPOSE = 'password_reset';

    private const MAX_ATTEMPTS = 5;

    private const CODE_LENGTH = 6;

    /**
     * @return array{verificationCode: VerificationCode, code: string}
     */
    public function issue(
        string $channel,
        string $destination,
        ?int $userId = null,
        ?string $ipAddress = null,
    ): array {
        $code = (string) random_int(
            10 ** (self::CODE_LENGTH - 1),
            (10 ** self::CODE_LENGTH) - 1,
        );

        $verificationCode = VerificationCode::query()->create([
            'user_id' => $userId,
            'channel' => $channel,
            'destination' => $destination,
            'purpose' => self::PURPOSE,
            'code_hash' => Hash::make($code),
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => $ipAddress,
        ]);

        return [
            'verificationCode' => $verificationCode,
            'code' => $code,
        ];
    }

    public function verify(VerificationCode $verificationCode, string $code): bool
    {
        $verificationCode = VerificationCode::query()->find($verificationCode->getKey());

        if (! $verificationCode || $verificationCode->purpose !== self::PURPOSE) {
            return false;
        }

        if (! $verificationCode->usable()->exists()) {
            return false;
        }

        if (! Hash::check($code, $verificationCode->code_hash)) {
            $verificationCode->attempts++;

            if ($verificationCode->attempts >= self::MAX_ATTEMPTS) {
                $verificationCode->consumed_at = now();
            }

            $verificationCode->save();

            return false;
        }

        $verificationCode->consumed_at = now();
        $verificationCode->save();

        return true;
    }
}
