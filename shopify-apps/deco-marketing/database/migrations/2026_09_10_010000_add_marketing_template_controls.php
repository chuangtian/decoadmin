<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_templates', function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
        });
        Schema::table('marketing_deliveries', function (Blueprint $table) {
            $table->string('template_key')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_deliveries', fn (Blueprint $table) => $table->dropColumn('template_key'));
        Schema::table('marketing_templates', fn (Blueprint $table) => $table->dropColumn('enabled'));
    }
};
