<?php
$is_shop_manager = is_shop_manager();
    $sidebar_shop_access = ($is_shop_manager || can("manage_shops"))
        ? accessible_shops(null, !$is_shop_manager)
        : [];
    $sidebar_store_access = ($is_shop_manager || can("manage_shops") || !tenant_admin_scope())
        ? accessible_stores(null, !$is_shop_manager)
        : [];
    $sidebar_shop = $sidebar_shop_access ? $sidebar_shop_access[0] : null;
    $sidebar_store = $sidebar_store_access ? $sidebar_store_access[0] : null;
    $sidebar_notifications = sidebar_notification_counts();
    ?>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <?php $sidebar_logo = system_logo_path(); ?>
            <?php if ($sidebar_logo !== ''): ?>
                <img src="<?php echo e($sidebar_logo); ?>" alt="" class="sidebar-logo">
            <?php endif; ?>
            <div class="brand-text"><?php echo e(system_title()); ?><small>Business Suite</small></div>
        </div>

        <?php if (is_godown_manager()): ?>
        <div class="sidebar-menu-title">Godown</div>
        <ul class="sidebar-menu">
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) === "godown.php" ? "active" : ""; ?>">
                <a href="godown.php"><i class="fa fa-archive"></i><span>My Godown</span></a>
            </li>
            <?php if (can_manage_product_catalogue()): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["products.php", "product-add.php", "product-edit.php", "product-view.php", "product-barcode.php"]) ? "active" : ""; ?>">
                <a href="products.php"><i class="fa fa-cube"></i><span>Products</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_inventory")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) === "inventory.php" ? "active" : ""; ?>">
                <a href="inventory.php"><i class="fa fa-cubes"></i><span>Inventory</span><?php echo sidebar_count_badge($sidebar_notifications['low_stock'], 'low-stock products', 'badge-warning'); ?></a>
            </li>
            <?php endif; ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["godown-transfers.php", "godown-transfer-add.php", "godown-transfer-view.php"]) ? "active" : ""; ?>">
                <a href="godown-transfers.php"><i class="fa fa-exchange"></i><span>Stock Transfers</span><?php echo sidebar_count_badge($sidebar_notifications['transfers'], 'active stock transfers', 'badge-info'); ?></a>
            </li>
            <?php if (can("manage_expenses")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) === "expenses.php" ? "active" : ""; ?>">
                <a href="expenses.php"><i class="fa fa-money"></i><span>Expenses</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_purchases")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["purchases.php", "purchase-add.php", "purchase-edit.php", "purchase-view.php", "purchase-delete.php"]) ? "active" : ""; ?>">
                <a href="purchases.php"><i class="fa fa-shopping-cart"></i><span>Purchases</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_suppliers")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) === "suppliers.php" ? "active" : ""; ?>">
                <a href="suppliers.php"><i class="fa fa-truck"></i><span>Suppliers</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_invoices")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["invoices.php", "invoice-add.php", "invoice-details.php", "invoice-edit.php", "invoice-cancel.php", "invoice-print.php"]) ? "active" : ""; ?>">
                <a href="invoices.php"><i class="fa fa-file-text"></i><span>Invoices</span><?php echo sidebar_count_badge($sidebar_notifications['invoices'], 'unpaid invoices', 'badge-warning'); ?></a>
            </li>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["quotations.php", "quotation-add.php", "quotation-details.php", "quotation-print.php", "quotation-download.php"]) ? "active" : ""; ?>">
                <a href="quotations.php"><i class="fa fa-file-signature"></i><span>Quotations</span></a>
            </li>
            <?php endif; ?>
        </ul>
        <?php else: ?>
        <?php if (tenant_admin_scope() && can("view_dashboard")): ?>
        <div class="sidebar-menu-title">Overview</div>
        <ul class="sidebar-menu">
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "dashboard.php" ? "active" : ""; ?>">
                <a href="dashboard.php"><i class="fa fa-tachometer"></i><span>Dashboard</span></a>
            </li>
        </ul>
        <?php endif; ?>

        <div class="sidebar-menu-title">Sales &amp; Commerce</div>
        <ul class="sidebar-menu">
            <?php if (can("manage_orders")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["orders.php", "order-add.php", "order-edit.php", "order-view.php"]) ? "active" : ""; ?>">
                <a href="orders.php"><i class="fa fa-list-alt"></i><span>Orders</span><?php echo sidebar_count_badge($sidebar_notifications['orders'], 'orders awaiting review'); ?></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_pos")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["sales.php", "sale-add.php", "sale-details.php", "sale-edit.php"]) ? "active" : ""; ?>">
                <a href="sales.php"><i class="fa fa-shopping-cart"></i><span>Sales</span></a>
            </li>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == 'sales-returns.php' ? 'active' : ''; ?>">
                <a href="sales-return-add.php"><i class="fa fa-undo"></i><span>Sales Returns</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_invoices")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["invoices.php", "invoice-add.php", "invoice-details.php", "invoice-edit.php", "invoice-print.php", "quotations.php", "quotation-add.php", "quotation-details.php", "quotation-print.php", "quotation-download.php"]) ? "active" : ""; ?>">
                <a href="invoices.php"><i class="fa fa-file-text"></i><span>Invoices &amp; Quotations</span><?php echo sidebar_count_badge($sidebar_notifications['invoices'], 'unpaid invoices', 'badge-warning'); ?></a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-menu-title">Inventory &amp; Supply Chain</div>
        <ul class="sidebar-menu">
            <?php if (can_manage_product_catalogue()): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["products.php", "product-add.php", "product-edit.php", "product-view.php", "product-barcode.php"]) ? "active" : ""; ?>">
                <a href="products.php"><i class="fa fa-cube"></i><span>Products</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_inventory")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "inventory.php" ? "active" : ""; ?>">
                <a href="inventory.php"><i class="fa fa-cubes"></i><span>Inventory</span><?php echo sidebar_count_badge($sidebar_notifications['low_stock'], 'low-stock products', 'badge-warning'); ?></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_godown") || can("view_godown") || can("approve_godown_transfers") || can("dispatch_godown_transfers") || can("receive_godown_transfers") || can("request_godown_transfers") || can("manage_inventory") || can("manage_shops")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["godown.php", "godown-transfers.php", "godown-transfer-add.php", "godown-transfer-view.php", "inventory-adjust.php"]) ? "active" : ""; ?>">
                <a href="<?php echo can("manage_godown") ? "godown.php" : "godown-transfers.php"; ?>"><i class="fa fa-archive"></i><span><?php echo can("manage_godown") ? "Central Godown" : "Stock Transfers"; ?></span><?php echo sidebar_count_badge($sidebar_notifications['transfers'], 'active stock transfers', 'badge-info'); ?></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_shops") || $is_shop_manager || $sidebar_store): ?>
            <?php if (can("manage_shops") || $is_shop_manager): ?>
            <?php if ($sidebar_shop): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "shop-view.php" ? "active" : ""; ?>">
                <a href="shop-view.php?id=<?php echo (int)$sidebar_shop["shop_id"]; ?>"><i class="fa fa-building-o"></i><span>Shop</span></a>
            </li>
            <?php else: ?>
            <li class="disabled"><a href="shops.php"><i class="fa fa-building-o"></i><span>Shop (none assigned)</span></a></li>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($sidebar_store && !$is_shop_manager): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "store-view.php" ? "active" : ""; ?>">
                <a href="store-view.php?id=<?php echo (int)$sidebar_store["warehouse_id"]; ?>"><i class="fa fa-cube"></i><span>Store</span></a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (can("manage_purchases")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "purchases.php" ? "active" : ""; ?>">
                <a href="purchases.php"><i class="fa fa-shopping-cart"></i><span>Purchases</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_suppliers")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "suppliers.php" ? "active" : ""; ?>">
                <a href="suppliers.php"><i class="fa fa-truck"></i><span>Suppliers</span></a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-menu-title">Customers &amp; CRM</div>
        <ul class="sidebar-menu">
            <?php if (can("manage_contacts")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["customers.php", "customer-add.php", "customer-edit.php", "customer-view.php", "wholesaler-view.php"]) ? "active" : ""; ?>">
                <a href="customers.php"><i class="fa fa-address-book"></i><span>Customers</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_leads")): ?>
            <?php
            $new_storefront_requests = fetch_one(
                "SELECT COUNT(*) AS total
                   FROM leads l
                   JOIN storefront_lead_requests r ON r.lead_id = l.lead_id AND r.tenant_id = l.tenant_id
                  WHERE l.tenant_id = ?
                    AND l.status NOT IN (\"converted\", \"lost\", \"cancelled\")
                    AND r.converted_order_id IS NULL",
                (int)$current_user->tenant_id
            );
            $new_storefront_request_count = (int)($new_storefront_requests["total"] ?? 0);
            ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["leads.php", "lead-add.php", "lead-edit.php", "lead-view.php"]) ? "active" : ""; ?>">
                <a href="leads.php"><i class="fa fa-bullseye"></i><span>Leads</span>
                    <?php if ($new_storefront_request_count > 0): ?>
                        <span class="badge badge-danger ml-auto" aria-label="<?php echo $new_storefront_request_count; ?> pending order requests"><?php echo $new_storefront_request_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-menu-title">Finance &amp; Reports</div>
        <ul class="sidebar-menu">
            <?php if (can("manage_expenses") || can("manage_settings")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "expenses.php" ? "active" : ""; ?>">
                <a href="expenses.php"><i class="fa fa-money"></i><span>Expenses</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_receipts")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "receipts.php" ? "active" : ""; ?>">
                <a href="receipts.php"><i class="fa fa-credit-card"></i><span>Payments</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("view_reports")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "reports.php" ? "active" : ""; ?>">
                <a href="reports.php"><i class="fa fa-bar-chart"></i><span>Reports</span></a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-menu-title">Administration &amp; System</div>
        <ul class="sidebar-menu">
            <?php if (tenant_admin_scope() && can("manage_shops")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["shops.php", "stores.php"]) ? "active" : ""; ?>">
                <a href="shops.php"><i class="fa fa-building"></i><span>Shops &amp; Stores</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_users")): ?>
            <li class="<?php echo in_array(basename($_SERVER["PHP_SELF"]), ["users.php", "user-add.php", "user-edit.php", "user-view.php", "permissions.php"]) ? "active" : ""; ?>">
                <a href="users.php"><i class="fa fa-users"></i><span>Users</span></a>
            </li>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "permissions.php" ? "active" : ""; ?>">
                <a href="permissions.php"><i class="fa fa-key"></i><span>Roles &amp; Permissions</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("view_activity_log") || can("view_reports")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "audit-log.php" ? "active" : ""; ?>">
                <a href="audit-log.php"><i class="fa fa-history"></i><span>Activity Log</span></a>
            </li>
            <?php endif; ?>
            <?php if (can("manage_settings")): ?>
            <li class="<?php echo basename($_SERVER["PHP_SELF"]) == "settings.php" ? "active" : ""; ?>">
                <a href="settings.php"><i class="fa fa-cog"></i><span>Settings</span></a>
            </li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>

        <div class="sidebar-footer no-print"><?php echo e(system_title()); ?> v3.0 &middot; &copy; <?php echo date("Y"); ?></div>
    </aside>
