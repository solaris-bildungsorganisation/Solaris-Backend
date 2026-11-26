<?php

namespace App\Services\Admin;

use App\Models\Language;
use App\Models\UiTranslation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TranslationManagementService
{
    private const CACHE_PREFIX = 'meta.translations.';

    public function listTranslations(array $filters = []): LengthAwarePaginator
    {
        $query = UiTranslation::query()
            ->with('language:id,code,name,direction');

        if (! empty($filters['group'])) {
            $query->where('group', $filters['group']);
        }

        if (! empty($filters['key'])) {
            $query->where('key', 'like', '%'.$filters['key'].'%');
        }

        if (! empty($filters['missing'])) {
            $languageCode = $filters['language'] ?? null;

            if (! $languageCode) {
                throw ValidationException::withMessages([
                    'language' => 'يجب تحديد اللغة عند طلب الترجمات الناقصة.',
                ]);
            }

            $arabicLanguage = Language::query()
                ->where('code', 'ar')
                ->first();

            $targetLanguage = Language::query()
                ->where('code', $languageCode)
                ->first();

            if (! $arabicLanguage || ! $targetLanguage) {
                throw ValidationException::withMessages([
                    'language' => 'اللغة المحددة غير موجودة.',
                ]);
            }

            if ($targetLanguage->code === 'ar') {
                throw ValidationException::withMessages([
                    'language' => 'لا يمكن طلب الترجمات الناقصة للغة العربية نفسها.',
                ]);
            }

            $query->where('language_id', $arabicLanguage->id)
                ->whereNotExists(function ($subQuery) use ($targetLanguage) {
                    $subQuery->selectRaw('1')
                        ->from('ui_translations as target_translations')
                        ->whereColumn(
                            'target_translations.group',
                            'ui_translations.group'
                        )
                        ->whereColumn(
                            'target_translations.key',
                            'ui_translations.key'
                        )
                        ->where(
                            'target_translations.language_id',
                            $targetLanguage->id
                        );
                });
        } elseif (! empty($filters['language'])) {
            $query->whereHas('language', function ($languageQuery) use ($filters) {
                $languageQuery->where('code', $filters['language']);
            });
        }

        return $query
            ->orderBy('group')
            ->orderBy('key')
            ->paginate(100);
    }

    public function saveTranslations(
        string $group,
        string $key,
        array $translations
    ): int {
        if (empty($translations)) {
            throw ValidationException::withMessages([
                'translations' => 'يجب إضافة ترجمة واحدة على الأقل.',
            ]);
        }

        $languageCodes = array_column($translations, 'language');

        if (count($languageCodes) !== count(array_unique($languageCodes))) {
            throw ValidationException::withMessages([
                'translations' => 'لا يمكن تكرار اللغة ضمن طلب الحفظ نفسه.',
            ]);
        }

        return DB::transaction(function () use (
            $group,
            $key,
            $translations,
            $languageCodes
        ) {
            $languages = Language::query()
                ->whereIn('code', $languageCodes)
                ->get()
                ->keyBy('code');

            $records = [];
            $oldValues = [];
            $affectedLanguages = [];

            foreach ($translations as $translation) {
                $languageCode = $translation['language'];
                $language = $languages->get($languageCode);

                if (! $language) {
                    throw ValidationException::withMessages([
                        'translations' => "اللغة {$languageCode} غير موجودة.",
                    ]);
                }

                $existingTranslation = UiTranslation::query()
                    ->where('group', $group)
                    ->where('key', $key)
                    ->where('language_id', $language->id)
                    ->first();

                $oldValues[$languageCode] = $existingTranslation?->value;

                $records[] = [
                    'uuid' => (string) Str::uuid(),
                    'group' => $group,
                    'key' => $key,
                    'language_id' => $language->id,
                    'value' => $translation['value'],
                    'updated_by' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $affectedLanguages[$languageCode] = $languageCode;
            }

            UiTranslation::upsert(
                $records,
                ['group', 'key', 'language_id'],
                ['value', 'updated_by', 'updated_at']
            );

            foreach ($affectedLanguages as $languageCode) {
                $language = $languages->get($languageCode);

                $savedTranslation = UiTranslation::query()
                    ->where('group', $group)
                    ->where('key', $key)
                    ->where('language_id', $language->id)
                    ->firstOrFail();

                activity('admin.translations')
                    ->causedBy(Auth::user())
                    ->performedOn($savedTranslation)
                    ->withProperties([
                        'old' => [
                            'value' => $oldValues[$languageCode],
                        ],
                        'new' => [
                            'value' => $savedTranslation->value,
                        ],
                        'language' => $languageCode,
                        'group' => $group,
                        'key' => $key,
                    ])
                    ->log('تم حفظ ترجمة');
            }

            foreach (array_keys($affectedLanguages) as $languageCode) {
                Cache::forget(self::CACHE_PREFIX.$languageCode);
            }

            return count($records);
        });
    }

    public function deleteTranslation(UiTranslation $translation): void
    {
        DB::transaction(function () use ($translation) {
            $translation->loadMissing('language');

            $languageCode = $translation->language->code;
            $oldValues = $translation->getAttributes();

            activity('admin.translations')
                ->causedBy(Auth::user())
                ->performedOn($translation)
                ->withProperties([
                    'old' => $oldValues,
                    'new' => [],
                    'language' => $languageCode,
                ])
                ->log('تم حذف ترجمة');

            $translation->delete();

            Cache::forget(self::CACHE_PREFIX.$languageCode);
        });
    }
}
