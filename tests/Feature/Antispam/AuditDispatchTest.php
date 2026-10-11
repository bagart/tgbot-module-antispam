<?php

declare(strict_types=1);

require_once __DIR__.'/Admin/AdminHelpers.php';

use BAGArt\TelegramBotAntispam\Commands\BlocklistSyncCommand;
use BAGArt\TelegramBotAntispam\Events\AntispamAuditEvent;
use BAGArt\TelegramBotAntispam\Models\AntispamBlocklistFeed;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();

    Event::fake([AntispamAuditEvent::class]);
});

it('dispatches rule.created from the rule store endpoint (F1 entity)', function () {
    $this->post(route('antispam.rules.store'), [
        'bot_id' => null,
        'name' => 'no.crypto',
        'group_id' => 'advertising',
        'type' => 'keyword',
        'config' => ['param' => 1],
        'score_weight' => 45,
        'severity' => 'high',
        'kind' => 'soft',
        'priority' => 50,
        'is_active' => true,
        'cooldown_seconds' => 30,
    ])->assertRedirect();

    Event::assertDispatched(
        AntispamAuditEvent::class,
        static fn (AntispamAuditEvent $event): bool => $event->operation() === AntispamAuditEvent::RULE_CREATED
            && $event->subjectType() === 'antispam_rule',
    );
});

it('dispatches settings.changed from the chat settings endpoint (F2 .changed)', function () {
    $this->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
        'strictness' => 'strict',
        'thresholds' => ['warn' => 20, 'restrict' => 40, 'ban' => 80],
    ])->assertRedirect();

    Event::assertDispatched(
        AntispamAuditEvent::class,
        static fn (AntispamAuditEvent $event): bool => $event->operation() === AntispamAuditEvent::SETTINGS_CHANGED,
    );
});

it('dispatches blocklist.synced from the CLI sync command (F3 automatic/CLI)', function () {
    AntispamBlocklistFeed::query()->create([
        'source_bot_id' => 'admin_bot',
        'user_id' => 42,
        'reason' => 'advertising.regex',
        'published_at' => now(),
    ]);

    $this->artisan(BlocklistSyncCommand::class)->assertSuccessful();

    Event::assertDispatched(
        AntispamAuditEvent::class,
        static fn (AntispamAuditEvent $event): bool => $event->operation() === AntispamAuditEvent::BLOCKLIST_SYNCED
            && $event->subjectType() === 'blocklist_feed',
    );
});
