<?php

namespace Database\Seeders;

use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\HeldSale;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Services\ShiftService;
use App\Support\FakeData;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A fake business to develop and test against: a few weeks of trading in a Lebanese mini-market, in the shape the
 * app really produces it.
 *
 * It does not insert sales rows. Every sale goes through SaleService, every stock movement through InventoryService,
 * every expense through ExpenseService, every shift is closed against ShiftService::totals(), so what is on screen is
 * consistent the way real data is: stock matches its movements, a closed shift's expected cash matches its sales, the
 * payment lines add up to the totals, loyalty points follow purchases. The clock is faked day by day, so every
 * created_at (sales, movements, audit rows, loyalty) falls on the day it belongs to, and the seed is deterministic:
 * the same seed gives the same shop.
 *
 * Everything is invented and marked as such ("Test" in every name, +961 70 100 xxx phone numbers), and the first
 * thing written is the marker that App\Support\FakeData uses to recognise (and, on a production launch, remove) it.
 * Never chained into a shipped build: see FakeData::problem() for when it refuses to run at all.
 */
class FakeDataSeeder extends Seeder
{
    public const DEFAULT_SEED = 20261022;

    public int $days = 21;

    public int $seed = self::DEFAULT_SEED;

    /** What was made, for the command's summary. @var array<string,int> */
    public array $summary = [];

    private Carbon $realNow;

    private User $admin;
    private User $manager;
    private User $stockKeeper;
    /** @var list<User> */
    private array $cashiers = [];

    /** @var list<Product> */
    private array $catalogue = [];
    private ?Product $delivery = null;
    /** @var array<int,float> product id => stock the seeder believes is on the shelf */
    private array $stock = [];
    /** @var list<Customer> */
    private array $customers = [];

    private SaleService $sales;
    private InventoryService $inventory;
    private ShiftService $shifts;
    private ExpenseService $expenses;
    private CurrencyService $currency;

    // [name, name_ar, category, price, cost, opening stock, taxable, wholesale, vip]
    private const PRODUCTS = [
        ['Cedar Valley Rice 1kg', 'أرز وادي الأرز 1 كغ', 'groceries', 2.40, 1.55, 120, false, 2.10, 2.20],
        ['Byblos Olive Oil 1L', 'زيت زيتون جبيل 1 ل', 'groceries', 9.50, 6.20, 48, true, 8.60, 9.00],
        ['Sunrise Lentils 500g', 'عدس الشروق 500 غ', 'groceries', 1.80, 1.05, 90, false, 1.55, 1.70],
        ['Mount Sannine Salt 1kg', 'ملح جبل صنين 1 كغ', 'groceries', 0.60, 0.25, 160, false, null, null],
        ['Golden Souk Sugar 1kg', 'سكر السوق الذهبي 1 كغ', 'groceries', 1.20, 0.75, 140, false, 1.05, 1.10],
        ['Fresh Eggs (12)', 'بيض طازج (12)', 'groceries', 3.20, 2.30, 60, false, null, null],
        ['Arabic Bread Pack', 'خبز عربي (حزمة)', 'groceries', 1.00, 0.55, 80, false, null, null],
        ['Tomato Paste 400g', 'معجون البندورة 400 غ', 'groceries', 1.65, 0.95, 70, true, 1.45, 1.55],

        ['Cedar Cola 330ml', 'كولا الأرز 330 مل', 'beverages', 1.10, 0.55, 240, true, 0.95, 1.00],
        ['Cedar Cola Zero 330ml', 'كولا الأرز زيرو 330 مل', 'beverages', 1.10, 0.55, 180, true, 0.95, 1.00],
        ['Jeita Spring Water 500ml', 'مياه نبع جعيتا 500 مل', 'beverages', 0.50, 0.18, 400, true, 0.40, 0.45],
        ['Jeita Spring Water 1.5L', 'مياه نبع جعيتا 1.5 ل', 'beverages', 1.00, 0.40, 200, true, 0.80, 0.90],
        ['Bekaa Orange Juice 1L', 'عصير برتقال البقاع 1 ل', 'beverages', 2.75, 1.70, 70, true, 2.30, 2.50],
        ['Beirut Roast Coffee 200g', 'بن بيروت المحمص 200 غ', 'beverages', 5.50, 3.60, 50, true, 4.70, 5.00],
        ['Mint Tea Box (25)', 'شاي نعناع (25)', 'beverages', 2.20, 1.30, 60, true, null, null],
        ['Energy Drink 250ml', 'مشروب طاقة 250 مل', 'beverages', 1.90, 1.10, 100, true, 1.65, 1.75],

        ['Zaatar Crisps 45g', 'رقائق بالزعتر 45 غ', 'snacks', 0.90, 0.42, 220, true, 0.75, 0.80],
        ['Chili Crisps 45g', 'رقائق حارة 45 غ', 'snacks', 0.90, 0.42, 200, true, 0.75, 0.80],
        ['Cedar Chocolate Bar 40g', 'لوح شوكولا الأرز 40 غ', 'snacks', 1.35, 0.78, 160, true, 1.15, 1.25],
        ['Roasted Mixed Nuts 200g', 'مكسرات مشكلة محمصة 200 غ', 'snacks', 6.40, 4.10, 50, true, 5.50, 6.00],
        ['Sesame Barazek 250g', 'برازق بالسمسم 250 غ', 'snacks', 4.20, 2.60, 45, true, null, null],
        ['Date Maamoul Box', 'علبة معمول بالتمر', 'snacks', 5.80, 3.70, 30, true, null, null],

        ['Fresh Milk 1L', 'حليب طازج 1 ل', 'dairy', 1.90, 1.30, 90, false, 1.70, 1.80],
        ['Labneh 400g', 'لبنة 400 غ', 'dairy', 3.40, 2.20, 50, false, 3.00, 3.20],
        ['Halloumi 250g', 'حلوم 250 غ', 'dairy', 4.60, 3.10, 40, false, 4.10, 4.40],
        ['Plain Yogurt 500g', 'لبن زبادي 500 غ', 'dairy', 1.70, 1.05, 70, false, 1.50, 1.60],
        ['Butter 200g', 'زبدة 200 غ', 'dairy', 3.90, 2.70, 35, false, null, null],

        ['Dish Soap 750ml', 'سائل جلي 750 مل', 'cleaning', 2.60, 1.45, 50, true, 2.20, 2.40],
        ['Laundry Powder 3kg', 'مسحوق غسيل 3 كغ', 'cleaning', 8.90, 6.10, 30, true, 7.80, 8.40],
        ['Bleach 1L', 'مبيض 1 ل', 'cleaning', 1.90, 0.95, 60, true, 1.60, 1.75],
        ['Paper Towels (2)', 'مناشف ورقية (2)', 'cleaning', 2.40, 1.40, 70, true, 2.10, 2.25],
        ['Trash Bags (30)', 'أكياس نفايات (30)', 'cleaning', 2.10, 1.15, 55, true, null, null],

        ['Shampoo 400ml', 'شامبو 400 مل', 'personal', 4.80, 3.00, 40, true, 4.10, 4.50],
        ['Toothpaste 100ml', 'معجون أسنان 100 مل', 'personal', 2.50, 1.40, 60, true, 2.15, 2.30],
        ['Hand Soap 500ml', 'صابون يدين 500 مل', 'personal', 3.10, 1.80, 45, true, null, null],
        ['Tissues Box', 'علبة محارم', 'personal', 1.20, 0.60, 100, true, 1.00, 1.10],
    ];

