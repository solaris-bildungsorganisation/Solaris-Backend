<?php

namespace App\Http\Requests\Admin\Content;
use App\Models\SuccessStory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateSuccessStoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
      $story = $this->route('story');

        return $story instanceof SuccessStory
            && Gate::allows('update', $story);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'array:ar,en,de'],
            'title.ar' => ['required_with:title', 'string', 'max:255'],
            'title.en' => ['required_with:title', 'string', 'max:255'],
            'title.de' => ['required_with:title', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'array:ar,en,de'],
            'summary.ar' => ['nullable', 'string', 'max:2000'],
            'summary.en' => ['nullable', 'string', 'max:2000'],
            'summary.de' => ['nullable', 'string', 'max:2000'],
            'body' => ['sometimes', 'required', 'array:ar,en,de'],
            'body.ar' => ['required_with:body', 'string', 'min:20'],
            'body.en' => ['required_with:body', 'string', 'min:20'],
            'body.de' => ['required_with:body', 'string', 'min:20'],
            'subject_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'organization_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('organizations', 'id'),
            ],
            'country_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('countries', 'id'),
            ],
            'region_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('regions', 'id'),
            ],
            'status' => ['missing'],
            'published_at' => ['missing'],
            'reviewed_by' => ['missing'],
            'created_by' => ['missing'],
            'consent_recorded_at' => ['missing'],
            'consent_recorded_by' => ['missing'],
            'consent_status' => ['missing'],
        ];
    }
}
