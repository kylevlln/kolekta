<?php
/**
 * Kolekta — missed pickup reports.
 *   POST create        resident submits a report (optional photo)
 *   GET  my            resident's own reports
 *   GET  all           admin triage list (filters: status, zone_id, waste_type, date)
 *   GET  detail        full report + chronological thread (owner or admin)
 *   POST set_status    admin moves a report: pending -> investigating -> resolved -> archived
 *   POST resolve       alias for set_status(status=resolved) — kept for compatibility
 *   POST followup      resident reopens a resolved report with a follow-up message
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';

/* Allowed forward transitions. Archived is a terminal state. */
/* 'resolved -> reopened' is performed by the resident only (via followup). */
function report_allowed_next(string $current): array
{
    switch ($current) {
        case 'pending':
            return ['investigating', 'resolved', 'archived'];
        case 'investigating':
            return ['resolved', 'archived'];
        case 'resolved':
            return ['archived'];
        case 'reopened':
            return ['investigating', 'resolved', 'archived'];
        default:
            return [];
    }
}

function report_status_message(string $status): string
{
    return [
        'pending'       => 'Report submitted successfully.',
        'investigating' => 'Report marked as under investigation.',
        'resolved'      => 'Report marked as resolved.',
        'reopened'      => 'Report reopened. The barangay has been notified.',
        'archived'      => 'Report archived.',
    ][$status] ?? 'Report updated.';
}

/* Record one entry in the report thread. */
function report_add_event(int $reportId, int $actorId, string $actorRole, string $kind,
                          ?string $message, ?string $fromStatus, ?string $toStatus): void
{
    db()->prepare(
        'INSERT INTO report_events
            (report_id, actor_id, actor_role, kind, message, from_status, to_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$reportId, $actorId, $actorRole, $kind, $message !== '' ? $message : null,
                $fromStatus, $toStatus]);
}

/* Full report + thread row, shared by detail + admin workflows. */
function report_fetch_with_thread(int $reportId, bool $forResident): ?array
{
    $sql = 'SELECT r.*, z.name AS zone_name, z.code AS zone_code,
                   u.name AS resident_name
              FROM reports r
              JOIN zones z ON z.id = r.zone_id
              JOIN users u ON u.id = r.user_id
             WHERE r.id = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $ev = db()->prepare(
        'SELECT e.id, e.kind, e.message, e.from_status, e.to_status,
                e.actor_role, e.created_at, u.name AS actor_name
           FROM report_events e
           JOIN users u ON u.id = e.actor_id
          WHERE e.report_id = ?
          ORDER BY e.created_at ASC, e.id ASC'
    );
    $ev->execute([$reportId]);
    $events = $ev->fetchAll();

    unset($row['user_id']);
    if ($forResident) {
        unset($row['resolved_by']);
        $row['reopen_allowed'] = $row['status'] === 'resolved';
    }

    return [
        'report' => array_merge($row, [
            'waste_label'  => waste_label($row['waste_type']),
            'status_label' => status_labels()[$row['status']] ?? $row['status'],
        ]),
        'events' => array_map(function (array $e): array {
            $e['label'] = [
                'created'  => 'Report filed',
                'status'   => 'Barangay update',
                'followup' => 'Follow-up received',
            ][$e['kind']] ?? 'Update';
            return $e;
        }, $events),
    ];
}

/* Build a resident-facing notification message for an admin status update. */
function report_notification(string $status, array $row, bool $dispatch): array
{
    $titles = [
        'investigating' => 'Report under investigation',
        'resolved'      => 'Report resolved',
        'reopened'      => 'Report reopened',
        'archived'      => 'Report archived',
    ];
    $title = $titles[$status] ?? 'Report updated';
    $body  = $status === 'resolved'
        ? ($dispatch
            ? 'Your missed-pickup report at ' . $row['address'] . ' was received. A secondary collection has been arranged for ' . $row['zone_name'] . '.'
            : 'Your missed-pickup report at ' . $row['address'] . ' has been marked resolved by the barangay.')
        : 'Your missed-pickup report at ' . $row['address'] . ' has been updated: ' . strtolower($title) . '.';

    return [$title, $body];
}

/** Notify every admin (used when a resident reopens a report). */
function notify_all_admins(string $title, string $body, string $link): void
{
    $admins = db()->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, kind, title, body, link)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($admins as $a) {
        $stmt->execute([(int)$a['id'], 'report_update', $title, notification_body_fit($body), $link]);
    }
}

