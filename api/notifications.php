<?php
/**
 * Kolekta — resident notification inbox.
 *   GET  list     own notifications
 *   GET  count    unread count
 *   POST mark_read  mark one/many/all as read
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $user = current_user();

    $archived = !empty($_GET['archived']);
    $sql = 'SELECT id, kind, title, body, link, is_read, is_archived, created_at
              FROM notifications
             WHERE user_id = ?';
    if ($archived) {
        $sql .= ' AND is_archived = 1';
    } else {
        $sql .= ' AND is_archived = 0';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 30';

    $stmt = db()->prepare($sql);
    $stmt->execute([(int)$user['id']]);
    ok($stmt->fetchAll());
}

if ($action === 'count') {
    $user = current_user();
    $stmt = db()->prepare('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_archived = 0 AND is_read = 0');
    $stmt->execute([(int)$user['id']]);
    ok(['unread' => (int)$stmt->fetch()['n']]);
}

if ($action === 'mark_read') {
    require_csrf();
    $user = current_user();

    $in  = input();
    $ids = $in['ids'] ?? null;

    if ($ids !== null) {
        if (!is_array($ids) || count($ids) === 0) {
            fail('Invalid notification selection.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                fail('Invalid notification selection.');
            }
        }
        if (!array_filter(array_map('intval', $ids), fn ($v) => $v > 0)) {
            fail('Invalid notification selection.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "UPDATE notifications
                SET is_read = 1, is_archived = 1, archived_at = NOW()
              WHERE user_id = ? AND id IN ($placeholders)"
        );
        $args = array_merge([(int)$user['id']], array_map('intval', $ids));
        $stmt->execute($args);
    } else {
        db()->prepare('UPDATE notifications SET is_read = 1, is_archived = 1, archived_at = NOW() WHERE user_id = ?')
           ->execute([(int)$user['id']]);
    }

    ok(['updated' => true]);
}

if ($action === 'restore') {
    require_csrf();
    $user = current_user();
    $in = input();
    $ids = $in['ids'] ?? [];
    if (!is_array($ids) || !count($ids)) {
        fail('Invalid notification selection.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE notifications SET is_archived = 0, archived_at = NULL, is_read = 0 WHERE user_id = ? AND id IN ($placeholders)")
       ->execute(array_merge([(int)$user['id']], array_map('intval', $ids)));
    ok(['restored' => true]);
}

if ($action === 'delete') {
    require_csrf();
    $user = current_user();

    $in = input();
    $ids = $in['ids'] ?? null;

    if (is_array($ids) && count($ids) > 0) {
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit((string)$id))) {
                fail('Invalid notification selection.');
            }
        }
        $clean = array_values(array_filter(array_map('intval', $ids), fn (int $v): bool => $v > 0));
        if (!count($clean)) {
            fail('Invalid notification selection.');
        }
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        db()->prepare(
            "DELETE FROM notifications WHERE user_id = ? AND id IN ($placeholders)"
        )->execute(array_merge([(int)$user['id']], $clean));
    } elseif (!empty($in['all'])) {
        db()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([(int)$user['id']]);
    } else {
        fail('Invalid notification request.');
    }

    ok(['deleted' => true]);
}

fail('Unknown notification action.', 404);