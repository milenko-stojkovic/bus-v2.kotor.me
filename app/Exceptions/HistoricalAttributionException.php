<?php

namespace App\Exceptions;

use RuntimeException;

final class HistoricalAttributionException extends RuntimeException
{
    public const NOT_IMPORTED_V1 = 'not_imported_v1';

    public const ALREADY_ATTRIBUTED = 'already_attributed';

    public const EVIDENCE_REQUIRED = 'evidence_required';

    public const NOT_ATTRIBUTED = 'not_attributed';

    public const ATTRIBUTION_MISMATCH = 'attribution_mismatch';

    public const AUDIT_MISSING = 'audit_missing';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
