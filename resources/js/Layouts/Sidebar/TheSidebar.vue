<template>
    <aside
        id="logo-sidebar"
        class="fixed left-0 top-14 z-40 flex h-[calc(100vh-3.5rem)] w-72 bg-[#3F0E40] transition-[transform,width] duration-200"
        :class="sidebarClasses"
        aria-label="Sidebar"
    >
        <!-- ── Rail: the handful of places used every day ─────────────── -->
        <!-- Exactly the top bar's purple, so the two read as one L-shaped
             surface with no seam where the bar ends. -->
        <div class="flex w-16 flex-shrink-0 flex-col items-center bg-[#3F0E40] py-3">
            <Link
                v-for="item in railItems"
                :key="item.href"
                :href="item.href"
                class="group mb-0.5 flex w-14 flex-col items-center gap-1 rounded-lg py-2 transition-colors"
                :class="isActive(item.href)
                    ? 'bg-white/15 text-white'
                    : 'text-white/60 hover:bg-white/10 hover:text-white'"
                @click="$emit('close')"
            >
                <font-awesome-icon :icon="['fas', item.icon]" class="h-[15px] w-[15px]" />
                <span class="text-[10px] font-medium leading-none">{{ item.label }}</span>
            </Link>

            <div class="mt-auto">
                <div
                    class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/15 text-sm font-bold text-white ring-1 ring-inset ring-white/20"
                    :title="userName"
                >
                    {{ userInitials }}
                </div>
            </div>
        </div>

        <!-- ── Menu ───────────────────────────────────────────────────── -->
        <div
            class="min-w-0 flex-1 flex-col overflow-hidden rounded-tl-[1.25rem] bg-[#4E2350]"
            :class="collapsed ? 'flex sm:hidden' : 'flex'"
        >
            <!-- Jump straight to a page instead of hunting through sections -->
            <div class="flex flex-shrink-0 items-center gap-2 px-3 pb-2 pt-3">
                <div class="relative min-w-0 flex-1">
                    <svg
                        class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-white/50"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                    </svg>
                    <input
                        v-model="query"
                        type="text"
                        placeholder="Find a page..."
                        class="w-full rounded-md border border-white/20 bg-white/5 py-1.5 pl-8 pr-2 text-[13px] text-white placeholder-white/50 transition-colors focus:border-white/40 focus:bg-white/10 focus:outline-none"
                    />
                </div>
                <button
                    @click="$emit('close')"
                    class="inline-flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-md text-white/70 transition-colors hover:bg-white/10 hover:text-white sm:hidden"
                    aria-label="Close sidebar"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="sidebar-scroll flex-1 overflow-y-auto px-2 pb-4">
                <!-- Search results: one flat list, no sections to open -->
                <template v-if="query.trim()">
                    <p v-if="!searchResults.length" class="px-2 py-3 text-[13px] text-white/50">
                        Nothing matches "{{ query }}"
                    </p>
                    <Link
                        v-for="item in searchResults"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-2.5 rounded-md px-2 py-[7px] text-[13px] transition-colors"
                        :class="itemClasses(item.href)"
                        @click="onNavigate"
                    >
                        <font-awesome-icon :icon="['fas', iconName(item.icon)]" class="h-3 w-3 flex-shrink-0 opacity-70" />
                        <span class="truncate">{{ item.label }}</span>
                        <span class="ml-auto truncate text-[11px] text-white/40">{{ item.section }}</span>
                    </Link>
                </template>

                <template v-else>
                    <template v-for="section in sections" :key="section.label">
                        <!-- A section that is just one destination -->
                        <Link
                            v-if="section.href"
                            :href="section.href"
                            class="mb-0.5 flex items-center gap-2.5 rounded-md px-2 py-[7px] text-[13px] transition-colors"
                            :class="itemClasses(section.href)"
                            @click="onNavigate"
                        >
                            <font-awesome-icon :icon="['fas', section.icon]" class="h-3.5 w-3.5 flex-shrink-0 opacity-80" />
                            <span class="truncate font-medium">{{ section.label }}</span>
                        </Link>

                        <div v-else class="mb-0.5">
                            <button
                                type="button"
                                class="flex w-full items-center gap-1.5 rounded-md px-2 py-[7px] text-[13px] font-medium text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                                @click="toggle(section.label)"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'chevron-down']"
                                    class="h-2.5 w-2.5 flex-shrink-0 transition-transform duration-200"
                                    :class="isOpenSection(section.label) ? '' : '-rotate-90'"
                                />
                                <span class="truncate">{{ section.label }}</span>
                                <span
                                    v-if="!isOpenSection(section.label) && sectionHasActive(section)"
                                    class="ml-auto h-1.5 w-1.5 flex-shrink-0 rounded-full bg-sky-400"
                                    title="Current page is in here"
                                ></span>
                            </button>

                            <div v-show="isOpenSection(section.label)" class="mt-0.5 space-y-px">
                                <Link
                                    v-for="item in section.items"
                                    :key="item.href"
                                    :href="item.href"
                                    class="flex items-center gap-2.5 rounded-md py-[7px] pl-7 pr-2 text-[13px] transition-colors"
                                    :class="itemClasses(item.href)"
                                    @click="onNavigate"
                                >
                                    <font-awesome-icon :icon="['fas', iconName(item.icon)]" class="h-3 w-3 flex-shrink-0 opacity-70" />
                                    <span class="truncate">{{ item.label }}</span>
                                </Link>
                            </div>
                        </div>
                    </template>
                </template>
            </nav>

            <div class="flex-shrink-0 border-t border-white/10 px-3 py-2.5">
                <p class="truncate text-[13px] font-medium text-white">{{ userName }}</p>
                <p class="truncate text-[11px] text-white/50">{{ userEmail }}</p>
            </div>
        </div>
    </aside>
