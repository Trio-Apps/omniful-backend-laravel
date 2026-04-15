<?php

namespace App\Exceptions;

use RuntimeException;

class SapRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $requestBody = [],
        public readonly ?string $responseBody = null,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct($message);
    }
}
