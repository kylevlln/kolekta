<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden: this installer is CLI-only.";
    exit;
}

/**
 * Kolekta — migration: report follow-up / reopen support.
 *   php db/migrate-followups.php
 *
 * Adds the 'reopened' status to reports, creates the report_events thread
 * table, and back-fills history events for existing reports. Safe to run more
 * than once (idempotent).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BASE_DIR', dirname(__DIR__));
require BASE_DIR . '/api/config.php';

try {
  $pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]
  );
} catch (PDOException $e) {
  fwrite(STDERR, "Cannot connect to MySQL: " . $e->getMessage() . "\n");
  exit(1);
}

echo "Migrating " . DB_NAME . " for report follow-ups…\n";

/* 1. Extend the reports.status enum. ---------------------------------- */
$pdo->exec(
  "ALTER TABLE reports
     MODIFY status ENUM('pending','investigating','resolved','reopened','archived')
     NOT NULL DEFAULT 'pending'"
);
echo "  [ok] reports.status now supports 'reopened'\n";

/* 2. Create the report_events thread table if missing. ---------------- */
$hasEvents = (int)$pdo->query(
  "SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = " . $pdo->quote(DB_NAME) . " AND TABLE_NAME = 'report_events'"
)->fetchColumn();

if (!$hasEvents) {
  $pdo->exec(
    "CREATE TABLE report_events (
       id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
       report_id   INT UNSIGNED NOT NULL,
       actor_id    INT UNSIGNED NOT NULL,
       actor_role  ENUM('resident','admin') NOT NULL,
       kind        ENUM('created','status','followup') NOT NULL DEFAULT 'status',
       message     VARCHAR(1000) NULL,
       from_status VARCHAR(20) NULL,
       to_status   VARCHAR(20) NULL,
       created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       KEY idx_ev_report (report_id, created_at, id),
       CONSTRAINT fk_ev_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
       CONSTRAINT fk_ev_actor  FOREIGN KEY (actor_id) REFERENCES users (id)
     ) ENGINE = InnoDB"
  );
  echo "  [ok] report_events table created\n";
} else {
  echo "  [ok] report_events table already exists\n";
}

/* 3. Back-fill a 'created' event for reports lacking any history. ------ */
$done = (int)$pdo->query(
  'SELECT COUNT(*) FROM report_events WHERE kind = \'created\''
)->fetchColumn();

if ($done > 0) {
  echo "  [skip] events already back-filled for existing reports\n";
  exit(0);
}

$reports = $pdo->query(
  "SELECT r.id, r.user_id, r.notes, r.status, r.resolution_note,
          r.resolved_by, r.created_at, r.resolved_at
     FROM reports r
    ORDER BY r.id"
)->fetchAll();

$ins = $pdo->prepare(
  'INSERT INTO report_events (report_id, actor_id, actor_role, kind, message, from_status, to_status, created_at)
   VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

$count = 0;
foreach ($reports as $r) {
  $ins->execute([
    (int)$r['id'],
    (int)$r['user_id'],
    'resident',
    'created',
    $r['notes'] ?? null,
    null,
    'pending',
    $r['created_at'],
  ]);
  $count++;

  if ($r['status'] !== 'pending' && $r['status'] !== 'reopened') {
    $ins->execute([
      (int)$r['id'],
      (int)($r['resolved_by'] ?? 1),
      'admin',
      'status',
      $r['resolution_note'] ?? null,
      'pending',
      $r['status'],
      $r['resolved_at'] ?? $r['created_at'],
    ]);
    $count++;
  }
}

echo "  contributed $count event rows across " . count($reports) . " reports.\n";
echo "Done.\n";