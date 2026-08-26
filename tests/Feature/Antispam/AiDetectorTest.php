<?php

declare(strict_types=1);

use BAGArt\TelegramBotAntispam\Ai\AiSpamDetector;
use BAGArt\TelegramBotAntispam\Ai\EndpointGuard;
use Illuminate\Support\Facades\Http;

const AI_ENDPOINT = 'https://ai.example.com/classify';

function aiDetector(): AiSpamDetector
{
    return new AiSpamDetector(app(\BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper::class));
}

beforeEach(function () {
    // Sandbox has no DNS: resolve the test host to a public IP deterministically.
    EndpointGuard::$dnsResolver = fn (string $host) => [['ip' => '93.184.216.34']];

    config(['antispam.ai' => [
        'enabled' => true,
        'endpoint' => AI_ENDPOINT,
        'key' => 'secret',
        'timeout_seconds' => 0.3,
        'min_confidence' => 0.6,
        'score_at_full_confidence' => 60,
        'failure_threshold' => 2,
        'breaker_cooldown_seconds' => 60,
    ]]);
});

it('issues zero HTTP calls when disabled', function () {
    config(['antispam.ai.enabled' => false]);
    Http::fake();

    aiDetector()->check(\BAGArt\TelegramBotAntispam\Tests\Support\Fixtures::context(text: 'buy my product now friend'));

    Http::assertNothingSent();
});

it('produces a soft detection with confidence metadata on spam responses', function () {
    Http::fake([AI_ENDPOINT => Http::response(['spam' => true, 'confidence' => 0.9])]);

    $detection = aiDetector()->check(
        \BAGArt\TelegramBotAntispam\Tests\Support\Fixtures::context(text: 'buy my product now friend'),
    );

    expect($detection)->not->toBeNull()
        ->and($detection->ruleId)->toBe(AiSpamDetector::SOURCE_ID)
        ->and($detection->kind->value)->toBe('soft')
        ->and($detection->metadata['confidence'])->toBe(0.9)
        // score scales with confidence: 60 * 0.9
        ->and($detection->score)->toBe(54);
});

it('stays silent on ham responses and low confidence', function () {
    $detector = aiDetector();

    Http::fake([AI_ENDPOINT => Http::response(['spam' => false, 'confidence' => 0.9])]);
    expect($detector->check(\BAGArt\TelegramBotAntispam\Tests\Support\Fixtures::context(text: 'nice weather today friend')))->toBeNull();

    Http::fake([AI_ENDPOINT => Http::response(['spam' => true, 'confidence' => 0.3])]);
    expect($detector->check(\BAGArt\TelegramBotAntispam\Tests\Support\Fixtures::context(text: 'nice weather today friend')))->toBeNull();
});

it('fails open on timeouts and trips the breaker after the threshold', function () {
    $detector = aiDetector();

    // failure_threshold = 2 → breaker opens after two connection failures.
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
    expect($detector->check(\BAGArt\TelegramBotAntispam\Tests\Support\Fixtures::context(text: 'first message hello')))->toBeNull();
    expect($detector->check(\BAGArt\TelegramBotAntispam\Tests\Support\Fixtures::context(text: 'second message hello')))->toBeNull();

    // Breaker open: no HTTP attempt at all (fail-open, webhook unblocked).
    Http::fake();
    expect($detector->check(\BAGArt\TelegramBotAntispam\Tests\Support\Fixtures::context(text: 'third message hello')))->toBeNull();
    Http::assertNothingSent();
});

it('rejects private endpoints via the SSRF guard without calling them', function () {
    config(['antispam.ai.endpoint' => 'http://169.254.169.254/latest/meta-data']);
    Http::fake();

    expect(aiDetector()->check(\BAGArt\TelegramBotAntispam\Tests\Support\Fixtures::context(text: 'buy my product now friend')))->toBeNull();

    Http::assertNothingSent();
});

it('blocks private and non-https hosts in the endpoint guard', function () {
    expect(EndpointGuard::allows('http://10.0.0.5/classify'))->toBeFalse()
        ->and(EndpointGuard::allows('https://127.0.0.1/classify'))->toBeFalse()
        ->and(EndpointGuard::allows('http://ai.example.com/classify'))->toBeFalse()
        ->and(EndpointGuard::allows('not-a-url'))->toBeFalse()
        // literal public IP over https is allowed without DNS
        ->and(EndpointGuard::allows('https://93.184.216.34/classify'))->toBeTrue();
});
