<?php
/**
 * Kolekta — migrate notifications inbox (auto-archive support).
 *   php db/migrate-notifications-archive.php   (idempotent)
 * CLI-only.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI-only';
    exit;
}
require __DIR__ . '/../api/config.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL");
$idx = $pdo->query("SHOW INDEX FROM notifications WHERE Key_name='idx_notif_archived'")->fetch();
if (!$idx) {
    $pdo->exec('ALTER TABLE notifications ADD KEY idx_notif_archived (user_id, is_archived, created_at)');
}
echo "Up to date.\n";
exit(0);
