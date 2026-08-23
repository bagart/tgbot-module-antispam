<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Domain\AggregatedScore;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamVerdict;
use BAGArt\TelegramBotAntispam\Domain\DetectionKind;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EnforcementAction;
use BAGArt\TelegramBotAntispam\Domain\RiskContext;
use BAGArt\TelegramBotAntispam\Engine\PolicyEvaluator;
use BAGArt\TelegramBotAntispam\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class PolicyEvaluatorTest extends TestCase
{
    private PolicyEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new PolicyEvaluator();
    }

    public function test_low_score_allows(): void
    {
        $verdict = $this->evaluateScore(10);

        self::assertTrue($verdict->allows());
        self::assertSame(EnforcementAction::Warn, $verdict->action);
    }

    public function test_score_thresholds_map_to_actions(): void
    {
        self::assertSame(EnforcementAction::Warn, $this->evaluateScore(40)->action);
        self::assertSame(EnforcementAction::Restrict, $this->evaluateScore(80)->action);
        self::assertSame(EnforcementAction::Ban, $this->evaluateScore(150)->action);
    }

    public function test_hard_detection_sets_minimum_via_severity_mapping(): void
    {
        // score 30 (< restrict 80) but hard+high → minimum restrict
        $score = new AggregatedScore(
            total: 30,
            globalCap: 200,
            groupBreakdown: [],
            detections: [$this->detection('advertising.regex', 30, DetectionKind::Hard, DetectionSeverity::High)],
        );

        $verdict = $this->evaluator->evaluate($score, null, Fixtures::plan());

        self::assertFalse($verdict->allows());
        self::assertSame(EnforcementAction::Restrict, $verdict->action);
    }

    public function test_hard_is_not_automatic_ban(): void
    {
        // hard + low severity → no minimum from mapping, score-only verdict
        $score = new AggregatedScore(
            total: 30,
            globalCap: 200,
            groupBreakdown: [],
            detections: [$this->detection('hard.low', 30, DetectionKind::Hard, DetectionSeverity::Low)],
        );

        self::assertTrue($this->evaluator->evaluate($score, null, Fixtures::plan())->allows());
    }

    public function test_risk_transition_can_raise_action(): void
    {
        // score 70 ≥ warn but < restrict; risk=high transition at 70 → restrict
        $plan = Fixtures::plan();
        $risk = $this->risk(RiskContext::LEVEL_HIGH);

        $score = new AggregatedScore(70, 200, [], []);
        $verdict = $this->evaluator->evaluate($score, $risk, $plan);

        self::assertSame(EnforcementAction::Restrict, $verdict->action);
    }

    public function test_risk_cannot_lower_below_hard_minimum(): void
    {
        // hard/high → minimum restrict; risk=low transition would say warn at 70.
        // Precedence answer (RFC): restrict — risk never lowers below hard minimum.
        $plan = Fixtures::plan();
        $risk = $this->risk(RiskContext::LEVEL_LOW);

        $score = new AggregatedScore(
            total: 70,
            globalCap: 200,
            groupBreakdown: [],
            detections: [$this->detection('advertising.regex', 70, DetectionKind::Hard, DetectionSeverity::High)],
        );

        $verdict = $this->evaluator->evaluate($score, $risk, $plan);

        self::assertSame(EnforcementAction::Restrict, $verdict->action);
    }

    public function test_risk_cannot_lower_score_policy_action(): void
    {
        // score 100 → restrict by thresholds; risk=low says warn at 70 → stays restrict
        $risk = $this->risk(RiskContext::LEVEL_LOW);
        $verdict = $this->evaluator->evaluate(new AggregatedScore(100, 200, [], []), $risk, Fixtures::plan());

        self::assertSame(EnforcementAction::Restrict, $verdict->action);
    }

    public function test_determinism_same_input_same_verdict(): void
    {
        $score = new AggregatedScore(70, 200, [], [
            $this->detection('advertising.regex', 70, DetectionKind::Hard, DetectionSeverity::High),
        ]);
        $plan = Fixtures::plan();
        $risk = $this->risk(RiskContext::LEVEL_MEDIUM);

        $first = $this->evaluator->evaluate($score, $risk, $plan);
        $second = $this->evaluator->evaluate($score, $risk, $plan);

        self::assertSame($first->action, $second->action);
        self::assertSame($first->reason, $second->reason);
        self::assertSame($first->matchedRules, $second->matchedRules);
    }

    public function test_verdict_carries_policy_version_and_thresholds(): void
    {
        $plan = Fixtures::plan();
        $verdict = $this->evaluator->evaluate(new AggregatedScore(0, 200, [], []), null, $plan);

        self::assertInstanceOf(AntiSpamVerdict::class, $verdict);
        self::assertSame('antispam.policy.v1', $verdict->policyVersion);
        self::assertSame(['warn' => 40, 'restrict' => 80, 'ban' => 150], $verdict->thresholds);
    }

    private function evaluateScore(int $total): AntiSpamVerdict
    {
        return $this->evaluator->evaluate(new AggregatedScore($total, 200, [], []), null, Fixtures::plan());
    }

    private function detection(string $ruleId, int $score, DetectionKind $kind, DetectionSeverity $severity): AntiSpamDetection
    {
        return new AntiSpamDetection(
            ruleId: $ruleId,
            score: $score,
            severity: $severity,
            kind: $kind,
            group: 'advertising',
            reason: 'test',
        );
    }

    private function risk(string $level): RiskContext
    {
        return new RiskContext(
            level: $level,
            accountAgeDays: null,
            chatMemberAgeDays: null,
            previousMessages: 0,
            previousViolations: 0,
            riskVersion: 'antispam.risk.v1',
        );
    }
}
