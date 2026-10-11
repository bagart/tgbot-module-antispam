# Antispam Module — SDD

> **Module:** `tgbot-module-antispam` (`BAGArt\TelegramBotAntispam`)
> **Status:** 100% complete

---

## What Was Done

AI-powered anti-spam module with captcha, strikes, violations, enforcement, and appeals.

### Core Components

- **AI Detection**: Machine learning-based spam classification.
- **Captcha System**: Challenge-response for suspicious users.
- **Commands**: Admin commands for spam management.
- **Counters**: Per-chat spam statistics.
- **Enforcement**: Automated actions (warn, mute, ban) based on rules.
- **Rules Engine**: Configurable spam detection rules.
- **Strikes**: Progressive penalty system.
- **Violations**: Violation history tracking.
- **Web Interface**: Admin panel for spam management.
- **Appeals**: User appeal process for false positives.
- **i18n**: 5 locales (RU, EN, FR, ES, ZH).

### Key Decisions

- AI-first approach (ML classification before rule-based checks).
- Progressive enforcement (strikes → escalation).
- Appeals process for false positives (human review).

### Files

- `src/` — Domain logic, AI integration, commands, enforcement

## T2 moderation-view gates — management-admin-rbac (2026-09-28)

- **`moderation.reason.view` and `moderation.log.view` are deny-by-default** through `AccessControlContract` (`Auth\T2Gate`), chat-scoped, workspaceId resolved from `tg_entities`; viewer = platform user from the web session (no tg-id lookup needed).
- **`ReasonVisibility` trait** masks per row on the moderation pages: stored reason emptied/nulled or history event dropped when the viewer lacks the capability — rule ids stay so the queue remains browsable. Memoized per capability|bot|chat on the request-scoped controller.
- **Legacy-fallback rule (shared with mafia `game.initiate`):** a viewer with no telegram link (break-glass/email account) keeps full visibility; linked accounts are always decided, fail-closed on access-layer errors.

## Test suite green — harness decisions (2026-09-28)

Suite went from 33 failed → 0 failed / 233 passed; Summarizer baseline (15 failed) and menu-feature (275 passed) unchanged. All fixes are harness/stale-test fixes — no product code changed.

- **`config/inertia.php`:** `page_paths` + `testing.page_paths` now glob `misc/BAGArt/*/resources/js/pages` so module Inertia components (`antispam/*`, menu, proxy) resolve for `assertInertia`. Per-test overrides (e.g. `ReasonViewGateTest`) are redundant and were removed.
- **`tests/Pest.php`:** the suite pins BOTH `ModuleEnablementContract` and `ModuleSettingsContract` to the legacy `TgModuleEnablementService` (same pattern as the lib `Feature/Modules` suite) because the Feature fixtures seed legacy enablement rows directly (AdminHelpers factory; retained decision above). `tg_modules.enablement_driver` stays `'engine'` (platform policy, untouched). Engine-mode contract coverage lives in the host seam test `tests/Feature/ModuleSettingsEngineModeTest` (decisions: `docs/questions/module-suite-engine-pinning.md`).
- **Settings migration RESOLVED (2026-09-29):** all antispam call sites (Chats/UserLists controllers, BlocklistSyncCommand) now go through `ModuleSettingsContract` (`patchSettings`/`chatsWithSettings`/`settingsFor`) on both drivers; the stale `INTENTIONAL BRIDGE` comment was removed. Host SDD: `docs/sdd/settings-migration-contract.md`.
- **Stale tests updated:** `FrontendPagesRegistrationTest` asserts `EngineModuleRegistry::frontendPages()` (declarative `frontendPages` in `config/tg_modules.php` replaced the retired `telegram.modules_frontend_pages` side-channel); `AnalyticsTest` heatmap fixtures now use last week's Tuesday/Monday so they always sit inside the rolling 30-day window.

## /report Counter (B4, 2026-10-08) — Option B chosen

`AntispamReportCommand`'s docblock claimed "counts manual reports per chat" over an acknowledgement-only flow (`process()` sends a usage hint or an acceptance ack, persists nothing) — a confirmed lying docblock (fix-impact §B4). Q5 option 2 keeps plain `/report` with antispam; the command survives, so its contract must be true.

- **Option B: correct the docblock, do not implement the counter.** Decision rule was "implement only if a reader for the per-chat count ships in the same change"; zero readers exist anywhere (grep of `src/`, `config/`, `Readme.md`, `AntispamStatsController` → no report metric). Option A's blast (fixed 7-key `CounterBatch::increments()` + both drivers + `CounterSnapshot` + Redis Lua batch + 5 `phpstan-baseline.neon` entries on the driver files) is disproportionate for an unread count against the ~1.8 pt coverage headroom.
- Docblock now reads: acknowledgement-only moderation intake — usage hint when bare, acceptance ack when replying to the spam message; persists nothing. 1 file, 1 line changed; the ack UX (`✅ Report accepted…`, asserted by `AntispamCommandsE2ETest`) is unchanged. No other doc repeated the claim.
- If a per-chat report count ever gains a consumer, option A reopens as its own task (reader-first); the counter-driver baseline hazard (`phpstan-baseline.neon:1549,1555,1561,1567,1573`) applies then.
- Sequencing: B4b's `/report` → `/netreport` rename (nettools) landed after this item (edge 14); nettools SDD carries the rename details.
