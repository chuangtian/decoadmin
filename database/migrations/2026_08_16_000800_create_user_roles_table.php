<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('scope_store_id')->storedAs('coalesce(`store_id`, 0)');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['organization_id', 'scope_store_id', 'user_id', 'role_id'],
                'user_roles_scope_user_role_unique'
            );
            $table->index(['user_id', 'organization_id', 'store_id'], 'user_roles_user_scope_index');

            $table->foreign(['organization_id', 'user_id'], 'user_roles_org_member_fk')
                ->references(['organization_id', 'user_id'])
                ->on('organization_users')
                ->restrictOnDelete();
            $table->foreign(['store_id', 'user_id'], 'user_roles_store_member_fk')
                ->references(['store_id', 'user_id'])
                ->on('store_members')
                ->restrictOnDelete();
            $table->foreign(['role_id', 'organization_id'], 'user_roles_role_org_fk')
                ->references(['id', 'organization_id'])
                ->on('roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
