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
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
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
    // =========================================
    // GET: Retrieve appointments for one person
    // =========================================
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

        // Verify that the person belongs to this user.
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

        $stmt = $pdo->prepare(
            'SELECT
                id,
                person_id,
                title,
                appointment_datetime,
                notes,
                created_at
             FROM appointments
             WHERE person_id = :person_id
             ORDER BY appointment_datetime ASC, id ASC'
        );

        $stmt->execute([
            'person_id' => $personId
        ]);

        $appointments = $stmt->fetchAll();

        foreach ($appointments as &$appointment) {
            $appointment['id'] = (int) $appointment['id'];
            $appointment['person_id'] =
                (int) $appointment['person_id'];
        }
        unset($appointment);

        jsonResponse(200, [
            'success' => true,
            'appointments' => $appointments
        ]);
    }

    // =========================================
    // POST: Create an appointment
    // =========================================
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

    $title = trim((string) ($input['title'] ?? ''));
    $rawDateTime = trim(
        (string) ($input['appointment_datetime'] ?? '')
    );
    $notes = trim((string) ($input['notes'] ?? ''));

    if ($personId === false || $personId === null) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'A valid person_id is required.'
        ]);
    }

    $titleLength = function_exists('mb_strlen')
        ? mb_strlen($title, 'UTF-8')
        : strlen($title);

    if ($titleLength < 1 || $titleLength > 150) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Appointment title must contain 1 to 150 characters.'
        ]);
    }

    // Accept a datetime-local value: YYYY-MM-DDTHH:MM.
    if (!preg_match(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
        $rawDateTime
    )) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Enter a valid appointment date and time.'
        ]);
    }

    $dateTime = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $rawDateTime
    );

    $dateErrors = DateTimeImmutable::getLastErrors();

    if (
        $dateTime === false ||
        (
            $dateErrors !== false &&
            (
                $dateErrors['warning_count'] > 0 ||
                $dateErrors['error_count'] > 0
            )
        ) ||
        $dateTime->format('Y-m-d\TH:i') !== $rawDateTime
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'The appointment date or time is invalid.'
        ]);
    }

    if (strlen($notes) > 5000) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Appointment notes cannot exceed 5000 bytes.'
        ]);
    }

    // Verify ownership before inserting anything.
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

    $formattedDateTime = $dateTime->format('Y-m-d H:i:s');

    $insert = $pdo->prepare(
        'INSERT INTO appointments
            (person_id, title, appointment_datetime, notes)
         VALUES
            (:person_id, :title, :appointment_datetime, :notes)'
    );

    $insert->execute([
        'person_id' => $personId,
        'title' => $title,
        'appointment_datetime' => $formattedDateTime,
        'notes' => $notes === '' ? null : $notes
    ]);

    $appointmentId = (int) $pdo->lastInsertId();

    jsonResponse(201, [
        'success' => true,
        'message' => 'Appointment saved successfully.',
        'appointment' => [
            'id' => $appointmentId,
            'person_id' => (int) $personId,
            'title' => $title,
            'appointment_datetime' => $formattedDateTime,
            'notes' => $notes === '' ? null : $notes
        ]
    ]);

} catch (Throwable $e) {
    error_log(
        'Appointments API error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Could not process the appointment request.'
    ]);
}