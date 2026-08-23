<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anti-spam module schema (RFC todo.antispam.md v5.3):
 * rules, rule_groups, violations, strike_events, user_strikes,
 * user_list_entries, appeals, stats.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('antispam_rule_groups', function (Blueprint $table) {
            // id is a logical group key ("advertising", "flood", ...); NULL bot_id = platform default row
            $table->uuid('id')->primary();
            $table->string('group_id', 50);
            $table->string('title');
            $table->integer('cap');
            $table->string('bot_id', 20)->nullable();

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->unique(['group_id', 'bot_id']);
        });

        Schema::create('antispam_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bot_id', 20)->nullable();
            $table->string('name', 100);
            $table->string('group_id', 50);
            // Detection mechanism: regex / keyword / url / window / repeat / size
            $table->string('type', 30);
            // Typed, RuleConfig-validated configuration (windows, patterns, thresholds)
            $table->json('config')->nullable();
            $table->integer('score_weight')->default(10);
            $table->string('severity', 20)->default('low');
            $table->string('kind', 10)->default('soft');
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->integer('cooldown_seconds')->nullable();
            $table->string('created_by', 100)->nullable();

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->unique(['bot_id', 'name']);
            $table->index(['bot_id', 'is_active']);
            $table->index('group_id');
            $table->timestampsTz();
        });

        Schema::create('antispam_violations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bot_id', 20);
            $table->bigInteger('chat_id');
            $table->bigInteger('user_id');
            $table->bigInteger('message_id');
            // Full original message facts — strikes survive message deletion
            $table->json('message_snapshot');
            // [{ruleId, score, severity, kind, group, reason, metadata}]
            $table->json('matched_rules');
            // [{group, contribution, cap}]
            $table->json('group_breakdown');
            $table->json('risk_context')->nullable();
            // policyVersion + riskVersion + rulesetVersion (replay/debugging contract)
            $table->json('evaluation_snapshot');
            $table->integer('score');
            // {action, policyVersion, thresholds}
            $table->json('verdict');
            $table->string('enforcement_action', 20);
            $table->string('status', 20)->default('pending');

            // Webhook retry idempotency: 1 message → max 1 violation
            $table->unique(['bot_id', 'chat_id', 'message_id']);
            $table->index(['bot_id', 'chat_id', 'user_id']);
            $table->index(['bot_id', 'status']);
            $table->timestampsTz();
        });

        Schema::create('antispam_strike_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // DB invariant: 1 violation = max 1 strike event (retry guard)
            $table->uuid('violation_id')->unique();
            $table->string('bot_id', 20);
            $table->bigInteger('chat_id');
            $table->bigInteger('user_id');
            $table->string('strike_consequence', 20);
            // Authoritative decay source: active = expired_at > now()
            $table->timestampTz('expired_at')->nullable();
            $table->boolean('active')->default(true);

            $table->index(['bot_id', 'chat_id', 'user_id', 'expired_at']);
            $table->timestampsTz();
        });

        Schema::create('antispam_user_strikes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bot_id', 20);
            $table->bigInteger('chat_id');
            $table->bigInteger('user_id');
            // CACHE ONLY: never authoritative input for escalation
            $table->unsignedInteger('active_strikes')->default(0);
            $table->unsignedInteger('total_strikes')->default(0);
            $table->timestampTz('last_offense_at')->nullable();
            $table->uuid('last_violation_id')->nullable();
            $table->timestampTz('muted_until')->nullable();
            $table->timestampTz('restricted_until')->nullable();
            $table->timestampTz('banned_at')->nullable();

            $table->unique(['bot_id', 'chat_id', 'user_id']);
            $table->timestampsTz();
        });

        Schema::create('antispam_user_list_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('list_type', 20); // whitelist | blacklist
            $table->string('bot_id', 20)->nullable();
            $table->bigInteger('chat_id');
            $table->bigInteger('user_id');
            $table->text('reason')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('created_by', 100)->nullable();

            $table->unique(['bot_id', 'chat_id', 'user_id', 'list_type'], 'antispam_user_lists_unique_scope');
            $table->index(['bot_id', 'chat_id', 'list_type']);

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->timestampsTz();
        });

        Schema::create('antispam_appeals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('violation_id');
            $table->bigInteger('user_id');
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending'); // pending/approved/rejected
            $table->string('decided_by', 100)->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->foreign('violation_id')
                ->references('id')->on('antispam_violations')
                ->cascadeOnDelete();

            $table->index(['violation_id', 'status']);
            $table->timestampsTz();
        });

        Schema::create('antispam_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('stat_date');
            $table->string('bot_id', 20);
            $table->bigInteger('chat_id')->nullable();
            $table->string('group_id', 50)->nullable();
            $table->unsignedBigInteger('detections')->default(0);
            $table->unsignedBigInteger('violations')->default(0);

            $table->unique(['stat_date', 'bot_id', 'chat_id', 'group_id'], 'antispam_stats_unique_scope');
            $table->index(['stat_date', 'bot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antispam_stats');
        Schema::dropIfExists('antispam_appeals');
        Schema::dropIfExists('antispam_user_list_entries');
        Schema::dropIfExists('antispam_user_strikes');
        Schema::dropIfExists('antispam_strike_events');
        Schema::dropIfExists('antispam_violations');
        Schema::dropIfExists('antispam_rules');
        Schema::dropIfExists('antispam_rule_groups');
    }
};
