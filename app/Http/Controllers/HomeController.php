<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The first page a user may visit after signing in.
     *
     * Dashboard access remains optional. A user with another permission must
     * be sent to that permitted module instead of the protected dashboard.
     */
    private const LANDING_PAGES = [
        ['dashboard.view', 'dashboard'],
        ['sales.add', 'sales.index'],
        ['sales.view', 'sales.report'],
        ['lift.add', 'lifts.index'],
        ['lift.view', 'lifts.report'],
        ['inventory.view', 'products.index'],
        ['expense.view', 'expenses.index'],
        ['report.profit-loss', 'profit-loss.index'],
        ['supplier.view', 'suppliers.index'],
        ['supplier.add', 'suppliers.create'],
        ['deposit.view', 'deposits.index'],
        ['category.view', 'categories.index'],
        ['brand.view', 'brands.index'],
        ['shop.view', 'shops.index'],
        ['shop.add', 'shops.create'],
        ['role.view', 'roles.index'],
        ['role.add', 'roles.create'],
        ['user.view', 'users.index'],
        ['user.add', 'users.create'],
    ];

    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        foreach (self::LANDING_PAGES as [$permission, $route]) {
            if ($user->checkPermissionTo($permission)) {
                return redirect()->route($route);
            }
        }

        return Inertia::render('Auth/NoAccess');
    }
}
