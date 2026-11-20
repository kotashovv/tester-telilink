# Daily Pass

A small PHP 8.3 / MySQL 8 carrier-billing service, a persistent HTTP carrier mock, and PHPUnit tests focused on money safety. No framework or external queue. Billing is manually triggerable; UTC defines a billing day.

## Run

Only Docker with Docker Compose is required. From the repository root:

```sh
docker compose up
```

Compose builds the PHP image, installs the locked Composer dependencies, generates local credentials into a Docker volume, waits for MySQL and the mock, and runs schema migrations before serving requests. No `.env`, Composer, PHP, database setup, or manual migration is required on the host. After code changes use `docker compose up --build`.

- Service: `http://localhost:8080/health`
- Mock: `http://localhost:8081/health`
- Both host ports bind to loopback. These unauthenticated demo/admin endpoints are for a trusted local machine.
- MySQL, mock data, and generated credentials survive container restarts in named volumes.

## Drive the scenarios

The following commands use POSIX shell quoting. In PowerShell use `curl.exe` with JSON files and `--data-binary @file.json` if native quoting interferes. All amounts are fixed by the service, default `5.00 XTS`. Configure another positive two-decimal fee using `DAILY_FEE=7.50 docker compose up` (or a local `.env`).

### Opt-in and welcome

```sh
curl -sS http://localhost:8080/subscriptions -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000001"}'
curl -sS http://localhost:8081/admin/state
```

The subscriber becomes `ACTIVE`. The mock's `subscriptions` and `sms` show registration and welcome. Repeating opt-in while active makes no further subscription request or welcome SMS.

### Successful daily debit, rerun, and finance audit

```sh
curl -sS http://localhost:8080/jobs/bill -H 'Content-Type: application/json' -d '{}'
curl -sS http://localhost:8080/jobs/bill -H 'Content-Type: application/json' -d '{}'
curl -sS 'http://localhost:8080/subscribers/status?subscriberId=tel%3A1000000001'
curl -sS http://localhost:8081/admin/state
```

`payments` contains one `SUCCEEDED` daily payment with its reference. `attempts` records the external key, status, reference, and timestamps for each actual attempt. The mock's `debits` has exactly one actual debit; `sms` contains a receipt quoting its reference. A second run returns `SKIPPED` for that subscriber. `GET /subscribers/status` also includes pending/failed SMS deliveries.

The job runs **all** active subscribers. It never accepts a client-supplied date, fee, or key. To run it from a scheduler without HTTP:

```sh
docker compose exec service php service/bin/job.php
```

### Insufficient balance and bounded retry

Use a fresh subscriber because today's successful payment cannot be overwritten:

```sh
curl -sS http://localhost:8081/admin/config -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000002","codes":["E1326","S1000"]}'
curl -sS http://localhost:8080/subscriptions -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000002"}'
curl -sS http://localhost:8080/jobs/bill -H 'Content-Type: application/json' -d '{}'
curl -sS 'http://localhost:8080/subscribers/status?subscriberId=tel%3A1000000002'
```

The payment is `RETRY_SCHEDULED`, subscriber remains `ACTIVE` in grace, no receipt is sent. Wait at least 60 seconds and rerun `/jobs/bill`: the second attempt uses **the same** key and succeeds. Repeated jobs before the due timestamp do nothing. `E1318` uses the same policy. The maximum is three attempts (60s then 120s backoff); after that `EXHAUSTED`. Grace lasts through the current UTC billing day; old pending retries become `EXPIRED` when the next day is billed. No catch-up debit for yesterday.

The mock consumes a `codes` sequence and repeats its final code. For a persistent insufficient-balance simulation use `"statusCode":"E1326"` instead of `codes`.

### Permanent error

```sh
curl -sS http://localhost:8081/admin/config -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000003","statusCode":"E1308"}'
curl -sS http://localhost:8080/subscriptions -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000003"}'
curl -sS http://localhost:8080/jobs/bill -H 'Content-Type: application/json' -d '{}'
curl -sS 'http://localhost:8080/subscribers/status?subscriberId=tel%3A1000000003'
```

Payment becomes `FAILED_PERMANENT`; subscription becomes `SUSPENDED`. No automatic retries. An explicit opt-in may reactivate the subscription, but cannot create a second payment for the same day.

### Duplicate subscription callback

The mock injects the configured application ID and makes a real HTTP callback. Use exactly the same `timeStamp` for duplicates:

```sh
curl -sS http://localhost:8081/admin/notify -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000004","status":"REGISTERED","timeStamp":"2026-01-01T00:00:00Z"}'
curl -sS http://localhost:8081/admin/notify -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000004","status":"REGISTERED","timeStamp":"2026-01-01T00:00:00Z"}'
curl -sS http://localhost:8080/jobs/bill -H 'Content-Type: application/json' -d '{}'
```

Both callbacks return `S1000`; a unique event fingerprint protects processing. Callbacks never initiate a debit. `UNREGISTERED` makes the subscriber inactive. `PENDING` and `CHARGE` are acknowledged and audited without activating or charging. Older lifecycle events are ignored; a STOP/UNREGISTERED tombstone can only be cleared by explicit opt-in.

### STOP through inbound SMS

```sh
curl -sS http://localhost:8081/admin/mo -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000001","message":"STOP"}'
curl -sS http://localhost:8080/jobs/bill -H 'Content-Type: application/json' -d '{}'
curl -sS 'http://localhost:8080/subscribers/status?subscriberId=tel%3A1000000001'
```

