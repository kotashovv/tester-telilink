<?php
declare(strict_types=1);
namespace DailyPass;

final class Billing
{
    public function __construct(private Store $store, private Carrier $carrier) {}

    public function run(): array
    {
        // Fixed server time; callers cannot manufacture a future billing day.
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $result = [];
        $identities = $this->store->query("SELECT subscriber_id FROM subscribers s WHERE state='ACTIVE' OR EXISTS (SELECT 1 FROM payments p WHERE p.subscriber_id=s.id AND p.state='PROCESSING') ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($identities as $identity) {
            try {
                $result[$identity] = $this->charge($identity, $day);
            } catch (\Throwable $e) {
                // One subscriber must not abort the run; never log messages that could carry secrets.
                $result[$identity] = $e->getCode() === Store::BUSY ? 'BUSY' : 'ERROR';
            }
        }
        return $result;
    }

    public function charge(string $identity, string $day): string
    {
        return $this->store->locked($identity, function () use ($identity, $day): string {
            $payment = $this->store->transaction(function () use ($identity, $day): ?array {
                $sub = $this->store->subscriber($identity, true);
                if (!$sub) {
                    return null;
                }
                // No lock owner remains for these markers, so their external outcome is unknown.
                $this->store->query("UPDATE charge_attempts a JOIN payments p ON p.id=a.payment_id SET a.status_code='UNKNOWN',a.finished_at=UTC_TIMESTAMP(6) WHERE p.subscriber_id=? AND a.status_code='PROCESSING'", [$sub['id']]);
                $this->store->query("UPDATE payments SET state='UNKNOWN',last_code='CRASH_RECOVERY' WHERE subscriber_id=? AND state='PROCESSING'", [$sub['id']]);
                if ($sub['state'] !== 'ACTIVE' || $sub['stopped']) {
                    return null;
                }
                $this->store->query("UPDATE payments SET state='EXPIRED',next_attempt_at=NULL WHERE subscriber_id=? AND billing_day < ? AND state IN ('PENDING','RETRY_SCHEDULED')", [$sub['id'], $day]);
                if ($this->store->query("SELECT 1 FROM payments WHERE subscriber_id=? AND state='UNKNOWN' LIMIT 1", [$sub['id']])->fetchColumn()) {
                    return null;
                }
                $key = RetryPolicy::key(Config::secret('APPLICATION_ID', 'application_id'), $identity, $day);
                $this->store->query('INSERT INTO payments (subscriber_id,billing_day,external_trx_id,amount) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$sub['id'], $day, $key, Config::fee()]);
                $p = $this->store->query('SELECT * FROM payments WHERE subscriber_id=? AND billing_day=? FOR UPDATE', [$sub['id'], $day])->fetch();
                if (!in_array($p['state'], ['PENDING', 'RETRY_SCHEDULED'], true) || ($p['next_attempt_at'] && $p['next_attempt_at'] > gmdate('Y-m-d H:i:s'))) {
                    return null;
                }
                $p['attempt_count'] = (int) $p['attempt_count'] + 1;
                $this->store->query("UPDATE payments SET state='PROCESSING',attempt_count=?,next_attempt_at=NULL WHERE id=?", [$p['attempt_count'], $p['id']]);
                $this->store->query('INSERT INTO charge_attempts (payment_id,attempt_number,external_trx_id) VALUES (?,?,?)', [$p['id'], $p['attempt_count'], $p['external_trx_id']]);
                return $p;
            });
            if ($payment === null) {
                return 'SKIPPED';
            }
            // Durable PROCESSING marker above. Subscriber lock remains held across HTTP.
            $code = 'TRANSPORT_UNKNOWN';
            $reference = null;
            try {
                $response = $this->carrier->request('/caas/direct/debit', [
                    'externalTrxId' => $payment['external_trx_id'], 'subscriberId' => $identity,
                    'paymentInstrumentName' => 'Mobile Account', 'amount' => $payment['amount'], 'currency' => $payment['currency'],
                ]);
                $code = $response['statusCode'];
                if ($code === 'S1000') {
                    if (($response['externalTrxId'] ?? null) !== $payment['external_trx_id'] || !is_string($response['referenceId'] ?? null) || $response['referenceId'] === '' || strlen($response['referenceId']) > 160) {
                        $code = 'INVALID_SUCCESS';
                    } else {
                        $reference = $response['referenceId'];
                    }
                }
                if (!preg_match('/\A[A-Z0-9_]{1,40}\z/', $code)) {
                    $code = 'INVALID_STATUS';
                }
            } catch (\Throwable) {
                // Do not log request bodies, credentials or untrusted carrier exceptions.
            }
            $state = RetryPolicy::state($code, $payment['attempt_count']);
            $this->store->transaction(function () use ($identity, $payment, $state, $code, $reference): void {
                $sub = $this->store->subscriber($identity, true);
                $next = $state === 'RETRY_SCHEDULED' ? gmdate('Y-m-d H:i:s', time() + RetryPolicy::delay($payment['attempt_count'])) : null;
                $this->store->query('UPDATE payments SET state=?,last_code=?,reference_id=?,next_attempt_at=? WHERE id=?', [$state, $code, $reference, $next, $payment['id']]);
                $this->store->query('UPDATE charge_attempts SET status_code=?,reference_id=?,finished_at=UTC_TIMESTAMP(6) WHERE payment_id=? AND attempt_number=?', [$code, $reference, $payment['id'], $payment['attempt_count']]);
                if ($state === 'SUCCEEDED') {
                    $this->store->sms((int) $sub['id'], 'receipt:' . $payment['id'], 'RECEIPT', 'Daily Pass ' . $payment['amount'] . ' XTS for ' . $payment['billing_day'] . '. Reference: ' . $reference);
                }
                if ($code === 'E1308') {
                    $this->store->query("UPDATE subscribers SET state='SUSPENDED' WHERE id=?", [$sub['id']]);
                }
            });
            return $state;
        });
    }
}
