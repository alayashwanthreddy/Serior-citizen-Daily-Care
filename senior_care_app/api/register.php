<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Configure a secure session cookie for local development
// and production HTTPS deployments.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Send a JSON response and stop execution.
function jsonResponse(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

// Registration only accepts POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
}

// Read the JSON request body.
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

// Read and validate the submitted information.
$name = trim((string) ($input['name'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
$role = (string) ($input['role'] ?? '');

$nameLength = function_exists('mb_strlen')
    ? mb_strlen($name, 'UTF-8')
    : strlen($name);

if ($nameLength < 2 || $nameLength > 100) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Name must contain 2 to 100 characters.'
    ]);
}

if (
    strlen($email) > 254 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Enter a valid email address.'
    ]);
}

if (strlen($password) < 8 || strlen($password) > 4096) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Password must be at least 8 characters long.'
    ]);
}

// These values must match the existing MySQL ENUM.
$allowedRoles = ['senior', 'guardian'];

if (!in_array($role, $allowedRoles, true)) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Choose senior or guardian as your role.'
    ]);
}

// Load the existing PDO connection.
require_once __DIR__ . '/../config/db.php';

try {
    // Check whether this email is already registered.
    $check = $pdo->prepare(
        'SELECT id FROM users WHERE email = :email LIMIT 1'
    );

    $check->execute(['email' => $email]);

    if ($check->fetch()) {
        jsonResponse(409, [
            'success' => false,
            'message' => 'This email is already registered.'
        ]);
    }

    // Never store a plain-text password.
    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    // Create the account and, for senior users, their own profile.
    // Both inserts succeed together or are rolled back together.
    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        'INSERT INTO users
            (name, email, password_hash, role)
        VALUES
            (:name, :email, :password_hash, :role)'
    );

    $insert->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => $role
    ]);

    $userId = (int) $pdo->lastInsertId();

    // Senior accounts automatically receive their own person record.
    if ($role === 'senior') {
        $personInsert = $pdo->prepare(
            'INSERT INTO people (user_id, name)
            VALUES (:user_id, :name)'
        );

        $personInsert->execute([
            'user_id' => $userId,
            'name' => $name
        ]);
    }

    $pdo->commit();

    // Start an authenticated session after successful registration.
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => $userId,
        'name' => $name,
        'email' => $email,
        'role' => $role
    ];

    jsonResponse(201, [
        'success' => true,
        'message' => 'Account created successfully.',
        'user' => $_SESSION['user']
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(
        'Registration database error: ' . $e->getMessage()
    );

    // Handles a duplicate email if a UNIQUE constraint exists.
    if ($e->getCode() === '23000') {
        jsonResponse(409, [
            'success' => false,
            'message' => 'This account may already exist.'
        ]);
    }

    jsonResponse(500, [
        'success' => false,
        'message' => 'Registration failed. Check the server error log.'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(
        'Registration error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}