<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_discount_claims', function (Blueprint $table): void {
            $table->string('name', 120)->nullable()->after('store_id');
            $table->timestamp('privacy_consented_at')->nullable()->after('normalized_email');
            $table->foreignId('superseded_by_claim_id')
                ->nullable()
                ->after('submission_count')
                ->constrained('student_discount_claims')
                ->nullOnDelete();
            $table->timestamp('superseded_at')->nullable()->after('superseded_by_claim_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_discount_claims', function (Blueprint $table): void {
            $table->dropForeign(['superseded_by_claim_id']);
            $table->dropColumn([
                'name',
                'privacy_consented_at',
                'superseded_by_claim_id',
                'superseded_at',
            ]);
        });
    }
};
