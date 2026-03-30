<?php
/**
 * Returns a singleton PDO connection.
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host    = getenv('DB_HOST') ?: 'localhost';
    $port    = getenv('DB_PORT') ?: '3306';
    $dbname  = getenv('DB_NAME') ?: '';
    $user    = getenv('DB_USER') ?: '';
    $pass    = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $utcOffset = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTime()) / 3600;
    $tzSign    = $utcOffset >= 0 ? '+' : '-';
    $tzStr     = $tzSign . sprintf('%02d:00', abs((int)$utcOffset));

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '$tzStr'",
    ]);

    return $pdo;
}
