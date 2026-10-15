
<?php

// Database configuration
$host = '127.0.0.1';
$dbname = 'senior_care';
$username = 'root';
$password = ''; // Default XAMPP password is often empty.

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    error_log(
        'Senior Care database connection failed: ' . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Database connection failed. Check your MySQL credentials '
        . 'and make sure MySQL is running.'
    );
}