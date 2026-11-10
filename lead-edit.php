<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_leads');
$page_title = 'Edit Lead';

$id = (int)($_GET['id'] ?? ($_POST['lead_id'] ?? 0));
$lead = fetch_one(
    "SELECT * FROM leads WHERE lead_id = ? AND tenant_id = ?",
    $id,
    $current_user->tenant_id
);
if (!$lead) {
    flash_set('danger', 'Lead not found.');
    redirect('leads.php');
}
if (!lead_is_visible($lead)) {
    http_response_code(403);
    die('This lead belongs to another brand.');
}

$error = '';
$sources = ['staff' => 'Staff entry', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'referral' => 'Referral', 'walk_in' => 'Walk-in', 'storefront' => 'Storefront'];
$statuses = lead_statuses();
$allowed_statuses = array_merge([(string)$lead['status']], lead_allowed_transitions((string)$lead['status']));
$contacts = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name, phone, mobile, email, address, city
     FROM contacts WHERE tenant_id = ? AND is_active = 1 ORDER BY first_name, company_name",
    $current_user->tenant_id
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $contact_name = trim($_POST['contact_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $source = (string)($_POST['source'] ?? 'staff');
    $target_status = (string)($_POST['status'] ?? $lead['status']);
    $next_follow_up_at = trim($_POST['next_follow_up_at'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!isset($sources[$source])) $source = 'staff';
    if (!isset($statuses[$target_status]) || !in_array($target_status, $allowed_statuses, true)) {
        $error = 'That lead status change is not allowed.';
    }
    if ($error === '' && lead_follow_up_date_required($target_status, $next_follow_up_at)) {
        $error = 'Enter a next follow-up date before setting the lead status to Follow Up.';
    }
    if ($contact_id > 0 && !fetch_one(
        "SELECT 1 FROM contacts WHERE contact_id = ? AND tenant_id = ? AND is_active = 1",
        $contact_id,
        $current_user->tenant_id
    )) {
        $error = 'The selected customer does not exist.';
    }
    if ($error === '') {
        if ($contact_name === '') $error = 'Contact name is required.';
        elseif (mb_strlen($contact_name) > 191) $error = 'Contact name is too long.';
        elseif ($phone === '' && $email === '') $error = 'Provide a phone number or an email address.';
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Email is not valid.';
        elseif (mb_strlen($phone) > 50 || mb_strlen($email) > 191 || mb_strlen($location) > 255 || mb_strlen($company_name) > 191) $error = 'One of the contact fields is too long.';
        elseif ($next_follow_up_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_follow_up_at)) $error = 'Next follow-up date is not valid.';
    }

    if ($error === '') {
        $contact_db = $contact_id ?: null;
        $company_db = $company_name !== '' ? $company_name : null;
        $phone_db = $phone !== '' ? $phone : null;
        $email_db = $email !== '' ? $email : null;
        $location_db = $location !== '' ? $location : null;
        $follow_db = $next_follow_up_at !== '' ? $next_follow_up_at : null;
        $notes_db = $notes !== '' ? $notes : null;
        $storefront_request = $target_status === 'confirmed' && $lead['source'] === 'storefront'
            ? fetch_one(
                "SELECT converted_order_id FROM storefront_lead_requests WHERE lead_id = ? AND tenant_id = ?",
                $id,
                (int)$current_user->tenant_id
            )
            : null;
        $requires_storefront_verification = $storefront_request
            && empty($storefront_request['converted_order_id']);
        $status_to_save = $requires_storefront_verification
            ? (string)$lead['status']
            : $target_status;
        $status_changed = $status_to_save !== (string)$lead['status'];

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "UPDATE leads SET contact_id = ?, contact_name = ?, company_name = ?, phone = ?, email = ?,
                    location = ?, source = ?, next_follow_up_at = ?, notes = ?, status = ?,
                    lost_reason = CASE WHEN ? = 'cancelled' THEN lost_reason ELSE NULL END,
                    converted_at = CASE WHEN ? = 'confirmed' THEN COALESCE(converted_at, NOW()) ELSE NULL END
                  WHERE lead_id = ? AND tenant_id = ? AND status = ?"
            );
            $stmt->bind_param(
                'i' . str_repeat('s', 11) . 'iis',
                $contact_db,
                $contact_name,
                $company_db,
                $phone_db,
                $email_db,
                $location_db,
                $source,
                $follow_db,
                $notes_db,
                $status_to_save,
                $status_to_save,
                $status_to_save,
                $id,
                $current_user->tenant_id,
                $lead['status']
            );
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }
            if ($status_changed && $stmt->affected_rows !== 1) {
                throw new RuntimeException('This lead was changed by someone else. Reload and try again.');
            }
            $stmt->close();

            if ($status_changed) {
                record_lead_activity(
                    $id,
                    (int)$current_user->tenant_id,
                    'status_change',
                    'Status: ' . ($statuses[$lead['status']] ?? $lead['status'])
                        . ' → ' . $statuses[$status_to_save],
                    $status_to_save === 'confirmed' ? 'Lead confirmed.' : null
                );
            }
            $conn->commit();
        } catch (Throwable $ex) {
            $conn->rollback();
            $error = 'Could not update the lead: ' . $ex->getMessage();
        }

        if ($error === '') {
            audit_log('update', 'lead', $id, 'Lead ' . $lead['lead_number'] . ' updated');
            if ($requires_storefront_verification) {
                flash_set('info', 'Choose the responsible shop to confirm this request and create its order.');
                redirect('lead-verify.php?id=' . $id);
            }
            if ($status_changed && $status_to_save === 'confirmed') {
                audit_log('set_status', 'lead', $id, 'Status changed to confirmed');
                if (can('manage_orders') && $lead['source'] !== 'storefront') {
                    $linked = fetch_one(
                        "SELECT order_id FROM orders WHERE lead_id = ? AND tenant_id = ? LIMIT 1",
                        $id,
                        (int)$current_user->tenant_id
                    );
                    if (!$linked) {
                        flash_set('success', 'Lead confirmed. Add the order lines to complete the conversion.');
                        redirect('order-add.php?lead_id=' . $id . ($contact_id ? '&contact_id=' . $contact_id : ''));
                    }
                }
            }
            flash_set('success', $status_changed
                ? 'Lead updated and status changed to ' . $statuses[$status_to_save] . '.'
                : 'Lead updated.');
            redirect('lead-view.php?id=' . $id);
        }
    }

    $lead = array_merge($lead, [
        'contact_id' => $contact_id,
        'contact_name' => $contact_name,
        'company_name' => $company_name,
        'phone' => $phone,
        'email' => $email,
        'location' => $location,
        'source' => $source,
        'status' => $target_status,
        'next_follow_up_at' => $next_follow_up_at,
        'notes' => $notes,
    ]);
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Edit <?php echo e($lead['lead_number']); ?></h1>
        <div class="page-sub">Update contact details, follow-up scheduling, and lead status.</div>
    </div>
    <a href="lead-view.php?id=<?php echo $id; ?>" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Lead</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="lead-edit.php?id=<?php echo $id; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="lead_id" value="<?php echo $id; ?>">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Existing Customer</label>
                            <select name="contact_id" class="form-control" id="editLeadContact">
                                <option value="">— Not linked —</option>
                                <?php foreach ($contacts as $c): ?>
                                    <option value="<?php echo (int)$c['contact_id']; ?>"
                                            data-name="<?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?>"
                                            data-company="<?php echo e($c['company_name']); ?>"
                                            data-phone="<?php echo e($c['phone'] ?: $c['mobile']); ?>"
                                            data-email="<?php echo e($c['email']); ?>"
                                            data-location="<?php echo e(trim(($c['address'] ?? '') . ' ' . ($c['city'] ?? ''))); ?>"
                                        <?php echo (int)$lead['contact_id'] === (int)$c['contact_id'] ? 'selected' : ''; ?>>
                                        <?php echo e((trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']) . ' — ' . ($c['phone'] ?: $c['mobile'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Contact Name <span class="text-danger">*</span></label>
                            <input type="text" name="contact_name" id="editLeadName" class="form-control" maxlength="191" required value="<?php echo e($lead['contact_name']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Company</label>
                            <input type="text" name="company_name" id="editLeadCompany" class="form-control" maxlength="191" value="<?php echo e($lead['company_name']); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" id="editLeadPhone" class="form-control" maxlength="50" value="<?php echo e($lead['phone']); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Email</label>
                            <input type="email" name="email" id="editLeadEmail" class="form-control" maxlength="191" value="<?php echo e($lead['email']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Location</label>
                            <input type="text" name="location" id="editLeadLocation" class="form-control" maxlength="255" value="<?php echo e($lead['location']); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Source</label>
                            <select name="source" class="form-control">
                                <?php foreach ($sources as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo $lead['source'] === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label for="leadNextFollowUp">Next Follow-up <span class="text-danger follow-up-required-mark" <?php echo $lead['status'] === 'follow_up' ? '' : 'hidden'; ?>>*</span></label>
                            <input type="date" name="next_follow_up_at" id="leadNextFollowUp" class="form-control" value="<?php echo e($lead['next_follow_up_at']); ?>" <?php echo $lead['status'] === 'follow_up' ? 'required' : ''; ?>>
                        </div>
                        <div class="col-md-3 form-group">
                            <label for="leadStatus">Status</label>
                            <select name="status" id="leadStatus" class="form-control" required>
                                <?php foreach ($allowed_statuses as $status_option): ?>
                                    <option value="<?php echo e($status_option); ?>" <?php echo (string)$lead['status'] === $status_option ? 'selected' : ''; ?>>
                                        <?php echo e($statuses[$status_option] ?? $status_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($lead['source'] === 'storefront' && $lead['status'] !== 'confirmed'): ?>
                                <small class="form-text text-muted">Confirming a storefront request opens shop verification before its order is created.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="4"><?php echo e($lead['notes']); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        var $status = $('#leadStatus');
        var $followUp = $('#leadNextFollowUp');
        var $requiredMark = $('.follow-up-required-mark');
        function updateFollowUpRequirement() {
            var required = $status.val() === 'follow_up';
            $followUp.prop('required', required);
            $requiredMark.prop('hidden', !required);
        }
        $status.on('change', updateFollowUpRequirement);
        updateFollowUpRequirement();

        $('#editLeadContact').on('change', function () {
            var opt = $(this).find('option:selected');
            if (!$(this).val()) return;
            $('#editLeadName').val(opt.data('name') || '');
            $('#editLeadCompany').val(opt.data('company') || '');
            $('#editLeadPhone').val(opt.data('phone') || '');
            $('#editLeadEmail').val(opt.data('email') || '');
            $('#editLeadLocation').val(opt.data('location') || '');
        });
    });
</script>

<?php require_once 'footer.php'; ?>
