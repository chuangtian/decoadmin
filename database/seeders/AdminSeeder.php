<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::withTrashed()->firstOrNew(['email' => config('rbac.admin.email')]);
        $metadata = $user->metadata ?? [];
        $metadata['is_super_admin'] = true;
        $user->fill([
            'name' => config('rbac.admin.name'),
            'password' => $user->exists ? $user->password : Hash::make(config('rbac.admin.password')),
            'status' => 'active',
            'metadata' => $metadata,
        ]);
        $user->deleted_at = null;
        $user->save();

        $organization = Organization::withTrashed()->firstOrNew(['code' => config('rbac.organization.code')]);
        $organization->fill([
            'name' => config('rbac.organization.name'),
            'status' => 'active',
            'created_by' => $user->getKey(),
        ]);
        $organization->deleted_at = null;
        $organization->save();

        $membership = DB::table('organization_users')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        $values = [
            'status' => 'active',
            'joined_at' => now(),
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        if ($membership) {
            DB::table('organization_users')->where('id', $membership->id)->update($values);
        } else {
            DB::table('organization_users')->insert([
                ...$values,
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'created_at' => now(),
            ]);
        }
    }
}
