<?php
require_once __DIR__ . '/storefront.php';

$sf_tenant = storefront_tenant();
if (!$sf_tenant) {
    http_response_code(503);
    exit('The storefront is not configured.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php#newsletter');
}

verify_csrf();
if (!form_token_valid()) {
    flash_set('danger', 'This subscription was already submitted.');
    redirect('index.php#newsletter');
}

$sf_email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
if ($sf_email === '' || mb_strlen($sf_email) > 191 || !filter_var($sf_email, FILTER_VALIDATE_EMAIL)) {
    flash_set('danger', 'Enter a valid email address.');
    redirect('index.php#newsletter');
}

$sf_tenant_id = (int)$sf_tenant['tenant_id'];
$stmt = $conn->prepare(
    "INSERT INTO storefront_subscribers (tenant_id, email, is_active)
     VALUES (?, ?, 1)
     ON DUPLICATE KEY UPDATE is_active = 1, updated_at = CURRENT_TIMESTAMP"
);
if ($stmt) {
    $stmt->bind_param('is', $sf_tenant_id, $sf_email);
    $stmt->execute();
    $stmt->close();
    consume_form_token();
    flash_set('success', 'You are subscribed to store updates.');
} else {
    flash_set('danger', 'We could not save your subscription. Please try again later.');
}

redirect('index.php#newsletter');
