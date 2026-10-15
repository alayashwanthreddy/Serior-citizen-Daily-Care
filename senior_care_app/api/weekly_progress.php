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

// Only authenticated users can access progress data.
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);
}

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

require_once __DIR__ . '/../config/db.php';

try {
    // Verify that this person belongs to the signed-in account.
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

    // Use MySQL's current date for consistency with task logs.
    $dateRow = $pdo->query(
        'SELECT CURDATE() AS today'
    )->fetch();

    $today = new DateTimeImmutable($dateRow['today']);
    $weeklyData = [];

    // Calculate the last seven days, oldest first.
    for ($offset = 6; $offset >= 0; $offset--) {
        $date = $today->sub(
            new DateInterval('P' . $offset . 'D')
        );

        $dateString = $date->format('Y-m-d');
        $dayOfWeek = (int) $date->format('w');

        $stmt = $pdo->prepare(
            'SELECT
                COUNT(DISTINCT t.id) AS scheduled,
                COUNT(DISTINCT CASE
                    WHEN tl.status = \'done\' THEN t.id
                END) AS done_count,
                COUNT(DISTINCT CASE
                    WHEN tl.status = \'skip\' THEN t.id
                END) AS skipped_count
             FROM tasks t
             INNER JOIN task_days td
                ON td.task_id = t.id
               AND td.day_of_week = :day_of_week
             LEFT JOIN task_logs tl
                ON tl.task_id = t.id
               AND tl.log_date = :log_date
             WHERE t.person_id = :person_id'
        );

        $stmt->execute([
            'day_of_week' => $dayOfWeek,
            'log_date' => $dateString,
            'person_id' => $personId
        ]);

        $row = $stmt->fetch();

        $scheduled = (int) $row['scheduled'];
        $done = (int) $row['done_count'];
        $skipped = (int) $row['skipped_count'];

        $percentage = $scheduled > 0
            ? (int) round(($done / $scheduled) * 100)
            : 0;

        $weeklyData[] = [
            'date' => $dateString,
            'day' => $date->format('D'),
            'scheduled' => $scheduled,
            'done' => $done,
            'skipped' => $skipped,
            'percentage' => $percentage
        ];
    }

    jsonResponse(200, [
        'success' => true,
        'person_id' => (int) $personId,
        'days' => $weeklyData
    ]);

} catch (Throwable $e) {
    error_log(
        'Weekly progress API error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Could not load weekly progress.'
    ]);
}