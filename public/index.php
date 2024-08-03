<?php

$autoloadPath1 = __DIR__ . '/../../autoload.php';
$autoloadPath2 = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadPath1)) {
    require_once $autoloadPath1;
} else {
    require_once $autoloadPath2;
}

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

// Загрузите переменные окружения из файла .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Создайте контейнер
$container = new Container();

// Настройте рендерер как сервис в контейнере
$container->set('renderer', function () {
    return new PhpRenderer(__DIR__ . '/../templates');
});

// Настройте PDO как сервис в контейнере
$container->set('pdo', function () {
    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    $username = $databaseUrl['user'];
    $password = $databaseUrl['pass'];
    $host = $databaseUrl['host'];
    $port = $databaseUrl['port'];
    $dbName = ltrim($databaseUrl['path'], '/');

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
});

// Настройте Guzzle HTTP Client как сервис в контейнере
$container->set('httpClient', function () {
    return new Client();
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

    if (isset($urlData['url']['name']) && is_string($urlData['url']['name'])) {
        $url = trim($urlData['url']['name']);
    } else {
        $url = '';
    }

    error_log('URL перед валидацией: ' . $url);

    $v = new V(['url' => $url]);
    $v->rule('required', 'url')->message('URL обязателен');
    $v->rule('url', 'url')->message('Неверный URL');
    $v->rule('lengthMax', 'url', 255)->message('URL не должен превышать 255 символов');

    if ($v->validate()) {
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = ?');
        $stmt->execute([$url]);
        $existingUrl = $stmt->fetch();

        if (!$existingUrl) {
            // Получаем содержимое страницы
            $html = file_get_contents($url);
            $document = new Document($html);

            // Извлечение данных SEO
            $h1 = optional($document->first('h1'))->text();
            $title = optional($document->first('title'))->text();
            $metaDescription = optional($document->first('meta[name="description"]'))->getAttribute('content');

            // Логирование данных SEO
            error_log('h1: ' . $h1);
            error_log('title: ' . $title);
            error_log('meta description: ' . $metaDescription);

            // Добавление URL и данных SEO в базу данных
            $stmt = $pdo->prepare('INSERT INTO urls (name, created_at, h1, title, meta_description) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$url, Carbon::now(), $h1, $title, $metaDescription]);
            $urlId = $pdo->lastInsertId();

            return $response->withHeader('Location', "/urls/$urlId")->withStatus(302);
        } else {
            return $response->withHeader('Location', "/urls/{$existingUrl['id']}")->withStatus(302);
        }
    } else {
        $errors = $v->errors();
        $errorMessages = [];
        foreach ($errors as $fieldErrors) {
            $errorMessages = array_merge($errorMessages, $fieldErrors);
        }
        $flashMessage = 'Ошибка валидации: ' . implode('; ', $errorMessages);
    }

    $response->getBody()->write($flashMessage);
    return $response;
});

$app->get('/urls', function (Request $request, Response $response, $args) use ($container) {
    $pdo = $container->get('pdo');

    // Запрос для получения всех URL и данных о последней проверке
    $stmt = $pdo->query('
        SELECT urls.*, 
               MAX(url_checks.created_at) as last_check, 
               (SELECT status_code 
                FROM url_checks 
                WHERE url_checks.url_id = urls.id 
                ORDER BY created_at DESC 
                LIMIT 1) as last_status_code
        FROM urls 
        LEFT JOIN url_checks ON urls.id = url_checks.url_id
        GROUP BY urls.id 
        ORDER BY urls.created_at DESC');

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
        $stmt = $pdo->prepare('SELECT * FROM url_checks WHERE url_id = ? ORDER BY created_at DESC');
        $stmt->execute([$args['id']]);
        $checks = $stmt->fetchAll();

        $args['url'] = $url;
        $args['checks'] = $checks;
        $renderer = $container->get('renderer');
        return $renderer->render($response, 'url.phtml', $args);
    } else {
        return $response->withStatus(404)->write('URL не найден');
    }
});

$app->post('/urls/{url_id}/checks', function (Request $request, Response $response, $args) use ($container) {
    $pdo = $container->get('pdo');
    $httpClient = $container->get('httpClient');
    $url_id = $args['url_id'];

    $stmt = $pdo->prepare('SELECT name FROM urls WHERE id = ?');
    $stmt->execute([$url_id]);
    $url = $stmt->fetchColumn();

    if ($url) {
        try {
            $res = $httpClient->request('GET', $url);
            $status_code = $res->getStatusCode();
            $body = (string) $res->getBody();
            $dom = new \DOMDocument();
            @$dom->loadHTML($body);
            $h1 = $dom->getElementsByTagName('h1')->item(0)->nodeValue ?? '';
            $title = $dom->getElementsByTagName('title')->item(0)->nodeValue ?? '';
            $description = '';
            $metas = $dom->getElementsByTagName('meta');
            foreach ($metas as $meta) {
                if ($meta->getAttribute('name') === 'description') {
                    $description = $meta->getAttribute('content');
                    break;
                }
            }

            $stmt = $pdo->prepare('INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$url_id, $status_code, $h1, $title, $description, Carbon::now()]);
        } catch (\Exception $e) {
            $response = $response->withHeader('Location', "/urls/{$url_id}")->withStatus(302);
            $response->getBody()->write('Ошибка при проверке сайта');
            return $response;
        }
    }

    return $response->withHeader('Location', "/urls/{$url_id}")->withStatus(302);
});

// Добавление маршрута для url
$app->get('/url', function (Request $request, Response $response, $args) use ($container) {
    $response->getBody()->write('Маршрут /url успешно обработан');
    return $response;
});

// Добавление маршрута для url/id
$app->get('/url/{id}', function (Request $request, Response $response, $args) use ($container) {
    $id = $args['id'];
    $response->getBody()->write("Маршрут /url/{$id} успешно обработан");
    return $response;
});

$app->run();