<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personalization_strategy_product_overrides', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minimum_quantity')->default(1)->after('position');
        });

        Schema::create('personalization_strategy_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->uuid('strategy_uuid');
            $table->string('strategy_name', 80);
            $table->uuid('idempotency_key')->nullable();
            $table->unsignedSmallInteger('detached_component_count')->default(0);
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at');
            $table->timestamps();

            $table->unique(['store_id', 'strategy_uuid'], 'personalization_strategy_deletion_store_uuid_unique');
            $table->unique(['store_id', 'idempotency_key'], 'personalization_strategy_deletion_store_key_unique');
            $table->index(['organization_id', 'store_id', 'deleted_at'], 'personalization_strategy_deletion_scope_index');
        });

        // The product no longer exposes a recycle bin. Purge legacy recycled
        // strategies without preserving their configuration, while retaining a
        // non-restorable name/UUID snapshot for audit and analytics labels.
        $trashed = DB::table('personalization_recommendation_strategies')
            ->whereNotNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'uuid', 'organization_id', 'store_id', 'name', 'deleted_at']);

        foreach ($trashed as $strategy) {
            DB::table('personalization_strategy_deletions')->insertOrIgnore([
                'organization_id' => $strategy->organization_id,
                'store_id' => $strategy->store_id,
                'strategy_uuid' => $strategy->uuid,
                'strategy_name' => $strategy->name,
                'idempotency_key' => null,
                'detached_component_count' => 0,
                'deleted_by' => null,
                'deleted_at' => $strategy->deleted_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $componentIds = DB::table('personalization_recommendation_components')
                ->where('strategy_id', $strategy->id)
                ->pluck('id');
            if ($componentIds->isNotEmpty()) {
                DB::table('personalization_component_styles')->whereIn('component_id', $componentIds)->delete();
                DB::table('personalization_recommendation_components')->whereIn('id', $componentIds)->delete();
            }
            DB::table('personalization_recommendation_strategies')
                ->where('id', $strategy->id)
                ->update(['published_version_id' => null]);
            DB::table('personalization_recommendation_strategies')->where('id', $strategy->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_strategy_deletions');
        Schema::table('personalization_strategy_product_overrides', function (Blueprint $table): void {
            $table->dropColumn('minimum_quantity');
        });
    }
};