The service commits `INACTIVE`, unsubscribes at the carrier, and sends goodbye. Repeated STOP is safe. Once STOP completes, billing cannot start another debit. An already dispatched debit is allowed to finish before STOP completes; the carrier contract has no cancellation primitive. If remote unsubscribe fails, the local inactive barrier remains committed; retry STOP to finish remote deregistration.

### Masked identity

```sh
curl -sS http://localhost:8080/subscriptions -H 'Content-Type: application/json' -d '{"subscriberId":"masked:AB***91","smsDestination":"tel:1000000005"}'
```

`subscriberId` is the carrier's stable canonical identity, preserved exactly in billing, locking, callbacks, and audit. A `tel:` identity is validated as 5–15 digits; other stable ASCII aliases are opaque. Opt-in with an alias requires a separate valid SMS destination. A callback-created alias can exist without a destination; outbound SMS waits until that destination is bound through opt-in. The API contract has no real-number-to-alias mapping: callers must consistently use the same canonical identity. Text STOP using that identity via `/admin/mo`.

### Ambiguous outcomes and faults

```sh
curl -sS http://localhost:8081/admin/config -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000006","loseResponse":true}'
curl -sS http://localhost:8080/subscriptions -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000006"}'
curl -sS http://localhost:8080/jobs/bill -H 'Content-Type: application/json' -d '{}'
curl -sS 'http://localhost:8080/subscribers/status?subscriberId=tel%3A1000000006'
```

The mock commits a debit but returns HTTP 503. The service records `UNKNOWN`, sends no receipt, and blocks further automatic debits for this subscriber (including later days). There is intentionally no unsafe "retry with a new key" or public reconciliation button. `E1337`, unexpected codes, malformed success, timeouts, and recovered `PROCESSING` also require external reconciliation. Carrier support/ledger evidence is needed before a controlled database correction; the supplied contract has no lookup endpoint.

`/admin/config` also supports `"delayMs":1000` (0–10000), codes `S1000`, `E1326`, `E1308`, `E1337`, `E1318`, and `"loseResponse":false`. Existing successful keys always return `E1337` without another debit. Failures known to be uncharged do not consume a key, allowing top-up retries with that key.

## Automated verification

```sh
docker compose --profile test run --build --rm tests
```

This starts dependencies as needed and runs PHPUnit against real MySQL and the HTTP mock. Tests use new randomized subscriber identities and do not reset demo data. They cover concurrent workers, a selected worker paused before STOP, in-flight debit vs STOP, worker termination after the carrier commits, lost responses, duplicate callbacks, same-day reruns after restart, retry bounds/backoff, permanent failure, alias routing, outbox recovery, and HTTP flows. Tests advance retry due timestamps directly in their own rows, without a production API for bypassing backoff.

With a local PHP/Composer installation, `composer install && composer test -- --testsuite unit` runs standalone unit tests. Integration tests explicitly skip without `DB_DSN`; the Docker test command runs both suites. See [VERIFICATION.md](VERIFICATION.md) for what was actually executed in the implementation environment.

## Configuration, persistence and audit

`Config` supports `DB_DSN`, `DB_USER`, `DB_PASSWORD`, `PLATFORM_URL`, `APPLICATION_ID`, `PLATFORM_PASSWORD`, `DAILY_FEE`, `SOURCE_ADDRESS`. Secret values default to files in `/run/daily-pass`; Compose generates them once. No carrier credentials are committed, returned by APIs, or logged. Do not delete just the secrets volume while retaining database data. Database and mock persistence are named volumes; do not remove volumes unless you intend to discard their data.

The schema is in `service/migrations/001_initial.sql`; applied versions live in `migrations`. No PDO SQL/body debug logs are enabled. A finance query inside MySQL:

```sql
SELECT s.subscriber_id, p.billing_day, p.amount, p.state,
       p.external_trx_id, p.reference_id, p.last_code
FROM subscribers s JOIN payments p ON p.subscriber_id = s.id
WHERE s.subscriber_id = 'tel:1000000001' AND p.billing_day = UTC_DATE();
```

`SUCCEEDED` with a reference is confirmed paid; `UNKNOWN` is unresolved, not a failed payment. All attempts are in `charge_attempts`; join via `payment_id`. SMS outbox is durable; invoke `/jobs/sms` or the billing job to drain retries. Delivery is at-least-once because the carrier offers no SMS deduplication key. Failed deliveries remain queryable after five tries.

## Layout and boundaries

- `service/src/Subscriptions.php`: opt-in/out and webhook lifecycle.
- `Billing.php`, `RetryPolicy.php`: payment state machine and retry rules.
- `Store.php`: SQL, transactions, per-subscriber locking and outbox persistence.
- `HttpCarrier.php`, `Sms.php`: carrier boundary and SMS delivery.
- `platform-mock/`: SQLite-backed carrier simulation, independently runnable in Compose.
- `tests/`: PHPUnit unit/integration tests and concurrent worker harness.

Real auth/SSO, multiple currencies, USSD, DLR, Query Balance, production infrastructure, and a polished UI are deliberately excluded. Read [DECISIONS.md](DECISIONS.md) for the consistency boundary and known limitations, and [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) for the initial implementation plan.

Subscription transitions are explicit: a new identity starts `INACTIVE`; successful opt-in or an eligible REGISTERED callback makes it `ACTIVE`; STOP/UNREGISTERED makes it `INACTIVE` with a tombstone; E1308 makes it `SUSPENDED`. Only explicit opt-in clears a tombstone or suspension. Payment uncertainty is represented on the payment, and independently blocks future billing even if the subscription still reads ACTIVE.
