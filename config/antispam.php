<?php

declare(strict_types=1);

return [
    /*
    | Counter driver: "redis" (production, atomic Lua batch) or "memory"
    | (tests / pure-PHP). Redis degradation keeps content rules working.
    */
    'counter_driver' => env('ANTISPAM_COUNTER_DRIVER', 'redis'),

    'redis' => [
        'connection' => env('ANTISPAM_REDIS_CONNECTION', 'default'),
        'grace_seconds' => 10,
        'fingerprint_cap' => 1000,   // bounded cardinality per user per window
        'fingerprint_window' => 300, // 5m
    ],

    'cache_ttl_seconds' => 300,      // compiled plan / user lists
    'risk_cache_ttl_seconds' => 60,

    /*
    | Stage instrumentation (final-phase validation): when true, the pipeline
    | logs debug entries "antispam.stage" with observe/detect/violation stage
    | durations in ms — the source for the perf budgets (<20ms allow p95,
    | <30ms detection, <50ms violation).
    */
    'instrumentation' => env('ANTISPAM_INSTRUMENTATION', false),

    /*
    | Federated blocklist (P3.7). Bans are published to the platform feed by
    | ActionExecutor; subscriber bots opt in via their bot-scope module
    | settings: {"blocklist_sync": {"enabled": true}}.
    */
    'blocklist' => [
        'retention_days' => 30,      // blacklist entry expiry for ingested bans
    ],

    /*
    | Optional AI spam classifier (P3.4). The core engine stays AI-free: this
    | source registers only when enabled=true, fails OPEN (errors/timeouts are
    | skipped, never block the webhook) and trips a circuit breaker after
    | repeated failures. The endpoint must be a public HTTPS host (SSRF guard).
    */
    'ai' => [
        'enabled' => env('ANTISPAM_AI_ENABLED', false),
        'endpoint' => env('ANTISPAM_AI_ENDPOINT', ''),
        'key' => env('ANTISPAM_AI_KEY', ''),
        'timeout_seconds' => 0.3,    // hard webhook budget
        'min_confidence' => 0.6,
        'score_at_full_confidence' => 60,
        'failure_threshold' => 5,    // failures before the breaker opens
        'breaker_cooldown_seconds' => 60,
    ],

    /*
    | User ids that bypass enforcement (admins, service accounts). Observation
    | continues — only actions are suppressed (bypass enforcement semantics).
    | Comma-separated ids via env, e.g. ANTISPAM_EXCLUDE_USER_IDS=424242,17.
    */
    'exclude_user_ids' => array_map('intval', array_filter(explode(',', (string) env('ANTISPAM_EXCLUDE_USER_IDS', '')))),

    /*
    | Default policy applied when module_settings carry no overrides:
    | thresholds × strictness, group caps, hard severity → action mapping,
    | risk transitions, rule parameters. Keys map 1:1 to EvaluationPlan fields
    | accepted by PolicyCompiler::compile() settings array.
    */
    'policy_defaults' => [
        'strictness' => 'normal',
        'thresholds' => null,          // {warn: int, restrict: int, ban: int} or null = derive from strictness
        'global_cap' => 200,
        'group_caps' => [],            // {advertising: 80, flood: 100}
        'severity_actions' => [],      // {high: restrict, critical: ban} defaults inside compiler
        'risk_transitions' => [],      // {low: {at_score, action}, high: {...}}
        'disabled_rules' => [],
        'rule_scores' => [],
        'rule_severities' => [],
        'rule_kinds' => [],
        'rule_params' => [],
        'rule_cooldowns' => [],
        'flood_windows' => [],         // {burst: 5, short: 15, medium: 40, long: 200}
    ],
];
