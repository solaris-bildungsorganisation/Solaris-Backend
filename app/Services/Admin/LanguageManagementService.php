<?php

namespace App\Services\Admin;

use App\Models\Language;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LanguageManagementService
{
    private const CACHE_PREFIX = 'meta.translations.';

    public function listLanguages(): LengthAwarePaginator
    {
        return Language::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->paginate(100);
    }

    public function createLanguage(array $data): Language
    {
        return DB::transaction(function () use ($data) {
            $language = Language::create($data);

            activity('admin.languages')
                ->causedBy(Auth::user())
                ->performedOn($language)
                ->withProperties([
                    'old' => [],
                    'new' => $language->getAttributes(),
                ])
                ->log('تمت إضافة لغة جديدة');

            Cache::forget(self::CACHE_PREFIX.$language->code);

            return $language->refresh();
        });
    }

    public function updateLanguage(
        Language $language,
        array $data
    ): Language {
        return DB::transaction(function () use ($language, $data) {
            $languages = Language::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $language = $languages->firstWhere('id', $language->id);

            if (! $language) {
                throw ValidationException::withMessages([
                    'language' => 'اللغة غير موجودة.',
                ]);
            }

            $oldValues = $language->getAttributes();

            $newIsActive = $data['is_active'] ?? $language->is_active;
            $newIsInterface = $data['is_interface'] ?? $language->is_interface;

            if ($language->code === 'ar' && ! $newIsActive) {
                throw ValidationException::withMessages([
                    'is_active' => 'لا يمكن تعطيل اللغة العربية.',
                ]);
            }

            $removesActiveInterface =
                $language->is_interface
                && $language->is_active
                && (! $newIsInterface || ! $newIsActive);

            if ($removesActiveInterface) {
                $activeInterfaceCount = $languages
                    ->where('is_interface', true)
                    ->where('is_active', true)
                    ->count();

                if ($activeInterfaceCount <= 1) {
                    throw ValidationException::withMessages([
                        'is_active' => 'يجب أن تبقى لغة واجهة واحدة نشطة على الأقل.',
                    ]);
                }
            }

            $language->fill($data);
            $language->save();

            activity('admin.languages')
                ->causedBy(Auth::user())
                ->performedOn($language)
                ->withProperties([
                    'old' => $oldValues,
                    'new' => $language->getAttributes(),
                ])
                ->log('تم تعديل بيانات لغة');

            Cache::forget(self::CACHE_PREFIX.$language->code);

            return $language->refresh();
        });
    }
}
