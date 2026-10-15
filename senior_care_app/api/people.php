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

function jsonResponse(int $status, array $body): void
{
    http_response_code($status);

    echo json_encode($body);

    exit;
}

// Verify the signed-in user.
$userId = filter_var(
    $_SESSION['user']['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($userId === false || $userId === null) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Please sign in first.'
    ]);
}

$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Only GET and POST requests are allowed.'
    ]);
}

require_once __DIR__ . '/../config/db.php';

try {

    // GET: Retrieve people belonging to this account.
    if ($method === 'GET') {

        $stmt = $pdo->prepare(
            'SELECT id, name, created_at
             FROM people
             WHERE user_id = :user_id
             ORDER BY id ASC'
        );

        $stmt->execute([
            'user_id' => $userId
        ]);

        jsonResponse(200, [
            'success' => true,
            'people' => $stmt->fetchAll()
        ]);
    }

    // POST: Only guardians can add people.

    if (($_SESSION['user']['role'] ?? '') !== 'guardian') {
        jsonResponse(403, [
            'success' => false,
            'message' => 'Only guardian accounts can add people.'
        ]);
    }
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

    $name = trim((string) ($input['name'] ?? ''));

    $nameLength = function_exists('mb_strlen')
        ? mb_strlen($name, 'UTF-8')
        : strlen($name);

    if ($nameLength < 2 || $nameLength > 100) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Name must contain 2 to 100 characters.'
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO people (user_id, name)
         VALUES (:user_id, :name)'
    );

    $stmt->execute([
        'user_id' => $userId,
        'name' => $name
    ]);

    $personId = (int) $pdo->lastInsertId();

    jsonResponse(201, [
        'success' => true,
        'message' => 'Person added successfully.',
        'person' => [
            'id' => $personId,
            'user_id' => (int) $userId,
            'name' => $name
        ]
    ]);

} catch (PDOException $e) {

    error_log(
        'People API database error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Could not process the people request.'
    ]);

} catch (Throwable $e) {

    error_log(
        'People API error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}