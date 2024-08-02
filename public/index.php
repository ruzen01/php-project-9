<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$autoloadPath1 = __DIR__ . '/../../autoload.php';
$autoloadPath2 = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadPath1)) {
    require_once $autoloadPath1;
} else {
    require_once $autoloadPath2;
}

$app = AppFactory::create();

// Добавление базового маршрута
$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello, World!");
    return $response;
});

// Пример маршрута с параметром
$app->get('/hello/{name}', function (Request $request, Response $response, $args) {
    $name = $args['name'];
    $response->getBody()->write("Hello, $name!");
    return $response;
});

// Запуск приложения
$app->run();
