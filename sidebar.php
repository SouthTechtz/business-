    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-text"><?php echo e(APP_NAME); ?><small>Business Suite</small></div>
        </div>
        <div class="sidebar-menu-title">Main</div>
        <ul class="sidebar-menu">
            <?php if (can('view_dashboard')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <a href="dashboard.php"><i class="fa fa-tachometer"></i><span>Dashboard</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_users')): ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['users.php', 'user-add.php', 'user-edit.php', 'user-view.php', 'permissions.php']) ? 'active' : ''; ?>">
                <a href="users.php"><i class="fa fa-users"></i><span>Users</span></a>
            </li>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'permissions.php' ? 'active' : ''; ?>">
                <a href="permissions.php"><i class="fa fa-key"></i><span>Roles &amp; Permissions</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_contacts')): ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['customers.php', 'customer-add.php', 'customer-edit.php', 'customer-view.php']) ? 'active' : ''; ?>">
                <a href="customers.php"><i class="fa fa-address-book"></i><span>Customers</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_leads')): ?>
            <?php
            $new_storefront_requests = fetch_one(
                "SELECT COUNT(*) AS total
                   FROM leads l
                   JOIN storefront_lead_requests r ON r.lead_id = l.lead_id AND r.tenant_id = l.tenant_id
                  WHERE l.tenant_id = ? AND l.source = 'storefront'
                    AND l.status NOT IN ('converted', 'lost')
                    AND r.converted_order_id IS NULL
                    AND l.lead_id > COALESCE((
                        SELECT n.last_seen_lead_id
                          FROM storefront_request_notification_reads n
                         WHERE n.tenant_id = l.tenant_id AND n.user_id = ?
                    ), 0)",
                (int)$current_user->tenant_id,
                (int)$current_user->user_id
            );
            $new_storefront_request_count = (int)($new_storefront_requests['total'] ?? 0);
            ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['leads.php', 'lead-add.php', 'lead-edit.php', 'lead-view.php']) ? 'active' : ''; ?>">
                <a href="leads.php"><i class="fa fa-bullseye"></i><span>Leads</span>
                    <?php if ($new_storefront_request_count > 0): ?>
                        <span class="badge badge-danger ml-auto" aria-label="<?php echo $new_storefront_request_count; ?> new storefront requests"><?php echo $new_storefront_request_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_suppliers')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'suppliers.php' ? 'active' : ''; ?>">
                <a href="suppliers.php"><i class="fa fa-truck"></i><span>Suppliers</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_products')): ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['products.php', 'product-add.php', 'product-edit.php', 'product-view.php']) ? 'active' : ''; ?>">
                <a href="products.php"><i class="fa fa-cube"></i><span>Products</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_inventory')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : ''; ?>">
                <a href="inventory.php"><i class="fa fa-cubes"></i><span>Inventory</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_godown') || can('view_godown') || can('approve_godown_transfers') || can('dispatch_godown_transfers') || can('receive_godown_transfers') || can('request_godown_transfers')): ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['godown.php', 'godown-transfers.php', 'godown-transfer-add.php', 'godown-transfer-view.php', 'inventory-adjust.php']) ? 'active' : ''; ?>">
                <a href="<?php echo can('manage_godown') ? 'godown.php' : 'godown-transfers.php'; ?>"><i class="fa fa-archive"></i><span>Central Godown</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_purchases')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'purchases.php' ? 'active' : ''; ?>">
                <a href="purchases.php"><i class="fa fa-shopping-cart"></i><span>Purchases</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_shops')): ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['shops.php', 'stores.php', 'user-shops.php']) ? 'active' : ''; ?>">
                <a href="shops.php"><i class="fa fa-building"></i><span>Shops &amp; Stores</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_orders')): ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['orders.php', 'order-add.php', 'order-edit.php', 'order-view.php']) ? 'active' : ''; ?>">
                <a href="orders.php"><i class="fa fa-list-alt"></i><span>Orders</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_pos')): ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['sales.php', 'sale-add.php', 'sale-details.php', 'sale-edit.php']) ? 'active' : ''; ?>">
                <a href="sales.php"><i class="fa fa-shopping-cart"></i><span>Sales</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_invoices')): ?>
            <li class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['invoices.php', 'invoice-add.php', 'invoice-details.php', 'invoice-edit.php', 'invoice-print.php', 'quotations.php', 'quotation-add.php', 'quotation-details.php', 'quotation-print.php', 'quotation-download.php']) ? 'active' : ''; ?>">
                <a href="invoices.php"><i class="fa fa-file-text"></i><span>Invoices &amp; Quotations</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_expenses') || can('manage_settings')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'expenses.php' ? 'active' : ''; ?>">
                <a href="expenses.php"><i class="fa fa-money"></i><span>Expenses</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_receipts')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'receipts.php' ? 'active' : ''; ?>">
                <a href="receipts.php"><i class="fa fa-credit-card"></i><span>Payments</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('view_reports')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                <a href="reports.php"><i class="fa fa-bar-chart"></i><span>Reports</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('view_activity_log') || can('view_reports')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit-log.php' ? 'active' : ''; ?>">
                <a href="audit-log.php"><i class="fa fa-history"></i><span>Activity Log</span></a>
            </li>
            <?php endif; ?>
            <?php if (can('manage_settings')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
                <a href="settings.php"><i class="fa fa-cog"></i><span>Settings</span></a>
            </li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer no-print"><?php echo e(APP_NAME); ?> v3.0 &middot; &copy; <?php echo date('Y'); ?></div>
    </aside>
