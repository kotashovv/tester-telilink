# Test Assignment — Carrier-Billing Daily Subscription

**Role:** Backend / full-stack developer. AI-assisted "vibecoding" is **expected and allowed** —
paste this into Claude and work with it. We are an AI-native team; act decisively, don't wait
for perfect clarity.

**What we evaluate:** not how much AI you used, but whether the result is **correct, safe with
money, and something you fully understand.** You will be asked to explain your own code on a
short call.

**Scope is deliberately narrow.** We care about depth on the money path, not breadth. A thin
slice that is provably correct beats a broad slice that double-charges.

---

## The product

Build **"Daily Pass"** — a paid daily subscription on top of a mobile Carrier Platform. A
subscriber opts in, is charged a fixed daily fee from their mobile account, gets an SMS receipt,
and can stop any time by texting `STOP`.

You build **two runnable pieces**:

1. **`service/`** — the subscription service (your product).
2. **`platform-mock/`** — a small mock of the Carrier Platform (the endpoints below) so the flow
   runs locally with no real operator. The mock must return a **configurable** status code for a
   charge (at least `S1000`, `E1326`, `E1308`, `E1337`) and be able to **push a subscription
   notification callback** to your service.

The whole thing must come up with **`docker compose up`** — a single command, no manual steps.
This is non-negotiable: reproducibility is part of what we grade. It must run cleanly on a fresh
machine with only Docker installed (no local runtime, no "install X first"). Expose a way to
drive the scenarios (a tiny UI, a CLI, or documented `curl`s).

---

## Required functionality

### F1 — Opt-in
- An entry point (HTTP endpoint or UI action) takes an MSISDN, calls the Platform
  `POST /subscription/send` with `action:"1"`, persists the subscriber as **ACTIVE**, and sends a
  welcome **SMS (MT)**.
- A subscriber who is already active must not be double-subscribed.

### F2 — Subscription notification webhook
- Expose the callback the Platform calls (§B). Handle at least `REGISTERED` and `UNREGISTERED`,
  reply `S1000`.
- The webhook must be **idempotent** — the same event delivered twice must not corrupt state or
  cause an extra charge.

### F3 — Daily charging
- A job (scheduled or manually triggerable for the demo) charges every ACTIVE subscriber the
  daily fee **at most once per billing day**:
  - call **Direct Debit** with a deliberately-designed **idempotency key** (`externalTrxId`);
  - on `S1000` → record the transaction, send an SMS **receipt** with the `referenceId`;
  - on `E1326` (insufficient balance, **transient**) → apply a **grace / retry** policy;
  - on `E1308` (**permanent**) → **suspend** the subscriber.
- Re-running the job the same day must **not** produce a second charge.

### F4 — Opt-out
- Handle an inbound **MO SMS** `STOP` (§C): call `/subscription/send` with `action:"0"`, mark the
  subscriber **INACTIVE**, send a goodbye SMS.
- After opt-out the subscriber must **never** be charged again — mind the race between opt-out and
  an in-flight charging job.

### F5 — Status
- An endpoint that returns a subscriber's current state (your view).

---

## Non-functional requirements (this is where the points are)

- **Money safety / idempotency:** a subscriber is charged **at most once per billing day** even
  under duplicate webhooks, job re-runs, retries, and restarts. Design the `externalTrxId`
  deliberately and explain it.
- **Retry policy:** distinguish **retry-able vs non-retry-able** codes. Bounded retries with
  backoff. Never retry a non-retry-able error into a double charge.
- **Secrets:** `applicationId` / `password` come from config/env — never hard-coded, never logged.
- **Validation:** validate MSISDN format (`tel:` + digits) and amount (2-dp); reject junk at the
  boundary.
