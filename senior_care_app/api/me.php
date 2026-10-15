<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Only GET requests are allowed.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);

    exit;
}

// Return the current authenticated user, if any.
if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Not signed in.'
    ]);

    exit;
}

echo json_encode([
    'success' => true,
    'user' => [
        'id' => (int) $_SESSION['user']['id'],
        'name' => $_SESSION['user']['name'],
        'email' => $_SESSION['user']['email'],
        'role' => $_SESSION['user']['role']
    ]
]);