    private const CATEGORIES = [
        ['groceries', 'Groceries', 'بقالة'],
        ['beverages', 'Beverages', 'مشروبات'],
        ['snacks', 'Snacks', 'وجبات خفيفة'],
        ['dairy', 'Dairy', 'ألبان'],
        ['cleaning', 'Cleaning', 'تنظيف'],
        ['personal', 'Personal care', 'عناية شخصية'],
        ['services', 'Services', 'خدمات'],
    ];

    // [name, group, credit limit, loyalty points]
    private const CUSTOMERS = [
        ['Rami Test', Customer::GROUP_RETAIL, 0, 0],
        ['Salma Test', Customer::GROUP_VIP, 300, 2100],
        ['Karim Test', Customer::GROUP_RETAIL, 100, 120],
        ['Nour Test', Customer::GROUP_RETAIL, 0, 640],
        ['Layal Test', Customer::GROUP_RETAIL, 150, 0],
        ['Tarek Test', Customer::GROUP_WHOLESALE, 500, 0],
        ['Dina Test', Customer::GROUP_RETAIL, 0, 30],
        ['Hadi Test', Customer::GROUP_VIP, 300, 760],
        ['Mira Test', Customer::GROUP_RETAIL, 50, 0],
        ['Joe Test', Customer::GROUP_WHOLESALE, 800, 0],
        ['Rita Test', Customer::GROUP_RETAIL, 0, 15],
        ['Fadi Test', Customer::GROUP_WHOLESALE, 600, 0],
    ];

    // [name, name_ar, contact, terms]
    private const SUPPLIERS = [
        ['Cedar Valley Wholesale (Test)', 'وادي الأرز للجملة (تجريبي)', 'Ghassan Test', 'NET30'],
        ['Bekaa Fresh Dairy (Test)', 'ألبان البقاع الطازجة (تجريبي)', 'Maya Test', 'NET15'],
        ['Phoenicia Beverages (Test)', 'مشروبات فينيقيا (تجريبي)', 'Elie Test', 'NET30'],
        ['Mount Lebanon Cleaning Supplies (Test)', 'مواد تنظيف جبل لبنان (تجريبي)', 'Hala Test', 'COD'],
    ];

    private bool $configured = false;

    public function configure(int $days, int $seed): static
    {
        $this->days = max(0, $days);
        $this->seed = $seed;
        $this->configured = true;

        return $this;
    }

