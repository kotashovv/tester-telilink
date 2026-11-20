<?php
declare(strict_types=1);
namespace DailyPass;

final class Store
{
    public const BUSY = 423;

    public function __construct(public readonly \PDO $db) {}

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function locked(string $identity, callable $work): mixed
    {
        // Connection-scoped: survives commits, releases automatically on process death.
        $key = hash('sha256', 'subscriber:' . $identity);
        if ((int) $this->query('SELECT GET_LOCK(?, 15)', [$key])->fetchColumn() !== 1) {
            throw new \RuntimeException('Subscriber is busy; retry later', self::BUSY);
        }
        try {
            return $work();
        } finally {
            $this->query('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }

    public function subscriber(string $identity, bool $forUpdate = false): array|false
    {
        return $this->query('SELECT * FROM subscribers WHERE subscriber_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$identity])->fetch();
    }

    public function ensure(string $identity, ?string $destination = null): void
    {
        $this->query('INSERT INTO subscribers (subscriber_id,sms_destination) VALUES (?,?) ON DUPLICATE KEY UPDATE subscriber_id = VALUES(subscriber_id)', [$identity, $destination]);
    }

    public function sms(int $subscriber, string $key, string $kind, string $message): void
    {
        $this->query('INSERT INTO sms_outbox (subscriber_id,dedupe_key,kind,message) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE dedupe_key=VALUES(dedupe_key)', [$subscriber, $key, $kind, $message]);
    }
}
