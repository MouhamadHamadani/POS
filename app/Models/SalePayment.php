<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of how a sale was paid. See the create_sale_payments_table migration for what
 * each column means and why the flat columns on `sales` still exist.
 *
 * Card and Wish are bookkeeping only: the cashier takes the money on the card machine or in the
 * Wish app, and nothing here talks to either.
 */
class SalePayment extends Model
{
    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';
    public const METHOD_WISH = 'wish';
    public const METHOD_CREDIT = 'credit';

    public const METHODS = [self::METHOD_CASH, self::METHOD_CARD, self::METHOD_WISH, self::METHOD_CREDIT];

    protected $fillable = ['sale_id', 'method', 'currency', 'amount', 'amount_usd', 'reference', 'card_type'];

    protected $casts = [
        'amount' => 'decimal:4',
        'amount_usd' => 'decimal:4',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * The one shape a line is displayed in, whether it came from a row or from the legacy columns.
     * `kind` is the label key: cash_usd | cash_lbp | card | wish | credit (enums.payment_method.*).
     *
     * @return array{method:string,currency:string,amount:float,amount_usd:float,reference:?string,card_type:?string,kind:string}
     */
    public static function line(string $method, string $currency, float $amount, float $amountUsd, ?string $reference = null, ?string $cardType = null): array
    {
        return [
            'method' => $method,
            'currency' => $currency,
            'amount' => $amount,
            'amount_usd' => $amountUsd,
            'reference' => $reference,
            'card_type' => $cardType,
            'kind' => $method === self::METHOD_CASH ? 'cash_'.strtolower($currency) : $method,
        ];
    }

    /** @return array{method:string,currency:string,amount:float,amount_usd:float,reference:?string,card_type:?string,kind:string} */
    public function toLine(): array
    {
        return self::line($this->method, $this->currency, (float) $this->amount, (float) $this->amount_usd, $this->reference, $this->card_type);
    }

    /**
     * Lines for a sale that has none (older data, or one built straight from the flat columns):
     * the same lines the backfill migration wrote for the sales it found.
     *
     * @return list<array{method:string,currency:string,amount:float,amount_usd:float,reference:?string,card_type:?string,kind:string}>
     */
    public static function legacyLines(Sale $sale): array
    {
        $lines = [];

        if (($usd = (float) $sale->amount_tendered_usd) > 0) {
            $lines[] = self::line(self::METHOD_CASH, 'USD', $usd, $usd);
        }
        if (($lbp = (float) $sale->amount_tendered_lbp) > 0) {
            $rate = (float) $sale->exchange_rate;
            $lines[] = self::line(self::METHOD_CASH, 'LBP', $lbp, $rate > 0 ? round($lbp / $rate, 4) : 0.0);
        }
        if (($card = (float) $sale->amount_card_usd) > 0) {
            $lines[] = self::line(self::METHOD_CARD, 'USD', $card, $card, $sale->card_reference, $sale->card_type);
        }
        if (($credit = (float) $sale->amount_credit_usd) > 0) {
            $lines[] = self::line(self::METHOD_CREDIT, 'USD', $credit, $credit);
        }

        return $lines;
    }
}
