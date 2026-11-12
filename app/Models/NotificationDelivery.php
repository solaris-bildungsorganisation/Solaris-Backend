<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * notification_deliveries — حالة كل محاولة إرسال.
 * status: queued | sent | delivered | failed | skipped_preference | skipped_sensitivity
 */
class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_uuid', 'user_id', 'notification_type_id', 'channel_id', 'template_id',
        'status', 'attempts', 'provider', 'provider_message_id', 'last_error',
        'sent_at', 'delivered_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    /** الإشعار في جدول notifications (مودل Laravel الجاهز) — ربط منطقي بدون FK */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(DatabaseNotification::class, 'notification_uuid', 'id');
    }
}
