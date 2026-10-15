<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Start or resume the authenticated session.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Standard JSON response helper.
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

if ($userId < 1) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Invalid user session.'
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

// Connect to the existing database.
require_once __DIR__ . '/../config/db.php';

try {

    // GET: Read today's water intake.
    if ($method === 'GET') {

        $personId = filter_var(
            $_GET['person_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($personId === false || $personId === null) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'A valid person_id is required.'
            ]);
        }

        // Ensure the person belongs to the signed-in account.
        $ownerCheck = $pdo->prepare(
            'SELECT id
             FROM people
             WHERE id = :person_id
               AND user_id = :user_id
             LIMIT 1'
        );

        $ownerCheck->execute([
            'person_id' => $personId,
            'user_id' => $userId
        ]);

        if (!$ownerCheck->fetch()) {
            jsonResponse(404, [
                'success' => false,
                'message' => 'Person not found.'
            ]);
        }

        // Retrieve today's latest water record.
        $stmt = $pdo->prepare(
            'SELECT id, glasses, log_date
             FROM water_logs
             WHERE person_id = :person_id
               AND log_date = CURDATE()
             ORDER BY id DESC
             LIMIT 1'
        );

        $stmt->execute([
            'person_id' => $personId
        ]);

        $record = $stmt->fetch();

        $glasses = $record
            ? (int) $record['glasses']
            : 0;

        $dateStmt = $pdo->query(
            'SELECT CURDATE() AS log_date'
        );

        $today = $dateStmt->fetch()['log_date'];

        jsonResponse(200, [
            'success' => true,
            'person_id' => (int) $personId,
            'log_date' => $today,
            'glasses' => $glasses,
            'daily_goal' => 8
        ]);
    }

    // POST: Save today's water intake.
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

    $glasses = filter_var(
        $input['glasses'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($personId === false || $personId === null) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'A valid person_id is required.'
        ]);
    }

    if (
        $glasses === false ||
        $glasses === null ||
        $glasses < 0 ||
        $glasses > 8
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Glasses must be a whole number from 0 to 8.'
        ]);
    }

    // Verify ownership before changing any record.
    $ownerCheck = $pdo->prepare(
        'SELECT id
         FROM people
         WHERE id = :person_id
           AND user_id = :user_id
         LIMIT 1'
    );

    $ownerCheck->execute([
        'person_id' => $personId,
        'user_id' => $userId
    ]);

    if (!$ownerCheck->fetch()) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Person not found.'
        ]);
    }

    // Update today's record or insert a new one.
    $pdo->beginTransaction();

    $findRecord = $pdo->prepare(
        'SELECT id
         FROM water_logs
         WHERE person_id = :person_id
           AND log_date = CURDATE()
         ORDER BY id DESC
         LIMIT 1'
    );

    $findRecord->execute([
        'person_id' => $personId
    ]);

    $existingRecord = $findRecord->fetch();

    if ($existingRecord) {

        $update = $pdo->prepare(
            'UPDATE water_logs
             SET glasses = :glasses
             WHERE id = :id'
        );

        $update->execute([
            'glasses' => $glasses,
            'id' => (int) $existingRecord['id']
        ]);

    } else {

        $insert = $pdo->prepare(
            'INSERT INTO water_logs
                (person_id, log_date, glasses)
             VALUES
                (:person_id, CURDATE(), :glasses)'
        );

        $insert->execute([
            'person_id' => $personId,
            'glasses' => $glasses
        ]);
    }

    $pdo->commit();

    $dateStmt = $pdo->query(
        'SELECT CURDATE() AS log_date'
    );

    $today = $dateStmt->fetch()['log_date'];

    jsonResponse(200, [
        'success' => true,
        'message' => 'Water intake saved successfully.',
        'person_id' => (int) $personId,
        'log_date' => $today,
        'glasses' => $glasses,
        'daily_goal' => 8
    ]);

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Water API database error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Could not save or retrieve water intake.'
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Water API error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}