</template>

<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import { computed, reactive, ref, watch } from "vue";

const props = defineProps({
    isOpen: { type: Boolean, default: false },
    collapsed: { type: Boolean, default: false },
});

const emit = defineEmits(["close"]);

const page = usePage();
const permissions = computed(() => page.props.userPermissions || []);
const hasPermission = (permission) => permissions.value.includes(permission);

const sidebarClasses = computed(() => ({
    "sm:translate-x-0": true,
    "translate-x-0": props.isOpen,
    "-translate-x-full": !props.isOpen,
    // Folded down to just the rail on desktop; full width on a phone.
    "sm:w-16": props.collapsed,
}));

// ── Current page ────────────────────────────────────────────────────────────

// Inertia's url carries the query string, so "/sales?draft=5" would never match
// the "/sales" menu entry without stripping it.
const currentPath = computed(() => String(page.url || "").split("?")[0]);
const isActive = (href) => currentPath.value === href;

const itemClasses = (href) =>
    isActive(href)
        ? "bg-[#1164A3] font-medium text-white"
        : "text-white/70 hover:bg-white/10 hover:text-white";

// FontAwesome names arrive as "fa-solid fa-list"; the component wants "list".
const iconName = (iconClass) => String(iconClass || "").split(" ").pop().replace("fa-", "");

// ── User ────────────────────────────────────────────────────────────────────

const userName = computed(() => page.props.auth?.user?.name || "User");
const userEmail = computed(() => page.props.auth?.user?.email || "");
const userInitials = computed(() =>
    userName.value
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join("")
);

// ── Menu ────────────────────────────────────────────────────────────────────

const visible = (items) => items.filter((item) => item.visible);

