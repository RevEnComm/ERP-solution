<?php

namespace App\Http\Controllers;

use App\Contracts\BrandContract;
use App\Contracts\CategoryContract;
use App\Contracts\ProductPurchaseContract;
use App\Contracts\SalesContract;
use App\Contracts\SalesItemContract;
use App\Contracts\ShopContract;
use App\Contracts\SupplierContract;
use App\Enums\PaymentStatus;
use App\Enums\SalesItemsStatus;
use App\Enums\SalesStatus;
use App\Http\Requests\StoreSalesRequest;
use App\Models\Product;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Illuminate\Support\Str;

class SalesController extends Controller
{
    public function __construct(
        protected ShopContract $shopRepository,
        protected ProductPurchaseContract $productPurchaseRepository,
        protected SupplierContract $supplierRepository,
        protected CategoryContract $categoryRepository,
        protected SalesContract $salesRepository,
        protected BrandContract $brandRepository,
        protected SalesItemContract $salesItemRepository,
        protected SmsService $smsService
    ) {
    }

    public function index()
    {
        $shops = $this->shopRepository->all()->map(function ($shop) {
            return [
                'id' => $shop->id,
                'road' => $shop->road,
                'shop_name' => $shop->shop_name,
            ];
        });

        $suppliers = $this->supplierRepository->all()->map(function ($supplier) {
            return [
                'id' => $supplier->id,
                'company_name' => $supplier->company_name,
            ];
        });

        $draftSale = null;
        if (request()->filled('draft')) {
            $sale = $this->salesRepository->query()
                ->with(['items.product.supplier', 'shop'])
                ->where('status', SalesStatus::DRAFT->value)
                ->find(request()->integer('draft'));

            if ($sale) {
                $draftSale = [
                    'id' => $sale->id,
                    'shop_id' => $sale->shop_id,
                    'sale_date' => optional($sale->sale_date)->format('Y-m-d'),
                    'items' => $sale->items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product?->name ?? 'Unknown',
                            'supplier_id' => $item->supplier_id,
                            'supplier_name' => $item->product?->supplier?->company_name ?? 'Unknown',
                            'variant' => $item->variant,
                            'cases' => $item->cases_sold,
                            'extra_bottles' => $item->extra_bottles,
                            'price_per_case' => $item->selling_price_per_case,
                            'bottles_per_case' => $item->bottles_per_case,
                            'free_bottles_per_case' => (int) round(($item->free_bottles_sold ?? 0) / max($item->cases_sold, 1)),
                        ];
                    })->values(),
                ];
            }
        }

        return Inertia::render('SalesManagement/CreateSale', [
            'shops' => $shops,
            'suppliers' => $suppliers,
            'draftSale' => $draftSale,
        ]);
    }

    public function searchInventoryProducts(Request $request)
    {
        $query = $request->get('q', '');
        $products = $this->productPurchaseRepository->getInventoryStock();

        if ($query) {
            $products = $products->filter(function ($product) use ($query) {
                return stripos($product['product_name'], $query) !== false;
            });
        }

        return response()->json($products->take(15)->values());
    }

    public function getProductsBySupplier(Request $request)
    {
        $supplierId = $request->get('supplier_id');

        if (!$supplierId) {
            return response()->json(['products' => []]);
        }

        $products = $this->productPurchaseRepository->getProductsBySupplier($supplierId);

        return response()->json(['products' => $products->toArray()]);
    }

    public function getVariantInventory(Request $request)
    {
        $productId = $request->get('product_id');
        $variant = $request->get('variant');

        if (!$productId || !$variant) {
            return response()->json(['error' => 'Product ID and variant are required'], 400);
        }

        $inventory = $this->productPurchaseRepository->getVariantInventory($productId, $variant);

        if (!$inventory) {
            return response()->json(['error' => 'Variant not found'], 404);
        }

        return response()->json($inventory);
    }

    public function store(Request $request)
    {
        $request->validate([
            // Only a real draft may be continued. With a bare exists rule a stale
            // draft_id pointing at an already-confirmed sale would silently rewrite
            // that sale's items without ever restoring its deducted stock.
            'draft_id' => ['nullable', Rule::exists('sales', 'id')->where('status', SalesStatus::DRAFT->value)],
            'save_as_draft' => 'nullable|boolean',
            'shop_id' => 'required|exists:shops,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'sale_date' => 'required|date',
            'include_free_bottles' => 'required|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant' => 'required|string',
            'items.*.cases_sold' => 'required|integer|min:0',
            'items.*.extra_bottles' => 'nullable|integer|min:0',
            'items.*.total_bottles_to_sell' => 'required|integer|min:1',
            'items.*.selling_price_per_bottle' => 'required|numeric|min:0',
            'items.*.free_bottles_per_case' => 'nullable|integer|min:0',
        ], [
            'draft_id.exists' => 'This draft is no longer available, it may have already been completed.',
        ]);

        $saveAsDraft = $request->boolean('save_as_draft');
        $smsPayload = null;

        $response = DB::transaction(function () use ($request, $saveAsDraft, &$smsPayload) {
            $sale = null;
            $data = [
                'shop_id' => $request->shop_id,
                'supplier_id' => $request->supplier_id,
                'total_amount' => 0,
                'subtotal' => 0,
                'status' => $saveAsDraft ? SalesStatus::DRAFT->value : SalesStatus::IN_PROGRESS->value,
                'sale_date' => $request->sale_date,
                'due_amount' => 0,
                'paid_amount' => 0,
                'is_paid' => false,
            ];

            if ($request->draft_id) {
                $sale = $this->salesRepository->find($request->draft_id);
                $this->salesRepository->updateSales($data, $sale->id);
                $sale->items()->delete();
                $sale = $sale->fresh(['items']);
            } else {
                $data['invoice_number'] = 'INV-' . Str::padLeft(mt_rand(1, 999999), 6, '0');
                $sale = $this->salesRepository->create($data);
            }

            $invoiceNumber = $sale->invoice_number ?? $data['invoice_number'];

            $totalAmount = 0;
            $totalProfit = 0;

            foreach ($request->items as $item) {
                $referenceProduct = $this->productPurchaseRepository->find($item['product_id']);

                $batches = Product::where('name', $referenceProduct->name)
                    ->where('supplier_id', $referenceProduct->supplier_id)
                    ->whereNotNull('metadata')
                    ->orderBy('date', 'asc')
                    ->get()
                    ->map(function ($p) use ($item) {
                        $variantData = collect($p->metadata['variants'] ?? [])->firstWhere('variant', $item['variant']);
                        if (!$variantData) return null;
                        $purchasedCases = $variantData['cases_without_free_bottles'] ?? 0;
                        $bpc = $variantData['bottles_per_case'] ?? 0;
                        $initialPurchased = $purchasedCases * $bpc;
                        $initialFree = $variantData['total_free_bottles'] ?? 0;
                        return [
                            'product'           => $p,
                            'purchased'         => $variantData['current_purchased_quantity'] ?? $initialPurchased,
                            'free'              => $variantData['current_free_quantity'] ?? $initialFree,
                            'bottles_per_case'  => $bpc,
                            'purchase_rate'     => floatval($variantData['actual_rate_per_bottle'] ?? 0),
                            'case_buying_price' => floatval($variantData['case_buying_price'] ?? 0),
                        ];
                    })
                    ->filter()
                    ->values();

                if ($batches->isEmpty()) {
                    throw ValidationException::withMessages([
                        'inventory' => "Variant {$item['variant']} not found for this product",
                    ]);
                }

                $bottlesPerCase          = $batches->first()['bottles_per_case'];
                $totalPurchasedAvailable = $batches->sum('purchased');
                $totalFreeAvailable      = $batches->sum('free');

                $targetBottlesToSell         = $item['total_bottles_to_sell'];
                $actualSellingPricePerBottle = $item['selling_price_per_bottle'];
                $freeBottlesPerCase          = $item['free_bottles_per_case'] ?? 0;
                $casesSoldFrontend           = $item['cases_sold'];
                $extraBottlesFrontend        = $item['extra_bottles'] ?? 0;

                if ($request->include_free_bottles) {
                    $effectiveBottlesPerCase = $bottlesPerCase + $freeBottlesPerCase;
                    $casesSold               = $casesSoldFrontend;
                    $purchasedBottlesSold    = ($casesSold * $bottlesPerCase) + $extraBottlesFrontend;
                    $freeBottlesSold         = $casesSold * $freeBottlesPerCase;
                    $actualTotalBottlesSold  = $purchasedBottlesSold + $freeBottlesSold;
                    $sellingPricePerCase     = $effectiveBottlesPerCase * $actualSellingPricePerBottle;
                } else {
                    $effectiveBottlesPerCase = $bottlesPerCase + $freeBottlesPerCase;
                    $casesSold               = $casesSoldFrontend;
                    $purchasedBottlesSold    = ($casesSold * $bottlesPerCase) + $extraBottlesFrontend;
                    $freeBottlesSold         = 0;
                    $actualTotalBottlesSold  = $purchasedBottlesSold;
                    $sellingPricePerCase     = $bottlesPerCase * $actualSellingPricePerBottle;
                }

                // When free bottles are excluded from the sale they must not be
                // sold or consumed, so validate against purchased stock only.
                $totalAvailable = $request->include_free_bottles
                    ? ($totalPurchasedAvailable + $totalFreeAvailable)
                    : $totalPurchasedAvailable;
                $totalToDeduct  = $purchasedBottlesSold + $freeBottlesSold;

                if (!$saveAsDraft && $totalToDeduct > $totalAvailable) {
                    throw ValidationException::withMessages([
                        'inventory' => "Insufficient stock for {$item['variant']}. Available: {$totalAvailable}, Required: {$totalToDeduct}",
                    ]);
                }

                $totalSalePrice = round($targetBottlesToSell * $actualSellingPricePerBottle, 2);

                // Cost the bottles that actually leave inventory, each at the price
                // of the batch it leaves. Bottles retained (free ones the sale
                // excludes) are not charged here - their cost is realised when they
                // are sold later.
                $consumption  = $this->planFifoConsumption(
                    $batches,
                    (int) $totalToDeduct,
                    (bool) $request->include_free_bottles,
                    (int) $effectiveBottlesPerCase
                );
                $purchaseCost = $consumption['cost'];
                $profit       = round($totalSalePrice - $purchaseCost, 2);

                // What this sale actually worked out at per bottle. With one batch
                // it is that batch's rate; spanning two, it lands in between.
                $purchaseRatePerBottle = $actualTotalBottlesSold > 0
                    ? round($purchaseCost / $actualTotalBottlesSold, 4)
                    : 0.0;

                $itemsData = [
                    'sale_id'                => $sale->id,
                    'product_id'             => $batches->first()['product']->id,
                    'supplier_id'            => $request->supplier_id,
                    'variant'                => $item['variant'],
                    'cases_sold'             => $casesSold,
                    'extra_bottles'          => $extraBottlesFrontend,
                    'bottles_per_case'       => $bottlesPerCase,
                    'purchased_bottles_sold' => $purchasedBottlesSold,
                    'free_bottles_sold'      => $freeBottlesSold,
                    'total_bottles_sold'     => $actualTotalBottlesSold,
                    'target_bottles_to_sell' => $targetBottlesToSell,
                    'purchase_unit_price'    => $purchaseRatePerBottle,
                    'selling_price_per_case' => $sellingPricePerCase,
                    'selling_price_per_bottle' => $actualSellingPricePerBottle,
                    'unit_price'             => $actualSellingPricePerBottle,
                    'total_price'            => $totalSalePrice,
                    'profit'                 => $profit,
                    'delivery_date'          => $request->sale_date,
                    'invoice_number'         => $invoiceNumber,
                    'status'                 => SalesItemsStatus::IN_PROGRESS->value,
                ];

                $this->salesItemRepository->create($itemsData);

                // Same plan the cost came from, so what was charged and what left
                // the shelf can never drift apart.
                if (!$saveAsDraft) {
                    foreach ($consumption['plan'] as $step) {
                        $this->productPurchaseRepository->updateInventory(
                            $step['product'],
                            $item['variant'],
                            $step['purchased'],
                            $step['free']
                        );
                    }
                }

                $totalAmount += $totalSalePrice;
                $totalProfit += $profit;
            }

            // Update sale totals
            $this->salesRepository->updateSales([
                'total_amount' => $totalAmount,
                'subtotal' => $totalAmount,
                'due_amount' => $totalAmount,
            ], $sale->id);

            if ($saveAsDraft) {
                // Land on the Drafts tab: the draft they just saved is there, not
                // in the completed list the report opens on by default.
                return redirect()->route('sales.report', ['tab' => 'draft'])
                    ->with('success', 'Sale draft saved successfully');
            }

            $shop = $this->shopRepository->find($request->shop_id);
            $smsPayload = [
                'mobile'  => $shop?->phone_number,
                'owner'   => $shop?->owner_name,
                'invoice' => $invoiceNumber,
                'total'   => $totalAmount,
                'due'     => $totalAmount,
            ];

            return redirect()->route('sales.payment', $sale->id)->with('success', 'Sale created successfully, please proceed with payment');
        });

        // Notify the shop owner by SMS after the sale has committed, so a gateway
        // failure can never roll back or block the sale. This call never throws.
        if ($smsPayload && !empty($smsPayload['mobile'])) {
            $this->smsService->sendSaleNotification(
                $smsPayload['mobile'],
                $smsPayload['owner'],
                $smsPayload['invoice'],
                (float) $smsPayload['total'],
                (float) $smsPayload['due'],
            );
        }

        return $response;
    }

    /**
     * Work out what a sale takes from each batch, oldest first, and cost every
     * bottle at the price of the batch it actually leaves.
     *
     * Stock already leaves FIFO, so costing it any other way cannot balance. A
     * weighted average over what remains climbs as the cheap batches drain, so
     * across several sales the books charged more (with rising prices) or less
     * (with falling ones) than the lifts ever cost. Costing batch by batch makes
     * the total charged equal the total paid, whatever order the sales come in.
     *
     * @param  \Illuminate\Support\Collection  $batches  oldest first
     * @return array{cost: float, plan: array<int, array{product: mixed, purchased: int, free: int}>}
     */
    private function planFifoConsumption(
        $batches,
        int $bottlesToTake,
        bool $includeFreeBottles,
        int $effectiveBottlesPerCase
    ): array {
        $remaining = max(0, $bottlesToTake);
        $cost      = 0.0;
        $plan      = [];
        $lastRate  = 0.0;

        $rateOf = function ($batch) use ($effectiveBottlesPerCase) {
            return $effectiveBottlesPerCase > 0
                ? ((float) ($batch['case_buying_price'] ?? 0)) / $effectiveBottlesPerCase
                : (float) ($batch['purchase_rate'] ?? 0);
        };

        foreach ($batches as $batch) {
            $lastRate = $rateOf($batch);

            if ($remaining <= 0) {
                continue;
            }

            // Free bottles are only consumed when the sale includes them;
            // otherwise the purchased pool alone is drawn down.
            if ($includeFreeBottles) {
                $batchTotal = (int) $batch['purchased'] + (int) $batch['free'];
                if ($batchTotal <= 0) {
                    continue;
                }
                $take          = min($remaining, $batchTotal);
                $takePurchased = min($take, (int) $batch['purchased']);
                $takeFree      = $take - $takePurchased;
            } else {
                $takePurchased = min($remaining, (int) $batch['purchased']);
                $takeFree      = 0;
                $take          = $takePurchased;
            }

            if ($take <= 0) {
                continue;
            }

            $cost += $take * $lastRate;
            $plan[] = [
                'product'   => $batch['product'],
                'purchased' => $takePurchased,
                'free'      => $takeFree,
            ];
            $remaining -= $take;
        }

        // A draft may be written for more than is in stock. Price the shortfall at
        // the newest batch's rate so its projected profit stays sensible; a real
        // sale never gets here because the stock check rejects it first.
        if ($remaining > 0) {
            $cost += $remaining * $lastRate;
        }

        return ['cost' => round($cost, 2), 'plan' => $plan];
    }

    public function payment(Request $request, $id)
    {
        $sale = $this->salesRepository->find($id);
        if (!$sale) {
            return redirect()->route('sales.index')->with('error', 'Sale not found');
        }

        $shop = $this->shopRepository->find($sale->shop_id);
        $advanceBalance = DB::table('payments')
            ->where('shop_id', $sale->shop_id)
            ->sum('advance_balance');

        return Inertia::render('SalesManagement/SalesPayment', [
            'sale' => [
                'id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'total_amount' => $sale->total_amount,
                'paid_amount' => $sale->paid_amount,
                'due_amount' => $sale->due_amount,
                'shop_name' => $shop ? $shop->shop_name : 'Unknown',
                'advance_balance' => $advanceBalance,
            ],
        ]);
    }

    public function storePayment(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $sale = $this->salesRepository->find($id);

            if (!$sale) {
                return redirect()->route('sales.index')->with('error', 'Sale not found');
            }

            $paymentAmount = round((float) $request->payment_amount, 2);
            $currentAdvanceBalance = DB::table('payments')
                ->where('shop_id', $sale->shop_id)
                ->sum('advance_balance');

            // Use total_amount as source of truth to avoid float accumulation errors.
            $newPaidAmount = round((float) $sale->paid_amount + $paymentAmount, 2);
            $newDueAmount  = round((float) $sale->total_amount - $newPaidAmount, 2);
            if ($newDueAmount <= 0) {
                $newDueAmount = 0;
            }

            $data = [
                'paid_amount' => $newPaidAmount,
                'due_amount' => $newDueAmount,
                'is_paid' => $newDueAmount <= 0,
                'status' => $newDueAmount <= 0 ? SalesStatus::COMPLETED : SalesStatus::IN_PROGRESS,
            ];

            $this->salesRepository->updateSales($data, $id);

            $paymentData = [
                'shop_id' => $sale->shop_id,
                'sale_id' => $sale->id,
                'amount' => $paymentAmount,
                'payment_method' => $request->payment_method,
                'advance_balance' => $currentAdvanceBalance,
                'status' => $newDueAmount <= 0 ? PaymentStatus::PAID : PaymentStatus::PENDING,
                'payment_date' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('payments')->insert($paymentData);

            $shop = $this->shopRepository->find($sale->shop_id);
            $sale->load('items.product');

            return Inertia::render('SalesManagement/CashMemo', [
                'sale' => [
                    'id' => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    'total_amount' => $sale->total_amount ?? 0,
                    'paid_amount' => $newPaidAmount,
                    'due_amount' => max(0, $newDueAmount),
                    'shop_name' => $shop ? $shop->shop_name : 'Unknown',
                    'status' => $data['status'],
                    'items' => $sale->items->map(function ($item) {
                        return [
                            'product_name' => $item->product ? $item->product->name : 'Unknown',
                            'variant' => $item->variant,
                            'cases_sold' => $item->cases_sold,
                            'extra_bottles' => $item->extra_bottles,
                            'total_bottles_sold' => $item->total_bottles_sold,
                            'target_bottles_to_sell' => $item->target_bottles_to_sell ?? $item->total_bottles_sold,
                            'unit_price' => $item->unit_price ?? $item->selling_price_per_bottle,
                            'total_price' => $item->total_price,
                        ];
                    }),
                ],
                'payment' => $paymentData,
            ]);
        });
    }

    public function cashMemo(Request $request, $id)
    {
        $sale = $this->salesRepository->find($id);
        if (!$sale) {
            return redirect()->route('sales.index')->with('error', 'Sale not found');
        }

        $shop = $this->shopRepository->find($sale->shop_id);
        $sale->load('items.product');
        $latestPayment = DB::table('payments')
            ->where('sale_id', $sale->id)
            ->orderBy('payment_date', 'desc')
            ->first();

        return Inertia::render('SalesManagement/CashMemo', [
            'sale' => [
                'id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'total_amount' => $sale->total_amount ?? 0,
                'paid_amount' => $sale->paid_amount,
                'due_amount' => $sale->due_amount,
                'shop_name' => $shop ? $shop->shop_name : 'Unknown',
                'status' => $sale->status,
                'items' => $sale->items->map(function ($item) {
                    return [
                        'product_name' => $item->product ? $item->product->name : 'Unknown',
                        'variant' => $item->variant,
                        'cases_sold' => $item->cases_sold,
                        'extra_bottles' => $item->extra_bottles,
                        'total_bottles_sold' => $item->total_bottles_sold,
                        'target_bottles_to_sell' => $item->target_bottles_to_sell ?? $item->total_bottles_sold,
                        'unit_price' => $item->unit_price ?? $item->selling_price_per_bottle,
                        'total_price' => $item->total_price,
                    ];
                }),
            ],
            'payment' => $latestPayment ? [
                'amount' => $latestPayment->amount,
                'payment_method' => $latestPayment->payment_method,
                'advance_balance' => $latestPayment->advance_balance,
                'payment_date' => $latestPayment->payment_date,
                'status' => $latestPayment->status,
            ] : [
                'amount' => 0,
                'payment_method' => '',
                'advance_balance' => 0,
                'payment_date' => now()->toDateTimeString(),
                'status' => PaymentStatus::PENDING,
            ],
        ]);
    }

        public function editSale($id)
    {
        $sale = $this->salesRepository->query()
            ->with(['items.product.supplier', 'shop'])
            ->find($id);

        if (!$sale) {
            return redirect()->route('sales.report')->with('error', 'Sale not found');
        }

        // A draft is edited through the draft flow, which posts back with draft_id
        // and so updates this same row. Rendering it as a normal edit would let the
        // "Save Draft" button spawn a second draft instead of updating this one.
        if ($sale->status === SalesStatus::DRAFT->value) {
            return redirect()->route('sales.index', ['draft' => $sale->id]);
        }

        $shops = $this->shopRepository->all()->map(function ($shop) {
            return [
                'id' => $shop->id,
                'road' => $shop->road,
                'shop_name' => $shop->shop_name,
            ];
        });

        $suppliers = $this->supplierRepository->all()->map(function ($supplier) {
            return [
                'id' => $supplier->id,
                'company_name' => $supplier->company_name,
            ];
        });

        $canSeeProfit = auth()->user()->can('sales.profit');

        $latestPayment = DB::table('payments')->where('sale_id', $sale->id)->orderBy('payment_date', 'desc')->first();

        $editSaleData = [
            'id' => $sale->id,
            'shop_id' => $sale->shop_id,
            'supplier_id' => $sale->supplier_id,
            'sale_date' => optional($sale->sale_date)->format('Y-m-d'),
            'invoice_number' => $sale->invoice_number,
            'status' => $sale->status,
            'paid_amount' => $sale->paid_amount,
            'discount' => 0, // Not explicitly tracked in DB right now, infer if needed or default 0
            'payment' => $latestPayment ? [
                'amount' => $latestPayment->amount,
                'payment_method' => $latestPayment->payment_method,
                'advance_balance' => $latestPayment->advance_balance,
                'payment_date' => $latestPayment->payment_date,
                'status' => $latestPayment->status,
            ] : null,
            'items' => $sale->items->map(function ($item) use ($canSeeProfit) {
                $itemRow = [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name ?? 'Unknown',
                    'supplier_id' => $item->supplier_id,
                    'supplier_name' => $item->product?->supplier?->company_name ?? 'Unknown',
                    'variant' => $item->variant,
                    'cases_sold' => $item->cases_sold,
                    'extra_bottles' => $item->extra_bottles,
                    'bottles_per_case' => $item->bottles_per_case,
                    'free_bottles_sold' => $item->free_bottles_sold,
                    'total_bottles_sold' => $item->total_bottles_sold,
                    'target_bottles_to_sell' => $item->target_bottles_to_sell ?? $item->total_bottles_sold,
                    'selling_price_per_bottle' => $item->selling_price_per_bottle,
                    'selling_price_per_case' => $item->selling_price_per_case,
                    'total_price' => $item->total_price,
                    'purchase_unit_price' => $item->purchase_unit_price,
                    'profit' => $item->profit,
                    // Calculated fields for CreateSale frontend
                    'cases' => $item->cases_sold,
                    'price_per_case' => $item->selling_price_per_case,
                    'free_bottles_per_case' => (int) round(($item->free_bottles_sold ?? 0) / max($item->cases_sold, 1)),
                ];

                return $canSeeProfit ? $itemRow : Arr::except($itemRow, ['purchase_unit_price', 'profit']);
            })->values(),
        ];

        return Inertia::render('SalesManagement/CreateSale', [
            'shops' => $shops,
            'suppliers' => $suppliers,
            'editSale' => $editSaleData,
        ]);
    }

    public function updateSale(Request $request, $id)
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'sale_date' => 'required|date',
            'include_free_bottles' => 'required|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant' => 'required|string',
            'items.*.cases_sold' => 'required|integer|min:0',
            'items.*.extra_bottles' => 'nullable|integer|min:0',
            'items.*.total_bottles_to_sell' => 'required|integer|min:1',
            'items.*.selling_price_per_bottle' => 'required|numeric|min:0',
            'items.*.free_bottles_per_case' => 'nullable|integer|min:0',
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $sale = $this->salesRepository->query()->with('items.product')->find($id);
            if (!$sale) {
                return redirect()->route('sales.report')->with('error', 'Sale not found');
            }

            // 1. Restore previous inventory. A draft never deducted any, so
            // restoring it would hand back bottles that were never sold and
            // inflate the batches another sale had drawn down.
            if ($sale->status !== SalesStatus::DRAFT->value) {
                $this->restoreSaleInventory($sale);
            }

            // 2. Delete old items
            $sale->items()->delete();

            // 3. Process new items and deduct stock
            $totalAmount = 0;
            $totalProfit = 0;

            foreach ($request->items as $item) {
                $referenceProduct = $this->productPurchaseRepository->find($item['product_id']);

                $batches = \App\Models\Product::where('name', $referenceProduct->name)
                    ->where('supplier_id', $referenceProduct->supplier_id)
                    ->whereNotNull('metadata')
                    ->orderBy('date', 'asc')
                    ->get()
                    ->map(function ($p) use ($item) {
                        $variantData = collect($p->metadata['variants'] ?? [])->firstWhere('variant', $item['variant']);
                        if (!$variantData) return null;
                        $purchasedCases = $variantData['cases_without_free_bottles'] ?? 0;
                        $bpc = $variantData['bottles_per_case'] ?? 0;
                        $initialPurchased = $purchasedCases * $bpc;
                        $initialFree = $variantData['total_free_bottles'] ?? 0;
                        return [
                            'product'           => $p,
                            'purchased'         => $variantData['current_purchased_quantity'] ?? $initialPurchased,
                            'free'              => $variantData['current_free_quantity'] ?? $initialFree,
                            'bottles_per_case'  => $bpc,
                            'purchase_rate'     => floatval($variantData['actual_rate_per_bottle'] ?? 0),
                            'case_buying_price' => floatval($variantData['case_buying_price'] ?? 0),
                        ];
                    })
                    ->filter()
                    ->values();

                if ($batches->isEmpty()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'inventory' => "Variant {$item['variant']} not found for this product",
                    ]);
                }

                $bottlesPerCase          = $batches->first()['bottles_per_case'];
                $totalPurchasedAvailable = $batches->sum('purchased');
                $totalFreeAvailable      = $batches->sum('free');

                $targetBottlesToSell         = $item['total_bottles_to_sell'];
                $actualSellingPricePerBottle = $item['selling_price_per_bottle'];
                $freeBottlesPerCase          = $item['free_bottles_per_case'] ?? 0;
                $casesSoldFrontend           = $item['cases_sold'];
                $extraBottlesFrontend        = $item['extra_bottles'] ?? 0;

                if ($request->include_free_bottles) {
                    $effectiveBottlesPerCase = $bottlesPerCase + $freeBottlesPerCase;
                    $casesSold               = $casesSoldFrontend;
                    $purchasedBottlesSold    = ($casesSold * $bottlesPerCase) + $extraBottlesFrontend;
                    $freeBottlesSold         = $casesSold * $freeBottlesPerCase;
                    $actualTotalBottlesSold  = $purchasedBottlesSold + $freeBottlesSold;
                    $sellingPricePerCase     = $effectiveBottlesPerCase * $actualSellingPricePerBottle;
                } else {
                    $effectiveBottlesPerCase = $bottlesPerCase + $freeBottlesPerCase;
                    $casesSold               = $casesSoldFrontend;
                    $purchasedBottlesSold    = ($casesSold * $bottlesPerCase) + $extraBottlesFrontend;
                    $freeBottlesSold         = 0;
                    $actualTotalBottlesSold  = $purchasedBottlesSold;
                    $sellingPricePerCase     = $bottlesPerCase * $actualSellingPricePerBottle;
                }

                // When free bottles are excluded from the sale they must not be
                // sold or consumed, so validate against purchased stock only.
                $totalAvailable = $request->include_free_bottles
                    ? ($totalPurchasedAvailable + $totalFreeAvailable)
                    : $totalPurchasedAvailable;
                $totalToDeduct  = $purchasedBottlesSold + $freeBottlesSold;

                if ($totalToDeduct > $totalAvailable) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'inventory' => "Insufficient stock for {$item['variant']}. Available: {$totalAvailable}, Required: {$totalToDeduct}",
                    ]);
                }

                $totalSalePrice = round($targetBottlesToSell * $actualSellingPricePerBottle, 2);

                // Cost the bottles that actually leave inventory, each at the price
                // of the batch it leaves. Bottles retained (free ones the sale
                // excludes) are not charged here - their cost is realised when they
                // are sold later.
                $consumption  = $this->planFifoConsumption(
                    $batches,
                    (int) $totalToDeduct,
                    (bool) $request->include_free_bottles,
                    (int) $effectiveBottlesPerCase
                );
                $purchaseCost = $consumption['cost'];
                $profit       = round($totalSalePrice - $purchaseCost, 2);

                // What this sale actually worked out at per bottle. With one batch
                // it is that batch's rate; spanning two, it lands in between.
                $purchaseRatePerBottle = $actualTotalBottlesSold > 0
                    ? round($purchaseCost / $actualTotalBottlesSold, 4)
                    : 0.0;

                $itemsData = [
                    'sale_id'                => $sale->id,
                    'product_id'             => $batches->first()['product']->id,
                    'supplier_id'            => $request->supplier_id,
                    'variant'                => $item['variant'],
                    'cases_sold'             => $casesSold,
                    'extra_bottles'          => $extraBottlesFrontend,
                    'bottles_per_case'       => $bottlesPerCase,
                    'purchased_bottles_sold' => $purchasedBottlesSold,
                    'free_bottles_sold'      => $freeBottlesSold,
                    'total_bottles_sold'     => $actualTotalBottlesSold,
                    'target_bottles_to_sell' => $targetBottlesToSell,
                    'purchase_unit_price'    => $purchaseRatePerBottle,
                    'selling_price_per_case' => $sellingPricePerCase,
                    'selling_price_per_bottle' => $actualSellingPricePerBottle,
                    'unit_price'             => $actualSellingPricePerBottle,
                    'total_price'            => $totalSalePrice,
                    'profit'                 => $profit,
                    'delivery_date'          => $request->sale_date,
                    'invoice_number'         => $sale->invoice_number,
                    'status'                 => \App\Enums\SalesItemsStatus::IN_PROGRESS->value,
                ];

                $this->salesItemRepository->create($itemsData);

                // Same plan the cost came from, so what was charged and what left
                // the shelf can never drift apart.
                foreach ($consumption['plan'] as $step) {
                    $this->productPurchaseRepository->updateInventory(
                        $step['product'],
                        $item['variant'],
                        $step['purchased'],
                        $step['free']
                    );
                }

                $totalAmount += $totalSalePrice;
                $totalProfit += $profit;
            }

            // 4. Update payment details
            $newPaidAmount = $sale->paid_amount;
            if ($request->has('payment_amount') && $request->payment_amount !== null) {
                $newPaidAmount = floatval($request->payment_amount);

                $existingPayment = DB::table('payments')->where('sale_id', $sale->id)->orderBy('payment_date', 'desc')->first();
                if ($existingPayment) {
                    DB::table('payments')->where('id', $existingPayment->id)->update([
                        'amount'         => $newPaidAmount,
                        'payment_method' => $request->payment_method ?? $existingPayment->payment_method,
                        'status'         => $newPaidAmount >= $totalAmount ? \App\Enums\PaymentStatus::PAID->value : \App\Enums\PaymentStatus::PENDING->value,
                        'updated_at'     => now(),
                    ]);
                } else {
                    DB::table('payments')->insert([
                        'shop_id'        => $sale->shop_id,
                        'sale_id'        => $sale->id,
                        'amount'         => $newPaidAmount,
                        'payment_method' => $request->payment_method ?? 'cash',
                        'advance_balance'=> 0,
                        'status'         => $newPaidAmount >= $totalAmount ? \App\Enums\PaymentStatus::PAID->value : \App\Enums\PaymentStatus::PENDING->value,
                        'payment_date'   => now(),
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }
            }

            $newDue = max(0, $totalAmount - $newPaidAmount);
            
            $this->salesRepository->updateSales([
                'shop_id'      => $request->shop_id,
                'supplier_id'  => $request->supplier_id,
                'sale_date'    => $request->sale_date,
                'total_amount' => $totalAmount,
                'subtotal'     => $totalAmount,
                'paid_amount'  => $newPaidAmount,
                'due_amount'   => $newDue,
                'is_paid'      => $newDue <= 0,
                'status'       => $newDue <= 0 ? \App\Enums\SalesStatus::COMPLETED->value : \App\Enums\SalesStatus::IN_PROGRESS->value,
            ], $id);

            return redirect()->route('sales.report', ['tab' => 'completed'])
                ->with('success', 'Sale updated successfully');
        });
    }

    /**
     * Add a sale's sold bottles back to the product inventory metadata.
     *
     * Stock is deducted at sale time FIFO *across batches* (oldest first), but a
     * sale_item only records one product_id, so the per-batch split is lost. We
     * therefore reverse the deduction by refilling batches in reverse-FIFO order
     * (newest first), each capped at its originally-lifted capacity, so no batch
     * is ever pushed above what was lifted into it and the returned bottles are
     * valued at the correct per-batch rate (updateInventory recomputes total_cost
     * from the batch's own rate). Only call for sales whose stock was actually
     * deducted (i.e. non-draft sales).
     */
    private function restoreSaleInventory(\App\Models\Sale $sale): void
    {
        foreach ($sale->items as $item) {
            // Resolve the product name even if the linked batch was soft-deleted,
            // so its surviving siblings still receive the stock back.
            $productName = $item->product?->name
                ?? ($item->product_id ? Product::withTrashed()->find($item->product_id)?->name : null);

            if (!$productName) {
                Log::warning('restoreSaleInventory: could not resolve product for sale_item', [
                    'sale_id' => $sale->id,
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                ]);
                continue;
            }

            // Reverse-FIFO: newest batch first, so we undo the oldest-first deduction.
            $restoreBatches = Product::where('name', $productName)
                ->where('supplier_id', $sale->supplier_id)
                ->whereNotNull('metadata')
                ->orderBy('date', 'desc')
                ->get()
                ->filter(fn ($p) => collect($p->metadata['variants'] ?? [])
                    ->contains(fn ($v) => (string) ($v['variant'] ?? '') === (string) $item->variant))
                ->values();

            if ($restoreBatches->isEmpty()) {
                Log::warning('restoreSaleInventory: no matching batches to restore stock', [
                    'sale_id' => $sale->id,
                    'sale_item_id' => $item->id,
                    'product' => $productName,
                    'variant' => $item->variant,
                ]);
                continue;
            }

            $remainingPurchased = abs((int) $item->purchased_bottles_sold);
            $remainingFree      = abs((int) $item->free_bottles_sold);

            foreach ($restoreBatches as $batch) {
                if ($remainingPurchased <= 0 && $remainingFree <= 0) break;

                $variantData = collect($batch->metadata['variants'])
                    ->first(fn ($v) => (string) ($v['variant'] ?? '') === (string) $item->variant);
                if (!$variantData) continue;

                // Purchased pool headroom = lifted capacity - current on hand.
                $capacity = ($variantData['cases_without_free_bottles'] ?? 0) * ($variantData['bottles_per_case'] ?? 0);
                $currentPurchased = $variantData['current_purchased_quantity'] ?? $capacity;
                $headroomPurchased = max(0, (int) $capacity - (int) $currentPurchased);

                // Free pool headroom.
                $freeCapacity = $variantData['total_free_bottles'] ?? 0;
                $currentFree = $variantData['current_free_quantity'] ?? $freeCapacity;
                $headroomFree = max(0, (int) $freeCapacity - (int) $currentFree);

                $addPurchased = min($remainingPurchased, $headroomPurchased);
                $addFree      = min($remainingFree, $headroomFree);

                if ($addPurchased > 0 || $addFree > 0) {
                    $this->productPurchaseRepository->updateInventory(
                        $batch,
                        $item->variant,
                        -$addPurchased,
                        -$addFree
                    );
                    $remainingPurchased -= $addPurchased;
                    $remainingFree      -= $addFree;
                }
            }

            // Anything left means every batch is already at capacity (manual stock
            // edits or pre-fix corruption). Don't inflate a batch past its lifted
            // amount — leave it and log, preserving the "current <= lifted" invariant.
            if ($remainingPurchased > 0 || $remainingFree > 0) {
                Log::warning('restoreSaleInventory: batches at capacity, stock not fully restored', [
                    'sale_id' => $sale->id,
                    'sale_item_id' => $item->id,
                    'product' => $productName,
                    'variant' => $item->variant,
                    'unrestored_purchased' => $remainingPurchased,
                    'unrestored_free' => $remainingFree,
                ]);
            }
        }
    }

    public function destroy(int $id)
    {
        return DB::transaction(function () use ($id) {
            $sale = $this->salesRepository->query()->with('items.product')->find($id);
            if (!$sale) {
                return back()->with('error', 'Sale not found.');
            }

            // Draft sales never deducted stock, so only finalized sales get restored.
            if ($sale->status !== SalesStatus::DRAFT->value) {
                $this->restoreSaleInventory($sale);
            }

            // Deleting cascades sale_items (FK onDelete cascade) and nulls payments.sale_id.
            $sale->delete();

            return back()->with('success', 'Sale deleted and stock returned to inventory.');
        });
    }

    public function report(Request $request)
    {
        return $this->renderReport($request, 'invoice');
    }

    public function summaryReport(Request $request)
    {
        return $this->renderReport($request, 'summary');
    }

    protected function renderReport(Request $request, string $defaultView)
    {
        $query = $this->salesRepository->query()->with(['shop', 'items.product', 'supplier']);

        // Apply filters
        if ($request->id) {
            $query->where('id', $request->id);
        }
        if ($request->start_date && $request->end_date) {
            $query->whereBetween('sale_date', [$request->start_date, $request->end_date]);
        }
        if ($request->shop_id) {
            $query->where('shop_id', $request->shop_id);
        }
        if ($request->product_id) {
            $query->whereHas('items', function ($q) use ($request) {
                $q->where('product_id', $request->product_id);
            });
        }
        if ($request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Without sales.profit the figures are left out of the page data, not
        // just hidden on screen, so they can't be read from the browser either.
        $canSeeProfit = $request->user()->can('sales.profit');

        $sales = $query->orderByDesc('sale_date')->orderByDesc('id')->get()->map(function ($sale) use ($canSeeProfit) {
            $row = [
                'id' => $sale->id,
                'shop_id' => $sale->shop_id,
                'shop_name' => $sale->shop ? $sale->shop->shop_name : 'Unknown',
                'supplier_id' => $sale->supplier_id,
                'supplier_name' => $sale->supplier ? $sale->supplier->company_name : 'Unknown',
                'invoice_number' => $sale->invoice_number,
                'total_amount' => $sale->total_amount,
                'paid_amount' => $sale->paid_amount,
                'due_amount' => $sale->due_amount,
                'total_profit' => $sale->items->sum('profit'),
                'sale_date' => $sale->sale_date->format('Y-m-d'),
                'status' => $sale->status,
                'items' => $sale->items->map(function ($item) use ($canSeeProfit) {
                    $itemRow = [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product ? $item->product->name : 'Unknown',
                        'variant' => $item->variant,
                        'cases_sold' => $item->cases_sold,
                        'extra_bottles' => $item->extra_bottles,
                        'total_bottles_sold' => $item->total_bottles_sold,
                        'target_bottles_to_sell' => $item->target_bottles_to_sell ?? $item->total_bottles_sold,
                        'purchased_bottles_sold' => $item->purchased_bottles_sold,
                        'free_bottles_sold' => $item->free_bottles_sold,
                        'total_price' => $item->total_price,
                        'profit' => $item->profit,
                    ];

                    return $canSeeProfit ? $itemRow : Arr::except($itemRow, ['profit']);
                }),
            ];

            return $canSeeProfit ? $row : Arr::except($row, ['total_profit']);
        });

        $shops = $this->shopRepository->all()->map(function ($shop) {
            return [
                'id' => $shop->id,
                'road' => $shop->road,
                'shop_name' => $shop->shop_name,
            ];
        });

        $products = $this->productPurchaseRepository->all()->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
            ];
        });

        $suppliers = $this->supplierRepository->all()->map(function ($supplier) {
            return [
                'id' => $supplier->id,
                'company_name' => $supplier->company_name,
            ];
        });

        return Inertia::render('SalesManagement/SalesReport', [
            'sales' => $sales,
            'shops' => $shops,
            'products' => $products,
            'suppliers' => $suppliers,
            'defaultView' => $defaultView,
            'canSeeProfit' => $canSeeProfit,
            'filters' => [
                'id' => $request->id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'shop_id' => $request->shop_id,
                'product_id' => $request->product_id,
                'supplier_id' => $request->supplier_id,
            ],
        ]);
    }
}
