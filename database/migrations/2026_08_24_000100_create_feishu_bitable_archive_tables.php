<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feishu_bitable_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source_section', 60);
            $table->string('source_table_id', 120);
            $table->string('name')->nullable();
            $table->longText('metadata_encrypted')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['store_id', 'source_section', 'source_table_id'],
                'feishu_tables_store_section_source_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'source_section'],
                'feishu_tables_scope_index',
            );
        });

        Schema::create('feishu_bitable_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feishu_bitable_table_id')->constrained('feishu_bitable_tables')->cascadeOnDelete();
            $table->string('source_field_id', 120);
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('type')->nullable();
            $table->unsignedInteger('field_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->longText('metadata_encrypted')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['feishu_bitable_table_id', 'source_field_id'],
                'feishu_fields_table_source_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'feishu_bitable_table_id'],
                'feishu_fields_scope_index',
            );
        });

        Schema::create('feishu_bitable_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feishu_bitable_table_id')->constrained('feishu_bitable_tables')->cascadeOnDelete();
            $table->string('source_record_id', 120);
            $table->longText('fields_encrypted');
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['feishu_bitable_table_id', 'source_record_id'],
                'feishu_records_table_source_unique',
            );
            $table->index(
                ['organization_id', 'store_id', 'feishu_bitable_table_id'],
                'feishu_records_scope_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feishu_bitable_records');
        Schema::dropIfExists('feishu_bitable_fields');
        Schema::dropIfExists('feishu_bitable_tables');
    }
};
