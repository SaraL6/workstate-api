<?php

namespace App\Exceptions;

use RuntimeException;

class ContractParseException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
