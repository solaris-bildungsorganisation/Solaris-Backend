<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Policies\ScopeGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class NotificationDeliveryService
{
    public function index(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = NotificationDelivery::query()
            ->with(['type', 'channel']);

        // تطبيق نطاق صلاحية المستخدم
        ScopeGuard::visibleTo(
            $query,
            $user,
            'notification_deliveries.view'
        );

        // فلترة حسب المستخدم
        if (!empty($filters['user'])) {
            $query->where('user_id', $filters['user']);
        }

        // فلترة حسب حالة الإرسال
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // فلترة حسب القناة
        if (!empty($filters['channel'])) {
            $query->whereHas('channel', function ($q) use ($filters) {
                $value = $filters['channel'];

                is_numeric($value)
                    ? $q->where('id', $value)
                    : $q->where('key', $value);
            });
        }

        // فلترة حسب نوع الإشعار
        if (!empty($filters['type'])) {
            $query->whereHas('type', function ($q) use ($filters) {
                $value = $filters['type'];

                is_numeric($value)
                    ? $q->where('id', $value)
                    : $q->where('key', $value);
            });
        }

        // من تاريخ
        if (!empty($filters['from'])) {
            $query->where(
                'created_at',
                '>=',
                Carbon::parse($filters['from'])->startOfDay()
            );
        }

        // إلى تاريخ
        if (!empty($filters['to'])) {
            $query->where(
                'created_at',
                '<=',
                Carbon::parse($filters['to'])->endOfDay()
            );
        }

        return $query
            ->latest()
            ->paginate(20)
            ->withQueryString();
    }
}
