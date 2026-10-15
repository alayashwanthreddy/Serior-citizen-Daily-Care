<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Configure the session cookie before starting the session.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Return a JSON response and stop execution.
function jsonResponse(int $status, array $body): void
{
    http_response_code($status);

    echo json_encode($body);

    exit;
}

// Accept only POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
}

// Read JSON from the request body.
$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);
}

// Read login credentials.
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');

// Validate the submitted information.
if (
    strlen($email) > 254 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    $password === '' ||
    strlen($password) > 4096
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Please enter a valid email and password.'
    ]);
}

// Connect to the existing MySQL database.
require_once __DIR__ . '/../config/db.php';

try {
    // Retrieve the account by email.
    $stmt = $pdo->prepare(
        'SELECT id, name, email, password_hash, role
         FROM users
         WHERE email = :email
         LIMIT 1'
    );

    $stmt->execute([
        'email' => $email
    ]);

    $user = $stmt->fetch();

    // Use a generic error so we do not reveal
    // whether an email address is registered.
    if (
        !$user ||
        !password_verify($password, $user['password_hash'])
    ) {
        jsonResponse(401, [
            'success' => false,
            'message' => 'Invalid email or password.'
        ]);
    }

    // Prevent session fixation after successful authentication.
    session_regenerate_id(true);

    // Store only necessary account information in the session.
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role']
    ];

    // Never return the password hash to the browser.
    jsonResponse(200, [
        'success' => true,
        'message' => 'Login successful.',
        'user' => $_SESSION['user']
    ]);

} catch (PDOException $e) {
    error_log(
        'Login database error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Login failed because of a server error.'
    ]);
}