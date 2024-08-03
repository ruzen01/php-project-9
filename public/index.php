<?php

require __DIR__ . '/../vendor/autoload.php';

use DI\Container;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Valitron\Validator as V;
use Carbon\Carbon;

// Загрузите переменные окружения из файла .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Создайте контейнер
$container = new Container();

// Настройте рендерер как сервис в контейнере
$container->set('renderer', function() {
    return new PhpRenderer('../templates');
});

// Настройте PDO как сервис в контейнере
$container->set('pdo', function() {
    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    $username = $databaseUrl['user'];
    $password = $databaseUrl['pass'];
    $host = $databaseUrl['host'];
    $port = $databaseUrl['port'];
    $dbName = ltrim($databaseUrl['path'], '/');

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
});

// Создайте приложение с контейнером
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) use ($container) {
    $renderer = $container->get('renderer');
    return $renderer->render($response, 'index.phtml', $args);
});

$app->post('/urls', function (Request $request, Response $response, $args) use ($container) {
    $pdo = $container->get('pdo');
    $urlData = $request->getParsedBody();
    $url = $urlData['url'] ?? [];

    $v = new Valitron\Validator($url);
    $v->rule('required', 'name')->message('URL обязателен');
    $v->rule('url', 'name')->message('Неверный URL');
    $v->rule('lengthMax', 'name', 255)->message('URL не должен превышать 255 символов');

    if ($v->validate()) {
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = ?');
        $stmt->execute([$url['name']]);
        $existingUrl = $stmt->fetch();

        if (!$existingUrl) {
            $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (?, ?)');
            $stmt->execute([$url['name'], Carbon::now()]);
            $flashMessage = 'URL успешно добавлен!';
        } else {
            $flashMessage = 'URL уже существует!';
        }
    } else {
        $flashMessage = 'Ошибка валидации';
    }

    $response->getBody()->write($flashMessage);
    return $response;
});

$app->get('/urls', function (Request $request, Response $response, $args) use ($container) {
    $pdo = $container->get('pdo');
    $stmt = $pdo->query('SELECT * FROM urls ORDER BY created_at DESC');
    $urls = $stmt->fetchAll();

    $args['urls'] = $urls;
    $renderer = $container->get('renderer');
    return $renderer->render($response, 'urls.phtml', $args);
});

$app->get('/urls/{id}', function (Request $request, Response $response, $args) use ($container) {
    $pdo = $container->get('pdo');
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([$args['id']]);
    $url = $stmt->fetch();

    if ($url) {
        $args['url'] = $url;
        $renderer = $container->get('renderer');
        return $renderer->render($response, 'url.phtml', $args);
    } else {
        return $response->withStatus(404)->write('URL не найден');
    }
});

// Добавление маршрута для /url
$app->get('/url', function (Request $request, Response $response, $args) use ($container) {
    $response->getBody()->write("Маршрут /url успешно обработан");
    return $response;
});

// Добавление маршрута для /url/{id}
$app->get('/url/{id}', function (Request $request, Response $response, $args) use ($container) {
    $id = $args['id'];
    $response->getBody()->write("Маршрут /url/{$id} успешно обработан");
    return $response;
});

$app->run();
