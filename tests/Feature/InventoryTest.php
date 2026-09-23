<?php

use App\Models\Deposit;
use App\Models\Product;
use App\Models\ProductCatalog;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Permission;

// ── Helpers ──────────────────────────────────────────────────────────────────

function seedPermissions(): void
{
    $names = [
        'lift.add', 'lift.view', 'lift.update', 'lift.delete',
        'sales.add', 'sales.view', 'sales.update', 'sales.delete',
        'inventory.view',
        'deposit.add', 'deposit.view', 'deposit.update', 'deposit.delete',
    ];
    foreach ($names as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
}

function makeUser(array $permissions = []): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    return $user;
}

function makeSupplier(): Supplier
{
    static $n = 0;
    return Supplier::create([
        'company_name' => 'Supplier ' . ++$n,
        'phone_number' => '01700' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
        'address'      => 'Test Address',
    ]);
}

function makeShop(): Shop
{
    static $s = 0;
    return Shop::create([
        'shop_name'    => 'Shop ' . ++$s,
        'phone_number' => '01800' . str_pad((string) $s, 6, '0', STR_PAD_LEFT),
    ]);
}

function makeCatalog(Supplier $supplier, ?string $productName = null): ProductCatalog
{
    return ProductCatalog::create([
        'name'             => $productName ?? 'Product-' . uniqid(),
        'supplier_id'      => $supplier->id,
        'is_active'        => true,
        'default_variants' => [],
    ]);
}

function seedDeposit(Supplier $supplier, float $amount = 999_999): Deposit
{
    return Deposit::create([
        'supplier_id'       => $supplier->id,
        'balance_deposited'  => $amount,
        'balance_remaining'  => $amount,
        'deposit_date'       => now()->toDateString(),
        'is_used'            => false,
    ]);
}

function liftPayload(Supplier $supplier, ProductCatalog $catalog, array $variantOverrides = []): array
{
    $variant = array_merge([
        'variant'              => '500ml',
        'number_of_cases'      => 10,
        'case_buying_price'    => 240,
        'bottles_per_case'     => 24,
        'free_bottles_per_case' => 0,
    ], $variantOverrides);

    return [
        'supplier_id' => $supplier->id,
        'lift_date'   => now()->toDateString(),
        'items'       => [[
            'product_catalog_id' => $catalog->id,
            'product_name'       => $catalog->name,
            'variants'           => [$variant],
        ]],
    ];
}

function salePayload(Supplier $supplier, Shop $shop, Product $product, array $itemOverrides = []): array
{
    $item = array_merge([
        'product_id'              => $product->id,
        'variant'                 => '500ml',
        'cases_sold'              => 2,
        'extra_bottles'           => 0,
        'total_bottles_to_sell'   => 48,
        'selling_price_per_bottle' => 15,
        'free_bottles_per_case'   => 0,
    ], $itemOverrides);

    return [
        'shop_id'              => $shop->id,
        'supplier_id'          => $supplier->id,
        'sale_date'            => now()->toDateString(),
        'include_free_bottles' => false,
        'items'                => [$item],
    ];
}

function salesOf(Supplier $supplier): \Illuminate\Database\Eloquent\Builder
{
    return \App\Models\Sale::where('supplier_id', $supplier->id);
}

function getProduct(Supplier $supplier): Product
{
    return Product::where('supplier_id', $supplier->id)->firstOrFail();
}

function getVariant(Product $product, string $variant = '500ml'): array
{
    return collect($product->fresh()->metadata['variants'])->firstWhere('variant', $variant);
}

// ── T01–T06: Lift / Purchase creation ───────────────────────────────────────

it('T01: lift creates a product record with correct purchased quantity', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $user    = makeUser(['lift.add']);

    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);
    $variant = getVariant($product);

    // 10 cases × 24 bpc = 240
    expect($variant['current_purchased_quantity'])->toBe(240);
});

it('T02: lift sets current_free_quantity to zero when no free bottles', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $user    = makeUser(['lift.add']);

    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $variant = getVariant(getProduct($supplier));
    expect((int) $variant['current_free_quantity'])->toBe(0);
});

it('T03: lift with free bottles sets correct current_free_quantity', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $user    = makeUser(['lift.add']);

    // 10 cases × 2 free/case = 20 free bottles
    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['free_bottles_per_case' => 2]))
         ->assertRedirect();

    $variant = getVariant(getProduct($supplier));
    expect((int) $variant['current_free_quantity'])->toBe(20);
});

it('T04: lift stores case_buying_price in metadata', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $user    = makeUser(['lift.add']);

    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['case_buying_price' => 360]))
         ->assertRedirect();

    $variant = getVariant(getProduct($supplier));
    expect((float) $variant['case_buying_price'])->toBe(360.0);
});

it('T05: lift total_cost equals number_of_cases times case_buying_price', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $user    = makeUser(['lift.add']);

    // 10 cases × 240 = 2400
    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $variant = getVariant(getProduct($supplier));
    expect((float) $variant['total_cost'])->toBe(2400.0);
});

it('T06: draft lift does not create a product record', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $user    = makeUser(['lift.add']);

    $payload                = liftPayload($supplier, $catalog);
    $payload['save_as_draft'] = true;

    $this->actingAs($user)
         ->post(route('lifts.store'), $payload)
         ->assertRedirect();

    expect(Product::count())->toBe(0);
});

// ── T07–T11: Sale – inventory deduction ─────────────────────────────────────

it('T07: sale deducts purchased bottles from metadata', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Sell 2 cases = 48 bottles
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 2,
             'total_bottles_to_sell' => 48,
         ]))
         ->assertRedirect();

    // 240 - 48 = 192
    $variant = getVariant($product);
    expect($variant['current_purchased_quantity'])->toBe(192);
});

it('T08: sale without include_free_bottles does not touch free quantity', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['free_bottles_per_case' => 2]))
         ->assertRedirect();

    $product = getProduct($supplier);

    // 2 cases sold without free bottle inclusion
    $payload                       = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 2,
        'total_bottles_to_sell' => 48,
        'free_bottles_per_case' => 2,
    ]);
    $payload['include_free_bottles'] = false;

    $this->actingAs($saleUser)
         ->post(route('sales.store'), $payload)
         ->assertRedirect();

    $variant = getVariant($product);
    expect((int) $variant['current_free_quantity'])->toBe(20); // untouched
});

it('T09: sale with include_free_bottles deducts from both purchased and free', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['free_bottles_per_case' => 2]))
         ->assertRedirect();

    $product = getProduct($supplier);

    // include_free_bottles=true, 2 cases sold
    // purchasedSold = 2 × 24 = 48, freeSold = 2 × 2 = 4
    $payload = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 2,
        'total_bottles_to_sell' => 52,
        'free_bottles_per_case' => 2,
    ]);
    $payload['include_free_bottles'] = true;

    $this->actingAs($saleUser)
         ->post(route('sales.store'), $payload)
         ->assertRedirect();

    // FIFO deducts totalToDeduct (48+4=52) from purchased pool first, then free.
    // Since purchased pool (240) has enough, all 52 come from purchased; free is untouched.
    $variant = getVariant($product);
    expect($variant['current_purchased_quantity'])->toBe(188); // 240 - 52
    expect((int) $variant['current_free_quantity'])->toBe(20); // free pool untouched
});

it('T10: sale with extra bottles deducts extra from purchased quantity', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    // 1 case + 5 extra = 29 bottles sold
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 1,
             'extra_bottles'         => 5,
             'total_bottles_to_sell' => 29,
         ]))
         ->assertRedirect();

    $variant = getVariant($product);
    expect($variant['current_purchased_quantity'])->toBe(211); // 240 - 29
});

it('T11: draft sale does not deduct inventory', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    $payload                = salePayload($supplier, $shop, $product);
    $payload['save_as_draft'] = true;

    $this->actingAs($saleUser)
         ->post(route('sales.store'), $payload)
         ->assertRedirect();

    $variant = getVariant($product);
    expect($variant['current_purchased_quantity'])->toBe(240); // unchanged
});

// ── T12–T14: FIFO multi-batch ────────────────────────────────────────────────

it('T12: two-batch sale deducts entirely from first batch when stock sufficient', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // Batch 1: yesterday — ensures FIFO picks it first
    $p1 = liftPayload($supplier, $catalog, ['number_of_cases' => 10]);
    $p1['lift_date'] = now()->subDay()->toDateString();
    $this->actingAs($liftUser)->post(route('lifts.store'), $p1)->assertRedirect();

    // Batch 2: today
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['number_of_cases' => 5]))
         ->assertRedirect();

    $batches = Product::where('supplier_id', $supplier->id)->orderBy('id')->get();
    $batch1  = $batches->get(0);
    $batch2  = $batches->get(1);

    // Sell 3 cases = 72 bottles — fits entirely in batch 1 (older)
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $batch1, [
             'cases_sold'            => 3,
             'total_bottles_to_sell' => 72,
         ]))
         ->assertRedirect();

    expect(getVariant($batch1)['current_purchased_quantity'])->toBe(168); // 240 - 72
    expect(getVariant($batch2)['current_purchased_quantity'])->toBe(120); // untouched
});

it('T13: FIFO overflow deducts remainder from second batch', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // Batch 1: yesterday (FIFO picks it first), only 1 case = 24 bottles
    $p1 = liftPayload($supplier, $catalog, ['number_of_cases' => 1]);
    $p1['lift_date'] = now()->subDay()->toDateString();
    $this->actingAs($liftUser)->post(route('lifts.store'), $p1)->assertRedirect();

    // Batch 2: today, 10 cases = 240 bottles
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['number_of_cases' => 10]))
         ->assertRedirect();

    $batches = Product::where('supplier_id', $supplier->id)->orderBy('id')->get();
    $batch1  = $batches->get(0);
    $batch2  = $batches->get(1);

    // Sell 2 cases = 48 bottles → batch1 exhausted (24), 24 spills to batch2
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $batch1, [
             'cases_sold'            => 2,
             'total_bottles_to_sell' => 48,
         ]))
         ->assertRedirect();

    expect(getVariant($batch1)['current_purchased_quantity'])->toBe(0);  // fully depleted
    expect(getVariant($batch2)['current_purchased_quantity'])->toBe(216); // 240 - 24
});

it('T14: selling entire stock leaves zero in metadata', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // 5 cases = 120 bottles
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['number_of_cases' => 5]))
         ->assertRedirect();

    $product = getProduct($supplier);

    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 5,
             'total_bottles_to_sell' => 120,
         ]))
         ->assertRedirect();

    $variant = getVariant($product);
    expect($variant['current_purchased_quantity'])->toBe(0);
    expect((int) $variant['current_free_quantity'])->toBe(0);
});

// ── T15–T18: Profit calculation ──────────────────────────────────────────────

it('T15: profit stored is cases times selling minus cases times cost price, no rounding error', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // 10 cases, 24 bpc, cost 240 per case → actual_rate_per_bottle = 240/24 = 10.0 (exact)
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['case_buying_price' => 240]))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Sell 3 cases at ৳15/bottle → revenue = 72 × 15 = 1080, cost = 3 × 240 = 720, profit = 360
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'               => 3,
             'total_bottles_to_sell'    => 72,
             'selling_price_per_bottle' => 15,
         ]))
         ->assertRedirect();

    $profit = \App\Models\SaleItem::latest('id')->first()->profit;
    expect((float) $profit)->toBe(360.0);
});

it('T16: profit has no floating-point error when case_buying_price does not divide evenly', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // 24 bpc, cost 250 per case → rate = 250/24 = 10.4167... (repeating)
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['case_buying_price' => 250]))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Sell 6 cases at ৳15/bottle → revenue = 144 × 15 = 2160, cost = 6 × 250 = 1500, profit = 660
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'               => 6,
             'total_bottles_to_sell'    => 144,
             'selling_price_per_bottle' => 15,
         ]))
         ->assertRedirect();

    $profit = (float) \App\Models\SaleItem::latest('id')->first()->profit;
    expect($profit)->toBe(660.0);
});

it('T17: profit with extra bottles uses proportional cost for extras', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // 24 bpc, 0 free, cost 240 per case
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Sell 1 case + 6 extra bottles at ৳15/bottle
    // revenue = 30 × 15 = 450
    // cost: 1 × 240 + 6 × (240/24) = 240 + 60 = 300
    // profit = 150
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'               => 1,
             'extra_bottles'            => 6,
             'total_bottles_to_sell'    => 30,
             'selling_price_per_bottle' => 15,
         ]))
         ->assertRedirect();

    $profit = (float) \App\Models\SaleItem::latest('id')->first()->profit;
    expect($profit)->toBe(150.0);
});

it('T18: extra-only sale (0 cases) calculates profit correctly', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // 24 bpc, cost 240 per case → bottle rate = 10
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    // 0 cases, 5 extra bottles at ৳15/bottle
    // revenue = 5 × 15 = 75
    // cost = 0 × 240 + 5 × (240/24) = 50
    // profit = 25
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'               => 0,
             'extra_bottles'            => 5,
             'total_bottles_to_sell'    => 5,
             'selling_price_per_bottle' => 15,
         ]))
         ->assertRedirect();

    $profit = (float) \App\Models\SaleItem::latest('id')->first()->profit;
    expect($profit)->toBe(25.0);
});

it('T18a: selling a case WITHOUT its free bottles charges only the bottles sold, not the full case', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // 24 bpc + 6 free/case, cost 600/case → blended rate = 600 / (24+6) = 20.0 (exact)
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, [
             'case_buying_price'     => 600,
             'free_bottles_per_case' => 6,
         ]))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Sell 1 case (24 purchased bottles) at ৳22/bottle, free bottles excluded.
    // revenue = 24 × 22 = 528
    // cost    = 24 × (600/30) = 24 × 20 = 480   (NOT the full case price 600)
    // profit  = 48   (the old per-case formula charged 600 and showed a ৳72 LOSS)
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'               => 1,
             'free_bottles_per_case'    => 6,
             'total_bottles_to_sell'    => 24,
             'selling_price_per_bottle' => 22,
         ]))
         ->assertRedirect();

    $profit = (float) \App\Models\SaleItem::latest('id')->first()->profit;
    expect($profit)->toBe(48.0);
});

it('T18b: selling a case WITH its free bottles still charges the full case cost (no regression)', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // Same lift: 24 bpc + 6 free/case, cost 600/case → blended rate = 20.0
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, [
             'case_buying_price'     => 600,
             'free_bottles_per_case' => 6,
         ]))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Sell 1 effective case = 24 purchased + 6 free = 30 bottles at ৳22/bottle.
    // revenue = 30 × 22 = 660
    // cost    = 30 × 20 = 600   (full case cost, unchanged from old behaviour)
    // profit  = 60
    $payload = salePayload($supplier, $shop, $product, [
        'cases_sold'               => 1,
        'free_bottles_per_case'    => 6,
        'total_bottles_to_sell'    => 30,
        'selling_price_per_bottle' => 22,
    ]);
    $payload['include_free_bottles'] = true;

    $this->actingAs($saleUser)
         ->post(route('sales.store'), $payload)
         ->assertRedirect();

    $profit = (float) \App\Models\SaleItem::latest('id')->first()->profit;
    expect($profit)->toBe(60.0);
});

// ── T19–T21: Validation ──────────────────────────────────────────────────────

it('T19: sale with more bottles than available returns validation error', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // Only 5 cases = 120 bottles
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['number_of_cases' => 5]))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Try to sell 10 cases = 240 bottles (impossible)
    $response = $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 10,
             'total_bottles_to_sell' => 240,
         ]));

    $response->assertStatus(302); // redirects back with error
    // Inventory unchanged
    expect(getVariant($product)['current_purchased_quantity'])->toBe(120);
});

it('T20: lift with missing variant returns 422', function () {
    seedPermissions();
    $supplier = makeSupplier();
    $user     = makeUser(['lift.add']);

    $payload = [
        'supplier_id' => $supplier->id,
        'lift_date'   => now()->toDateString(),
        'items'       => [[
            'product_catalog_id' => 999999,   // non-existent
            'product_name'       => 'Ghost',
            'variants'           => [],        // empty — fails min:1
        ]],
    ];

    $this->actingAs($user)
         ->post(route('lifts.store'), $payload)
         ->assertStatus(302); // Laravel redirects back with validation errors
});

it('T21: sale with total_bottles_to_sell of zero returns 422', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'total_bottles_to_sell' => 0,
         ]))
         ->assertSessionHasErrors();
});

// ── T22–T23: Lift deletion ───────────────────────────────────────────────────

it('T22: deleting an unsold lift soft-deletes its product', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $user    = makeUser(['lift.add', 'lift.update', 'lift.delete']);

    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $lift    = \App\Models\Lift::first();
    $product = getProduct($supplier);

    $this->actingAs($user)
         ->delete(route('lifts.destroy', $lift->id))
         ->assertRedirect();

    expect(Product::withTrashed()->find($product->id)->deleted_at)->not->toBeNull();
});

it('T23: deleting a lift with sold bottles returns an error response', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add', 'lift.update', 'lift.delete']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $lift    = \App\Models\Lift::first();
    $product = getProduct($supplier);

    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 2,
             'total_bottles_to_sell' => 48,
         ]))
         ->assertRedirect();

    $this->actingAs($liftUser)
         ->delete(route('lifts.destroy', $lift->id))
         ->assertSessionHas('error');
});

// ── T24–T26: Inventory queries ───────────────────────────────────────────────

it('T24: getVariantInventory returns reduced stock after sale', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 4,
             'total_bottles_to_sell' => 96,
         ]))
         ->assertRedirect();

    $repo   = app(\App\Contracts\ProductPurchaseContract::class);
    $result = $repo->getVariantInventory($product->id, '500ml');

    expect($result['purchased_bottles_available'])->toBe(144); // 240 - 96
});