    public function run(): void
    {
        if ($problem = FakeData::problem()) {
            throw new RuntimeException($problem);
        }

        // Called from DatabaseSeeder rather than the command: the length of the history is an env setting.
        if (! $this->configured) {
            $this->days = max(0, (int) config('pos.fake_data_days', 21));
        }

        $this->sales = app(SaleService::class);
        $this->inventory = app(InventoryService::class);
        $this->shifts = app(ShiftService::class);
        $this->expenses = app(ExpenseService::class);
        $this->currency = app(CurrencyService::class);

        mt_srand($this->seed);
        $this->realNow = Carbon::now();
        // Hand the clock back as it was found (a test may have frozen it), not merely "unfrozen".
        $before = Carbon::hasTestNow() ? Carbon::getTestNow() : null;
        $start = $this->realNow->copy()->startOfDay()->subDays($this->days);

        // Config defaults first (VAT, currencies, the settings the till reads); the marker straight after, so even a
        // seed that dies halfway is still recognisably fake and can be purged.
        $this->call([DefaultCurrenciesSeeder::class, DefaultTaxSeeder::class, DefaultSettingsSeeder::class]);
        Setting::set(FakeData::MARKER, $this->realNow->toIso8601String(), 'system');

        try {
            Carbon::setTestNow($start->copy()->setTime(7, 30));

            $this->settings($start);
            $this->users();
            $this->catalogue();
            $this->customers();
            $suppliers = $this->suppliers();

            $this->trade($start, $suppliers);
            $this->shelfCheck();
        } finally {
            Carbon::setTestNow($before);
        }

        $this->summarise();
    }

    // --- Set-up -----------------------------------------------------------------------------------------------

    private function settings(Carbon $start): void
    {
        foreach ([
            ['business_name', 'Cedar & Co. Test Market', 'general', 'string'],
            ['business_name_ar', 'سوق الأرز التجريبي', 'general', 'string'],
            ['address', 'Test Street 12, Beirut, Lebanon', 'general', 'string'],
            ['phone', '+961 1 100 100', 'general', 'string'],
            ['email', 'test@fake.lebasouk.local', 'general', 'string'],
            ['tax_number', 'TEST-VAT-0001', 'general', 'string'],
            ['exchange_rate', 89500, 'currency', 'float'],
            ['receipt_header', "Cedar & Co. Test Market\nFake data, not a real shop", 'receipt', 'string'],
            ['receipt_footer', 'Test receipt: fake data.', 'receipt', 'string'],
            // Everything switched on, and never locked by the trial clock while someone is testing.
            ['tier', 'premium', 'license', 'string'],
            ['activation_status', 'activated', 'activation', 'string'],
            ['installed_at', $start->toIso8601String(), 'activation', 'string'],
        ] as [$key, $value, $group, $type]) {
            Setting::set($key, $value, $group, $type);
        }
    }

    private function users(): void
    {
        $password = (string) config('pos.fake_password', 'test1234');
        $make = fn (string $username, string $name, string $role, float $discount, string $language = 'en') => User::create([
            'name' => $name, 'username' => $username, 'email' => $username.'@fake.lebasouk.local',
            'password' => $password, 'role' => $role, 'language' => $language,
            'is_active' => true, 'max_discount_pct' => $discount,
        ]);

        $this->admin = $make('fake_admin', 'Fake Admin', User::ROLE_ADMIN, 100);
        $this->manager = $make('fake_manager', 'Fake Manager', User::ROLE_MANAGER, 50);
        $this->cashiers = [
            $make('fake_cashier', 'Fake Cashier', User::ROLE_CASHIER, 10),
            $make('fake_cashier2', 'Fake Cashier Two', User::ROLE_CASHIER, 5, 'ar'),
        ];
        $this->stockKeeper = $make('fake_stock', 'Fake Stock Keeper', User::ROLE_STOCK, 0);
    }

    private function catalogue(): void
    {
        $vat = \App\Models\Tax::where('is_default', true)->first();

        $categories = [];
        foreach (self::CATEGORIES as $i => [$slug, $name, $nameAr]) {
            $categories[$slug] = Category::create(['slug' => $slug, 'name' => $name, 'name_ar' => $nameAr, 'sort_order' => $i + 1, 'is_active' => true]);
        }

        foreach (self::PRODUCTS as $i => [$name, $nameAr, $cat, $price, $cost, $opening, $taxable, $wholesale, $vip]) {
            $product = Product::create([
                'category_id' => $categories[$cat]->id, 'tax_id' => $taxable ? $vat?->id : null,
                'barcode' => $this->ean13($i + 1), 'sku' => sprintf('FAKE-%04d', $i + 1),
                'name' => $name, 'name_ar' => $nameAr,
                'price_usd' => $price, 'cost_usd' => $cost, 'wholesale_price_usd' => $wholesale, 'vip_price_usd' => $vip,
                'stock_qty' => 0, 'min_stock' => max(10, (int) round($opening * 0.15)), 'unit' => 'pcs',
                'type' => Product::TYPE_SIMPLE, 'is_active' => true, 'is_taxable' => $taxable,
                'allow_discount' => true, 'track_stock' => true, 'created_by' => $this->admin->id,
            ]);
            // Opening stock goes through the movement log, so the history reads 0 -> n like a real product's.
            $this->inventory->recordOpening($product, (float) $opening, $this->stockKeeper->id);
            $this->stock[$product->id] = (float) $opening;
            $this->catalogue[] = $product;
        }

        // A service line for online orders: no stock, so nothing to run out of.
        $this->delivery = Product::create([
            'category_id' => $categories['services']->id, 'tax_id' => null,
            'barcode' => $this->ean13(900), 'sku' => 'FAKE-DELIVERY',
            'name' => 'Home Delivery', 'name_ar' => 'توصيل إلى المنزل',
            'price_usd' => 2.00, 'cost_usd' => 0, 'stock_qty' => 0, 'min_stock' => 0, 'unit' => 'pcs',
            'type' => Product::TYPE_SERVICE, 'is_active' => true, 'is_taxable' => false,
            'allow_discount' => false, 'track_stock' => false, 'created_by' => $this->admin->id,
        ]);
    }

