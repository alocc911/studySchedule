<?php

namespace App\Services;

use RuntimeException;

final class SchedulingException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'Unable to complete the scheduling operation.'
    ) {
        parent::__construct($message);
    }
}
