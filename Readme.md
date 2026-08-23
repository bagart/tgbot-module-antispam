# telegram-bot-antispam-module

Anti-spam module for the [telegram-bot-platform](../../../). Deterministic anti-abuse
engine implemented as a platform module (`TgModuleContract`): content + behavioral
rules, score aggregation with per-group caps, sliding-window counters in Redis,
risk context, policy → violation → strike → async enforcement.

Design source: `docs/tasks/todo.antispam.md` (RFC v5.3) in the platform repo.

## Core properties

- **Pure evaluation** — the engine has zero side effects: same input + same plan = same verdict.
- **Compiled EvaluationPlan** — settings merged once, cached per (bot, chat, version). No per-webhook merges.
- **Batch Counter API** — one Redis round trip updates all counters and returns the snapshot (Lua).
- **DB as strike correctness** — PostgreSQL `SELECT … FOR UPDATE` serializes strikes; `UNIQUE(violation_id)` guards 1 violation = 1 event.
- **Async enforcement** — Telegram API calls go through the outbound pipeline; webhook latency is network-independent.
- **Fail-open enforcement** — module/storage errors disable filtering instead of blocking chats.

## Install

```bash
composer require bagart/telegram-bot-antispam-module
```

Register the provider in `bootstrap/providers.php`:

```php
BAGArt\TelegramBotAntispam\TelegramBotAntispamServiceProvider::class,
```

The provider pushes `AntispamModule::class` into `config('telegram.modules_providers')`
and loads its migrations. Enable per bot/chat via `tg:module:enable antispam`.

## Layout

```
src/
├── AntispamModule.php          # TgModuleContract entry point
├── Domain/                     # immutable VOs (context, detection, verdict, snapshots)
├── Rules/                      # abstract rule + 16 content/behavior rules
├── Counters/                   # batch counter API (Redis Lua / in-memory)
├── Engine/                     # pure engine, compiled plan, aggregator, policy evaluator
├── Risk/                       # risk context builder (hot/cold signals)
├── Violation/                  # idempotent violation persistence
├── Strike/                     # DB-serialized strikes, escalation with hysteresis
├── Enforcement/                # async action executor over TgSenderContract
├── UserList/                   # whitelist/blacklist gating (bypass module vs enforcement)
├── DryRun/, Replay/            # breakdown without side effects; old vs new policy comparison
└── Processors/                 # message processor + /antispam and /report commands
```
