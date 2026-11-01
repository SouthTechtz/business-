<?php
/** Local-only HTTP test bootstrap: load an existing user session without login writes. */
$userId = (int)($_GET['__stage1_user'] ?? 1);
if (!in_array($userId, [1, 20], true)) $userId = 1;
$sessionId = 'stage1-reporting-' . $userId;
session_id($sessionId);
$savePath = session_save_path();
if ($savePath === '') $savePath = sys_get_temp_dir();
$sessionData = 'user_id|i:' . $userId . ';tenant_id|i:1;';
file_put_contents($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sessionId, $sessionData, LOCK_EX);