/* ------------------------------------------------------------ */
if ($action === 'create') {
    require_csrf();
    $user = current_user();
    if ($user['role'] !== 'resident') {
        fail('Only residents can file missed-pickup reports.', 403);
    }

    $in = input();
    $zoneId  = (int)($user['zone_id'] ?? 0);
    $wasteType = trim((string)($in['waste_type'] ?? 'mixed'));
    $address = trim((string)($in['address'] ?? ''));
    $notes   = trim((string)($in['notes'] ?? ''));

    if ($zoneId < 1) {
        fail('Your account is not assigned to a purok yet. Contact the barangay hall.');
    }
    $reportTypes = [];
    foreach (waste_types_rows(true) as $row) {
        $reportTypes[] = $row['code'];
    }
    if (!in_array($wasteType, $reportTypes, true)) {
        fail('Please select a valid waste type.');
    }
    if ($address === '') {
        fail('Please indicate the address of the missed pickup.');
    }
    if (mb_strlen($address) > 160) {
        fail('Address is too long.');
    }
    if (mb_strlen($notes) > 500) {
        fail('Notes are too long.');
    }

    $photoPath = null;
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];
        if ($file['size'] > MAX_PHOTO_BYTES) {
            fail('Photo exceeds the 5 MB limit.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            fail('Photo must be a JPEG, PNG, or WebP image.');
        }
        if (getimagesize($file['tmp_name']) === false) {
            fail('The uploaded file is not a valid image.');
        }

        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $name = 'rpt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0775, true);
        }
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $name)) {
            fail('Could not save the uploaded photo.');
        }
        $photoPath = UPLOAD_URL . $name;
    }

    $stmt = db()->prepare(
        'INSERT INTO reports (user_id, zone_id, address, waste_type, photo_path, notes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([(int)$user['id'], $zoneId, $address, $wasteType, $photoPath, $notes !== '' ? $notes : null]);
    $reportId = (int)db()->lastInsertId();

    report_add_event($reportId, (int)$user['id'], 'resident', 'created',
        $notes !== '' ? $notes : null, null, 'pending');

    ok([
        'id'         => $reportId,
        'status'     => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'message'    => 'Report submitted. The barangay has been notified.',
    ], 201);
}

/* ------------------------------------------------------------ */
if ($action === 'my') {
    $user = current_user();
    $stmt = db()->prepare(
        'SELECT r.id, r.address, r.waste_type, r.photo_path, r.notes,
                r.status, r.secondary_dispatch, r.resolution_note,
                r.created_at, r.resolved_at
           FROM reports r
          WHERE r.user_id = ?
          ORDER BY r.created_at DESC'
    );
    $stmt->execute([(int)$user['id']]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['waste_label']    = waste_label($row['waste_type']);
        $row['status_label']   = status_labels()[$row['status']] ?? $row['status'];
        $row['reopen_allowed'] = $row['status'] === 'resolved';
    }
    unset($row);

    ok($rows);
}

/* ------------------------------------------------------------ */
if ($action === 'all') {
    require_admin();

    $status    = trim((string)($_GET['status'] ?? ''));
    $zoneId    = (int)($_GET['zone_id'] ?? 0);
    $wasteType = trim((string)($_GET['waste_type'] ?? ''));
    $date      = trim((string)($_GET['date'] ?? ''));
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $perPage   = max(1, min(100, (int)($_GET['per_page'] ?? 25)));

    $sql = 'SELECT r.id, r.address, r.waste_type, r.photo_path, r.notes,
                   r.status, r.secondary_dispatch, r.resolution_note,
                   r.created_at, r.resolved_at,
                   (SELECT COUNT(*) FROM report_events e WHERE e.report_id = r.id) AS event_count,
                   u.name AS resident_name, u.phone AS resident_phone,
                   z.name AS zone_name, z.code AS zone_code
              FROM reports r
              JOIN users u ON u.id = r.user_id
              JOIN zones z ON z.id = r.zone_id
             WHERE 1 = 1';
    $args = [];

    $validStatuses = ['pending', 'investigating', 'resolved', 'reopened', 'archived'];
    if (in_array($status, $validStatuses, true)) {
        $sql .= ' AND r.status = ?';
        $args[] = $status;
    } elseif ($status === '' || $status === 'default') {
        // Default list: newest first, excluding resolved reports.
        $sql .= ' AND r.status <> ?';
        $args[] = 'resolved';
    }
    if ($zoneId > 0) {
        $sql .= ' AND r.zone_id = ?';
        $args[] = $zoneId;
    }
    if ($wasteType !== '' && waste_type_exists($wasteType, false)) {
        $sql .= ' AND r.waste_type = ?';
        $args[] = $wasteType;
    }
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $sql .= ' AND DATE(r.created_at) = ?';
        $args[] = $date;
    }
    $sql .= ' ORDER BY r.created_at DESC, r.id DESC';

    /* Total matching rows (same filters, no pagination). */
    $countSql = 'SELECT COUNT(*) FROM reports r WHERE 1 = 1';
    $countArgs = [];
    if (in_array($status, $validStatuses, true)) {
        $countSql .= ' AND r.status = ?';
        $countArgs[] = $status;
    } elseif ($status === '' || $status === 'default') {
        $countSql .= ' AND r.status <> ?';
        $countArgs[] = 'resolved';
    }
    if ($zoneId > 0) {
        $countSql .= ' AND r.zone_id = ?';
        $countArgs[] = $zoneId;
    }
    if ($wasteType !== '' && waste_type_exists($wasteType, false)) {
        $countSql .= ' AND r.waste_type = ?';
        $countArgs[] = $wasteType;
    }
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $countSql .= ' AND DATE(r.created_at) = ?';
        $countArgs[] = $date;
    }
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($countArgs);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = db()->prepare($sql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['waste_label']  = waste_label($row['waste_type']);
        $row['status_label'] = status_labels()[$row['status']] ?? $row['status'];
    }
    unset($row);

    $totalPages = max(1, (int)ceil($total / $perPage));
    ok([
        'data' => $rows,
        'meta' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
        ],
    ]);
}

