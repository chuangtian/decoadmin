<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('handle', 120)->unique();
            $table->string('client_id', 255)->nullable()->unique();
            $table->text('client_secret_encrypted')->nullable();
            $table->string('distribution', 30)->default('custom');
            $table->string('status', 30)->default('active')->index();
            $table->json('scopes')->nullable();
            $table->json('redirect_uris')->nullable();
            $table->string('webhook_api_version', 20)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
