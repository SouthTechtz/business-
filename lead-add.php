<?php
require_once 'header.php';
require_once 'auth_check.php';
require_permission('manage_leads');
$page_title = 'New Lead';

$error = '';
$sources = ['staff' => 'Staff entry', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'referral' => 'Referral', 'walk_in' => 'Walk-in'];
$contacts = fetch_all(
    "SELECT contact_id, first_name, last_name, company_name, phone, mobile, email, address, city
     FROM contacts WHERE tenant_id = ? AND is_active = 1 ORDER BY first_name, company_name",
    $current_user->tenant_id
);
$staff = users_with_permission('manage_leads');

$prefill_contact_id = (int)($_GET['contact_id'] ?? 0);
$prefill = ['name' => '', 'company' => '', 'phone' => '', 'email' => '', 'location' => '', 'contact_id' => $prefill_contact_id];
if ($prefill_contact_id > 0) {
    $contact = fetch_one(
        "SELECT * FROM contacts WHERE contact_id = ? AND tenant_id = ?",
        $prefill_contact_id,
        $current_user->tenant_id
    );
    if ($contact) {
        $prefill['name'] = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: (string)$contact['company_name'];
        $prefill['company'] = (string)$contact['company_name'];
        $prefill['phone'] = (string)($contact['phone'] ?: $contact['mobile']);
        $prefill['email'] = (string)$contact['email'];
        $prefill['location'] = trim(($contact['address'] ?? '') . ' ' . ($contact['city'] ?? ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This form was already submitted. Check the Leads list before trying again.');
        redirect('leads.php');
    }

    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $contact_name = trim($_POST['contact_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $source = (string)($_POST['source'] ?? 'staff');
    $assigned_to = (int)($_POST['assigned_to'] ?? 0);
    $next_follow_up_at = trim($_POST['next_follow_up_at'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!isset($sources[$source])) $source = 'staff';
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
    if ($error === '' && $assigned_to > 0) {
        if (!user_has_tenant_permission($assigned_to, 'manage_leads', (int)$current_user->tenant_id)) {
            $error = 'The selected staff member cannot be assigned leads.';
        }
    }

    if ($error === '') {
        $conn->begin_transaction();
        try {
            $lead_number = next_document_number($current_user->tenant_id, 'lead', 'LEAD');
            $lead_id = db_insert('leads', [
                'tenant_id'        => (int)$current_user->tenant_id,
                'lead_number'      => $lead_number,
                'contact_id'       => $contact_id ?: null,
                'contact_name'     => $contact_name,
                'company_name'     => $company_name !== '' ? $company_name : null,
                'phone'            => $phone !== '' ? $phone : null,
                'email'            => $email !== '' ? $email : null,
                'location'         => $location !== '' ? $location : null,
                'source'           => $source,
                'status'           => 'new',
                'assigned_to'      => $assigned_to ?: null,
                'next_follow_up_at'=> $next_follow_up_at !== '' ? $next_follow_up_at : null,
                'notes'            => $notes !== '' ? $notes : null,
                'created_by'       => (int)$current_user->user_id,
            ]);
            if (!$lead_id) throw new RuntimeException('Could not save the lead.');
            record_lead_activity(
                $lead_id,
                (int)$current_user->tenant_id,
                'created',
                'Lead created',
                $notes !== '' ? $notes : null,
                null,
                null,
                $next_follow_up_at ?: null
            );
            $conn->commit();
            consume_form_token();
            audit_log('create', 'lead', $lead_id, 'Lead ' . $lead_number . ' created');
            flash_set('success', 'Lead ' . $lead_number . ' created.');
            redirect('lead-view.php?id=' . $lead_id);
        } catch (Throwable $ex) {
            $conn->rollback();
            $error = 'Could not save the lead: ' . $ex->getMessage();
        }
    }
}

require_once 'navbar.php';
require_once 'sidebar.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">New Lead</h1>
        <div class="page-sub">Track a customer conversation. A lead can exist before an order and does not affect stock or sales.</div>
    </div>
    <a href="leads.php" class="btn btn-light"><i class="fa fa-arrow-left mr-1"></i> Back to Leads</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="lead-add.php">
                    <?php echo csrf_field(); ?>
                    <?php echo form_token_field(); ?>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Existing Customer (optional)</label>
                            <select name="contact_id" class="form-control" id="leadContact">
                                <option value="">— Not linked —</option>
                                <?php foreach ($contacts as $c): ?>
                                    <option value="<?php echo (int)$c['contact_id']; ?>"
                                            data-name="<?php echo e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']); ?>"
                                            data-company="<?php echo e($c['company_name']); ?>"
                                            data-phone="<?php echo e($c['phone'] ?: $c['mobile']); ?>"
                                            data-email="<?php echo e($c['email']); ?>"
                                            data-location="<?php echo e(trim(($c['address'] ?? '') . ' ' . ($c['city'] ?? ''))); ?>"
                                        <?php echo (int)($_POST['contact_id'] ?? $prefill['contact_id']) === (int)$c['contact_id'] ? 'selected' : ''; ?>>
                                        <?php echo e((trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: $c['company_name']) . ' — ' . ($c['phone'] ?: $c['mobile'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Contact Name <span class="text-danger">*</span></label>
                            <input type="text" name="contact_name" id="leadName" class="form-control" maxlength="191" required
                                   value="<?php echo e($_POST['contact_name'] ?? $prefill['name']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Company</label>
                            <input type="text" name="company_name" id="leadCompany" class="form-control" maxlength="191"
                                   value="<?php echo e($_POST['company_name'] ?? $prefill['company']); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" id="leadPhone" class="form-control" maxlength="50"
                                   value="<?php echo e($_POST['phone'] ?? $prefill['phone']); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Email</label>
                            <input type="email" name="email" id="leadEmail" class="form-control" maxlength="191"
                                   value="<?php echo e($_POST['email'] ?? $prefill['email']); ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Location</label>
                            <input type="text" name="location" id="leadLocation" class="form-control" maxlength="255"
                                   value="<?php echo e($_POST['location'] ?? $prefill['location']); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Source</label>
                            <select name="source" class="form-control">
                                <?php foreach ($sources as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo ($_POST['source'] ?? 'staff') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Assign To</label>
                            <select name="assigned_to" class="form-control">
                                <option value="0">— Unassigned —</option>
                                <?php foreach ($staff as $u): ?>
                                    <option value="<?php echo (int)$u['user_id']; ?>" <?php echo (int)($_POST['assigned_to'] ?? 0) === (int)$u['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo e(trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Next Follow-up</label>
                            <input type="date" name="next_follow_up_at" class="form-control"
                                   value="<?php echo e($_POST['next_follow_up_at'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="4" placeholder="What did the customer ask for?"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-check mr-1"></i> Create Lead</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(function () {
        $('#leadContact').on('change', function () {
            var opt = $(this).find('option:selected');
            if (!$(this).val()) return;
            $('#leadName').val(opt.data('name') || '');
            $('#leadCompany').val(opt.data('company') || '');
            $('#leadPhone').val(opt.data('phone') || '');
            $('#leadEmail').val(opt.data('email') || '');
            $('#leadLocation').val(opt.data('location') || '');
        });
    });
</script>

<?php require_once 'footer.php'; ?>
