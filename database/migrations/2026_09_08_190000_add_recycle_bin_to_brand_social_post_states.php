<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_social_post_states', function (Blueprint $table): void {
            $table->boolean('is_deleted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('brand_social_post_states', function (Blueprint $table): void {
            $table->dropColumn('is_deleted');
        });
    }
};
