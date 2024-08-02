<?php

$_ENV['DATABASE_URL'] = 'postgresql://postgres:b12345678@localhost:5432/mydb';

$databaseUrl = parse_url($_ENV['DATABASE_URL']);

$username = $databaseUrl['user'];
$password = $databaseUrl['pass'];
$host = $databaseUrl['host'];
$port = $databaseUrl['port'];
$dbName = ltrim($databaseUrl['path'], '/');

echo "Username: $username\n";
echo "Password: $password\n";
echo "Host: $host\n";
echo "Port: $port\n";
echo "Database Name: $dbName\n";

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    echo "Подключение к базе данных успешно!";
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}