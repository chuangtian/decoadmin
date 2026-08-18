<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_can_create_a_user_with_an_avatar(): void
    {
        Storage::fake('public');
        [$admin, $organization] = $this->adminContext();

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('users.store'), [
                'name' => 'Avatar User',
                'email' => 'avatar@example.com',
                'password' => 'password123',
                'status' => 'active',
                'avatar' => $this->avatarFile(),
            ])
            ->assertRedirect(route('users.index'));

        $user = User::query()->where('email', 'avatar@example.com')->firstOrFail();
        $this->assertStringStartsWith('/storage/avatars/', $user->avatar_url);
        Storage::disk('public')->assertExists(Str::after($user->avatar_url, '/storage/'));
    }

    public function test_non_image_avatar_is_rejected(): void
    {
        Storage::fake('public');
        [$admin, $organization] = $this->adminContext();

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('users.store'), [
                'name' => 'Invalid Avatar',
                'email' => 'invalid-avatar@example.com',
                'password' => 'password123',
                'status' => 'active',
                'avatar' => UploadedFile::fake()->create('avatar.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertDatabaseMissing('users', ['email' => 'invalid-avatar@example.com']);
    }

    public function test_updating_an_avatar_replaces_the_previous_local_file(): void
    {
        Storage::fake('public');
        [$admin, $organization] = $this->adminContext();
        Storage::disk('public')->put('avatars/old-avatar.png', 'old-avatar');
        $user = $this->organizationUser($organization, [
            'avatar_url' => '/storage/avatars/old-avatar.png',
        ]);

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('users.update', $user), [
                '_method' => 'put',
                'name' => $user->name,
                'email' => $user->email,
                'status' => 'active',
                'avatar' => $this->avatarFile(),
            ])
            ->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertNotSame('/storage/avatars/old-avatar.png', $user->avatar_url);
        Storage::disk('public')->assertMissing('avatars/old-avatar.png');
        Storage::disk('public')->assertExists(Str::after($user->avatar_url, '/storage/'));
    }

    public function test_user_avatar_can_be_removed(): void
    {
        Storage::fake('public');
        [$admin, $organization] = $this->adminContext();
        Storage::disk('public')->put('avatars/removable.png', 'avatar');
        $user = $this->organizationUser($organization, [
            'avatar_url' => '/storage/avatars/removable.png',
        ]);

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('users.update', $user), [
                '_method' => 'put',
                'name' => $user->name,
                'email' => $user->email,
                'status' => 'active',
                'remove_avatar' => true,
            ])
            ->assertRedirect(route('users.index'));

        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertMissing('avatars/removable.png');
    }

    public function test_editing_user_avatar_is_saved_immediately(): void
    {
        Storage::fake('public');
        [$admin, $organization] = $this->adminContext();
        $user = $this->organizationUser($organization);

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id])
            ->from(route('users.edit', $user))
            ->post(route('users.avatar.update', $user), [
                'avatar' => $this->avatarFile(),
            ])
            ->assertRedirect(route('users.edit', $user))
            ->assertSessionHas('success', '头像已自动保存。');

        $this->assertStringStartsWith('/storage/avatars/', $user->fresh()->avatar_url);
        Storage::disk('public')->assertExists(Str::after($user->fresh()->avatar_url, '/storage/'));
    }

    public function test_editing_user_avatar_can_be_removed_immediately(): void
    {
        Storage::fake('public');
        [$admin, $organization] = $this->adminContext();
        Storage::disk('public')->put('avatars/immediate-remove.png', 'avatar');
        $user = $this->organizationUser($organization, [
            'avatar_url' => '/storage/avatars/immediate-remove.png',
        ]);

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id])
            ->from(route('users.edit', $user))
            ->delete(route('users.avatar.destroy', $user))
            ->assertRedirect(route('users.edit', $user))
            ->assertSessionHas('success', '头像已移除。');

        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertMissing('avatars/immediate-remove.png');
    }

    public function test_user_without_update_permission_cannot_save_an_avatar(): void
    {
        Storage::fake('public');
        [$admin, $organization] = $this->adminContext();
        $user = $this->organizationUser($organization);
        $viewer = $this->organizationUser($organization);

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('users.avatar.update', $user), [
                'avatar' => $this->avatarFile(),
            ])
            ->assertForbidden();

        $this->assertNull($user->fresh()->avatar_url);
        $this->assertNotNull($admin);
    }

    public function test_authenticated_user_avatar_is_shared_with_the_admin_shell(): void
    {
        [$admin, $organization] = $this->adminContext([
            'avatar_url' => '/storage/avatars/admin.png',
        ]);

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.avatar_url', '/storage/avatars/admin.png'));
    }

    /** @return array{0: User, 1: Organization} */
    private function adminContext(array $attributes = []): array
    {
        $admin = User::factory()->create([
            ...$attributes,
            'metadata' => ['is_super_admin' => true],
        ]);
        $organization = Organization::query()->create([
            'name' => 'Avatar Organization',
            'code' => 'avatar-organization',
        ]);
        $organization->users()->attach($admin, ['status' => 'active', 'joined_at' => now()]);

        return [$admin, $organization];
    }

    private function organizationUser(Organization $organization, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $user;
    }

    private function avatarFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'avatar.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
