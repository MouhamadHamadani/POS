<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every balance that existed before the debt book becomes one `opening` ledger entry, so the ledger already adds up
 * to customers.balance on day one. No per-receipt history is rebuilt: for a credit sale made before the book there
 * is no reliable record of what was paid against it, so such a debt lives in the opening pot (sale_id NULL), not on
 * its receipt.
 *
 * Idempotent: only a customer with a non-zero balance AND no ledger entry at all is touched, so running it again
 * (or on a database where the book is already in use) changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_ledger_entries')) {
            return;
        }

        $now = now();

        DB::table('customers')
            ->where('balance', '<>', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('customer_ledger_entries')
                ->whereColumn('customer_ledger_entries.customer_id', 'customers.id'))
            ->orderBy('id')
            ->select('id', 'balance')
            ->chunkById(200, function ($customers) use ($now) {
                DB::table('customer_ledger_entries')->insert($customers->map(fn ($c) => [
                    'customer_id' => $c->id,
                    'type' => 'opening',
                    'amount_usd' => round((float) $c->balance, 4),
                    'entry_date' => $now,
                    'notes' => 'Opening balance (before debt book)',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_ledger_entries')) {
            DB::table('customer_ledger_entries')->where('type', 'opening')
                ->where('notes', 'Opening balance (before debt book)')->delete();
        }
    }
};
