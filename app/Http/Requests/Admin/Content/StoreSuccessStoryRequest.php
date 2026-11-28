<?php

namespace App\Http\Requests\Admin\Content;
use App\Models\SuccessStory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSuccessStoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
       return Gate::allows('create', SuccessStory::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
          return [
        'title' => ['required', 'array:ar,en,de'],
        'title.ar' => ['required', 'string', 'max:255'],
        'title.en' => ['required', 'string', 'max:255'],
        'title.de' => ['required', 'string', 'max:255'],
        'summary' => ['nullable', 'array:ar,en,de'],
        'summary.ar' => ['nullable', 'string', 'max:2000'],
        'summary.en' => ['nullable', 'string', 'max:2000'],
        'summary.de' => ['nullable', 'string', 'max:2000'],
        'body' => ['required', 'array:ar,en,de'],
        'body.ar' => ['required', 'string', 'min:20'],
        'body.en' => ['required', 'string', 'min:20'],
        'body.de' => ['required', 'string', 'min:20'],
        'subject_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
        'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
        'region_id' => ['nullable', 'integer', Rule::exists('regions', 'id')],
    ];
    }
}