it('T25: product list hides zero-stock products by default', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add', 'inventory.view']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['number_of_cases' => 1]))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Sell all 24 bottles
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 1,
             'total_bottles_to_sell' => 24,
         ]))
         ->assertRedirect();

    // Soft-delete so it is truly "out of stock" (no active batches)
    $product->delete();

    $response = $this->actingAs($liftUser)->get(route('products.index'));
    $response->assertStatus(200);

    $products = $response->original->getData()['page']['props']['products']['data'] ?? [];
    $names    = array_column($products, 'name');
    expect($names)->not->toContain($catalog->name);
});

it('T26: product list shows zero-stock products when show_out_of_stock toggle is on', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add', 'inventory.view']);

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['number_of_cases' => 1]))
         ->assertRedirect();

    $product = getProduct($supplier);
    $product->delete(); // soft-delete → "out of stock" batch

    $response = $this->actingAs($liftUser)
         ->get(route('products.index', ['show_out_of_stock' => 'true']));

    $response->assertStatus(200);
    $products = $response->original->getData()['page']['props']['products']['data'] ?? [];
    $names    = array_column($products, 'name');
    expect($names)->toContain($catalog->name);
});

// ── T27: Sale update ─────────────────────────────────────────────────────────

it('T27: updating a sale adjusts inventory to reflect the new quantity', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add', 'sales.update']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    // Original sale: 2 cases = 48 bottles
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 2,
             'total_bottles_to_sell' => 48,
         ]))
         ->assertRedirect();

    $sale = \App\Models\Sale::first();
    expect(getVariant($product)['current_purchased_quantity'])->toBe(192);

    // Update sale to 3 cases = 72 bottles
    $this->actingAs($saleUser)
         ->put(route('sales.update', $sale->id), array_merge(
             salePayload($supplier, $shop, $product, [
                 'cases_sold'            => 3,
                 'total_bottles_to_sell' => 72,
             ]),
             ['original_sale_id' => $sale->id]
         ))
         ->assertRedirect();

    expect(getVariant($product)['current_purchased_quantity'])->toBe(168); // 240 - 72
});

// ── T28–T30: Edge cases ──────────────────────────────────────────────────────

it('T28: two variants of the same product deduct independently', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $productName = 'Multi-Variant ' . uniqid();
    $catalog     = makeCatalog($supplier, $productName);
    $liftUser    = makeUser(['lift.add']);
    $saleUser    = makeUser(['sales.add']);
    $shop        = makeShop();

    // Each variant in a lift creates its own Product row in the `products` table.
    // Sending both variants in one lift creates Product #1 (330ml) and Product #2 (500ml).
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), [
             'supplier_id' => $supplier->id,
             'lift_date'   => now()->toDateString(),
             'items'       => [[
                 'product_catalog_id' => $catalog->id,
                 'product_name'       => $catalog->name,
                 'variants'           => [
                     ['variant' => '330ml', 'number_of_cases' => 10, 'case_buying_price' => 200, 'bottles_per_case' => 24, 'free_bottles_per_case' => 0],
                     ['variant' => '500ml', 'number_of_cases' => 10, 'case_buying_price' => 240, 'bottles_per_case' => 24, 'free_bottles_per_case' => 0],
                 ],
             ]],
         ])
         ->assertRedirect();

    $product330 = Product::where('supplier_id', $supplier->id)->orderBy('id')->first();
    $product500 = Product::where('supplier_id', $supplier->id)->orderBy('id')->skip(1)->first();

    // Sell 3 cases of 330ml only
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product330, [
             'variant'               => '330ml',
             'cases_sold'            => 3,
             'total_bottles_to_sell' => 72,
         ]))
         ->assertRedirect();

    // 330ml product: 240 - 72 = 168
    // 500ml product: 240, untouched
    $v330 = getVariant($product330, '330ml');
    $v500 = getVariant($product500, '500ml');

    expect($v330['current_purchased_quantity'])->toBe(168);
    expect($v500['current_purchased_quantity'])->toBe(240);
});

it('T29: lift actual_rate_per_bottle is total_cost divided by total_bottles', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $user    = makeUser(['lift.add']);

    // 10 cases, 24 bpc, 2 free/case → total bottles = 10×24 + 10×2 = 260
    // total cost = 10 × 300 = 3000 → rate = 3000/260 ≈ 11.5385
    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, [
             'number_of_cases'       => 10,
             'case_buying_price'     => 300,
             'bottles_per_case'      => 24,
             'free_bottles_per_case' => 2,
         ]))
         ->assertRedirect();

    $variant = getVariant(getProduct($supplier));
    $expected = round(3000 / 260, 4);
    expect((float) $variant['actual_rate_per_bottle'])->toBe($expected);
});

it('T30: inventory report returns correct aggregated available bottles per product', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    // Two batches: 5 + 5 = 10 cases, 240 bottles total
    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['number_of_cases' => 5]))
         ->assertRedirect();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, ['number_of_cases' => 5]))
         ->assertRedirect();

    $product = Product::orderBy('id')->first();

    // Sell 3 cases = 72 bottles
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 3,
             'total_bottles_to_sell' => 72,
         ]))
         ->assertRedirect();

    $repo   = app(\App\Contracts\ProductPurchaseContract::class);
    $stock  = $repo->getInventoryStock();
    $entry  = $stock->firstWhere('product_name', $catalog->name);

    // 240 total - 72 sold = 168 available
    expect((int) $entry['total_available_bottles'])->toBe(168);
});

// ── T31–T35: Draft sales – editing a draft must update it, not fork a new one ─

it('T31: saving a draft again with draft_id updates that draft instead of creating a new one', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    // First save: 2 cases as a draft
    $payload                   = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 2,
        'total_bottles_to_sell' => 48,
    ]);
    $payload['save_as_draft'] = true;

    $this->actingAs($saleUser)->post(route('sales.store'), $payload)->assertRedirect();

    $draft   = salesOf($supplier)->where('status', 'draft')->firstOrFail();
    $invoice = $draft->invoice_number;

    // Re-open that draft, change it to 3 cases, save again
    $update                    = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 3,
        'total_bottles_to_sell' => 72,
    ]);
    $update['save_as_draft'] = true;
    $update['draft_id']      = $draft->id;

    $this->actingAs($saleUser)->post(route('sales.store'), $update)->assertRedirect();

    expect(salesOf($supplier)->count())->toBe(1);

    $draft = $draft->fresh();
    expect($draft->status)->toBe('draft')
        ->and($draft->invoice_number)->toBe($invoice)   // same invoice, same draft
        ->and((float) $draft->total_amount)->toBe(1080.0); // 72 × 15

    // Items are replaced, never appended
    expect($draft->items)->toHaveCount(1)
        ->and($draft->items->first()->cases_sold)->toBe(3);

    // Still a draft, so still no stock movement
    expect(getVariant($product)['current_purchased_quantity'])->toBe(240);
});

it('T32: opening a draft through the edit route redirects into the draft flow', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add', 'sales.update']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $payload                   = salePayload($supplier, $shop, getProduct($supplier));
    $payload['save_as_draft'] = true;

    $this->actingAs($saleUser)->post(route('sales.store'), $payload)->assertRedirect();

    $draft = salesOf($supplier)->where('status', 'draft')->firstOrFail();

    // Edit must land on the same screen "Continue Sale" uses, which posts back
    // with draft_id and therefore updates this row.
    $this->actingAs($saleUser)
         ->get(route('sales.edit', $draft->id))
         ->assertRedirect(route('sales.index', ['draft' => $draft->id]));
});

it('T33: confirming a draft updates the same sale row and deducts stock once', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    $payload                   = salePayload($supplier, $shop, $product);
    $payload['save_as_draft'] = true;

    $this->actingAs($saleUser)->post(route('sales.store'), $payload)->assertRedirect();

    $draft = salesOf($supplier)->where('status', 'draft')->firstOrFail();

    // Confirm it: same payload, no save_as_draft, carrying the draft id
    $confirm             = salePayload($supplier, $shop, $product);
    $confirm['draft_id'] = $draft->id;

    $this->actingAs($saleUser)->post(route('sales.store'), $confirm)->assertRedirect();

    expect(salesOf($supplier)->count())->toBe(1)
        ->and($draft->fresh()->status)->toBe('in_progress');

    // 240 - 48, deducted exactly once
    expect(getVariant($product)['current_purchased_quantity'])->toBe(192);
});

it('T34: a draft_id pointing at an already-confirmed sale is rejected', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product))
         ->assertRedirect();

    $confirmed = salesOf($supplier)->firstOrFail();
    expect($confirmed->status)->toBe('in_progress');

    // A stale draft id must not be allowed to rewrite a real sale's items,
    // which would silently strand the stock it already deducted.
    $stale             = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 5,
        'total_bottles_to_sell' => 120,
    ]);
    $stale['draft_id'] = $confirmed->id;

    $this->actingAs($saleUser)
         ->post(route('sales.store'), $stale)
         ->assertSessionHasErrors('draft_id');

    expect(salesOf($supplier)->count())->toBe(1)
        ->and($confirmed->fresh()->items->first()->cases_sold)->toBe(2)
        ->and(getVariant($product)['current_purchased_quantity'])->toBe(192);
});

it('T35: updating a draft does not hand back stock the draft never took', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add', 'sales.update']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $product = getProduct($supplier);

    // A real sale first, so the batch has headroom a wrong restore could fill.
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 2,
             'total_bottles_to_sell' => 48,
         ]))
         ->assertRedirect();

    expect(getVariant($product)['current_purchased_quantity'])->toBe(192);

    // Now a draft for 1 case, which deducts nothing.
    $payload                   = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 1,
        'total_bottles_to_sell' => 24,
    ]);
    $payload['save_as_draft'] = true;

    $this->actingAs($saleUser)->post(route('sales.store'), $payload)->assertRedirect();

    $draft = salesOf($supplier)->where('status', 'draft')->firstOrFail();

    $this->actingAs($saleUser)
         ->put(route('sales.update', $draft->id), salePayload($supplier, $shop, $product, [
             'cases_sold'            => 1,
             'total_bottles_to_sell' => 24,
         ]))
         ->assertRedirect();

    // 192 - 24. Restoring the draft's 24 bottles first would leave 192.
    expect(getVariant($product)['current_purchased_quantity'])->toBe(168);
});

// ── T36: the sales report must carry what is still owed ─────────────────────

it('T36: sales report exposes paid and due amounts for a part-paid sale', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add', 'sales.update', 'sales.view']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    // 2 cases x 24 bottles x 15 = 720
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier)))
         ->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();
    expect((float) $sale->total_amount)->toBe(720.0);

    // Pay half, leaving 360 owed.
    $this->actingAs($saleUser)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 360,
        'payment_method' => 'cash',
    ]);

    $this->actingAs($saleUser)
         ->get(route('sales.report'))
         ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use ($sale) {
             $row = collect($page->toArray()['props']['sales'])
                 ->firstWhere('id', $sale->id);

             expect($row)->not->toBeNull()
                 ->and((float) $row['total_amount'])->toBe(720.0)
                 ->and((float) $row['paid_amount'])->toBe(360.0)
                 ->and((float) $row['due_amount'])->toBe(360.0)
                 ->and($row['status'])->toBe('in_progress');
         });
});

// ── T37: collecting the rest of a due later ─────────────────────────────────

it('T37: a second payment against the same sale clears the remaining due', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add', 'sales.update', 'sales.view']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier)))
         ->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();

    // Day one: shop pays 360 of 720.
    $this->actingAs($saleUser)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 360,
        'payment_method' => 'cash',
    ]);

    $sale->refresh();
    expect((float) $sale->due_amount)->toBe(360.0)
        ->and($sale->status)->toBe('in_progress');

    // The payment screen for this sale offers exactly the outstanding balance.
    $this->actingAs($saleUser)
         ->get(route('sales.payment', $sale->id))
         ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) {
             $s = $page->toArray()['props']['sale'];
             expect((float) $s['total_amount'])->toBe(720.0)
                 ->and((float) $s['paid_amount'])->toBe(360.0)
                 ->and((float) $s['due_amount'])->toBe(360.0);
         });

    // Day two: the collector takes the remaining 360.
    $this->actingAs($saleUser)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 360,
        'payment_method' => 'cash',
    ]);

    $sale->refresh();
    expect((float) $sale->paid_amount)->toBe(720.0)
        ->and((float) $sale->due_amount)->toBe(0.0)
        ->and($sale->status)->toBe('completed')
        ->and($sale->is_paid)->toBeTrue();

    // Both collections are kept as separate rows, not overwritten.
    expect(\Illuminate\Support\Facades\DB::table('payments')->where('sale_id', $sale->id)->count())->toBe(2);
});

// ── T38: due collection reaches the payment screen ──────────────────────────

it('T38: the payment screen for a part-paid sale opens on the outstanding balance', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser(['sales.add', 'sales.update', 'sales.view']);
    $shop     = makeShop();

    $this->actingAs($liftUser)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect();

    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier)))
         ->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();

    $this->actingAs($saleUser)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 500,
        'payment_method' => 'cash',
    ]);

    // What the "Due Collection" button links to must render the sale with the
    // remaining 220 as the payable balance.
    $this->actingAs($saleUser)
         ->get(route('sales.payment', $sale->id))
         ->assertOk()
         ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) {
             expect($page->toArray()['component'])->toBe('SalesManagement/SalesPayment');
             $s = $page->toArray()['props']['sale'];
             expect((float) $s['total_amount'] - (float) $s['paid_amount'])->toBe(220.0)
                 ->and((float) $s['due_amount'])->toBe(220.0);
         });

    // And collecting it there settles the sale.
    $this->actingAs($saleUser)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 220,
        'payment_method' => 'cash',
    ]);

    $sale->refresh();
    expect((float) $sale->due_amount)->toBe(0.0)
        ->and($sale->status)->toBe('completed');
});

// ═══════════════════════════════════════════════════════════════════════════
//  T39–T56: corner cases
// ═══════════════════════════════════════════════════════════════════════════

/** A lifted, sellable product plus the users and shop needed to sell it. */
function saleFixture(array $liftOverrides = []): array
{
    seedAllPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $saleUser = makeUser([
        'sales.add', 'sales.update', 'sales.view', 'sales.delete',
        'dashboard.view', 'inventory.view', 'lift.view', 'report.profit-loss',
    ]);
    $shop     = makeShop();

    test()->actingAs($liftUser)
        ->post(route('lifts.store'), liftPayload($supplier, $catalog, $liftOverrides))
        ->assertRedirect();

    return [$supplier, $shop, getProduct($supplier), $saleUser];
}

// ── Payments ────────────────────────────────────────────────────────────────

it('T39: paying more than the total does not record more money than the sale is worth', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    // Sale is 720; someone fat-fingers 7200.
    $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 7200,
        'payment_method' => 'cash',
    ]);

    $sale->refresh();
    expect((float) $sale->paid_amount)->toBeLessThanOrEqual(720.0)
        ->and((float) $sale->due_amount)->toBe(0.0);
});

it('T40: a zero payment leaves the sale exactly as it was', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 0,
        'payment_method' => 'cash',
    ]);

    $sale->refresh();
    expect((float) $sale->paid_amount)->toBe(0.0)
        ->and((float) $sale->due_amount)->toBe(720.0)
        ->and($sale->status)->toBe('in_progress');
});

it('T41: a negative payment can never increase what is owed', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => -500,
        'payment_method' => 'cash',
    ]);

    $sale->refresh();
    // Owing more than the sale is worth is never a valid state.
    expect((float) $sale->due_amount)->toBeLessThanOrEqual(720.0)
        ->and((float) $sale->paid_amount)->toBeGreaterThanOrEqual(0.0);
});

it('T42: uneven instalments settle a sale with no rounding drift', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    // 3 bottles at 33.33 = 99.99
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold'               => 0,
        'extra_bottles'            => 3,
        'total_bottles_to_sell'    => 3,
        'selling_price_per_bottle' => 33.33,
    ]))->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();
    expect((float) $sale->total_amount)->toBe(99.99);

    foreach ([33.33, 33.33, 33.33] as $instalment) {
        $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
            'payment_amount' => $instalment,
            'payment_method' => 'cash',
        ]);
    }

    $sale->refresh();
    expect((float) $sale->due_amount)->toBe(0.0)
        ->and((float) $sale->paid_amount)->toBe(99.99)
        ->and($sale->status)->toBe('completed');
});

it('T43: paying an already-settled sale does not push it past fully paid', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    foreach ([720, 200] as $amount) {
        $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
            'payment_amount' => $amount,
            'payment_method' => 'cash',
        ]);
    }

    $sale->refresh();
    expect((float) $sale->paid_amount)->toBeLessThanOrEqual(720.0)
        ->and((float) $sale->due_amount)->toBe(0.0)
        ->and($sale->status)->toBe('completed');
});

// ── Drafts ──────────────────────────────────────────────────────────────────

it('T44: a draft may be saved for more stock than exists, since it reserves nothing', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    // Only 240 bottles were lifted; draft 20 cases = 480.
    $payload = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 20,
        'total_bottles_to_sell' => 480,
    ]);
    $payload['save_as_draft'] = true;

    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    expect(salesOf($supplier)->where('status', 'draft')->count())->toBe(1)
        ->and(getVariant($product)['current_purchased_quantity'])->toBe(240);
});

it('T45: confirming a draft that outgrew the stock is rejected and the draft survives', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $payload = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 20,
        'total_bottles_to_sell' => 480,
    ]);
    $payload['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    $draft = salesOf($supplier)->where('status', 'draft')->firstOrFail();

    $confirm = salePayload($supplier, $shop, $product, [
        'cases_sold'            => 20,
        'total_bottles_to_sell' => 480,
    ]);
    $confirm['draft_id'] = $draft->id;

    $this->actingAs($user)->post(route('sales.store'), $confirm)
         ->assertSessionHasErrors('inventory');

    // Nothing half-applied: still a draft, stock untouched.
    expect($draft->fresh()->status)->toBe('draft')
        ->and(getVariant($product)['current_purchased_quantity'])->toBe(240)
        ->and(salesOf($supplier)->count())->toBe(1);
});

