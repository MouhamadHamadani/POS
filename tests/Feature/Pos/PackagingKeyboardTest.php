<?php

namespace Tests\Feature\Pos;

use Tests\TestCase;

/**
 * The "Pack or Package?" question is answered from the keyboard on a till whose scanner types like a keyboard. This runs the
 * live packaging mixin of the sell screen (via Node, on a virtual clock) and checks that a person's 1 / 2 / Enter / Esc work,
 * and that a scanned barcode typed into the open question picks nothing. Skipped where Node is not installed.
 * It cannot replace trying a real scanner: see docs/packaging.md.
 */
class PackagingKeyboardTest extends TestCase
{
    private function run_scenarios(array $scenarios): array
    {
        exec('node --version', $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Node is not installed.');
        }

        config(['pos.features.packaging' => true]);
        $js = view('pos._packaging-js')->render();
        $payload = tempnam(sys_get_temp_dir(), 'pk').'.json';
        file_put_contents($payload, json_encode(['js' => $js, 'scenarios' => $scenarios]));

        $out = shell_exec('node '.escapeshellarg(__DIR__.'/packaging_keys.cjs').' < '.escapeshellarg($payload));
        @unlink($payload);
        $this->assertNotNull($out, 'the harness printed nothing');

        return collect(json_decode($out, true, 512, JSON_THROW_ON_ERROR))->keyBy('name')->all();
    }

    /** A scanner: every character of $code 12 ms apart, then Enter, starting $first ms after the modal opened. */
    private function scan(string $code, int $first = 400): array
    {
        $steps = [];
        foreach (str_split($code) as $i => $ch) {
            $steps[] = ['at' => $i === 0 ? $first : 12, 'key' => $ch];
        }
        $steps[] = ['at' => 12, 'key' => 'Enter'];

        return $steps;
    }

    public function test_a_person_answers_with_the_keys_and_a_scanned_code_answers_nothing(): void
    {
        $r = $this->run_scenarios([
            ['name' => 'enter', 'steps' => [['at' => 300, 'key' => 'Enter']]],
            ['name' => 'one', 'steps' => [['at' => 300, 'key' => '1']]],
            ['name' => 'two', 'steps' => [['at' => 300, 'key' => '2']]],
            ['name' => 'three_not_offered', 'steps' => [['at' => 300, 'key' => '3']]],
            ['name' => 'three_offered', 'product' => ['sell_carton_at_pos' => true], 'steps' => [['at' => 300, 'key' => '3']]],
            ['name' => 'escape', 'steps' => [['at' => 300, 'key' => 'Escape']]],
            ['name' => 'the_opening_enter', 'steps' => [['before' => true, 'key' => 'Enter']]],
            ['name' => 'scan_starting_with_2', 'steps' => $this->scan('2000000000017')],
            ['name' => 'scan_starting_with_1', 'steps' => $this->scan('1234567890128')],
            ['name' => 'scan_starting_with_3', 'product' => ['sell_carton_at_pos' => true], 'steps' => $this->scan('3017620422003')],
            ['name' => 'scan_then_a_person', 'steps' => array_merge($this->scan('5449000000996'), [['at' => 500, 'key' => '2']])],
            ['name' => 'two_then_escape', 'steps' => [['at' => 300, 'key' => '2'], ['at' => 20, 'key' => 'Escape']]],
        ]);

        $this->assertSame(['pack', 'package'], $r['enter']['units']);
        $this->assertSame(['pack'], $r['enter']['added']);
        $this->assertFalse($r['enter']['open']);
        $this->assertSame(['pack'], $r['one']['added']);
        $this->assertSame(['package'], $r['two']['added']);
        $this->assertSame([], $r['three_not_offered']['added'], 'cartons are not offered, so 3 does nothing');
        $this->assertTrue($r['three_not_offered']['open']);
        $this->assertSame(['pack', 'package', 'carton'], $r['three_offered']['units']);
        $this->assertSame(['carton'], $r['three_offered']['added']);
        $this->assertSame([], $r['escape']['added']);
        $this->assertFalse($r['escape']['open']);

        $this->assertSame([], $r['the_opening_enter']['added'], 'the Enter that opened the question does not also answer it');
        $this->assertTrue($r['the_opening_enter']['open']);

        foreach (['scan_starting_with_2', 'scan_starting_with_1', 'scan_starting_with_3'] as $name) {
            $this->assertSame([], $r[$name]['added'], "$name: a scanned code never picks a unit");
            $this->assertTrue($r[$name]['open'], "$name: the question is still waiting for the cashier");
        }
        $this->assertSame(['package'], $r['scan_then_a_person']['added'], 'after the burst a real keypress still works, once');
        $this->assertSame([], $r['two_then_escape']['added'], 'Esc inside the wait cancels it');
    }

    public function test_stock_is_shared_by_every_line_of_a_product_in_packs(): void
    {
        $pack = ['product_id' => 1, 'sale_unit' => 'pack', 'unit_multiplier' => 1, 'qty' => 6, 'stock_available' => 100];
        $pkg = ['product_id' => 1, 'sale_unit' => 'package', 'unit_multiplier' => 10, 'qty' => 3, 'stock_available' => 100];
        $other = ['product_id' => 2, 'sale_unit' => 'pack', 'unit_multiplier' => 1, 'qty' => 99, 'stock_available' => 100];

        $r = $this->run_scenarios([
            ['name' => 'package_line', 'math' => ['cart' => [$pack, $pkg, $other], 'line' => $pkg, 'qty' => 5]],
            ['name' => 'pack_line', 'math' => ['cart' => [$pack, $pkg, $other], 'line' => $pack, 'qty' => 6]],
        ]);

        // The package line may grow until 6 packs + 10 per package reach 100: (100 - 6) / 10 = 9 packages.
        $this->assertSame(9, $r['package_line']['max']);
        $this->assertSame(6 + 50, $r['package_line']['base']);
        // The pack line: 100 - 30 packs of packages = 70 packs.
        $this->assertSame(70, $r['pack_line']['max']);
        $this->assertSame(6 + 30, $r['pack_line']['base']);
    }
}
