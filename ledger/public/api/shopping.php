<?php
/**
 * POST /api/shopping.php — tick an errand off.
 *
 * Session-authenticated and CSRF-checked: this one is called by the page, not by
 * a background automation. It answers both a fetch() and an ordinary form post,
 * so the list still works with scripting off.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
ledger_boot();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// No redirect-to-login for an API call; a signed-out fetch should fail cleanly.
if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

csrf_check();

$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'bought');
$item   = $id ? q1('SELECT * FROM shopping_items WHERE id = ?', [$id]) : null;

if ($item === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'No such item.']);
    exit;
}

if ($action === 'bought') {
    qx('UPDATE shopping_items SET bought_at = datetime("now") WHERE id = ?', [$id]);
    log_change('shopping_items', $id, 'bought_at', null, date('c'), 'update');
} elseif ($action === 'unbuy') {
    qx('UPDATE shopping_items SET bought_at = NULL WHERE id = ?', [$id]);
    log_change('shopping_items', $id, 'bought_at', $item['bought_at'], null, 'update');
} else {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

// A plain form post gets a redirect; fetch() gets JSON.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    header('Location: ../errands.php');
    exit;
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'id' => $id, 'action' => $action]);