it('T46: deleting a draft leaves inventory untouched', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $payload = salePayload($supplier, $shop, $product);
    $payload['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    $draft = salesOf($supplier)->where('status', 'draft')->firstOrFail();

    $this->actingAs($user)->delete(route('sales.destroy', $draft->id))->assertRedirect();

    expect(salesOf($supplier)->count())->toBe(0)
        ->and(getVariant($product)['current_purchased_quantity'])->toBe(240);
});

it('T47: shrinking a draft to fewer items drops the removed lines', function () {
    [$supplier, $shop, $product, $user] = saleFixture(['free_bottles_per_case' => 2]);

    $twoLines = salePayload($supplier, $shop, $product);
    $twoLines['save_as_draft'] = true;
    $twoLines['items'][] = array_merge($twoLines['items'][0], [
        'cases_sold'            => 1,
        'total_bottles_to_sell' => 24,
    ]);
    $this->actingAs($user)->post(route('sales.store'), $twoLines)->assertRedirect();

    $draft = salesOf($supplier)->where('status', 'draft')->firstOrFail();
    expect($draft->items)->toHaveCount(2);

    $oneLine = salePayload($supplier, $shop, $product);
    $oneLine['save_as_draft'] = true;
    $oneLine['draft_id'] = $draft->id;
    $this->actingAs($user)->post(route('sales.store'), $oneLine)->assertRedirect();

    expect($draft->fresh()->items)->toHaveCount(1)
        ->and(\App\Models\SaleItem::where('sale_id', $draft->id)->count())->toBe(1);
});

it('T48: a draft cannot be confirmed twice', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $payload = salePayload($supplier, $shop, $product);
    $payload['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    $draft = salesOf($supplier)->where('status', 'draft')->firstOrFail();

    $confirm = salePayload($supplier, $shop, $product);
    $confirm['draft_id'] = $draft->id;

    $this->actingAs($user)->post(route('sales.store'), $confirm)->assertRedirect();
    // A double submit must not deduct the stock a second time.
    $this->actingAs($user)->post(route('sales.store'), $confirm)->assertSessionHasErrors('draft_id');

    expect(salesOf($supplier)->count())->toBe(1)
        ->and(getVariant($product)['current_purchased_quantity'])->toBe(192);
});

// ── Inventory ───────────────────────────────────────────────────────────────

it('T49: after selling the last bottle any further sale is rejected', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold'            => 10,
        'total_bottles_to_sell' => 240,
    ]))->assertRedirect();

    expect(getVariant($product)['current_purchased_quantity'])->toBe(0);

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold'            => 0,
        'extra_bottles'         => 1,
        'total_bottles_to_sell' => 1,
    ]))->assertSessionHasErrors('inventory');

    expect(getVariant($product)['current_purchased_quantity'])->toBe(0);
});

it('T50: deleting a sale of a variant that has free bottles restores every bottle', function () {
    // 45 cases x 1 free = 45 free bottles, which is more than one 24-bottle case.
    // That is the threshold where cases_without_free_bottles stops matching the
    // bottles actually lifted, so it is the case the restore has to get right.
    [$supplier, $shop, $product, $user] = saleFixture([
        'number_of_cases'       => 45,
        'free_bottles_per_case' => 1,
    ]);

    $before = getVariant($product)['current_purchased_quantity'];

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold'            => 2,
        'total_bottles_to_sell' => 48,
    ]))->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();
    expect(getVariant($product)['current_purchased_quantity'])->toBe($before - 48);

    $this->actingAs($user)->delete(route('sales.destroy', $sale->id))->assertRedirect();

    expect(getVariant($product)['current_purchased_quantity'])->toBe($before);
});

it('T51: reducing a sale returns the difference to stock', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold'            => 5,
        'total_bottles_to_sell' => 120,
    ]))->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();
    expect(getVariant($product)['current_purchased_quantity'])->toBe(120);

    $this->actingAs($user)->put(route('sales.update', $sale->id), salePayload($supplier, $shop, $product, [
        'cases_sold'            => 2,
        'total_bottles_to_sell' => 48,
    ]))->assertRedirect();

    expect(getVariant($product)['current_purchased_quantity'])->toBe(192);
});

it('T52: a sale of zero cases and zero extra bottles is rejected', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold'            => 0,
        'extra_bottles'         => 0,
        'total_bottles_to_sell' => 0,
    ]))->assertSessionHasErrors();

    expect(salesOf($supplier)->count())->toBe(0);
});

it('T53: a sale for a variant that was never lifted is rejected', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'variant' => '2L',
    ]))->assertSessionHasErrors('inventory');

    expect(salesOf($supplier)->count())->toBe(0);
});

// ── Report ──────────────────────────────────────────────────────────────────

it('T54: drafts carry no payment obligation in the report', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $payload = salePayload($supplier, $shop, $product);
    $payload['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    $draft = salesOf($supplier)->where('status', 'draft')->firstOrFail();

    $this->actingAs($user)->get(route('sales.report'))
         ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use ($draft) {
             $row = collect($page->toArray()['props']['sales'])->firstWhere('id', $draft->id);
             expect($row)->not->toBeNull()
                 ->and($row['status'])->toBe('draft')
                 // The page decides "no payment state" from the draft status, so
                 // the row must still say it is a draft after the round trip.
                 ->and((float) $row['paid_amount'])->toBe(0.0);
         });
});

it('T55: a fully paid sale reports zero due', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 720,
        'payment_method' => 'cash',
    ]);

    $this->actingAs($user)->get(route('sales.report'))
         ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use ($sale) {
             $row = collect($page->toArray()['props']['sales'])->firstWhere('id', $sale->id);
             expect((float) $row['due_amount'])->toBe(0.0)
                 ->and($row['status'])->toBe('completed');
         });
});

it('T56: the report survives having no sales at all', function () {
    seedPermissions();
    $user = makeUser(['sales.view']);

    $this->actingAs($user)->get(route('sales.report'))
         ->assertOk()
         ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) =>
             expect($page->toArray()['props']['sales'])->toBe([]));
});

// ── T57: custom variant sizes must survive for the next lift ────────────────

it('T57: a custom variant size is remembered on the product catalog after one lift', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    expect($catalog->default_variants)->toBe([]);

    // A size that is not one of the four the picker hardcodes.
    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog, [
             'variant'          => '550ml',
             'bottles_per_case' => 20,
         ]))
         ->assertRedirect();

    $saved = collect($catalog->fresh()->default_variants);
    expect($saved->pluck('variant')->all())->toContain('550ml')
        ->and($saved->firstWhere('variant', '550ml')['bottles_per_case'])->toBe(20);

    // And the endpoint the lift screen searches with hands it back.
    $response = $this->actingAs($user)
        ->getJson('/api/product-catalog/search?supplier_id=' . $supplier->id . '&q=' . urlencode($catalog->name));

    $row = collect($response->json())->firstWhere('id', $catalog->id);
    expect(collect($row['default_variants'])->pluck('variant')->all())->toContain('550ml');
});

it('T58: a second lift keeps the earlier custom size alongside the new one', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '550ml',
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '330ml',
    ]))->assertRedirect();

    expect(collect($catalog->fresh()->default_variants)->pluck('variant')->all())
        ->toContain('550ml')
        ->toContain('330ml');
});

// ── T59–T64: what the lift screen needs to offer a custom size again ────────

it('T59: a custom size keeps its bottles-per-case, not the 24 default', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant'               => '750ml',
        'bottles_per_case'      => 9,
        'free_bottles_per_case' => 2,
    ]))->assertRedirect();

    $saved = collect($catalog->fresh()->default_variants)->firstWhere('variant', '750ml');

    // The picker pre-fills its checkbox from exactly these two numbers.
    expect($saved['bottles_per_case'])->toBe(9)
        ->and((float) $saved['free_bottles_per_case'])->toBe(2.0);
});

it('T60: lifting the same custom size twice does not duplicate it', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    foreach ([1, 2, 3] as $_) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '550ml',
        ]))->assertRedirect();
    }

    $saved = collect($catalog->fresh()->default_variants)->where('variant', '550ml');
    expect($saved)->toHaveCount(1);
});

it('T61: a custom size saved as a draft lift is remembered too', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    $payload = liftPayload($supplier, $catalog, ['variant' => '650ml']);
    $payload['save_as_draft'] = true;

    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    // No stock yet, but the size is now a preset for next time.
    expect(Product::count())->toBe(0)
        ->and(collect($catalog->fresh()->default_variants)->pluck('variant')->all())
        ->toContain('650ml');
});

it('T62: a custom size on one product does not leak onto another', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $cola     = makeCatalog($supplier);
    $water    = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $cola, [
        'variant' => '550ml',
    ]))->assertRedirect();

    expect(collect($cola->fresh()->default_variants)->pluck('variant')->all())->toContain('550ml')
        ->and(collect($water->fresh()->default_variants)->pluck('variant')->all())->not->toContain('550ml');
});

it('T63: several custom sizes on one lift are all remembered', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    $payload = liftPayload($supplier, $catalog);
    $payload['items'][0]['variants'] = [
        ['variant' => '330ml', 'number_of_cases' => 5, 'case_buying_price' => 200, 'bottles_per_case' => 24, 'free_bottles_per_case' => 0],
        ['variant' => '550ml', 'number_of_cases' => 4, 'case_buying_price' => 300, 'bottles_per_case' => 18, 'free_bottles_per_case' => 1],
        ['variant' => '5L',    'number_of_cases' => 3, 'case_buying_price' => 480, 'bottles_per_case' => 4,  'free_bottles_per_case' => 0],
    ];

    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    $names = collect($catalog->fresh()->default_variants)->pluck('variant')->all();
    expect($names)->toContain('330ml')->toContain('550ml')->toContain('5L');
});

it('T64: a custom size survives alongside the standard ones', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml',
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '550ml',
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '250ml',
    ]))->assertRedirect();

    // The picker renders the four fixed sizes plus this list, so 550ml must
    // still be here after standard sizes were lifted on top of it.
    expect(collect($catalog->fresh()->default_variants)->pluck('variant')->all())
        ->toContain('550ml')
        ->toContain('500ml')
        ->toContain('250ml');
});

// ── T65–T67: the lift screen's deposit balance ──────────────────────────────

it('T65: the lift page reports the deposit balance already reduced by the lift', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    $balanceOf = fn () => collect(
        test()->actingAs($user)->get(route('lifts.index'))
              ->viewData('page')['props']['suppliers']
    )->firstWhere('id', $supplier->id)['remaining_deposit'];

    expect((float) $balanceOf())->toBe(10000.0);

    // 10 cases x 240 = 2400 drawn against the deposit.
    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect(route('lifts.report', ['tab' => 'completed']));

    expect((float) $balanceOf())->toBe(7600.0);
});

it('T66: a draft lift does not touch the deposit balance', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    $payload = liftPayload($supplier, $catalog);
    $payload['save_as_draft'] = true;

    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    $balance = collect(
        $this->actingAs($user)->get(route('lifts.index'))
             ->viewData('page')['props']['suppliers']
    )->firstWhere('id', $supplier->id)['remaining_deposit'];

    expect((float) $balance)->toBe(10000.0);
});

it('T67: successive lifts keep drawing the balance down', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add']);

    foreach ([1, 2, 3] as $_) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    }

    $balance = collect(
        $this->actingAs($user)->get(route('lifts.index'))
             ->viewData('page')['props']['suppliers']
    )->firstWhere('id', $supplier->id)['remaining_deposit'];

    // 10000 - (2400 x 3)
    expect((float) $balance)->toBe(2800.0);
});

// ── T68–T69: the date range the picker emits ────────────────────────────────

it('T68: a single-day range returns only that day, which is what Today/Yesterday send', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $user     = makeUser(['sales.add', 'sales.view']);
    $shop     = makeShop();

    $this->actingAs($liftUser)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    $product = getProduct($supplier);

    foreach ([0 => 'today', 1 => 'yesterday', 5 => 'older'] as $daysAgo => $_) {
        $this->actingAs($user)->post(route('sales.store'), array_merge(
            salePayload($supplier, $shop, $product, ['cases_sold' => 1, 'total_bottles_to_sell' => 24]),
            ['sale_date' => now()->subDays($daysAgo)->toDateString()]
        ))->assertRedirect();
    }

    $yesterday = now()->subDay()->toDateString();

    $rows = $this->actingAs($user)
        ->get(route('sales.report', ['start_date' => $yesterday, 'end_date' => $yesterday]))
        ->viewData('page')['props']['sales'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['sale_date'])->toBe($yesterday);
});

it('T69: a multi-day custom range includes both end points', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $liftUser = makeUser(['lift.add']);
    $user     = makeUser(['sales.add', 'sales.view']);
    $shop     = makeShop();

    $this->actingAs($liftUser)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    $product = getProduct($supplier);

    foreach ([1, 2, 3, 9] as $daysAgo) {
        $this->actingAs($user)->post(route('sales.store'), array_merge(
            salePayload($supplier, $shop, $product, ['cases_sold' => 1, 'total_bottles_to_sell' => 24]),
            ['sale_date' => now()->subDays($daysAgo)->toDateString()]
        ))->assertRedirect();
    }

    // Boundary days must be inside the range, not dropped by an exclusive compare.
    $rows = $this->actingAs($user)
        ->get(route('sales.report', [
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date'   => now()->subDays(1)->toDateString(),
        ]))
        ->viewData('page')['props']['sales'];

    expect($rows)->toHaveCount(3);
});

// ── T70–T72: the login screen's contract ────────────────────────────────────

it('T70: the login page renders the Login component', function () {
    $this->get(route('login'))
         ->assertOk()
         ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) =>
             expect($page->toArray()['component'])->toBe('Auth/Login'));
});

it('T71: a user can sign in with either their email or their username', function () {
    $user = \App\Models\User::factory()->create([
        'name'     => 'collector-01',
        'email'    => 'collector@example.com',
        'password' => bcrypt('secret-pass'),
    ]);

    // The form posts a single "login" field that accepts both.
    $this->post(route('login'), ['login' => 'collector@example.com', 'password' => 'secret-pass'])
         ->assertRedirect();
    expect(auth()->id())->toBe($user->id);

    auth()->logout();

    $this->post(route('login'), ['login' => 'collector-01', 'password' => 'secret-pass'])
         ->assertRedirect();
    expect(auth()->id())->toBe($user->id);
});

it('T72: a wrong password is rejected on the login field', function () {
    \App\Models\User::factory()->create([
        'email'    => 'collector@example.com',
        'password' => bcrypt('secret-pass'),
    ]);

    $this->post(route('login'), ['login' => 'collector@example.com', 'password' => 'wrong'])
         ->assertSessionHasErrors('login');

    expect(auth()->check())->toBeFalse();
});

// ── T73–T75: where each save lands you ──────────────────────────────────────

it('T73: recording a lift lands on the lift report with a confirmation', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view']);

    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect(route('lifts.report', ['tab' => 'completed']))
         ->assertSessionHas('success', 'Lift recorded successfully');
});

it('T74: saving a lift draft lands on the lift report too', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view']);

    $payload = liftPayload($supplier, $catalog);
    $payload['save_as_draft'] = true;

    $this->actingAs($user)
         ->post(route('lifts.store'), $payload)
         ->assertRedirect(route('lifts.report', ['tab' => 'draft']))
         ->assertSessionHas('success', 'Lift draft saved successfully');
});

it('T75: saving a sale draft lands on the sales report with a confirmation', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $payload = salePayload($supplier, $shop, $product);
    $payload['save_as_draft'] = true;

    $this->actingAs($user)
         ->post(route('sales.store'), $payload)
         ->assertRedirect(route('sales.report', ['tab' => 'draft']))
         ->assertSessionHas('success', 'Sale draft saved successfully');
});

// ── T76–T79: editing a completed lift must not re-charge the deposit ────────

/** Deposit balance as the lift screen reports it. */
function depositBalance(Supplier $supplier, User $user): float
{
    return (float) collect(
        test()->actingAs($user)->get(route('lifts.index'))
              ->viewData('page')['props']['suppliers']
    )->firstWhere('id', $supplier->id)['remaining_deposit'];
}

it('T76: re-saving a completed lift unchanged does not draw the money twice', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);

    // 10 cases x 240 = 2400
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    expect(depositBalance($supplier, $user))->toBe(7600.0);

    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();

    // Open it from the list and save again with nothing changed.
    $again = liftPayload($supplier, $catalog);
    $again['draft_id'] = $lift->id;
    $this->actingAs($user)->post(route('lifts.store'), $again)->assertRedirect();

    // A second full charge would leave 5200.
    expect(depositBalance($supplier, $user))->toBe(7600.0)
        ->and(\App\Models\Lift::where('supplier_id', $supplier->id)->count())->toBe(1);
});

it('T77: raising a completed lift draws only the difference', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();

    // 10 -> 12 cases: 2880 total, so only 480 more should be taken.
    $bigger = liftPayload($supplier, $catalog, ['number_of_cases' => 12]);
    $bigger['draft_id'] = $lift->id;
    $this->actingAs($user)->post(route('lifts.store'), $bigger)->assertRedirect();

    expect(depositBalance($supplier, $user))->toBe(7120.0)
        ->and((float) $lift->fresh()->total_amount)->toBe(2880.0);
});

it('T78: lowering a completed lift credits the difference back', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();

    // 10 -> 5 cases: 1200 total, so 1200 goes back.
    $smaller = liftPayload($supplier, $catalog, ['number_of_cases' => 5]);
    $smaller['draft_id'] = $lift->id;
    $this->actingAs($user)->post(route('lifts.store'), $smaller)->assertRedirect();

    expect(depositBalance($supplier, $user))->toBe(8800.0);
});

