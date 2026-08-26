<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Commands;

use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\ChatContext;
use BAGArt\TelegramBotAntispam\Domain\UserContext;
use BAGArt\TelegramBotAntispam\DryRun\DryRunExecutor;
use BAGArt\TelegramBotAntispam\Engine\PolicyCompiler;
use Illuminate\Console\Command;
use Throwable;

/**
 * FP/FN validation harness (todo.antispam.md final phase): runs a labeled
 * message dataset through the pure evaluation path and prints the confusion
 * matrix of expected vs actual verdict classes.
 *
 * Dataset format (JSON list):
 *   [{"text": "...", "expected": "allow|warn|restrict|ban"}, …]
 */
final class ValidateDatasetCommand extends Command
{
    protected $signature = 'antispam:validate-dataset
        {file : Path to the JSON dataset}
        {--bot-id=validation_bot}
        {--chat-id=100}';

    protected $description = 'Validate anti-spam policy against a labeled dataset (confusion matrix)';

    public function handle(DryRunExecutor $executor, PolicyCompiler $compiler): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Dataset file not found: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
            $this->error('Dataset must be a non-empty JSON array of {text, expected} rows.');

            return self::FAILURE;
        }

        $classes = ['allow', 'warn', 'restrict', 'ban'];
        /** @var array<string, array<string, int>> $matrix matrix[expected][actual] */
        $matrix = [];
        foreach ($classes as $expectedClass) {
            $matrix[$expectedClass] = array_fill_keys($classes, 0);
        }

        $botId = (string) $this->option('bot-id');
        $chatId = (int) $this->option('chat-id');

        try {
            $settings = app(ModuleSettingsContract::class)->settingsFor('antispam', $botId, $chatId);
        } catch (Throwable) {
            $settings = [];
        }
        $plan = $compiler->compile($botId, $chatId, $settings);

        $context = new AntispamMessageContext(
            user: new UserContext(userId: 42, username: 'validator', isBot: false),
            chat: new ChatContext(chatId: $chatId, type: 'supergroup'),
            message: new \BAGArt\TelegramBotAntispam\Domain\MessageData(
                messageId: 0,
                date: new \DateTimeImmutable(),
                text: null,
                entities: null,
                hasMedia: false,
                mediaKind: null,
                mediaFileId: null,
                hasSticker: false,
                stickerEmoji: null,
                caption: null,
                isForwarded: false,
                isReply: false,
                length: 0,
            ),
            behavior: new BehaviorContext(),
            settings: $settings,
        );

        $total = 0;
        $mismatches = 0;

        foreach ($rows as $row) {
            $text = (string) ($row['text'] ?? '');
            $expected = (string) ($row['expected'] ?? 'allow');
            if (! isset($matrix[$expected])) {
                $this->warn("Skipping row with unknown expected class [{$expected}].");

                continue;
            }

            $message = $context->message;
            // Text-only harness rows: rebuild the immutable context per row.
            $rowContext = new AntispamMessageContext(
                user: $context->user,
                chat: $context->chat,
                message: new \BAGArt\TelegramBotAntispam\Domain\MessageData(
                    messageId: $message->messageId,
                    date: $message->date,
                    text: $text,
                    entities: null,
                    hasMedia: false,
                    mediaKind: null,
                    mediaFileId: null,
                    hasSticker: false,
                    stickerEmoji: null,
                    caption: null,
                    isForwarded: false,
                    isReply: false,
                    length: mb_strlen($text),
                ),
                behavior: $context->behavior,
                settings: $settings,
            );

            $report = $executor->run($rowContext, $plan);
            $actual = $report->verdict->action->value;
            // allow == sub-warn score (verdict carries warn with score 0 semantics)
            if ($report->score === 0 && $actual === 'warn') {
                $actual = 'allow';
            }

            ++$matrix[$expected][$actual];
            ++$total;
            if ($expected !== $actual) {
                ++$mismatches;
                $this->line(sprintf(
                    'MISMATCH [%s → %s]: %s',
                    $expected,
                    $actual,
                    mb_substr($text, 0, 60),
                ));
            }
        }

        $this->table(
            array_merge(['expected \\ actual'], $classes, ['total']),
            collect($classes)->map(fn (string $expected): array => array_merge(
                [$expected],
                array_map(fn (string $actual): int|string => $matrix[$expected][$actual], $classes),
                [array_sum($matrix[$expected])],
            ))->all(),
        );

        $softBans = $matrix['allow']['ban'] + $matrix['warn']['ban'];
        $this->info(sprintf(
            'Rows: %d · mismatches: %d (%.1f%%) · false bans on benign/warn rows: %d',
            $total,
            $mismatches,
            $total > 0 ? $mismatches / $total * 100 : 0.0,
            $softBans,
        ));

        return self::SUCCESS;
    }
}
