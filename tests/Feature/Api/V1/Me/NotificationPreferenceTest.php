<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private const URL = '/api/v1/me/notification-preferences';
private NotificationChannel $email;
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->email = NotificationChannel::query()->create([
            'key' => 'email', 'name' => ['ar' => 'بريد', 'en' => 'Email'],
            'driver' => 'mail', 'is_active' => true, 'sort_order' => 1,
        ]);

        NotificationType::query()->create([
            'key' => 'enrollment.accepted', 'category' => 'enrollment',
            'name' => ['ar' => 'x', 'en' => 'x'], 'is_mandatory' => false,
        ]);
        NotificationType::query()->create([
            'key' => 'security.new_login', 'category' => 'security',
            'name' => ['ar' => 'x', 'en' => 'x'], 'is_mandatory' => true,
        ]);
    }

    private function allowedUser(): User
    {
        $role = Role::query()
            ->whereNull('scope_id')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'notifications.preferences_own'))
            ->firstOrFail();

        return $this->userWithRole($role->name);
    }

    private function as(User $user): static
    {
        return $this->withToken($this->loginAs($user));
    }

    public function test_index_returns_matrix_with_locked_flag(): void
    {
        $res = $this->as($this->allowedUser())->getJson(self::URL)->assertOk();

        $rows = collect($res->json('data'))->keyBy('category');
        $this->assertFalse($rows['enrollment']['locked']);
        $this->assertTrue($rows['security']['locked']);
    }

    public function test_index_denied_without_permission(): void
    {
        $this->as(User::factory()->create())->getJson(self::URL)->assertForbidden();
    }

    public function test_disabling_locked_category_is_rejected(): void
    {
        $this->as($this->allowedUser())->putJson(self::URL, [
            'preferences' => [['category' => 'security', 'channel' => 'email', 'is_enabled' => false]],
        ])->assertStatus(422);
    }

    public function test_disabling_normal_category_is_saved(): void
    {
        $user = $this->allowedUser();

        $this->as($user)->putJson(self::URL, [
            'preferences' => [['category' => 'enrollment', 'channel' => 'email', 'is_enabled' => false]],
        ])->assertOk();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'enrollment',
            'channel_id' => $this->email->id,
            'is_enabled' => false,
        ]);
    }

    public function test_update_denied_without_permission(): void
    {
        $this->as(User::factory()->create())->putJson(self::URL, [
            'preferences' => [['category' => 'enrollment', 'channel' => 'email', 'is_enabled' => false]],
        ])->assertForbidden();
    }

    public function test_unknown_channel_is_rejected(): void
    {
        $this->as($this->allowedUser())->putJson(self::URL, [
            'preferences' => [['category' => 'enrollment', 'channel' => 'nope', 'is_enabled' => false]],
        ])->assertStatus(422);
    }
}