it('T79: editing a completed lift three times still leaves one correct charge', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();

    foreach ([12, 8, 15] as $cases) {
        $edit = liftPayload($supplier, $catalog, ['number_of_cases' => $cases]);
        $edit['draft_id'] = $lift->id;
        $this->actingAs($user)->post(route('lifts.store'), $edit)->assertRedirect();
    }

    // Only the final state should be charged: 15 x 240 = 3600.
    expect(depositBalance($supplier, $user))->toBe(6400.0)
        ->and((float) $lift->fresh()->total_amount)->toBe(3600.0);
});

// ── T80–T82: editing a completed lift must not double the stock either ──────

it('T80: re-saving a completed lift keeps the stock and the batch count as they were', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    expect(getVariant(getProduct($supplier))['current_purchased_quantity'])->toBe(240)
        ->and(Product::where('supplier_id', $supplier->id)->count())->toBe(1);

    $lift  = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();
    $again = liftPayload($supplier, $catalog);
    $again['draft_id'] = $lift->id;
    $this->actingAs($user)->post(route('lifts.store'), $again)->assertRedirect();

    // 480 would mean the lift was counted twice; a second row would orphan the
    // sale_items that point at the first batch.
    expect(getVariant(getProduct($supplier))['current_purchased_quantity'])->toBe(240)
        ->and(Product::where('supplier_id', $supplier->id)->count())->toBe(1);
});

it('T81: editing a lift that already has sales keeps the sold bottles deducted', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    $product = getProduct($supplier);

    // Sell 2 cases: 240 - 48 = 192 left.
    $this->actingAs($saleUser)
         ->post(route('sales.store'), salePayload($supplier, $shop, $product))
         ->assertRedirect();
    expect(getVariant($product)['current_purchased_quantity'])->toBe(192);

    // Now correct the lift up to 12 cases: 288 lifted, 48 sold, 240 should remain.
    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();
    $edit = liftPayload($supplier, $catalog, ['number_of_cases' => 12]);
    $edit['draft_id'] = $lift->id;
    $this->actingAs($user)->post(route('lifts.store'), $edit)->assertRedirect();

    expect(getVariant($product)['current_purchased_quantity'])->toBe(240)
        ->and(Product::where('supplier_id', $supplier->id)->count())->toBe(1);
});

it('T82: a lift cannot be edited below what has already been sold', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);
    $saleUser = makeUser(['sales.add']);
    $shop     = makeShop();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    $product = getProduct($supplier);

    // Sell 5 cases = 120 bottles.
    $this->actingAs($saleUser)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold'            => 5,
        'total_bottles_to_sell' => 120,
    ]))->assertRedirect();

    // Try to shrink the lift to 2 cases (48 bottles) - fewer than the 120 sold.
    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();
    $edit = liftPayload($supplier, $catalog, ['number_of_cases' => 2]);
    $edit['draft_id'] = $lift->id;

    $this->actingAs($user)->post(route('lifts.store'), $edit)->assertSessionHasErrors('lift');

    // Rejected whole: the lift and the stock are untouched.
    expect((float) $lift->fresh()->total_amount)->toBe(2400.0)
        ->and(getVariant($product)['current_purchased_quantity'])->toBe(120)
        ->and(depositBalance($supplier, $user))->toBe(7600.0);
});

// ── T83–T84: editing a lift must not invent a deposit ───────────────────────

it('T83: editing a completed lift creates no extra deposit row', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    expect(Deposit::where('supplier_id', $supplier->id)->count())->toBe(1);

    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();

    // The edit screen must not think a top-up is needed, so no
    // deposit_from_here_amount goes with the update.
    $edit = liftPayload($supplier, $catalog, ['number_of_cases' => 12]);
    $edit['draft_id'] = $lift->id;
    $this->actingAs($user)->post(route('lifts.store'), $edit)->assertRedirect();

    expect(Deposit::where('supplier_id', $supplier->id)->count())->toBe(1)
        ->and(depositBalance($supplier, $user))->toBe(7120.0);
});

it('T84: the deposit ledger stays consistent across a lift edit', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10_000);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'lift.update']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();

    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();
    $edit = liftPayload($supplier, $catalog, ['number_of_cases' => 12]);
    $edit['draft_id'] = $lift->id;
    $this->actingAs($user)->post(route('lifts.store'), $edit)->assertRedirect();

    $deposit = Deposit::where('supplier_id', $supplier->id)->firstOrFail();

    // used + remaining must still add up to what was deposited, and used must
    // equal the lift's current value - not the sum of both saves.
    expect((float) $deposit->balance_used)->toBe(2880.0)
        ->and((float) $deposit->balance_remaining)->toBe(7120.0)
        ->and((float) $deposit->balance_used + (float) $deposit->balance_remaining)
        ->toBe((float) $deposit->balance_deposited);
});

// ── T85–T86: the reports carry the supplier identity grouping relies on ─────

it('T85: every sales report row names its supplier', function () {
    seedPermissions();
    $supplierA = makeSupplier();
    $supplierB = makeSupplier();
    $shop      = makeShop();
    $liftUser  = makeUser(['lift.add']);
    $user      = makeUser(['sales.add', 'sales.view']);

    foreach ([$supplierA, $supplierB] as $supplier) {
        seedDeposit($supplier);
        $catalog = makeCatalog($supplier);
        $this->actingAs($liftUser)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
        $this->actingAs($user)
             ->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier)))
             ->assertRedirect();
    }

    $rows = $this->actingAs($user)->get(route('sales.report'))->viewData('page')['props']['sales'];

    // Two suppliers, each row labelled - that label is what the list groups on.
    expect(collect($rows)->pluck('supplier_name')->unique()->values()->all())
        ->toHaveCount(2)
        ->and(collect($rows)->every(fn ($r) => filled($r['supplier_name'])))->toBeTrue();
});

it('T86: every lift report row carries its supplier relation', function () {
    seedPermissions();
    $supplierA = makeSupplier();
    $supplierB = makeSupplier();
    $user      = makeUser(['lift.add', 'lift.view']);

    foreach ([$supplierA, $supplierB, $supplierA] as $supplier) {
        seedDeposit($supplier);
        $catalog = makeCatalog($supplier);
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    }

    $rows = collect(
        $this->actingAs($user)->get(route('lifts.report'))->viewData('page')['props']['liftHistory']
    );

    expect($rows)->toHaveCount(3)
        ->and($rows->every(fn ($r) => filled(data_get($r, 'supplier.company_name'))))->toBeTrue()
        // Supplier A has two lifts, so the page has something to group.
        ->and($rows->groupBy(fn ($r) => data_get($r, 'supplier.company_name'))->get($supplierA->company_name))
        ->toHaveCount(2);
});

// ═══════════════════════════════════════════════════════════════════════════
//  T87–T94: ACL — a user must get exactly what was granted, no more
// ═══════════════════════════════════════════════════════════════════════════

/** The app's real permission list, not the trimmed one the other tests use. */
function seedAllPermissions(): void
{
    (new \Database\Seeders\PermissionSeeder())->run();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
}

/** Every guarded page in the app, keyed by the permission that opens it. */
function guardedPages(): array
{
    return [
        '/dashboard'        => 'dashboard.view',
        '/suppliers/index'  => 'supplier.view',
        '/suppliers/create' => 'supplier.add',
        '/deposits'         => 'deposit.view',
        '/categories/index' => 'category.view',
        '/brands/index'     => 'brand.view',
        '/lifts'            => 'lift.add',
        '/lifts/report'     => 'lift.view',
        '/shops'            => 'shop.view',
        '/shops/create'     => 'shop.add',
        '/sales'            => 'sales.add',
        '/sales/report'     => 'sales.view',
        '/sales/summary'    => 'sales.view',
        '/products'         => 'inventory.view',
        '/inventory/report' => 'inventory.view',
        '/expenses'         => 'expense.view',
        '/expenses/report'  => 'expense.view',
        '/profit-loss'      => 'report.profit-loss',
        '/roles'            => 'role.view',
        '/users'            => 'user.view',
    ];
}

it('T87: a user with no permissions is refused by every guarded page', function () {
    seedAllPermissions();
    $user = makeUser([]);

    foreach (array_keys(guardedPages()) as $path) {
        $response = $this->actingAs($user)->get($path);

        if ($path === '/dashboard') {
            $response->assertRedirect(route('home', absolute: false));
        } else {
            $response->assertForbidden();
        }
    }
});

it('T88: each page opens only for the permission it is guarded by', function () {
    seedAllPermissions();

    foreach (guardedPages() as $path => $permission) {
        // The one permission that should open it.
        $allowed = makeUser([$permission]);
        expect($this->actingAs($allowed)->get($path)->status())
            ->not->toBe(403, "{$path} should open for {$permission}");

        // Every other permission must not.
        $wrong = makeUser([$permission === 'sales.view' ? 'expense.view' : 'sales.view']);
        $response = $this->actingAs($wrong)->get($path);

        if ($path === '/dashboard') {
            $response->assertRedirect(route('home', absolute: false));
        } else {
            $response->assertForbidden();
        }
    }
});

it('T89: a view-only user cannot create, update or delete a sale', function () {
    [$supplier, $shop, $product, $seller] = saleFixture();

    $this->actingAs($seller)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    $viewer = makeUser(['sales.view', 'inventory.view']);

    $this->actingAs($viewer)->get(route('sales.report'))->assertOk();
    $this->actingAs($viewer)->get(route('sales.cash-memo', $sale->id))->assertOk();

    // Everything that changes data is closed to them.
    $this->actingAs($viewer)->get(route('sales.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertForbidden();
    $this->actingAs($viewer)->get(route('sales.edit', $sale->id))->assertForbidden();
    $this->actingAs($viewer)->put(route('sales.update', $sale->id), [])->assertForbidden();
    $this->actingAs($viewer)->delete(route('sales.destroy', $sale->id))->assertForbidden();
    $this->actingAs($viewer)->post(route('sales.payment.store', $sale->id), [])->assertForbidden();

    // And the sale is untouched.
    expect(salesOf($supplier)->count())->toBe(1);
});

it('T90: a sales user cannot reach lift, expense or ACL', function () {
    seedAllPermissions();
    $user = makeUser(['sales.add', 'sales.view', 'sales.update']);

    foreach (['/lifts', '/lifts/report', '/expenses', '/deposits', '/roles', '/users', '/products'] as $path) {
        $this->actingAs($user)->get($path)->assertForbidden();
    }

    $this->actingAs($user)->post(route('lifts.store'), [])->assertForbidden();
    $this->actingAs($user)->post(route('expenses.store'), [])->assertForbidden();
});

it('T91: the app tells the page exactly which permissions the user holds', function () {
    seedAllPermissions();
    $granted = ['sales.view', 'sales.add', 'inventory.view'];
    $user    = makeUser($granted);

    $shared = $this->actingAs($user)
        ->get(route('sales.report'))
        ->viewData('page')['props']['userPermissions'];

    // The sidebar hides everything not in this list, so it must match exactly.
    expect(collect($shared)->sort()->values()->all())->toBe(collect($granted)->sort()->values()->all());
});

it('T92: the API endpoints behind the sale screen are guarded too', function () {
    seedAllPermissions();
    $user = makeUser(['sales.view']); // view only - these need sales.add

    $this->actingAs($user)->get('/api/inventory/search?q=x')->assertForbidden();
    $this->actingAs($user)->get('/api/variant-inventory?product_id=1&variant=250ml')->assertForbidden();
    $this->actingAs($user)->get('/api/products-by-supplier?supplier_id=1')->assertForbidden();
    $this->actingAs($user)->get('/api/product-catalog/search?supplier_id=1')->assertForbidden();
});

it('T93: permissions granted through a role work like direct ones', function () {
    seedAllPermissions();

    $role = \App\Models\Role::firstOrCreate(
        ['name' => 'collector', 'guard_name' => 'web'],
        ['description' => 'Collects dues', 'is_active' => true]
    );
    $role->syncPermissions(['sales.view', 'sales.update']);

    $user = \App\Models\User::factory()->create();
    $user->syncRoles([$role->name]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user)->get(route('sales.report'))->assertOk();
    $this->actingAs($user)->get(route('sales.index'))->assertForbidden();  // no sales.add
    $this->actingAs($user)->get('/lifts')->assertForbidden();
});

it('T94: taking a permission away from a role locks the user out again', function () {
    seedAllPermissions();

    $role = \App\Models\Role::firstOrCreate(
        ['name' => 'temp-role', 'guard_name' => 'web'],
        ['description' => 'temp', 'is_active' => true]
    );
    $role->syncPermissions(['expense.view']);

    $user = \App\Models\User::factory()->create();
    $user->syncRoles([$role->name]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user)->get(route('expenses.index'))->assertOk();

    $role->syncPermissions([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user)->get(route('expenses.index'))->assertForbidden();
});

// ── T95–T97: deleting is its own permission ─────────────────────────────────

it('T95: every module that can be deleted has a delete permission of its own', function () {
    seedAllPermissions();

    $names = \Spatie\Permission\Models\Permission::pluck('name');

    foreach (['sales', 'lift', 'supplier', 'deposit', 'expense', 'category', 'brand', 'shop', 'role'] as $module) {
        expect($names)->toContain("{$module}.delete");
    }
});

it('T96: the manager role, described as "without delete", really cannot delete', function () {
    seedAllPermissions();
    (new \Database\Seeders\RoleSeeder())->run();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();

    $manager = \App\Models\User::factory()->create();
    $manager->syncRoles(['manager']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    // It can still do the operational work.
    $this->actingAs($manager)->post(route('lifts.store'), liftPayload($supplier, $catalog))->assertRedirect();
    $this->actingAs($manager)
         ->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier)))
         ->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();
    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();

    // But nothing can be destroyed, which is what the role promises.
    $this->actingAs($manager)->delete(route('sales.destroy', $sale->id))->assertForbidden();
    $this->actingAs($manager)->delete(route('lifts.destroy', $lift->id))->assertForbidden();
    $this->actingAs($manager)->delete(route('categories.delete', 1))->assertForbidden();

    expect(salesOf($supplier)->count())->toBe(1)
        ->and(\App\Models\Lift::where('supplier_id', $supplier->id)->count())->toBe(1);
});

it('T97: update lets you correct a sale but no longer lets you destroy it', function () {
    [$supplier, $shop, $product, $seller] = saleFixture();

    $this->actingAs($seller)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    // A corrector: may edit, may not delete.
    $corrector = makeUser(['sales.view', 'sales.update']);

    $this->actingAs($corrector)->get(route('sales.edit', $sale->id))->assertOk();
    $this->actingAs($corrector)->delete(route('sales.destroy', $sale->id))->assertForbidden();
    expect(salesOf($supplier)->count())->toBe(1);

    // A remover: may delete.
    $remover = makeUser(['sales.view', 'sales.delete']);
    $this->actingAs($remover)->delete(route('sales.destroy', $sale->id))->assertRedirect();
    expect(salesOf($supplier)->count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════════════════════
//  T98–T157: does every screen show the same inventory?
// ═══════════════════════════════════════════════════════════════════════════

/** A user who can see every inventory surface at once. */
function inventoryViewer(): User
{
    // Needs the full permission list - the trimmed helper has no dashboard.view.
    seedAllPermissions();

    return makeUser([
        'inventory.view', 'dashboard.view', 'sales.add', 'sales.view',
        'lift.add', 'lift.view', 'lift.delete', 'sales.update', 'sales.delete',
    ]);
}

/** Product List row (/products) for a catalog name. */
function listRow(User $user, string $name): ?array
{
    return collect(
        test()->actingAs($user)->get('/products?show_out_of_stock=1')
              ->viewData('page')['props']['products']['data']
    )->firstWhere('name', $name);
}

/** Inventory Report row (/inventory/report) for a product name. */
function reportRow(User $user, string $name): ?array
{
    return collect(
        test()->actingAs($user)->get('/inventory/report')
              ->viewData('page')['props']['inventoryStock']
    )->firstWhere('product_name', $name);
}

/** Dashboard inventory row for a product name. */
function dashRow(User $user, string $name): ?array
{
    return collect(
        test()->actingAs($user)->get('/dashboard')
              ->viewData('page')['props']['inventoryStock']
    )->firstWhere('product_name', $name);
}

/** What the sale screen's product search returns for a product name. */
function searchRow(User $user, string $name): ?array
{
    return collect(
        test()->actingAs($user)->getJson('/api/inventory/search?q=' . urlencode($name))->json()
    )->firstWhere('product_name', $name);
}

/** Bottles available for one variant, as each surface reports it. */
function bottlesEverywhere(User $user, string $name, string $variant = '500ml'): array
{
    $list   = listRow($user, $name);
    $report = reportRow($user, $name);
    $dash   = dashRow($user, $name);
    $search = searchRow($user, $name);

    $pick = fn (?array $row, string $key) => $row
        ? (int) (collect($row[$key] ?? [])->firstWhere('variant', $variant)['total_bottles_available']
            ?? collect($row[$key] ?? [])->firstWhere('variant', $variant)['stock_bottles']
            ?? -1)
        : -1;

    return [
        'list'   => $pick($list, 'default_variants'),
        'report' => $pick($report, 'variants'),
        'dash'   => $pick($dash, 'variants'),
        'search' => $pick($search, 'variants'),
    ];
}

// ── T98–T107: the four surfaces must agree ──────────────────────────────────

it('T98: a fresh lift shows the same bottles on all four screens', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $seen = bottlesEverywhere($user, $catalog->name);
    expect($seen)->toBe(['list' => 240, 'report' => 240, 'dash' => 240, 'search' => 240]);
});

it('T99: after a sale all four screens drop by the same amount', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 3, 'total_bottles_to_sell' => 72,
    ]))->assertRedirect();

    expect(bottlesEverywhere($user, $catalog->name))
        ->toBe(['list' => 168, 'report' => 168, 'dash' => 168, 'search' => 168]);
});

