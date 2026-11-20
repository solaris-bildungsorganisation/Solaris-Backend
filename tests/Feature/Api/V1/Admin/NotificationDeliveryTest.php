<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AccessScope;
use App\Models\Country;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_admin_permission_cannot_view_notification_deliveries(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test');

        $user->sessions()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'last_activity_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/admin/notification-deliveries')
            ->assertForbidden();
    }

    public function test_admin_with_permission_can_view_notification_deliveries(): void
    {
        $permission = Permission::create([
            'name' => 'notification_deliveries.view',
            'guard_name' => 'web',
        ]);

        $country = Country::create([
            'iso2' => 'SY',
            'name' => [
                'ar' => 'سوريا',
                'en' => 'Syria',
            ],
        ]);

        $scope = AccessScope::forCountry($country);

        setPermissionsTeamId($scope->id);

        $admin = User::factory()->create([
            'country_id' => $country->id,
        ]);

        $admin->givePermissionTo($permission);

        $token = $admin->createToken('test');

        $admin->sessions()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'active_scope_id' => $scope->id,
            'last_activity_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/admin/notification-deliveries')
            ->assertOk();
    }

    public function test_admin_cannot_view_notification_delivery_from_another_country(): void
    {
        $permission = Permission::create([
            'name' => 'notification_deliveries.view',
            'guard_name' => 'web',
        ]);

        $syria = Country::create([
            'iso2' => 'SY',
            'name' => ['ar' => 'سوريا', 'en' => 'Syria'],
        ]);

        $jordan = Country::create([
            'iso2' => 'JO',
            'name' => ['ar' => 'الأردن', 'en' => 'Jordan'],
        ]);

        $scope = AccessScope::forCountry($syria);

        setPermissionsTeamId($scope->id);

        $admin = User::factory()->create([
            'country_id' => $syria->id,
        ]);

        $admin->givePermissionTo($permission);

        $otherUser = User::factory()->create([
            'country_id' => $jordan->id,
        ]);

        $channel = NotificationChannel::create([
            'key' => 'email',
            'name' => [
                'ar' => 'البريد الإلكتروني',
                'en' => 'Email',
            ],
            'driver' => 'mail',
        ]);

        $delivery = NotificationDelivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_id' => $otherUser->id,
            'channel_id' => $channel->id,
            'status' => 'sent',
            'attempts' => 1,
        ]);

        $token = $admin->createToken('test');

        $admin->sessions()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'active_scope_id' => $scope->id,
            'last_activity_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/admin/notification-deliveries/'.$delivery->id)
            ->assertNotFound();
    }




public function test_delivery_details_include_last_error(): void
{
    $permission = Permission::create([
        'name' => 'notification_deliveries.view',
        'guard_name' => 'web',
    ]);

    $country = Country::create([
        'iso2' => 'SY',
        'name' => ['ar' => 'سوريا', 'en' => 'Syria'],
    ]);

    $scope = AccessScope::forCountry($country);
    setPermissionsTeamId($scope->id);

    $admin = User::factory()->create([
        'country_id' => $country->id,
    ]);

    $admin->givePermissionTo($permission);

    $user = User::factory()->create([
        'country_id' => $country->id,
    ]);

    $channel = NotificationChannel::create([
        'key' => 'email',
        'name' => ['ar' => 'البريد', 'en' => 'Email'],
        'driver' => 'mail',
    ]);

    $delivery = NotificationDelivery::create([
        'notification_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'channel_id' => $channel->id,
        'status' => 'failed',
        'last_error' => 'SMTP connection failed',
    ]);

    $token = $admin->createToken('test');

    $admin->sessions()->create([
        'personal_access_token_id' => $token->accessToken->id,
        'active_scope_id' => $scope->id,
        'last_activity_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/admin/notification-deliveries/'.$delivery->id)
        ->assertOk()
        ->assertJsonPath('data.last_error', 'SMTP connection failed');
}
















}
