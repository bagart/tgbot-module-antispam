<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Ai;

use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\DetectionKind;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Rules\DetectionSource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Optional AI spam classifier (todo.antispam.md P3.4) plugged in through the
 * DetectionSource seam — a SOFT detector: verdicts contribute score with soft
 * semantics and never carry a hard minimum.
 *
 * Failure policy: the webhook is never blocked. Disabled config → zero HTTP
 * calls; timeouts/errors are skipped and counted; after failure_threshold
 * consecutive failures the breaker opens for cooldown_seconds (skip without
 * calling). The endpoint must pass the SSRF guard.
 */
final class AiSpamDetector implements DetectionSource
{
    public const string SOURCE_ID = 'ai.detector';

    private const string BREAKER_KEY = 'antispam:ai:breaker';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ?ASKLogWrapper $logger = null,
    ) {
    }

    public function id(): string
    {
        return self::SOURCE_ID;
    }

    public function group(): string
    {
        return 'advertising';
    }

    public function check(AntispamMessageContext $context): ?AntiSpamDetection
    {
        $text = $context->message->effectiveText();
        if ($text === null || mb_strlen($text) < 8) {
            return null;
        }
        if (! (bool) Config::get('antispam.ai.enabled', false)) {
            return null;
        }
        $endpoint = (string) Config::get('antispam.ai.endpoint', '');
        if ($endpoint === '' || ! EndpointGuard::allows($endpoint) || $this->breakerOpen()) {
            return null;
        }

        try {
            $response = Http::withToken((string) (string) Config::get('antispam.ai.key', ''))
                ->timeout((float) Config::get('antispam.ai.timeout_seconds', 0.3))
                ->connectTimeout(0.2)
                ->post($endpoint, ['text' => $text]);
        } catch (Throwable $e) {
            $this->tripFailure($e::class.': '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            $this->tripFailure('http_'.$response->status());

            return null;
        }

        $this->cache->delete(self::BREAKER_KEY);

        $payload = (array) $response->json();
        $spam = (bool) ($payload['spam'] ?? false);
        $confidence = (float) ($payload['confidence'] ?? 0);
        $minConfidence = (float) Config::get('antispam.ai.min_confidence', 0.6);

        if (! $spam || $confidence < $minConfidence) {
            return null;
        }

        $scoreAtFull = max(1, (int) (int) Config::get('antispam.ai.score_at_full_confidence', 60));

        return new AntiSpamDetection(
            ruleId: self::SOURCE_ID,
            score: (int) round($scoreAtFull * $confidence),
            severity: DetectionSeverity::Medium,
            kind: DetectionKind::Soft,
            group: 'advertising',
            reason: sprintf('AI classifier: spam with confidence %.2f', $confidence),
            metadata: ['confidence' => $confidence],
        );
    }

    private function breakerOpen(): bool
    {
        $state = $this->cache->get(self::BREAKER_KEY);
        if (! is_array($state)) {
            return false;
        }

        $cooldown = max(1, (int) Config::get('antispam.ai.breaker_cooldown_seconds', 60));
        $openedAt = (int) ($state['opened_at'] ?? 0);

        if ($openedAt > 0 && time() - $openedAt >= $cooldown) {
            // half-open: allow one probe cycle again
            $this->cache->delete(self::BREAKER_KEY);

            return false;
        }

        return true;
    }

    private function tripFailure(string $reason): void
    {
        $threshold = max(1, (int) Config::get('antispam.ai.failure_threshold', 5));
        $state = is_array($this->cache->get(self::BREAKER_KEY)) ? (array) $this->cache->get(self::BREAKER_KEY) : [];
        $failures = ((int) ($state['failures'] ?? 0)) + 1;

        if ($failures >= $threshold) {
            $this->cache->set(self::BREAKER_KEY, [
                'failures' => $failures,
                'opened_at' => time(),
            ], (int) Config::get('antispam.ai.breaker_cooldown_seconds', 60));
        } else {
            $this->cache->set(self::BREAKER_KEY, ['failures' => $failures], 300);
        }

        $this->logger?->info('antispam: ai classifier failure', ['reason' => $reason, 'failures' => $failures]);
    }
}