it('T100: a draft sale moves none of the four screens', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $payload = salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 3, 'total_bottles_to_sell' => 72,
    ]);
    $payload['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    expect(bottlesEverywhere($user, $catalog->name))
        ->toBe(['list' => 240, 'report' => 240, 'dash' => 240, 'search' => 240]);
});

it('T101: two batches of one product are added together on every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    foreach ([10, 6] as $cases) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    // 240 + 144
    expect(bottlesEverywhere($user, $catalog->name))
        ->toBe(['list' => 384, 'report' => 384, 'dash' => 384, 'search' => 384]);
});

it('T102: free bottles are counted as stock on every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    // 10 cases x 24 = 240 purchased, plus 2 free per case = 20
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 2,
    ]))->assertRedirect();

    expect(bottlesEverywhere($user, $catalog->name))
        ->toBe(['list' => 260, 'report' => 260, 'dash' => 260, 'search' => 260]);
});

it('T103: selling everything leaves zero on every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 10, 'total_bottles_to_sell' => 240,
    ]))->assertRedirect();

    expect(bottlesEverywhere($user, $catalog->name))
        ->toBe(['list' => 0, 'report' => 0, 'dash' => 0, 'search' => 0]);
});

it('T104: deleting a sale puts the bottles back on every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 3, 'total_bottles_to_sell' => 72,
    ]))->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();
    $this->actingAs($user)->delete(route('sales.destroy', $sale->id))->assertRedirect();

    expect(bottlesEverywhere($user, $catalog->name))
        ->toBe(['list' => 240, 'report' => 240, 'dash' => 240, 'search' => 240]);
});

it('T105: cases shown are the bottles divided by bottles-per-case', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    // Sell 5 bottles, leaving 235 -> 9 whole cases with 19 loose bottles.
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 0, 'extra_bottles' => 5, 'total_bottles_to_sell' => 5,
    ]))->assertRedirect();

    $report = reportRow($user, $catalog->name);
    $list   = listRow($user, $catalog->name);

    expect((int) $report['total_available_bottles'])->toBe(235)
        ->and((int) $report['total_available_cases'])->toBe(9)
        ->and((int) $list['stock_bottles'])->toBe(235)
        ->and((int) $list['stock_cases'])->toBe(9);
});

it('T106: two variants of one product are summed, not muddled', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $payload = liftPayload($supplier, $catalog);
    $payload['items'][0]['variants'] = [
        ['variant' => '250ml', 'number_of_cases' => 5, 'case_buying_price' => 200, 'bottles_per_case' => 24, 'free_bottles_per_case' => 0],
        ['variant' => '500ml', 'number_of_cases' => 4, 'case_buying_price' => 300, 'bottles_per_case' => 12, 'free_bottles_per_case' => 0],
    ];
    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    $report = reportRow($user, $catalog->name);
    $byVariant = collect($report['variants'])->keyBy('variant');

    expect((int) $byVariant['250ml']['total_bottles_available'])->toBe(120)
        ->and((int) $byVariant['500ml']['total_bottles_available'])->toBe(48)
        ->and((int) $report['total_available_bottles'])->toBe(168)
        // 5 whole cases of 24 + 4 whole cases of 12
        ->and((int) $report['total_available_cases'])->toBe(9);
});

it('T107: a product with no lift yet reports zero rather than vanishing', function () {
    seedPermissions();
    $supplier = makeSupplier();
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $list = listRow($user, $catalog->name);

    expect($list)->not->toBeNull()
        ->and((int) $list['stock_bottles'])->toBe(0)
        ->and((int) $list['purchase_batches_count'])->toBe(0);
});

// ── T108–T121: harder inventory cases ───────────────────────────────────────

it('T108: two suppliers selling a same-named product keep separate stock', function () {
    seedPermissions();
    $user = inventoryViewer();

    $a = makeSupplier();
    $b = makeSupplier();
    seedDeposit($a);
    seedDeposit($b);

    // Same product name, two different suppliers - a real case for Coca-Cola,
    // Pran Up and so on when two depots carry it.
    $catalogA = makeCatalog($a, 'Shared Cola');
    $catalogB = makeCatalog($b, 'Shared Cola');

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($a, $catalogA, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($b, $catalogB, [
        'variant' => '500ml', 'number_of_cases' => 4, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $rows = collect(
        $this->actingAs($user)->get('/inventory/report')
             ->viewData('page')['props']['inventoryStock']
    )->where('product_name', 'Shared Cola')->values();

    // Each supplier's stock must stand on its own: 240 and 96, never merged.
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('supplier_id')->unique())->toHaveCount(2)
        ->and($rows->sum('total_available_bottles'))->toBe(336);
});

it('T109: the product list gives each supplier its own stock for a shared name', function () {
    seedPermissions();
    $user = inventoryViewer();

    $a = makeSupplier();
    $b = makeSupplier();
    seedDeposit($a);
    seedDeposit($b);
    $catalogA = makeCatalog($a, 'Shared Cola');
    $catalogB = makeCatalog($b, 'Shared Cola');

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($a, $catalogA, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($b, $catalogB, [
        'variant' => '500ml', 'number_of_cases' => 4, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $rows = collect(
        $this->actingAs($user)->get('/products?show_out_of_stock=1')
             ->viewData('page')['props']['products']['data']
    )->where('name', 'Shared Cola')->keyBy('supplier_name');

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[$a->company_name]['stock_bottles'])->toBe(240)
        ->and((int) $rows[$b->company_name]['stock_bottles'])->toBe(96);
});

it('T110: selling from one supplier does not move the other supplier stock', function () {
    seedPermissions();
    $user = inventoryViewer();
    $shop = makeShop();

    $a = makeSupplier();
    $b = makeSupplier();
    seedDeposit($a);
    seedDeposit($b);
    $catalogA = makeCatalog($a, 'Shared Cola');
    $catalogB = makeCatalog($b, 'Shared Cola');

    foreach ([[$a, $catalogA, 10], [$b, $catalogB, 4]] as [$supplier, $catalog, $cases]) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    $productA = Product::where('supplier_id', $a->id)->firstOrFail();
    $this->actingAs($user)->post(route('sales.store'), salePayload($a, $shop, $productA, [
        'variant' => '500ml', 'cases_sold' => 2, 'total_bottles_to_sell' => 48,
    ]))->assertRedirect();

    $rows = collect(
        $this->actingAs($user)->get('/inventory/report')
             ->viewData('page')['props']['inventoryStock']
    )->where('product_name', 'Shared Cola')->keyBy('supplier_id');

    expect((int) $rows[$a->id]['total_available_bottles'])->toBe(192)
        ->and((int) $rows[$b->id]['total_available_bottles'])->toBe(96);
});

it('T111: the inventory report can look at stock as it was on an earlier date', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $payload = liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]);
    $payload['lift_date'] = now()->subDays(5)->toDateString();
    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    $rows = collect(
        $this->actingAs($user)
             ->get('/inventory/report?snapshot_date=' . now()->subDays(10)->toDateString())
             ->viewData('page')['props']['inventoryStock']
    )->firstWhere('product_name', $catalog->name);

    // The lift had not happened yet on that date.
    expect($rows)->toBeNull();
});

it('T112: stock is never reported as a negative number', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 10, 'total_bottles_to_sell' => 240,
    ]))->assertRedirect();

    $report = reportRow($user, $catalog->name);

    foreach ($report['variants'] as $variant) {
        expect((int) $variant['total_bottles_available'])->toBeGreaterThanOrEqual(0)
            ->and((int) $variant['purchased_bottles_available'])->toBeGreaterThanOrEqual(0)
            ->and((int) $variant['free_bottles_available'])->toBeGreaterThanOrEqual(0)
            ->and((int) $variant['cases_available'])->toBeGreaterThanOrEqual(0);
    }
});

it('T113: the product list hides sold-out products unless asked, the report never does', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 10, 'total_bottles_to_sell' => 240,
    ]))->assertRedirect();

    // A sold-out product still has a batch, so it stays listed either way, but
    // the report must always carry it so the zero is visible.
    expect(reportRow($user, $catalog->name))->not->toBeNull()
        ->and((int) reportRow($user, $catalog->name)['total_available_bottles'])->toBe(0);
});

it('T114: the variant API and the report agree for a single-batch product', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 1,
    ]))->assertRedirect();

    $product = getProduct($supplier);
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'variant' => '500ml', 'cases_sold' => 2, 'total_bottles_to_sell' => 48,
    ]))->assertRedirect();

    $api = $this->actingAs($user)
        ->getJson('/api/variant-inventory?product_id=' . $product->id . '&variant=500ml')
        ->json();

    $reportVariant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');

    expect((int) $api['purchased_bottles_available'])->toBe((int) $reportVariant['purchased_bottles_available'])
        ->and((int) $api['free_bottles_available'])->toBe((int) $reportVariant['free_bottles_available']);
});

it('T115: editing a lift upward raises the stock on every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();
    $edit = liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 15, 'bottles_per_case' => 24,
    ]);
    $edit['draft_id'] = $lift->id;
    $this->actingAs($user)->post(route('lifts.store'), $edit)->assertRedirect();

    expect(bottlesEverywhere($user, $catalog->name))
        ->toBe(['list' => 360, 'report' => 360, 'dash' => 360, 'search' => 360]);
});

it('T116: deleting an unsold lift removes its stock from every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->firstOrFail();
    $this->actingAs($user)->delete(route('lifts.destroy', $lift->id))->assertRedirect();

    expect(reportRow($user, $catalog->name))->toBeNull()
        ->and((int) listRow($user, $catalog->name)['stock_bottles'])->toBe(0);
});

it('T117: the product list purchase-batch count matches the real batches', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    foreach ([5, 6, 7] as $cases) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    $list = listRow($user, $catalog->name);

    expect((int) $list['purchase_batches_count'])
        ->toBe(Product::where('product_catalog_id', $catalog->id)->count())
        ->toBe(3);
});

// ── T118–T131: money and counts across dashboard, reports and sale items ────

/** Dashboard props for a given day. */
function dashProps(User $user, ?string $date = null): array
{
    $date ??= now()->toDateString();

    return test()->actingAs($user)
        ->get('/dashboard?daily_sales_date=' . $date)
        ->viewData('page')['props'];
}

it('T118: dashboard inventory rows match the inventory report exactly', function () {
    seedPermissions();
    $user = inventoryViewer();
    $shop = makeShop();

    foreach ([['A', 10], ['B', 6]] as [$suffix, $cases]) {
        $supplier = makeSupplier();
        seedDeposit($supplier);
        $catalog  = makeCatalog($supplier, 'Cross Check ' . $suffix);
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    $dash   = collect(dashProps($user)['inventoryStock']);
    $report = collect(
        $this->actingAs($user)->get('/inventory/report')->viewData('page')['props']['inventoryStock']
    );

    $key = fn ($row) => $row['supplier_id'] . '::' . $row['product_name'];

    expect($dash->count())->toBe($report->count());
    foreach ($dash as $row) {
        $match = $report->first(fn ($r) => $key($r) === $key($row));
        expect($match)->not->toBeNull()
            ->and((int) $match['total_available_bottles'])->toBe((int) $row['total_available_bottles'])
            ->and((int) $match['total_available_cases'])->toBe((int) $row['total_available_cases']);
    }
});

it('T119: the sales report total equals the sum of its own rows', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    foreach ([1, 2, 3] as $cases) {
        $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
            'cases_sold' => $cases, 'total_bottles_to_sell' => $cases * 24,
        ]))->assertRedirect();
    }

    $rows = collect($this->actingAs($user)->get(route('sales.report'))->viewData('page')['props']['sales']);

    expect(round($rows->sum(fn ($r) => (float) $r['total_amount']), 2))
        ->toBe(round((float) \App\Models\Sale::where('supplier_id', $supplier->id)->sum('total_amount'), 2));
});

it('T120: a sale row profit equals the sum of that sale item profits', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold' => 3, 'total_bottles_to_sell' => 72,
    ]))->assertRedirect();

    $sale = salesOf($supplier)->firstOrFail();
    $row  = collect($this->actingAs($user)->get(route('sales.report'))->viewData('page')['props']['sales'])
        ->firstWhere('id', $sale->id);

    expect(round((float) $row['total_profit'], 2))
        ->toBe(round((float) \App\Models\SaleItem::where('sale_id', $sale->id)->sum('profit'), 2));
});

it('T121: report revenue minus profit equals the cost of what was sold', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold' => 4, 'total_bottles_to_sell' => 96, 'selling_price_per_bottle' => 15,
    ]))->assertRedirect();

    $row = collect($this->actingAs($user)->get(route('sales.report'))->viewData('page')['props']['sales'])->first();

    // 96 bottles at 15 = 1440 revenue; the lift cost 10 per bottle.
    expect(round((float) $row['total_amount'], 2))->toBe(1440.0)
        ->and(round((float) $row['total_amount'] - (float) $row['total_profit'], 2))->toBe(960.0);
});

it('T122: dashboard due for the day equals the sales report due for the same day', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 300, 'payment_method' => 'cash',
    ]);

    $reportDue = collect($this->actingAs($user)->get(route('sales.report'))->viewData('page')['props']['sales'])
        ->sum(fn ($r) => (float) $r['due_amount']);

    expect(round($reportDue, 2))->toBe(round((float) $sale->fresh()->due_amount, 2))
        ->toBe(420.0);
});

it('T123: a draft is left out of the sales totals but still listed', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();

    $draft = salePayload($supplier, $shop, $product);
    $draft['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $draft)->assertRedirect();

    $rows = collect($this->actingAs($user)->get(route('sales.report'))->viewData('page')['props']['sales']);

    expect($rows)->toHaveCount(2)
        ->and($rows->where('status', 'draft'))->toHaveCount(1);

    // The dashboard's money must ignore the draft: one 720 sale counted once.
    $monthly = dashProps($user)['monthlySales'];
    expect(round((float) $monthly['total_sales'], 2))->toBe(720.0)
        ->and(round((float) $monthly['due_amount'], 2))->toBe(720.0);
});

it('T124: the dashboard counts a sale on its sale_date, like the report does', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    // Entered today, but dated three days ago - a normal catch-up entry.
    $backdated = array_merge(
        salePayload($supplier, $shop, $product),
        ['sale_date' => now()->subDays(3)->toDateString()]
    );
    $this->actingAs($user)->post(route('sales.store'), $backdated)->assertRedirect();

    $today = dashProps($user, now()->toDateString());
    $then  = dashProps($user, now()->subDays(3)->toDateString());

    // It belongs to the day it was made on, not the day it was typed in.
    expect((int) $today['totalShopsSold'])->toBe(0)
        ->and((int) $then['totalShopsSold'])->toBe(1);
});

it('T125: cases sold on the dashboard match the sale items for that day', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    foreach ([2, 3] as $cases) {
        $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
            'cases_sold' => $cases, 'total_bottles_to_sell' => $cases * 24,
        ]))->assertRedirect();
    }

    $props = dashProps($user);

    expect((int) $props['totalCasesSold'])
        ->toBe((int) \App\Models\SaleItem::whereHas('sale', fn ($q) => $q->where('supplier_id', $supplier->id))->sum('cases_sold'))
        ->toBe(5);
});

it('T126: lift totals on the dashboard match the lift report', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    foreach ([10, 5] as $cases) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
            'case_buying_price' => 240,
        ]))->assertRedirect();
    }

    $props   = dashProps($user);
    $history = collect($this->actingAs($user)->get(route('lifts.report'))->viewData('page')['props']['liftHistory']);

    expect((float) $props['totalLiftAmount'])
        ->toBe(round($history->sum(fn ($l) => (float) $l['total_amount']), 2))
        ->toBe(3600.0)
        ->and((int) $props['totalLiftCases'])->toBe(15)
        ->and((int) $props['totalLiftBottles'])->toBe(360);
});

it('T127: low-stock list only holds products actually under the threshold', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $low  = makeCatalog($supplier, 'Nearly Gone');
    $full = makeCatalog($supplier, 'Plenty Left');

    foreach ([[$low, 1], [$full, 20]] as [$catalog, $cases]) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    // Take the low one down to 4 bottles.
    $lowBatch = Product::where('name', 'Nearly Gone')->firstOrFail();
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $lowBatch, [
        'variant' => '500ml', 'cases_sold' => 0, 'extra_bottles' => 20, 'total_bottles_to_sell' => 20,
    ]))->assertRedirect();

    $names = collect(dashProps($user)['lowStockProducts'])->pluck('product_name');

    expect($names)->toContain('Nearly Gone')
        ->and($names)->not->toContain('Plenty Left');
});

it('T128: top selling products reflect what was actually sold', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $busy = makeCatalog($supplier, 'Fast Mover');
    $slow = makeCatalog($supplier, 'Slow Mover');

    foreach ([$busy, $slow] as $catalog) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => 20, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, Product::where('name', 'Fast Mover')->firstOrFail(), [
        'variant' => '500ml', 'cases_sold' => 10, 'total_bottles_to_sell' => 240,
    ]))->assertRedirect();
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, Product::where('name', 'Slow Mover')->firstOrFail(), [
        'variant' => '500ml', 'cases_sold' => 1, 'total_bottles_to_sell' => 24,
    ]))->assertRedirect();

    $top = collect(dashProps($user)['topSellingProducts']);

    expect(data_get($top->first(), 'name'))->toBe('Fast Mover');
});

it('T129: the shop dues on the dashboard match what the sale still owes', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 200, 'payment_method' => 'cash',
    ]);

    $row = collect(dashProps($user)['shops'])->firstWhere('shop_id', $shop->id);

    expect($row)->not->toBeNull()
        ->and(round((float) $row['total_due'], 2))->toBe(round((float) $sale->fresh()->due_amount, 2))
        ->toBe(520.0);
});

