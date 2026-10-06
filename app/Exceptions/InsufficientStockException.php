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
        parent::__construct(__('messages.insufficient_stock', [
            'product' => $productName,
            'available' => rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.'),
            'requested' => rtrim(rtrim(number_format($requested, 4, '.', ''), '0'), '.'),
        ]));
    }
}
