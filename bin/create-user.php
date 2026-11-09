<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$email = strtolower(trim(getenv('SMARTSTORE_BOOTSTRAP_EMAIL') ?: ''));
$name = trim(getenv('SMARTSTORE_BOOTSTRAP_NAME') ?: '');
$role = getenv('SMARTSTORE_BOOTSTRAP_ROLE') ?: 'admin';
$password = getenv('SMARTSTORE_BOOTSTRAP_PASSWORD');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || !in_array($role, ['admin', 'staff'], true)
    || !is_string($password) || strlen($password) < 12 || strlen($password) > 1024) {
    fwrite(STDERR, "Set SMARTSTORE_BOOTSTRAP_EMAIL, SMARTSTORE_BOOTSTRAP_NAME, SMARTSTORE_BOOTSTRAP_ROLE (admin/staff), and a password of at least 12 characters.\n");
    exit(2);
}

$db = getDBConnection();
$stmt = $db->prepare('INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, ?)');
$stmt->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT), $role]);
fwrite(STDOUT, "Created {$role} account.\n");
