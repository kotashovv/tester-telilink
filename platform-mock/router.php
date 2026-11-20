<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use DailyPass\{Config, Validation};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$db = new PDO('sqlite:' . (getenv('MOCK_DB') ?: '/data/platform.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL;');
$db->exec('CREATE TABLE IF NOT EXISTS config (subscriber TEXT PRIMARY KEY, codes TEXT NOT NULL, delay_ms INTEGER NOT NULL DEFAULT 0, lose_response INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS subscriptions (subscriber TEXT PRIMARY KEY, status TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS debits (external_trx_id TEXT PRIMARY KEY, subscriber TEXT NOT NULL, amount TEXT NOT NULL, reference_id TEXT NOT NULL, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS requests (id INTEGER PRIMARY KEY AUTOINCREMENT, subscriber TEXT NOT NULL, external_trx_id TEXT NOT NULL, status_code TEXT NOT NULL, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS sms (id INTEGER PRIMARY KEY AUTOINCREMENT, destination TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL);');
function query(string $sql, array $args = []): PDOStatement {
    global $db;
    $s = $db->prepare($sql);
    $s->execute($args);
    return $s;
}
function response(string $code, array $extra = []): array {
    return ['statusCode' => $code, 'statusDetail' => $code === 'S1000' ? 'Success' : 'Simulated carrier outcome'] + $extra;
}
function callback(string $path, array $data): array {
    $data['applicationId'] = Config::secret('APPLICATION_ID', 'application_id');
    $ch = curl_init((getenv('SERVICE_URL') ?: 'http://service:8080') . $path);
    curl_setopt_array($ch, [CURLOPT_NOPROXY => 'localhost,127.0.0.1,service,platform-mock', CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200 || !is_string($body)) {
        throw new RuntimeException('Callback failed');
    }
    return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
}
try {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && $path === '/') {
        $result = ['name' => 'Carrier Platform mock', 'try' => ['GET /admin/state', 'POST /admin/config', 'POST /admin/notify', 'POST /admin/mo', 'GET /health']];
    } elseif ($method === 'GET' && $path === '/health') {
        $result = ['ok' => true];
    } elseif ($method === 'GET' && $path === '/admin/state') {
        $result = [];
        foreach (['config', 'subscriptions', 'debits', 'requests', 'sms'] as $table) {
            $result[$table] = query('SELECT * FROM ' . $table)->fetchAll();
        }
    } elseif ($method === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($raw) > 16384) {
            throw new InvalidArgumentException('Request too large');
        }
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Object required');
        }
        if (!str_starts_with($path, '/admin/')) {
            if (!is_string($data['applicationId'] ?? null) || !is_string($data['password'] ?? null) || !hash_equals(Config::secret('APPLICATION_ID', 'application_id'), $data['applicationId']) || !hash_equals(Config::secret('PLATFORM_PASSWORD', 'platform_password'), $data['password'])) {
                http_response_code(403);
                echo json_encode(response('AUTH_ERROR'));
                exit;
            }
        }
        switch ($path) {
            case '/admin/config':
                $identity = Validation::identity($data['subscriberId'] ?? null);
                $codes = $data['codes'] ?? [$data['statusCode'] ?? 'S1000'];
                if (!is_array($codes) || !array_is_list($codes) || $codes === [] || array_diff($codes, ['S1000', 'E1326', 'E1308', 'E1337', 'E1318'])) {
                    throw new InvalidArgumentException('codes must be a nonempty list of supported status codes');
                }
                $delay = $data['delayMs'] ?? 0;
                if (!is_int($delay) || $delay < 0 || $delay > 10000 || isset($data['loseResponse']) && !is_bool($data['loseResponse'])) {
                    throw new InvalidArgumentException('Invalid fault configuration');
                }
                query('INSERT INTO config(subscriber,codes,delay_ms,lose_response) VALUES (?,?,?,?) ON CONFLICT(subscriber) DO UPDATE SET codes=excluded.codes,delay_ms=excluded.delay_ms,lose_response=excluded.lose_response', [$identity, json_encode($codes), $delay, (int) ($data['loseResponse'] ?? false)]);
                $result = ['ok' => true];
                break;
            case '/admin/notify':
                $result = callback('/webhooks/subscription', [
                    'subscriberId' => $data['subscriberId'] ?? null, 'status' => $data['status'] ?? null,
                    'timeStamp' => $data['timeStamp'] ?? gmdate('Y-m-d\TH:i:s\Z'), 'frequency' => 'DAILY', 'version' => '1.0',
                ]);
                break;
            case '/admin/mo':
                $result = callback('/webhooks/mo', ['sourceAddress' => $data['subscriberId'] ?? null, 'message' => $data['message'] ?? 'STOP', 'encoding' => '0', 'version' => '1.0']);
                break;
            case '/subscription/send':
                $identity = Validation::identity($data['subscriberId'] ?? null);
                if (!in_array($data['action'] ?? null, ['1', '0'], true)) {
                    throw new InvalidArgumentException('Invalid action');
                }
                $status = $data['action'] === '1' ? 'REGISTERED' : 'UNREGISTERED';
                query('INSERT INTO subscriptions VALUES (?,?) ON CONFLICT(subscriber) DO UPDATE SET status=excluded.status', [$identity, $status]);
                $result = response('S1000', ['version' => '1.0', 'requestId' => bin2hex(random_bytes(8)), 'subscriptionStatus' => $status]);
                break;
            case '/sms/send':
                if (!is_array($data['destinationAddresses'] ?? null) || !$data['destinationAddresses'] || !is_string($data['message'] ?? null)) {
                    throw new InvalidArgumentException('Invalid SMS payload');
                }
                foreach ($data['destinationAddresses'] as $destination) {
                    Validation::phone($destination);
                    query('INSERT INTO sms(destination,message,created_at) VALUES (?,?,?)', [$destination, $data['message'], gmdate('c')]);
                }
                $result = response('S1000');
                break;
            case '/caas/direct/debit':
                $identity = Validation::identity($data['subscriberId'] ?? null);
                $amount = Validation::amount($data['amount'] ?? null);
                $key = $data['externalTrxId'] ?? null;
                if (!is_string($key) || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $key) || ($data['currency'] ?? null) !== 'XTS') {
                    throw new InvalidArgumentException('Invalid debit');
                }
                query('INSERT INTO requests(subscriber,external_trx_id,status_code,created_at) VALUES (?,?,?,?)', [$identity, $key, 'PROCESSING', gmdate('c')]);
                $requestId = $db->lastInsertId();
                $db->exec('BEGIN IMMEDIATE');
                $config = query('SELECT * FROM config WHERE subscriber=?', [$identity])->fetch();
                $codes = $config ? json_decode($config['codes'], true) : ['S1000'];
                $code = $codes[0];
                if (count($codes) > 1) {
                    array_shift($codes);
                    query('UPDATE config SET codes=? WHERE subscriber=?', [json_encode($codes), $identity]);
                }
                $duplicate = query('SELECT * FROM debits WHERE external_trx_id=?', [$key])->fetch();
                if ($duplicate) {
                    $code = 'E1337';
                }
                $reference = bin2hex(random_bytes(8));
                if ($code === 'S1000') {
                    query('INSERT INTO debits VALUES (?,?,?,?,?)', [$key, $identity, $amount, $reference, gmdate('c')]);
                }
                query('UPDATE requests SET status_code=? WHERE id=?', [$code, $requestId]);
                $db->exec('COMMIT');
                if ($config && $config['delay_ms']) {
                    usleep((int) $config['delay_ms'] * 1000);
                }
                if ($config && $config['lose_response'] && $code === 'S1000') {
                    http_response_code(503); // Debit committed, success response lost.
                    $result = ['error' => 'Simulated lost response'];
                } else {
                    $result = response($code, ['externalTrxId' => $key, 'internalTrxId' => $reference, 'referenceId' => $code === 'S1000' ? $reference : null, 'timeStamp' => gmdate('c')]);
                }
                break;
            default:
                http_response_code(404);
                $result = ['error' => 'Not found'];
        }
    } else {
        http_response_code(404);
        $result = ['error' => 'Not found'];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['error' => 'Mock operation unavailable']);
}
