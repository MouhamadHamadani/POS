<?php

namespace App\Console\Commands;

use App\Services\RecurringExpenseService;
use Illuminate\Console\Command;

/**
 * Books the recurring expenses that have come due. Scheduled daily (routes/console.php);
 * the desktop app also runs the same call at launch and at every sign-in, because under
 * NativePHP the scheduler loop is opt-in (see PatchNativePhpScheduler) and the till is
 * often closed on the due day.
 */
class GenerateRecurringExpenses extends Command
{
    protected $signature = 'pos:generate-recurring-expenses';

    protected $description = 'Book every recurring expense that has come due (idempotent)';

    public function handle(RecurringExpenseService $recurring): int
    {
        $result = $recurring->generateDue(source: RecurringExpenseService::SOURCE_SCHEDULE);

        $this->info($result['expenses'] === 0
            ? 'No recurring expenses were due.'
            : "Booked {$result['expenses']} expense(s) from {$result['templates']} recurring template(s).");

        return self::SUCCESS;
    }
}
