<?php

declare(strict_types=1);

namespace MyInvoice\Tri\MyUcto;

final class MyUctoApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function isDisabled(): bool
    {
        return $this->errorCode === 'disabled';
    }

    public function isUnavailable(): bool
    {
        if (in_array($this->errorCode, ['network', 'timeout', 'rate_limited'], true)) {
            return true;
        }

        // Proxy / gateway outages only — application 500s (varsymbol, validation) must surface.
        return in_array($this->httpStatus, [502, 503, 504], true);
    }
}
