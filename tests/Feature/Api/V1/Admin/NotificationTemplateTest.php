<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AccessScope;
use App\Models\Context;
use App\Models\Language;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use Database\Seeders\Notifications\NotificationCatalogSeeder;
use Database\Seeders\Rbac\ContextSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class NotificationTemplateTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithRbac;

    private Language $arabic;

    private Language $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ContextSeeder::class);
        $this->seedRbac();
        $contextId = Context::query()->where('key', 'administration')->value('id');
        $this->assertNotNull($contextId);
        DB::table('roles')
            ->where('name', 'platform_admin')
            ->whereNull('scope_id')
            ->update(['context_id' => $contextId]);
        $this->seed(NotificationCatalogSeeder::class);

        $this->arabic = Language::query()->firstOrCreate(
            ['code' => 'ar'],
            [
                'name' => ['ar' => 'العربية', 'en' => 'Arabic'],
                'native_name' => 'العربية',
                'direction' => 'rtl',
            ]
        );

        $this->english = Language::query()->firstOrCreate(
            ['code' => 'en'],
            [
                'name' => ['ar' => 'الإنجليزية', 'en' => 'English'],
                'native_name' => 'English',
                'direction' => 'ltr',
            ]
        );
    }

    public function test_notification_types_include_active_template_counts(): void
    {
        [$admin, $token] = $this->adminWithToken();
        $type = $this->makeNotificationType();
        $channel = NotificationChannel::query()->where('key', 'in_app')->firstOrFail();

        $this->makeTemplate($type, $channel, $this->arabic, true, 1, $admin);
        $this->makeTemplate($type, $channel, $this->arabic, true, 2, $admin);
        $this->makeTemplate($type, $channel, $this->arabic, false, 3, $admin);

        $response = $this->withToken($token)->getJson('/api/v1/admin/notification-types');

        $response->assertOk();
        $typeData = collect($response->json('data'))->firstWhere('id', $type->id);

        $this->assertNotNull($typeData);
        $this->assertSame(2, $typeData['active_templates_count']);
    }

    public function test_updating_type_ignores_protected_fields_and_writes_an_admin_audit_log(): void
    {
        [$admin, $token] = $this->adminWithToken();
        $type = NotificationType::query()->where('key', 'security.new_login')->firstOrFail();
        $original = $type->only(['key', 'category', 'sensitivity', 'is_mandatory']);
        $originalName = $type->getTranslations('name');

        $response = $this->withToken($token)->patchJson(
            "/api/v1/admin/notification-types/{$type->id}",
            [
                'name' => ['ar' => 'تسجيل دخول جديد معدل', 'en' => 'Updated sign-in'],
                'default_channels' => ['in_app'],
                'is_active' => false,
                'key' => 'changed.key',
                'category' => 'changed',
                'sensitivity' => 'sensitive',
                'is_mandatory' => false,
            ]
        );

        $response->assertOk()
            ->assertJsonPath('data.name.en', 'Updated sign-in')
            ->assertJsonPath('data.default_channels.0', 'in_app')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.key', $original['key'])
            ->assertJsonPath('data.category', $original['category'])
            ->assertJsonPath('data.sensitivity', $original['sensitivity'])
            ->assertJsonPath('data.is_mandatory', $original['is_mandatory']);

        $this->assertDatabaseHas('notification_types', [
            'id' => $type->id,
            'key' => $original['key'],
            'category' => $original['category'],
            'sensitivity' => $original['sensitivity'],
            'is_mandatory' => $original['is_mandatory'],
        ]);

        $properties = $this->assertAdminActivity($admin, NotificationType::class, $type->id);
        $this->assertSame($originalName, $properties['old']['name']);
        $this->assertSame(['ar' => 'تسجيل دخول جديد معدل', 'en' => 'Updated sign-in'], $properties['new']['name']);
        $this->assertSame(['in_app'], $properties['new']['default_channels']);
        $this->assertSame(false, $properties['new']['is_active']);
        $this->assertArrayNotHasKey('key', $properties['new']);
    }

    public function test_sensitive_notifications_reject_unsupported_channels_and_accept_in_app(): void
    {
        [, $token] = $this->adminWithToken();
        $type = NotificationType::query()->where('key', 'finance.transaction_status')->firstOrFail();
        $email = NotificationChannel::query()->where('key', 'email')->firstOrFail();
        $inApp = NotificationChannel::query()->where('key', 'in_app')->firstOrFail();
        $this->assertSame('sensitive', $type->sensitivity);
        $this->assertFalse($email->supports_sensitive);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/notification-types/{$type->id}", ['default_channels' => ['email']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_channels');

        $this->withToken($token)
            ->patchJson("/api/v1/admin/notification-types/{$type->id}", ['default_channels' => ['in_app']])
            ->assertOk()
            ->assertJsonPath('data.default_channels', ['in_app']);

        $templateData = [
            'notification_type_id' => $type->id,
            'language_id' => $this->arabic->id,
            'body' => 'إشعار حساس',
        ];

        $this->withToken($token)
            ->postJson('/api/v1/admin/notification-templates', [...$templateData, 'channel_id' => $email->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('channel_id');

        $this->withToken($token)
            ->postJson('/api/v1/admin/notification-templates', [...$templateData, 'channel_id' => $inApp->id])
            ->assertCreated();
    }

    public function test_template_listing_filters_by_type_channel_language_and_active_state(): void
    {
        [$admin, $token] = $this->adminWithToken();
        $typeA = $this->makeNotificationType();
        $typeB = $this->makeNotificationType();
        $typeC = $this->makeNotificationType();
        $email = $this->makeNotificationChannel('email');
        $inApp = $this->makeNotificationChannel('in_app');
        $arabic = $this->makeLanguage('ar');
        $english = $this->makeLanguage('en');

        $templates = [
            $this->makeTemplate($typeA, $email, $arabic, true, 1, $admin),
            $this->makeTemplate($typeA, $inApp, $english, false, 1, $admin),
            $this->makeTemplate($typeB, $email, $english, false, 1, $admin),
            $this->makeTemplate($typeC, $inApp, $arabic, true, 1, $admin),
        ];

        $this->assertTemplateIds(
            $this->withToken($token)->getJson('/api/v1/admin/notification-templates?'.http_build_query(['type' => $typeA->id])),
            [$templates[0]->id, $templates[1]->id]
        );
        $this->assertTemplateIds(
            $this->withToken($token)->getJson('/api/v1/admin/notification-templates?'.http_build_query(['type' => $typeB->id])),
            [$templates[2]->id]
        );
        $this->assertTemplateIds(
            $this->withToken($token)->getJson('/api/v1/admin/notification-templates?'.http_build_query(['channel' => $email->id])),
            [$templates[0]->id, $templates[2]->id]
        );
        $this->assertTemplateIds(
            $this->withToken($token)->getJson('/api/v1/admin/notification-templates?'.http_build_query(['channel' => $inApp->id])),
            [$templates[1]->id, $templates[3]->id]
        );
        $this->assertTemplateIds(
            $this->withToken($token)->getJson('/api/v1/admin/notification-templates?'.http_build_query(['language' => $arabic->id])),
            [$templates[0]->id, $templates[3]->id]
        );
        $this->assertTemplateIds(
            $this->withToken($token)->getJson('/api/v1/admin/notification-templates?'.http_build_query(['language' => $english->id])),
            [$templates[1]->id, $templates[2]->id]
        );
        $activeIds = array_map(
            'intval',
            array_column(
                $this->withToken($token)
                    ->getJson('/api/v1/admin/notification-templates?'.http_build_query(['active' => '1']))
                    ->assertOk()
                    ->json('data'),
                'id'
            )
        );
        $this->assertContains($templates[0]->id, $activeIds);
        $this->assertContains($templates[3]->id, $activeIds);
        $this->assertNotContains($templates[1]->id, $activeIds);
        $this->assertNotContains($templates[2]->id, $activeIds);

        $inactiveIds = array_map(
            'intval',
            array_column(
                $this->withToken($token)
                    ->getJson('/api/v1/admin/notification-templates?'.http_build_query(['active' => '0']))
                    ->assertOk()
                    ->json('data'),
                'id'
            )
        );
        $this->assertContains($templates[1]->id, $inactiveIds);
        $this->assertContains($templates[2]->id, $inactiveIds);
        $this->assertNotContains($templates[0]->id, $inactiveIds);
        $this->assertNotContains($templates[3]->id, $inactiveIds);
    }

    public function test_creating_a_template_creates_sequential_versions_and_hides_internal_user_id(): void
    {
        [$admin, $token] = $this->adminWithToken();
        $type = $this->makeNotificationType();
        $channel = NotificationChannel::query()->where('key', 'in_app')->firstOrFail();

        $data = [
            'notification_type_id' => $type->id,
            'channel_id' => $channel->id,
            'language_id' => $this->arabic->id,
            'subject' => 'نتيجة جديدة',
            'body' => 'لديك نتيجة جديدة',
        ];

        $firstResponse = $this->withToken($token)->postJson('/api/v1/admin/notification-templates', $data);
        $firstResponse->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.updater.uuid', $admin->uuid)
            ->assertJsonMissingPath('data.updater.id')
            ->assertJsonMissingPath('data.updated_by');
        $first = NotificationTemplate::query()->findOrFail($firstResponse->json('data.id'));

        $secondResponse = $this->withToken($token)->postJson('/api/v1/admin/notification-templates', $data);
        $secondResponse->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.updater.uuid', $admin->uuid)
            ->assertJsonMissingPath('data.updater.id')
            ->assertJsonMissingPath('data.updated_by');
        $second = NotificationTemplate::query()->findOrFail($secondResponse->json('data.id'));

        $this->assertDatabaseHas('notification_templates', [
            'id' => $first->id,
            'notification_type_id' => $type->id,
            'channel_id' => $channel->id,
            'language_id' => $this->arabic->id,
            'version' => 1,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('notification_templates', [
            'id' => $second->id,
            'notification_type_id' => $type->id,
            'channel_id' => $channel->id,
            'language_id' => $this->arabic->id,
            'version' => 2,
            'is_active' => true,
        ]);
        $this->assertFalse($first->fresh()->is_active);
        $this->assertSame(2, $second->version);
        $this->assertTrue($second->is_active);

        $firstLog = $this->assertAdminActivity($admin, NotificationTemplate::class, $first->id);
        $this->assertSame([], $firstLog['old']['deactivated_templates']);
        $this->assertSame($first->id, $firstLog['new']['id']);
        $this->assertSame(1, $firstLog['new']['version']);
        $this->assertTrue($firstLog['new']['is_active']);

        $secondLog = $this->assertAdminActivity($admin, NotificationTemplate::class, $second->id);
        $this->assertSame([[
            'id' => $first->id,
            'version' => 1,
            'is_active' => true,
        ]], $secondLog['old']['deactivated_templates']);
        $this->assertSame($second->id, $secondLog['new']['id']);
        $this->assertSame(2, $secondLog['new']['version']);
        $this->assertTrue($secondLog['new']['is_active']);
        $this->assertSame(
            2,
            DB::table('activity_log')
                ->where('log_name', 'admin')
                ->where('subject_type', NotificationTemplate::class)
                ->where('causer_id', $admin->id)
                ->count()
        );
    }

    public function test_template_status_update_does_not_change_its_body(): void
    {
        [$admin, $token] = $this->adminWithToken();
        $template = $this->makeTemplate(
            $this->makeNotificationType(),
            NotificationChannel::query()->where('key', 'in_app')->firstOrFail(),
            $this->arabic,
            true,
            1,
            $admin,
            'Original template body'
        );

        $response = $this->withToken($token)->patchJson(
            "/api/v1/admin/notification-templates/{$template->id}",
            ['is_active' => false, 'body' => 'Attempted replacement body']
        );

        $response->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.body', 'Original template body');
        $this->assertDatabaseHas('notification_templates', [
            'id' => $template->id,
            'is_active' => false,
            'body' => 'Original template body',
        ]);
        $properties = $this->assertAdminActivity($admin, NotificationTemplate::class, $template->id);
        $this->assertSame(['is_active' => true], $properties['old']);
        $this->assertSame(['is_active' => false], $properties['new']);
    }

    public function test_template_preview_replaces_supplied_values_and_leaves_missing_values_blank(): void
    {
        [, $token] = $this->adminWithToken();
        $template = $this->makeTemplate(
            $this->makeNotificationType(),
            NotificationChannel::query()->where('key', 'in_app')->firstOrFail(),
            $this->arabic,
            true,
            1,
            null,
            'Code {{ data.code }} {{ absent }}',
            'Hello {{ name }} {{ missing }}'
        );

        $this->withToken($token)
            ->postJson(
                "/api/v1/admin/notification-templates/{$template->id}/preview",
                ['data' => ['name' => 'Firas', 'data' => ['code' => 'ABC']]]
            )
            ->assertOk()
            ->assertJsonPath('data.subject', 'Hello Firas ')
            ->assertJsonPath('data.body', 'Code ABC ');

        $this->withToken($token)
            ->postJson("/api/v1/admin/notification-templates/{$template->id}/preview", ['data' => null])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Hello  ')
            ->assertJsonPath('data.body', 'Code  ');
    }

    public function test_country_scoped_and_unprivileged_users_are_forbidden_from_all_six_routes(): void
    {
        $type = $this->makeNotificationType();
        $channel = NotificationChannel::query()->where('key', 'in_app')->firstOrFail();
        $template = $this->makeTemplate($type, $channel, $this->arabic);

        $country = $this->makeCountry(Str::upper(Str::random(2)));
        $countryScope = AccessScope::forCountry($country);
        $countryAdmin = $this->userWithRole('platform_admin', $countryScope, ['country_id' => $country->id]);
        $countryToken = $this->loginAs($countryAdmin, $countryScope, 'administration');

        $this->assertAllManagementRoutesForbidden($countryToken, $type, $template);

        $unprivilegedUser = User::factory()->create();
        $unprivilegedToken = $this->loginAs($unprivilegedUser);

        $this->assertAllManagementRoutesForbidden($unprivilegedToken, $type, $template);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function adminWithToken(): array
    {
        $admin = $this->userWithRole('super_admin');

        return [$admin, $this->loginAs($admin, $this->globalScope(), 'administration')];
    }

    private function makeTemplate(
        NotificationType $type,
        NotificationChannel $channel,
        Language $language,
        bool $isActive = true,
        int $version = 1,
        ?User $updater = null,
        string $body = 'Template {{ name }}',
        ?string $subject = null
    ): NotificationTemplate {
        $identity = [
            'notification_type_id' => $type->id,
            'channel_id' => $channel->id,
            'language_id' => $language->id,
        ];

        if (NotificationTemplate::query()->where($identity)->where('version', $version)->exists()) {
            $version = ((int) NotificationTemplate::query()->where($identity)->max('version')) + 1;
        }

        return NotificationTemplate::factory()->create([
            ...$identity,
            'version' => $version,
            'is_active' => $isActive,
            'updated_by' => $updater?->id,
            'body' => $body,
            'subject' => $subject,
        ]);
    }

    private function makeNotificationType(): NotificationType
    {
        return NotificationType::query()->create([
            'key' => 'test.notification.'.Str::uuid(),
            'category' => 'test',
            'name' => ['ar' => 'إشعار اختباري', 'en' => 'Test notification'],
            'sensitivity' => 'personal',
            'is_mandatory' => false,
            'default_channels' => ['in_app'],
            'is_active' => true,
        ]);
    }

    private function makeNotificationChannel(string $key): NotificationChannel
    {
        return NotificationChannel::query()->create([
            'key' => 'test_'.$key.'_'.Str::lower(Str::random(8)),
            'name' => ['ar' => $key, 'en' => $key],
            'driver' => $key,
            'supports_sensitive' => true,
            'is_active' => true,
        ]);
    }

    private function makeLanguage(string $code): Language
    {
        return Language::query()->create([
            'code' => Str::lower(Str::random(5)).'-'.$code,
            'name' => ['ar' => $code, 'en' => $code],
            'native_name' => $code,
            'direction' => $code === 'ar' ? 'rtl' : 'ltr',
            'is_active' => true,
        ]);
    }

    private function assertTemplateIds(TestResponse $response, array $expectedIds): void
    {
        $response->assertOk();
        $actualIds = array_map('intval', array_column($response->json('data'), 'id'));
        $expectedIds = array_map('intval', $expectedIds);
        sort($actualIds);
        sort($expectedIds);

        $this->assertSame($expectedIds, $actualIds);
    }

    private function assertAdminActivity(User $actor, string $subjectType, int|string $subjectId): array
    {
        $activity = [
            'log_name' => 'admin',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'causer_id' => $actor->id,
        ];
        $this->assertDatabaseHas('activity_log', $activity);

        $properties = DB::table('activity_log')->where($activity)->value('properties');
        $this->assertNotNull($properties);

        return json_decode($properties, true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertAllManagementRoutesForbidden(
        string $token,
        NotificationType $type,
        NotificationTemplate $template
    ): void {
        $templateData = [
            'notification_type_id' => $type->id,
            'channel_id' => $template->channel_id,
            'language_id' => $template->language_id,
            'body' => 'Unauthorized template',
        ];

        $this->withToken($token)->getJson('/api/v1/admin/notification-types')->assertForbidden();
        $this->withToken($token)
            ->patchJson("/api/v1/admin/notification-types/{$type->id}", ['is_active' => false])
            ->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/notification-templates')->assertForbidden();
        $this->withToken($token)
            ->postJson('/api/v1/admin/notification-templates', $templateData)
            ->assertForbidden();
        $this->withToken($token)
            ->patchJson("/api/v1/admin/notification-templates/{$template->id}", ['is_active' => false])
            ->assertForbidden();
        $this->withToken($token)
            ->postJson("/api/v1/admin/notification-templates/{$template->id}/preview", ['data' => []])
            ->assertForbidden();
    }
}
