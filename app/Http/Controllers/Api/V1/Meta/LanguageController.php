<?php

namespace App\Http\Controllers\Api\V1\Meta;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class LanguageController extends Controller
{
    #[OA\Get(
    path: '/v1/meta/translations/{lang}',
    tags: ['Meta - I18N'],
    summary: 'جلب ترجمات واجهة المستخدم للغة',
    parameters: [
        new OA\Parameter(
            name: 'lang',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', example: 'ar')
        )
    ],
    responses: [
        new OA\Response(response: 200, description: 'الترجمات واتجاه الكتابة'),
        new OA\Response(response: 404, description: 'اللغة غير موجودة أو غير مفعّلة'),
    ]
)]
    public function show(string $lang)
    {
        $language = Language::query()
            ->where('code', $lang)
            ->where('is_active', true)
            ->first();
        if (! $language) {
            abort(404, 'اللغة غير موجودة أو غير مفعّلة.');
        }

        $translations = Cache::remember(
            'meta.translations.'.$language->code,
            now()->addHour(),
            function () use ($language) {
                $items = $language->uiTranslations()
                    ->orderBy('group')
                    ->orderBy('key')
                    ->get(['group', 'key', 'value']);

                $result = [];

                foreach ($items as $item) {
                    $result[$item->group][$item->key] = $item->value;
                }

                return $result;
            }
        );

        return response()->json([
            'language' => $language->code,
            'direction' => $language->direction,
            'translations' => $translations,
        ]);
    }
}