- **Masked MSISDN:** your code must work when `subscriberId` is a stable masked alias, not a real
  number (don't assume you can parse a phone number out of it).
- **Persistence & audit:** subscribers and every charge attempt (status, `externalTrxId`,
  `referenceId`, timestamp) are persisted and queryable. A finance person must be able to answer
  "was MSISDN X charged today, and what's the reference?".

**Out of scope (say so, don't build):** real auth/SSO, multi-currency, USSD, DLR handling,
Query Balance, production infra, pixel-perfect UI.

---

## Deliverables

1. A git repo with `service/` and `platform-mock/`.
2. `README.md`: the exact `docker compose up` run, and how to trigger each scenario (opt-in,
   daily charge success, insufficient balance, duplicate webhook, opt-out).
3. Automated tests for the money-critical paths: **charge-once idempotency**, **retry
   classification**, **opt-out-then-charge race**. This is where coverage matters.
4. `DECISIONS.md` — ~half a page: key trade-offs, what you deliberately skipped, known gaps.

---

## Acceptance checklist (self-check before submitting)

- [ ] `docker compose up` on a clean machine brings up service + mock, no manual steps.
- [ ] Opt-in registers the subscriber and sends a welcome SMS visible in the mock.
- [ ] Daily charge success charges once, stores the transaction, sends a receipt with `referenceId`.
- [ ] Running the daily job **twice** in the same day charges **once** (prove it in a test).
- [ ] A duplicate subscription notification does **not** double-charge (prove it).
- [ ] Insufficient balance (`E1326`) and permanent error (`E1308`) are handled **differently**.
- [ ] `STOP` opts the subscriber out; a subsequent charge run skips them.
- [ ] No credentials in code or logs.

---

## Evaluation rubric (100)

| Area | Pts | What "great" looks like |
|------|-----|--------------------------|
| Money safety & idempotency | 35 | Provably charge-once; sound `externalTrxId`; survives restarts/dupes |
| Correctness of flows (F1–F5) | 25 | All flows work end-to-end against the mock |
| Error & retry handling | 15 | Retry-able vs not correctly classified; backoff; no double charge on retry |
| Tests | 10 | Money paths covered; failures simulated via the mock |
| Security & config hygiene | 8 | Secrets in env; input validation; masked-MSISDN safe |
| Code clarity & docs | 7 | Readable, small modules; no dead AI cruft; honest DECISIONS.md |

**Hard gate:** if `docker compose up` does not bring the whole thing up on a clean machine, the
submission is not graded further — reproducibility comes first. "It worked on my machine" is a
fail.

A submission that "looks done" but **double-charges under a duplicate webhook or a job re-run
fails** — regardless of polish. Getting the money right beats breadth.

---

# Appendix — Platform API (the only integration contract)

Brand-neutral reference for a generic mobile-operator billing gateway ("the Platform").
Transport: **HTTP + JSON**, `Content-Type: application/json`. Base URL is a placeholder
(`https://api.carrier.example`) — point it at your mock. Auth: every request carries
`applicationId` + `password` in the JSON body (no OAuth).

Every response includes `statusCode` + `statusDetail`.

## §A — Opt-in / Opt-out — `POST /subscription/send`
```json
{ "applicationId": "APP_000123", "password": "<password>", "version": "1.0",
  "action": "1", "subscriberId": "tel:1000000001" }   // action "1" = opt-in, "0" = opt-out
```
Response:
```json
{ "version": "1.0", "requestId": "1374746416574", "statusCode": "S1000",
  "statusDetail": "SUCCESS", "subscriptionStatus": "REGISTERED" }   // "UNREGISTERED" for opt-out
```

## §B — Subscription Notification (Platform → your webhook)
The Platform calls **your** endpoint when a subscription event happens. Fields:
`applicationId`, `subscriberId`, `frequency`, `status`, `version`, `timeStamp`, where
`status ∈ REGISTERED | UNREGISTERED | PENDING | CHARGE`. Your endpoint replies:
```json
{ "statusCode": "S1000", "statusDetail": "Success" }
```

## §C — SMS
- **Send (MT)** — `POST /sms/send`: `applicationId`, `password`, `message`,
  `destinationAddresses: ["tel:1000000001"]`, `version`, `sourceAddress`, `encoding:"0"`.
- **Receive (MO)** — Platform → **your** MO endpoint when a subscriber texts your shortcode:
  `applicationId`, `sourceAddress` (the subscriber), `message`, `encoding`, `version`.
  Reply `{ "statusCode": "S1000", "statusDetail": "Success" }`.

## §D — Direct Debit — `POST /caas/direct/debit`
```json
{ "applicationId": "APP_000123", "password": "<password>",
  "externalTrxId": "25609",                 // YOUR id — maps request↔response, dedupe key
  "subscriberId": "tel:1000000001",
  "paymentInstrumentName": "Mobile Account",
  "amount": "5.00", "currency": "XTS" }
```
Response:
```json
{ "statusCode": "S1000", "statusDetail": "Request was successfully processed",
  "externalTrxId": "25609", "internalTrxId": "913080108430074",
  "referenceId": "80430074", "timeStamp": "2013-08-01T08:43:34.344+05:30" }
```
**`externalTrxId` is the idempotency key.** The Platform detects duplicates via it (`E1337`).
Reusing the same value for a logical retry is intentional; a fresh value creates a second charge.

## §E — Status codes you must handle

| Code | Meaning | Class |
|------|---------|-------|
| `S1000` | Success | terminal-ok |
| `E1337` | Duplicate request (same `externalTrxId`) | non-retry-able |
| `E1308` | Permanent charging error | non-retry-able → suspend |
| `E1326` | Insufficient balance (transient — user may top up) | retry-able → grace/retry |
| `E1318` | Per-second transaction limit exceeded | retry-able → throttle/backoff |

> Design note: `E1326` and `E1308` both mean "not charged", but call for **different** handling
> — grace/retry vs. suspend. Decide your policy deliberately.
