<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private const BASE = '/api/v1/me/notifications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    // مستخدم معه صلاحية notifications.view_own (عن طريق أي دور بيحملها)
    private function allowedUser(): User
    {
        $role = Role::query()
            ->whereNull('scope_id')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'notifications.view_own'))
            ->firstOrFail();

        return $this->userWithRole($role->name);
    }

    // مستخدم بدون الصلاحية
    private function deniedUser(): User
    {
        return User::factory()->create();
    }

    private function as(User $user): static
    {
        return $this->withToken($this->loginAs($user));
    }

    private function notify(User $user, array $o = []): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\Notifications\Dummy',
            'data' => [
                'title' => 'عنوان',
                'body' => 'نص',
                'action_url' => '/x',
                'category' => $o['category'] ?? 'account',
                'type' => 'account.test',
            ],
            'read_at' => $o['read_at'] ?? null,
        ]);

        return $id;
    }

    public function test_index_lists_only_my_notifications(): void
    {
        $me = $this->allowedUser();
        $other = $this->allowedUser();
        $this->notify($me);
        $this->notify($other);

        $this->as($me)->getJson(self::BASE)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => [['id', 'category', 'type', 'title', 'body', 'action_url', 'read_at', 'created_at']]]);
    }

    public function test_index_denied_without_permission(): void
    {
        $this->as($this->deniedUser())->getJson(self::BASE)->assertForbidden();
    }

    public function test_index_unread_and_category_filters(): void
    {
        $me = $this->allowedUser();
        $this->notify($me, ['read_at' => now()]);
        $this->notify($me, ['category' => 'security']);

        $this->as($me)->getJson(self::BASE.'?unread=1')->assertOk()->assertJsonCount(1, 'data');
        $this->as($me)->getJson(self::BASE.'?category=security')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unread_count_allow_and_deny(): void
    {
        $me = $this->allowedUser();
        $this->notify($me);
        $this->notify($me);

        $this->as($me)->getJson(self::BASE.'/unread-count')
            ->assertOk()->assertJsonPath('data.count', 2);

        $this->as($this->deniedUser())->getJson(self::BASE.'/unread-count')->assertForbidden();
    }

    public function test_mark_read_allow_deny_and_other_user_404(): void
    {
        $me = $this->allowedUser();
        $other = $this->allowedUser();
        $mine = $this->notify($me);
        $theirs = $this->notify($other);

        $this->as($me)->patchJson(self::BASE."/$mine/read")
            ->assertOk()->assertJsonPath('data.id', $mine);
        $this->assertNotNull($me->notifications()->whereKey($mine)->first()->read_at);

        $this->as($me)->patchJson(self::BASE."/$theirs/read")->assertNotFound();
        $this->as($this->deniedUser())->patchJson(self::BASE."/$mine/read")->assertForbidden();
    }

    public function test_read_all_sets_count_to_zero_and_denies(): void
    {
        $me = $this->allowedUser();
        $this->notify($me);
        $this->notify($me);

        $this->as($me)->postJson(self::BASE.'/read-all')->assertNoContent();
        $this->as($me)->getJson(self::BASE.'/unread-count')->assertJsonPath('data.count', 0);$this->as($this->deniedUser())->postJson(self::BASE.'/read-all')->assertForbidden();
    }

    public function test_destroy_allow_deny_and_other_user_404(): void
    {
        $me = $this->allowedUser();
        $other = $this->allowedUser();
        $mine = $this->notify($me);
        $theirs = $this->notify($other);

        $this->as($me)->deleteJson(self::BASE."/$theirs")->assertNotFound();
        $this->as($this->deniedUser())->deleteJson(self::BASE."/$mine")->assertForbidden();

        $this->as($me)->deleteJson(self::BASE."/$mine")->assertNoContent();
        $this->assertDatabaseMissing('notifications', ['id' => $mine]);
        $this->assertDatabaseHas('notifications', ['id' => $theirs]);
    }
}
