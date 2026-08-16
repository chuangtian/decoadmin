<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug'], 'roles_org_slug_unique');
            $table->unique(['id', 'organization_id'], 'roles_id_org_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
