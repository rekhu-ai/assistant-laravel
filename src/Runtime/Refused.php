<?php

namespace Rekhu\Assistant\Runtime;

use RuntimeException;

/**
 * Rekhu refused a model call. The type says whose problem it is: "tenant_limit" (the developer paused this customer, or
 * it reached its monthly limit) or "billing_error" (the developer's own Rekhu credit is used up), among others.
 */
class Refused extends RuntimeException
{
    public function __construct(string $message, int $status, public readonly string $type = '')
    {
        parent::__construct($message, $status);
    }
}
