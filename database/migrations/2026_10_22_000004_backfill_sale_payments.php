<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing sale its payment lines, built from the flat columns it already has.
 *
 * Idempotent: a sale that already has any line is skipped, so a second run writes nothing.
 * Chunked by id so a till with years of sales never loads them all at once. Every sale
 * gets its lines whatever its status (voided included), so History and the reports read
 * the same way for old and new data. The logic is written out here on purpose, not borrowed
 * from the models: a migration must keep meaning what it meant the day it was written.
 *
 * Lines, from the legacy columns:
 *   cash USD  <- amount_tendered_usd          (what was tendered; change stays on the sale)
 *   cash LBP  <- amount_tendered_lbp          (USD value at the sale's own exchange_rate)
 *   card      <- amount_card_usd              (+ card_type, card_reference)
 *   credit    <- amount_credit_usd            (on account)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales')
            ->select('id', 'exchange_rate', 'amount_tendered_usd', 'amount_tendered_lbp', 'amount_card_usd', 'amount_credit_usd', 'card_type', 'card_reference', 'created_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sale_payments')->whereColumn('sale_payments.sale_id', 'sales.id'))
            ->chunkById(500, function ($sales) {
                $rows = [];

                foreach ($sales as $sale) {
                    $now = $sale->created_at ?? now();
                    $line = fn (string $method, string $currency, float $amount, float $usd, ?string $ref = null, ?string $type = null) => [
                        'sale_id' => $sale->id, 'method' => $method, 'currency' => $currency,
                        'amount' => $amount, 'amount_usd' => $usd, 'reference' => $ref, 'card_type' => $type,
                        'created_at' => $now, 'updated_at' => $now,
                    ];

                    if (($usd = (float) $sale->amount_tendered_usd) > 0) {
                        $rows[] = $line('cash', 'USD', $usd, $usd);
                    }
                    if (($lbp = (float) $sale->amount_tendered_lbp) > 0) {
                        $rate = (float) $sale->exchange_rate;
                        $rows[] = $line('cash', 'LBP', $lbp, $rate > 0 ? round($lbp / $rate, 4) : 0.0);
                    }
                    if (($card = (float) $sale->amount_card_usd) > 0) {
                        $rows[] = $line('card', 'USD', $card, $card, $sale->card_reference, $sale->card_type);
                    }
                    if (($credit = (float) $sale->amount_credit_usd) > 0) {
                        $rows[] = $line('credit', 'USD', $credit, $credit);
                    }
                }

                foreach (array_chunk($rows, 200) as $batch) {
                    DB::table('sale_payments')->insert($batch);
                }
            });
    }

    /** The lines are derived data; rolling back leaves them (the table's own down() removes it). */
    public function down(): void
    {
        //
    }
};
