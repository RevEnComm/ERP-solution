<template>
    <div
        class="px-1 py-4 lg:p-6 space-y-6 bg-gradient-to-br from-gray-50 via-white to-gray-50 min-h-screen"
        :class="{ 'bangla-font': currentLanguage === 'bn' }"
    >
        <FlashToast />

        <!-- Title -->
        <div
            class="flex flex-col lg:flex-row lg:justify-between items-start lg:items-center mb-8 border-b border-gray-200 pb-4 gap-4"
        >
            <h1
                class="text-2xl lg:text-3xl font-semibold text-gray-800 flex items-center tracking-tight animate-fade-in"
            >
                <div
                    class="p-2 mr-3 bg-indigo-100 rounded-full flex items-center justify-center"
                >
                    <svg
                        class="w-6 h-6 lg:w-8 lg:h-8 text-indigo-600"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                        />
                    </svg>
                </div>
                {{ getTranslation("salesReport") }}
            </h1>
            <button
                v-if="viewMode !== 'summary'"
                @click="printReport"
                class="print:hidden inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:bg-slate-800 transition-colors"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H5a2 2 0 01-2-2v-5a2 2 0 012-2h14a2 2 0 012 2v5a2 2 0 01-2 2h-1m-10 0h10v4H10v-4z" />
                </svg>
                {{ getTranslation("printPdf") }}
            </button>
        </div>

        <section class="print-only sales-print-sheet">
            <div class="sales-print-header">
                <div>
                    <p class="sales-print-kicker">{{ getTranslation("salesReport") }}</p>
                    <h2 class="sales-print-title">{{ getTranslation("salesPerformanceSummary") }}</h2>
                    <p class="sales-print-subtitle">
                        {{ getTranslation("reportPeriod") }}:
                        {{ formatDate(filters.start_date || "") }}
                        <span v-if="filters.end_date">- {{ formatDate(filters.end_date || "") }}</span>
                    </p>
                </div>
                <div class="sales-print-meta">
                    <p>{{ getTranslation("generatedOn") }}: {{ printedAtLabel }}</p>
                    <p>{{ getTranslation("status") }}: {{ getTranslation(activeTab) }}</p>
                    <p>{{ getTranslation("viewModeLabel") }}: {{ getTranslation(printViewMode === "invoice" ? "invoiceView" : printViewMode === "product" ? "productView" : "salesSummary") }}</p>
                </div>
            </div>

            <div class="sales-print-summary">
                <div class="sales-print-card">
                    <span>{{ getTranslation("totalSales") }}</span>
                    <strong>{{ toBengaliNumber(filteredSales.length) }}</strong>
                </div>
                <div class="sales-print-card">
                    <span>{{ getTranslation("totalRevenue") }}</span>
                    <strong>৳{{ toBengaliNumber(formatCurrency(printTotals.revenue), 2) }}</strong>
                </div>
                <div v-if="canSeeProfit" class="sales-print-card">
                    <span>{{ getTranslation("totalCost") }}</span>
                    <strong>৳{{ toBengaliNumber(formatCurrency(printTotals.cost), 2) }}</strong>
                </div>
                <div v-if="canSeeProfit" class="sales-print-card">
                    <span>{{ getTranslation("totalProfit") }}</span>
                    <strong>৳{{ toBengaliNumber(formatCurrency(printTotals.profit), 2) }}</strong>
                </div>
                <div class="sales-print-card">
                    <span>{{ getTranslation("totalDue") }}</span>
                    <strong>৳{{ toBengaliNumber(formatCurrency(printTotals.due), 2) }}</strong>
                </div>
                <div class="sales-print-card">
                    <span>{{ getTranslation("totalItems") }}</span>
                    <strong>{{ toBengaliNumber(printTotals.items) }}</strong>
                </div>
            </div>

            <table v-if="printViewMode === 'invoice'" class="sales-print-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ getTranslation("shop") }}</th>
                        <th>{{ getTranslation("supplier") }}</th>
                        <th>{{ getTranslation("saleDate") }}</th>
                        <th>{{ getTranslation("products") }}</th>
                        <th>{{ getTranslation("totalCases") }}</th>
                        <th>{{ getTranslation("totalRevenue") }}</th>
                        <th v-if="canSeeProfit">{{ getTranslation("totalCost") }}</th>
                        <th v-if="canSeeProfit">{{ getTranslation("totalProfit") }}</th>
                        <th>{{ getTranslation("due") }}</th>
                        <th>{{ getTranslation("paymentStatus") }}</th>
                        <th>{{ getTranslation("items") }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(sale, index) in filteredSales" :key="`print-${sale.id}`">
                        <td>{{ toBengaliNumber(index + 1) }}</td>
                        <td>
                            <div>{{ sale.shop_name }}</div>
                            <div style="font-size: 0.8em; color: #666;">#{{ sale.invoice_number }}</div>
                        </td>
                        <td>{{ sale.supplier_name || "-" }}</td>
                        <td>{{ formatDate(sale.sale_date) }}</td>
                        <td>{{ getSaleProductNames(sale) }}</td>
                        <td>{{ toBengaliNumber(getSaleTotalCases(sale)) }}</td>
                        <td>৳{{ toBengaliNumber(formatCurrency(sale.total_amount), 2) }}</td>
                        <td v-if="canSeeProfit">৳{{ toBengaliNumber(formatCurrency(getSaleCost(sale)), 2) }}</td>
                        <td v-if="canSeeProfit">৳{{ toBengaliNumber(formatCurrency(sale.total_profit), 2) }}</td>
                        <td>৳{{ toBengaliNumber(formatCurrency(saleDue(sale)), 2) }}</td>
                        <td>{{ paymentStateOf(sale) ? getTranslation(paymentStateOf(sale)!) : "—" }}</td>
                        <td>{{ toBengaliNumber(sale.items?.length || 0) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">{{ getTranslation("total") }}</td>
                        <td>—</td>
                        <td>{{ toBengaliNumber(filteredSales.reduce((s, sale) => s + getSaleTotalCases(sale), 0)) }}</td>
                        <td>৳{{ toBengaliNumber(formatCurrency(printTotals.revenue), 2) }}</td>
                        <td v-if="canSeeProfit">৳{{ toBengaliNumber(formatCurrency(printTotals.cost), 2) }}</td>
                        <td v-if="canSeeProfit">৳{{ toBengaliNumber(formatCurrency(printTotals.profit), 2) }}</td>
                        <td>৳{{ toBengaliNumber(formatCurrency(printTotals.due), 2) }}</td>
                        <td>—</td>
                        <td>{{ toBengaliNumber(printTotals.items) }}</td>
                    </tr>
                </tfoot>
            </table>

            <table v-else class="sales-print-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ getTranslation("product") }}</th>
                        <th>{{ getTranslation("variant") }}</th>
                        <th>{{ getTranslation("totalCases") }}</th>
                        <th>{{ getTranslation("totalQty") }}</th>
                        <th>{{ getTranslation("totalRevenue") }}</th>
                        <th v-if="canSeeProfit">{{ getTranslation("totalProfit") }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in productSummary" :key="`print-product-${row.product_id}-${row.variant}`">
                        <td>{{ toBengaliNumber(index + 1) }}</td>
                        <td>{{ row.product_name }}</td>
                        <td>{{ row.variant || "-" }}</td>
                        <td>{{ toBengaliNumber(row.total_cases) }}</td>
                        <td>{{ toBengaliNumber(row.total_qty) }}</td>
                        <td>৳{{ toBengaliNumber(formatCurrency(row.total_revenue), 2) }}</td>
                        <td v-if="canSeeProfit">৳{{ toBengaliNumber(formatCurrency(row.total_profit), 2) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">{{ getTranslation("total") }}</td>
                        <td>{{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_cases, 0)) }}</td>
                        <td>{{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_qty, 0)) }}</td>
                        <td>৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_revenue, 0)), 2) }}</td>
                        <td v-if="canSeeProfit">৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_profit, 0)), 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <!-- Total Metrics -->
        <div
            v-if="viewMode !== 'summary'"
            class="print:hidden grid gap-2 sm:gap-4 mb-4"
            :class="canSeeProfit ? 'grid-cols-4' : 'grid-cols-3'"
        >
            <div
                class="bg-gradient-to-br from-indigo-50 to-indigo-100 p-1.5 sm:p-4 lg:p-6 rounded-xl shadow-sm border border-indigo-200 hover:shadow-md transition-shadow"
            >
                <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-0">
                    <div class="p-1.5 sm:p-2 bg-indigo-500 rounded-lg sm:mr-3 flex-shrink-0">
                        <svg
                            class="w-4 h-4 sm:w-6 sm:h-6 text-white"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                            />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[9px] leading-tight sm:text-xs md:text-sm font-medium text-indigo-700">
                            {{ getTranslation("totalSales") }}
                        </p>
                        <p class="text-[11px] sm:text-base md:text-lg font-bold leading-tight break-all text-indigo-900">
                            {{ toBengaliNumber(totalSales) }}
                        </p>
                    </div>
                </div>
            </div>

            <div
                class="bg-gradient-to-br from-green-50 to-green-100 p-1.5 sm:p-4 lg:p-6 rounded-xl shadow-sm border border-green-200 hover:shadow-md transition-shadow"
            >
                <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-0">
                    <div class="p-1.5 sm:p-2 bg-green-500 rounded-lg sm:mr-3 flex-shrink-0">
                        <svg
                            class="w-4 h-4 sm:w-6 sm:h-6 text-white"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[9px] leading-tight sm:text-xs md:text-sm font-medium text-green-700">
                            {{ getTranslation("totalAmount") }}
                        </p>
                        <p class="text-[11px] sm:text-base md:text-lg font-bold leading-tight break-all text-green-900">
                            ৳{{ toBengaliNumber(formatCurrency(totalAmount), 2) }}
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="canSeeProfit"
                class="bg-gradient-to-br from-purple-50 to-purple-100 p-1.5 sm:p-4 lg:p-6 rounded-xl shadow-sm border border-purple-200 hover:shadow-md transition-shadow"
            >
                <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-0">
                    <div class="p-1.5 sm:p-2 bg-purple-500 rounded-lg sm:mr-3 flex-shrink-0">
                        <svg
                            class="w-4 h-4 sm:w-6 sm:h-6 text-white"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
                            />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[9px] leading-tight sm:text-xs md:text-sm font-medium text-purple-700">
                            {{ getTranslation("totalProfit") }}
                        </p>
                        <p
                            class="text-[11px] sm:text-base md:text-lg font-bold leading-tight break-all"
                            :class="
                                totalProfit >= 0
                                    ? 'text-purple-900'
                                    : 'text-red-600'
                            "
                        >
                            ৳{{ toBengaliNumber(formatCurrency(totalProfit), 2) }}
                        </p>
                    </div>
                </div>
            </div>

            <div
                class="bg-gradient-to-br from-orange-50 to-orange-100 p-1.5 sm:p-4 lg:p-6 rounded-xl shadow-sm border border-orange-200 hover:shadow-md transition-shadow"
            >
                <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-0">
                    <div class="p-1.5 sm:p-2 bg-orange-500 rounded-lg sm:mr-3 flex-shrink-0">
                        <svg
                            class="w-4 h-4 sm:w-6 sm:h-6 text-white"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                            />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[9px] leading-tight sm:text-xs md:text-sm font-medium text-orange-700">
                            {{ getTranslation("totalItems") }}
                        </p>
                        <p class="text-[11px] sm:text-base md:text-lg font-bold leading-tight break-all text-orange-900">
                            {{ toBengaliNumber(totalItemsSold) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div
            v-if="viewMode !== 'summary'"
            class="print:hidden bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4 mb-4"
        >
            <div class="flex items-center justify-between gap-2 sm:mb-4">
                <!-- Tapping the title opens/closes the panel on phones; from sm up
                     the panel is always open so the button is inert. -->
                <button
                    type="button"
                    class="flex items-center min-w-0 text-left sm:pointer-events-none"
                    @click="showFilters = !showFilters"
                >
                    <div class="p-1.5 bg-indigo-100 rounded-lg mr-2 flex-shrink-0">
                        <svg
                            class="w-4 h-4 text-indigo-600"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.414A1 1 0 013 6.707V4z"
                            />
                        </svg>
                    </div>
                    <h2 class="text-base sm:text-lg font-semibold text-gray-800">
                        {{ getTranslation("filters") }}
                    </h2>
                    <span
                        v-if="activeFilterCount > 0"
                        class="ml-2 px-1.5 py-0.5 rounded-full bg-indigo-600 text-white text-[10px] font-semibold leading-none"
                    >
                        {{ toBengaliNumber(activeFilterCount) }}
                    </span>
                    <svg
                        class="w-4 h-4 ml-2 text-gray-400 transition-transform flex-shrink-0 sm:hidden"
                        :class="showFilters ? 'rotate-180' : ''"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div :class="[showFilters ? 'flex' : 'hidden sm:flex', 'gap-2 flex-shrink-0']">
                    <button
                        @click="clearFilters"
                        class="px-2.5 py-1.5 sm:px-3 sm:py-2 bg-gray-100 text-gray-700 text-xs sm:text-sm font-medium rounded-lg whitespace-nowrap hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors duration-200"
                    >
                        {{ getTranslation("clearFilters") }}
                    </button>
                    <button
                        @click="applyFilters"
                        class="px-2.5 py-1.5 sm:px-4 sm:py-2 bg-indigo-600 text-white text-xs sm:text-sm font-medium rounded-lg whitespace-nowrap hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-colors duration-200"
                    >
                        <span class="flex items-center">
                            <svg
                                class="w-4 h-4 mr-1.5 hidden sm:block"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.414A1 1 0 013 6.707V4z"
                                />
                            </svg>
                            {{ getTranslation("applyFilters") }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div :class="[showFilters ? 'grid' : 'hidden sm:grid', 'grid-cols-3 gap-2 sm:gap-3 mt-3 sm:mt-0']">
                <div>
                    <label
                        for="shop_id"
                        class="block text-[10px] sm:text-xs font-medium text-gray-600 mb-0.5 sm:mb-1 truncate"
                    >
                        {{ getTranslation("shop") }}
                    </label>
                    <select
                        v-model="filters.shop_id"
                        id="shop_id"
                        class="w-full px-2 py-1.5 sm:px-3 sm:py-2 text-xs sm:text-sm bg-white border border-gray-300 rounded-lg focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                    >
                        <option value="">
                            {{ getTranslation("allShops") }}
                        </option>
                        <option
                            v-for="shop in shops"
                            :key="shop.id"
                            :value="shop.id"
                        >
                            {{ shop.shop_name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label
                        for="product_id"
                        class="block text-[10px] sm:text-xs font-medium text-gray-600 mb-0.5 sm:mb-1 truncate"
                    >
                        {{ getTranslation("product") }}
                    </label>
                    <select
                        v-model="filters.product_id"
                        id="product_id"
                        class="w-full px-2 py-1.5 sm:px-3 sm:py-2 text-xs sm:text-sm bg-white border border-gray-300 rounded-lg focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                    >
                        <option value="">
                            {{ getTranslation("allProducts") }}
                        </option>
                        <option
                            v-for="product in products"
                            :key="product.id"
                            :value="product.id"
                        >
                            {{ product.name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label
                        for="supplier_id"
                        class="block text-[10px] sm:text-xs font-medium text-gray-600 mb-0.5 sm:mb-1 truncate"
                    >
                        {{ getTranslation("supplier") }}
                    </label>
                    <select
                        v-model="filters.supplier_id"
                        id="supplier_id"
                        class="w-full px-2 py-1.5 sm:px-3 sm:py-2 text-xs sm:text-sm bg-white border border-gray-300 rounded-lg focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                    >
                        <option value="">
                            {{ getTranslation("allSuppliers") }}
                        </option>
                        <option
                            v-for="supplier in suppliers"
                            :key="supplier.id"
                            :value="supplier.id"
                        >
                            {{ supplier.company_name }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Search & Filter Fields -->
        <div v-if="viewMode !== 'summary'" class="print:hidden flex flex-col sm:flex-row sm:justify-between sm:items-center mb-4 gap-2 sm:gap-4">
            <div class="flex items-center gap-2 flex-nowrap">
                <!-- Status Tabs -->
                <div class="flex items-center gap-1 sm:gap-2 rounded-xl bg-white p-1 shadow-sm border border-gray-200 flex-shrink-0">
                    <button
                        @click="activeTab = 'completed'"
                        :class="[
                            'px-2 py-1.5 sm:px-4 sm:py-2 text-xs sm:text-sm font-medium rounded-lg transition-colors',
                            activeTab === 'completed' ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100',
                        ]"
                    >
                        {{ getTranslation("completedTab") }}
                    </button>
                    <button
                        @click="activeTab = 'draft'"
                        :class="[
                            'px-2 py-1.5 sm:px-4 sm:py-2 text-xs sm:text-sm font-medium rounded-lg transition-colors',
                            activeTab === 'draft' ? 'bg-amber-500 text-white' : 'text-gray-600 hover:bg-gray-100',
                        ]"
                    >
                        {{ getTranslation("draftTab") }}
                    </button>
                </div>

                <!-- View Mode Toggle -->
                <div class="flex items-center gap-1 rounded-xl bg-white p-1 shadow-sm border border-gray-200 flex-shrink-0">
                    <button
                        @click="viewMode = 'invoice'"
                        :class="[
                            'px-2 py-1.5 sm:px-4 sm:py-2 text-xs sm:text-sm font-medium rounded-lg transition-colors flex items-center gap-1 sm:gap-1.5',
                            viewMode === 'invoice' ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100',
                        ]"
                    >
                        <svg class="w-4 h-4 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="sm:hidden">{{ getTranslation("invoiceViewShort") }}</span>
                        <span class="hidden sm:inline">{{ getTranslation("invoiceView") }}</span>
                    </button>
                    <button
                        @click="viewMode = 'product'"
                        :class="[
                            'px-2 py-1.5 sm:px-4 sm:py-2 text-xs sm:text-sm font-medium rounded-lg transition-colors flex items-center gap-1 sm:gap-1.5',
                            viewMode === 'product' ? 'bg-teal-600 text-white' : 'text-gray-600 hover:bg-gray-100',
                        ]"
                    >
                        <svg class="w-4 h-4 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <span class="sm:hidden">{{ getTranslation("productViewShort") }}</span>
                        <span class="hidden sm:inline">{{ getTranslation("productView") }}</span>
                    </button>
                </div>
            </div>
            <!-- Date range + search share a row on phones. `sm:contents` dissolves
                 this wrapper from sm up, restoring the original 3-way split. -->
            <div class="flex flex-row items-center gap-2 sm:contents">
                <DateRangePicker
                    v-model:startDate="filters.start_date"
                    v-model:endDate="filters.end_date"
                    :language="currentLanguage"
                    @change="applyFilters"
                    class="flex-1 min-w-0 sm:flex-none sm:w-auto"
                />
                <div class="relative flex-1 min-w-0 sm:flex-none sm:w-80">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-2.5 sm:pl-3 pointer-events-none">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="searchQuery"
                        type="text"
                        :placeholder="getTranslation('searchSales')"
                        class="w-full pl-8 sm:pl-10 pr-2 sm:pr-4 py-2 sm:py-3 bg-white border-2 border-gray-200 rounded-xl shadow-sm focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 transition-all duration-300 text-xs sm:text-sm font-medium hover:border-indigo-300 text-ellipsis"
                    />
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="isLoading" class="print:hidden flex justify-center items-center py-12">
            <div
                class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"
            ></div>
        </div>

        <!-- Empty State -->
        <div v-else-if="(viewMode === 'invoice' && filteredSales.length === 0) || (viewMode === 'product' && productSummary.length === 0) || (viewMode === 'summary' && filteredSales.length === 0)" class="print:hidden text-center py-12">
            <svg
                class="mx-auto h-12 w-12 text-gray-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">
                {{ getTranslation("noSales") }}
            </h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ getTranslation("noSalesDescription") }}
            </p>
        </div>

        <div v-else-if="viewMode === 'summary'" class="print:hidden space-y-3 sm:space-y-6">
            <div class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-2.5 sm:p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <!-- Row 1 on phones: date range + view toggle -->
                <div class="flex items-center gap-2 min-w-0">
                    <DateRangePicker
                        v-model:startDate="filters.start_date"
                        v-model:endDate="filters.end_date"
                        :language="currentLanguage"
                        @change="applyFilters"
                        class="flex-1 min-w-0 sm:flex-none sm:w-auto"
                    />
                    <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1 flex-shrink-0">
                        <button
                            @click="summaryDisplayMode = 'invoice'"
                            :class="[
                                'rounded-md px-2 py-1.5 sm:px-3 sm:py-2 text-xs sm:text-sm font-medium transition-colors',
                                summaryDisplayMode === 'invoice' ? 'bg-white text-indigo-600 shadow-sm' : 'text-gray-600 hover:bg-white/70',
                            ]"
                        >
                            <span class="sm:hidden">{{ getTranslation("invoiceViewShort") }}</span>
                            <span class="hidden sm:inline">{{ getTranslation("invoiceView") }}</span>
                        </button>
                        <button
                            @click="summaryDisplayMode = 'product'"
                            :class="[
                                'rounded-md px-2 py-1.5 sm:px-3 sm:py-2 text-xs sm:text-sm font-medium transition-colors',
                                summaryDisplayMode === 'product' ? 'bg-white text-teal-600 shadow-sm' : 'text-gray-600 hover:bg-white/70',
                            ]"
                        >
                            <span class="sm:hidden">{{ getTranslation("productViewShort") }}</span>
                            <span class="hidden sm:inline">{{ getTranslation("productView") }}</span>
                        </button>
                    </div>
                </div>
                <!-- Row 2 on phones: actions -->
                <div class="flex items-center gap-1.5 sm:gap-2">
                    <button
                        @click="clearFilters"
                        class="flex-1 sm:flex-none px-2 py-1.5 sm:px-3 sm:py-2 bg-gray-100 text-gray-700 text-xs sm:text-sm font-medium rounded-lg whitespace-nowrap hover:bg-gray-200 transition-colors"
                    >
                        {{ getTranslation("clearFilters") }}
                    </button>
                    <button
                        @click="applyFilters"
                        class="flex-1 sm:flex-none px-2 py-1.5 sm:px-4 sm:py-2 bg-indigo-600 text-white text-xs sm:text-sm font-medium rounded-lg whitespace-nowrap hover:bg-indigo-700 transition-colors"
                    >
                        {{ getTranslation("applyFilters") }}
                    </button>
                    <button
                        @click="printReport"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 sm:gap-2 rounded-lg bg-slate-900 px-2 py-1.5 sm:px-4 sm:py-2 text-xs sm:text-sm font-medium text-white whitespace-nowrap hover:bg-slate-800 transition-colors"
                    >
                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H5a2 2 0 01-2-2v-5a2 2 0 012-2h14a2 2 0 012 2v5a2 2 0 01-2 2h-1m-10 0h10v4H10v-4z" />
                        </svg>
                        {{ getTranslation("printPdf") }}
                    </button>
                </div>
            </div>

            <section class="sales-summary-sheet">
            <div class="mb-3 sm:mb-5 flex flex-col gap-1 sm:gap-3 border-b border-gray-200 pb-2.5 sm:pb-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-[9px] sm:text-xs font-semibold uppercase tracking-[0.12em] sm:tracking-[0.18em] text-slate-500">{{ getTranslation("salesSummary") }}</p>
                    <h2 class="mt-0.5 sm:mt-1 text-base sm:text-2xl font-bold text-slate-900">{{ getTranslation("salesPerformanceSummary") }}</h2>
                    <p class="mt-0.5 sm:mt-2 text-[11px] sm:text-sm text-slate-500">
                        {{ getTranslation("reportPeriod") }}:
                        {{ formatDate(filters.start_date || "") }}
                        <span v-if="filters.end_date">- {{ formatDate(filters.end_date || "") }}</span>
                    </p>
                </div>
                <div class="text-[11px] sm:text-sm text-slate-500 flex-shrink-0">
                    {{ getTranslation("generatedOn") }}: {{ printedAtLabel }}
                </div>
            </div>

            <div class="sales-summary-stats mb-5">
                <div class="sales-summary-stat">
                    <span>{{ summaryDisplayMode === 'invoice' ? getTranslation("totalSales") : getTranslation("product") }}</span>
                    <strong>{{ toBengaliNumber(summaryDisplayMode === 'invoice' ? filteredSales.length : productSummary.length) }}</strong>
                </div>
                <div class="sales-summary-stat">
                    <span>{{ getTranslation("totalRevenue") }}</span>
                    <strong>৳{{ toBengaliNumber(formatCurrency(printTotals.revenue), 2) }}</strong>
                </div>
                <div v-if="canSeeProfit" class="sales-summary-stat">
                    <span>{{ getTranslation("totalCost") }}</span>
                    <strong>৳{{ toBengaliNumber(formatCurrency(printTotals.cost), 2) }}</strong>
                </div>
                <div v-if="canSeeProfit" class="sales-summary-stat">
                    <span>{{ getTranslation("totalProfit") }}</span>
                    <strong>৳{{ toBengaliNumber(formatCurrency(printTotals.profit), 2) }}</strong>
                </div>
                <div class="sales-summary-stat">
                    <span>{{ summaryDisplayMode === 'invoice' ? getTranslation("totalItems") : getTranslation("totalQty") }}</span>
                    <strong>{{ toBengaliNumber(summaryDisplayMode === 'invoice' ? printTotals.items : productSummary.reduce((s, r) => s + r.total_qty, 0)) }}</strong>
                </div>
            </div>

            <template v-if="summaryDisplayMode === 'invoice'">
            <div class="hidden lg:block overflow-x-auto">
                <table class="w-full min-w-[920px] divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("shop") }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("supplier") }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("saleDate") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("totalRevenue") }}</th>
                            <th v-if="canSeeProfit" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("totalCost") }}</th>
                            <th v-if="canSeeProfit" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("totalProfit") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("totalCases") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("items") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-for="(sale, index) in filteredSales" :key="`summary-${sale.id}`" class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-sm text-slate-500">{{ toBengaliNumber(index + 1) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-slate-800">{{ sale.shop_name }}</span>
                                    <span class="text-xs text-slate-500 mt-0.5">#{{ sale.invoice_number }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600">{{ sale.supplier_name || "-" }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600">{{ formatDate(sale.sale_date) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-slate-800">৳{{ toBengaliNumber(formatCurrency(sale.total_amount), 2) }}</td>
                            <td v-if="canSeeProfit" class="px-4 py-3 text-right text-sm text-slate-700">৳{{ toBengaliNumber(formatCurrency(getSaleCost(sale)), 2) }}</td>
                            <td v-if="canSeeProfit" class="px-4 py-3 text-right text-sm font-semibold" :class="Number(sale.total_profit) >= 0 ? 'text-emerald-600' : 'text-red-600'">৳{{ toBengaliNumber(formatCurrency(sale.total_profit), 2) }}</td>
                            <td class="px-4 py-3 text-right text-sm text-slate-700">{{ toBengaliNumber(sale.items?.reduce((s, item) => s + (item.cases_sold || 0), 0) || 0) }}</td>
                            <td class="px-4 py-3 text-right text-sm text-slate-700">{{ toBengaliNumber(sale.items?.reduce((s, item) => s + (item.total_bottles_sold || item.quantity || 0), 0) || 0) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-sm font-semibold text-slate-700">{{ getTranslation("total") }}</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900">৳{{ toBengaliNumber(formatCurrency(printTotals.revenue), 2) }}</td>
                            <td v-if="canSeeProfit" class="px-4 py-3 text-right text-sm font-bold text-slate-900">৳{{ toBengaliNumber(formatCurrency(printTotals.cost), 2) }}</td>
                            <td v-if="canSeeProfit" class="px-4 py-3 text-right text-sm font-bold" :class="printTotals.profit >= 0 ? 'text-emerald-700' : 'text-red-700'">৳{{ toBengaliNumber(formatCurrency(printTotals.profit), 2) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900">{{ toBengaliNumber(printTotals.cases) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900">{{ toBengaliNumber(printTotals.items) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Invoice summary cards (mobile) -->
            <div class="lg:hidden divide-y divide-slate-100">
                <div
                    v-for="(sale, index) in filteredSales"
                    :key="`summary-m-${sale.id}`"
                    class="px-2.5 py-2"
                >
                    <div class="flex items-start gap-2">
                        <span class="text-[10px] font-medium text-slate-400 mt-0.5 flex-shrink-0">{{ toBengaliNumber(index + 1) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-slate-800 break-words">{{ sale.shop_name }}</p>
                            <p class="text-[10px] text-slate-500">#{{ sale.invoice_number }}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-xs font-bold text-slate-800">৳{{ toBengaliNumber(formatCurrency(sale.total_amount), 2) }}</p>
                            <p v-if="canSeeProfit" class="text-[10px] font-semibold" :class="Number(sale.total_profit) >= 0 ? 'text-emerald-600' : 'text-red-600'">
                                ৳{{ toBengaliNumber(formatCurrency(sale.total_profit), 2) }}
                            </p>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-x-2 gap-y-1 mt-1.5 text-[10px]">
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("supplier") }}</span>
                            <span class="font-medium text-slate-700 break-words">{{ sale.supplier_name || "-" }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("saleDate") }}</span>
                            <span class="font-medium text-slate-700">{{ formatDate(sale.sale_date) }}</span>
                        </div>
                        <div v-if="canSeeProfit" class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalCost") }}</span>
                            <span class="font-medium text-slate-700">৳{{ toBengaliNumber(formatCurrency(getSaleCost(sale)), 2) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalCases") }}</span>
                            <span class="font-medium text-slate-700">{{ toBengaliNumber(sale.items?.reduce((s, item) => s + (item.cases_sold || 0), 0) || 0) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("items") }}</span>
                            <span class="font-medium text-slate-700">{{ toBengaliNumber(sale.items?.reduce((s, item) => s + (item.total_bottles_sold || item.quantity || 0), 0) || 0) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Totals -->
                <div class="px-2.5 py-2 bg-slate-50">
                    <p class="text-[10px] sm:text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5">{{ getTranslation("total") }}</p>
                    <div class="grid grid-cols-3 gap-x-2 gap-y-1 text-[10px]">
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalRevenue") }}</span>
                            <span class="font-bold text-slate-900">৳{{ toBengaliNumber(formatCurrency(printTotals.revenue), 2) }}</span>
                        </div>
                        <div v-if="canSeeProfit" class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalCost") }}</span>
                            <span class="font-bold text-slate-900">৳{{ toBengaliNumber(formatCurrency(printTotals.cost), 2) }}</span>
                        </div>
                        <div v-if="canSeeProfit" class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalProfit") }}</span>
                            <span class="font-bold" :class="printTotals.profit >= 0 ? 'text-emerald-700' : 'text-red-700'">৳{{ toBengaliNumber(formatCurrency(printTotals.profit), 2) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalCases") }}</span>
                            <span class="font-bold text-slate-900">{{ toBengaliNumber(printTotals.cases) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("items") }}</span>
                            <span class="font-bold text-slate-900">{{ toBengaliNumber(printTotals.items) }}</span>
                        </div>
                    </div>
                </div>
            </div>
            </template>

            <template v-else>
            <div class="hidden lg:block overflow-x-auto">
                <table class="w-full min-w-[920px] divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("product") }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("variant") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("totalCases") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("totalQty") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("totalRevenue") }}</th>
                            <th v-if="canSeeProfit" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ getTranslation("totalProfit") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-for="(row, index) in productSummary" :key="`summary-product-${row.product_id}-${row.variant}`" class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-sm text-slate-500">{{ toBengaliNumber(index + 1) }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-800">{{ row.product_name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600">{{ row.variant || "-" }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium text-slate-700">{{ toBengaliNumber(row.total_cases) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium text-slate-700">{{ toBengaliNumber(row.total_qty) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-slate-800">৳{{ toBengaliNumber(formatCurrency(row.total_revenue), 2) }}</td>
                            <td v-if="canSeeProfit" class="px-4 py-3 text-right text-sm font-semibold" :class="row.total_profit >= 0 ? 'text-emerald-600' : 'text-red-600'">৳{{ toBengaliNumber(formatCurrency(row.total_profit), 2) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr>
                            <td colspan="3" class="px-4 py-3 text-sm font-semibold text-slate-700">{{ getTranslation("total") }}</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900">{{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_cases, 0)) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900">{{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_qty, 0)) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900">৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_revenue, 0)), 2) }}</td>
                            <td v-if="canSeeProfit" class="px-4 py-3 text-right text-sm font-bold" :class="productSummary.reduce((s, r) => s + r.total_profit, 0) >= 0 ? 'text-emerald-700' : 'text-red-700'">৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_profit, 0)), 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Product summary cards (mobile) -->
            <div class="lg:hidden divide-y divide-slate-100">
                <div
                    v-for="(row, index) in productSummary"
                    :key="`summary-product-m-${row.product_id}-${row.variant}`"
                    class="px-2.5 py-2"
                >
                    <div class="flex items-start gap-2">
                        <span class="text-[10px] font-medium text-slate-400 mt-0.5 flex-shrink-0">{{ toBengaliNumber(index + 1) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-slate-800 break-words">{{ row.product_name }}</p>
                            <span
                                v-if="row.variant"
                                class="inline-flex items-center mt-1 px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-xs font-medium"
                            >
                                {{ row.variant }}
                            </span>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-xs font-bold text-slate-800">৳{{ toBengaliNumber(formatCurrency(row.total_revenue), 2) }}</p>
                            <p v-if="canSeeProfit" class="text-[10px] font-semibold" :class="row.total_profit >= 0 ? 'text-emerald-600' : 'text-red-600'">
                                ৳{{ toBengaliNumber(formatCurrency(row.total_profit), 2) }}
                            </p>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-x-2 gap-y-1 mt-1.5 text-[10px]">
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalCases") }}</span>
                            <span class="font-medium text-slate-700">{{ toBengaliNumber(row.total_cases) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalQty") }}</span>
                            <span class="font-medium text-slate-700">{{ toBengaliNumber(row.total_qty) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Totals -->
                <div class="px-2.5 py-2 bg-slate-50">
                    <p class="text-[10px] sm:text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5">{{ getTranslation("total") }}</p>
                    <div class="grid grid-cols-3 gap-x-2 gap-y-1 text-[10px]">
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalCases") }}</span>
                            <span class="font-bold text-slate-900">{{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_cases, 0)) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalQty") }}</span>
                            <span class="font-bold text-slate-900">{{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_qty, 0)) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalRevenue") }}</span>
                            <span class="font-bold text-slate-900">৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_revenue, 0)), 2) }}</span>
                        </div>
                        <div v-if="canSeeProfit" class="flex flex-col">
                            <span class="text-slate-400">{{ getTranslation("totalProfit") }}</span>
                            <span class="font-bold" :class="productSummary.reduce((s, r) => s + r.total_profit, 0) >= 0 ? 'text-emerald-700' : 'text-red-700'">৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_profit, 0)), 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>
            </template>
            </section>
        </div>

        <!-- Sales Table (Invoice View) -->
        <div v-else-if="viewMode === 'invoice'" class="print:hidden bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th
                                class="px-3 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                {{ getTranslation("shop") }}
                            </th>
                            <th
                                class="px-3 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden xl:table-cell"
                            >
                                {{ getTranslation("supplier") }}
                            </th>
                            <th
                                class="px-3 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell"
                            >
                                {{ getTranslation("totalAmount") }}
                            </th>
                            <th
                                class="px-3 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell"
                            >
                                {{ getTranslation("due") }}
                            </th>
                            <th v-if="canSeeProfit"
                                class="px-3 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell"
                            >
                                {{ getTranslation("totalProfit") }}
                            </th>
                            <th
                                class="px-3 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell"
                            >
                                {{ getTranslation("saleDate") }}
                            </th>
                            <th
                                class="px-3 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell"
                            >
                                {{ getTranslation("status") }}
                            </th>
                            <th
                                class="px-3 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell"
                            >
                                {{ getTranslation("paymentStatus") }}
                            </th>
                            <th
                                class="px-3 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                {{ getTranslation("actions") }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template
                            v-for="(sale, index) in filteredSales"
                            :key="sale.id"
                        >
                            <tr v-if="startsSupplierGroup(index)" class="bg-slate-100/70">
                                <td
                                    colspan="9"
                                    class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600"
                                >
                                    {{ sale.supplier_name || "-" }}
                                </td>
                            </tr>
                            <tr
                                class="hover:bg-gray-50 transition-colors cursor-pointer"
                                @click="toggleItems(sale.id)"
                            >
                                <td
                                    class="px-2 py-2.5 md:px-3 md:py-4 text-sm font-medium text-gray-900"
                                >
                                    <div class="flex items-start">
                                        <svg
                                            :class="[
                                                'w-4 h-4 mr-2 mt-0.5 transition-transform flex-shrink-0',
                                                expandedSale === sale.id
                                                    ? 'rotate-90'
                                                    : '',
                                            ]"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M9 5l7 7-7 7"
                                            />
                                        </svg>
                                        <div class="flex flex-col min-w-0">
                                            <span
                                                class="font-semibold text-gray-800 break-words"
                                                :title="sale.shop_name"
                                                >{{ sale.shop_name }}</span
                                            >
                                            <span
                                                class="text-xs text-gray-500 mt-0.5"
                                                :title="sale.invoice_number"
                                                >#{{ sale.invoice_number }}</span
                                            >

                                            <!-- Phones hide the amount/profit/date/status
                                                 columns, so surface them here instead. -->
                                            <div class="md:hidden mt-1 space-y-0.5">
                                                <div class="flex items-baseline gap-2 flex-wrap">
                                                    <span class="text-sm font-bold text-gray-800">
                                                        ৳{{ toBengaliNumber(formatCurrency(sale.total_amount), 2) }}
                                                    </span>
                                                    <span v-if="canSeeProfit"
                                                        class="text-xs font-semibold"
                                                        :class="Number(sale.total_profit) >= 0 ? 'text-green-600' : 'text-red-600'"
                                                    >
                                                        ৳{{ toBengaliNumber(formatCurrency(sale.total_profit), 2) }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <span class="text-xs text-gray-500 font-normal">{{ formatDate(sale.sale_date) }}</span>
                                                    <span
                                                        :class="{
                                                            'bg-yellow-100 text-yellow-800': sale.status === 'pending',
                                                            'bg-blue-100 text-blue-800': sale.status === 'in_progress',
                                                            'bg-green-100 text-green-800': sale.status === 'completed',
                                                        }"
                                                        class="px-2 py-0.5 rounded-full text-[10px] font-medium capitalize"
                                                    >
                                                        {{ getTranslation(sale.status) }}
                                                    </span>
                                                    <span
                                                        v-if="paymentStateOf(sale)"
                                                        :class="paymentStateClass(paymentStateOf(sale))"
                                                        class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                                                    >
                                                        {{ getTranslation(paymentStateOf(sale)!) }}
                                                    </span>
                                                </div>
                                                <div v-if="saleDue(sale) > 0" class="text-xs font-semibold text-red-600">
                                                    {{ getTranslation("due") }}: ৳{{ toBengaliNumber(formatCurrency(saleDue(sale)), 2) }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td
                                    class="px-3 py-4 text-sm text-gray-500 hidden xl:table-cell"
                                >
                                    <span
                                        class="truncate max-w-32"
                                        :title="sale.supplier_name"
                                        >{{ sale.supplier_name }}</span
                                    >
                                </td>
                                <td class="px-3 py-4 text-sm text-gray-500 hidden md:table-cell">
                                    <div class="text-right font-medium">
                                        ৳{{
                                            toBengaliNumber(
                                                formatCurrency(
                                                    sale.total_amount
                                                ),
                                                2
                                            )
                                        }}
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-sm hidden md:table-cell">
                                    <div
                                        class="text-right font-medium"
                                        :class="saleDue(sale) > 0 ? 'text-red-600' : 'text-gray-400'"
                                    >
                                        ৳{{ toBengaliNumber(formatCurrency(saleDue(sale)), 2) }}
                                    </div>
                                </td>
                                <td v-if="canSeeProfit"
                                    class="px-3 py-4 text-sm hidden md:table-cell"
                                    :class="
                                        Number(sale.total_profit) >= 0
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    "
                                >
                                    <div class="text-right font-medium">
                                        ৳{{
                                            toBengaliNumber(
                                                formatCurrency(
                                                    sale.total_profit
                                                ),
                                                2
                                            )
                                        }}
                                    </div>
                                </td>
                                <td
                                    class="px-3 py-4 text-sm text-gray-500 hidden md:table-cell"
                                >
                                    {{ formatDate(sale.sale_date) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm hidden md:table-cell"
                                >
                                    <span
                                        :class="{
                                            'bg-yellow-100 text-yellow-800':
                                                sale.status === 'pending',
                                            'bg-blue-100 text-blue-800':
                                                sale.status === 'in_progress',
                                            'bg-green-100 text-green-800':
                                                sale.status === 'completed',
                                        }"
                                        class="px-2 py-1 rounded-full text-xs font-medium capitalize"
                                    >
                                        {{ getTranslation(sale.status) }}
                                    </span>
                                </td>
                                <td
                                    class="px-3 py-4 text-sm hidden md:table-cell"
                                >
                                    <span
                                        v-if="paymentStateOf(sale)"
                                        :class="paymentStateClass(paymentStateOf(sale))"
                                        class="px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap"
                                    >
                                        {{ getTranslation(paymentStateOf(sale)!) }}
                                    </span>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-2 py-2.5 md:px-3 md:py-4 text-sm align-top">
                                    <div
                                        class="flex flex-wrap gap-1 justify-end md:justify-start"
                                    >
                                        <button
                                            @click.stop="toggleItems(sale.id)"
                                            class="px-2 py-1 bg-indigo-100 text-indigo-600 rounded text-xs hover:bg-indigo-200 transition duration-200 whitespace-nowrap"
                                        >
                                            {{
                                                expandedSale === sale.id
                                                    ? getTranslation("hide")
                                                    : `${getTranslation(
                                                          "show"
                                                      )} (${toBengaliNumber(
                                                          sale.items?.length ||
                                                              0
                                                      )})`
                                            }}
                                        </button>
                                        <button
                                            v-if="sale.status === 'draft'"
                                            @click.stop="continueDraft(sale.id)"
                                            class="px-2 py-1 bg-amber-100 text-amber-700 rounded text-xs hover:bg-amber-200 transition duration-200 whitespace-nowrap"
                                        >
                                            {{ getTranslation("continueDraft") }}
                                        </button>
                                        <button
                                            @click.stop="viewCashMemo(sale.id)"
                                            v-if="sale.status !== 'draft'"
                                            class="px-2 py-1 bg-green-100 text-green-600 rounded text-xs hover:bg-green-200 transition duration-200 whitespace-nowrap"
                                        >
                                            {{ getTranslation("view") }}
                                        </button>
                                        <button
                                            v-if="paymentStateOf(sale) === 'due'"
                                            @click.stop="collectDue(sale.id)"
                                            class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium hover:bg-blue-200 transition duration-200 whitespace-nowrap"
                                        >
                                            {{ getTranslation("dueCollection") }}
                                        </button>
                                        <button
                                            @click.stop="router.visit(`/sales/${sale.id}/edit`)"
                                            class="px-2 py-1 bg-orange-100 text-orange-600 rounded text-xs hover:bg-orange-200 transition duration-200 whitespace-nowrap"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            @click.stop="deleteSale(sale.id)"
                                            class="px-2 py-1 bg-red-100 text-red-600 rounded text-xs hover:bg-red-200 transition duration-200 whitespace-nowrap"
                                        >
                                            {{ getTranslation('delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Expanded Row -->
                            <tr
                                v-if="expandedSale === sale.id"
                                class="bg-gradient-to-r from-gray-50 to-gray-100 animate-slide-down"
                            >
                                <td :colspan="7" class="px-2 py-3 md:px-6 md:py-6">
                                    <div class="md:ml-6">
                                        <!-- Supplier is the one column hidden until xl;
                                             everything else already shows in the row. -->
                                        <p class="xl:hidden mb-3 text-xs text-gray-500">
                                            {{ getTranslation("supplier") }}:
                                            <span class="font-semibold text-gray-800 break-words">{{ sale.supplier_name || "-" }}</span>
                                        </p>

                                        <!-- Items Header -->
                                        <h4 class="mb-2 flex items-center text-sm font-semibold text-gray-800">
                                            <svg
                                                class="w-4 h-4 mr-1.5 text-indigo-600 flex-shrink-0"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
                                                />
                                            </svg>
                                            {{ getTranslation("orderItems") }}
                                            <span class="ml-1.5 px-1.5 py-0.5 bg-indigo-100 text-indigo-700 rounded-full text-[10px] font-medium">
                                                {{ toBengaliNumber(sale.items?.length || 0) }}
                                            </span>
                                        </h4>

                                        <!-- Items List -->
                                        <div
                                            v-if="
                                                sale.items &&
                                                sale.items.length > 0
                                            "
                                        >
                                            <!-- Mobile Cards -->
                                            <div class="lg:hidden space-y-1.5">
                                                <div
                                                    v-for="(item, itemIndex) in sale.items"
                                                    :key="`${item.product_id}-${item.variant}-${itemIndex}`"
                                                    class="bg-white rounded-lg px-2.5 py-2 shadow-sm border border-gray-200"
                                                >
                                                    <!-- product + variant + bottles -->
                                                    <div class="flex items-center justify-between gap-2">
                                                        <div class="flex items-baseline gap-1.5 min-w-0">
                                                            <h5
                                                                class="text-xs font-semibold text-gray-900 truncate"
                                                                :title="item.product_name"
                                                            >
                                                                {{ item.product_name }}
                                                            </h5>
                                                            <span
                                                                v-if="item.variant"
                                                                class="flex-shrink-0 px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 text-[10px] font-medium"
                                                            >
                                                                {{ item.variant }}
                                                            </span>
                                                        </div>
                                                        <span class="flex-shrink-0 flex items-center gap-1 text-[11px] font-medium text-gray-600">
                                                            <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                                            </svg>
                                                            {{ toBengaliNumber(item.total_bottles_sold || item.quantity || 0) }}
                                                        </span>
                                                    </div>

                                                    <!-- figures on one wrapping line -->
                                                    <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-[11px] text-gray-500">
                                                        <span>
                                                            {{ getTranslation("totalPrice") }}
                                                            <span class="font-bold text-gray-900">৳{{ toBengaliNumber(formatCurrency(item.total_price)) }}</span>
                                                        </span>
                                                        <span v-if="canSeeProfit">
                                                            {{ getTranslation("profit") }}
                                                            <span
                                                                class="font-bold"
                                                                :class="parseFloat(item.profit.toString()) >= 0 ? 'text-green-600' : 'text-red-600'"
                                                            >৳{{ toBengaliNumber(formatCurrency(item.profit)) }}</span>
                                                        </span>
                                                        <span v-if="item.cases_sold">
                                                            {{ getTranslation("cases") }}
                                                            <span class="font-bold text-blue-600">{{ toBengaliNumber(item.cases_sold) }}</span>
                                                        </span>
                                                        <span v-if="item.extra_bottles">
                                                            {{ getTranslation("extraBottles") }}
                                                            <span class="font-bold text-blue-600">{{ toBengaliNumber(item.extra_bottles) }}</span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Desktop Table -->
                                            <div
                                                class="hidden lg:block overflow-hidden rounded-xl border border-gray-200 shadow-sm"
                                            >
                                                <table
                                                    class="w-full table-fixed bg-white"
                                                >
                                                    <thead
                                                        class="bg-gradient-to-r from-indigo-50 to-purple-50"
                                                    >
                                                        <tr>
                                                            <th
                                                                class="w-2/5 px-4 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border-r border-gray-200"
                                                            >
                                                                <div
                                                                    class="flex items-center"
                                                                >
                                                                    <svg
                                                                        class="w-4 h-4 mr-2 text-indigo-600"
                                                                        fill="none"
                                                                        stroke="currentColor"
                                                                        viewBox="0 0 24 24"
                                                                    >
                                                                        <path
                                                                            stroke-linecap="round"
                                                                            stroke-linejoin="round"
                                                                            stroke-width="2"
                                                                            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                                                                        />
                                                                    </svg>
                                                                    {{
                                                                        getTranslation(
                                                                            "product"
                                                                        )
                                                                    }}
                                                                </div>
                                                            </th>
                                                            <th
                                                                class="w-1/5 px-4 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border-r border-gray-200"
                                                            >
                                                                {{
                                                                    getTranslation(
                                                                        "variant"
                                                                    )
                                                                }}
                                                            </th>
                                                            <th
                                                                class="w-1/12 px-4 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border-r border-gray-200"
                                                            >
                                                                {{
                                                                    getTranslation(
                                                                        "quantity"
                                                                    )
                                                                }}
                                                            </th>
                                                            <th
                                                                v-if="
                                                                    hasAnyCases
                                                                "
                                                                class="w-1/12 px-4 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border-r border-gray-200"
                                                            >
                                                                {{
                                                                    getTranslation(
                                                                        "cases"
                                                                    )
                                                                }}
                                                            </th>
                                                            <th
                                                                class="w-1/12 px-4 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border-r border-gray-200"
                                                            >
                                                                {{
                                                                    getTranslation(
                                                                        "extraBottles"
                                                                    )
                                                                }}
                                                            </th>
                                                            <th
                                                                class="w-1/6 px-4 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider border-r border-gray-200"
                                                            >
                                                                {{
                                                                    getTranslation(
                                                                        "totalPrice"
                                                                    )
                                                                }}
                                                            </th>
                                                            <th v-if="canSeeProfit"
                                                                class="w-1/6 px-4 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"
                                                            >
                                                                {{
                                                                    getTranslation(
                                                                        "profit"
                                                                    )
                                                                }}
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        class="divide-y divide-gray-100"
                                                    >
                                                        <tr
                                                            v-for="(
                                                                item, itemIndex
                                                            ) in sale.items"
                                                            :key="`${item.product_id}-${item.variant}-${itemIndex}`"
                                                            class="hover:bg-gray-50 transition-colors"
                                                            :class="
                                                                itemIndex %
                                                                    2 ===
                                                                0
                                                                    ? 'bg-white'
                                                                    : 'bg-gray-25'
                                                            "
                                                        >
                                                            <td
                                                                class="px-4 py-4 border-r border-gray-200"
                                                            >
                                                                <div
                                                                    class="flex items-center"
                                                                >
                                                                    <div
                                                                        class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center mr-3"
                                                                    >
                                                                        <span
                                                                            class="text-xs font-medium text-indigo-700"
                                                                        >
                                                                            {{
                                                                                toBengaliNumber(
                                                                                    itemIndex +
                                                                                        1
                                                                                )
                                                                            }}
                                                                        </span>
                                                                    </div>
                                                                    <div>
                                                                        <p
                                                                            class="font-semibold text-gray-900"
                                                                            :title="
                                                                                item.product_name
                                                                            "
                                                                        >
                                                                            {{
                                                                                item.product_name
                                                                            }}
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td
                                                                class="px-4 py-4 border-r border-gray-200"
                                                            >
                                                                <span
                                                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800"
                                                                >
                                                                    {{
                                                                        item.variant
                                                                    }}
                                                                </span>
                                                            </td>
                                                            <td
                                                                class="px-4 py-4 text-center border-r border-gray-200"
                                                            >
                                                                <span
                                                                    class="inline-flex items-center justify-center w-12 h-8 bg-indigo-100 text-indigo-800 rounded-lg font-bold text-sm"
                                                                >
                                                                    {{
                                                                        toBengaliNumber(
                                                                            item.total_bottles_sold ||
                                                                                item.quantity ||
                                                                                0
                                                                        )
                                                                    }}
                                                                </span>
                                                            </td>
                                                            <td
                                                                v-if="
                                                                    hasAnyCases
                                                                "
                                                                class="px-4 py-4 text-center border-r border-gray-200"
                                                            >
                                                                <span
                                                                    v-if="
                                                                        item.cases_sold
                                                                    "
                                                                    class="inline-flex items-center justify-center w-12 h-8 bg-blue-100 text-blue-800 rounded-lg font-bold text-sm"
                                                                >
                                                                    {{
                                                                        toBengaliNumber(
                                                                            item.cases_sold
                                                                        )
                                                                    }}
                                                                </span>
                                                                <span
                                                                    v-else
                                                                    class="text-gray-400"
                                                                    >-</span
                                                                >
                                                            </td>
                                                            <td
                                                                class="px-4 py-4 text-center border-r border-gray-200"
                                                            >
                                                                <span
                                                                    v-if="
                                                                        item.extra_bottles
                                                                    "
                                                                    class="inline-flex items-center justify-center w-12 h-8 bg-indigo-50 text-indigo-700 rounded-lg font-bold text-sm"
                                                                >
                                                                    {{
                                                                        toBengaliNumber(
                                                                            item.extra_bottles
                                                                        )
                                                                    }}
                                                                </span>
                                                                <span
                                                                    v-else
                                                                    class="text-gray-400"
                                                                    >-</span
                                                                >
                                                            </td>
                                                            <td
                                                                class="px-4 py-4 text-right border-r border-gray-200"
                                                            >
                                                                <span
                                                                    class="font-bold text-gray-900 text-lg"
                                                                >
                                                                    ৳{{
                                                                        toBengaliNumber(
                                                                            formatCurrency(
                                                                                item.total_price
                                                                            )
                                                                        )
                                                                    }}
                                                                </span>
                                                            </td>
                                                            <td v-if="canSeeProfit"
                                                                class="px-4 py-4 text-right"
                                                            >
                                                                <span
                                                                    class="font-bold text-lg"
                                                                    :class="
                                                                        parseFloat(
                                                                            item.profit.toString()
                                                                        ) >= 0
                                                                            ? 'text-green-600'
                                                                            : 'text-red-600'
                                                                    "
                                                                >
                                                                    ৳{{
                                                                        toBengaliNumber(
                                                                            formatCurrency(
                                                                                item.profit
                                                                            )
                                                                        )
                                                                    }}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div
                                            v-else
                                            class="text-center py-8 text-gray-500"
                                        >
                                            {{ getTranslation("noItemsFound") }}
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Product Summary Table (Product View) -->
        <div v-else-if="viewMode === 'product'" class="print:hidden bg-white rounded-xl shadow-sm overflow-hidden">
            <!-- Header -->
            <div class="px-6 py-4 border-b border-gray-200 flex items-center gap-3">
                <div class="p-2 bg-teal-100 rounded-lg">
                    <svg class="w-5 h-5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <h2 class="text-lg font-semibold text-gray-800">{{ getTranslation("productSummaryTitle") }}</h2>
                <span class="ml-auto px-3 py-1 bg-teal-100 text-teal-700 rounded-full text-sm font-medium">
                    {{ toBengaliNumber(productSummary.length) }} {{ getTranslation("product") }}
                </span>
            </div>

            <!-- Desktop Table -->
            <div class="overflow-x-auto hidden lg:block">
                <table class="w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ getTranslation("product") }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ getTranslation("variant") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ getTranslation("totalCases") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ getTranslation("totalQty") }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ getTranslation("totalRevenue") }}</th>
                            <th v-if="canSeeProfit" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ getTranslation("totalProfit") }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr
                            v-for="(row, idx) in productSummary"
                            :key="`${row.product_id}-${row.variant}`"
                            class="hover:bg-gray-50 transition-colors"
                        >
                            <td class="px-4 py-3 text-sm text-gray-500">{{ toBengaliNumber(idx + 1) }}</td>
                            <td class="px-4 py-3 text-sm font-semibold text-gray-800">{{ row.product_name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <span v-if="row.variant" class="px-2 py-0.5 bg-gray-100 rounded text-xs font-medium">{{ row.variant }}</span>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700 text-right font-medium">{{ toBengaliNumber(row.total_cases) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 text-right font-medium">{{ toBengaliNumber(row.total_qty) }}</td>
                            <td class="px-4 py-3 text-sm text-right font-semibold text-gray-800">
                                ৳{{ toBengaliNumber(formatCurrency(row.total_revenue), 2) }}
                            </td>
                            <td v-if="canSeeProfit"
                                class="px-4 py-3 text-sm text-right font-semibold"
                                :class="row.total_profit >= 0 ? 'text-green-600' : 'text-red-600'"
                            >
                                ৳{{ toBengaliNumber(formatCurrency(row.total_profit), 2) }}
                            </td>
                        </tr>
                        <!-- Totals row -->
                        <tr class="bg-teal-50 font-bold border-t-2 border-teal-200">
                            <td class="px-4 py-3 text-sm text-teal-800" colspan="3">{{ getTranslation("totalSales") }}</td>
                            <td class="px-4 py-3 text-sm text-teal-800 text-right">
                                {{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_cases, 0)) }}
                            </td>
                            <td class="px-4 py-3 text-sm text-teal-800 text-right">
                                {{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_qty, 0)) }}
                            </td>
                            <td class="px-4 py-3 text-sm text-teal-800 text-right">
                                ৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_revenue, 0)), 2) }}
                            </td>
                            <td v-if="canSeeProfit"
                                class="px-4 py-3 text-sm text-right"
                                :class="productSummary.reduce((s, r) => s + r.total_profit, 0) >= 0 ? 'text-green-700' : 'text-red-700'"
                            >
                                ৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_profit, 0)), 2) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cards -->
            <div class="lg:hidden divide-y divide-gray-100">
                <div
                    v-for="(row, idx) in productSummary"
                    :key="`m-${row.product_id}-${row.variant}`"
                    class="p-4 hover:bg-gray-50 transition-colors"
                >
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <span class="text-xs text-gray-400 mr-1">{{ toBengaliNumber(idx + 1) }}.</span>
                            <span class="font-semibold text-gray-800 text-sm">{{ row.product_name }}</span>
                            <span v-if="row.variant" class="ml-2 px-2 py-0.5 bg-gray-100 rounded text-xs text-gray-600">{{ row.variant }}</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm mt-2">
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500">{{ getTranslation("totalCases") }}</span>
                            <span class="font-medium text-gray-800">{{ toBengaliNumber(row.total_cases) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500">{{ getTranslation("totalQty") }}</span>
                            <span class="font-medium text-gray-800">{{ toBengaliNumber(row.total_qty) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500">{{ getTranslation("totalRevenue") }}</span>
                            <span class="font-semibold text-gray-800">৳{{ toBengaliNumber(formatCurrency(row.total_revenue), 2) }}</span>
                        </div>
                        <div v-if="canSeeProfit" class="flex flex-col">
                            <span class="text-xs text-gray-500">{{ getTranslation("totalProfit") }}</span>
                            <span class="font-semibold" :class="row.total_profit >= 0 ? 'text-green-600' : 'text-red-600'">
                                ৳{{ toBengaliNumber(formatCurrency(row.total_profit), 2) }}
                            </span>
                        </div>
                    </div>
                </div>
                <!-- Mobile totals -->
                <div class="p-4 bg-teal-50 border-t-2 border-teal-200">
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div class="flex flex-col">
                            <span class="text-xs text-teal-600 font-medium">{{ getTranslation("totalCases") }}</span>
                            <span class="font-bold text-teal-800">{{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_cases, 0)) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-teal-600 font-medium">{{ getTranslation("totalQty") }}</span>
                            <span class="font-bold text-teal-800">{{ toBengaliNumber(productSummary.reduce((s, r) => s + r.total_qty, 0)) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-teal-600 font-medium">{{ getTranslation("totalRevenue") }}</span>
                            <span class="font-bold text-teal-800">৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_revenue, 0)), 2) }}</span>
                        </div>
                        <div v-if="canSeeProfit" class="flex flex-col">
                            <span class="text-xs text-teal-600 font-medium">{{ getTranslation("totalProfit") }}</span>
                            <span
                                class="font-bold"
                                :class="productSummary.reduce((s, r) => s + r.total_profit, 0) >= 0 ? 'text-green-700' : 'text-red-700'"
                            >
                                ৳{{ toBengaliNumber(formatCurrency(productSummary.reduce((s, r) => s + r.total_profit, 0)), 2) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { router } from "@inertiajs/vue3";
import Layout from "../../Layout.vue";
import DateRangePicker from "../../Components/DateRangePicker.vue";
import FlashToast from "../../Components/FlashToast.vue";

defineOptions({
    layout: Layout,
});

// Types
interface SaleItem {
    product_id: number;
    product_name: string;
    variant: string;
    cases_sold?: number;
    total_bottles_sold?: number;
    purchased_bottles_sold?: number;
    free_bottles_sold?: number;
    extra_bottles?: number;
    quantity?: number;
    total_price: string | number;
    profit?: string | number;
}

interface Sale {
    id: number;
    shop_id: number;
    shop_name: string;
    supplier_id: number | null;
    supplier_name: string;
    invoice_number: string;
    total_amount: string | number;
    paid_amount?: string | number | null;
    due_amount?: string | number | null;
    total_profit?: string | number;
    sale_date: string;
    status: string;
    items: SaleItem[];
}

interface Shop {
    id: number;
    shop_name: string;
}

interface Product {
    id: number;
    name: string;
}

interface Supplier {
    id: number;
    company_name: string;
}

interface Filters {
    start_date: string | undefined;
    end_date: string | undefined;
    shop_id: number | string | undefined;
    product_id: number | string | undefined;
    supplier_id: number | string | undefined;
}

// Props
const props = defineProps<{
    sales: Sale[];
    shops?: Shop[];
    products?: Product[];
    suppliers?: Supplier[];
    filters?: Filters;
    defaultView?: "invoice" | "product" | "summary";
    // False when the user lacks sales.profit: profit and cost are left out of
    // the data and every profit/cost figure on the page is hidden.
    canSeeProfit?: boolean;
}>();

// Reactive data
const currentLanguage = ref(localStorage.getItem("language") || "en");
const searchQuery = ref("");
const expandedSale = ref<number | null>(null);
const isLoading = ref(false);
/** ?tab=draft after saving a draft, so the row just created is on screen. */
const tabFromUrl = (): "completed" | "draft" =>
    new URLSearchParams(window.location.search).get("tab") === "draft" ? "draft" : "completed";

const activeTab = ref<"completed" | "draft">(tabFromUrl());
const viewMode = ref<"invoice" | "product" | "summary">(props.defaultView || "invoice");
const summaryDisplayMode = ref<"invoice" | "product">("invoice");
const printViewMode = computed(() =>
    viewMode.value === "summary" ? summaryDisplayMode.value : viewMode.value
);

// Computed properties
const tabbedSales = computed(() =>
    (props.sales || []).filter((sale) =>
        activeTab.value === "draft" ? sale.status === "draft" : sale.status !== "draft"
    )
);

const totalSales = computed(() => tabbedSales.value.length || 0);

const totalAmount = computed(() => {
    return (
        tabbedSales.value.reduce(
            (sum, sale) => sum + parseFloat(sale.total_amount.toString()),
            0
        ) || 0
    );
});

const totalProfit = computed(() => {
    return (
        tabbedSales.value.reduce(
            (sum, sale) => sum + (parseFloat(String(sale.total_profit ?? 0)) || 0),
            0
        ) || 0
    );
});

const totalItemsSold = computed(() => {
    return (
        tabbedSales.value.reduce((sum, sale) => {
            return (
                sum +
                (sale.items?.reduce((itemSum, item) => {
                    return (
                        itemSum +
                        (item.total_bottles_sold || item.quantity || 0)
                    );
                }, 0) || 0)
            );
        }, 0) || 0
    );
});

const hasAnyCases = computed(() => {
    return (
        tabbedSales.value.some((sale) =>
            sale.items?.some((item) => item.cases_sold && item.cases_sold > 0)
        ) || false
    );
});

const supplierNameOf = (sale: Sale) => (sale.supplier_name || "").trim();

/**
 * Rows of one supplier stay in a single run, whatever their dates, so a report
 * can be read (and handed over) supplier by supplier. Inside a supplier the
 * usual newest-first order is kept.
 */
const bySupplierThenNewest = (a: Sale, b: Sale) => {
    const supplier = supplierNameOf(a).localeCompare(supplierNameOf(b));
    if (supplier !== 0) return supplier;
    if (a.sale_date !== b.sale_date) return a.sale_date < b.sale_date ? 1 : -1;
    return b.id - a.id;
};

/** True when this row opens a new supplier's block, so a heading is drawn. */
const startsSupplierGroup = (index: number) => {
    const list = filteredSales.value;
    if (index === 0) return true;
    return supplierNameOf(list[index]) !== supplierNameOf(list[index - 1]);
};

const filteredSales = computed(() => {
    return tabbedSales.value.filter((sale) => {
        // Date range filter (client-side, instant — no server round-trip needed)
        if (filters.value.start_date || filters.value.end_date) {
            if (!sale.sale_date) return false;
            if (filters.value.start_date && sale.sale_date < filters.value.start_date) return false;
            if (filters.value.end_date && sale.sale_date > filters.value.end_date) return false;
        }

        // Search filter
        if (searchQuery.value) {
            const query = searchQuery.value.toLowerCase();
            return (
                sale.invoice_number.toLowerCase().includes(query) ||
                sale.shop_name.toLowerCase().includes(query) ||
                sale.supplier_name.toLowerCase().includes(query)
            );
        }

        return true;
    }).sort(bySupplierThenNewest);
});

const productSummary = computed(() => {
    const map = new Map<string, {
        product_id: number;
        product_name: string;
        variant: string;
        total_qty: number;
        total_cases: number;
        total_revenue: number;
        total_profit: number;
    }>();

    for (const sale of filteredSales.value) {
        for (const item of sale.items || []) {
            const key = `${item.product_id}-${item.variant}`;
            if (!map.has(key)) {
                map.set(key, {
                    product_id: item.product_id,
                    product_name: item.product_name,
                    variant: item.variant,
                    total_qty: 0,
                    total_cases: 0,
                    total_revenue: 0,
                    total_profit: 0,
                });
            }
            const entry = map.get(key)!;
            entry.total_qty += item.total_bottles_sold || item.quantity || 0;
            entry.total_cases += item.cases_sold || 0;
            entry.total_revenue += parseFloat(item.total_price.toString()) || 0;
            entry.total_profit += parseFloat(String(item.profit ?? 0)) || 0;
        }
    }

    return Array.from(map.values()).sort((a, b) => b.total_revenue - a.total_revenue);
});

// Today's date string helper
const todayDateStr = (() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
})();

// Filters
const filters = ref<Filters>({
    start_date: props.filters?.start_date || todayDateStr,
    end_date: props.filters?.end_date || todayDateStr,
    shop_id: props.filters?.shop_id || "",
    product_id: props.filters?.product_id || "",
    supplier_id: props.filters?.supplier_id || "",
});

// The filter panel eats most of a phone screen before any data shows, so it
// collapses on mobile. From `sm` up it is always open and this is ignored.
const showFilters = ref(false);

const activeFilterCount = computed(
    () =>
        [filters.value.shop_id, filters.value.product_id, filters.value.supplier_id].filter(
            (value) => value !== "" && value !== null && value !== undefined
        ).length
);

// Translations
const translations = {
    en: {
        languageLabel: "English",
        salesReport: "Sales Report",
        invoiceNumber: "Invoice",
        shop: "Shop",
        supplier: "Supplier",
        totalAmount: "Amount",
        totalProfit: "Profit",
        saleDate: "Date",
        status: "Status",
        items: "Items",
        totalItems: "Total Items",
        cases: "Cases",
        extraBottles: "Extra",
        totalSales: "Total Sales",
        total: "Total",
        totalPrice: "Total",
        profit: "Profit",
        product: "Product",
        variant: "Variant",
        quantity: "Qty",
        filters: "Filters",
        startDate: "Start Date",
        endDate: "End Date",
        allShops: "All Shops",
        allProducts: "All Products",
        allSuppliers: "All Suppliers",
        applyFilters: "Apply Filters",
        clearFilters: "Clear Filters",
        delete: "Delete",
        searchSales: "Search with Invoice",
        completedTab: "Completed",
        draftTab: "Drafts",
        continueDraft: "Continue Sale",
        pending: "Pending",
        in_progress: "In Progress",
        completed: "Completed",
        draft: "Draft",
        paymentStatus: "Payment",
        due: "Due",
        totalDue: "Total Due",
        paid: "Paid",
        dueCollection: "Due Collection",
        actions: "Actions",
        show: "Show",
        hide: "Hide",
        view: "Bill",
        saleDetails: "Sale Details",
        orderItems: "Sales Items",
        noSales: "No sales found",
        noSalesDescription: "Try adjusting your filters or search criteria.",
        noItemsFound: "No items found for this sale",
        invoiceView: "Invoice View",
        productView: "Product View",
        invoiceViewShort: "Invoice",
        productViewShort: "Product",
        salesSummary: "Sales Summary",
        productSummaryTitle: "Product Summary",
        totalQty: "Total Qty (Bottles)",
        totalCases: "Total Cases",
        products: "Products",
        totalRevenue: "Revenue",
        totalCost: "Cost",
        noProducts: "No products found",
        printPdf: "Print / PDF",
        salesPerformanceSummary: "Sales Performance Summary",
        reportPeriod: "Report Period",
        generatedOn: "Generated On",
        viewModeLabel: "View",
    },
    bn: {
        languageLabel: "বাংলা",
        salesReport: "বিক্রয় রিপোর্ট",
        invoiceNumber: "চালান",
        shop: "দোকান",
        supplier: "সরবরাহকারী",
        totalAmount: "পরিমাণ",
        totalProfit: "লাভ",
        saleDate: "তারিখ",
        status: "অবস্থা",
        items: "আইটেম",
        totalItems: "মোট আইটেম",
        cases: "কেস",
        extraBottles: "অতিরিক্ত",
        totalSales: "মোট বিক্রয়",
        total: "মোট",
        totalPrice: "মোট",
        profit: "লাভ",
        product: "পণ্য",
        variant: "ভেরিয়েন্ট",
        quantity: "পরিমাণ",
        filters: "ফিল্টার",
        startDate: "শুরুর তারিখ",
        endDate: "শেষের তারিখ",
        allShops: "সকল দোকান",
        allProducts: "সকল পণ্য",
        allSuppliers: "সকল সরবরাহকারী",
        applyFilters: "ফিল্টার প্রয়োগ করুন",
        clearFilters: "ফিল্টার পরিষ্কার করুন",
        delete: "মুছুন",
        searchSales: "ইনভয়েস দিয়ে খুঁজুন",
        completedTab: "সম্পন্ন",
        draftTab: "ড্রাফট",
        continueDraft: "ড্রাফট চালিয়ে যান",
        pending: "অপেক্ষায়",
        in_progress: "চলমান",
        completed: "সম্পন্ন",
        draft: "ড্রাফট",
        paymentStatus: "পেমেন্ট",
        due: "বকেয়া",
        totalDue: "মোট বকেয়া",
        paid: "পরিশোধিত",
        dueCollection: "বকেয়া আদায়",
        actions: "কর্ম",
        show: "দেখান",
        hide: "লুকান",
        view: "বিল",
        saleDetails: "বিক্রয় বিবরণ",
        orderItems: "বিক্রয় আইটেম",
        noSales: "কোন বিক্রয় পাওয়া যায়নি",
        noSalesDescription:
            "ফিল্টার বা অনুসন্ধানের মানদণ্ড সামঞ্জস্য করার চেষ্টা করুন।",
        noItemsFound: "এই বিক্রয়ের জন্য কোন আইটেম পাওয়া যায়নি",
        invoiceView: "ইনভয়েস ভিউ",
        productView: "পণ্য ভিউ",
        invoiceViewShort: "ইনভয়েস",
        productViewShort: "পণ্য",
        salesSummary: "সেলস সামারি",
        productSummaryTitle: "পণ্য সারসংক্ষেপ",
        totalQty: "মোট পরিমাণ (বোতল)",
        totalCases: "মোট কেস",
        products: "পণ্য",
        totalRevenue: "রাজস্ব",
        noProducts: "কোন পণ্য পাওয়া যায়নি",
        totalCost: "খরচ",
        printPdf: "প্রিন্ট / পিডিএফ",
        salesPerformanceSummary: "বিক্রয় সারসংক্ষেপ",
        reportPeriod: "রিপোর্ট সময়কাল",
        generatedOn: "তৈরির সময়",
        viewModeLabel: "ভিউ",
    },
} as const;

type SalesTranslationKey  = keyof typeof translations.en;
type SalesTranslationLang = keyof typeof translations;

// Methods
function getTranslation(key: string): string {
    return translations[currentLanguage.value as SalesTranslationLang]?.[key as SalesTranslationKey] ?? key;
}


function toBengaliNumber(numValue: number | string, decimals: number | null = null): string {
    if (numValue === null || numValue === undefined || numValue === "") return "";
    
    let n = Number(numValue);
    if (isNaN(n)) return String(numValue);

    // Group thousands so 3000 reads as 3,000 wherever an amount is shown.
    let output: string;
    if (decimals !== null) {
        output = n.toLocaleString("en-US", { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    } else {
        output = n % 1 !== 0
            ? n.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : n.toLocaleString("en-US");
    }

    if (currentLanguage.value !== 'bn') return output;

    const bengaliDigits = ["০", "১", "২", "৩", "৪", "৫", "৬", "৭", "৮", "৯"];
    return output.replace(/[0-9]/g, (d) => bengaliDigits[parseInt(d)]);
}

function formatCurrency(amount: string | number): string {
    const num = parseFloat(amount.toString());
    return isNaN(num) ? "0.00" : num.toFixed(2);
}

function formatDate(dateString: string): string {
    if (!dateString) return "";

    try {
        const date = new Date(dateString);
        if (currentLanguage.value === "bn") {
            return toBengaliNumber(date.toLocaleDateString("en-CA")); // YYYY-MM-DD format
        }
        return date.toLocaleDateString("en-CA"); // YYYY-MM-DD format
    } catch (error) {
        return dateString;
    }
}


function getSaleProductNames(sale: Sale): string {
    if (!sale.items?.length) return "—";
    const names = [...new Set(sale.items.map(i => i.product_name).filter(Boolean))];
    return names.join(", ");
}

function getSaleTotalCases(sale: Sale): number {
    return sale.items?.reduce((s, i) => s + (i.cases_sold || 0), 0) ?? 0;
}

function getSaleCost(sale: Sale): number {
    const revenue = parseFloat(String(sale.total_amount)) || 0;
    const profit = parseFloat(String(sale.total_profit)) || 0;
    return revenue - profit;
}

function toggleItems(saleId: number): void {
    expandedSale.value = expandedSale.value === saleId ? null : saleId;
}

function applyFilters(): void {
    isLoading.value = true;
    router.get(props.defaultView === "summary" ? "/sales/summary" : "/sales/report", filters.value, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            isLoading.value = false;
        },
    });
}

function clearFilters(): void {
    filters.value = {
        start_date: "",
        end_date: "",
        shop_id: "",
        product_id: "",
        supplier_id: "",
    };
    applyFilters();
}

function viewCashMemo(saleId: number): void {
    router.visit(`/sales/cash-memo/${saleId}`);
}

// Straight to the existing payment screen, which opens with the outstanding
// balance already filled in.
function collectDue(saleId: number): void {
    router.visit(`/sales/payment/${saleId}`);
}

function continueDraft(saleId: number): void {
    router.visit(`/sales?draft=${saleId}`);
}

function deleteSale(saleId: number): void {
    if (!confirm('Are you sure you want to delete this sale? This cannot be undone.')) return;
    router.delete(route('sales.destroy', { id: saleId }), {
        preserveScroll: true,
    });
}

function printReport(): void {
    window.print();
}

type PaymentState = "paid" | "due";

/**
 * What is still owed on a sale. If the server ever omits due_amount, fall back to
 * total - paid rather than treating the missing field as zero - reading "nothing
 * owed" off absent data hides real money and is the one wrong answer that looks
 * fine on screen.
 */
const saleDue = (sale: Sale): number => {
    if (sale.due_amount !== undefined && sale.due_amount !== null) {
        return parseFloat(String(sale.due_amount)) || 0;
    }
    const total = parseFloat(String(sale.total_amount ?? 0)) || 0;
    const paid = parseFloat(String(sale.paid_amount ?? 0)) || 0;
    return Math.max(0, total - paid);
};

/**
 * Either the sale is settled or money is still owed on it. A draft has not been
 * billed yet, so it has no payment state at all - showing one would put every
 * draft on the money-owed list.
 */
const paymentStateOf = (sale: Sale): PaymentState | null => {
    if (sale.status === "draft") return null;
    return saleDue(sale) <= 0 ? "paid" : "due";
};

const paymentStateClass = (state: PaymentState | null): string =>
    state === "paid" ? "bg-green-100 text-green-800" : "bg-red-100 text-red-800";

const printTotals = computed(() => {
    const revenue = filteredSales.value.reduce(
        (sum, sale) => sum + (parseFloat(String(sale.total_amount)) || 0),
        0
    );
    const profit = filteredSales.value.reduce(
        (sum, sale) => sum + (parseFloat(String(sale.total_profit)) || 0),
        0
    );
    const items = filteredSales.value.reduce(
        (sum, sale) =>
            sum +
            (sale.items?.reduce(
                (itemSum, item) =>
                    itemSum + (item.total_bottles_sold || item.quantity || 0),
                0
            ) || 0),
        0
    );
    const cases = filteredSales.value.reduce(
        (sum, sale) =>
            sum + (sale.items?.reduce((s, item) => s + (item.cases_sold || 0), 0) || 0),
        0
    );

    const due = filteredSales.value.reduce((sum, sale) => sum + saleDue(sale), 0);

    return {
        revenue,
        profit,
        items,
        cases,
        due,
        cost: revenue - profit,
    };
});

const printedAtLabel = computed(() => {
    const now = new Date();
    return now.toLocaleString(currentLanguage.value === "bn" ? "bn-BD" : "en-GB");
});

// Lifecycle
onMounted(() => {
    // Set initial language
    document.documentElement.lang = currentLanguage.value;
});
</script>

<style scoped>
@import url("https://fonts.maateen.me/kalpurush/font.css");

.bangla-font {
    font-family: "Kalpurush", "Noto Sans Bengali", sans-serif;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
    }
    to {
        opacity: 1;
        max-height: 1000px;
    }
}

.animate-fade-in {
    animation: fadeIn 1s ease-out;
}

.animate-slide-down {
    animation: slideDown 0.3s ease-out;
    overflow: hidden;
}

.rotate-90 {
    transform: rotate(90deg);
}

/* Custom responsive utilities */
.truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.max-w-32 {
    max-width: 8rem;
}

/* Mobile optimizations */
@media (max-width: 640px) {
    .table-responsive {
        font-size: 0.75rem;
    }

    th,
    td {
        padding: 0.5rem;
    }
}

/* Ensure table doesn't overflow */
.overflow-x-auto {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Loading animation */
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

/* Improved hover effects */
.hover\:shadow-md:hover {
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1),
        0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

/* Better focus states */
.focus\:ring-4:focus {
    --tw-ring-offset-shadow: var(--tw-ring-inset) 0 0 0
        var(--tw-ring-offset-width) var(--tw-ring-offset-color);
    --tw-ring-shadow: var(--tw-ring-inset) 0 0 0
        calc(4px + var(--tw-ring-offset-width)) var(--tw-ring-color);
    box-shadow: var(--tw-ring-offset-shadow), var(--tw-ring-shadow),
        var(--tw-shadow, 0 0 #0000);
}

.focus\:ring-indigo-100:focus {
    --tw-ring-color: rgb(224 231 255);
}

.print-only {
    display: none;
}

.sales-summary-sheet {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 24px;
}

.sales-summary-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
}

.sales-summary-stat {
    padding-bottom: 12px;
    border-bottom: 1px solid #e2e8f0;
}

.sales-summary-stat span {
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
}

.sales-summary-stat strong {
    display: block;
    margin-top: 8px;
    font-size: 1.25rem;
    line-height: 1.2;
    color: #0f172a;
}

@media print {
    @page {
        size: A4 portrait;
        margin: 12mm;
    }

    .print\:hidden {
        display: none !important;
    }

    .print-only {
        display: block !important;
    }

    .sales-print-sheet {
        color: #0f172a;
    }

    .sales-print-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 24px;
        border-bottom: 2px solid #cbd5e1;
        padding-bottom: 14px;
        margin-bottom: 16px;
    }

    .sales-print-kicker {
        margin: 0 0 4px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #475569;
    }

    .sales-print-title {
        margin: 0;
        font-size: 24px;
        font-weight: 800;
        color: #0f172a;
    }

    .sales-print-subtitle,
    .sales-print-meta p {
        margin: 4px 0 0;
        font-size: 11px;
        color: #475569;
    }

    .sales-print-meta {
        text-align: right;
    }

    .sales-print-summary {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 16px;
    }

    .sales-print-card {
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 10px 12px;
        background: #f8fafc;
    }

    .sales-print-card span {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #64748b;
        margin-bottom: 6px;
    }

    .sales-print-card strong {
        display: block;
        font-size: 16px;
        line-height: 1.2;
        color: #0f172a;
    }

    .sales-print-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10.5px;
    }

    .sales-print-table th,
    .sales-print-table td {
        border: 1px solid #cbd5e1;
        padding: 6px 8px;
        text-align: left;
        vertical-align: top;
    }

    .sales-print-table thead th {
        background: #e2e8f0;
        font-weight: 700;
    }

    .sales-print-table tfoot td {
        background: #f8fafc;
        font-weight: 700;
    }

    .min-h-screen,
    .bg-gradient-to-br {
        background: white !important;
        min-height: auto !important;
    }
}

@media (max-width: 1024px) {
    .sales-summary-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .sales-summary-sheet {
        padding: 12px;
        border-radius: 12px;
    }

    /* Three across instead of stacked: five stacked stats cost ~320px of
       scrolling before a single row of data appears. */
    .sales-summary-stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
    }

    .sales-summary-stat {
        padding-bottom: 8px;
    }

    .sales-summary-stat span {
        font-size: 9px;
        letter-spacing: 0.04em;
    }

    .sales-summary-stat strong {
        margin-top: 2px;
        font-size: 0.8125rem;
        word-break: break-all;
    }
}
</style>
