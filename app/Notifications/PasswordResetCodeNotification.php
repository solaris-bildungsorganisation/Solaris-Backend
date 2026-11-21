<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCodeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('رمز استعادة كلمة المرور')
            ->line('استخدم رمز التحقق التالي لاستعادة كلمة المرور:')
            ->line($this->code)
            ->line('صلاحية الرمز 10 دقائق.');
    }
}