const sections = computed(() =>
    [
        {
            label: "Dashboard",
            icon: "house",
            href: "/dashboard",
            standalone: hasPermission("dashboard.view"),
            items: [],
        },
        {
            label: "Suppliers",
            items: visible([
                { label: "Add Supplier", href: "/suppliers/create", icon: "fa-solid fa-plus", visible: hasPermission("supplier.add") },
                { label: "Supplier List", href: "/suppliers/index", icon: "fa-solid fa-list", visible: hasPermission("supplier.view") },
            ]),
        },
        {
            label: "Lift",
            items: visible([
                { label: "Manage Deposits", href: "/deposits", icon: "fa-solid fa-money-check", visible: hasPermission("deposit.view") },
                { label: "Manage Category", href: "/categories/index", icon: "fa-solid fa-folder", visible: hasPermission("category.view") },
                { label: "Manage Brand", href: "/brands/index", icon: "fa-solid fa-tag", visible: hasPermission("brand.view") },
                { label: "Manage Lift", href: "/lifts", icon: "fa-solid fa-shopping-cart", visible: hasPermission("lift.add") },
                { label: "Lifting Report", href: "/lifts/report", icon: "fa-solid fa-file-alt", visible: hasPermission("lift.view") },
            ]),
        },
        {
            label: "Sales",
            items: visible([
                { label: "Create Shop", href: "/shops/create", icon: "fa-solid fa-plus", visible: hasPermission("shop.add") },
                { label: "Shop List", href: "/shops", icon: "fa-solid fa-list", visible: hasPermission("shop.view") },
                { label: "Create Sale", href: "/sales", icon: "fa-solid fa-plus-circle", visible: hasPermission("sales.add") },
                { label: "Sales Report", href: "/sales/report", icon: "fa-solid fa-file-alt", visible: hasPermission("sales.view") },
                { label: "Sales Summary", href: "/sales/summary", icon: "fa-solid fa-chart-line", visible: hasPermission("sales.view") },
            ]),
        },
        {
            label: "Expense",
            items: visible([
                { label: "Expense Management", href: "/expenses", icon: "fa-solid fa-clipboard-list", visible: hasPermission("expense.view") },
            ]),
        },
        {
            label: "Inventory",
            items: visible([
                { label: "Product List", href: "/products", icon: "fa-solid fa-list", visible: hasPermission("inventory.view") },
                { label: "Inventory Report", href: "/inventory/report", icon: "fa-solid fa-file-alt", visible: hasPermission("inventory.view") },
            ]),
        },
        {
            label: "Reports",
            items: visible([
                { label: "Profit & Loss", href: "/profit-loss", icon: "fa-solid fa-chart-line", visible: hasPermission("report.profit-loss") },
                { label: "Expense Report", href: "/expenses/report", icon: "fa-solid fa-receipt", visible: hasPermission("expense.view") },
            ]),
        },
        {
            label: "ACL",
            items: visible([
                { label: "Role Management", href: "/roles", icon: "fa-solid fa-user-shield", visible: hasPermission("role.view") },
                { label: "User Management", href: "/users", icon: "fa-solid fa-users-gear", visible: hasPermission("user.view") },
            ]),
        },
    ].filter((section) => (section.href ? section.standalone : section.items.length))
);

const railItems = computed(() =>
    [
        { label: "Home", href: "/dashboard", icon: "house", visible: hasPermission("dashboard.view") },
        { label: "Sell", href: "/sales", icon: "shopping-bag", visible: hasPermission("sales.add") },
        { label: "Lift", href: "/lifts", icon: "shopping-cart", visible: hasPermission("lift.add") },
        { label: "Stock", href: "/products", icon: "box", visible: hasPermission("inventory.view") },
        { label: "Report", href: "/sales/report", icon: "chart-pie", visible: hasPermission("sales.view") },
    ].filter((item) => item.visible)
);

// ── Open / closed sections ──────────────────────────────────────────────────

const openSections = reactive({});

const sectionHasActive = (section) => section.items.some((item) => isActive(item.href));

const isOpenSection = (label) => openSections[label] === true;

const toggle = (label) => {
    openSections[label] = !openSections[label];
};

// Keep the section holding the current page open, so a reload never lands on a
// sidebar where nothing is expanded.
watch(
    [currentPath, sections],
    () => {
        const active = sections.value.find((section) => !section.href && sectionHasActive(section));
        if (active) openSections[active.label] = true;
    },
    { immediate: true }
);

// ── Search ──────────────────────────────────────────────────────────────────

const query = ref("");

const searchResults = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return [];

    return sections.value.flatMap((section) =>
        section.href
            ? (section.label.toLowerCase().includes(needle)
                ? [{ label: section.label, href: section.href, icon: section.icon, section: "" }]
                : [])
            : section.items
                .filter((item) => item.label.toLowerCase().includes(needle))
                .map((item) => ({ ...item, section: section.label }))
    );
});

const onNavigate = () => {
    query.value = "";
    emit("close");
};
</script>

<style scoped>
aside {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.sidebar-scroll::-webkit-scrollbar {
    width: 6px;
}
.sidebar-scroll::-webkit-scrollbar-track {
    background: transparent;
}
.sidebar-scroll::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.18);
    border-radius: 4px;
}
.sidebar-scroll::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.3);
}
</style>