    private function customers(): void
    {
        foreach (self::CUSTOMERS as $i => [$name, $group, $credit, $points]) {
            $this->customers[] = Customer::create([
                'name' => $name, 'phone' => sprintf('+961 70 100 %03d', $i + 1), 'customer_group' => $group,
                'credit_limit' => $credit, 'balance' => 0, 'loyalty_points' => $points,
                'loyalty_tier' => $points >= 2000 ? 'gold' : ($points >= 500 ? 'silver' : 'bronze'),
                'is_active' => true, 'notes' => 'Fake customer: not a real person.',
            ]);
        }
    }

    /** @return list<Supplier> */
    private function suppliers(): array
    {
        return array_map(fn ($row) => Supplier::create([
            'name' => $row[0], 'name_ar' => $row[1], 'contact_person' => $row[2], 'payment_terms' => $row[3],
            'phone' => sprintf('+961 1 200 %03d', mt_rand(100, 999)), 'email' => 'orders@fake-supplier.local',
            'address' => 'Test Industrial Zone, Lebanon', 'balance' => 0, 'is_active' => true,
            'notes' => 'Fake supplier: not a real company.',
        ]), self::SUPPLIERS);
    }

    // --- Trading ----------------------------------------------------------------------------------------------

    /** @param list<Supplier> $suppliers */
    private function trade(Carbon $start, array $suppliers): void
    {
        $opening = ['usd' => 150.0, 'lbp' => 3_000_000.0];
        // Plain arrays, picked with mt_rand: Collection::random() uses a CSPRNG and would make the seed non-repeatable.
        $fixed = ExpenseCategory::where('kind', ExpenseCategory::KIND_FIXED)->orderBy('sort_order')->get()->all();
        $running = ExpenseCategory::where('kind', ExpenseCategory::KIND_RUNNING)->orderBy('sort_order')->get()->all();

        for ($d = $this->days; $d >= 0; $d--) {
            $day = $this->realNow->copy()->startOfDay()->subDays($d);
            $isToday = $d === 0;
            $cashier = $isToday ? $this->cashiers[0] : $this->cashiers[$d % 2];

            [$open, $close] = $this->window($day, $isToday);

            Carbon::setTestNow($open);
            $shift = Shift::create([
                'user_id' => $cashier->id, 'opened_at' => $open,
                'opening_cash_usd' => $opening['usd'], 'opening_cash_lbp' => $opening['lbp'], 'status' => Shift::STATUS_OPEN,
            ]);

            // Stock arrives and gets counted on set days, before the day's trading.
            $this->stockEvents($d, $day, $suppliers);

            foreach ($this->saleTimes($day, $open, $close) as $at) {
                $sale = $this->ring($at, $cashier, $shift);
                if (! $sale) {
                    continue;
                }
                $this->afterSale($sale, $at, $close);
            }

            $this->expensesFor($d, $day, $open, $close, $cashier, $fixed, $running);

            if ($isToday) {
                $this->heldCarts($cashier);
                Carbon::setTestNow($close);

                continue; // today's shift stays open: the till works the moment someone signs in
            }

            $opening = $this->closeShift($shift, $cashier, $close);
        }
    }

    /** @return array{0:Carbon,1:Carbon} when the shift opens and closes. Today ends a couple of minutes ago, never in the future. */
    private function window(Carbon $day, bool $isToday): array
    {
        $open = $day->copy()->setTime(8, mt_rand(0, 25));
        $close = $day->copy()->setTime(20, mt_rand(0, 40));

        if ($isToday) {
            $close = $this->realNow->copy()->subMinutes(2);
            if ($open->greaterThan($close)) {
                // Seeded early in the morning: a short shift that has only just started.
                $open = $close->copy()->subMinutes(20)->max($day->copy()->addMinute());
                $close = $close->max($open->copy()->addMinutes(5))->min($this->realNow);
            }
        }

        return [$open, $close];
    }

