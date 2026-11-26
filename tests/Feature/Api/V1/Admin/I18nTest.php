<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AccessScope;
use App\Models\Language;
use Database\Seeders\Reference\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class I18nTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbac();
        $this->seed(LanguageSeeder::class);
    }

    private function globalAdminToken(): string
    {
        $admin = $this->userWithRole('platform_admin');

        return $this->loginAs($admin, null, 'administration');
    }

    public function test_admin_can_list_languages(): void
    {
        $response = $this->withToken($this->globalAdminToken())
            ->getJson('/api/v1/admin/languages');

        $response->assertOk()
            ->assertJsonFragment(['code' => 'ar'])
            ->assertJsonFragment(['code' => 'en'])
            ->assertJsonFragment(['code' => 'de']);
    }

    public function test_admin_can_create_language(): void
    {
        $response = $this->withToken($this->globalAdminToken())
            ->postJson('/api/v1/admin/languages', [
                'code' => 'fr',
                'name' => [
                    'ar' => 'الفرنسية',
                    'en' => 'French',
                ],
                'native_name' => 'Français',
                'direction' => 'ltr',
                'is_interface' => true,
                'is_content' => true,
                'is_active' => true,
                'sort_order' => 4,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'fr');

        $this->assertDatabaseHas('languages', [
            'code' => 'fr',
            'native_name' => 'Français',
        ]);

        $this->assertNotEmpty(
            Language::query()->where('code', 'fr')->value('uuid')
        );
    }

    public function test_duplicate_language_code_is_rejected(): void
    {
        $response = $this->withToken($this->globalAdminToken())
            ->postJson('/api/v1/admin/languages', [
                'code' => 'en',
                'name' => [
                    'ar' => 'الإنجليزية',
                    'en' => 'English',
                ],
                'native_name' => 'English',
                'direction' => 'ltr',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_arabic_language_cannot_be_deactivated(): void
    {
        $arabic = Language::query()->where('code', 'ar')->firstOrFail();

        $response = $this->withToken($this->globalAdminToken())
            ->patchJson("/api/v1/admin/languages/{$arabic->uuid}", [
                'is_active' => false,
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('languages', [
            'id' => $arabic->id,
            'is_active' => true,
        ]);
    }

    public function test_last_active_interface_language_cannot_be_deactivated(): void
    {
        $token = $this->globalAdminToken();

        $arabic = Language::query()->where('code', 'ar')->firstOrFail();
        $english = Language::query()->where('code', 'en')->firstOrFail();
        $german = Language::query()->where('code', 'de')->firstOrFail();

        $this->withToken($token)
            ->patchJson("/api/v1/admin/languages/{$arabic->uuid}", [
                'is_interface' => false,
            ])
            ->assertOk();

        $this->withToken($token)
            ->patchJson("/api/v1/admin/languages/{$german->uuid}", [
                'is_interface' => false,
            ])
            ->assertOk();

        $response = $this->withToken($token)
            ->patchJson("/api/v1/admin/languages/{$english->uuid}", [
                'is_active' => false,
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('languages', [
            'id' => $english->id,
            'is_active' => true,
            'is_interface' => true,
        ]);
    }

    public function test_admin_can_save_translations_and_read_public_translations(): void
    {
        $token = $this->globalAdminToken();

        $saveResponse = $this->withToken($token)
            ->putJson('/api/v1/admin/translations', [
                'group' => 'auth',
                'key' => 'login',
                'translations' => [
                    [
                        'language' => 'ar',
                        'value' => 'تسجيل الدخول',
                    ],
                    [
                        'language' => 'en',
                        'value' => 'Login',
                    ],
                ],
            ]);

        $saveResponse->assertOk();

        $this->assertDatabaseHas('ui_translations', [
            'group' => 'auth',
            'key' => 'login',
            'value' => 'Login',
        ]);

        $publicResponse = $this->getJson('/api/v1/meta/translations/en');

        $publicResponse->assertOk()
            ->assertJsonPath('language', 'en')
            ->assertJsonPath('direction', 'ltr')
            ->assertJsonPath('translations.auth.login', 'Login');
    }

    public function test_translation_cache_is_invalidated_after_update(): void
    {
        $token = $this->globalAdminToken();

        $payload = [
            'group' => 'auth',
            'key' => 'login',
            'translations' => [
                [
                    'language' => 'en',
                    'value' => 'Login',
                ],
            ],
        ];

        $this->withToken($token)
            ->putJson('/api/v1/admin/translations', $payload)
            ->assertOk();

        $this->getJson('/api/v1/meta/translations/en')
            ->assertOk()
            ->assertJsonPath('translations.auth.login', 'Login');

        $payload['translations'][0]['value'] = 'Sign in';

        $this->withToken($token)
            ->putJson('/api/v1/admin/translations', $payload)
            ->assertOk();

        $this->getJson('/api/v1/meta/translations/en')
            ->assertOk()
            ->assertJsonPath('translations.auth.login', 'Sign in');
    }

    public function test_missing_translations_filter_returns_only_missing_keys(): void
    {
        $token = $this->globalAdminToken();

        $this->withToken($token)
            ->putJson('/api/v1/admin/translations', [
                'group' => 'auth',
                'key' => 'login',
                'translations' => [
                    ['language' => 'ar', 'value' => 'تسجيل الدخول'],
                    ['language' => 'en', 'value' => 'Login'],
                ],
            ])
            ->assertOk();

        $this->withToken($token)
            ->putJson('/api/v1/admin/translations', [
                'group' => 'auth',
                'key' => 'logout',
                'translations' => [
                    ['language' => 'ar', 'value' => 'تسجيل الخروج'],
                ],
            ])
            ->assertOk();

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/translations?language=en&missing=1');

        $response->assertOk()
            ->assertJsonFragment([
                'key' => 'logout',
                'value' => 'تسجيل الخروج',
            ])
            ->assertJsonMissing([
                'key' => 'login',
                'value' => 'تسجيل الدخول',
            ]);
    }

    public function test_inactive_language_is_not_available_publicly(): void
    {
        $german = Language::query()->where('code', 'de')->firstOrFail();

        $german->update(['is_active' => false]);

        $this->getJson('/api/v1/meta/translations/de')
            ->assertNotFound();
    }

    public function test_country_scoped_admin_cannot_manage_global_languages_or_translations(): void
    {
        $country = $this->makeCountry('SY');
        $scope = AccessScope::forCountry($country);

        $admin = $this->userWithRole('platform_admin', $scope);
        $token = $this->loginAs($admin, $scope, 'administration');

        $this->withToken($token)
            ->getJson('/api/v1/admin/languages')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/admin/translations')
            ->assertForbidden();
    }
}
