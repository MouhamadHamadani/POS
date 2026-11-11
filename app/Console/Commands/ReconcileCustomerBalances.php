<?php

namespace App\Console\Commands;

use App\Services\CustomerLedgerService;
use Illuminate\Console\Command;

/**
 * customers.balance is a cache of the debt book. This lists every customer whose cache no longer matches the
 * ledger (read-only), and with --fix rewrites the cache from the ledger and audit-logs each repair.
 * Exit status is 1 while any drift remains, so a script can alert on it.
 */
class ReconcileCustomerBalances extends Command
{
    protected $signature = 'customers:reconcile-balances {--fix : Rewrite the cached balance from the ledger}';

    protected $description = 'Report customers whose cached balance differs from their debt-book ledger';

    public function handle(CustomerLedgerService $ledger): int
    {
        $fix = (bool) $this->option('fix');
        $drift = $ledger->drift($fix);

        if ($drift === []) {
            $this->info('Every customer balance matches the ledger.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Customer', 'Cached', 'Ledger'],
            array_map(fn ($d) => [$d['customer']->id, $d['customer']->name, number_format($d['cached'], 2), number_format($d['ledger'], 2)], $drift),
        );

        if ($fix) {
            $this->info(count($drift) . ' balance(s) rewritten from the ledger.');

            return self::SUCCESS;
        }

        $this->warn(count($drift) . ' balance(s) differ from the ledger. Run again with --fix to rewrite them.');

        return self::FAILURE;
    }
}
