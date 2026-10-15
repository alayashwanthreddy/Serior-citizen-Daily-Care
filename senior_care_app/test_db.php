<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

try {
    $stmt = $pdo->query("SELECT DATABASE() AS database_name");
    $result = $stmt->fetch();

    if ($result && $result['database_name'] === 'senior_care') {
        echo '<h1 style="color: green;">Database connected successfully!</h1>';
        echo '<p>Database: ' .
            htmlspecialchars($result['database_name'], ENT_QUOTES, 'UTF-8') .
            '</p>';
    } else {
        echo '<h1>Unexpected database selected.</h1>';
    }
} catch (PDOException $e) {
    error_log('Database test failed: ' . $e->getMessage());
    http_response_code(500);
    echo '<h1>Database test failed. Check the PHP error log.</h1>';
}