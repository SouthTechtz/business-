<?php
require_once __DIR__ . '/storefront.php';
require_once __DIR__ . '/wholesale.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
$sf_settings = storefront_settings($sf_tenant);
$sf_company = storefront_company_name($sf_tenant, $sf_settings);
$sf_currency = storefront_currency($sf_tenant, $sf_settings);
$sf_tenant_id = (int)$sf_tenant['tenant_id'];
$sf_search_q = '';
$sf_category = 0;

$sf_contact_phone = trim((string)($sf_settings['company_phone'] ?? ''));
if ($sf_contact_phone === '') $sf_contact_phone = trim((string)($sf_tenant['phone'] ?? ''));
$sf_contact_email = trim((string)($sf_settings['company_email'] ?? ''));
if ($sf_contact_email === '') $sf_contact_email = trim((string)($sf_tenant['email'] ?? ''));

$sf_form = [
    'name'     => '',
    'phone'    => '',
    'location' => '',
    'email'    => '',
];
$sf_error = '';
$sf_sent = isset($_GET['sent']);
$sf_new_username = trim((string)($_GET['user'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!form_token_valid()) {
        flash_set('danger', 'This registration was already submitted. Please sign in or contact us.');
        redirect('wholesale-registration.php');
    }

    foreach (array_keys($sf_form) as $sf_field) {
        $sf_raw = $_POST[$sf_field] ?? '';
        $sf_form[$sf_field] = is_string($sf_raw) ? trim($sf_raw) : '';
    }
    $sf_password = is_string($_POST['password'] ?? '') ? (string)$_POST['password'] : '';
    $sf_password_confirm = is_string($_POST['password_confirm'] ?? '') ? (string)$_POST['password_confirm'] : '';

    if ($sf_form['name'] === '') {
        $sf_error = 'Please enter your name or business name.';
    } elseif (mb_strlen($sf_form['name']) > 191) {
        $sf_error = 'The name is too long.';
    } elseif ($sf_form['location'] === '') {
        $sf_error = 'Please enter your town, district or delivery area.';
    } elseif (mb_strlen($sf_form['location']) > 255) {
        $sf_error = 'The location is too long.';
    } elseif (strlen(preg_replace('/\D+/', '', $sf_form['phone'])) < 7) {
        $sf_error = 'Please enter a valid phone number.';
    } elseif (strlen(preg_replace('/\D+/', '', $sf_form['phone'])) > 15) {
        $sf_error = 'The phone number is too long.';
    } elseif ($sf_form['email'] !== '' && !filter_var($sf_form['email'], FILTER_VALIDATE_EMAIL)) {
        $sf_error = 'Enter a valid email address, or leave it empty.';
    } elseif (mb_strlen($sf_form['email']) > 191) {
        $sf_error = 'The email address is too long.';
    } elseif (strlen($sf_password) < 8) {
        $sf_error = 'Choose a password of at least 8 characters.';
    } elseif ($sf_password !== $sf_password_confirm) {
        $sf_error = 'The two passwords do not match.';
    } elseif (wholesale_phone_in_use($sf_tenant_id, $sf_form['phone'])) {
        $sf_error = 'An account already uses that phone number. Please sign in instead.';
    } elseif ($sf_form['email'] !== '' && fetch_one(
        "SELECT user_id FROM users WHERE tenant_id = ? AND email = ? LIMIT 1",
        $sf_tenant_id,
        $sf_form['email']
    )) {
        $sf_error = 'An account already exists with that email address. Please sign in instead.';
    }

    if ($sf_error === '') {
        try {
            $created = create_wholesale_account($sf_tenant_id, [
                'name'     => $sf_form['name'],
                'phone'    => $sf_form['phone'],
                'location' => $sf_form['location'],
                'email'    => $sf_form['email'],
                'password' => $sf_password,
            ]);
            consume_form_token();
            audit_log('create', 'wholesaler', (int)$created['wholesaler_id'], 'Wholesale account registered: ' . $sf_form['name']);
            redirect('wholesale-registration.php?sent=1&user=' . urlencode($created['username']));
        } catch (Throwable $sf_exception) {
            error_log('Wholesale registration failed: ' . $sf_exception->getMessage());
            $sf_error = 'We could not create your account right now. Please try again or contact us directly.';
        }
    }
}

$page_title = 'Wholesale registration';
$sf_meta_description = 'Register for a wholesale account with ' . $sf_company
    . '. Sign in with your name or phone number to order in bulk.';
$sf_canonical_url = storefront_absolute_url('wholesale-registration.php');

require __DIR__ . '/storefront-header.php';
?>
<main class="sf-main" id="sf-main">
    <div class="sf-container sf-wholesale-page">
        <nav aria-label="Breadcrumb">
            <ol class="sf-breadcrumb">
                <li><a href="index.php">Home</a></li>
                <li aria-current="page">Wholesale registration</li>
            </ol>
        </nav>

        <?php if ($sf_sent): ?>
            <div class="sf-success-card">
                <i class="fa fa-check" aria-hidden="true"></i>
                <h1>Your wholesale account is ready</h1>
                <p>Thank you. You can now sign in to your wholesale portal to create order requests and track your order history.</p>
                <?php if ($sf_new_username !== ''): ?>
                    <span class="sf-success-ref">Your username: <?php echo e($sf_new_username); ?></span>
                    <p>Sign in with that username, your name, or your phone number, plus your password.</p>
                <?php endif; ?>
                <p>You can add business details such as email, business type and monthly volume from your account profile.</p>
                <div class="sf-success-actions">
                    <a class="sf-btn sf-btn-primary" href="login.php">Sign in to wholesale portal</a>
                    <a class="sf-btn" href="index.php?all=1#catalogue">Browse the catalogue</a>
                </div>
            </div>
        <?php else: ?>
            <div class="sf-wholesale-layout">
                <div>
                    <div class="sf-page-head">
                        <div>
                            <h1>Wholesale registration</h1>
                            <p>Create a simple wholesale account. Only name, phone and location are required.</p>
                        </div>
                    </div>

                    <div class="sf-form-card">
                        <div class="sf-form-card-head">
                            <h2>Create your account</h2>
                            <p>Fields marked with <span aria-hidden="true">*</span> are required.</p>
                        </div>

                        <?php if ($sf_error !== ''): ?>
                            <div class="sf-form-error" role="alert"><?php echo e($sf_error); ?></div>
                        <?php endif; ?>

                        <form method="post" action="wholesale-registration.php">
                            <?php echo csrf_field(); ?>
                            <?php echo form_token_field(); ?>
                            <div class="sf-form-grid">
                                <div class="sf-form-group sf-form-wide">
                                    <label for="sf-name">Name or business name <span aria-hidden="true">*</span></label>
                                    <input type="text" id="sf-name" name="name" maxlength="191" required
                                           autocomplete="name" value="<?php echo e($sf_form['name']); ?>">
                                    <small class="text-muted">You can sign in with this name later.</small>
                                </div>
                                <div class="sf-form-group">
                                    <label for="sf-phone">Phone number <span aria-hidden="true">*</span></label>
                                    <input type="tel" id="sf-phone" name="phone" maxlength="50" required
                                           autocomplete="tel" placeholder="+255 700 000 000" value="<?php echo e($sf_form['phone']); ?>">
                                </div>
                                <div class="sf-form-group">
                                    <label for="sf-location">Town, district or delivery area <span aria-hidden="true">*</span></label>
                                    <input type="text" id="sf-location" name="location" maxlength="255" required
                                           autocomplete="address-level2" value="<?php echo e($sf_form['location']); ?>">
                                </div>
                                <div class="sf-form-group sf-form-wide">
                                    <label for="sf-email">Email address <small class="text-muted">(optional)</small></label>
                                    <input type="email" id="sf-email" name="email" maxlength="191"
                                           autocomplete="email" placeholder="you@example.com" value="<?php echo e($sf_form['email']); ?>">
                                </div>
                                <div class="sf-form-group">
                                    <label for="sf-password">Password <span aria-hidden="true">*</span></label>
                                    <input type="password" id="sf-password" name="password" minlength="8" required
                                           autocomplete="new-password" placeholder="At least 8 characters">
                                </div>
                                <div class="sf-form-group">
                                    <label for="sf-password-confirm">Confirm password <span aria-hidden="true">*</span></label>
                                    <input type="password" id="sf-password-confirm" name="password_confirm" minlength="8" required
                                           autocomplete="new-password" placeholder="Repeat your password">
                                </div>
                            </div>

                            <div class="sf-form-actions">
                                <button type="submit" class="sf-btn sf-btn-primary">
                                    <i class="fa fa-paper-plane" aria-hidden="true"></i> Create wholesale account
                                </button>
                                <a class="sf-btn" href="login.php">Sign in</a>
                            </div>
                            <p class="sf-form-footnote">No payment is taken. You can add more business details from your account after signing in.</p>
                        </form>
                    </div>
                </div>

                <aside class="sf-wholesale-aside" aria-label="Wholesale benefits">
                    <div class="sf-aside-card">
                        <h2>Why register?</h2>
                        <ul class="sf-aside-list">
                            <li><i class="fa fa-check-circle" aria-hidden="true"></i> Volume pricing agreed with our team.</li>
                            <li><i class="fa fa-check-circle" aria-hidden="true"></i> Priority confirmation on repeat orders.</li>
                            <li><i class="fa fa-check-circle" aria-hidden="true"></i> Delivery arranged around your schedule.</li>
                            <li><i class="fa fa-check-circle" aria-hidden="true"></i> One account for all your buying locations.</li>
                        </ul>
                    </div>
                    <div class="sf-aside-card is-dark">
                        <h2>Need to talk first?</h2>
                        <p>Our team can answer questions about pricing and availability before you register.</p>
                        <?php if ($sf_contact_phone !== ''): ?>
                            <p><a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $sf_contact_phone)); ?>"><?php echo e($sf_contact_phone); ?></a></p>
                        <?php endif; ?>
                        <?php if ($sf_contact_email !== ''): ?>
                            <p><a href="mailto:<?php echo e($sf_contact_email); ?>"><?php echo e($sf_contact_email); ?></a></p>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/storefront-footer.php'; ?>
