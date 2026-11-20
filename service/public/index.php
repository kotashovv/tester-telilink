<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use DailyPass\{App, Config, Validation};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    $app = new App();
    if ($method === 'GET' && $path === '/') {
        $result = ['name' => 'Daily Pass service', 'try' => ['POST /subscriptions', 'POST /jobs/bill', 'GET /subscribers/status?subscriberId=tel%3A1000000001', 'GET /health']];
    } elseif ($method === 'GET' && $path === '/health') {
        $app->store->query('SELECT 1');
        $result = ['ok' => true];
    } elseif ($method === 'GET' && $path === '/subscribers/status') {
        $result = $app->status(Validation::identity($_GET['subscriberId'] ?? null));
    } elseif ($method === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
            throw new InvalidArgumentException('Request too large');
        }
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            throw new InvalidArgumentException('Content-Type must be application/json');
        }
        $body = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($body) > 16384) {
            throw new InvalidArgumentException('Request too large');
        }
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input) && $input !== []) {
            throw new InvalidArgumentException('JSON object required');
        }
        if (str_starts_with($path, '/webhooks/')) {
            if (!is_string($input['applicationId'] ?? null) || !hash_equals(Config::secret('APPLICATION_ID', 'application_id'), $input['applicationId'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid applicationId']);
                exit;
            }
        }
        switch ($path) {
            case '/subscriptions':
                $identity = Validation::identity($input['subscriberId'] ?? null);
                $destination = isset($input['smsDestination']) ? Validation::phone($input['smsDestination']) : (str_starts_with($identity, 'tel:') ? $identity : null);
                if ($destination === null) {
                    throw new InvalidArgumentException('Masked identity requires smsDestination for opt-in');
                }
                if (isset($input['amount'])) {
                    throw new InvalidArgumentException('The daily fee is server-configured; amount is not accepted');
                }
                $result = ['subscriber' => $app->subscriptions->optIn($identity, $destination)];
                $app->sms->dispatch();
                break;
            case '/webhooks/subscription':
                $app->subscriptions->notification($input);
                $result = ['statusCode' => 'S1000', 'statusDetail' => 'Success'];
                break;
            case '/webhooks/mo':
                $identity = Validation::identity($input['sourceAddress'] ?? null);
                if (!is_string($input['message'] ?? null)) {
                    throw new InvalidArgumentException('message must be a string');
                }
                if (strtoupper(trim($input['message'])) === 'STOP') {
                    $app->subscriptions->stop($identity);
                    $app->sms->dispatch();
                }
                $result = ['statusCode' => 'S1000', 'statusDetail' => 'Success'];
                break;
            case '/jobs/bill':
                $result = ['results' => $app->billing->run()];
                $app->sms->dispatch();
                break;
            case '/jobs/sms':
                $app->sms->dispatch();
                $result = ['ok' => true];
                break;
            default:
                http_response_code(404);
                $result = ['error' => 'Not found'];
        }
    } else {
        http_response_code(404);
        $result = ['error' => 'Not found'];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['error' => 'Operation unavailable; inspect persisted status before retrying']);
}
