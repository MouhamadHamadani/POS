<?php

namespace Tests\Feature\BugHunt;

use App\Models\Shift;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-013: counting one currency by denomination locks the other currency's cash field at 0.
 *
 * Both open-shift and close-shift forms bind every total field to
 * `x-bind:readonly="hasAnyDenom()"` and `x-bind:value="<own>Total() || <old>"`, where
 * hasAnyDenom() is "any USD or any LBP denomination entered". A cashier who counts the
 * dollar notes by denomination (the usual way) finds the LBP total field read-only and
 * showing 0 — they cannot type their LBP total — and the server then records
 * closing_cash_lbp = 0, i.e. a variance of minus the whole expected LBP float.
 * (The server only trusts denominations for a currency whose denominations were sent.)
 *
 * The same lock applies the other way round. Each field should lock only on its own
 * currency's denominations.
 */
class Bug013ShiftCashFieldLockedByOtherCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function fields(): array
    {
        return [
            'close usd' => ['close', 'closing_cash_usd', 'lbpTotal'],
            'close lbp' => ['close', 'closing_cash_lbp', 'usdTotal'],
            'open usd' => ['open', 'opening_cash_usd', 'lbpTotal'],
            'open lbp' => ['open', 'opening_cash_lbp', 'usdTotal'],
        ];
    }

    #[DataProvider('fields')]
    public function test_a_cash_total_field_only_locks_for_its_own_currencys_denominations(string $page, string $field, string $otherCurrencyTotal): void
    {
        $user = User::factory()->create();
        if ($page === 'close') {
            Shift::create([
                'user_id' => $user->id, 'opened_at' => now(),
                'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
            ]);
        }

        $html = $this->actingAs($user)->get($page === 'close' ? route('shifts.close') : route('shifts.open'))
            ->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $input = (new DOMXPath($dom))->query("//input[@name='{$field}']")->item(0);
        $this->assertNotNull($input, "No <input name=\"{$field}\"> on the {$page}-shift page.");

        $readonlyExpr = $input->getAttribute('x-bind:readonly');
        $this->assertStringNotContainsString('hasAnyDenom', $readonlyExpr, "{$field} is made read-only by ANY denomination ({$readonlyExpr}), including the other currency's.");
        $this->assertStringNotContainsString($otherCurrencyTotal, $readonlyExpr);
    }
}
