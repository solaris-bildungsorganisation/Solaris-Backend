<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'array'],
            'description' => ['sometimes', 'nullable', 'array'],
            'default_channels' => ['sometimes', 'array'],
            'default_channels.*' => ['string', 'exists:notification_channels,key'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($validator->errors()->keys() as $errorKey) {
                if ($errorKey === 'default_channels' || str_starts_with($errorKey, 'default_channels.')) {
                    return;
                }
            }

            if (! is_array($this->input('default_channels'))) {
                return;
            }

            $routeType = $this->route('type');
            $type = $routeType instanceof NotificationType
                ? $routeType
                : (is_numeric($routeType)
                    ? NotificationType::query()->find($routeType)
                    : NotificationType::query()->where('key', $routeType)->first());

            if ($type?->sensitivity !== 'sensitive') {
                return;
            }

            $hasUnsupportedChannel = NotificationChannel::query()
                ->whereIn('key', $this->input('default_channels'))
                ->where('supports_sensitive', false)
                ->exists();

            if ($hasUnsupportedChannel) {
                $validator->errors()->add(
                    'default_channels',
                    __('A selected notification channel does not support sensitive notifications.')
                );
            }
        });
    }
}
