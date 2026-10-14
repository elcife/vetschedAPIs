<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';

$stdout = fopen('php://stdout', 'w');
fwrite($stdout, 'Super-admin username: ');
$username = trim((string) fgets(STDIN));
fwrite($stdout, 'Super-admin display name: ');
$displayName = trim((string) fgets(STDIN));
$password = getenv('VETSCHED_BOOTSTRAP_PASSWORD');
putenv('VETSCHED_BOOTSTRAP_PASSWORD');

if ($username === '' || $displayName === '' || strlen($username) > 50 || strlen($displayName) > 100) {
    fwrite(STDERR, "Username and display name are required (maximum 50 and 100 characters).\n");
    exit(1);
}
if ($password === false || strlen($password) < 10) {
    fwrite(STDERR, "Set VETSCHED_BOOTSTRAP_PASSWORD to a password of at least 10 characters, then rerun this script.\n");
    exit(1);
}

try {
    $conn->beginTransaction();
    $conn->query("SELECT pg_advisory_xact_lock(741852963)");

    $existingSuperAdmin = $conn->query("SELECT 1 FROM admin_account WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
    if ($existingSuperAdmin) {
        $conn->rollBack();
        fwrite(STDERR, "A super-admin already exists; bootstrap is disabled.\n");
        exit(1);
    }

    $existingUsername = $conn->prepare('SELECT 1 FROM admin_account WHERE LOWER(username) = LOWER(:username) LIMIT 1');
    $existingUsername->execute([':username' => $username]);
    if ($existingUsername->fetchColumn()) {
        $conn->rollBack();
        fwrite(STDERR, "That username is already in use.\n");
        exit(1);
    }

    $insert = $conn->prepare("INSERT INTO admin_account (username, password_hash, display_name, role, is_active) VALUES (:username, :password_hash, :display_name, 'super_admin', TRUE)");
    $insert->execute([
        ':username' => $username,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':display_name' => $displayName,
    ]);
    $conn->commit();
    fwrite(STDOUT, "Super-admin account created successfully.\n");
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, "Could not create the super-admin account: " . $error->getMessage() . "\n");
    exit(1);
}
