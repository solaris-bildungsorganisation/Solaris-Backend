<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\AuthEvent;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\SessionService;
use App\Services\VerificationCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;
class PasswordResetController extends Controller
{
    public function __invoke(
        ForgotPasswordRequest $request,
        VerificationCodeService $verificationCodeService,
    ) {
        $identifier = $request->string('identifier')->toString();
        $user = User::query()->where('email', $identifier)->first();
        $channel = 'email';
        $destination = $user?->email;

        if (! $user) {
            $user = User::query()->where('phone', $identifier)->first();
            $channel = 'sms';
            $destination = $user?->phone;
        }

        if ($user) {
            $issuedCode = $verificationCodeService->issue(
                channel: $channel,
                destination: $destination,
                userId: $user->getKey(),
                ipAddress: $request->ip(),
            );

    if ($destination) {
    try {
        app(NotificationService::class)->send(
            $user,
            'account.password_reset',
            [],
            ['secret' => $issuedCode['code']]
        );
    } catch (Throwable $e) {
        Log::warning('Failed to send password reset code notification.', [
            'user_id' => $user->getKey(),
            'error' => $e->getMessage(),
        ]);
    }
}

            AuthEvent::query()->create([
                'user_id' => $user->getKey(),
                'event' => 'password_reset_requested',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'message' => 'إذا كانت البيانات صحيحة، سيتم إرسال رمز استعادة كلمة المرور.',
        ], 202);
    }

    public function reset(ResetPasswordRequest $request,
    VerificationCodeService $verificationCodeService,
    SessionService $sessionService,
    NotificationService $notificationService,
) {
        $identifier = $request->string('identifier')->toString();
        $user = User::query()->where('email', $identifier)->first();
        $channel = 'email';
        $destination = $user?->email;

        if (! $user) {
            $user = User::query()->where('phone', $identifier)->first();
            $channel = 'sms';
            $destination = $user?->phone;
        }

        $verificationCode = $user
            ? VerificationCode::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', 'password_reset')
                ->where('channel', $channel)
                ->where('destination', $destination)
                ->usable()
                ->latest('created_at')
                ->first()
            : null;

        if (! $user || ! $verificationCode) {
            return $this->invalidResetResponse();
        }

        $newPassword = $request->string('password')->toString();
        $recentHistories = PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();

        if (Hash::check($newPassword, $user->getRawOriginal('password'))) {
            return $this->invalidResetResponse();
        }

        foreach ($recentHistories as $history) {
            if (Hash::check($newPassword, $history->password_hash)) {
                return $this->invalidResetResponse();
            }
        }

        $updated = DB::transaction(function () use ($request, $user, $verificationCode, $verificationCodeService, $newPassword): bool {
            if (! $verificationCodeService->verify($verificationCode, $request->string('code')->toString())) {
                return false;
            }

            PasswordHistory::query()->create([
                'user_id' => $user->getKey(),
                'password_hash' => $user->getRawOriginal('password'),
            ]);

            $user->forceFill([
                'password' => $newPassword,
                'password_changed_at' => now(),
                'must_change_password' => false,
                'failed_login_count' => 0,
                'locked_until' => null,
            ])->save();

            AuthEvent::query()->create([
                'user_id' => $user->getKey(),
                'event' => 'password_reset_completed',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return true;
        });

        if (! $updated) {
            return $this->invalidResetResponse();
        }
$sessionService->revokeAllExcept($user, null, null, 'password_changed');
try {
    $notificationService->send($user, 'security.password_changed');
} catch (Throwable $exception) {
    Log::warning('Failed to send password changed notification.', [
        'user_id' => $user->getKey(),
    ]);
}

return response()->json([
    'message' => 'تمت إعادة تعيين كلمة المرور بنجاح.',
]);
    }



    private function invalidResetResponse()
    {
        return response()->json([
            'message' => 'تعذر إعادة تعيين كلمة المرور بالبيانات المقدمة.',
        ], 422);
    }
}
