<?php

namespace App\Exceptions;

use App\Support\Utf8;
use RuntimeException;

class SapRequestException extends RuntimeException
{
    public readonly array $requestBody;

    public readonly ?string $responseBody;

    public readonly ?int $statusCode;

    public function __construct(
        string $message,
        array $requestBody = [],
        ?string $responseBody = null,
        ?int $statusCode = null,
    ) {
        $this->requestBody = Utf8::sanitize($requestBody);
        $this->responseBody = is_string($responseBody) ? Utf8::sanitizeString($responseBody) : null;
        $this->statusCode = $statusCode;

        parent::__construct(Utf8::sanitizeString($message));
    }
}
