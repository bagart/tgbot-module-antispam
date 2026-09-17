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
