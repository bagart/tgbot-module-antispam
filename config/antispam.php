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
    | User ids that bypass enforcement (admins, service accounts). Observation
    | continues — only actions are suppressed (bypass enforcement semantics).
    */
    'exclude_user_ids' => [],

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
