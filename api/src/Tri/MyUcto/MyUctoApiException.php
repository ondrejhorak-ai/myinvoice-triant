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
        return in_array($this->errorCode, ['network', 'timeout', 'rate_limited'], true)
            || $this->httpStatus >= 500;
    }
}