it('T130: the profit and loss page agrees with the sale item profits', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product, [
        'cases_sold' => 5, 'total_bottles_to_sell' => 120,
    ]))->assertRedirect();

    $props = $this->actingAs($user)->get(route('profit-loss.index'))->viewData('page')['props'];
    $stored = round((float) \App\Models\SaleItem::sum('profit'), 2);

    $reported = collect($props)->first(fn ($v, $k) => str_contains(strtolower((string) $k), 'profit') && is_numeric($v));

    expect($reported === null ? $stored : round((float) $reported, 2))->toBe($stored);
});

it('T131: the stock value on the report is the bottles times their cost', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    // 10 cases x 24 bottles at 240 per case = 10 per bottle.
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'case_buying_price' => 240,
    ]))->assertRedirect();

    $report = reportRow($user, $catalog->name);

    expect(round((float) $report['total_stock_value'], 2))->toBe(2400.0);
});

// ── T132–T157: the remaining corners ────────────────────────────────────────

it('T132: a draft sale does not make a product look like a top seller', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $real  = makeCatalog($supplier, 'Really Sold');
    $draft = makeCatalog($supplier, 'Only Drafted');

    foreach ([$real, $draft] as $catalog) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => 20, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, Product::where('name', 'Really Sold')->firstOrFail(), [
        'variant' => '500ml', 'cases_sold' => 1, 'total_bottles_to_sell' => 24,
    ]))->assertRedirect();

    $draftPayload = salePayload($supplier, $shop, Product::where('name', 'Only Drafted')->firstOrFail(), [
        'variant' => '500ml', 'cases_sold' => 15, 'total_bottles_to_sell' => 360,
    ]);
    $draftPayload['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $draftPayload)->assertRedirect();

    $names = collect(dashProps($user)['topSellingProducts'])->pluck('name');

    // The draft moved no stock, so it cannot be the best seller.
    expect($names)->toContain('Really Sold')
        ->and($names)->not->toContain('Only Drafted');
});

it('T133: deleting a sale takes it back out of the top sellers', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    $this->actingAs($user)->delete(route('sales.destroy', $sale->id))->assertRedirect();

    expect(collect(dashProps($user)['topSellingProducts'])->pluck('name'))
        ->not->toContain($product->name);
});

it('T134: the search endpoint and the report agree on available bottles', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    foreach ([10, 4] as $cases) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 5, 'total_bottles_to_sell' => 120,
    ]))->assertRedirect();

    $search = searchRow($user, $catalog->name);
    $report = reportRow($user, $catalog->name);

    expect((int) $search['total_available_bottles'])->toBe((int) $report['total_available_bottles'])
        ->toBe(216)
        ->and((int) $search['total_available_cases'])->toBe((int) $report['total_available_cases']);
});

it('T135: the sale screen never offers more bottles than the report shows', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $offered = (int) searchRow($user, $catalog->name)['total_available_bottles'];

    // Selling exactly what is offered must succeed; one more must not.
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 0, 'extra_bottles' => $offered,
        'total_bottles_to_sell' => $offered,
    ]))->assertRedirect();

    expect((int) searchRow($user, $catalog->name)['total_available_bottles'])->toBe(0);
});

it('T136: adjusting stock by hand shows up on every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $product = getProduct($supplier);

    $response = $this->actingAs($user)->put(route('inventory.adjust-stock'), [
        'product_id' => $product->id,
        'variant'    => '500ml',
        'purchased_bottles' => 200,
        'free_bottles'      => 0,
    ]);

    // Whatever the endpoint decides, the screens must not disagree with each other.
    $seen = bottlesEverywhere($user, $catalog->name);
    expect($seen['list'])->toBe($seen['report'])
        ->and($seen['report'])->toBe($seen['dash'])
        ->and($seen['dash'])->toBe($seen['search']);
});

it('T137: a second variant added by a later lift appears on every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '250ml', 'number_of_cases' => 5, 'bottles_per_case' => 12,
    ]))->assertRedirect();

    $report = collect(reportRow($user, $catalog->name)['variants'])->pluck('variant');
    $list   = collect(listRow($user, $catalog->name)['default_variants'])->pluck('variant');

    expect($report)->toContain('500ml')->toContain('250ml')
        ->and($list)->toContain('500ml')->toContain('250ml');
});

it('T138: total bottles on a row equal the sum of that row variants', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $payload = liftPayload($supplier, $catalog);
    $payload['items'][0]['variants'] = [
        ['variant' => '250ml', 'number_of_cases' => 5, 'case_buying_price' => 200, 'bottles_per_case' => 24, 'free_bottles_per_case' => 1],
        ['variant' => '500ml', 'number_of_cases' => 4, 'case_buying_price' => 300, 'bottles_per_case' => 12, 'free_bottles_per_case' => 0],
        ['variant' => '1000ml', 'number_of_cases' => 3, 'case_buying_price' => 400, 'bottles_per_case' => 6, 'free_bottles_per_case' => 2],
    ];
    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    $report = reportRow($user, $catalog->name);

    expect((int) $report['total_available_bottles'])
        ->toBe((int) collect($report['variants'])->sum('total_bottles_available'))
        ->and((int) $report['total_available_cases'])
        ->toBe((int) collect($report['variants'])->sum('cases_available'));
});

it('T139: bottles sold on the report equal the sale items for that product', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    foreach ([2, 3] as $cases) {
        $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
            'variant' => '500ml', 'cases_sold' => $cases, 'total_bottles_to_sell' => $cases * 24,
        ]))->assertRedirect();
    }

    $report = reportRow($user, $catalog->name);

    expect((int) $report['total_bottles_sold'])
        ->toBe((int) \App\Models\SaleItem::whereHas('sale', fn ($q) => $q->where('supplier_id', $supplier->id))->sum('total_bottles_sold'))
        ->toBe(120);
});

it('T140: lifted plus free minus sold equals what is left', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    // 10 cases x 24 = 240 purchased, 10 free
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 1,
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 3, 'total_bottles_to_sell' => 72,
    ]))->assertRedirect();

    $variant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');

    $lifted = (int) $variant['purchased_bottles_total'] + (int) $variant['free_bottles_total'];
    $left   = (int) $variant['total_bottles_available'];
    $sold   = (int) $variant['total_bottles_sold'];

    expect($lifted - $sold)->toBe($left);
});

it('T141: an expense lands on its expense date, not the day it was typed', function () {
    seedAllPermissions();
    $user = makeUser(['expense.view', 'expense.add', 'dashboard.view']);

    $this->actingAs($user)->post(route('expenses.store'), [
        'reason'       => 'Backdated fuel',
        'category'     => 'Fuel',
        'description'  => 'test',
        'amount'       => 500,
        'expense_date' => now()->subDays(4)->toDateString(),
    ]);

    $today = dashProps($user, now()->toDateString());
    $then  = dashProps($user, now()->subDays(4)->toDateString());

    expect(round((float) $today['todaysExpensesAmount'], 2))->toBe(0.0)
        ->and(round((float) $then['todaysExpensesAmount'], 2))->toBe(500.0);
});

it('T142: the daily sales graph counts a sale on its sale date', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $when = now()->subDays(2)->toDateString();
    $this->actingAs($user)->post(route('sales.store'), array_merge(
        salePayload($supplier, $shop, $product),
        ['sale_date' => $when]
    ))->assertRedirect();

    $graph = collect(
        $this->actingAs($user)
             ->get('/dashboard?graph_start_date=' . now()->subDays(5)->toDateString()
                 . '&graph_end_date=' . now()->toDateString())
             ->viewData('page')['props']['dateWiseSalesData']
    );

    $row = $graph->first(fn ($r) => str_starts_with((string) data_get($r, 'sale_date'), $when));
    expect($row)->not->toBeNull();
});

it('T143: the monthly figures ignore a sale dated outside the month', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), array_merge(
        salePayload($supplier, $shop, $product),
        ['sale_date' => now()->subMonths(2)->toDateString()]
    ))->assertRedirect();

    $monthly = dashProps($user)['monthlySales'];
    expect(round((float) $monthly['total_sales'], 2))->toBe(0.0);
});

it('T144: paying a sale moves paid and due together on the dashboard', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();
    $sale = salesOf($supplier)->firstOrFail();

    $this->actingAs($user)->post(route('sales.payment.store', $sale->id), [
        'payment_amount' => 420, 'payment_method' => 'cash',
    ]);

    $monthly = dashProps($user)['monthlySales'];

    expect(round((float) $monthly['total_sales'], 2))->toBe(720.0)
        ->and(round((float) $monthly['paid_amount'], 2))->toBe(420.0)
        ->and(round((float) $monthly['due_amount'], 2))->toBe(300.0)
        ->and(round((float) $monthly['paid_amount'] + (float) $monthly['due_amount'], 2))->toBe(720.0);
});

it('T145: the product list stock equals the sum of its variant stock', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $payload = liftPayload($supplier, $catalog);
    $payload['items'][0]['variants'] = [
        ['variant' => '250ml', 'number_of_cases' => 5, 'case_buying_price' => 200, 'bottles_per_case' => 24, 'free_bottles_per_case' => 0],
        ['variant' => '500ml', 'number_of_cases' => 4, 'case_buying_price' => 300, 'bottles_per_case' => 12, 'free_bottles_per_case' => 0],
    ];
    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    $list = listRow($user, $catalog->name);

    expect((int) $list['stock_bottles'])
        ->toBe((int) collect($list['default_variants'])->sum('stock_bottles'))
        ->toBe(168);
});

it('T146: searching the product list does not change the numbers it shows', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier, 'Findable Drink');
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $unfiltered = listRow($user, 'Findable Drink');
    $filtered = collect(
        $this->actingAs($user)->get('/products?show_out_of_stock=1&search=Findable')
             ->viewData('page')['props']['products']['data']
    )->firstWhere('name', 'Findable Drink');

    expect((int) $filtered['stock_bottles'])->toBe((int) $unfiltered['stock_bottles'])
        ->and((int) $filtered['stock_cases'])->toBe((int) $unfiltered['stock_cases']);
});

it('T147: a product list search by supplier name finds the product', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $rows = collect(
        $this->actingAs($user)
             ->get('/products?show_out_of_stock=1&search=' . urlencode($supplier->company_name))
             ->viewData('page')['props']['products']['data']
    );

    expect($rows->pluck('name'))->toContain($catalog->name);
});

it('T148: a sale of only free bottles leaves the purchased pool alone', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 2,
    ]))->assertRedirect();

    // Excluding free bottles means only purchased stock may move.
    $payload = salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 2, 'total_bottles_to_sell' => 48,
    ]);
    $payload['include_free_bottles'] = false;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    $variant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');

    expect((int) $variant['free_bottles_available'])->toBe(20)
        ->and((int) $variant['purchased_bottles_available'])->toBe(192);
});

it('T149: a soft-deleted batch is gone from every screen', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    foreach ([10, 5] as $cases) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    expect((int) reportRow($user, $catalog->name)['total_available_bottles'])->toBe(360);

    $lift = \App\Models\Lift::where('supplier_id', $supplier->id)->orderByDesc('id')->firstOrFail();
    $this->actingAs($user)->delete(route('lifts.destroy', $lift->id))->assertRedirect();

    expect(bottlesEverywhere($user, $catalog->name))
        ->toBe(['list' => 240, 'report' => 240, 'dash' => 240, 'search' => 240]);
});

it('T150: the report is not thrown off by a product with no variants', function () {
    seedPermissions();
    $supplier = makeSupplier();
    $catalog  = makeCatalog($supplier, 'Empty Catalogue');
    $user     = inventoryViewer();

    $this->actingAs($user)->get('/inventory/report')->assertOk();
    $this->actingAs($user)->get('/products?show_out_of_stock=1')->assertOk();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(reportRow($user, 'Empty Catalogue'))->toBeNull();
});

it('T151: every inventory screen loads with no data at all', function () {
    seedAllPermissions();
    $user = makeUser(['inventory.view', 'dashboard.view', 'sales.view', 'sales.add', 'lift.view']);

    $this->actingAs($user)->get('/products')->assertOk();
    $this->actingAs($user)->get('/inventory/report')->assertOk();
    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->actingAs($user)->get(route('lifts.report'))->assertOk();
    $this->actingAs($user)->getJson('/api/inventory/search?q=')->assertOk();
});

it('T152: three batches at different prices give a blended bottle cost', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    // 10 cases at 240 and 10 at 360 -> 20 cases, 4800 total, 480 bottles = 10 each
    foreach ([240, 360] as $price) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
            'case_buying_price' => $price,
        ]))->assertRedirect();
    }

    $variant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');

    expect(round((float) $variant['unit_price'], 2))->toBe(12.5)
        ->and((int) $variant['total_bottles_available'])->toBe(480);
});

it('T153: stock value across the report equals bottles times their blended cost', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    foreach ([240, 360] as $price) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
            'case_buying_price' => $price,
        ]))->assertRedirect();
    }

    $report = reportRow($user, $catalog->name);
    $variant = collect($report['variants'])->firstWhere('variant', '500ml');

    expect(round((float) $report['total_stock_value'], 2))
        ->toBe(round((float) $variant['unit_price'] * (int) $variant['total_bottles_available'], 2));
});

it('T154: a lift dated in the future stays out of today figures', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $payload = liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
    ]);
    $payload['lift_date'] = now()->addDays(3)->toDateString();
    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    // Today's snapshot must not count stock that has not arrived yet.
    expect(reportRow($user, $catalog->name))->toBeNull();
});

it('T155: the same product on two screens never disagrees after mixed activity', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    foreach ([10, 8] as $cases) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
            'free_bottles_per_case' => 1,
        ]))->assertRedirect();
    }

    foreach ([2, 1, 3] as $cases) {
        $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
            'variant' => '500ml', 'cases_sold' => $cases, 'total_bottles_to_sell' => $cases * 24,
        ]))->assertRedirect();
    }

    $draft = salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 4, 'total_bottles_to_sell' => 96,
    ]);
    $draft['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $draft)->assertRedirect();

    $seen = bottlesEverywhere($user, $catalog->name);

    expect($seen['list'])->toBe($seen['report'])
        ->and($seen['report'])->toBe($seen['dash'])
        ->and($seen['dash'])->toBe($seen['search'])
        ->and($seen['list'])->toBeGreaterThan(0);
});

it('T156: two suppliers with a shared product name keep separate dashboard rows', function () {
    seedPermissions();
    $user = inventoryViewer();

    $a = makeSupplier();
    $b = makeSupplier();
    seedDeposit($a);
    seedDeposit($b);

    foreach ([[$a, 10], [$b, 4]] as [$supplier, $cases]) {
        $catalog = makeCatalog($supplier, 'Twin Named');
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    $rows = collect(dashProps($user)['inventoryStock'])->where('product_name', 'Twin Named');

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('total_available_bottles')->sort()->values()->all())->toBe([96, 240]);
});

it('T157: low stock counts each supplier product separately', function () {
    seedPermissions();
    $user = inventoryViewer();

    $a = makeSupplier();
    $b = makeSupplier();
    seedDeposit($a);
    seedDeposit($b);

    // One supplier is nearly out, the other is well stocked.
    foreach ([[$a, 1], [$b, 30]] as [$supplier, $cases]) {
        $catalog = makeCatalog($supplier, 'Twin Named');
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 1,
        ]))->assertRedirect();
    }

    $low = collect(dashProps($user)['lowStockProducts'])->where('product_name', 'Twin Named');

    // Only the short one should be flagged, not both and not neither.
    expect($low)->toHaveCount(1)
        ->and((int) $low->first()['supplier_id'])->toBe($a->id);
});

// ── T158–T162: which purchase price a sale is costed at ─────────────────────

/** Lift the same variant twice at two different case prices. */
function twoPriceFixture(int $firstPrice, int $secondPrice, int $cases = 10): array
{
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    foreach ([[30, $firstPrice], [10, $secondPrice]] as [$daysAgo, $price]) {
        $payload = liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
            'case_buying_price' => $price,
        ]);
        $payload['lift_date'] = now()->subDays($daysAgo)->toDateString();
        test()->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();
    }

    return [$supplier, $shop, $catalog, $user];
}

it('T158: a sale is costed at the price of the batch the bottles came from', function () {
    [$supplier, $shop, $catalog, $user] = twoPriceFixture(300, 500);

    // Sell 5 cases at 25 a bottle: 120 x 25 = 3000 revenue. Those 120 bottles all
    // come out of the older 300 batch, which holds 240.
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 5, 'total_bottles_to_sell' => 120,
        'selling_price_per_bottle' => 25,
    ]))->assertRedirect();

    $item = \App\Models\SaleItem::latest('id')->firstOrFail();

    // Cost 120 x (300/24) = 1500, so profit is 1500.
    expect(round((float) $item->total_price, 2))->toBe(3000.0)
        ->and(round((float) $item->profit, 2))->toBe(1500.0);
});

it('T158a: a sale spanning both batches is charged part at each price', function () {
    [$supplier, $shop, $catalog, $user] = twoPriceFixture(300, 500);

    // 300 bottles: the 240 cheap ones, then 60 from the dearer batch.
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 0, 'extra_bottles' => 300,
        'total_bottles_to_sell' => 300, 'selling_price_per_bottle' => 25,
    ]))->assertRedirect();

    $item = \App\Models\SaleItem::latest('id')->firstOrFail();
    $cost = round((float) $item->total_price - (float) $item->profit, 2);

    // 240 x 12.50 + 60 x 20.8333 = 3000 + 1250 = 4250
    expect($cost)->toBe(4250.0);
});

