<?php

session_start();

use DI\Container;
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

$envPath = __DIR__ . '/../.env';

if (file_exists($envPath)) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

$container = new Container();
$container->set('renderer', fn() => new PhpRenderer(__DIR__ . '/../templates'));

$container->set('pdo', function () {
    $databaseUrl = getenv('DATABASE_URL') ? parse_url(getenv('DATABASE_URL')) : parse_url($_ENV['DATABASE_URL']);

    if (!$databaseUrl) {
        throw new RuntimeException('DATABASE_URL not set or incorrectly formatted');
    }

    $host = $databaseUrl['host'] ?? null;
    $port = $databaseUrl['port'] ?? '5432';
    $dbname = isset($databaseUrl['path']) ? ltrim($databaseUrl['path'], '/') : null;
    $user = $databaseUrl['user'] ?? null;
    $pass = $databaseUrl['pass'] ?? null;

    if (!$host || !$dbname || !$user || !$pass) {
        throw new RuntimeException('Incomplete database connection details');
    }

    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", $host, $port, $dbname);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException("Failed to connect to the database: " . $e->getMessage());
    }

    initializeDatabase($pdo, __DIR__ . '/../' . 'database.sql');

    return $pdo;
});

function initializeDatabase(PDO $pdo, string $sqlFilePath): void
{
    if (!file_exists($sqlFilePath)) {
        throw new RuntimeException("SQL file not found: " . $sqlFilePath);
    }

    $sql = file_get_contents($sqlFilePath);

    if ($sql === false) {
        throw new RuntimeException("Failed to read SQL file: " . $sqlFilePath);
    }

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        throw new RuntimeException("Failed to initialize the database: " . $e->getMessage());
    }
}

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
    $parsedBody = $request->getParsedBody();
    $url = '';

    if (is_array($parsedBody) && isset($parsedBody['url']['name'])) {
        $url = trim($parsedBody['url']['name']);
    }

    $v = new V(['url' => $url]);

    $isEmpty = empty($url);

    // Валидация: обязательность заполнения URL
    $v->rule('required', 'url')->message('URL не должен быть пустым');
    if (!$isEmpty) {
        // Валидация: корректность URL и длина
        $v->rule('url', 'url')->message('Некорректный URL')
          ->rule('lengthMax', 'url', 255)->message('URL не должен превышать 255 символов');
    }

    if ($v->validate()) {
        // Подключение к базе данных
        $pdo = $container->get('pdo');
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = ?');
        $stmt->execute([$url]);
        $existingUrl = $stmt->fetch();

        $flash = $container->get('flash');
        if (!$existingUrl) {
            // Добавление нового URL в базу данных
            $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (?, ?)');
            $stmt->execute([$url, Carbon::now()]);
            $flash->addMessage('success', 'Страница успешно добавлена');
            return $response->withHeader('Location', "/urls/{$pdo->lastInsertId()}")->withStatus(302);
        } else {
            // Если URL уже существует
            $flash->addMessage('info', 'Страница уже существует');
            return $response->withHeader('Location', "/urls/{$existingUrl['id']}")->withStatus(302);
        }
    }

    // Обработка ошибок валидации
    $errors = $v->errors();
    $errorMessages = [];
    $incorrectUrlError = false;
    $emptyUrlError = false;

    if (is_array($errors)) {
        foreach ($errors as $fieldErrors) {
            foreach ($fieldErrors as $error) {
                if ($error === 'Некорректный URL' && !$isEmpty) {
                    $incorrectUrlError = true;
                } elseif ($error === 'URL не должен быть пустым') {
                    $emptyUrlError = true;
                } else {
                    $errorMessages[] = $error;
                }
            }
        }
    }

    // Рендеринг страницы с отображением сообщений
    $renderer = $container->get('renderer');
    return $renderer->render($response->withStatus(422), 'index.phtml', [
        'errors' => $errorMessages,
        'incorrectUrlError' => $incorrectUrlError,
        'emptyUrlError' => $emptyUrlError,
        'entered_url' => $url
    ]);
});

$app->post('/urls/{url_id}/checks', function (Request $request, Response $response, $args) use ($container) {
    $pdo = $container->get('pdo');
    $httpClient = $container->get('httpClient');
    $urlId = $args['url_id'];

    $stmt = $pdo->prepare('SELECT name FROM urls WHERE id = ?');
    $stmt->execute([$urlId]);
    $url = $stmt->fetchColumn();

    $flash = $container->get('flash');

    try {
        $res = $httpClient->get($url, ['timeout' => 10]);

        if ($res->getStatusCode() >= 400) {
            // Если статус код >= 400, считаем это ошибкой
            throw new \Exception('Сайт вернул ошибку: ' . $res->getStatusCode());
        }

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

        $flash->addMessage('success', 'Страница успешно проверена');
    } catch (\GuzzleHttp\Exception\ConnectException $e) {
        $flash->addMessage('error', 'Ошибка при проверке: не удалось подключиться к сайту.');
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $flash->addMessage('error', 'Ошибка при проверке: ' . $e->getMessage());
    } catch (\Exception $e) {
        // Ловим любые другие ошибки, включая ошибки статуса ответа
        $flash->addMessage('error', 'Произошла ошибка при проверке сайта: ' . $e->getMessage());
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

    $response->getBody()->write('URL не найден');
    return $response->withStatus(404);
});

$app->run();
