<?php
require_once __DIR__ . '/includes/config.php';
$user = requireLogin();
$db   = db();

header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);

if ($id > 0) {
    // Mark single notification as read (only if it belongs to current user)
    $s = $db->prepare("UPDATE notifikasi SET dibaca=1 WHERE id=? AND user_id=?");
    $s->bind_param('ii', $id, $user['id']);
    $s->execute();
} else {
    // Mark all notifications as read
    $s = $db->prepare("UPDATE notifikasi SET dibaca=1 WHERE user_id=?");
    $s->bind_param('i', $user['id']);
    $s->execute();
}

echo json_encode(['ok' => true]);