    /** @return list<Carbon> */
    private function saleTimes(Carbon $day, Carbon $open, Carbon $close): array
    {
        $minutes = (int) max(0, floor($open->diffInMinutes($close)) - 10);
        $busy = $day->dayOfWeek === Carbon::SUNDAY ? mt_rand(4, 10) : mt_rand(11, 24);
        $count = (int) round($busy * min(1.0, $minutes / 710));

        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $times[] = $open->copy()->addMinutes(5 + mt_rand(0, max(0, $minutes - 5)));
        }
        usort($times, fn (Carbon $a, Carbon $b) => $a <=> $b);

        return $times;
    }

    private function ring(Carbon $at, User $cashier, Shift $shift): ?Sale
    {
        Carbon::setTestNow($at);

        $online = $this->chance(0.15);
        $customer = $this->chance($online ? 0.6 : 0.34) ? $this->customers[array_rand($this->customers)]->refresh() : null;

        $cart = [];
        $used = [];
        $lines = $this->weighted([1 => 30, 2 => 30, 3 => 20, 4 => 12, 5 => 8]);
        for ($i = 0; $i < $lines; $i++) {
            $product = $this->popular();
            $have = (int) floor($this->stock[$product->id] ?? 0);
            if (isset($used[$product->id]) || $have < 1) {
                continue;
            }
            $line = ['product_id' => $product->id, 'qty' => min($have, $this->weighted([1 => 62, 2 => 22, 3 => 10, 6 => 6]))];
            if ($this->chance(0.07)) {
                $line['discount_pct'] = min((float) $cashier->maxDiscountPct(), 5.0 * mt_rand(1, 2));
            }
            $cart[] = $line;
            $used[$product->id] = true;
        }
        if ($cart === []) {
            return null;
        }
        if ($online && $this->chance(0.7)) {
            $cart[] = ['product_id' => $this->delivery->id, 'qty' => 1];
        }

        try {
            $total = (float) $this->sales->totalsFromCart($cart, $customer, $customer?->customer_group ?? Customer::GROUP_RETAIL)['total'];
            $payment = $this->payment($total, $customer);

            $sale = $this->sales->process(
                cart: $cart, payment: $payment, user: $cashier, shiftId: $shift->id,
                customerId: $customer?->id, notes: $online && $this->chance(0.4) ? 'Deliver after 6pm (fake)' : null,
                channel: $online ? Sale::CHANNEL_ONLINE : Sale::CHANNEL_ONSITE,
                channelSource: $online ? $this->weighted(['whatsapp' => 45, 'instagram' => 30, 'facebook' => 10, 'phone' => 15]) : null,
            );
        } catch (InsufficientStockException|ValidationException) {
            return null; // a line the shelf could not cover, or a discount over a cap: the shop simply did not make that sale
        }

        foreach ($cart as $line) {
            if (isset($this->stock[$line['product_id']])) {
                $this->stock[$line['product_id']] -= $line['qty'];
            }
        }

        return $sale;
    }

    /**
     * How this customer pays. Every kind of money the till takes shows up: cash in both currencies, card, Wish,
     * on account, and the splits between them. Cash is tendered in notes (so there is change to give), card and
     * Wish to the cent like the till's own buttons, and on-account only for a customer whose limit allows it.
     *
     * @return array<string,mixed>
     */
    private function payment(float $total, ?Customer $customer): array
    {
        $cents = round($total, 2);
        $rate = $this->currency->getRate();
        // The note the customer hands over: to the cent, or up to the next 1 / 5 / 10 / 20 / 50. (String keys: a float
        // array key would be truncated to an int and 0.01 would become 0.)
        $tender = function (float $due): float {
            $step = (float) $this->weighted(['0.01' => 30, '1' => 20, '5' => 25, '10' => 15, '20' => 7, '50' => 3]);

            return round(ceil(($due - 1e-9) / $step) * $step, 2);
        };
        $card = fn () => ['amount_card' => $cents, 'card_type' => $this->weighted(['Visa' => 55, 'MasterCard' => 35, 'Amex' => 10]), 'card_reference' => 'AP'.mt_rand(100000, 999999)];
        $wish = fn (float $amount) => ['amount_wish' => $amount, 'wish_reference' => 'WSH-'.mt_rand(10000000, 99999999)];

        $kind = $this->weighted(['cash' => 36, 'lbp' => 8, 'mixed' => 7, 'card' => 17, 'wish' => 7, 'cash_wish' => 9, 'cash_card' => 5, 'card_wish' => 2, 'credit' => 4, 'cash_credit' => 3]);

        // On account needs a customer with room; otherwise the customer simply pays cash.
        if (str_contains($kind, 'credit') && (! $customer || ! $customer->hasAvailableCredit(round($total, 4)))) {
            $kind = 'cash';
        }
        // A split needs two real halves.
        if (in_array($kind, ['cash_wish', 'cash_card', 'card_wish', 'cash_credit'], true) && $total < 4) {
            $kind = 'cash';
        }

        $share = round($total * mt_rand(30, 60) / 100, 2);

        return match ($kind) {
            'lbp' => ['amount_lbp' => ceil($total * $rate / 5000) * 5000],
            'mixed' => $total >= 3
                ? ['amount_usd' => floor($total * 0.6), 'amount_lbp' => ceil(($total - floor($total * 0.6)) * $rate / 1000) * 1000]
                : ['amount_usd' => $tender($total)],
            'card' => $card(),
            'wish' => $wish($cents),
            'cash_wish' => $wish($share) + ['amount_usd' => $tender($total - $share)],
            'cash_card' => ['amount_card' => $share, 'card_type' => 'Visa', 'card_reference' => 'AP'.mt_rand(100000, 999999), 'amount_usd' => $tender($total - $share)],
            'card_wish' => ['amount_card' => $share, 'card_type' => 'MasterCard', 'card_reference' => 'AP'.mt_rand(100000, 999999)] + $wish(round($total - $share, 2)),
            'credit' => ['amount_credit' => round($total, 4)],
            'cash_credit' => ['amount_credit' => min(round($total - 1, 4), round($share, 4)), 'amount_usd' => $tender($total - min(round($total - 1, 4), round($share, 4)))],
            default => ['amount_usd' => $tender($total)],
        };
    }

    /** What can happen to a sale a little after it was rung up: nothing, a void, or a return. */
    private function afterSale(Sale $sale, Carbon $at, Carbon $close): void
    {
        $later = $at->copy()->addMinutes(mt_rand(8, 90));
        if ($later->greaterThan($close)) {
            return;
        }

        $roll = mt_rand(1, 100);

        if ($roll <= 2) {
            Carbon::setTestNow($later);
            $this->sales->voidSale($sale, $this->manager->id, 'Rung up twice (fake data)');
            foreach ($sale->items as $item) {
                if (isset($this->stock[$item->product_id])) {
                    $this->stock[$item->product_id] += (float) $item->qty;
                }
            }
        } elseif ($roll <= 6) {
            $this->makeReturn($sale->fresh(['items.product', 'customer', 'payments']), $later);
        }
    }

    /**
     * A return, approved by the manager, one line of the sale (or one unit of it), refunded the way the customer paid.
     * Mirrors what ReturnController does on approval: restock, mark the line, move the sale's status, settle the
     * account for an account refund. Kept here because that logic lives in the controller.
     */
    private function makeReturn(Sale $sale, Carbon $at): void
    {
        Carbon::setTestNow($at);

        $item = $sale->items->first(fn (SaleItem $i) => $i->product && $i->product->type !== Product::TYPE_SERVICE);
        if (! $item) {
            return;
        }

        $qty = (float) $item->qty >= 2 && $this->chance(0.5) ? 1.0 : (float) $item->qty;
        $refund = round((float) $item->line_total_usd / (float) $item->qty * $qty, 4);
        $method = $sale->suggestedRefundMethod();

        $prefix = Setting::get('return_prefix', 'RTN-'.date('Y').'-');
        $counter = (int) Setting::get('return_counter', 0) + 1;
        Setting::set('return_counter', $counter, 'numbering', 'int');

        $return = ReturnTransaction::create([
            'return_number' => $prefix.str_pad((string) $counter, 5, '0', STR_PAD_LEFT),
            'sale_id' => $sale->id, 'user_id' => $this->manager->id,
            'reason' => $this->weighted(['defective' => 35, 'wrong_item' => 20, 'customer_preference' => 35, 'other' => 10]),
            'refund_method' => $method, 'refund_amount_usd' => $refund,
            'refund_amount_lbp' => $method === 'cash_lbp' ? $this->currency->usdToLbp($refund) : 0,
            'status' => ReturnTransaction::STATUS_APPROVED, 'approved_by' => $this->manager->id, 'approved_at' => $at,
            'notes' => 'Fake return.',
        ]);
        $return->items()->create([
            'sale_item_id' => $item->id, 'product_id' => $item->product_id, 'qty_returned' => $qty,
            'refund_amount_usd' => $refund, 'restock' => true, 'condition' => 'good',
        ]);

        SaleItem::where('id', $item->id)->increment('returned_qty', $qty);
        if ($item->product->track_stock) {
            $this->inventory->restockFromReturn($item->product, $qty, $this->manager->id, $return->id);
            $this->stock[$item->product_id] = ($this->stock[$item->product_id] ?? 0) + $qty;
        }

        $fresh = $sale->fresh('items');
        $all = $fresh->items->every(fn ($i) => (float) $i->returned_qty >= (float) $i->qty - 0.0001);
        $fresh->update(['status' => $all ? Sale::STATUS_REFUNDED : Sale::STATUS_PARTIAL_REFUND]);

        if ($method === 'account' && $sale->customer) {
            $sale->customer->decrement('balance', $refund);
        }

        $return->update(['status' => ReturnTransaction::STATUS_COMPLETED]);
        AuditLog::record($this->manager->id, 'return_create', ReturnTransaction::class, $return->id, null, [
            'sale' => $sale->receipt_number, 'amount' => $refund, 'auto_approved' => true,
        ]);
    }

    /**
     * @param  list<ExpenseCategory>  $fixed
     * @param  list<ExpenseCategory>  $running
     */
    private function expensesFor(int $d, Carbon $day, Carbon $open, Carbon $close, User $cashier, array $fixed, array $running): void
    {
        $book = function (ExpenseCategory $category, float $amount, string $currency, string $paidFrom, User $by, string $payee, Carbon $at) {
            $at = $at->copy()->min($this->realNow);
            Carbon::setTestNow($at);
            $this->expenses->create([
                'expense_category_id' => $category->id, 'date' => $at->toDateString(), 'currency' => $currency,
                'amount' => $amount, 'paid_from' => $paidFrom, 'payee' => $payee, 'notes' => 'Fake expense.',
            ], $by);
        };

        // The month's fixed bills, once each, early in the period.
        if ($d === $this->days - 1 && $fixed !== []) {
            foreach ($fixed as $i => $category) {
                $book($category, [1200.0, 900.0, 250.0, 180.0][$i % 4], 'usd', 'outside', $this->admin, 'Fake landlord / utility', $open->copy()->subMinutes(5));
            }
        }

        if ($running === []) {
            return;
        }

        // A drawer expense happens while the shift is open, so it sits inside the shift's own window.
        $inShift = fn (): Carbon => $open->copy()->addMinutes(mt_rand(0, max(0, (int) floor($open->diffInMinutes($close)))))->min($close);
        $any = fn (): ExpenseCategory => $running[mt_rand(0, count($running) - 1)];

        if ($this->chance(0.3)) {
            $book($any(), (float) mt_rand(4, 35), 'usd', 'drawer', $cashier, 'Fake corner vendor', $inShift());
        }
        if ($this->chance(0.18)) {
            $book($any(), (float) mt_rand(10, 80) * 100_000, 'lbp', 'drawer', $cashier, 'Fake corner vendor', $inShift());
        }
        if ($this->chance(0.2)) {
            $book($any(), (float) mt_rand(30, 220), 'usd', 'outside', $this->manager, 'Fake supplier invoice', $close->copy()->addMinutes(10));
        }
    }

    /** Count the drawer and close the shift the way the close screen does; most days it is right, some days a little off. */
    private function closeShift(Shift $shift, User $cashier, Carbon $close): array
    {
        Carbon::setTestNow($close);
        $totals = $this->shifts->totals($shift);

        $usd = max(0.0, round($totals['expected_usd'] + [0, 0, 0, 0, -1, -2, 1][mt_rand(0, 6)], 2));
        $lbp = max(0.0, round($totals['expected_lbp'] / 1000) * 1000 + ($this->chance(0.2) ? -5000 : 0));

        $shift->update([
            'closed_at' => $close, 'status' => Shift::STATUS_CLOSED, 'closed_by' => $cashier->id,
            'closing_cash_usd' => $usd, 'closing_cash_lbp' => $lbp,
            'expected_cash_usd' => $totals['expected_usd'], 'expected_cash_lbp' => $totals['expected_lbp'],
            'variance_usd' => $usd - $totals['expected_usd'], 'variance_lbp' => $lbp - $totals['expected_lbp'],
        ]);

        return ['usd' => $usd, 'lbp' => $lbp];
    }

    /** Held carts on the open till (one of them an online order), as if someone had stepped away. */
    private function heldCarts(User $cashier): void
    {
        foreach ([
            ['Table 3 / Mrs Test', Sale::CHANNEL_ONSITE, null, [[0, 2], [8, 1]]],
            ['Order to deliver', Sale::CHANNEL_ONLINE, 'phone', [[1, 1], [12, 2], [22, 1]]],
        ] as [$label, $channel, $source, $picks]) {
            HeldSale::create([
                'user_id' => $cashier->id, 'customer_id' => null, 'label' => $label,
                'cart' => array_map(fn ($p) => ['product_id' => $this->catalogue[$p[0]]->id, 'qty' => $p[1]], $picks),
                'price_tier' => Customer::GROUP_RETAIL, 'channel' => $channel, 'channel_source' => $source,
                'cart_discount' => null, 'notes' => 'Fake held cart.',
            ]);
        }
    }

    // --- Stock ------------------------------------------------------------------------------------------------

    /** @param list<Supplier> $suppliers */
    private function stockEvents(int $d, Carbon $day, array $suppliers): void
    {
        $at = $day->copy()->setTime(7, 40);
        $period = max(1, $this->days);

        // Purchase orders across the period: two received (one in part), one sent, one still a draft.
        $orders = [
            [$period - 1, 0, PurchaseOrder::STATUS_RECEIVED, 1.0, 12],
            [(int) round($period * 0.55), 1, PurchaseOrder::STATUS_PARTIAL, 0.5, 8],
            [(int) round($period * 0.2), 2, PurchaseOrder::STATUS_SENT, 0.0, 9],
            [1, 3, PurchaseOrder::STATUS_DRAFT, 0.0, 6],
        ];
        foreach ($orders as [$onDay, $supplier, $status, $fraction, $lines]) {
            if ($d === min($onDay, $period)) {
                $this->purchaseOrder($suppliers[$supplier], $status, $fraction, $lines, $at);
            }
        }

        // Mid-period the stock keeper corrects two counts and writes some damaged goods off.
        if ($d === (int) round($period / 2)) {
            Carbon::setTestNow($at->copy()->addHours(1));
            foreach ([3, 14] as $i) {
                $product = $this->catalogue[$i]->fresh();
                $this->inventory->setStock($product, max(0.0, (float) $product->stock_qty - mt_rand(1, 4)), $this->stockKeeper->id, 'Stock count (fake)');
            }
            $damaged = $this->catalogue[9]->fresh();
            $this->inventory->adjust($damaged, -2.0, InventoryAdjustment::TYPE_REMOVE, $this->stockKeeper->id, reason: 'Damaged (fake)');
            $this->syncStock();
        }
    }

    private function purchaseOrder(Supplier $supplier, string $status, float $fraction, int $lineCount, Carbon $at): void
    {
        Carbon::setTestNow($at);

        $prefix = Setting::get('po_prefix', 'PO-'.date('Y').'-');
        $counter = (int) Setting::get('po_counter', 0) + 1;
        Setting::set('po_counter', $counter, 'numbering', 'int');

        $picked = collect($this->catalogue)->shuffle()->take($lineCount);
        $subtotal = 0.0;
        $items = [];
        foreach ($picked as $product) {
            $qty = (float) (mt_rand(2, 10) * 12);
            $subtotal += $qty * (float) $product->cost_usd;
            $items[] = [$product, $qty];
        }

        $po = PurchaseOrder::create([
            'po_number' => $prefix.str_pad((string) $counter, 5, '0', STR_PAD_LEFT),
            'supplier_id' => $supplier->id, 'user_id' => $this->manager->id, 'status' => $status,
            'subtotal_usd' => round($subtotal, 4), 'tax_amount_usd' => 0, 'shipping_usd' => 0, 'total_usd' => round($subtotal, 4),
            'expected_at' => $at->copy()->addDays(3)->toDateString(), 'notes' => 'Fake purchase order.',
        ]);

        $receivedValue = 0.0;
        foreach ($items as [$product, $qty]) {
            $received = floor($qty * $fraction);
            $po->items()->create([
                'product_id' => $product->id, 'product_name' => $product->name, 'qty_ordered' => $qty, 'qty_received' => $received,
                'cost_usd' => $product->cost_usd, 'tax_rate' => 0, 'line_total_usd' => round($qty * (float) $product->cost_usd, 4),
            ]);
            if ($received > 0) {
                $this->inventory->receiveStock($product, $received, $this->stockKeeper->id, $po->id);
                $receivedValue += $received * (float) $product->cost_usd;
            }
        }

        if ($receivedValue > 0) {
            $supplier->increment('balance', round($receivedValue, 2));
            $po->update(['received_at' => $status === PurchaseOrder::STATUS_RECEIVED ? $at : null]);
        }

        $this->syncStock();
    }

    /** A couple of products out and a few running low, so the dashboard and the stock report have something to say. */
    private function shelfCheck(): void
    {
        Carbon::setTestNow($this->realNow->copy()->subMinutes(30));

        foreach ([5, 21] as $i) {
            $product = $this->catalogue[$i]->fresh();
            $this->inventory->setStock($product, 0.0, $this->stockKeeper->id, 'Stock count (fake): shelf empty');
        }
        foreach ([2, 11, 19] as $i) {
            $product = $this->catalogue[$i]->fresh();
            $this->inventory->setStock($product, (float) max(1, (int) $product->min_stock - 3), $this->stockKeeper->id, 'Stock count (fake): running low');
        }
        $this->syncStock();
    }

    private function syncStock(): void
    {
        $this->stock = Product::query()->pluck('stock_qty', 'id')->map(fn ($q) => (float) $q)->all();
    }

    private function summarise(): void
    {
        $this->summary = [
            'users' => User::count(),
            'categories' => Category::count(),
            'products' => Product::count(),
            'customers' => Customer::count(),
            'suppliers' => Supplier::count(),
            'purchase orders' => PurchaseOrder::count(),
            'shifts' => Shift::count(),
            'sales' => Sale::count(),
            'online sales' => Sale::where('channel', Sale::CHANNEL_ONLINE)->count(),
            'voided sales' => Sale::where('status', Sale::STATUS_VOIDED)->count(),
            'returns' => ReturnTransaction::count(),
            'expenses' => \App\Models\Expense::count(),
            'held carts' => HeldSale::count(),
        ];
    }

    // --- Small helpers ----------------------------------------------------------------------------------------

    private function chance(float $p): bool
    {
        return mt_rand(1, 10000) <= (int) round($p * 10000);
    }

    /** @param array<int|string,int> $weights value => weight */
    private function weighted(array $weights): int|string|float
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    /** Earlier catalogue entries sell more: a few staples, a long tail. */
    private function popular(): Product
    {
        return $this->catalogue[(int) floor(count($this->catalogue) * ((mt_rand() / mt_getrandmax()) ** 1.8))];
    }

    /** Valid EAN-13 in the 29 in-store range (no real manufacturer), like the demo's. */
    private function ean13(int $n): string
    {
        $base = '29'.str_pad((string) $n, 10, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 ? 3 : 1);
        }

        return $base.((10 - $sum % 10) % 10);
    }
}
