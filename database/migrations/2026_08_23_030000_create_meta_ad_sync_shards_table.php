<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_sync_shards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sync_job_id')->constrained('sync_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('meta_ad_account_id');
            $table->string('shard_key', 191);
            $table->string('kind', 32);
            $table->string('level', 16)->nullable();
            $table->string('mode', 20);
            $table->string('status', 20)->default('queued');
            $table->timestamp('since_at')->nullable();
            $table->timestamp('until_at')->nullable();
            $table->date('since_date')->nullable();
            $table->date('until_date')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedBigInteger('records_count')->default(0);
            $table->json('result')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['meta_ad_account_id', 'organization_id', 'store_id'], 'meta_sync_shard_account_scope_fk')
                ->references(['id', 'organization_id', 'store_id'])
                ->on('meta_ad_accounts')
                ->cascadeOnDelete();
            $table->unique(['sync_job_id', 'shard_key'], 'meta_sync_shards_job_key_unique');
            $table->index(
                ['organization_id', 'store_id', 'status', 'sync_job_id'],
                'meta_sync_shards_scope_status_index',
            );
            $table->index(
                ['sync_job_id', 'kind', 'level', 'status'],
                'meta_sync_shards_job_kind_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_sync_shards');
    }
};
