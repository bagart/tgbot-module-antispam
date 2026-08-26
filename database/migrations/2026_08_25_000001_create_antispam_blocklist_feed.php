<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Federated blocklist feed (todo.antispam.md P3.7): bans published per bot,
 * ingested by subscriber bots of the same platform via
 * antispam:blocklist:sync. Sync runs over the platform DB — no network.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('antispam_blocklist_feed', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_bot_id', 20);
            $table->bigInteger('user_id');
            $table->text('reason')->nullable();
            $table->timestampTz('published_at');

            $table->unique(['source_bot_id', 'user_id'], 'antispam_blocklist_feed_unique_source');
            $table->index('user_id');

            $table->foreign('source_bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antispam_blocklist_feed');
    }
};
