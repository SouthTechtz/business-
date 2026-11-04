<?php
require_once __DIR__ . '/wholesale_auth.php';

$tenant_id = (int)$wholesale_user->tenant_id;
$contact_id = (int)($wholesaler['contact_id'] ?? 0);
$currency = tenant_currency();
$activity = wholesale_activity($tenant_id, (int)$wholesale_user->user_id, 40);
$recent_requests = $contact_id ? wholesale_requests($tenant_id, $contact_id, 10, 0) : [];
$page_title = 'Activity';

require __DIR__ . '/wholesale-portal-header.php';
?>
<div class="ws-page-head">
    <div>
        <h1>Account activity</h1>
        <p>Sign-ins, account changes and order requests from your wholesale account.</p>
    </div>
    <div class="ws-page-head-actions">
        <a class="ws-btn" href="wholesale-dashboard.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
        <a class="ws-btn ws-btn-primary" href="wholesale-order-new.php"><i class="fa fa-plus" aria-hidden="true"></i> New order</a>
    </div>
</div>

<div class="ws-grid-2">
    <section class="ws-panel" aria-labelledby="ws-history-title">
        <div class="ws-panel-head">
            <div>
                <h2 id="ws-history-title">Recent account activity</h2>
                <p>Newest first.</p>
            </div>
        </div>
        <div class="ws-panel-body">
            <?php if ($activity): ?>
                <ul class="ws-timeline">
                    <?php foreach ($activity as $entry): ?>
                        <li>
                            <strong>
                                <?php
                                $action = strtolower((string)$entry['action']);
                                echo $action === 'login' ? 'Signed in to the wholesale portal'
                                    : ($action === 'create' ? 'Created a record'
                                    : ($action === 'update' ? 'Account updated' : ucfirst($action)));
                                ?>
                            </strong>
                            <?php if (!empty($entry['details'])): ?><span><?php echo e($entry['details']); ?></span><?php endif; ?>
                            <span><?php echo e(date('d M Y, H:i', strtotime($entry['created_at']))); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="ws-empty">
                    <i class="fa fa-history" aria-hidden="true"></i>
                    <strong>No activity yet</strong>
                    <p>Your sign-ins, profile changes and order requests will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="ws-panel" aria-labelledby="ws-activity-orders">
        <div class="ws-panel-head">
            <div>
                <h2 id="ws-activity-orders">Order requests</h2>
                <p>Your latest requests.</p>
            </div>
            <a class="ws-btn ws-btn-sm" href="wholesale-orders.php">View all</a>
        </div>
        <?php if ($recent_requests): ?>
            <div class="ws-table-wrap">
                <table class="ws-table">
                    <tbody>
                        <?php foreach ($recent_requests as $request_row): ?>
                            <?php $status_meta = wholesale_status_meta($request_row['status']); ?>
                            <tr>
                                <td>
                                    <a href="wholesale-order-view.php?id=<?php echo (int)$request_row['request_id']; ?>"><strong><?php echo e($request_row['lead_number']); ?></strong></a><br>
                                    <small class="text-muted"><?php echo e(date('d M Y', strtotime($request_row['created_at']))); ?> &middot; <?php echo e($status_meta['label']); ?></small>
                                </td>
                                <td class="text-right"><?php echo e(money($request_row['subtotal'], $request_row['currency_code'] ?: $currency)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ws-panel-body">
                <p class="text-muted mb-0" style="font-size:13px;">No order requests yet.</p>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/wholesale-portal-footer.php'; ?>
