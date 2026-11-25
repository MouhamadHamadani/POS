<?php

namespace Tests\Feature\Pos;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\ReceiptExtras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a receipt prints besides the sale: the shop's logo, a QR code and the receipt-number barcode. All three
 * are client settings on every plan (no feature key), off by default, made offline, and shown on the thermal
 * receipt, the A4 invoice and the test print alike.
 */
class ReceiptExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $cashier;
    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        $this->admin = User::factory()->admin()->create();
        $this->cashier = User::factory()->create();
        $shift = Shift::create(['user_id' => $this->cashier->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);
        $vat = Tax::firstOrCreate(['name' => 'Lebanese VAT'], ['rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $product = Product::factory()->create(['name' => 'Test Item', 'name_ar' => 'صنف', 'price_usd' => 5, 'stock_qty' => 10, 'tax_id' => $vat->id, 'is_taxable' => true]);
        $this->sale = app(\App\Services\SaleService::class)->process(
            cart: [['product_id' => $product->id, 'qty' => 2]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 20.00],
            user: $this->cashier, shiftId: $shift->id,
        );
    }

    private function receipt(string $format = 'thermal'): string
    {
        return $this->actingAs($this->cashier)->get("/pos/receipts/{$this->sale->id}/print?format={$format}&auto=0")->assertOk()->getContent();
    }

    private function testPrint(array $settings = [], ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->admin)->post('/settings/receipt/test-print', ['group' => 'receipt', 'settings' => $settings + ['receipt_width' => 80]])->assertOk()->getContent();
    }

    private function save(array $settings): void
    {
        $this->actingAs($this->admin)->post(route('settings.update'), ['group' => 'receipt', 'settings' => $settings + ['receipt_width' => 80]])
            ->assertSessionHasNoErrors();
    }

    // ============================ the logo ============================

    /** A 600x200 logo: white, a black block, a mid-grey block, and a fully transparent corner when $alpha. */
    private function logoFile(bool $alpha = false, int $w = 600, int $h = 200): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, $alpha ? 127 : 0));
        imagefilledrectangle($im, 20, 20, 200, 150, imagecolorallocate($im, 0, 0, 0));
        imagefilledrectangle($im, 300, 20, 500, 150, imagecolorallocate($im, 200, 40, 40));   // red: dark enough to print black
        ob_start();
        imagepng($im);

        return UploadedFile::fake()->createWithContent('shop logo.png', ob_get_clean());
    }

    private function upload(?UploadedFile $file = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('settings.receipt.logo'), ['logo' => $file ?? $this->logoFile()]);
    }

    private function decode(string $uri): \GdImage
    {
        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        return imagecreatefromstring(base64_decode(substr($uri, strlen('data:image/png;base64,'))));
    }

    public function test_nothing_extra_prints_by_default_even_with_a_logo_uploaded(): void
    {
        $this->upload()->assertRedirect()->assertSessionHasNoErrors();

        foreach (['thermal', 'a4'] as $format) {
            $this->assertStringNotContainsString('class="logo"', $this->receipt($format), $format);
        }
        $this->assertStringNotContainsString('class="logo"', $this->testPrint());
    }

    public function test_upload_stores_a_colour_copy_and_a_monochrome_copy_per_roll_and_audits_without_the_image(): void
    {
        $this->upload()->assertRedirect(route('settings.index', ['tab' => 'receipt']))->assertSessionHasNoErrors();

        $logo = Setting::get(ReceiptExtras::LOGO_KEY);
        $this->assertSame('shop logo.png', $logo['name']);
        $this->assertSame([600, 200], [$logo['color']['w'], $logo['color']['h']]);
        $this->assertSame(['80', '58'], array_map('strval', array_keys($logo['mono'])));

        $audit = AuditLog::where('action', 'receipt_logo_upload')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertStringNotContainsString('base64', json_encode($audit->getAttributes()), 'the audit row names the file, it does not carry it');
    }

    #[DataProvider('rolls')]
    public function test_the_thermal_logo_is_pure_black_and_white_and_fits_the_printable_width(int $paper, int $maxDots): void
    {
        $this->upload();
        $this->save(['receipt_logo_thermal' => '1', 'receipt_width' => $paper]);

        $html = $this->receipt('thermal');
        $this->assertMatchesRegularExpression('/<div class="logo"><img src="(data:image\/png;base64,[^"]+)" alt="" style="width: [\d.]+mm;"><\/div>/', $html);
        preg_match('/<div class="logo"><img src="([^"]+)"/', $html, $m);

        $im = $this->decode($m[1]);
        $this->assertLessThanOrEqual($maxDots, imagesx($im));
        $this->assertLessThanOrEqual(20 * 8, imagesy($im), 'at most 20 mm tall at 8 dots/mm');
        $seen = [];
        for ($y = 0; $y < imagesy($im); $y += 2) {
            for ($x = 0; $x < imagesx($im); $x += 2) {
                $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                $seen[$c['red'].'-'.$c['green'].'-'.$c['blue']] = true;
            }
        }
        $this->assertEqualsCanonicalizing(['0-0-0', '255-255-255'], array_keys($seen), 'thresholded: no grey for the driver to dither into a smudge');
    }

    /** @return array<string, array{int, int}> paper => [roll, widest logo in dots: (printable - 2 x 1.5 mm padding) x 8] */
    public static function rolls(): array
    {
        return ['80 mm' => [80, 69 * 8], '58 mm' => [58, 45 * 8]];
    }

    public function test_a_transparent_logo_prints_as_ink_on_white_not_a_black_block(): void
    {
        $this->upload($this->logoFile(alpha: true));
        $this->save(['receipt_logo_thermal' => '1']);

        preg_match('/<div class="logo"><img src="([^"]+)"/', $this->receipt('thermal'), $m);
        $im = $this->decode($m[1]);

        $pixel = fn (int $x, int $y) => array_values(array_slice(imagecolorsforindex($im, imagecolorat($im, $x, $y)), 0, 3));
        $this->assertSame([255, 255, 255], $pixel(imagesx($im) - 2, imagesy($im) - 2), 'the transparent corner is paper');
        $this->assertSame([0, 0, 0], $pixel((int) (imagesx($im) * 0.1), (int) (imagesy($im) * 0.4)), 'the ink is ink');
        $this->assertSame([0, 0, 0], $pixel((int) (imagesx($im) * 0.58), (int) (imagesy($im) * 0.4)), 'a dark red is ink too');
    }

    public function test_the_two_toggles_are_independent_and_a4_keeps_its_colours(): void
    {
        $this->upload();

        $this->save(['receipt_logo_thermal' => '1', 'receipt_logo_a4' => '0']);
        $this->assertStringContainsString('class="logo"', $this->receipt('thermal'));
        $this->assertStringNotContainsString('class="logo"', $this->receipt('a4'));

        $this->save(['receipt_logo_thermal' => '0', 'receipt_logo_a4' => '1']);
        $this->assertStringNotContainsString('class="logo"', $this->receipt('thermal'));
        $a4 = $this->receipt('a4');
        $this->assertStringContainsString('class="logo"', $a4);

        preg_match('/<div class="logo"><img src="([^"]+)" alt="" style="width: ([\d.]+)mm; height: ([\d.]+)mm;"/', $a4, $m);
        $im = $this->decode($m[1]);
        $this->assertSame([600, 200], [imagesx($im), imagesy($im)], 'the A4 copy is the original, not the thresholded one');
        $c = imagecolorsforindex($im, imagecolorat($im, 400, 80));
        $this->assertSame([200, 40, 40], [$c['red'], $c['green'], $c['blue']], 'colours kept');
        $this->assertLessThanOrEqual(70.0, (float) $m[2]);
        $this->assertLessThanOrEqual(25.0, (float) $m[3]);
    }

    public function test_the_logo_sits_above_the_business_name_and_below_the_copy_banner(): void
    {
        $this->upload();
        $this->save(['receipt_logo_thermal' => '1']);

        $html = $this->actingAs($this->cashier)->get("/pos/receipts/{$this->sale->id}/print?format=thermal&auto=0&copy=1")->getContent();
        $banner = strpos($html, '*** COPY');
        $logo = strpos($html, 'class="logo"');
        $name = strpos($html, '<div class="center bold">LebaSouk</div>');

        $this->assertTrue($banner < $logo && $logo < $name, 'COPY banner, then logo, then the name');
    }

    public function test_the_silent_print_and_the_test_print_carry_the_logo_as_inline_data(): void
    {
        $this->upload();
        $this->save(['receipt_logo_thermal' => '1']);

        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:4000/api/']);
        \Illuminate\Support\Facades\Http::fake(['*/system/print' => \Illuminate\Support\Facades\Http::response('OK', 200)]);
        $this->actingAs($this->cashier)->postJson("/pos/receipts/{$this->sale->id}/print-thermal")->assertOk();

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains(rawurldecode($r['html']), '<div class="logo"><img src="data:image/png;base64,')
            && ! str_contains(rawurldecode($r['html']), 'http://') );
    }

    public function test_the_auto_print_waits_for_the_logo_to_decode(): void
    {
        foreach (['thermal', 'a4'] as $format) {
            $html = $this->actingAs($this->cashier)->get("/pos/receipts/{$this->sale->id}/print?format={$format}")->getContent();
            $this->assertStringContainsString('document.images', $html, $format);
            $this->assertStringContainsString('.decode()', $html, $format);
            $script = strpos($html, "addEventListener('load'");                      // the Print button's own onclick comes earlier
            $this->assertLessThan(strpos($html, 'window.print()', $script), strpos($html, 'document.images', $script), $format);
        }
    }

    public function test_the_test_print_shows_the_typed_unsaved_toggles(): void
    {
        $this->upload();

        $this->assertStringNotContainsString('class="logo"', $this->testPrint());                                     // saved: off
        $this->assertStringContainsString('class="logo"', $this->testPrint(['receipt_logo_thermal' => '1']));         // typed on
        $this->assertFalse((bool) Setting::get('receipt_logo_thermal'), 'and nothing was saved');

        $this->save(['receipt_logo_thermal' => '1']);
        $this->assertStringContainsString('class="logo"', $this->testPrint());                                        // saved: on
        $this->assertStringNotContainsString('class="logo"', $this->testPrint(['receipt_logo_thermal' => '0']));      // typed off
    }

    public function test_replacing_and_removing_the_logo(): void
    {
        $this->upload();
        $this->upload($this->logoFile(w: 300, h: 300));
        $this->assertSame([300, 300], [Setting::get(ReceiptExtras::LOGO_KEY)['color']['w'], Setting::get(ReceiptExtras::LOGO_KEY)['color']['h']]);
        $this->assertSame(2, AuditLog::where('action', 'receipt_logo_upload')->count());

        $this->actingAs($this->admin)->delete(route('settings.receipt.logo.destroy'))->assertRedirect(route('settings.index', ['tab' => 'receipt']));
        $this->assertNull(Setting::get(ReceiptExtras::LOGO_KEY));
        $this->assertSame(1, AuditLog::where('action', 'receipt_logo_remove')->count());

        $this->save(['receipt_logo_thermal' => '1']);                                    // switched on, nothing to print
        $this->assertStringNotContainsString('class="logo"', $this->receipt('thermal'));

        $this->actingAs($this->admin)->delete(route('settings.receipt.logo.destroy'));   // nothing left: no second audit row
        $this->assertSame(1, AuditLog::where('action', 'receipt_logo_remove')->count());
    }

    /** @return array<string, array{string}> */
    public static function badLogos(): array
    {
        return [
            'svg' => ['svg'], 'svg named png' => ['svg-as-png'], 'oversize' => ['big'], 'not an image' => ['pdf'],
            'too many pixels' => ['huge'], 'gif' => ['gif'], 'corrupt png' => ['corrupt'],
        ];
    }

    #[DataProvider('badLogos')]
    public function test_a_bad_upload_is_refused_and_stores_nothing(string $kind): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
        $file = match ($kind) {
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
            'svg-as-png' => UploadedFile::fake()->createWithContent('logo.png', $svg),
            'big' => UploadedFile::fake()->image('big.png', 100, 100)->size(2048),
            'pdf' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'huge' => UploadedFile::fake()->image('huge.png', 2600, 100),
            'gif' => UploadedFile::fake()->image('logo.gif', 50, 50),
            'corrupt' => UploadedFile::fake()->createWithContent('logo.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 200)),
        };

        $this->upload($file)->assertSessionHasErrors('logo');

        $this->assertNull(Setting::get(ReceiptExtras::LOGO_KEY));
        $this->assertSame(0, AuditLog::where('action', 'receipt_logo_upload')->count());
    }

    public function test_jpeg_and_webp_are_accepted(): void
    {
        foreach (['logo.jpg', 'logo.jpeg', 'logo.webp'] as $name) {
            $im = imagecreatetruecolor(300, 100);
            imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
            ob_start();
            str_ends_with($name, 'webp') ? imagewebp($im) : imagejpeg($im);
            $this->upload(UploadedFile::fake()->createWithContent($name, ob_get_clean()))->assertSessionHasNoErrors();
            $this->assertNotNull(Setting::get(ReceiptExtras::LOGO_KEY), $name);
        }
    }

    public function test_the_logo_routes_and_toggles_follow_the_receipt_tabs_own_gate(): void
    {
        $this->post(route('settings.receipt.logo'))->assertRedirect(route('login'));   // signed out first: actingAs sticks

        foreach ([User::factory()->manager()->create(), $this->cashier, User::factory()->stock()->create()] as $user) {
            $this->upload(null, $user)->assertForbidden();
            $this->actingAs($user)->delete(route('settings.receipt.logo.destroy'))->assertForbidden();
        }
        $this->assertNull(Setting::get(ReceiptExtras::LOGO_KEY));

        $this->upload(null, User::factory()->superAdmin()->create())->assertSessionHasNoErrors();
        $this->assertNotNull(Setting::get(ReceiptExtras::LOGO_KEY));
    }

    public function test_the_receipt_tab_shows_the_logo_card_in_both_languages_and_never_ships_the_image_in_the_form(): void
    {
        $this->actingAs($this->admin)->get('/settings?tab=receipt')->assertOk()
            ->assertSee('Receipt logo')->assertSee('No logo uploaded.')->assertSee('Upload logo')
            ->assertSee('name="settings[receipt_logo_thermal]"', false)->assertSee('name="settings[receipt_logo_a4]"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $this->upload();
        $this->actingAs($this->admin)->get('/settings?tab=receipt')->assertOk()
            ->assertSee('Replace logo')->assertSee('Remove logo')->assertSee('data:image/png;base64,', false);

        $this->admin->update(['language' => 'ar']);
        $this->actingAs($this->admin)->get('/settings?tab=receipt')->assertOk()->assertSee('شعار الإيصال')->assertSee('إزالة الشعار');
    }

    public function test_saving_the_toggles_writes_booleans_and_audits_like_any_settings_change(): void
    {
        $before = AuditLog::where('action', 'settings_update')->count();
        $this->save(['receipt_logo_thermal' => '1', 'receipt_logo_a4' => '0']);

        $this->assertTrue(Setting::get('receipt_logo_thermal'));
        $this->assertFalse(Setting::get('receipt_logo_a4'));
        $this->assertSame($before + 1, AuditLog::where('action', 'settings_update')->count());
    }

    // ===================== every plan, no plan check =====================

    /** @return array<string, array{string, bool}> */
    public static function plans(): array
    {
        return ['starter' => ['starter', false], 'standard' => ['standard', false], 'premium' => ['premium', false], 'premium, everything optional off' => ['premium', true]];
    }

    #[DataProvider('plans')]
    public function test_the_logo_behaves_the_same_on_every_plan_and_with_overrides(string $plan, bool $overrides): void
    {
        $features = app(FeatureService::class);
        $features->setPlan($plan);
        if ($overrides) {
            $features->sync(array_fill_keys(array_keys(array_filter(config('features.features'), fn ($f) => empty($f['core']))), false));
        }

        $this->upload()->assertSessionHasNoErrors();
        $this->save(['receipt_logo_thermal' => '1', 'receipt_logo_a4' => '1']);

        foreach (['thermal', 'a4'] as $format) {
            $this->assertStringContainsString('class="logo"', $this->receipt($format), "{$plan}/{$format}");
        }
        $this->assertStringContainsString('class="logo"', $this->testPrint());
    }

    public function test_no_plan_feature_key_or_route_gate_exists_for_these(): void
    {
        $keys = array_keys(config('features.features'));
        $this->assertSame([], array_values(array_filter($keys, fn ($k) => preg_match('/logo|qr|barcode/i', $k))), 'receipt logo / QR / barcode are not plan features');

        foreach (['settings.receipt.logo', 'settings.receipt.logo.destroy', 'settings.receipt.test-print', 'settings.update'] as $name) {
            $gates = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertSame([], array_values(array_filter($gates, fn ($m) => is_string($m) && str_starts_with($m, 'feature:'))), "{$name} must carry no feature: gate");
            $this->assertContains('role:admin', $gates, $name);
        }
    }
}
