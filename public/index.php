<?php

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Valitron\Validator as V;
use Carbon\Carbon;

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

$app = AppFactory::create();
$container = $app->getContainer();

$container['renderer'] = function () {
    return new PhpRenderer('../templates');
};

$app->get('/', function (Request $request, Response $response, $args) {
    $renderer = $this->get('renderer');
    return $renderer->render($response, 'index.phtml', $args);
});

$app->post('/urls', function (Request $request, Response $response, $args) use ($pdo) {
    $urlData = $request->getParsedBodyParam('url', []);
    $v = new Valitron\Validator($urlData);
    $v->rule('required', 'name')->message('URL обязателен');
    $v->rule('url', 'name')->message('Неверный URL');
    $v->rule('lengthMax', 'name', 255)->message('URL не должен превышать 255 символов');

    if ($v->validate()) {
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = ?');
        $stmt->execute([$urlData['name']]);
        $existingUrl = $stmt->fetch();

        if (!$existingUrl) {
            $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (?, ?)');
            $stmt->execute([$urlData['name'], Carbon::now()]);
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

$app->get('/urls', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->query('SELECT * FROM urls ORDER BY created_at DESC');
    $urls = $stmt->fetchAll();

    $args['urls'] = $urls;
    $renderer = $this->get('renderer');
    return $renderer->render($response, 'urls.phtml', $args);
});

$app->get('/urls/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([$args['id']]);
    $url = $stmt->fetch();

    if ($url) {
        $args['url'] = $url;
        $renderer = $this->get('renderer');
        return $renderer->render($response, 'url.phtml', $args);
    } else {
        return $response->withStatus(404)->write('URL не найден');
    }
});

$app->run();
