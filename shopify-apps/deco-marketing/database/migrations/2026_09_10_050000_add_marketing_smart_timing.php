<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('marketing_settings',fn(Blueprint $t)=>$t->string('welcome_coupon',100)->nullable());
  Schema::table('marketing_campaigns',fn(Blueprint $t)=>$t->boolean('smart_timing')->default(false));
  Schema::table('marketing_contacts',fn(Blueprint $t)=>$t->string('timezone',64)->nullable());
 }
 public function down(): void {
  Schema::table('marketing_settings',fn(Blueprint $t)=>$t->dropColumn('welcome_coupon'));
  Schema::table('marketing_campaigns',fn(Blueprint $t)=>$t->dropColumn('smart_timing'));
  Schema::table('marketing_contacts',fn(Blueprint $t)=>$t->dropColumn('timezone'));
 }
};
