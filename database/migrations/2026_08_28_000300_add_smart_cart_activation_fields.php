<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personalization_smart_cart_settings', function (Blueprint $table): void {
            $table->string('theme_id', 64)->nullable()->after('compatibility_checked_at');
            $table->string('theme_name', 120)->nullable()->after('theme_id');
            $table->timestamp('preview_confirmed_at')->nullable()->after('theme_name');
            $table->timestamp('enabled_at')->nullable()->after('preview_confirmed_at');
            $table->timestamp('disabled_at')->nullable()->after('enabled_at');
            $table->index(['store_id', 'theme_id'], 'personalization_smart_cart_theme_index');
        });
    }

    public function down(): void
    {
        Schema::table('personalization_smart_cart_settings', function (Blueprint $table): void {
            $table->dropIndex('personalization_smart_cart_theme_index');
            $table->dropColumn([
                'theme_id', 'theme_name', 'preview_confirmed_at', 'enabled_at', 'disabled_at',
            ]);
        });
    }
};
