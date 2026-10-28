<?php
/**
 * DEV-ONLY rollback for 003_orders_leads.php.
 *
 * Removes:
 *   - order_items, orders, leads tables (ALL order/lead history is lost)
 *   - document_sequences table
 *   - lead/order history columns and FKs added to activities
 *   - manage_orders and manage_leads permissions (and their role grants)
 *
 * It does NOT touch contacts, products, customers, POS sales, invoices,
 * payments, inventory, shops, or warehouses.
 *
 * Run only on a disposable local database after a verified backup:
 *   php database/migrations/003_orders_leads_rollback.php --confirm=ROLLBACK_ORDERS_LEADS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This rollback can only be run from the command line.\n");
}

if (($argv[1] ?? '') !== '--confirm=ROLLBACK_ORDERS_LEADS') {
    fwrite(STDERR, "Refusing to run. Pass --confirm=ROLLBACK_ORDERS_LEADS after taking a verified backup.\n");
    exit(1);
}

require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

$allowedHosts = ['127.0.0.1', 'localhost', '::1'];
if (!in_array(DB_HOST, $allowedHosts, true) && getenv('ORDERS_LEADS_ROLLBACK_ALLOW_REMOTE') !== '1') {
    fwrite(STDERR, "Refusing to run against non-local DB_HOST. Set ORDERS_LEADS_ROLLBACK_ALLOW_REMOTE=1 only on a disposable database.\n");
    exit(1);
}

function m3r_fail(string $message): void
{
    throw new RuntimeException($message);
}

function m3r_query(string $sql): void
{
    global $conn;
    if ($conn->query($sql) !== true) {
        m3r_fail($conn->error . ' | SQL: ' . $sql);
    }
}

function m3r_exec(string $sql, string $types = '', ...$params): void
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        m3r_fail($conn->error . ' | ' . $sql);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        m3r_fail($stmt->error . ' | ' . $sql);
    }
    $stmt->close();
}

function m3r_has_table(string $table): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        $table
    );
}

function m3r_has_column(string $table, string $column): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        $table,
        $column
    );
}

function m3r_has_fk(string $table, string $constraint): bool
{
    return (bool)fetch_one(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
        $table,
        $constraint
    );
}

function m3r_drop_fk(string $table, string $constraint): void
{
    if (!m3r_has_fk($table, $constraint)) {
        return;
    }
    m3r_query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
    echo "  - FK {$table}.{$constraint}\n";
}

function m3r_drop_index(string $table, string $index): void
{
    $exists = fetch_one(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        $table,
        $index
    );
    if (!$exists) {
        return;
    }
    m3r_query("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    echo "  - index {$table}.{$index}\n";
}

function m3r_drop_column(string $table, string $column): void
{
    if (!m3r_has_column($table, $column)) {
        return;
    }
    m3r_query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
    echo "  - column {$table}.{$column}\n";
}

echo "Rollback 003: Orders and Leads\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    echo "1) Remove lead/order history from activities\n";
    m3r_drop_fk('activities', 'fk_act_lead');
    m3r_drop_fk('activities', 'fk_act_order');
    m3r_drop_index('activities', 'idx_act_lead');
    m3r_drop_index('activities', 'idx_act_order');
    $historyWhere = [];
    if (m3r_has_column('activities', 'lead_id')) $historyWhere[] = '`lead_id` IS NOT NULL';
    if (m3r_has_column('activities', 'order_id')) $historyWhere[] = '`order_id` IS NOT NULL';
    if ($historyWhere) {
        m3r_query("DELETE FROM `activities` WHERE " . implode(' OR ', $historyWhere));
        echo "  - linked lead/order activity rows\n";
    }
    m3r_drop_column('activities', 'outcome');
    m3r_drop_column('activities', 'notes');
    m3r_drop_column('activities', 'activity_type');
    m3r_drop_column('activities', 'order_id');
    m3r_drop_column('activities', 'lead_id');

    echo "\n2) Drop orders and leads tables\n";
    if (m3r_has_table('order_items')) {
        m3r_query("DROP TABLE `order_items`");
        echo "  - order_items\n";
    }
    if (m3r_has_table('orders')) {
        m3r_query("DROP TABLE `orders`");
        echo "  - orders\n";
    }
    if (m3r_has_table('leads')) {
        m3r_query("DROP TABLE `leads`");
        echo "  - leads\n";
    }
    if (m3r_has_table('document_sequences')) {
        m3r_query("DROP TABLE `document_sequences`");
        echo "  - document_sequences\n";
    }

    echo "\n3) Remove permissions\n";
    m3r_exec("DELETE FROM permissions WHERE name IN ('manage_orders', 'manage_leads')");
    echo "  - manage_orders, manage_leads\n";

    echo "\n4) Remove migration record\n";
    m3r_exec("DELETE FROM schema_migrations WHERE migration = '003_orders_leads'");
    echo "  - schema_migrations entry\n";

    echo "\nRollback 003 completed. All order and lead records were removed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "\nRollback 003 FAILED: " . $ex->getMessage() . "\n");
    exit(1);
}
