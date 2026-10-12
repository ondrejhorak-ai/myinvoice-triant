<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use Psr\Http\Message\ResponseInterface as Response;

final class InvoiceError
{
    public static function fromException(Response $response, MyUctoApiException $e, bool $hideRecordIds = false): Response
    {
        $status = $e->httpStatus >= 400 ? $e->httpStatus : 502;
        if ($e->errorCode === 'network') {
            $status = 503;
        }
        $message = $hideRecordIds ? self::withoutRecordIds($e->getMessage()) : $e->getMessage();
        $extra = [];
        if ($e->details !== []) {
            $extra['fields'] = $hideRecordIds ? self::withoutRecordIdsInFields($e->details) : $e->details;
        }

        return Json::error($response, $e->errorCode, $message, $status, $extra);
    }

    /**
     * Hláška o cizím projektu a klientovi nesmí ukázat vnitřní čísla.
     */
    public static function withoutRecordIds(string $message): string
    {
        $hasId = preg_match('/#\s*\d+/', $message) === 1;
        $aboutBinding = preg_match('/zakázk|projekt|klient|client|project/ui', $message) === 1;
        if ($hasId && $aboutBinding) {
            return 'Doklad se nepodařilo vystavit na zvolený kontakt. Doplňte adresu, nebo akci zopakujte.';
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function withoutRecordIdsInFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (is_string($value)) {
                $out[$key] = self::withoutRecordIds($value);
                continue;
            }
            if (!is_array($value)) {
                $out[$key] = $value;
                continue;
            }
            $messages = [];
            foreach ($value as $item) {
                $messages[] = is_string($item) ? self::withoutRecordIds($item) : $item;
            }
            $out[$key] = $messages;
        }

        return $out;
    }
}
