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

// Require an authenticated user.
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

// Only POST is accepted.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
}

// Read JSON request.
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

require_once __DIR__ . '/../config/db.php';

try {
    $pdo->beginTransaction();

    // Confirm that this task belongs to a person
    // managed by the signed-in account.
    $check = $pdo->prepare(
        'SELECT t.id
         FROM tasks t
         INNER JOIN people p ON p.id = t.person_id
         WHERE t.id = :task_id
           AND p.user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );

    $check->execute([
        'task_id' => $taskId,
        'user_id' => $userId
    ]);

    if (!$check->fetch()) {
        $pdo->rollBack();

        jsonResponse(404, [
            'success' => false,
            'message' => 'Task not found.'
        ]);
    }

    // Delete dependent records first.
    $deleteDays = $pdo->prepare(
        'DELETE FROM task_days WHERE task_id = :task_id'
    );

    $deleteDays->execute([
        'task_id' => $taskId
    ]);

    $deleteLogs = $pdo->prepare(
        'DELETE FROM task_logs WHERE task_id = :task_id'
    );

    $deleteLogs->execute([
        'task_id' => $taskId
    ]);

    // Delete the task itself.
    $deleteTask = $pdo->prepare(
        'DELETE FROM tasks WHERE id = :task_id'
    );

    $deleteTask->execute([
        'task_id' => $taskId
    ]);

    $pdo->commit();

    jsonResponse(200, [
        'success' => true,
        'message' => 'Activity deleted successfully.',
        'task_id' => (int) $taskId
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Delete task API error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Could not delete the activity.'
    ]);
}