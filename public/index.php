<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use DI\Container;

$autoloadPath1 = __DIR__ . '/../../autoload.php';
$autoloadPath2 = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadPath1)) {
    require_once $autoloadPath1;
} else {
    require_once $autoloadPath2;
}

$app = AppFactory::create();

// Создание контейнера
$container = new Container();
AppFactory::setContainer($container);

// Настройка PHP-View
$container->set('renderer', function () {
    return new PhpRenderer('../templates');
});

$app = AppFactory::create();

// Маршрут для главной страницы
$app->get('/', function (Request $request, Response $response, $args) {
    $renderer = $this->get('renderer');
    return $renderer->render($response, 'index.phtml', $args);
});

// Запуск приложения
$app->run();

