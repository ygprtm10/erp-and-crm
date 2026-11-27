<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

/**
 * Tracks the posting and audit lifecycle of a general ledger journal entry.
 */
enum JournalStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';
}
