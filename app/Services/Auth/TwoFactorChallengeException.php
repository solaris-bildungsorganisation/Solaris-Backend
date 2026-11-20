<?php

namespace App\Services\Auth;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** خطأ تحدّي الدخول (K2) — يُعرض JSON: {message, code} ليميّز الـ Frontend الحالة. */
class TwoFactorChallengeException extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($status, $message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->getStatusCode());
    }
}
