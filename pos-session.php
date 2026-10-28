<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_pos');
$page_title = 'POS Session';

$error = '';
$open_session = find_open_pos_session();
$open_store = $open_session && (int)$open_session['warehouse_id'] > 0
    ? find_accessible_store((int)$open_session['warehouse_id'], false)
    : null;

$shops = accessible_shops(null, false);
$stores = accessible_stores(null, false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'open_session') {
        if ($open_session) {
            flash_set('danger', 'You already have an open POS session. Close it before opening another.');
            redirect('pos-session.php');
        }

        $shop_id = (int)($_POST['shop_id'] ?? 0);
        $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
        $shop = $shop_id > 0 ? find_open_shop_for_session($shop_id) : null;
        $store = $warehouse_id > 0 ? find_accessible_store($warehouse_id, false) : null;

        if (!$shop) {
            $error = 'Select an active shop you are allowed to use.';
        } elseif (!$store || (int)$store['shop_id'] !== (int)$shop['shop_id']) {
            $error = 'Select an active store that belongs to the selected shop.';
        } else {
            $conn->begin_transaction();
            try {
                // Serialize session opening per user, then re-check inside the lock.
                $lock = fetch_one(
                    "SELECT user_id FROM users WHERE user_id = ? AND tenant_id = ? FOR UPDATE",
                    $current_user->user_id,
                    $current_user->tenant_id
                );
                if (!$lock) {
                    throw new RuntimeException('Your user account could not be locked.');
                }
                if (find_open_pos_session()) {
                    throw new RuntimeException('You already have an open POS session.');
                }

                $session_number = next_pos_session_number($current_user->tenant_id);
                $stmt = $conn->prepare(
                    "INSERT INTO pos_sessions
                        (tenant_id, user_id, warehouse_id, session_number, start_time, starting_cash, is_closed)
                     VALUES (?, ?, ?, ?, NOW(), 0.00, 0)"
                );
                $stmt->bind_param(
                    'iiis',
                    $current_user->tenant_id,
                    $current_user->user_id,
                    $warehouse_id,
                    $session_number
                );
                $stmt->execute();
                $session_id = (int)$stmt->insert_id;
                $stmt->close();

                $conn->commit();

                // A new location must never inherit another location's cart.
                unset($_SESSION['pos_cart'], $_SESSION['pos_warehouse_id']);
                $_SESSION['pos_session_id'] = $session_id;
                audit_log('create', 'pos_session', $session_id, 'POS session opened at ' . store_label($store));
                flash_set('success', 'POS session opened at ' . store_label($store) . '. The location is fixed until you close the session.');
                redirect('sale-add.php');
            } catch (Throwable $ex) {
                $conn->rollback();
                $error = 'Could not open the POS session: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'close_session') {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $conn->begin_transaction();
        try {
            $session = fetch_one(
                "SELECT * FROM pos_sessions
                 WHERE session_id = ? AND tenant_id = ? AND user_id = ? AND is_closed = 0
                 FOR UPDATE",
                $session_id,
                $current_user->tenant_id,
                $current_user->user_id
            );
            if (!$session) {
                throw new RuntimeException('Open POS session not found.');
            }

            $stmt = $conn->prepare("UPDATE pos_sessions SET is_closed = 1 WHERE session_id = ? AND tenant_id = ? AND user_id = ?");
            $stmt->bind_param('iii', $session_id, $current_user->tenant_id, $current_user->user_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            unset($_SESSION['pos_cart'], $_SESSION['pos_warehouse_id'], $_SESSION['pos_session_id']);
            audit_log('close', 'pos_session', $session_id, 'POS session closed');
            flash_set('success', 'POS session closed. You can open a new session at another shop or store.');
            redirect('pos-session.php');
        } catch (Throwable $ex) {
            $conn->rollback();
            flash_set('danger', 'Could not close the POS session: ' . $ex->getMessage());
            redirect('pos-session.php');
        }
    }
}

$recent_sessions = fetch_all(
    "SELECT ps.*, w.name store_name, w.code store_code, s.name shop_name, s.code shop_code
     FROM pos_sessions ps
     LEFT JOIN warehouses w ON w.warehouse_id = ps.warehouse_id AND w.tenant_id = ps.tenant_id
     LEFT JOIN shops s ON s.shop_id = w.shop_id AND s.tenant_id = w.tenant_id
     WHERE ps.tenant_id = ? AND ps.user_id = ?
     ORDER BY ps.session_id DESC LIMIT 10",
    $current_user->tenant_id,
    $current_user->user_id
);

/**
 * Shop lookup used only by the session opener. It deliberately requires the
 * shop to be active and accessible to the current user.
 */
function find_open_shop_for_session($shop_id) {
    global $current_user;
    if (!shop_is_accessible($shop_id, false)) return null;
    return fetch_one(
        "SELECT * FROM shops WHERE shop_id = ? AND tenant_id = ? AND is_active = 1",
        (int)$shop_id,
        $current_user->tenant_id
    );
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">POS Session</h1>
        <div class="page-sub">Choose the shop and store for this till session. The location is fixed until the session is closed.</div>
    </div>
    <a href="sales.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Sales</a>
</div>

<?php if ($f = flash_get()): ?>
    <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-7 mb-4">
        <?php if ($open_session): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="fa fa-lock mr-1"></i> Active POS Session</h5>
                    <span class="badge badge-success">Open</span>
                </div>
                <div class="card-body">
                    <?php if ($open_store): ?>
                        <p class="mb-1">Selling from</p>
                        <h4><?php echo e(store_label($open_store)); ?></h4>
                        <p class="text-muted mb-3">The store cannot be changed inside this session.</p>
                    <?php else: ?>
                        <div class="alert alert-warning mb-3">
                            This session points to a store or shop that is no longer active or accessible.
                            Close it and open a new session at an available location.
                        </div>
                    <?php endif; ?>
                    <dl class="row mb-3">
                        <dt class="col-sm-4">Session number</dt>
                        <dd class="col-sm-8"><?php echo e($open_session['session_number']); ?></dd>
                        <dt class="col-sm-4">Started</dt>
                        <dd class="col-sm-8"><?php echo e(date('M d, Y H:i', strtotime($open_session['start_time']))); ?></dd>
                        <dt class="col-sm-4">Shop</dt>
                        <dd class="col-sm-8"><?php echo e($open_session['shop_name'] ?: 'Unavailable'); ?></dd>
                        <dt class="col-sm-4">Store</dt>
                        <dd class="col-sm-8"><?php echo e($open_session['store_name'] ?: 'Unavailable'); ?></dd>
                    </dl>
                    <div class="d-flex">
                        <?php if ($open_store): ?>
                            <a href="sale-add.php" class="btn btn-primary mr-2"><i class="fa fa-shopping-cart mr-1"></i> Continue to POS</a>
                        <?php endif; ?>
                        <form method="POST" action="pos-session.php" onsubmit="return confirm('Close this POS session? The cart will be cleared and you must choose a new shop/store next time.');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="close_session">
                            <input type="hidden" name="session_id" value="<?php echo (int)$open_session['session_id']; ?>">
                            <button type="submit" class="btn btn-outline-danger"><i class="fa fa-unlock mr-1"></i> Close Session</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0"><i class="fa fa-play mr-1"></i> Open POS Session</h5></div>
                <div class="card-body">
                    <?php if (!$shops || !$stores): ?>
                        <div class="alert alert-warning mb-0">
                            No active shop/store is assigned to your account. Ask an administrator to grant access in
                            <strong>Users &rarr; Shop Access</strong>.
                        </div>
                    <?php else: ?>
                        <form method="POST" action="pos-session.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="open_session">
                            <div class="form-group">
                                <label>Shop <span class="text-danger">*</span></label>
                                <select name="shop_id" id="sessionShop" class="form-control" required>
                                    <option value="">— Select Shop —</option>
                                    <?php foreach ($shops as $shop): ?>
                                        <option value="<?php echo (int)$shop['shop_id']; ?>"><?php echo e($shop['name'] . ' (' . $shop['code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Store / Warehouse <span class="text-danger">*</span></label>
                                <select name="warehouse_id" id="sessionStore" class="form-control" required>
                                    <option value="">— Select Shop first —</option>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo (int)$store['warehouse_id']; ?>" data-shop="<?php echo (int)$store['shop_id']; ?>">
                                            <?php echo e(store_label($store)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Only active stores in the selected shop are available.</small>
                            </div>
                            <div class="alert alert-info small">
                                Stock checks and deductions for this session use the selected store. To sell from a
                                different store, close this session and open a new one.
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fa fa-play mr-1"></i> Open Session</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Recent Sessions</h5></div>
            <div class="card-body p-0">
                <?php if (!$recent_sessions): ?>
                    <div class="p-3 text-muted">No sessions yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Session</th><th>Location</th><th>Started</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($recent_sessions as $row): ?>
                                <tr>
                                    <td><small><?php echo e($row['session_number']); ?></small></td>
                                    <td>
                                        <small>
                                            <?php echo e(($row['shop_name'] ?: 'Unknown') . ' / ' . ($row['store_name'] ?: 'Unknown')); ?>
                                        </small>
                                    </td>
                                    <td><small><?php echo e(date('M d, H:i', strtotime($row['start_time']))); ?></small></td>
                                    <td>
                                        <?php if ((int)$row['is_closed']): ?>
                                            <span class="badge badge-secondary">Closed</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">Open</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var shop = document.getElementById('sessionShop');
        var store = document.getElementById('sessionStore');
        if (!shop || !store) return;

        function filterStores() {
            var shopId = shop.value;
            var first = null;
            Array.prototype.forEach.call(store.options, function (option) {
                if (!option.value) return;
                var matches = option.getAttribute('data-shop') === shopId;
                option.hidden = !matches;
                option.disabled = !matches;
                if (matches && first === null) first = option;
            });
            if (shopId && first) {
                store.value = first.value;
            } else {
                store.value = '';
            }
        }

        shop.addEventListener('change', filterStores);
        filterStores();
    }());
</script>

<?php require_once 'footer.php'; ?>
