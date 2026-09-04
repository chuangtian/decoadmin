<?php

namespace Tests\Feature;

use App\Models\ModelAssetFolder;
use App\Models\ModelAssetImage;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\ModelAssetLibraryService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModelAssetLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_upload_view_and_permanently_delete_model_assets(): void
    {
        Storage::fake('local');
        [$user, $organization, $store] = $this->context('operator');

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->post(route('model-assets.folders.store'), ['name' => 'X1 车型'])
            ->assertRedirect();
        $folder = ModelAssetFolder::query()->sole();
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->from(route('model-assets.index'))
            ->post(route('model-assets.folders.store'), ['name' => '  X1 车型  '])
            ->assertRedirect(route('model-assets.index'))
            ->assertSessionHasErrors('name');
        $this->assertDatabaseCount('model_asset_folders', 1);

        $upload = $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->postJson(route('model-assets.images.store', ['folder' => $folder->uuid]), [
                'image' => UploadedFile::fake()->image('x1.png', 800, 600)->size(300),
            ])
            ->assertCreated()
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.height', 600);
        $image = ModelAssetImage::query()->sole();
        Storage::disk('local')->assertExists($image->path);
        Storage::disk('local')->assertExists($image->thumbnail_path);
        $this->assertStringNotContainsString('x1.png', (string) $upload->json('data.url'));
        $this->assertNotSame($upload->json('data.url'), $upload->json('data.thumbnail_url'));

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('model-assets.folders.show', ['folder' => $folder->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ModelAssets/Show')
                ->where('folder.id', $folder->uuid)
                ->where('images.data.0.id', $image->uuid)
                ->missing('images.data.0.original_name'));

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('model-assets.images.content', ['image' => $image->uuid]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('model-assets.images.thumbnail', ['image' => $image->uuid]))
            ->assertOk()
            ->assertHeader('content-type', 'image/webp');

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->deleteJson(route('model-assets.images.destroy', ['image' => $image->uuid]))
            ->assertOk()
            ->assertJsonPath('message', '图片已永久删除。');
        Storage::disk('local')->assertMissing($image->path);
        Storage::disk('local')->assertMissing($image->thumbnail_path);
        $this->assertDatabaseMissing('model_asset_images', ['id' => $image->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'model_asset_image_deleted', 'store_id' => $store->id]);
    }

    public function test_clear_and_folder_delete_remove_every_physical_file_and_database_record(): void
    {
        Storage::fake('local');
        [$user, $organization, $store] = $this->context('operator');
        $service = app(ModelAssetLibraryService::class);
        $folder = $service->createFolder($store, $user, 'X7 车型');
        $first = $service->upload($store, $folder, $user, UploadedFile::fake()->image('one.jpg', 300, 300));
        $second = $service->upload($store, $folder, $user, UploadedFile::fake()->image('two.png', 300, 300));

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->deleteJson(route('model-assets.folders.clear', ['folder' => $folder->uuid]))
            ->assertOk()
            ->assertJsonPath('deleted', 2);
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertMissing($first->thumbnail_path);
        Storage::disk('local')->assertMissing($second->path);
        Storage::disk('local')->assertMissing($second->thumbnail_path);
        $this->assertDatabaseCount('model_asset_images', 0);

        $third = $service->upload($store, $folder->fresh(), $user, UploadedFile::fake()->image('three.webp', 300, 300));
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->delete(route('model-assets.folders.destroy', ['folder' => $folder->uuid]))
            ->assertRedirect(route('model-assets.index'));
        Storage::disk('local')->assertMissing($third->path);
        Storage::disk('local')->assertMissing($third->thumbnail_path);
        $this->assertDatabaseMissing('model_asset_folders', ['id' => $folder->id]);
        $this->assertDatabaseCount('model_asset_images', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'model_asset_folder_deleted', 'store_id' => $store->id]);
    }

    public function test_assets_are_store_scoped_and_read_only_users_cannot_modify_them(): void
    {
        Storage::fake('local');
        [$viewer, $organization, $store] = $this->context('viewer');
        $other = $organization->stores()->create([
            'name' => 'Other', 'shopify_domain' => 'other-assets.myshopify.com', 'status' => 'active',
        ]);
        $otherFolder = ModelAssetFolder::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $other->id,
            'created_by' => $viewer->id,
            'name' => '其他店铺素材',
        ]);

        $this->actingAs($viewer)->withSession($this->contextSession($organization, $store))
            ->get(route('model-assets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ModelAssets/Index')
                ->has('folders.data', 0)
                ->where('permissions.manage', false));
        $this->actingAs($viewer)->withSession($this->contextSession($organization, $store))
            ->get(route('model-assets.folders.show', ['folder' => $otherFolder->uuid]))
            ->assertNotFound();
        $this->actingAs($viewer)->withSession($this->contextSession($organization, $store))
            ->post(route('model-assets.folders.store'), ['name' => '不允许'])
            ->assertForbidden();
        $this->actingAs($viewer)->withSession($this->contextSession($organization, $store))
            ->delete(route('model-assets.folders.destroy', ['folder' => $otherFolder->uuid]))
            ->assertForbidden();
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Assets Org', 'code' => 'assets-org']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Assets Store', 'shopify_domain' => 'assets-store.myshopify.com',
            'status' => 'active', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
