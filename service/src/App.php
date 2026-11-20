<?php
declare(strict_types=1);
namespace DailyPass;

final class App
{
    public readonly Store $store;
    public readonly Subscriptions $subscriptions;
    public readonly Billing $billing;
    public readonly Sms $sms;

    public function __construct(?\PDO $db = null, ?Carrier $carrier = null)
    {
        Config::fee();
        $this->store = new Store($db ?? Config::db());
        $carrier ??= new HttpCarrier();
        $this->subscriptions = new Subscriptions($this->store, $carrier);
        $this->billing = new Billing($this->store, $carrier);
        $this->sms = new Sms($this->store, $carrier);
    }

    public function status(string $identity): array
    {
        $sub = $this->store->subscriber($identity);
        if (!$sub) {
            return ['subscriber' => null, 'payments' => [], 'attempts' => [], 'sms' => []];
        }
        return [
            'subscriber' => $sub,
            'payments' => $this->store->query('SELECT * FROM payments WHERE subscriber_id=? ORDER BY id', [$sub['id']])->fetchAll(),
            'attempts' => $this->store->query('SELECT a.* FROM charge_attempts a JOIN payments p ON p.id=a.payment_id WHERE p.subscriber_id=? ORDER BY a.id', [$sub['id']])->fetchAll(),
            'sms' => $this->store->query('SELECT * FROM sms_outbox WHERE subscriber_id=? ORDER BY id', [$sub['id']])->fetchAll(),
        ];
    }
}
