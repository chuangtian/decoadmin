<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_rewards', fn (Blueprint $t) => $t->string('redeemed_order_id')->nullable());
        Schema::create('affiliate_reward_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('membership_id')->constrained('affiliate_program_memberships')->restrictOnDelete();
            $t->foreignId('reward_id')->constrained('affiliate_rewards')->restrictOnDelete();
            $t->string('event', 32);
            $t->json('rule_snapshot');
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->unique(['reward_id', 'event']);
            $t->index(['store_id', 'membership_id', 'id']);
        });
        DB::table('affiliate_rewards')->orderBy('id')->chunkById(200, function ($rewards) {
            foreach ($rewards as $reward) {
                $events = ['earned' => $reward->created_at];
                if ($reward->issued_at) {
                    $events['issued'] = $reward->issued_at;
                }
                if (in_array($reward->status, ['redeemed', 'expired', 'revoked'], true)) {
                    $events[$reward->status] = $reward->updated_at;
                }
                foreach ($events as $event => $at) {
                    DB::table('affiliate_reward_ledger_entries')->insert([
                        'public_id' => (string) Str::ulid(), 'organization_id' => $reward->organization_id,
                        'store_id' => $reward->store_id, 'membership_id' => $reward->membership_id, 'reward_id' => $reward->id,
                        'event' => $event, 'rule_snapshot' => $reward->rule_snapshot, 'occurred_at' => $at, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_reward_ledger_entries');
        Schema::table('affiliate_rewards', fn (Blueprint $t) => $t->dropColumn('redeemed_order_id'));
    }
};