/* ------------------------------------------------------------ */
if ($action === 'detail') {
    $user  = current_user();
    $in    = input();
    $reportId = (int)($in['id'] ?? ($_GET['id'] ?? 0));

    if ($reportId < 1) {
        fail('Invalid report.', 400);
    }

    if ($user['role'] === 'admin') {
        $data = report_fetch_with_thread($reportId, false);
    } else {
        $owner = db()->prepare('SELECT user_id FROM reports WHERE id = ?');
        $owner->execute([$reportId]);
        $ownerId = (int)($owner->fetchColumn() ?: 0);
        if ($ownerId !== (int)$user['id']) {
            fail('You can only view your own reports.', 403);
        }
        $data = report_fetch_with_thread($reportId, true);
    }

    if ($data === null) {
        fail('Report not found.', 404);
    }

    ok($data);
}

/* ------------------------------------------------------------ */
if ($action === 'followup') {
    require_csrf();
    $user = current_user();
    if ($user['role'] !== 'resident') {
        fail('Only residents can reopen reports.', 403);
    }

    $in = input();
    $reportId = (int)($in['id'] ?? 0);
    $message  = trim((string)($in['message'] ?? ''));

    if ($reportId < 1) {
        fail('Invalid report.', 400);
    }
    if ($message === '') {
        fail('Please tell the barangay why the pickup is still incomplete.');
    }
    if (mb_strlen($message) > 1000) {
        fail('Follow-up message is too long.');
    }

    $stmt = db()->prepare(
        'SELECT r.*, z.name AS zone_name, u.name AS resident_name
           FROM reports r
           JOIN zones z ON z.id = r.zone_id
           JOIN users u ON u.id = r.user_id
          WHERE r.id = ?'
    );
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();

    if (!$row) {
        fail('Report not found.', 404);
    }
    if ((int)$row['user_id'] !== (int)$user['id']) {
        fail('You can only reopen your own reports.', 403);
    }
    if ($row['status'] !== 'resolved') {
        fail('This report is not resolved, so it cannot be reopened right now.');
    }

    db()->prepare(
        'UPDATE reports
            SET status = ?, resolved_at = NULL, resolved_by = NULL
          WHERE id = ?'
    )->execute(['reopened', $reportId]);

    report_add_event($reportId, (int)$user['id'], 'resident', 'followup',
        $message, 'resolved', 'reopened');

    notify_all_admins(
        'Report reopened by resident',
        $row['resident_name'] . ' (Purok ' . $row['zone_name'] . ') reopened their missed-pickup report at ' . $row['address'] . ': "' . $message . '"',
        'admin.php#reports'
    );

    ok([
        'message'      => 'Report reopened. The barangay has been notified.',
        'id'           => $reportId,
        'status'       => 'reopened',
        'status_label' => status_labels()['reopened'],
    ]);
}

