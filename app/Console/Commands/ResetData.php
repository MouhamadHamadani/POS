<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Returns a database to the state a freshly-installed client machine should be
 * in: no accounts, no catalogue, no history — but still configured, so the till
 * knows its VAT rate, exchange rate and LBP rounding step.
 *
 * With no accounts left, the next launch lands on /setup.
 */
class ResetData extends Command
{
    protected $signature = 'pos:reset-data
                            {--keep-users : Leave user accounts in place (local dev, not for a delivery build)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe demo and transactional data, keeping settings, taxes and currencies';

    /**
     * Child-first: anything holding a foreign key comes before what it points at.
     * Tables absent from a given schema are skipped, so this survives the
     * migration set moving on without it.
     */
    private const TRANSACTIONAL = [
        'sale_items',
        'sales',
        'return_items',
        'returns',
        'purchase_order_items',
        'purchase_orders',
        'held_sales',
        'stock_movements',
        'shifts',
        'audit_logs',
    ];

    private const CATALOGUE = [
        'products',
        'categories',
        'customers',
        'suppliers',
    ];

    /** Left standing — without these the till cannot price anything correctly. */
    private const KEPT = ['settings', 'taxes', 'currencies', 'print_templates', 'migrations'];

    public function handle(): int
    {
        $tables = array_merge(self::TRANSACTIONAL, self::CATALOGUE);

        if (! $this->option('keep-users')) {
            $tables[] = 'users';
        }

        $present = array_values(array_filter($tables, fn ($t) => Schema::hasTable($t)));
        $counts = [];
        foreach ($present as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        $this->newLine();
        $this->line('Will clear: ' . implode(', ', $present));
        $this->line('Will keep:  ' . implode(', ', self::KEPT));
        $this->newLine();

        $total = array_sum($counts);
        foreach (array_filter($counts) as $table => $count) {
            $this->line(sprintf('  %-24s %d row%s', $table, $count, $count === 1 ? '' : 's'));
        }
        $this->line(sprintf('  %-24s %d', 'TOTAL', $total));
        $this->newLine();

        if ($total === 0) {
            $this->info('Nothing to clear — already in a clean state.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently delete these {$total} rows?", false)) {
            $this->warn('Aborted. Nothing was deleted.');

            return self::FAILURE;
        }

        // SQLite enforces FKs per-connection; disabling them lets the delete run
        // in any order and keeps this correct if the table list drifts.
        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($present) {
                foreach ($present as $table) {
                    DB::table($table)->delete();
                    // Reset AUTOINCREMENT so a delivered machine starts at id 1.
                    if (Schema::hasTable('sqlite_sequence')) {
                        DB::table('sqlite_sequence')->where('name', $table)->delete();
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->info("Cleared {$total} rows.");

        if (! $this->option('keep-users')) {
            $this->line('No accounts remain — the next launch will open the setup screen.');
        }

        return self::SUCCESS;
    }
}
