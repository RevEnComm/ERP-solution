<?php

use App\Http\Controllers\Acl\RoleController;
use App\Http\Controllers\Acl\UserController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LiftController;
use App\Http\Controllers\ProductPurchaseController;
use App\Http\Controllers\ProfileController; // Fixed: Correct namespace
use App\Http\Controllers\ProfitLossController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// Authentication and ERP Routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/home', HomeController::class)->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permission.redirect:dashboard.view')->name('dashboard');

    // Profile Routes (from Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Suppliers
    Route::get('suppliers/index', [SupplierController::class, 'index'])->middleware('permission:supplier.view')->name('suppliers.index');
    Route::get('suppliers/create', [SupplierController::class, 'create'])->middleware('permission:supplier.add')->name('suppliers.create');
    Route::post('suppliers/store', [SupplierController::class, 'store'])->middleware('permission:supplier.add')->name('suppliers.store');
    Route::put('suppliers/{id}', [SupplierController::class, 'update'])->middleware('permission:supplier.update')->name('suppliers.update');
    Route::get('suppliers/{id}/edit', [SupplierController::class, 'edit'])->middleware('permission:supplier.update')->name('suppliers.edit');
    Route::post('suppliers/quick-store', [SupplierController::class, 'quickStore'])->middleware('permission:supplier.add')->name('suppliers.quick-store');
    Route::delete('suppliers/{id}/delete', [SupplierController::class, 'destroy'])->middleware('permission:supplier.delete')->name('suppliers.destroy');

    // Deposits
    Route::get('deposits', [DepositController::class, 'index'])->middleware('permission:deposit.view')->name('deposits.index');
    Route::post('deposits/store', [DepositController::class, 'store'])->middleware('permission:deposit.add')->name('deposits.store');
    Route::put('deposits/{id}', [DepositController::class, 'update'])->middleware('permission:deposit.update')->name('deposits.update');
    Route::post('/api/deposits/quick-store', [DepositController::class, 'quickStore'])->middleware('permission:deposit.add');
    Route::delete('deposits/{id}/delete', [DepositController::class, 'destroy'])->middleware('permission:deposit.delete')->name('deposits.destroy');

    // Categories
    Route::get('categories/index', [CategoryController::class, 'index'])->middleware('permission:category.view')->name('categories.index');
    Route::post('categories/store', [CategoryController::class, 'store'])->middleware('permission:category.add')->name('categories.store');
    Route::put('categories/{id}/update', [CategoryController::class, 'update'])->middleware('permission:category.update')->name('categories.update');
    Route::delete('categories/{id}/delete', [CategoryController::class, 'destroy'])->middleware('permission:category.delete')->name('categories.delete');
    Route::post('categories/quick-store', [CategoryController::class, 'quickStore'])->middleware('permission:category.add')->name('categories.quick-store');

    // Brands
    Route::get('brands/index', [BrandController::class, 'index'])->middleware('permission:brand.view')->name('brands.index');
    Route::post('brands/store', [BrandController::class, 'store'])->middleware('permission:brand.add')->name('brands.store');
    Route::put('brands/{id}/update', [BrandController::class, 'update'])->middleware('permission:brand.update')->name('brands.update');
    Route::delete('brands/{id}/delete', [BrandController::class, 'destroy'])->middleware('permission:brand.delete')->name('brands.delete');
    Route::post('brands/quick-store', [BrandController::class, 'quickStore'])->middleware('permission:brand.add')->name('brands.quick-store');

    // Lifts (new purchase system)
    Route::get('lifts', [LiftController::class, 'index'])->middleware('permission:lift.add')->name('lifts.index');
    Route::post('lifts/store', [LiftController::class, 'store'])->middleware('permission:lift.add')->name('lifts.store');
    Route::get('lifts/report', [LiftController::class, 'report'])->middleware('permission:lift.view')->name('lifts.report');
    Route::delete('lifts/{id}/delete', [LiftController::class, 'destroy'])->middleware('permission:lift.delete')->name('lifts.destroy');
    Route::get('/api/product-catalog/search', [LiftController::class, 'searchProducts'])->middleware('permission:lift.add');
    Route::post('/api/product-catalog/quick-store', [LiftController::class, 'quickStoreProduct'])->middleware('permission:lift.add');

    // Shops
    Route::get('shops', [ShopController::class, 'index'])->middleware('permission:shop.view')->name('shops.index');
    Route::get('shops/create', [ShopController::class, 'create'])->middleware('permission:shop.add')->name('shops.create');
    Route::post('shops/store', [ShopController::class, 'store'])->middleware('permission:shop.add')->name('shops.store');
    Route::get('shops/{id}/edit', [ShopController::class, 'edit'])->middleware('permission:shop.update')->name('shops.edit');
    Route::put('shops/{id}', [ShopController::class, 'update'])->middleware('permission:shop.update')->name('shops.update');
    Route::delete('shops/{id}', [ShopController::class, 'destroy'])->middleware('permission:shop.delete')->name('shops.destroy');

    // Sales
    Route::get('sales', [SalesController::class, 'index'])->middleware('permission:sales.add')->name('sales.index');
    Route::post('sales/store', [SalesController::class, 'store'])->middleware('permission:sales.add')->name('sales.store');
    Route::get('/sales/report', [SalesController::class, 'report'])->middleware('permission:sales.view')->name('sales.report');
    Route::get('/sales/summary', [SalesController::class, 'summaryReport'])->middleware('permission:sales.view')->name('sales.summary');
    Route::get('sales/payment/{id}', [SalesController::class, 'payment'])->middleware('permission:sales.update')->name('sales.payment');
    Route::post('/sales/payment/store/{id}', [SalesController::class, 'storePayment'])->middleware('permission:sales.update')->name('sales.payment.store');
    Route::get('/sales/cash-memo/{id}', [SalesController::class, 'cashMemo'])->middleware('permission:sales.view')->name('sales.cash-memo');
    Route::get('/sales/{id}/edit', [SalesController::class, 'editSale'])->middleware('permission:sales.update')->name('sales.edit');
    Route::put('/sales/{id}', [SalesController::class, 'updateSale'])->middleware('permission:sales.update')->name('sales.update');
    Route::delete('/sales/{id}/delete', [SalesController::class, 'destroy'])->middleware('permission:sales.delete')->name('sales.destroy');

    // API Routes for Sales
    Route::get('/api/products-by-supplier', [SalesController::class, 'getProductsBySupplier'])->middleware('permission:sales.add');
    Route::get('/api/variant-inventory', [SalesController::class, 'getVariantInventory'])->middleware('permission:sales.add');
    Route::get('/api/inventory/search', [SalesController::class, 'searchInventoryProducts'])->middleware('permission:sales.add');

    // Inventory Report
    Route::get('/products', [ProductPurchaseController::class, 'productList'])->middleware('permission:inventory.view')->name('products.index');
    Route::post('/products/{id}/update', [ProductPurchaseController::class, 'updateProductCatalog'])->middleware('permission:lift.add')->name('products.update');
    Route::get('/inventory/report', [ProductPurchaseController::class, 'inventoryReport'])->middleware('permission:inventory.view')->name('inventory.report');
    Route::put('/inventory/adjust-stock', [ProductPurchaseController::class, 'adjustVariantStock'])->middleware('permission:lift.add')->name('inventory.adjust-stock');

    // Expenses
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expense.view')->name('expenses.index');
    Route::post('expenses/store', [ExpenseController::class, 'storeExpense'])->middleware('permission:expense.add')->name('expenses.store');
    Route::put('expenses/{id}/update', [ExpenseController::class, 'update'])->middleware('permission:expense.update')->name('expenses.update');
    Route::get('expenses/report', [ExpenseController::class, 'report'])->middleware('permission:expense.view')->name('expenses.report');
    Route::delete('expenses/{id}/delete', [ExpenseController::class, 'destroy'])->middleware('permission:expense.delete')->name('expenses.destroy');

    // Profit & Loss Report
    Route::get('profit-loss', [ProfitLossController::class, 'index'])->middleware('permission:report.profit-loss')->name('profit-loss.index');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:role.view')->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->middleware('permission:role.add')->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:role.add')->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->middleware('permission:role.update')->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:role.update')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:role.delete')->name('roles.destroy');

    Route::get('users', [UserController::class, 'index'])->middleware('permission:user.view')->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->middleware('permission:user.add')->name('users.create');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:user.add')->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:user.update')->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:user.update')->name('users.update');
});

require __DIR__.'/auth.php';
