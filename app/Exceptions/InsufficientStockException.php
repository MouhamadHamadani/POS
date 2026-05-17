<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly float $available,
        public readonly float $requested,
    ) {
        parent::__construct(sprintf(
            'Insufficient stock for "%s": %s available, %s requested.',
            $productName,
            rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($requested, 4, '.', ''), '0'), '.'),
        ));
    }
}