/* ------------------------------------------------------------ */
if ($action === 'set_status') {
    require_csrf();
    $admin = require_admin();
    $in = input();

    $reportId = (int)($in['id'] ?? 0);
    $status   = trim((string)($in['status'] ?? ''));
    $note     = trim((string)($in['note'] ?? ''));
    $dispatch = !empty($in['dispatch']) ? 1 : 0;

    $report = db()->prepare(
        'SELECT r.*, z.name AS zone_name, u.name AS resident_name
           FROM reports r
           JOIN zones z ON z.id = r.zone_id
           JOIN users u ON u.id = r.user_id
          WHERE r.id = ?'
    );
    $report->execute([$reportId]);
    $row = $report->fetch();

    if (!$row) {
        fail('Report not found.', 404);
    }
    if (mb_strlen($note) > 500) {
        fail('Resolution note is too long.');
    }
    if ($in['status'] === 'resolved') {
        /* legacy resolve — always allowed from pending/investigating */
        $status = 'resolved';
    }
    if (!in_array($status, ['investigating', 'resolved', 'archived'], true)) {
        fail('Invalid target status.');
    }
    if (!in_array($status, report_allowed_next($row['status']), true)) {
        fail('That status change is not allowed for this report.');
    }
    if ($status === 'investigating' && !in_array($row['status'], ['pending', 'reopened'], true)) {
        fail('Only pending or reopened reports can be moved to Investigating.');
    }

    $resolvedAt = in_array($status, ['resolved', 'archived'], true) ? date('Y-m-d H:i:s') : null;

    /* The secondary-dispatch checkbox is resolved-only; other transitions must
       leave the flag untouched instead of silently clearing it. */
    $updateSql = 'UPDATE reports SET status = ?, resolved_at = COALESCE(?, resolved_at),
                          resolved_by = ?, resolution_note = ?';
    $updateArgs = [$status, $resolvedAt, (int)$admin['id'], $note !== '' ? $note : null];
    if ($status === 'resolved') {
        $updateSql .= ', secondary_dispatch = ?';
        $updateArgs[] = $dispatch;
    }
    $updateSql .= ' WHERE id = ?';
    $updateArgs[] = $reportId;

    db()->prepare($updateSql)->execute($updateArgs);

    report_add_event($reportId, (int)$admin['id'], 'admin', 'status',
        $note !== '' ? $note : null, $row['status'], $status);

    // Notify the resident about the outcome.
    [$notifTitle, $notifBody] = report_notification($status, $row, (bool)$dispatch);

    db()->prepare(
        'INSERT INTO notifications (user_id, kind, title, body, link)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([(int)$row['user_id'], 'report_update', $notifTitle, notification_body_fit($notifBody), 'user.php']);

    ok([
        'message'      => report_status_message($status),
        'id'           => $reportId,
        'status'       => $status,
        'status_label' => status_labels()[$status] ?? $status,
    ]);
}

/* ------------------------------------------------------------ */
if ($action === 'resolve') {
    require_csrf();
    $admin = require_admin();
    $in = input();
    $in['status'] = 'resolved';
    /* Delegate to the workflow above. */
    /* (kept as a thin alias; the shared logic lives in set_status) */

    $reportId = (int)($in['id'] ?? 0);
    $dispatch = !empty($in['dispatch']) ? 1 : 0;
    $note     = trim((string)($in['note'] ?? ''));

    $report = db()->prepare(
        'SELECT r.*, z.name AS zone_name
           FROM reports r JOIN zones z ON z.id = r.zone_id
          WHERE r.id = ?'
    );
    $report->execute([$reportId]);
    $row = $report->fetch();

    if (!$row) {
        fail('Report not found.', 404);
    }
    if ($row['status'] === 'archived') {
        fail('That report is archived.');
    }
    if ($row['status'] === 'resolved') {
        fail('That report is already resolved.');
    }
    if (mb_strlen($note) > 500) {
        fail('Resolution note is too long.');
    }

    db()->prepare(
        'UPDATE reports
            SET status = ?, secondary_dispatch = ?, resolved_at = NOW(),
                resolved_by = ?, resolution_note = ?
          WHERE id = ?'
    )->execute(['resolved', $dispatch, (int)$admin['id'], $note !== '' ? $note : null, $reportId]);

    report_add_event($reportId, (int)$admin['id'], 'admin', 'status',
        $note !== '' ? $note : null, $row['status'], 'resolved');

    $title = $dispatch ? 'Secondary collection scheduled' : 'Report resolved';
    $body  = $dispatch
        ? 'Your missed-pickup report at ' . $row['address'] . ' was received. A secondary collection has been arranged for ' . $row['zone_name'] . '.'
        : 'Your missed-pickup report at ' . $row['address'] . ' has been marked resolved by the barangay.';

    db()->prepare(
        'INSERT INTO notifications (user_id, kind, title, body, link)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([(int)$row['user_id'], 'report_update', $title, notification_body_fit($body), 'user.php']);

    ok(['message' => 'Report marked as resolved.', 'id' => $reportId, 'status' => 'resolved']);
}

fail('Unknown report action.', 404);