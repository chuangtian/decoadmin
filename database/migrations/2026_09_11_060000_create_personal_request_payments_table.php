<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_request_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->date('paid_on');
            $table->string('reference', 180)->nullable();
            $table->timestamps();

            $table->unique(['personal_request_id', 'paid_on'], 'personal_request_payments_request_date_unique');
            $table->index(['organization_id', 'paid_on'], 'personal_request_payments_org_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_request_payments');
    }
};
