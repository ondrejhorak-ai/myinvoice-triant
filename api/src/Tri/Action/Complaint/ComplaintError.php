<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Complaint;

final class ComplaintError
{
    public static function message(string $code): string
    {
        return match ($code) {
            'INVALID_JOB' => 'Neplatná zakázka.',
            'TITLE_REQUIRED' => 'Název reklamace je povinný.',
            'TITLE_TOO_LONG' => 'Název reklamace je příliš dlouhý.',
            'INVALID_STATUS' => 'Neplatný stav reklamace.',
            'COMMENT_REQUIRED' => 'Text komentáře je povinný.',
            'COMMENT_TOO_LONG' => 'Komentář je příliš dlouhý.',
            default => 'Neplatná reklamace.',
        };
    }
}
