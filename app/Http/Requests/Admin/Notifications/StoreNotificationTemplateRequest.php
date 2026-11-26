<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notification_type_id' => ['required', 'integer', 'exists:notification_types,id'],
            'channel_id' => ['required', 'integer', 'exists:notification_channels,id'],
            'language_id' => ['required', 'integer', 'exists:languages,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                $validator->errors()->has('notification_type_id')
                || $validator->errors()->has('channel_id')
            ) {
                return;
            }

            $type = NotificationType::query()->find($this->input('notification_type_id'));
            $channel = NotificationChannel::query()->find($this->input('channel_id'));

            if (
                $type?->sensitivity === 'sensitive'
                && $channel !== null
                && ! $channel->supports_sensitive
            ) {
                $validator->errors()->add(
                    'channel_id',
                    __('This notification channel does not support sensitive notifications.')
                );
            }
        });
    }
}
