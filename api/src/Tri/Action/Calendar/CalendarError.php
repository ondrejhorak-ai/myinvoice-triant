<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Calendar;

final class CalendarError
{
    public static function message(string $code): string
    {
        return match ($code) {
            'INVALID_CALENDAR' => 'Neplatný kalendář.',
            'TITLE_REQUIRED' => 'Název události je povinný.',
            'TITLE_TOO_LONG' => 'Název události je příliš dlouhý.',
            'STATION_REQUIRED' => 'Stanice je povinná u kalendáře výroby.',
            'INVALID_STATUS' => 'Neplatný stav události.',
            'INVALID_JOB' => 'Neplatná zakázka.',
            'INVALID_RANGE' => 'Konec musí být po začátku.',
            'STARTS_REQUIRED' => 'Začátek je povinný.',
            'INVALID_DATETIME' => 'Neplatné datum nebo čas.',
            default => 'Neplatná událost kalendáře.',
        };
    }
}
