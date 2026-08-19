<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('color', 20)->default('slate');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'type', 'name'], 'finance_categories_scope_name_unique');
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('finance_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 16, 4);
            $table->char('currency', 3)->default('USD');
            $table->date('occurred_on');
            $table->string('description', 500);
            $table->string('reference', 150)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'occurred_on'], 'finance_entries_org_date_index');
            $table->index(['store_id', 'occurred_on'], 'finance_entries_store_date_index');
            $table->index(['organization_id', 'type', 'occurred_on'], 'finance_entries_org_type_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('finance_categories');
    }
};
