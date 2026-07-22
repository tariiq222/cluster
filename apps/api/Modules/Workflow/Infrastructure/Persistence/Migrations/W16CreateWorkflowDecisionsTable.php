<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workflow_decisions')) {
            return;
        }

        Schema::create('workflow_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_step_id');
            $table->uuid('workflow_instance_id');
            $table->string('decision', 16);
            $table->text('reason')->nullable();
            $table->uuid('actor_user_id');
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index('workflow_instance_id', 'workflow_decisions_instance_index');
            $table->index('actor_user_id', 'workflow_decisions_actor_index');
            $table->index('workflow_step_id', 'workflow_decisions_step_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_decisions');
    }
};
