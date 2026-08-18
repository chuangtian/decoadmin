<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_open_own_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('profile.data.id', $user->id)
                ->where('profile.data.email', $user->email));
    }

    public function test_user_can_update_own_profile_but_cannot_change_status(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
                'password' => 'new-password-123',
                'status' => 'inactive',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', '个人资料已保存。');

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('updated@example.com', $user->email);
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check('new-password-123', $user->password));
    }

    public function test_user_avatar_is_saved_immediately_and_shared_with_header(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('profile.avatar.update'), [
                'avatar' => $this->avatarFile(),
            ])
            ->assertRedirect(route('profile.edit'));

        $avatarUrl = $user->fresh()->avatar_url;
        $this->assertStringStartsWith('/storage/avatars/', $avatarUrl);
        Storage::disk('public')->assertExists(Str::after($avatarUrl, '/storage/'));

        $this->actingAs($user->fresh())
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.avatar_url', $avatarUrl)
                ->where('profile.data.avatar_url', $avatarUrl));
    }

    public function test_user_can_remove_own_avatar_immediately(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/profile.png', 'avatar');
        $user = User::factory()->create(['avatar_url' => '/storage/avatars/profile.png']);

        $this->actingAs($user)
            ->delete(route('profile.avatar.destroy'))
            ->assertRedirect();

        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertMissing('avatars/profile.png');
    }

    public function test_guest_cannot_access_profile_or_avatar_actions(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->put(route('profile.update'))->assertRedirect(route('login'));
        $this->post(route('profile.avatar.update'))->assertRedirect(route('login'));
        $this->delete(route('profile.avatar.destroy'))->assertRedirect(route('login'));
    }

    private function avatarFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'avatar.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
