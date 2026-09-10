<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function base(Blueprint $t): void
    {
        $t->id();
        $t->uuid('uuid')->unique();
        $t->foreignId('organization_id')->constrained();
        $t->foreignId('store_id')->constrained();
        $t->timestamps();
        $t->index(['organization_id', 'store_id']);
    }

    public function up(): void
    {
        Schema::create('marketing_settings', function (Blueprint $t) {
            $this->base($t);
            $t->unique('store_id');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('daily_limit')->default(100);
            $t->unsignedInteger('frequency_hours')->default(48);
            $t->string('timezone')->default('America/Los_Angeles');
            $t->json('popup')->nullable();
            $t->json('coupons')->nullable();
            $t->timestamp('cutover_at')->nullable();
        });
        Schema::create('marketing_contacts', function (Blueprint $t) {
            $this->base($t);
            $t->text('email_encrypted');
            $t->string('email_hash', 64);
            $t->text('name_encrypted')->nullable();
            $t->string('customer_id')->nullable();
            $t->string('source');
            $t->string('consent')->default('not_subscribed');
            $t->timestamp('consent_at')->nullable();
            $t->boolean('suppressed')->default(false);
            $t->string('suppression_reason')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->unsignedInteger('orders_count')->default(0);
            $t->unique(['store_id', 'email_hash']);
        });
        Schema::create('marketing_templates', function (Blueprint $t) {
            $this->base($t);
            $t->string('key');
            $t->string('name');
            $t->json('draft');
            $t->json('published')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->string('tested_hash', 64)->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->unique(['store_id', 'key']);
        });
        Schema::create('marketing_flows', function (Blueprint $t) {
            $this->base($t);
            $t->string('key');
            $t->string('name');
            $t->boolean('enabled')->default(false);
            $t->json('steps');
            $t->unsignedInteger('version')->default(1);
            $t->unique(['store_id', 'key']);
        });
        Schema::create('marketing_campaigns', function (Blueprint $t) {
            $this->base($t);
            $t->string('name');
            $t->string('status')->default('draft');
            $t->string('audience')->default('subscribed');
            $t->json('content');
            $t->json('variant_b')->nullable();
            $t->unsignedInteger('test_percent')->default(20);
            $t->string('winner')->nullable();
            $t->timestamp('winner_at')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('test_ends_at')->nullable();
            $t->timestamp('expanded_at')->nullable();
            $t->unsignedBigInteger('cursor')->default(0);
            $t->string('stop_reason')->nullable();
        });
        Schema::create('marketing_enrollments', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('contact_id')->constrained('marketing_contacts');
            $t->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns');
            $t->string('flow_key');
            $t->string('source_key', 120);
            $t->string('status')->default('active');
            $t->unsignedInteger('step')->default(0);
            $t->json('steps');
            $t->text('context_encrypted')->nullable();
            $t->timestamp('next_at')->nullable();
            $t->string('stop_reason')->nullable();
            $t->string('variant')->nullable();
            $t->unique(['store_id', 'contact_id', 'flow_key', 'source_key'], 'marketing_enrollment_identity');
            $t->index(['store_id', 'status', 'next_at']);
        });
        Schema::create('marketing_deliveries', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('contact_id')->constrained('marketing_contacts');
            $t->foreignId('enrollment_id')->nullable()->constrained('marketing_enrollments');
            $t->unsignedInteger('step')->default(0);
            $t->string('kind')->default('automation');
            $t->string('status')->default('pending');
            $t->string('dedupe_key', 64)->unique();
            $t->string('provider_id')->nullable()->index();
            $t->text('payload_encrypted')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('first_attempt_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('retry_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('clicked_at')->nullable();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->string('reason')->nullable();
            $t->index(['store_id', 'status', 'retry_at']);
        });
        Schema::create('marketing_events', function (Blueprint $t) {
            $this->base($t);
            $t->string('provider');
            $t->string('event_key', 120);
            $t->string('type');
            $t->text('payload_encrypted');
            $t->timestamp('processed_at')->nullable();
            $t->unique(['store_id', 'provider', 'event_key']);
        });
        Schema::create('marketing_waitlist', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('contact_id')->constrained('marketing_contacts');
            $t->string('sku', 120);
            $t->string('product_title')->nullable();
            $t->string('variant_id')->nullable();
            $t->string('status')->default('waiting');
            $t->timestamp('notified_at')->nullable();
            $t->unique(['store_id', 'contact_id', 'sku']);
        });
        Schema::create('marketing_attributions', function (Blueprint $t) {
            $this->base($t);
            $t->string('order_id');
            $t->foreignId('delivery_id')->constrained('marketing_deliveries');
            $t->decimal('revenue', 18, 2);
            $t->string('currency', 3);
            $t->string('status');
            $t->timestamp('ordered_at');
            $t->unique(['store_id', 'order_id']);
        });
        Schema::create('marketing_popup_events', function (Blueprint $t) {
            $this->base($t);
            $t->string('visitor_hash', 64);
            $t->string('type');
            $t->date('day');
            $t->unique(['store_id', 'visitor_hash', 'type', 'day'], 'marketing_popup_unique');
        });
        foreach (['view' => '查看营销自动化', 'manage' => '管理营销自动化'] as $action => $name) {
            DB::table('permissions')->updateOrInsert(['slug' => 'marketing.'.$action], ['name' => $name, 'group' => 'marketing', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['popup_events', 'attributions', 'waitlist', 'events', 'deliveries', 'enrollments', 'campaigns', 'flows', 'templates', 'contacts', 'settings'] as $table) {
            Schema::dropIfExists('marketing_'.$table);
        }
    }
};
