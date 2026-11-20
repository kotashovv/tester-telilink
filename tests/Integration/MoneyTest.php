<?php
declare(strict_types=1);
use DailyPass\{App, Carrier, RetryPolicy, Config};
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private App $app;
    private string $identity;

    protected function setUp(): void
    {
        if (!getenv('DB_DSN')) {
            self::markTestSkipped('Integration tests require Compose MySQL and mock; run docker compose --profile test run --rm tests');
        }
        $this->app = new App();
        $this->identity = 'tel:1' . random_int(100000000, 999999999);
    }

    private function http(string $url, ?array $body = null, int $expected = 200): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOPROXY => 'localhost,127.0.0.1,service,platform-mock', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        }
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        self::assertSame($expected, $code, (string) $result);
        return json_decode($result, true, 32, JSON_THROW_ON_ERROR);
    }

    private function admin(string $path, ?array $body = null): array
    {
        return $this->http((getenv('MOCK_ADMIN_URL') ?: 'http://platform-mock:8081') . '/admin/' . $path, $body);
    }

    private function configure(array $config): void
    {
        $this->admin('config', ['subscriberId' => $this->identity] + $config);
    }

    private function activate(): void
    {
        $this->app->subscriptions->optIn($this->identity, $this->identity);
    }

    private function charge(): string
    {
        return $this->app->billing->charge($this->identity, gmdate('Y-m-d'));
    }

    private function snapshot(): array
    {
        return $this->app->status($this->identity);
    }

    private function rows(string $table): array
    {
        return array_values(array_filter($this->admin('state')[$table], fn(array $r): bool => ($r['subscriber'] ?? $r['destination'] ?? '') === $this->identity));
    }

    private function due(): void
    {
        $this->app->store->query("UPDATE payments SET next_attempt_at='2000-01-01 00:00:00' WHERE subscriber_id=?", [$this->snapshot()['subscriber']['id']]);
    }

    private function worker(string $operation): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/worker.php', $operation, $this->identity], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        return [$process, $pipes];
    }

    private function finish(array $worker): string
    {
        [$process, $pipes] = $worker;
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        return trim($stdout);
    }

    public function testChargeOnceSurvivesRerunAndNewApplicationInstance(): void
    {
        $this->activate();
        $this->app->subscriptions->optIn($this->identity, $this->identity);
        self::assertSame('SUCCEEDED', $this->charge());
        $this->app = new App();
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(1, $this->rows('debits'));
        self::assertCount(1, $this->snapshot()['attempts']);
        $this->app->sms->dispatch();
        $sms = $this->rows('sms');
        self::assertCount(2, $sms);
        self::assertStringContainsString($this->snapshot()['payments'][0]['reference_id'], $sms[1]['message']);
    }

    public function testInsufficientBalanceRetryHasSameKeyAndBackoff(): void
    {
        $this->configure(['codes' => ['E1326', 'S1000']]);
        $this->activate();
        self::assertSame('RETRY_SCHEDULED', $this->charge());
        self::assertSame('ACTIVE', $this->snapshot()['subscriber']['state']);
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(1, $this->rows('requests'));
        $this->due();
        self::assertSame('SUCCEEDED', $this->charge());
        $requests = $this->rows('requests');
        self::assertSame($requests[0]['external_trx_id'], $requests[1]['external_trx_id']);
        self::assertCount(1, $this->rows('debits'));
    }

    public function testRetryBudgetIsBounded(): void
    {
        $this->configure(['statusCode' => 'E1318']);
        $this->activate();
        self::assertSame('RETRY_SCHEDULED', $this->charge());
        $this->due();
        self::assertSame('RETRY_SCHEDULED', $this->charge());
        $this->due();
        self::assertSame('EXHAUSTED', $this->charge());
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(3, $this->rows('requests'));
        self::assertCount(0, $this->rows('debits'));
    }

    public function testPermanentErrorSuspendsAndDoesNotRetry(): void
    {
        $this->configure(['statusCode' => 'E1308']);
        $this->activate();
        self::assertSame('FAILED_PERMANENT', $this->charge());
        self::assertSame('SUSPENDED', $this->snapshot()['subscriber']['state']);
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(1, $this->rows('requests'));
        self::assertCount(0, $this->rows('debits'));
    }

    public function testLostResponseIsUnknownAndNeverProducesReceiptOrNewDayDebit(): void
    {
        $this->configure(['loseResponse' => true]);
        $this->activate();
        self::assertSame('UNKNOWN', $this->charge());
        self::assertSame('SKIPPED', $this->charge());
        self::assertSame('SKIPPED', $this->app->billing->charge($this->identity, gmdate('Y-m-d', time() + 86400)));
        self::assertCount(1, $this->rows('debits'));
        self::assertNull($this->snapshot()['payments'][0]['reference_id']);
        self::assertSame(['WELCOME'], array_column($this->snapshot()['sms'], 'kind'));
    }

    public function testDuplicateCodeDoesNotProveSuccess(): void
    {
        $this->configure(['statusCode' => 'E1337']);
        $this->activate();
        self::assertSame('UNKNOWN', $this->charge());
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(0, $this->rows('debits'));
        self::assertSame(['WELCOME'], array_column($this->snapshot()['sms'], 'kind'));
    }

    public function testDuplicateWebhookAndStopTombstone(): void
    {
        $event = ['subscriberId' => $this->identity, 'status' => 'REGISTERED', 'timeStamp' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->admin('notify', $event);
        $this->admin('notify', $event);
        self::assertSame('ACTIVE', $this->snapshot()['subscriber']['state']);
        self::assertSame('SUCCEEDED', $this->charge());
        $this->admin('notify', $event);
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(1, $this->rows('debits'));
        $this->admin('mo', ['subscriberId' => $this->identity, 'message' => ' stop ']);
        $this->admin('notify', $event);
        self::assertSame('INACTIVE', $this->snapshot()['subscriber']['state']);
        self::assertSame('SKIPPED', $this->charge());
    }

    public function testUnregisteredCallbackAndStaleRegisteredEvent(): void
    {
        $this->activate();
        $this->admin('notify', ['subscriberId' => $this->identity, 'status' => 'UNREGISTERED', 'timeStamp' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->admin('notify', ['subscriberId' => $this->identity, 'status' => 'REGISTERED', 'timeStamp' => '2020-01-01T00:00:00Z']);
        self::assertSame('INACTIVE', $this->snapshot()['subscriber']['state']);
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(0, $this->rows('debits'));
    }

    public function testSelectedWorkerRechecksStateAfterStop(): void
    {
        $this->activate();
        $worker = $this->worker('selected');
        self::assertSame("SELECTED\n", fgets($worker[1][1]));
        $this->app->subscriptions->stop($this->identity);
        fwrite($worker[1][0], "continue\n");
        self::assertSame('SKIPPED', $this->finish($worker));
        self::assertCount(0, $this->rows('requests'));
        self::assertSame('UNREGISTERED', $this->rows('subscriptions')[0]['status']);
    }

    public function testConcurrentWorkersChargeOnlyOnce(): void
    {
        $this->activate();
        $this->configure(['delayMs' => 500]);
        $first = $this->worker('bill');
        $second = $this->worker('bill');
        $states = [$this->finish($first), $this->finish($second)];
        sort($states);
        self::assertSame(['SKIPPED', 'SUCCEEDED'], $states);
        self::assertCount(1, $this->rows('requests'));
        self::assertCount(1, $this->rows('debits'));
    }

    public function testStopWaitsForAlreadyInFlightDebitAndPreventsLaterDebits(): void
    {
        $this->activate();
        $this->configure(['delayMs' => 1000]);
        $worker = $this->worker('bill');
        $this->waitForDebit();
        $this->app->subscriptions->stop($this->identity);
        self::assertSame('SUCCEEDED', $this->finish($worker));
        self::assertSame('INACTIVE', $this->snapshot()['subscriber']['state']);
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(1, $this->rows('debits'));
    }

    private function waitForDebit(): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (count($this->rows('debits')) === 1) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::fail('Debit did not reach mock');
    }

    public function testKilledWorkerLeavesDurableUnknownMarker(): void
    {
        $this->activate();
        $this->configure(['delayMs' => 3000]);
        $worker = $this->worker('bill');
        $this->waitForDebit();
        proc_terminate($worker[0], 9);
        foreach ($worker[1] as $pipe) {
            fclose($pipe);
        }
        proc_close($worker[0]);
        self::assertSame('SKIPPED', $this->charge());
        self::assertSame('UNKNOWN', $this->snapshot()['payments'][0]['state']);
        self::assertSame('UNKNOWN', $this->snapshot()['attempts'][0]['status_code']);
        self::assertCount(1, $this->rows('debits'));
        self::assertSame(['WELCOME'], array_column($this->snapshot()['sms'], 'kind'));
    }

    public function testReceiptOutboxSurvivesApplicationRestart(): void
    {
        $this->activate();
        $this->charge();
        self::assertCount(0, $this->rows('sms'));
        $this->app = new App();
        $this->app->sms->dispatch();
        $this->app->sms->dispatch();
        self::assertCount(2, $this->rows('sms'));
        self::assertSame(['SENT', 'SENT'], array_column($this->snapshot()['sms'], 'state'));
    }

    public function testOpaqueAliasAndSeparateSmsDestination(): void
    {
        $destination = $this->identity;
        $this->identity = 'masked:' . bin2hex(random_bytes(10));
        $this->app->subscriptions->optIn($this->identity, $destination);
        self::assertSame('SUCCEEDED', $this->charge());
        $this->app->sms->dispatch();
        self::assertCount(1, $this->rows('debits'));
        self::assertSame($destination, $this->snapshot()['subscriber']['sms_destination']);
        $this->app->subscriptions->stop($this->identity);
        self::assertSame('SKIPPED', $this->charge());
    }

    public function testHttpOptInStatusBillingAndMo(): void
    {
        $base = getenv('SERVICE_URL') ?: 'http://service:8080';
        $this->http($base . '/subscriptions', ['subscriberId' => $this->identity]);
        $this->http($base . '/jobs/bill', []);
        $this->http($base . '/jobs/bill', []);
        $status = $this->http($base . '/subscribers/status?subscriberId=' . rawurlencode($this->identity));
        self::assertSame('SUCCEEDED', $status['payments'][0]['state']);
        $this->admin('mo', ['subscriberId' => $this->identity]);
        self::assertSame('INACTIVE', $this->snapshot()['subscriber']['state']);
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(1, $this->rows('debits'));
    }

    public function testUnconfirmedSuccessNeverCreatesReceipt(): void
    {
        $this->activate();
        $badCarrier = new class implements Carrier {
            public function request(string $path, array $payload): array
            {
                return ['statusCode' => 'S1000', 'externalTrxId' => $payload['externalTrxId']];
            }
        };
        $app = new App(Config::db(), $badCarrier);
        self::assertSame('UNKNOWN', $app->billing->charge($this->identity, gmdate('Y-m-d')));
        self::assertSame('INVALID_SUCCESS', $this->snapshot()['payments'][0]['last_code']);
        self::assertSame(['WELCOME'], array_column($this->snapshot()['sms'], 'kind'));
    }

    public function testPersistedPendingPaymentCanRunAfterRestart(): void
    {
        $this->activate();
        $day = gmdate('Y-m-d');
        $key = RetryPolicy::key(Config::secret('APPLICATION_ID', 'application_id'), $this->identity, $day);
        $this->app->store->query('INSERT INTO payments(subscriber_id,billing_day,external_trx_id,amount) VALUES (?,?,?,?)', [$this->snapshot()['subscriber']['id'], $day, $key, '5.00']);
        $this->app = new App();
        self::assertSame('SUCCEEDED', $this->charge());
        self::assertSame($key, $this->rows('debits')[0]['external_trx_id']);
        self::assertCount(1, $this->snapshot()['payments']);
    }

    public function testCrashBeforeHttpCallConservativelyRequiresReconciliation(): void
    {
        $this->activate();
        $day = gmdate('Y-m-d');
        $key = RetryPolicy::key(Config::secret('APPLICATION_ID', 'application_id'), $this->identity, $day);
        $this->app->store->query("INSERT INTO payments(subscriber_id,billing_day,external_trx_id,amount,state,attempt_count) VALUES (?,?,?,?,'PROCESSING',1)", [$this->snapshot()['subscriber']['id'], $day, $key, '5.00']);
        $payment = $this->snapshot()['payments'][0];
        $this->app->store->query('INSERT INTO charge_attempts(payment_id,attempt_number,external_trx_id) VALUES (?,1,?)', [$payment['id'], $key]);
        $this->app = new App();
        self::assertSame('SKIPPED', $this->charge());
        self::assertSame('UNKNOWN', $this->snapshot()['payments'][0]['state']);
        self::assertCount(0, $this->rows('requests'));
    }

    public function testExplicitReoptInCannotChargeTwiceInOneDay(): void
    {
        $this->activate();
        self::assertSame('SUCCEEDED', $this->charge());
        $this->app->subscriptions->stop($this->identity);
        $this->activate();
        self::assertSame('ACTIVE', $this->snapshot()['subscriber']['state']);
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(1, $this->rows('debits'));
    }

    public function testInformationalChargeCallbackCannotInitiateDebit(): void
    {
        $this->activate();
        $this->admin('notify', ['subscriberId' => $this->identity, 'status' => 'CHARGE']);
        self::assertCount(0, $this->rows('requests'));
        self::assertCount(0, $this->snapshot()['payments']);
    }

    public function testHttpValidationRejectsInvalidNumbersFeesAndUnknownApp(): void
    {
        $base = getenv('SERVICE_URL') ?: 'http://service:8080';
        $this->http($base . '/subscriptions', ['subscriberId' => 'tel:bad'], 422);
        $this->http($base . '/subscriptions', ['subscriberId' => $this->identity, 'amount' => '5.000'], 422);
        $this->http($base . '/subscriptions', ['subscriberId' => 'masked:missing-route'], 422);
        $this->http($base . '/webhooks/mo', ['applicationId' => 'wrong-app', 'sourceAddress' => $this->identity, 'message' => 'STOP'], 403);
        self::assertNull($this->snapshot()['subscriber']);
    }

    public function testAliasDiscoveredThroughCallbackCanBindAnSmsRoute(): void
    {
        $destination = $this->identity;
        $this->identity = 'alias:' . bin2hex(random_bytes(10));
        $this->admin('notify', ['subscriberId' => $this->identity, 'status' => 'REGISTERED']);
        self::assertNull($this->snapshot()['subscriber']['sms_destination']);
        $this->app->subscriptions->optIn($this->identity, $destination);
        self::assertSame($destination, $this->snapshot()['subscriber']['sms_destination']);
        self::assertSame('SUCCEEDED', $this->charge());
        $this->app->sms->dispatch();
        self::assertSame('SENT', $this->snapshot()['sms'][0]['state']);
    }

    public function testOldRetryExpiresWithoutCatchUpDebit(): void
    {
        $this->activate();
        $this->configure(['codes' => ['E1326', 'S1000']]);
        $yesterday = gmdate('Y-m-d', time() - 86400);
        self::assertSame('RETRY_SCHEDULED', $this->app->billing->charge($this->identity, $yesterday));
        self::assertSame('SUCCEEDED', $this->charge());
        self::assertSame(['EXPIRED', 'SUCCEEDED'], array_column($this->snapshot()['payments'], 'state'));
        self::assertCount(1, $this->rows('debits'));
        self::assertCount(2, $this->snapshot()['attempts']);
    }

    private function holdLock(string $identity): \PDO
    {
        $other = Config::db();
        self::assertSame(1, (int) $other->query("SELECT GET_LOCK('" . hash('sha256', 'subscriber:' . $identity) . "', 0)")->fetchColumn());
        return $other;
    }

    public function testBusySubscriberDoesNotAbortBillingRun(): void
    {
        $this->activate();
        $busy = $this->identity;
        $this->identity = 'tel:1' . random_int(100000000, 999999999);
        $this->activate();
        $holder = $this->holdLock($busy);
        $result = $this->app->billing->run();
        self::assertSame('BUSY', $result[$busy]);
        self::assertSame('SUCCEEDED', $result[$this->identity]);
        self::assertCount(1, $this->rows('debits'));
        unset($holder);
    }

    public function testBusySubscriberDoesNotAbortSmsDispatch(): void
    {
        $this->activate();
        $busy = $this->identity;
        $this->identity = 'tel:1' . random_int(100000000, 999999999);
        $this->activate();
        $holder = $this->holdLock($busy);
        $this->app->sms->dispatch();
        self::assertSame('SENT', $this->snapshot()['sms'][0]['state']);
        $this->identity = $busy;
        self::assertSame('PENDING', $this->snapshot()['sms'][0]['state']);
        unset($holder);
    }

    public function testUnregisteredIsNeverStaleAndIsIdempotent(): void
    {
        $this->activate();
        $event = ['subscriberId' => $this->identity, 'status' => 'UNREGISTERED', 'timeStamp' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)];
        $this->admin('notify', $event);
        $this->admin('notify', $event);
        self::assertSame('INACTIVE', $this->snapshot()['subscriber']['state']);
        self::assertSame('SKIPPED', $this->charge());
        self::assertCount(0, $this->rows('debits'));
    }

    public function testBillingDayIsAlwaysUtc(): void
    {
        putenv('BILLING_TIMEZONE=Pacific/Kiritimati');
        try {
            $this->activate();
            $this->app->billing->run();
            self::assertSame(gmdate('Y-m-d'), $this->snapshot()['payments'][0]['billing_day']);
        } finally {
            putenv('BILLING_TIMEZONE');
        }
    }
}
