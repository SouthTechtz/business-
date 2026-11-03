<?php
require_once __DIR__ . '/../config.php';

function lead_edit_status_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$admin = fetch_one("SELECT user_id, tenant_id FROM users WHERE is_active = 1 ORDER BY user_id LIMIT 1");
if (!$admin) {
    echo "SKIP: no active user is available.\n";
    exit(0);
}

require_once __DIR__ . '/../User.php';
$GLOBALS['current_user'] = new User($admin);
$current_user = $GLOBALS['current_user'];
$tenant_id = (int)$admin['tenant_id'];
$user_id = (int)$admin['user_id'];

$conn->begin_transaction();
try {
    $lead_id = db_insert('leads', [
        'tenant_id' => $tenant_id,
        'lead_number' => 'TESTEDIT_' . time(),
        'contact_name' => 'Lead Status Test',
        'status' => 'pending',
        'created_by' => $user_id,
    ]);
    lead_edit_status_assert($lead_id > 0, 'pending lead created');
    lead_edit_status_assert(
        in_array('confirmed', lead_allowed_transitions('pending'), true),
        'pending lead can be confirmed'
    );
    lead_edit_status_assert(
        lead_allowed_transitions('confirmed') === [],
        'confirmed lead cannot be reopened'
    );
    lead_edit_status_assert(
        lead_follow_up_date_required('follow_up', '') && lead_follow_up_date_required('follow_up', '   '),
        'Follow Up status requires a non-empty follow-up date'
    );
    lead_edit_status_assert(
        !lead_follow_up_date_required('follow_up', '2026-11-04')
            && !lead_follow_up_date_required('pending', ''),
        'a supplied date satisfies Follow Up and other statuses do not require one'
    );

    $contact_id = null;
    $contact_name = 'Lead Status Test';
    $nullable = null;
    $source = 'staff';
    $target_status = 'confirmed';
    $previous_status = 'pending';
    $stmt = $conn->prepare(
        "UPDATE leads SET contact_id = ?, contact_name = ?, company_name = ?, phone = ?, email = ?,
            location = ?, source = ?, next_follow_up_at = ?, notes = ?, status = ?,
            lost_reason = CASE WHEN ? = 'cancelled' THEN lost_reason ELSE NULL END,
            converted_at = CASE WHEN ? = 'confirmed' THEN COALESCE(converted_at, NOW()) ELSE NULL END
          WHERE lead_id = ? AND tenant_id = ? AND status = ?"
    );
    $stmt->bind_param(
        'i' . str_repeat('s', 11) . 'iis',
        $contact_id,
        $contact_name,
        $nullable,
        $nullable,
        $nullable,
        $nullable,
        $source,
        $nullable,
        $nullable,
        $target_status,
        $target_status,
        $target_status,
        $lead_id,
        $tenant_id,
        $previous_status
    );
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    lead_edit_status_assert($stmt->affected_rows === 1, 'status update affects the expected lead');
    $stmt->close();

    record_lead_activity(
        $lead_id,
        $tenant_id,
        'status_change',
        'Status: Pending to Confirmed',
        'Lead confirmed.'
    );
    $saved = fetch_one(
        "SELECT status, converted_at FROM leads WHERE lead_id = ? AND tenant_id = ?",
        $lead_id,
        $tenant_id
    );
    lead_edit_status_assert(
        $saved && $saved['status'] === 'confirmed' && !empty($saved['converted_at']),
        'confirmation saves status and conversion timestamp'
    );
    $activity = fetch_one(
        "SELECT activity_id FROM activities WHERE lead_id = ? AND tenant_id = ? AND activity_type = 'status_change'",
        $lead_id,
        $tenant_id
    );
    lead_edit_status_assert((bool)$activity, 'status change is recorded in lead history');

    $conn->rollback();
    echo "Lead edit status test passed (rolled back).\n";
} catch (Throwable $ex) {
    $conn->rollback();
    fwrite(STDERR, "ERROR: " . $ex->getMessage() . "\n");
    exit(1);
}
