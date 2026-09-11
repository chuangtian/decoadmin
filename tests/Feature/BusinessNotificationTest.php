<?php

namespace Tests\Feature;

use App\Models\BusinessNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_only_returns_notifications_for_the_current_user_and_organization(): void
    {
        $organization = Organization::query()->create(['name' => 'DecoMKT', 'code' => 'decomkt']);
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'code' => 'other']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $organization->users()->attach($otherUser, ['status' => 'active', 'joined_at' => now()]);
        $otherOrganization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $mine = $this->notification($organization, $user, 'mine');
        $this->notification($organization, $otherUser, 'other-user');
        $this->notification($otherOrganization, $user, 'other-organization');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->getJson(route('business-notifications.index'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.uuid', $mine->uuid)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_user_can_mark_own_notifications_read_but_not_another_users_notification(): void
    {
        $organization = Organization::query()->create(['name' => 'DecoMKT', 'code' => 'decomkt']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $organization->users()->attach($otherUser, ['status' => 'active', 'joined_at' => now()]);
        $first = $this->notification($organization, $user, 'first');
        $second = $this->notification($organization, $user, 'second');
        $other = $this->notification($organization, $otherUser, 'other');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id]);
        $this->patchJson(route('business-notifications.read', $first))->assertOk();
        $this->assertNotNull($first->fresh()->read_at);
        $this->patchJson(route('business-notifications.read', $other))->assertNotFound();
        $this->patchJson(route('business-notifications.read-all'))->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    private function notification(Organization $organization, User $user, string $key): BusinessNotification
    {
        return BusinessNotification::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'type' => 'test',
            'title' => '测试通知',
            'message' => '只包含安全的摘要信息。',
            'action_url' => '/dashboard',
            'dedupe_key' => hash('sha256', $organization->id.':'.$user->id.':'.$key),
        ]);
    }
}
