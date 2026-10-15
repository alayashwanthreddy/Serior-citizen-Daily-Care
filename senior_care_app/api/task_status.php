<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Start or resume the user's session.
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

// Require an authenticated user.
if (
    !isset($_SESSION['user']['id']) ||
    !is_numeric($_SESSION['user']['id'])
) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Please sign in first.'
    ]);
}

$userId = (int) $_SESSION['user']['id'];

// Only POST requests are accepted.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
}

// Read JSON request data.
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

// Validate the task ID.
$taskId = filter_var(
    $input['task_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($taskId === false || $taskId === null) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'A valid task_id is required.'
    ]);
}

// Only these two status values are supported by task_logs.
$status = $input['status'] ?? '';

if (!in_array($status, ['done', 'skip'], true)) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Status must be done or skip.'
    ]);
}

// Connect to the existing database.
require_once __DIR__ . '/../config/db.php';

try {
    // Verify that the task belongs to a person
    // associated with the authenticated account.
    $ownerCheck = $pdo->prepare(
        'SELECT t.id
         FROM tasks t
         INNER JOIN people p ON p.id = t.person_id
         WHERE t.id = :task_id
           AND p.user_id = :user_id
         LIMIT 1'
    );

    $ownerCheck->execute([
        'task_id' => $taskId,
        'user_id' => $userId
    ]);

    if (!$ownerCheck->fetch()) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Task not found.'
        ]);
    }

    // Update today's existing log or create a new one.
    $pdo->beginTransaction();

    $findLog = $pdo->prepare(
        'SELECT id
         FROM task_logs
         WHERE task_id = :task_id
           AND log_date = CURDATE()
         ORDER BY id DESC
         LIMIT 1'
    );

    $findLog->execute([
        'task_id' => $taskId
    ]);

    $existingLog = $findLog->fetch();

    if ($existingLog) {
        // If today's log already exists, update its status.
        $updateLog = $pdo->prepare(
            'UPDATE task_logs
             SET status = :status
             WHERE id = :log_id'
        );

        $updateLog->execute([
            'status' => $status,
            'log_id' => (int) $existingLog['id']
        ]);

    } else {
        // Otherwise, add today's first log for this task.
        $insertLog = $pdo->prepare(
            'INSERT INTO task_logs
                (task_id, log_date, status)
             VALUES
                (:task_id, CURDATE(), :status)'
        );

        $insertLog->execute([
            'task_id' => $taskId,
            'status' => $status
        ]);
    }

    $pdo->commit();

    jsonResponse(200, [
        'success' => true,
        'message' => 'Task status saved successfully.',
        'task_id' => (int) $taskId,
        'status' => $status,
        'log_date' => date('Y-m-d')
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Task status database error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Could not save the task status.'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Task status API error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}