<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_requests', function (Blueprint $table): void {
            $table->string('task_name', 160)->nullable()->after('reference_no');
        });

        Schema::create('design_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('design_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['design_request_id', 'sort_order'], 'design_request_attachments_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_request_attachments');
        Schema::table('design_requests', function (Blueprint $table): void {
            $table->dropColumn('task_name');
        });
    }
};
