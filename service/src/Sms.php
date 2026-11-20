<?php
declare(strict_types=1);
namespace DailyPass;

final class Sms
{
    public function __construct(private Store $store, private Carrier $carrier) {}

    public function dispatch(): void
    {
        $identities = $this->store->query("SELECT DISTINCT s.subscriber_id FROM sms_outbox o JOIN subscribers s ON s.id=o.subscriber_id WHERE o.state='PENDING' AND (o.next_attempt_at IS NULL OR o.next_attempt_at <= UTC_TIMESTAMP(6)) AND s.sms_destination IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($identities as $identity) {
            try {
            $this->store->locked($identity, function () use ($identity): void {
                $sub = $this->store->subscriber($identity);
                $messages = $this->store->query("SELECT * FROM sms_outbox WHERE subscriber_id=? AND state='PENDING' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP(6)) ORDER BY id", [$sub['id']])->fetchAll();
                foreach ($messages as $message) {
                    if ($message['kind'] === 'WELCOME' && $sub['state'] !== 'ACTIVE') {
                        $this->store->query("UPDATE sms_outbox SET state='CANCELLED' WHERE id=?", [$message['id']]);
                        continue;
                    }
                    try {
                    $ok = false;
                    try {
                        $result = $this->carrier->request('/sms/send', ['version' => '1.0', 'message' => $message['message'], 'destinationAddresses' => [$sub['sms_destination']], 'sourceAddress' => getenv('SOURCE_ADDRESS') ?: 'DailyPass', 'encoding' => '0']);
                        $ok = $result['statusCode'] === 'S1000';
                    } catch (\Throwable) {
                    }
                    $attempts = (int) $message['attempts'] + 1;
                    $state = $ok ? 'SENT' : ($attempts >= 5 ? 'FAILED' : 'PENDING');
                    $this->store->query('UPDATE sms_outbox SET state=?,attempts=?,next_attempt_at=? WHERE id=?', [$state, $attempts, gmdate('Y-m-d H:i:s', time() + min(3600, 60 * 2 ** $attempts)), $message['id']]);
                    } catch (\Throwable) {
                        // Isolate this message; it stays PENDING and is retried on the next dispatch.
                    }
                }
            });
            } catch (\Throwable) {
                // Busy or failed subscriber must not block other subscribers' messages.
            }
        }
    }
}