it('T159: the value of remaining stock rises as the cheaper stock runs out', function () {
    [$supplier, $shop, $catalog, $user] = twoPriceFixture(300, 500);

    $before = (float) collect(reportRow($user, $catalog->name)['variants'])
        ->firstWhere('variant', '500ml')['unit_price'];

    // Stock leaves oldest-first, so this eats 5 of the 10 cheap cases.
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 5, 'total_bottles_to_sell' => 120,
        'selling_price_per_bottle' => 25,
    ]))->assertRedirect();

    $after = (float) collect(reportRow($user, $catalog->name)['variants'])
        ->firstWhere('variant', '500ml')['unit_price'];

    // 120 left at 300 and 240 at 500 -> 433.33 a case.
    expect(round($before * 24, 2))->toBe(400.0)
        ->and(round($after * 24, 2))->toBe(433.33)
        ->and($after)->toBeGreaterThan($before);
});

it('T160: once the cheap stock is gone the remaining stock is valued at the later price', function () {
    [$supplier, $shop, $catalog, $user] = twoPriceFixture(300, 500);

    // Clear all 10 cheap cases.
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 10, 'total_bottles_to_sell' => 240,
        'selling_price_per_bottle' => 25,
    ]))->assertRedirect();

    $variant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');

    expect(round((float) $variant['unit_price'] * 24, 2))->toBe(500.0)
        ->and((int) $variant['total_bottles_available'])->toBe(240);
});

it('T161: the sale screen is given the batches it needs to quote the real cost', function () {
    [$supplier, $shop, $catalog, $user] = twoPriceFixture(300, 500);

    $quoted = collect(searchRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');
    $batches = collect($quoted['cost_batches']);

    // Oldest first, each with its own price, so the cart can walk them exactly as
    // the server does. Without this the screen and the books would disagree.
    expect($batches)->toHaveCount(2)
        ->and((float) $batches[0]['case_buying_price'])->toBe(300.0)
        ->and((float) $batches[1]['case_buying_price'])->toBe(500.0)
        ->and((int) $batches[0]['available_purchased'])->toBe(240);

    // Walking those batches for 120 bottles gives the cost the sale then records.
    $quotedCost = round(120 * (300 / 24), 2);

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 5, 'total_bottles_to_sell' => 120,
        'selling_price_per_bottle' => 25,
    ]))->assertRedirect();

    $item = \App\Models\SaleItem::latest('id')->firstOrFail();

    expect(round((float) $item->total_price - (float) $item->profit, 2))->toBe($quotedCost);
});

it('T162: order of lifting does not change how the remaining stock is valued', function () {
    [$supplierA, $shopA, $catalogA, $userA] = twoPriceFixture(300, 500);
    $cheapFirst = (float) collect(reportRow($userA, $catalogA->name)['variants'])
        ->firstWhere('variant', '500ml')['unit_price'];

    [$supplierB, $shopB, $catalogB, $userB] = twoPriceFixture(500, 300);
    $dearFirst = (float) collect(reportRow($userB, $catalogB->name)['variants'])
        ->firstWhere('variant', '500ml')['unit_price'];

    expect(round($cheapFirst, 4))->toBe(round($dearFirst, 4));
});

// ── T163–T164: does the cost charged add up to the cost paid? ────────────────

it('T163: selling the whole stock in one go charges exactly what was paid', function () {
    [$supplier, $shop, $catalog, $user] = twoPriceFixture(300, 500);

    // 10 cases at 300 + 10 at 500 = 8000 paid for 480 bottles.
    $paid = round((float) \App\Models\Lift::where('supplier_id', $supplier->id)->sum('total_amount'), 2);
    expect($paid)->toBe(8000.0);

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 20, 'total_bottles_to_sell' => 480,
        'selling_price_per_bottle' => 25,
    ]))->assertRedirect();

    $charged = \App\Models\SaleItem::whereHas('sale', fn ($q) => $q->where('supplier_id', $supplier->id))
        ->get()
        ->sum(fn ($i) => (float) $i->total_price - (float) $i->profit);

    expect(round($charged, 2))->toBe($paid);
});

it('T164: selling the same stock in three goes still charges what was paid', function () {
    [$supplier, $shop, $catalog, $user] = twoPriceFixture(300, 500);

    $paid = round((float) \App\Models\Lift::where('supplier_id', $supplier->id)->sum('total_amount'), 2);

    // Same 480 bottles, just spread over three sales as they would be in real life.
    foreach ([120, 120, 240] as $bottles) {
        $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
            'variant' => '500ml', 'cases_sold' => 0, 'extra_bottles' => $bottles,
            'total_bottles_to_sell' => $bottles, 'selling_price_per_bottle' => 25,
        ]))->assertRedirect();
    }

    $charged = \App\Models\SaleItem::whereHas('sale', fn ($q) => $q->where('supplier_id', $supplier->id))
        ->get()
        ->sum(fn ($i) => (float) $i->total_price - (float) $i->profit);

    // The books must not report a different cost just because it took three trips.
    expect(round($charged, 2))->toBe($paid);
});

it('T165: the same mismatch appears in reverse when prices fall', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    // Dear stock first, cheap stock second, on distinct dates so FIFO is definite.
    foreach ([[30, 500], [10, 300]] as [$daysAgo, $price]) {
        $payload = liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
            'case_buying_price' => $price,
        ]);
        $payload['lift_date'] = now()->subDays($daysAgo)->toDateString();
        $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();
    }

    foreach ([120, 120, 240] as $bottles) {
        $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
            'variant' => '500ml', 'cases_sold' => 0, 'extra_bottles' => $bottles,
            'total_bottles_to_sell' => $bottles, 'selling_price_per_bottle' => 25,
        ]))->assertRedirect();
    }

    $paid = round((float) \App\Models\Lift::where('supplier_id', $supplier->id)->sum('total_amount'), 2);
    $charged = round(\App\Models\SaleItem::whereHas('sale', fn ($q) => $q->where('supplier_id', $supplier->id))
        ->get()->sum(fn ($i) => (float) $i->total_price - (float) $i->profit), 2);

    expect($charged)->toBe($paid);
});

// ── T166–T168: the free-bottle toggle still behaves exactly as before ────────

it('T166: the subtotal with free bottles included is unchanged', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    // 10 cases x 24 bottles, 2 free per case.
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 2, 'case_buying_price' => 240,
    ]))->assertRedirect();

    // Toggle ON: a case is 26 bottles, priced across all 26.
    // (salePayload defaults the toggle to off, so it has to be turned on here.)
    $payload = salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 2, 'extra_bottles' => 0,
        'total_bottles_to_sell' => 52, 'free_bottles_per_case' => 2,
        'selling_price_per_bottle' => 15,
    ]);
    $payload['include_free_bottles'] = true;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    $item = \App\Models\SaleItem::latest('id')->firstOrFail();

    // Revenue is bottles x price, untouched by how cost is worked out.
    expect(round((float) $item->total_price, 2))->toBe(780.0)   // 52 x 15
        ->and((int) $item->purchased_bottles_sold)->toBe(48)
        ->and((int) $item->free_bottles_sold)->toBe(4)
        ->and((int) $item->total_bottles_sold)->toBe(52);
});

it('T167: the subtotal with free bottles excluded is unchanged', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 2, 'case_buying_price' => 240,
    ]))->assertRedirect();

    // Toggle OFF: only the 24 paid bottles of each case leave.
    $payload = salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 2, 'extra_bottles' => 0,
        'total_bottles_to_sell' => 48, 'free_bottles_per_case' => 2,
        'selling_price_per_bottle' => 15,
    ]);
    $payload['include_free_bottles'] = false;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    $item = \App\Models\SaleItem::latest('id')->firstOrFail();

    expect(round((float) $item->total_price, 2))->toBe(720.0)   // 48 x 15
        ->and((int) $item->purchased_bottles_sold)->toBe(48)
        ->and((int) $item->free_bottles_sold)->toBe(0)
        ->and((int) $item->total_bottles_sold)->toBe(48);

    // The free bottles stayed on the shelf and were not charged to this sale.
    $variant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');
    expect((int) $variant['free_bottles_available'])->toBe(20);
});

it('T168: with one batch the toggle costs exactly what it always did', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    // One batch only, so FIFO and the old average must give the same answer:
    // 240 a case over 26 effective bottles = 9.2308 a bottle.
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 2, 'case_buying_price' => 240,
    ]))->assertRedirect();

    $payload = salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 2, 'extra_bottles' => 0,
        'total_bottles_to_sell' => 48, 'free_bottles_per_case' => 2,
        'selling_price_per_bottle' => 15,
    ]);
    $payload['include_free_bottles'] = false;
    $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect();

    $item = \App\Models\SaleItem::latest('id')->firstOrFail();
    $cost = round((float) $item->total_price - (float) $item->profit, 2);

    // 48 bottles x (240 / 26) = 443.08 - the same figure the old code produced.
    expect($cost)->toBe(443.08);
});

// ── T169–T173: a short deposit no longer blocks a lift ──────────────────────

it('T169: a lift bigger than the deposit goes through and leaves a negative balance', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10);          // only 10 on account
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view']);

    // 1 case at 15 - five more than the supplier's balance covers.
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'number_of_cases' => 1, 'case_buying_price' => 15, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    expect(round((float) Deposit::where('supplier_id', $supplier->id)->sum('balance_remaining'), 2))
        ->toBe(-5.0)
        // The lift itself was recorded in full.
        ->and(round((float) \App\Models\Lift::where('supplier_id', $supplier->id)->sum('total_amount'), 2))->toBe(15.0)
        ->and(Product::where('supplier_id', $supplier->id)->count())->toBe(1);
});

it('T170: the next deposit settles what was owed', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view', 'deposit.add', 'deposit.view']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'number_of_cases' => 1, 'case_buying_price' => 15, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $balance = fn () => round((float) Deposit::where('supplier_id', $supplier->id)->sum('balance_remaining'), 2);
    expect($balance())->toBe(-5.0);

    // Paying in another 10 leaves 5 genuinely available.
    Deposit::create([
        'supplier_id'       => $supplier->id,
        'balance_deposited' => 10,
        'balance_remaining' => 10,
        'deposit_date'      => now()->toDateString(),
        'is_used'           => false,
    ]);

    expect($balance())->toBe(5.0);
});

it('T171: the lift screen shows the settled balance after a top-up', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'number_of_cases' => 1, 'case_buying_price' => 15, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    $shown = fn () => (float) collect(
        test()->actingAs($user)->get(route('lifts.index'))->viewData('page')['props']['suppliers']
    )->firstWhere('id', $supplier->id)['remaining_deposit'];

    // The negative has to reach the screen, not be clamped to zero.
    expect($shown())->toBe(-5.0);

    Deposit::create([
        'supplier_id'       => $supplier->id,
        'balance_deposited' => 10,
        'balance_remaining' => 10,
        'deposit_date'      => now()->toDateString(),
        'is_used'           => false,
    ]);

    expect($shown())->toBe(5.0);
});

it('T172: a further lift keeps digging from the negative balance', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 10);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view']);

    foreach ([15, 20] as $price) {
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'number_of_cases' => 1, 'case_buying_price' => $price, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    // 10 in, 35 lifted -> 25 owed.
    expect(round((float) Deposit::where('supplier_id', $supplier->id)->sum('balance_remaining'), 2))
        ->toBe(-25.0);
});

it('T173: a lift for a supplier with no deposit at all still records', function () {
    seedPermissions();
    $supplier = makeSupplier();          // no deposit row whatsoever
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view']);

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'number_of_cases' => 2, 'case_buying_price' => 100, 'bottles_per_case' => 24,
    ]))->assertRedirect();

    expect(round((float) Deposit::where('supplier_id', $supplier->id)->sum('balance_remaining'), 2))
        ->toBe(-200.0)
        ->and(Product::where('supplier_id', $supplier->id)->count())->toBe(1);
});

// ── T174–T176: one stock value, shared by every screen ──────────────────────

it('T174: the report and the dashboard are handed the same stock value', function () {
    seedPermissions();
    $user = inventoryViewer();

    // A free-bottle product is where the two used to diverge.
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 30, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 1, 'case_buying_price' => 720,
    ]))->assertRedirect();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 4, 'total_bottles_to_sell' => 96,
    ]))->assertRedirect();

    $report = reportRow($user, $catalog->name);
    $dash   = dashRow($user, $catalog->name);

    expect(round((float) $report['total_stock_value'], 2))
        ->toBe(round((float) $dash['total_stock_value'], 2));
});

it('T175: stock value is the bottles left times their blended rate', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    // 30 cases x 24 = 720 purchased + 30 free = 750 bottles for 21,600.
    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 30, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 1, 'case_buying_price' => 720,
    ]))->assertRedirect();

    $variant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');

    // Counting whole cases instead would give floor(750/24)=31 x 720 = 22,320 -
    // more than was ever paid, because the free bottles inflate the case count.
    expect((int) $variant['total_bottles_available'])->toBe(750)
        ->and(round((float) reportRow($user, $catalog->name)['total_stock_value'], 2))->toBe(21600.0);
});

it('T176: across the whole catalogue, lifted equals sold cost plus stock value', function () {
    seedPermissions();
    $user = inventoryViewer();
    $shop = makeShop();

    foreach ([[10, 300, 0], [8, 480, 2], [5, 640, 1]] as $i => [$cases, $price, $free]) {
        $supplier = makeSupplier();
        seedDeposit($supplier);
        $catalog  = makeCatalog($supplier, 'Balanced ' . $i);

        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 24,
            'free_bottles_per_case' => $free, 'case_buying_price' => $price,
        ]))->assertRedirect();

        // The sale must declare the same free-per-case the lift did, exactly as
        // the sale screen does - it is what spreads the case price over the
        // bottles a case really hands over.
        $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
            'variant' => '500ml', 'cases_sold' => 2, 'total_bottles_to_sell' => 48,
            'free_bottles_per_case' => $free,
        ]))->assertRedirect();
    }

    $lifted = round((float) \App\Models\Lift::sum('total_amount'), 2);
    $sold   = round(\App\Models\SaleItem::get()->sum(fn ($i) => (float) $i->total_price - (float) $i->profit), 2);
    $shelf  = round(app(\App\Contracts\ProductPurchaseContract::class)->getInventoryStock()->sum('total_stock_value'), 2);

    expect(round($sold + $shelf, 2))->toEqualWithDelta($lifted, 0.05);
});

// ── T177–T180: money is computed once, on the server ────────────────────────

it('T177: a product stock value is exactly the sum of its variant values', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $payload = liftPayload($supplier, $catalog);
    $payload['items'][0]['variants'] = [
        ['variant' => '250ml', 'number_of_cases' => 10, 'case_buying_price' => 300, 'bottles_per_case' => 24, 'free_bottles_per_case' => 2],
        ['variant' => '500ml', 'number_of_cases' => 6,  'case_buying_price' => 480, 'bottles_per_case' => 12, 'free_bottles_per_case' => 0],
        ['variant' => '1000ml', 'number_of_cases' => 4, 'case_buying_price' => 640, 'bottles_per_case' => 6,  'free_bottles_per_case' => 1],
    ];
    $this->actingAs($user)->post(route('lifts.store'), $payload)->assertRedirect();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '250ml', 'cases_sold' => 3, 'total_bottles_to_sell' => 72,
        'free_bottles_per_case' => 2,
    ]))->assertRedirect();

    $row = reportRow($user, $catalog->name);

    // Every screen prints these; if the parts stopped adding to the whole, one
    // screen would disagree with another again.
    expect(round((float) $row['total_stock_value'], 2))
        ->toBe(round(collect($row['variants'])->sum(fn ($v) => (float) $v['stock_value']), 2));
});

it('T178: every variant carries a ready-made stock value', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 2, 'case_buying_price' => 240,
    ]))->assertRedirect();

    $variant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');

    // 10 cases at 240 = 2400 for 260 bottles. Counting cases instead would give
    // floor(260/24)=10 x 240 = 2400 here, but 11 x 240 once any bottle is sold.
    expect($variant)->toHaveKey('stock_value')
        ->and(round((float) $variant['stock_value'], 2))->toBe(2400.0);
});

it('T179: the value survives a sale that leaves a part-case behind', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $shop     = makeShop();
    $user     = inventoryViewer();

    $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 10, 'bottles_per_case' => 24,
        'free_bottles_per_case' => 2, 'case_buying_price' => 240,
    ]))->assertRedirect();

    // Sell 5 loose bottles: 255 left, which is not a whole number of cases.
    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, getProduct($supplier), [
        'variant' => '500ml', 'cases_sold' => 0, 'extra_bottles' => 5,
        'total_bottles_to_sell' => 5, 'free_bottles_per_case' => 2,
    ]))->assertRedirect();

    $variant = collect(reportRow($user, $catalog->name)['variants'])->firstWhere('variant', '500ml');
    $rate    = 240 / 26;

    expect((int) $variant['total_bottles_available'])->toBe(255)
        ->and(round((float) $variant['stock_value'], 2))->toBe(round(255 * $rate, 2));
});

it('T180: the inventory screens never do money arithmetic of their own', function () {
    // An architecture guard, not a behaviour test. The report and the dashboard
    // drifted apart because one of them multiplied a price by a quantity in the
    // template instead of printing the figure the server sends. Values are
    // computed once, in ProductPurchaseRepository; screens only display them.
    $screens = [
        'resources/js/Pages/InventoryManagement/InventoryReport.vue',
        'resources/js/Pages/InventoryManagement/ProductList.vue',
        'resources/js/Pages/Dashboard/Partials/InventoryStock.vue',
    ];

    // Any multiplication involving a price or rate field is the thing to catch.
    $moneyMaths = '/(case_buying_price|purchase_rate|unit_price|rate_per_bottle)\s*(\?\?[^*\n]*)?\)*\s*\*|\*\s*[^;\n]*(case_buying_price|purchase_rate|unit_price|rate_per_bottle)/i';

    foreach ($screens as $screen) {
        expect(file_exists(base_path($screen)))->toBeTrue("missing screen: {$screen}");

        $offenders = collect(preg_split('/\R/', file_get_contents(base_path($screen))))
            ->filter(fn ($line) => preg_match($moneyMaths, $line) === 1)
            ->values();

        expect($offenders->all())->toBe([], sprintf(
            '%s works out money itself; print the value the server sends instead. Offending line(s): %s',
            $screen,
            $offenders->implode(' | ')
        ));
    }
});

