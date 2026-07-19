<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->json('restriction_facts')->nullable()->after('classification');
        });

        Schema::create('document_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            // IDs only: source modules own the target and its lifecycle.
            $table->string('source_module', 64);
            $table->string('source_type', 64);
            $table->string('source_id', 128);
            $table->string('relation_type', 32);
            $table->string('link_classification', 24)->nullable();
            $table->uuid('linked_by_user_id');
            $table->string('status', 16)->default('active');
            $table->timestamp('unlinked_at')->nullable();
            $table->string('unlink_reason', 1000)->nullable();
            $table->timestamps();

            $table->unique(
                ['document_id', 'source_module', 'source_type', 'source_id', 'relation_type', 'status'],
                'doc_link_active_unique',
            );
            $table->index(['source_module', 'source_type', 'source_id', 'status'], 'doc_link_source_idx');
            $table->index(['document_id', 'status'], 'doc_link_document_idx');
        });

        Schema::create('document_restriction_facts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->string('fact_key', 128);
            $table->json('fact_value');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->uuid('recorded_by_user_id');
            $table->timestamps();

            $table->unique(['document_id', 'fact_key', 'valid_from'], 'doc_fact_unique');
            $table->index(['document_id', 'fact_key', 'valid_until'], 'doc_fact_lookup_idx');
        });

        Schema::create('document_access_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->uuid('document_version_id')->nullable();
            $table->uuid('actor_user_id');
            $table->uuid('acting_organization_unit_id');
            $table->string('action', 24);
            $table->string('decision', 16);
            $table->string('decision_reason_code', 64);
            $table->json('source_context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('event_id')->unique('doc_access_event_id_uq');
            $table->timestamps();

            $table->index(['document_id', 'occurred_at'], 'doc_access_document_idx');
            $table->index(['actor_user_id', 'occurred_at'], 'doc_access_actor_idx');
            $table->index(['action', 'occurred_at'], 'doc_access_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_events');
        Schema::dropIfExists('document_restriction_facts');
        Schema::dropIfExists('document_links');
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('restriction_facts');
        });
    }
};
