<?php

use DI\Container;
use DiDom\Document;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Valitron\Validator as V;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Slim\Flash\Messages;

require_once file_exists(__DIR__ . '/../../autoload.php') ? __DIR__ . '/../../autoload.php' : __DIR__ . '/../vendor/autoload.php';

// Загружаем переменные окружения
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$container = new Container();

$container->set('renderer', fn() => new PhpRenderer(__DIR__ . '/../templates'));

$container->set('pdo', function () {
    // Парсинг переменной окружения DATABASE_URL
    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", $databaseUrl['host'], $databaseUrl['port'], ltrim($databaseUrl['path'], '/'));
    return new PDO($dsn, $databaseUrl['user'], $databaseUrl['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
});

$container->set('httpClient', fn() => new Client());
$container->set('flash', fn() => new Messages());

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->add(function ($request, $handler) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $response = $handler->handle($request);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    return $response;
});

$app->get('/', function (Request $request, Response $response) use ($container) {
    $flash = $container->get('flash');
    $renderer = $container->get('renderer');
    return $renderer->render($response, 'index.phtml', [
        'flashMessages' => $flash->getMessages()
    ]);
});

$app->post('/urls', function (Request $request, Response $response) use ($container) {
    $url = trim($request->getParsedBody()['url']['name'] ?? '');
    $v = new V(['url' => $url]);
    $v->rule('required', 'url')->message('URL обязателен')
      ->rule('url', 'url')->message('Некорректный URL')
      ->rule('lengthMax', 'url', 255)->message('URL не должен превышать 255 символов');

    if ($v->validate()) {
        $pdo = $container->get('pdo');
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = ?');
        $stmt->execute([$url]);
        $existingUrl = $stmt->fetch();

        $flash = $container->get('flash');
        if (!$existingUrl) {
            $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (?, ?)');
            $stmt->execute([$url, Carbon::now()]);
            $flash->addMessage('success', 'Страница успешно добавлена');
            return $response->withHeader('Location', "/urls/{$pdo->lastInsertId()}")->withStatus(302);
        } else {
            $flash->addMessage('info', 'Страница уже существует');
            return $response->withHeader('Location', "/urls/{$existingUrl['id']}")->withStatus(302);
        }
    }

    $flash = $container->get('flash');
    $errors = $v->errors();
    $errorMessages = [];
    foreach ($errors as $fieldErrors) {
        $errorMessages = array_merge($errorMessages, $fieldErrors);
    }
    $flash->addMessage('error', implode('; ', $errorMessages));
    return $response->withHeader('Location', '/')->withStatus(302)->withHeader('X-URL', $url);
});

$app->post('/urls/{url_id}/checks', function (Request $request, Response $response, $args) use ($container) {
    $pdo = $container->get('pdo');
    $httpClient = $container->get('httpClient');
    $urlId = $args['url_id'];

    $stmt = $pdo->prepare('SELECT name FROM urls WHERE id = ?');
    $stmt->execute([$urlId]);
    $url = $stmt->fetchColumn();

    if ($url) {
        try {
            $res = $httpClient->get($url);
            $dom = new \DOMDocument();
            @$dom->loadHTML((string) $res->getBody());

            $h1 = $dom->getElementsByTagName('h1')->item(0)->nodeValue ?? '';
            $title = $dom->getElementsByTagName('title')->item(0)->nodeValue ?? '';

            $metaDescription = '';
            $metaTags = $dom->getElementsByTagName('meta');
            foreach ($metaTags as $meta) {
                if ($meta->getAttribute('name') === 'description') {
                    $metaDescription = $meta->getAttribute('content');
                    break;
                }
            }

            $stmt = $pdo->prepare('INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $urlId,
                $res->getStatusCode(),
                $h1,
                $title,
                $metaDescription,
                Carbon::now()
            ]);

            $flash = $container->get('flash');
            $flash->addMessage('success', 'Страница успешно проверена');
        } catch (\Exception $e) {
            $flash = $container->get('flash');
            $flash->addMessage('error', 'Ошибка при проверке сайта');
        }
    }

    return $response->withHeader('Location', "/urls/{$urlId}")->withStatus(302);
});

$app->get('/urls', function (Request $request, Response $response) use ($container) {
    $pdo = $container->get('pdo');
    $stmt = $pdo->query('
        SELECT urls.id, urls.name, 
               url_checks.created_at as last_check_at,
               url_checks.status_code as last_status_code 
        FROM urls
        LEFT JOIN url_checks ON urls.id = url_checks.url_id 
        AND url_checks.created_at = (
            SELECT MAX(created_at) 
            FROM url_checks 
            WHERE url_checks.url_id = urls.id
        )
        ORDER BY urls.id DESC
    ');
    $renderer = $container->get('renderer');
    return $renderer->render($response, 'urls.phtml', ['urls' => $stmt->fetchAll()]);
});

$app->get('/urls/{id}', function (Request $request, Response $response, $args) use ($container) {
    $pdo = $container->get('pdo');
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([$args['id']]);
    $url = $stmt->fetch();

    if ($url) {
        $stmt = $pdo->prepare('SELECT * FROM url_checks WHERE url_id = ? ORDER BY created_at DESC');
        $stmt->execute([$args['id']]);
        $renderer = $container->get('renderer');
        return $renderer->render($response, 'url.phtml', [
            'url' => $url,
            'checks' => $stmt->fetchAll(),
            'flashMessages' => $container->get('flash')->getMessages()
        ]);
    }

    return $response->withStatus(404)->write('URL не найден');
});

$app->run();