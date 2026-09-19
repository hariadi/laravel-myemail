<?php

namespace Hariadi\MyEmail\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A request the API rejected and that will not succeed if retried unchanged,
 * such as a validation failure or a revoked key.
 */
class MyEmailRequestException extends TransportException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly string $errorCode = 'unknown',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
