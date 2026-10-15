
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

require_once __DIR__ . '/../config/db.php';

try {

    // =========================================
    // GET: Retrieve a person's daily timetable
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

        // Verify that this person belongs to the signed-in user.
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

        // Load today's status along with each task.
        $stmt = $pdo->prepare(
            'SELECT
                t.id,
                t.person_id,
                t.title,
                t.category,
                t.task_time,
                t.created_at,
                (
                    SELECT tl.status
                    FROM task_logs tl
                    WHERE tl.task_id = t.id
                      AND tl.log_date = CURDATE()
                    ORDER BY tl.id DESC
                    LIMIT 1
                ) AS today_status
             FROM tasks t
             WHERE t.person_id = :person_id
             ORDER BY t.task_time, t.id'
        );

        $stmt->execute([
            'person_id' => $personId
        ]);

        $tasks = $stmt->fetchAll();

        // Initialize the days array for every task.
        foreach ($tasks as &$task) {
            $task['id'] = (int) $task['id'];
            $task['person_id'] = (int) $task['person_id'];
            $task['task_time'] = substr($task['task_time'], 0, 5);
            $task['days'] = [];
        }
        unset($task);

        // Load all repeat days for these tasks.
        $daysStmt = $pdo->prepare(
            'SELECT td.task_id, td.day_of_week
             FROM task_days td
             INNER JOIN tasks t ON t.id = td.task_id
             WHERE t.person_id = :person_id
             ORDER BY td.task_id, td.day_of_week'
        );

        $daysStmt->execute([
            'person_id' => $personId
        ]);

        $daysByTask = [];

        foreach ($daysStmt->fetchAll() as $row) {
            $taskId = (int) $row['task_id'];

            $daysByTask[$taskId][] = (int) $row['day_of_week'];
        }

        foreach ($tasks as &$task) {
            $task['days'] = $daysByTask[$task['id']] ?? [];
        }
        unset($task);

        jsonResponse(200, [
            'success' => true,
            'tasks' => $tasks
        ]);
    }

    // =========================================
    // POST: Create a task and its repeat days
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
    $category = (string) ($input['category'] ?? '');
    $taskTime = (string) ($input['task_time'] ?? '');
    $days = $input['days'] ?? null;

    if ($personId === false || $personId === null) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'A valid person_id is required.'
        ]);
    }

    if (
        $title === '' ||
        strlen($title) > 150
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Task title must contain 1 to 150 characters.'
        ]);
    }

    // These categories match the current frontend.
    $allowedCategories = [
        'medication',
        'meal',
        'water',
        'exercise',
        'rest',
        'other'
    ];

    if (!in_array($category, $allowedCategories, true)) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Invalid task category.'
        ]);
    }

    if (!preg_match(
        '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',
        $taskTime
    )) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Task time must use HH:MM format.'
        ]);
    }

    if (!is_array($days) || count($days) < 1 || count($days) > 7) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Select at least one repeat day.'
        ]);
    }

    $validatedDays = [];

    foreach ($days as $day) {
        if (
            !is_int($day) ||
            $day < 0 ||
            $day > 6
        ) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'Each repeat day must be between 0 and 6.'
            ]);
        }

        if (in_array($day, $validatedDays, true)) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'Repeat days must not contain duplicates.'
            ]);
        }

        $validatedDays[] = $day;
    }

    sort($validatedDays);

    // Make sure the person belongs to this account.
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

    // Insert the task and its repeat days atomically.
    $pdo->beginTransaction();

    $insertTask = $pdo->prepare(
        'INSERT INTO tasks
            (person_id, title, category, task_time)
         VALUES
            (:person_id, :title, :category, :task_time)'
    );

    $insertTask->execute([
        'person_id' => $personId,
        'title' => $title,
        'category' => $category,
        'task_time' => $taskTime
    ]);

    $taskId = (int) $pdo->lastInsertId();

    $insertDay = $pdo->prepare(
        'INSERT INTO task_days
            (task_id, day_of_week)
         VALUES
            (:task_id, :day_of_week)'
    );

    foreach ($validatedDays as $day) {
        $insertDay->execute([
            'task_id' => $taskId,
            'day_of_week' => $day
        ]);
    }

    $pdo->commit();

    jsonResponse(201, [
        'success' => true,
        'message' => 'Task created successfully.',
        'task' => [
            'id' => $taskId,
            'person_id' => $personId,
            'title' => $title,
            'category' => $category,
            'task_time' => $taskTime,
            'days' => $validatedDays,
            'today_status' => null
        ]
    ]);

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Tasks API database error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'A database error occurred while processing tasks.'
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Tasks API error: ' . $e->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}
