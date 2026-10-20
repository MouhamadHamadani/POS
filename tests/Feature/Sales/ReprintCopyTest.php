<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\PrintTemplate;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `?copy=1` is what every Sales History link sends: the page is stamped COPY / نسخة
 * and the reprint is audited. The sell screen's own print never sends it.
 */
class ReprintCopyTest extends TestCase
{
    use MakesSales, RefreshDatabase;

    private function sampleSale(User $cashier): Sale
    {
        $sale = $this->sale($cashier, ['receipt_number' => 'REC-C-1']);
        $this->item($sale);

        return $sale;
    }

    public function test_the_sell_screens_own_print_is_unmarked_and_unaudited(): void
    {
        $alice = $this->cashier();
        $sale = $this->sampleSale($alice);

        // Exactly the URLs the sell screen opens.
        foreach (['thermal', 'a4'] as $format) {
            $this->actingAs($alice)->get("/pos/receipts/{$sale->id}/print?format={$format}&auto=1")
                ->assertOk()->assertSee('REC-C-1')
                ->assertDontSee('COPY')->assertDontSee('نسخة');
        }
        $this->actingAs($alice)->postJson("/pos/receipts/{$sale->id}/print-a4")->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'receipt_reprint')->count());
    }

    public function test_a_thermal_copy_carries_the_bilingual_notice_and_is_audited_once(): void
    {
        $manager = User::factory()->manager()->create();
        $sale = $this->sampleSale($this->cashier());

        $this->actingAs($manager)->get("/pos/receipts/{$sale->id}/print?format=thermal&auto=1&copy=1&src=history")
            ->assertOk()
            ->assertSee('COPY')->assertSee('نسخة')->assertSee('REC-C-1');

        $log = AuditLog::where('action', 'receipt_reprint')->sole();
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame($sale->id, $log->model_id);
        $this->assertSame(['receipt' => 'REC-C-1', 'format' => 'thermal', 'source' => 'history'], $log->new_values);
    }

    public function test_the_notice_is_the_same_whatever_language_the_user_reads_the_app_in(): void
    {
        $sale = $this->sampleSale($this->cashier());

        foreach (['en', 'ar'] as $lang) {
            $user = User::factory()->manager()->create(['language' => $lang]);
            foreach (['thermal', 'a4'] as $format) {
                $this->actingAs($user)->get("/pos/receipts/{$sale->id}/print?format={$format}&auto=0&copy=1")
                    ->assertOk()->assertSee('COPY')->assertSee('نسخة');
            }
        }
    }

    public function test_an_a4_copy_is_audited_with_its_format_and_source(): void
    {
        $manager = User::factory()->manager()->create();
        $sale = $this->sampleSale($this->cashier());

        $this->actingAs($manager)->get("/pos/receipts/{$sale->id}/print?format=a4&auto=0&copy=1&src=detail")->assertOk();
        $this->actingAs($manager)->get("/pos/receipts/{$sale->id}/print?format=a4&auto=0&copy=1")->assertOk();

        $this->assertSame(
            [['format' => 'a4', 'source' => 'detail'], ['format' => 'a4', 'source' => 'direct']],
            AuditLog::where('action', 'receipt_reprint')->orderBy('id')->get()
                ->map(fn ($l) => ['format' => $l->new_values['format'], 'source' => $l->new_values['source']])->all(),
        );
    }

    public function test_a_silent_a4_print_that_did_not_happen_is_not_logged_but_the_fallback_page_is(): void
    {
        $alice = $this->cashier();
        $sale = $this->sampleSale($alice);

        // Outside the desktop shell there is no A4 printer to reach: printed:false, nothing logged...
        $this->actingAs($alice)->postJson("/pos/receipts/{$sale->id}/print-a4?copy=1&src=history")
            ->assertOk()->assertJson(['printed' => false]);
        $this->assertSame(0, AuditLog::where('action', 'receipt_reprint')->count());

        // ...and the dialog fallback the page then opens is the one that logs it.
        $this->actingAs($alice)->get("/pos/receipts/{$sale->id}/print?format=a4&auto=1&copy=1&src=history")->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'receipt_reprint')->count());
    }

    public function test_a_custom_receipt_template_is_stamped_too(): void
    {
        PrintTemplate::create([
            'name' => 'Mine', 'type' => 'receipt', 'is_active' => true, 'is_default' => true,
            'template_html' => '<html><body><p>{{ receipt_number }}</p></body></html>',
        ]);
        $alice = $this->cashier();
        $sale = $this->sampleSale($alice);

        $this->actingAs($alice)->get("/pos/receipts/{$sale->id}/print?auto=0")
            ->assertOk()->assertSee('REC-C-1')->assertDontSee('COPY');
        $this->actingAs($alice)->get("/pos/receipts/{$sale->id}/print?auto=0&copy=1")
            ->assertOk()->assertSee('COPY')->assertSee('نسخة');
    }

    public function test_a_cashier_cannot_reprint_someone_elses_sale_and_nothing_is_logged(): void
    {
        $sale = $this->sampleSale($this->cashier());

        $this->actingAs($this->cashier())->get("/pos/receipts/{$sale->id}/print?copy=1")->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'receipt_reprint')->count());
    }

    public function test_closing_the_receipt_page_never_calls_a_bare_window_close(): void
    {
        $alice = $this->cashier();
        $sale = $this->sampleSale($alice);

        foreach (['thermal', 'a4'] as $format) {
            $html = $this->actingAs($alice)->get("/pos/receipts/{$sale->id}/print?format={$format}&auto=1&copy=1")->getContent();
            $this->assertStringNotContainsString('onclick="window.close()"', $html);
            $this->assertStringContainsString('window.opener', $html);
        }
    }
}
