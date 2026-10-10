<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Contact;

final class ContactValidationException extends \RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        public readonly array $fields,
        string $message = 'Kontakt nejde uložit.',
    ) {
        parent::__construct($message);
    }
}
