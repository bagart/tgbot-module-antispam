<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Appeals;

enum AppealFilingOutcome: string
{
    case Created = 'created';

    case DuplicatePending = 'duplicate_pending';

    case NoActiveSanction = 'no_active_sanction';
}
