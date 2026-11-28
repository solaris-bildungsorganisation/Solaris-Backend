<?php
namespace App\Http\Requests\Admin\Content;
use App\Models\SuccessStory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
class UpdateSuccessStoryConsentRequest extends FormRequest{
public function authorize(): bool
{
    $story = $this->route('story');

    return $story instanceof SuccessStory && Gate::allows('recordConsent', $story);
}

public function rules(): array
{
    return [
        'consent_status' => ['required', Rule::in(['granted', 'not_required', 'withdrawn'])],
    ];
}
}
