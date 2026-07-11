<?php
declare(strict_types=1);

// Salin file ini menjadi config.php di hosting, lalu isi credential database hosting.
// Jangan upload config.php berisi credential ke tempat publik jika hosting mendukung folder private.

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = 'localhost';
    $database = 'NAMA_DATABASE';
    $username = 'USERNAME_DATABASE';
    $password = 'PASSWORD_DATABASE';

    $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
