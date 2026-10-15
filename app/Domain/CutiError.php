<?php

namespace App\Domain;

use RuntimeException;

final class CutiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $errorCode = 'INVALID', public readonly mixed $details = null)
    {
        parent::__construct($message);
    }

    public static function check(mixed $condition, string $message, int $status = 422, string $code = 'INVALID', mixed $details = null): void
    {
        if (! $condition) {
            throw new self($message, $status, $code, $details);
        }
    }
}
