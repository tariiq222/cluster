<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_storage_objects', function (Blueprint $table): void {
            $table->string('etag', 512)->nullable()->after('object_key');
            $table->string('generation', 128)->nullable()->after('etag');
        });

        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dateTime('promotion_requested_at', 3)->nullable()->after('available_at');
        });

        Schema::table('document_upload_intents', function (Blueprint $table): void {
            $table->char('expected_sha256', 64)->nullable()->after('storage_object_id');
            $table->unsignedBigInteger('expected_size_bytes')->nullable()->after('expected_sha256');
            $table->string('expected_mime_type', 128)->nullable()->after('expected_size_bytes');
            $table->boolean('conditional_create')->default(true)->after('expected_mime_type');
            $table->text('signed_intent_payload')->nullable()->after('conditional_create');
        });
    }

    public function down(): void
    {
        Schema::table('document_upload_intents', function (Blueprint $table): void {
            $table->dropColumn(['expected_sha256', 'expected_size_bytes', 'expected_mime_type', 'conditional_create', 'signed_intent_payload']);
        });
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn('promotion_requested_at');
        });
        Schema::table('document_storage_objects', function (Blueprint $table): void {
            $table->dropColumn(['etag', 'generation']);
        });
    }
};
