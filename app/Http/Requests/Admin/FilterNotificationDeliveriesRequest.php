<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterNotificationDeliveriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user' => [
                'sometimes',
                'integer',
                'exists:users,id',
            ],

            'status' => [
                'sometimes',
                'string',
                Rule::in([
                    'queued',
                    'sent',
                    'delivered',
                    'failed',
                    'skipped_preference',
                    'skipped_sensitivity',
                ]),
            ],

            'channel' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'type' => [
                'sometimes',
                'string',
                'max:80',
            ],

            'from' => [
                'sometimes',
                'date',
            ],

            'to' => [
                'sometimes',
                'date',
                'after_or_equal:from',
            ],
        ];
    }
}
