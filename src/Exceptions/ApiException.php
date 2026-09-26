<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Exceptions;

/** laya-serve answered with an error status. The message is the server's `detail`. */
abstract class ApiException extends \RuntimeException implements LayaException
{
    public function __construct(string $message, public readonly int $status, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