// ── T181–T183: the product list must not count what has not arrived ─────────

it('T181: a lift dated tomorrow changes neither the stock nor the purchase value', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 500000);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $today = liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 30, 'bottles_per_case' => 100,
        'case_buying_price' => 400,
    ]);
    $today['lift_date'] = now()->toDateString();
    $this->actingAs($user)->post(route('lifts.store'), $today)->assertRedirect();

    $before = listRow($user, $catalog->name);
    expect((int) $before['stock_bottles'])->toBe(3000)
        ->and(round((float) $before['total_purchase_amount'], 2))->toBe(12000.0);

    // Entered now, dated tomorrow - what a server running a day behind produces.
    $tomorrow = liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 2, 'bottles_per_case' => 100,
        'case_buying_price' => 500,
    ]);
    $tomorrow['lift_date'] = now()->addDay()->toDateString();
    $this->actingAs($user)->post(route('lifts.store'), $tomorrow)->assertRedirect();

    $after = listRow($user, $catalog->name);

    // Both figures hold still: the money must not move without the bottles.
    expect((int) $after['stock_bottles'])->toBe(3000)
        ->and((int) $after['stock_cases'])->toBe(30)
        ->and(round((float) $after['total_purchase_amount'], 2))->toBe(12000.0)
        ->and((int) $after['purchase_batches_count'])->toBe(1);
});

it('T182: the product list and the inventory report agree once the day arrives', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 500000);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    // Both lifts dated today, so nothing is held back.
    foreach ([[30, 400], [2, 500]] as [$cases, $price]) {
        $p = liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => $cases, 'bottles_per_case' => 100,
            'case_buying_price' => $price,
        ]);
        $p['lift_date'] = now()->toDateString();
        $this->actingAs($user)->post(route('lifts.store'), $p)->assertRedirect();
    }

    $list   = listRow($user, $catalog->name);
    $report = reportRow($user, $catalog->name);

    expect((int) $list['stock_bottles'])->toBe((int) $report['total_available_bottles'])
        ->toBe(3200)
        ->and((int) $list['stock_cases'])->toBe((int) $report['total_available_cases'])
        ->and(round((float) $list['total_purchase_amount'], 2))->toBe(13000.0);
});

it('T183: a variant that only exists in a future lift shows no stock and no value', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier, 500000);
    $catalog  = makeCatalog($supplier);
    $user     = inventoryViewer();

    $today = liftPayload($supplier, $catalog, [
        'variant' => '500ml', 'number_of_cases' => 30, 'bottles_per_case' => 100,
        'case_buying_price' => 400,
    ]);
    $today['lift_date'] = now()->toDateString();
    $this->actingAs($user)->post(route('lifts.store'), $today)->assertRedirect();

    $later = liftPayload($supplier, $catalog, [
        'variant' => '250ml', 'number_of_cases' => 33, 'bottles_per_case' => 24,
        'case_buying_price' => 409,
    ]);
    $later['lift_date'] = now()->addDay()->toDateString();
    $this->actingAs($user)->post(route('lifts.store'), $later)->assertRedirect();

    $row = listRow($user, $catalog->name);
    $future = collect($row['default_variants'])->firstWhere('variant', '250ml');

    // It may be listed - the catalogue knows the size now - but it must carry
    // neither stock nor money until the lift date comes round.
    expect((int) ($future['stock_bottles'] ?? 0))->toBe(0)
        ->and(round((float) ($future['total_purchase_amount'] ?? 0), 2))->toBe(0.0)
        ->and(round((float) $row['total_purchase_amount'], 2))->toBe(12000.0);
});

// ── T184–T187: you land on the tab holding what you just saved ──────────────

it('T184: saving a sale draft lands on the report asking for the Drafts tab', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $payload = salePayload($supplier, $shop, $product);
    $payload['save_as_draft'] = true;

    $this->actingAs($user)
         ->post(route('sales.store'), $payload)
         ->assertRedirect(route('sales.report', ['tab' => 'draft']));
});

it('T185: saving a lift draft lands on the Drafts tab', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view']);

    $payload = liftPayload($supplier, $catalog);
    $payload['save_as_draft'] = true;

    $this->actingAs($user)
         ->post(route('lifts.store'), $payload)
         ->assertRedirect(route('lifts.report', ['tab' => 'draft']));
});

it('T186: recording a lift lands on the completed tab', function () {
    seedPermissions();
    $supplier = makeSupplier();
    seedDeposit($supplier);
    $catalog  = makeCatalog($supplier);
    $user     = makeUser(['lift.add', 'lift.view']);

    $this->actingAs($user)
         ->post(route('lifts.store'), liftPayload($supplier, $catalog))
         ->assertRedirect(route('lifts.report', ['tab' => 'completed']));
});

it('T187: the tab in the url does not change what the report loads', function () {
    [$supplier, $shop, $product, $user] = saleFixture();

    $this->actingAs($user)->post(route('sales.store'), salePayload($supplier, $shop, $product))->assertRedirect();

    $draft = salePayload($supplier, $shop, $product);
    $draft['save_as_draft'] = true;
    $this->actingAs($user)->post(route('sales.store'), $draft)->assertRedirect();

    // The tab is a display choice; both rows must still be sent either way.
    foreach ([['tab' => 'draft'], ['tab' => 'completed'], []] as $query) {
        $rows = $this->actingAs($user)
            ->get(route('sales.report', $query))
            ->viewData('page')['props']['sales'];

        expect($rows)->toHaveCount(2);
    }
});

// ── T188–T189: the sale search must be groupable by supplier ────────────────

it('T188: search results carry the supplier so the sale screen can group them', function () {
    seedPermissions();
    $user = inventoryViewer();

    $globe  = makeSupplier();
    $partex = makeSupplier();

    foreach ([[$globe, ['Globe Lemon', 'Globe Orange', 'Globe Cola']],
              [$partex, ['Partex Water', 'Partex Cola']]] as [$supplier, $names]) {
        seedDeposit($supplier);
        foreach ($names as $name) {
            $catalog = makeCatalog($supplier, $name);
            $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
                'variant' => '500ml', 'number_of_cases' => 5, 'bottles_per_case' => 24,
            ]))->assertRedirect();
        }
    }

    $rows = collect($this->actingAs($user)->getJson('/api/inventory/search?q=')->json());

    // Every row names its supplier, and the two suppliers stay distinct.
    expect($rows->every(fn ($r) => filled($r['supplier_name']) && filled($r['supplier_id'])))->toBeTrue()
        ->and($rows->groupBy('supplier_id'))->toHaveCount(2)
        ->and($rows->where('supplier_id', $globe->id))->toHaveCount(3)
        ->and($rows->where('supplier_id', $partex->id))->toHaveCount(2);
});

it('T189: searching one supplier name returns only that supplier products', function () {
    seedPermissions();
    $user = inventoryViewer();

    $globe  = makeSupplier();
    $partex = makeSupplier();

    foreach ([[$globe, 'Globe Lemon'], [$globe, 'Globe Orange'], [$partex, 'Partex Water']] as [$supplier, $name]) {
        seedDeposit($supplier);
        $catalog = makeCatalog($supplier, $name);
        $this->actingAs($user)->post(route('lifts.store'), liftPayload($supplier, $catalog, [
            'variant' => '500ml', 'number_of_cases' => 5, 'bottles_per_case' => 24,
        ]))->assertRedirect();
    }

    $rows = collect($this->actingAs($user)->getJson('/api/inventory/search?q=Globe')->json());

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('supplier_id')->unique()->all())->toBe([$globe->id]);
});

// ── T190–T193: expenses reach the dashboard ─────────────────────────────────

it('T190: an expense created through the form carries its date', function () {
    seedAllPermissions();
    $user = makeUser(['expense.add', 'expense.view']);

    $this->actingAs($user)->post(route('expenses.store'), [
        'reason'       => 'Delivery van fuel',
        'category'     => 'Fuel',
        'description'  => 'test',
        'amount'       => 4500,
        'expense_date' => now()->toDateString(),
    ]);

    $expense = \App\Models\Expense::latest('id')->firstOrFail();

    // Deliberately uncast on the model: the date input needs a plain Y-m-d
    // string, and a date cast would serialise it as an ISO timestamp instead.
    expect($expense->expense_date)->not->toBeNull()
        ->and((string) $expense->expense_date)->toBe(now()->toDateString());
});

it('T191: today expenses show on the dashboard', function () {
    seedAllPermissions();
    $user = makeUser(['expense.add', 'expense.view', 'dashboard.view']);

    $this->actingAs($user)->post(route('expenses.store'), [
        'reason' => 'Fuel', 'category' => 'Fuel', 'amount' => 4500,
        'expense_date' => now()->toDateString(),
    ]);

    $props = dashProps($user);

    expect(round((float) $props['todaysExpensesAmount'], 2))->toBe(4500.0)
        ->and(round((float) $props['monthlyExpenses']['total_amount'], 2))->toBe(4500.0)
        ->and((int) $props['monthlyExpenses']['total_expenses'])->toBe(1);
});

it('T192: an expense with no date still counts, on the day it was recorded', function () {
    seedAllPermissions();
    $user = makeUser(['expense.add', 'expense.view', 'dashboard.view']);

    // Rows created before the form had a date field look like this.
    \App\Models\Expense::create([
        'reason'       => 'Legacy expense',
        'category'     => 'Misc',
        'description'  => 'no date on file',
        'amount'       => 6000,
        'expense_date' => null,
    ]);

    $props = dashProps($user);

    // It must not vanish from the dashboard just because the column is null.
    expect(round((float) $props['todaysExpensesAmount'], 2))->toBe(6000.0)
        ->and(round((float) $props['monthlyExpenses']['total_amount'], 2))->toBe(6000.0);
});

it('T193: a backdated expense lands on its own day, not today', function () {
    seedAllPermissions();
    $user = makeUser(['expense.add', 'expense.view', 'dashboard.view']);

    $this->actingAs($user)->post(route('expenses.store'), [
        'reason' => 'Warehouse rent', 'category' => 'Rent', 'amount' => 25000,
        'expense_date' => now()->subDays(4)->toDateString(),
    ]);

    expect(round((float) dashProps($user, now()->toDateString())['todaysExpensesAmount'], 2))->toBe(0.0)
        ->and(round((float) dashProps($user, now()->subDays(4)->toDateString())['todaysExpensesAmount'], 2))->toBe(25000.0);
});

// ── T194–T197: the expense report and the dashboard date alike ──────────────

it('T194: a backdated expense lands in the month it was spent', function () {
    seedAllPermissions();
    $user = makeUser(['expense.add', 'expense.view', 'dashboard.view']);

    // Spent last month, typed in today.
    $spent = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);

    $this->actingAs($user)->post(route('expenses.store'), [
        'reason' => 'Warehouse rent', 'category' => 'Rent', 'amount' => 25000,
        'expense_date' => $spent->toDateString(),
    ]);

    $lastMonth = $this->actingAs($user)
        ->get(route('expenses.report', ['month' => $spent->month, 'year' => $spent->year]))
        ->viewData('page')['props']['report'];

    $thisMonth = $this->actingAs($user)
        ->get(route('expenses.report', ['month' => now()->month, 'year' => now()->year]))
        ->viewData('page')['props']['report'];

    expect(round((float) $lastMonth['total'], 2))->toBe(25000.0)
        ->and(round((float) $thisMonth['total'], 2))->toBe(0.0);
});

it('T195: an expense with no date still appears in the month it was recorded', function () {
    seedAllPermissions();
    $user = makeUser(['expense.view']);

    \App\Models\Expense::create([
        'reason' => 'Legacy', 'category' => 'Misc', 'description' => null,
        'amount' => 6000, 'expense_date' => null,
    ]);

    $report = $this->actingAs($user)
        ->get(route('expenses.report', ['month' => now()->month, 'year' => now()->year]))
        ->viewData('page')['props']['report'];

    expect(round((float) $report['total'], 2))->toBe(6000.0);
});

it('T196: every expense row carries the date the screens display', function () {
    seedAllPermissions();
    $user = makeUser(['expense.add', 'expense.view']);

    $this->actingAs($user)->post(route('expenses.store'), [
        'reason' => 'Fuel', 'category' => 'Fuel', 'amount' => 500,
        'expense_date' => now()->subDays(2)->toDateString(),
    ]);
    \App\Models\Expense::create(['reason' => 'Legacy', 'amount' => 100, 'expense_date' => null]);

    $rows = collect($this->actingAs($user)
        ->get(route('expenses.report'))
        ->viewData('page')['props']['report']['detailed']);

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r) => filled($r['effective_date'])))->toBeTrue();

    // The dated one shows its own day; the undated one falls back to today.
    expect($rows->firstWhere('reason', 'Fuel')['effective_date'])->toBe(now()->subDays(2)->toDateString())
        ->and($rows->firstWhere('reason', 'Legacy')['effective_date'])->toBe(now()->toDateString());
});

it('T197: the expense report total matches what the dashboard counts', function () {
    seedAllPermissions();
    $user = makeUser(['expense.add', 'expense.view', 'dashboard.view']);

    // Anchored to this month so the run date cannot push one into the previous
    // month and skew the expected total.
    foreach ([[0, 4500], [1, 2100], [2, 1700]] as [$offset, $amount]) {
        $this->actingAs($user)->post(route('expenses.store'), [
            'reason' => 'Cost ' . $offset, 'category' => 'Misc', 'amount' => $amount,
            'expense_date' => now()->startOfMonth()->addDays($offset)->toDateString(),
        ]);
    }
    // Plus a legacy row with no date at all.
    \App\Models\Expense::create(['reason' => 'Legacy', 'amount' => 735, 'expense_date' => null]);

    $reportTotal = (float) $this->actingAs($user)
        ->get(route('expenses.report'))
        ->viewData('page')['props']['report']['total'];

    $dashTotal = (float) dashProps($user)['monthlyExpenses']['total_amount'];

    expect(round($reportTotal, 2))->toBe(round($dashTotal, 2))
        ->toBe(9035.0);
});

// ── T198–T200: existing production rows must read exactly as before ─────────

/** The eight rows the live system already holds, all with no expense_date. */
function productionLikeExpenses(): void
{
    $rows = [
        ['Extra Expanse', 'Miscellaneous', 'Nasta', 135],
        ['Babol S R', 'Salary', 'Advance', 2020],
        ['Oill', 'Transport', 'Oill repine', 2100],
        ['Babol S R', 'Salary', 'Buy nodols', 160],
        ['Babol S R', 'Salary', 'Advance', 200],
        ['Babol S R', 'Salary', 'Advance', 300],
        ['Unload', 'Miscellaneous', 'Products unload', 1700],
        ['D S R', 'Salary', "Three day's da,together", 420],
    ];

    foreach ($rows as [$reason, $category, $description, $amount]) {
        \App\Models\Expense::create([
            'reason'       => $reason,
            'category'     => $category,
            'description'  => $description,
            'amount'       => $amount,
            'expense_date' => null,   // as they are on the live system today
        ]);
    }
}

it('T198: the live expense figures are unchanged by the date rework', function () {
    seedAllPermissions();
    $user = makeUser(['expense.view']);
    productionLikeExpenses();

    $report = $this->actingAs($user)
        ->get(route('expenses.report'))
        ->viewData('page')['props']['report'];

    // The totals the client is looking at right now.
    expect(round((float) $report['total'], 2))->toBe(7035.0)
        ->and($report['detailed'])->toHaveCount(8);

    $byCategory = collect($report['summary']);
    expect(round((float) $byCategory['Salary'], 2))->toBe(3100.0)
        ->and(round((float) $byCategory['Transport'], 2))->toBe(2100.0)
        ->and(round((float) $byCategory['Miscellaneous'], 2))->toBe(1835.0);
});

it('T199: dated and undated expenses live together without double counting', function () {
    seedAllPermissions();
    $user = makeUser(['expense.add', 'expense.view', 'dashboard.view']);

    productionLikeExpenses();                       // 7,035 with no dates
    $this->actingAs($user)->post(route('expenses.store'), [
        'reason' => 'New fuel', 'category' => 'Transport', 'amount' => 965,
        'expense_date' => now()->toDateString(),    // the first row with a date
    ]);

    $report = $this->actingAs($user)->get(route('expenses.report'))->viewData('page')['props']['report'];
    $dash   = dashProps($user)['monthlyExpenses'];

    expect(round((float) $report['total'], 2))->toBe(8000.0)
        ->and($report['detailed'])->toHaveCount(9)
        ->and(round((float) $dash['total_amount'], 2))->toBe(8000.0)
        ->and((int) $dash['total_expenses'])->toBe(9);
});

it('T200: an undated expense keeps showing the day it was recorded', function () {
    seedAllPermissions();
    $user = makeUser(['expense.view']);
    productionLikeExpenses();

    $rows = collect($this->actingAs($user)
        ->get(route('expenses.report'))
        ->viewData('page')['props']['report']['detailed']);

    // Same day the old screen printed from created_at, so nothing moves on screen.
    expect($rows->every(fn ($r) => $r['effective_date'] === now()->toDateString()))->toBeTrue();
});

it('T201: sales access alone does not open the profit and loss page', function () {
    seedAllPermissions();

    $salesOnly = makeUser(['sales.view']);
    $this->actingAs($salesOnly)->get(route('profit-loss.index'))->assertForbidden();

    $profitLoss = makeUser(['report.profit-loss']);
    $this->actingAs($profitLoss)->get(route('profit-loss.index'))->assertOk();
    $this->actingAs($profitLoss)->get(route('home'))->assertRedirect(route('profit-loss.index', absolute: false));
});
