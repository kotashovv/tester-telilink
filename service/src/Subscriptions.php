<?php
declare(strict_types=1);
namespace DailyPass;

final class Subscriptions
{
    public function __construct(private Store $store, private Carrier $carrier) {}

    public function optIn(string $identity, ?string $destination): array
    {
        return $this->store->locked($identity, function () use ($identity, $destination): array {
            $this->store->ensure($identity, $destination);
            $sub = $this->store->subscriber($identity);
            if ($sub['state'] === 'ACTIVE') {
                $this->store->query('UPDATE subscribers SET sms_destination=COALESCE(sms_destination,?) WHERE id=?', [$destination, $sub['id']]);
                return $this->store->subscriber($identity);
            }
            $response = $this->carrier->request('/subscription/send', ['subscriberId' => $identity, 'action' => '1', 'version' => '1.0']);
            if ($response['statusCode'] !== 'S1000') {
                throw new \RuntimeException('Carrier registration not confirmed');
            }
            $this->store->transaction(function () use ($identity, $destination, $sub): void {
                $this->store->subscriber($identity, true);
                $this->store->query("UPDATE subscribers SET state='ACTIVE',stopped=FALSE,sms_destination=COALESCE(?,sms_destination),event_at=UTC_TIMESTAMP() WHERE id=?", [$destination, $sub['id']]);
                $this->store->sms((int) $sub['id'], 'welcome:' . bin2hex(random_bytes(16)), 'WELCOME', 'Welcome to Daily Pass. Daily fee ' . Config::fee() . ' XTS. Text STOP to unsubscribe.');
            });
            return $this->store->subscriber($identity);
        });
    }

    public function stop(string $identity): void
    {
        $this->store->locked($identity, function () use ($identity): void {
            $this->store->ensure($identity, str_starts_with($identity, 'tel:') ? $identity : null);
            $this->store->transaction(function () use ($identity): void {
                $sub = $this->store->subscriber($identity, true);
                // Commit the local safety barrier even if the remote unsubscribe fails.
                $this->store->query("UPDATE subscribers SET state='INACTIVE',stopped=TRUE,event_at=UTC_TIMESTAMP() WHERE id=?", [$sub['id']]);
                if (!$sub['stopped']) {
                    $this->store->sms((int) $sub['id'], 'goodbye:' . bin2hex(random_bytes(16)), 'GOODBYE', 'Daily Pass has stopped. You will receive no further daily charges.');
                }
            });
            $result = $this->carrier->request('/subscription/send', ['subscriberId' => $identity, 'action' => '0', 'version' => '1.0']);
            if ($result['statusCode'] !== 'S1000') {
                throw new \RuntimeException('Stopped locally; remote unsubscribe requires retry');
            }
        });
    }

    public function notification(array $event): void
    {
        $identity = Validation::identity($event['subscriberId'] ?? null);
        $status = $event['status'] ?? null;
        if (!in_array($status, ['REGISTERED', 'UNREGISTERED', 'PENDING', 'CHARGE'], true)) {
            throw new \InvalidArgumentException('Invalid subscription notification status');
        }
        $at = Validation::timestamp($event['timeStamp'] ?? null);
        $fingerprint = hash('sha256', json_encode([$identity, $status, $at], JSON_THROW_ON_ERROR));
        $this->store->locked($identity, function () use ($identity, $status, $at, $fingerprint): void {
            $this->store->transaction(function () use ($identity, $status, $at, $fingerprint): void {
                if ($this->store->query('SELECT 1 FROM webhook_events WHERE fingerprint=?', [$fingerprint])->fetchColumn()) {
                    return;
                }
                $this->store->query('INSERT INTO webhook_events (fingerprint,subscriber_id,status,event_at) VALUES (?,?,?,?)', [$fingerprint, $identity, $status, $at]);
                $this->store->ensure($identity, str_starts_with($identity, 'tel:') ? $identity : null);
                $sub = $this->store->subscriber($identity, true);
                if ($status === 'REGISTERED' && $sub['event_at'] !== null && $at < $sub['event_at']) {
                    return;
                }
                if ($status === 'UNREGISTERED') {
                    $this->store->query("UPDATE subscribers SET state='INACTIVE',stopped=TRUE,event_at=COALESCE(GREATEST(event_at,?),?) WHERE id=?", [$at, $at, $sub['id']]);
                } elseif ($status === 'REGISTERED' && !$sub['stopped'] && $sub['state'] !== 'SUSPENDED') {
                    $this->store->query("UPDATE subscribers SET state='ACTIVE',event_at=? WHERE id=?", [$at, $sub['id']]);
                }
                // CHARGE is informational, never an instruction to debit.
            });
        });
    }
}
