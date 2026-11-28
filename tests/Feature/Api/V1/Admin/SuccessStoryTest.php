<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AccessScope;
use App\Models\Role;
use App\Models\SuccessStory;
use App\Models\User;
use Database\Seeders\Rbac\ContextSeeder;
use Database\Seeders\Rbac\PermissionSeeder;
use Database\Seeders\Rbac\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * اختبارات Feature لمهمة ADMIN-05 — قصص النجاح.
 *
 * مهم: شغّل الاختبارات على قاعدة MySQL مخصصة للاختبار؛ بعض migrations
 * تستخدم أوامر ALTER TABLE خاصة بـ MySQL ولا تتوافق مع SQLite.
 * لا توجّه الاختبارات إلى قاعدة التطوير أو الإنتاج.
 */
class SuccessStoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            ContextSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();

        parent::tearDown();
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/success-stories')
            ->assertUnauthorized();
    }

    public function test_public_index_only_returns_published_stories_with_a_publish_timestamp(): void
    {
        $creator = $this->makeUser('public-index-creator');

        $older = $this->makeStory($creator, [
            'status' => 'published',
            'consent_status' => 'granted',
            'published_at' => now()->subDay(),
        ]);

        $newer = $this->makeStory($creator, [
            'status' => 'published',
            'consent_status' => 'granted',
            'published_at' => now(),
        ]);

        $this->makeStory($creator, [
            'status' => 'draft',
            'consent_status' => 'pending',
            'published_at' => null,
        ]);

        $this->makeStory($creator, [
            'status' => 'published',
            'consent_status' => 'granted',
            'published_at' => null,
        ]);

        $response = $this->getJson('/api/v1/success-stories?per_page=100');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.uuid', $newer->uuid)
            ->assertJsonPath('data.1.uuid', $older->uuid);

        $firstItem = $response->json('data.0');

        $this->assertArrayNotHasKey('status', $firstItem);
        $this->assertArrayNotHasKey('created_by', $firstItem);
        $this->assertArrayNotHasKey('consent_recorded_by', $firstItem);
    }

    public function test_public_pagination_is_clamped_to_the_supported_range(): void
    {
        $creator = $this->makeUser('pagination-creator');

        foreach (range(1, 3) as $index) {
            $this->makeStory($creator, [
                'status' => 'published',
                'consent_status' => 'granted',
                'published_at' => now()->addSeconds($index),
            ]);
        }

        $this->getJson('/api/v1/success-stories?per_page=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);

        $this->getJson('/api/v1/success-stories?per_page=101')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_public_show_returns_a_published_story_but_hides_unpublished_or_unknown_stories(): void
    {
        $creator = $this->makeUser('public-show-creator');

        $published = $this->makeStory($creator, [
            'status' => 'published',
            'consent_status' => 'granted',
            'published_at' => now(),
        ]);

        $draft = $this->makeStory($creator, [
            'status' => 'draft',
            'consent_status' => 'pending',
            'published_at' => null,
        ]);

        $response = $this->getJson('/api/v1/success-stories/'.$published->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $published->uuid);

        $publicData = $response->json('data');

        $this->assertArrayNotHasKey('status', $publicData);
        $this->assertArrayNotHasKey('created_by', $publicData);
        $this->assertArrayNotHasKey('subject_user_id', $publicData);
        $this->assertArrayNotHasKey('consent_status', $publicData);

        $this->getJson('/api/v1/success-stories/'.$draft->uuid)
            ->assertNotFound();

        $this->getJson('/api/v1/success-stories/'.Str::uuid())
            ->assertNotFound();
    }

    public function test_success_story_can_be_created_reviewed_published_and_hidden_after_consent_withdrawal(): void
    {
        $creator = $this->makePlatformAdmin('story-creator');
        $reviewer = $this->makePlatformAdmin('story-reviewer');

        $payload = [
            'title' => [
                'ar' => 'قصة نجاح تجريبية للاختبار',
                'en' => 'A test success story',
                'de' => 'Eine Erfolgsgeschichte zum Testen',
            ],
            'summary' => [
                'ar' => 'ملخص تجريبي',
                'en' => 'Test summary',
                'de' => 'Testzusammenfassung',
            ],
            'body' => [
                'ar' => 'هذه قصة نجاح تجريبية طويلة بما يكفي لاجتياز قواعد التحقق.',
                'en' => 'This is a sufficiently long success story body for feature testing.',
                'de' => 'Dies ist ein ausreichend langer Erfolgsgeschichten-Text zum Testen.',
            ],
        ];

        $created = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/success-stories', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.consent_status', 'pending');

        $uuid = $created->json('data.uuid');
        $this->assertNotEmpty($uuid);

        $this->postJson("/api/v1/admin/success-stories/{$uuid}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        $this->patchJson("/api/v1/admin/success-stories/{$uuid}/consent", [
            'consent_status' => 'granted',
        ])
            ->assertOk()
            ->assertJsonPath('data.consent_status', 'granted');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/admin/success-stories/{$uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.reviewed_by', $reviewer->getKey());

        $this->assertNotNull(
            SuccessStory::query()->where('uuid', $uuid)->value('published_at')
        );

        $publicResponse = $this->getJson("/api/v1/success-stories/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $uuid);

        $publicData = $publicResponse->json('data');

        $this->assertArrayNotHasKey('status', $publicData);
        $this->assertArrayNotHasKey('created_by', $publicData);
        $this->assertArrayNotHasKey('reviewed_by', $publicData);
        $this->assertArrayNotHasKey('consent_status', $publicData);

        $this->actingAs($creator, 'sanctum')
            ->patchJson("/api/v1/admin/success-stories/{$uuid}/consent", [
                'consent_status' => 'withdrawn',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.consent_status', 'withdrawn');

        $this->getJson("/api/v1/success-stories/{$uuid}")
            ->assertNotFound();
    }

    public function test_story_creator_cannot_publish_their_own_story(): void
    {
        $creator = $this->makePlatformAdmin('self-publish-creator');

        $payload = [
            'title' => [
                'ar' => 'قصة اختبار',
                'en' => 'Test story',
                'de' => 'Testgeschichte',
            ],
            'body' => [
                'ar' => 'هذه قصة اختبار طويلة بما يكفي لاجتياز التحقق المطلوب.',
                'en' => 'This is a sufficiently long test story body for validation.',
                'de' => 'Dies ist ein ausreichend langer Testtext für die Validierung.',
            ],
        ];

        $created = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/success-stories', $payload)
            ->assertCreated();

        $uuid = $created->json('data.uuid');

        $this->postJson("/api/v1/admin/success-stories/{$uuid}/submit")
            ->assertOk();

        $this->patchJson("/api/v1/admin/success-stories/{$uuid}/consent", [
            'consent_status' => 'granted',
        ])->assertOk();

        $this->postJson("/api/v1/admin/success-stories/{$uuid}/publish")
            ->assertForbidden();

        $this->assertSame(
            'in_review',
            SuccessStory::query()->where('uuid', $uuid)->value('status')
        );
    }

    private function makePlatformAdmin(string $emailPrefix): User
    {
        $user = $this->makeUser($emailPrefix);
        $registrar = app(PermissionRegistrar::class);

        // الأدوار المعرّفة في RoleSeeder عامة، لذلك نبحث عنها مع scope_id = null.
        $registrar->setPermissionsTeamId(null);

        $role = Role::query()
            ->where('name', 'platform_admin')
            ->where('guard_name', 'web')
            ->whereNull('scope_id')
            ->firstOrFail();

        $globalScopeId = AccessScope::query()
            ->where('type', AccessScope::TYPE_GLOBAL)
            ->value('id');

        $this->assertNotNull(
            $globalScopeId,
            'يجب أن يكون نطاق global قد أُنشئ بواسطة الـMigration.'
        );

        // إسناد الدور الإداري ضمن النطاق العام.
        $registrar->setPermissionsTeamId((int) $globalScopeId);
        $user->assignRole($role);

        $registrar->forgetCachedPermissions();

        return $user;
    }

    private function makeUser(string $emailPrefix): User
    {
        $user = new User();

        $user->forceFill([
            'first_name' => 'ADMIN05',
            'last_name' => 'Test User',
            'display_name' => 'ADMIN-05 Test User',
            'email' => $emailPrefix.'-'.Str::uuid().'@example.test',
            'password' => Hash::make('Password-for-tests-123!'),
            'status' => 'active',
        ]);

        $user->save();

        return $user;
    }

    /**
     * إنشاء سجل اختبار مباشرة لتغطية مسارات العرض العام.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeStory(User $creator, array $overrides = []): SuccessStory
    {
        $story = new SuccessStory();

        $story->forceFill(array_merge([
            'title' => [
                'ar' => 'قصة نجاح منشورة للاختبار',
                'en' => 'Published test success story',
                'de' => 'Veröffentlichte Test-Erfolgsgeschichte',
            ],
            'summary' => [
                'ar' => 'ملخص عام',
                'en' => 'Public summary',
                'de' => 'Öffentliche Zusammenfassung',
            ],
            'body' => [
                'ar' => 'نص قصة نجاح تجريبية منشورة للاختبارات.',
                'en' => 'Published success story body for feature tests.',
                'de' => 'Veröffentlichter Erfolgsgeschichten-Text für Tests.',
            ],
            'subject_user_id' => null,
            'organization_id' => null,
            'country_id' => null,
            'region_id' => null,
            'consent_status' => 'granted',
            'consent_recorded_at' => now(),
            'consent_recorded_by' => $creator->getKey(),
            'status' => 'published',
            'published_at' => now(),
            'created_by' => $creator->getKey(),
            'reviewed_by' => null,
        ], $overrides));

        $story->save();

        return $story;
    }
}
