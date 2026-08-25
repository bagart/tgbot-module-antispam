<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotManagement\Models\TgBot;

/** Shared fixtures for the antispam admin-panel feature tests (loaded via require_once). */

/**
 * Records every send() call without touching Telegram.
 */
function antispamSenderSpy(): TgSenderContract
{
    return new class () implements TgSenderContract {
        /** @var list<class-string> */
        public array $sent = [];

        /** @var list<TgApiMethodDTOContract> */
        public array $dtos = [];

        public function send(TgBotConfig $botConfig, TgApiMethodDTOContract $dto): void
        {
            $this->sent[] = $dto::class;
            $this->dtos[] = $dto;
        }
    };
}

function antispamAdminSetup(): void
{
    TgBot::create(['bot_id' => 'admin_bot', 'token' => 't:token']);

    // Chat-level antispam enablement so the chats page and effective-plan resolution have data
    \BAGArt\TelegramBotManagement\Models\TgModuleEnablement::factory()
        ->forChat('admin_bot', 100)
        ->enabled(true)
        ->create(['module_id' => 'antispam']);
}

function antispamAdminActingAs(): User
{
    $user = User::factory()->create();

    test()->actingAs($user);

    return $user;
}

function antispamViolationRow(array $overrides = []): string
{
    static $sequence = 0;
    $sequence++;

    $defaults = [
        'id' => (string) Illuminate\Support\Str::uuid(),
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
        'user_id' => 42,
        'message_id' => $sequence,
        'message_snapshot' => ['text' => 'spam text'],
        'matched_rules' => [
            ['ruleId' => 'flood.rate.burst', 'score' => 30, 'severity' => 'high', 'kind' => 'soft', 'group' => 'flood', 'reason' => 'rate'],
            ['ruleId' => 'advertising.contacts', 'score' => 60, 'severity' => 'high', 'kind' => 'hard', 'group' => 'advertising', 'reason' => 'contact'],
        ],
        'group_breakdown' => ['flood' => ['contribution' => 30, 'cap' => 100], 'advertising' => ['contribution' => 60, 'cap' => 80]],
        'risk_context' => null,
        'evaluation_snapshot' => [
            'policyVersion' => 'antispam.policy.v1',
            'rulesetVersion' => 'abc123',
        ],
        'score' => 90,
        'verdict' => ['action' => 'restrict', 'policyVersion' => 'antispam.policy.v1'],
        'enforcement_action' => 'restrict',
        'status' => 'applied',
    ];

    $row = $overrides + $defaults;

    // Query-builder inserts bypass Eloquent casts — encode JSON columns manually
    foreach (['message_snapshot', 'matched_rules', 'group_breakdown', 'risk_context', 'evaluation_snapshot', 'verdict'] as $jsonColumn) {
        if (is_array($row[$jsonColumn])) {
            $row[$jsonColumn] = json_encode($row[$jsonColumn], JSON_UNESCAPED_UNICODE);
        }
    }

    Illuminate\Support\Facades\DB::table('antispam_violations')->insert($row);

    return (string) $row['id'];
}

/**
 * Runs the admin dry-run endpoint and returns the decoded report.
 *
 * @return array<string, mixed>
 */
function antispamDryRun(string $text): array
{
    $response = test()->postJson(route('antispam.dry-run'), [
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
        'text' => $text,
    ]);

    $response->assertOk();

    return (array) $response->json();
}
