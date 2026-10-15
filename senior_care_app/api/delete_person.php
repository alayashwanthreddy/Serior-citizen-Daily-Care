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
// Only guardians may remove people and their associated records.
if (($_SESSION['user']['role'] ?? '') !== 'guardian') {
    jsonResponse(403, [
        'success' => false,
        'message' => 'Only guardian accounts can remove people.'
    ]);
}
// Accept POST requests only.
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

$personId = filter_var(
    $input['person_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($personId === false || $personId === null) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'A valid person_id is required.'
    ]);
}

require_once __DIR__ . '/../config/db.php';

try {
    $pdo->beginTransaction();

    // Verify ownership and lock the person's record.
    $check = $pdo->prepare(
        'SELECT id
         FROM people
         WHERE id = :person_id
           AND user_id = :user_id
         FOR UPDATE'
    );

    $check->execute([
        'person_id' => $personId,
        'user_id' => $userId
    ]);

    if (!$check->fetch()) {
        $pdo->rollBack();

        jsonResponse(404, [
            'success' => false,
            'message' => 'Person not found or access denied.'
        ]);
    }

    // Delete task completion history for this person's tasks.
    $deleteLogs = $pdo->prepare(
        'DELETE tl
         FROM task_logs AS tl
         INNER JOIN tasks AS t ON t.id = tl.task_id
         WHERE t.person_id = :person_id'
    );

    $deleteLogs->execute([
        'person_id' => $personId
    ]);

    // Delete repeat-day records.
    $deleteDays = $pdo->prepare(
        'DELETE td
         FROM task_days AS td
         INNER JOIN tasks AS t ON t.id = td.task_id
         WHERE t.person_id = :person_id'
    );

    $deleteDays->execute([
        'person_id' => $personId
    ]);

    // Delete the person's activities.
    $deleteTasks = $pdo->prepare(
        'DELETE FROM tasks
         WHERE person_id = :person_id'
    );

    $deleteTasks->execute([
        'person_id' => $personId
    ]);

    // Delete water tracking history.
    $deleteWater = $pdo->prepare(
        'DELETE FROM water_logs
         WHERE person_id = :person_id'
    );

    $deleteWater->execute([
        'person_id' => $personId
    ]);

    // Delete only the person record owned by this user.
    $deletePerson = $pdo->prepare(
        'DELETE FROM people
         WHERE id = :person_id
           AND user_id = :user_id'
    );

    $deletePerson->execute([
        'person_id' => $personId,
        'user_id' => $userId
    ]);

    if ($deletePerson->rowCount() !== 1) {
        throw new RuntimeException(
            'The person record could not be deleted.'
        );
    }

    $pdo->commit();

    jsonResponse(200, [
        'success' => true,
        'message' => 'Person and associated records deleted successfully.',
        'person_id' => (int) $personId
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Delete person API error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Could not delete the person. No changes were committed.'
    ]);
}