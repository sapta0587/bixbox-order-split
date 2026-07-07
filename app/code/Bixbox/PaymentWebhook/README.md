# Bixbox_PaymentWebhook

Magento 2 module that exposes a **REST webhook endpoint** for external
payment-gateway providers. It accepts the **dynamic, inconsistent** payloads
gateways send (three variations shown in the brief), safely normalises them,
identifies the corresponding order via `payment_id`, applies the correct
state transition, and handles **repeated / duplicated** webhooks
idempotently.

> Bonus task of the Full Stack Engineer coding test for bixbox.co.id.
> Independent of [`Bixbox_OrderSplit`](../../README.md); both modules ship in
> this repo under `app/code/Bixbox/`.

---

## Endpoint

```
POST /bixbox/webhook
Content-Type: application/json
X-Bixbox-Webhook-Token: <shared secret>

<any JSON object the gateway sends>
```

- A **frontend controller** (`Controller/Webhook/Index.php`) is used rather than
  a `webapi.xml` route on purpose: webapi requires the body to be wrapped under
  the service-method parameter name (e.g. `{"payload":{...}}`), but payment
  gateways POST their own raw dynamic JSON. The controller reads `php://input`
  directly so the gateway's body is accepted verbatim. It implements
  `HttpPostActionInterface` (GET → 404) and `CsrfAwareActionInterface` (the
  form-key check is bypassed — gateways cannot send one).
- **Anonymous** (no Magento customer token) — gateways can't obtain one.
- **Gated by a shared secret** in the `X-Bixbox-Webhook-Token` header, compared
  with `Stores → Configuration → Bixbox → Payment Webhook → Security → Shared
  Secret` using `hash_equals` (constant-time). An empty configured secret is
  **fail-closed**: every call is refused (401). A disabled module returns 400.

### Response

```json
{
  "order_id": 42,
  "state": "processing",
  "status": "processing",
  "action": "invoice",
  "idempotent": false,
  "payment_id": "123xx"
}
```

`idempotent: true` means an identical payload was already processed and **no
order mutation occurred** on this call.

### Errors

| HTTP | Cause |
|------|-------|
| 400  | Module disabled / payload missing `payment_id` or `payment_detail.status` / unknown status |
| 401  | Missing or mismatched `X-Bixbox-Webhook-Token` |
| 404  | No order carries the given `payment_id` |
| 500  | Order state transition threw |

---

## The three brief variations

All three are handled by one normaliser:

```json
// Variation One
{ "payment_id": "123xx", "payment_detail": { "status": "paid", "va_code": "xx001" } }

// Variation Two
{ "payment_id": "123xx", "payment_detail": { "status": "paid", "qr_code": "xx001" }, "items": [ { "sku": "sku1", "qty": 100 } ] }

// Variation Three
{ "payment_id": "123xx", "payment_detail": { "status": "authorize" }, "customer": { "email": "john.doe@example.com" } }
```

The normaliser extracts `payment_id`, `status`, `va_code`, `qr_code`, `items[]`
and `customer.email` and yields `null` / `[]` for anything missing — it never
throws on a malformed payload. `items` and `customer.email` are accepted but
not used for state transitions (see "Assumptions" below).

---

## How it works

```
POST /bixbox/webhook
   │
   ▼
Controller\Webhook\Index::execute()
   │  0. read php://input, json_decode → array (else 400)
   │  1. isEnabled()?  else 400
   │  2. token == configured secret (hash_equals)?  else 401
   ▼
WebhookProcessor::process($payload)
   │  3. IdempotencyKey::hash($payload)           ← pure static, sha256 of canonical JSON
   │  4. WebhookLogRepository::getByPayloadHash() ← if hit → return cached result, idempotent=true
   │  5. Normalizer::normalize($payload)          ← pure static, → WebhookPayload DTO
   │  6. validate (payment_id + known status)     ← else 400
   │  7. OrderFinder::findOrderIdByPaymentId()    ← indexed select on sales_order_payment.bixbox_payment_id; else 404
   │  8. StatusMapper::resolve($status)           ← pure static, → {state, action}
   │  9. OrderUpdater::update(orderId, state, action)
   │       ACTION_INVOICE → InvoiceService + Transaction (capture online) → STATE_PROCESSING
   │       ACTION_CANCEL  → Order::cancel() → STATE_CANCELED
   │       ACTION_NONE   → setState + setStatus only
   │ 10. persist WebhookLog row (unique payload_hash → DuplicateException = concurrent replay, return idempotent)
   ▼
{ order_id, state, status, action, idempotent, payment_id }
```

The **pure logic** (`Normalizer`, `StatusMapper`, `IdempotencyKey`) is static
and unit-tested without a Magento bootstrap; the Magento glue
(`Controller\Webhook\Index`, `WebhookProcessor`, `OrderFinder`, `OrderUpdater`,
`WebhookLog*`) is covered by integration testing against a running Magento.

---

## Schema (declarative)

`etc/db_schema.xml` adds:

1. **`bixbox_payment_webhook_log`** — the idempotency ledger.
   - unique index on `payload_hash` (the dedupe gate; first writer wins),
   - secondary index on `payment_id` for audit lookups,
   - audit columns: `status`, `action`, `order_id`, `order_state`, `payload`
     (canonical JSON), `is_duplicate`, `received_at`.
2. **`sales_order_payment.bixbox_payment_id`** (+ btree index) — where the
   gateway's `payment_id` is persisted so the webhook can look the order up.

`setup:upgrade` creates both. `bin/magento setup:db-declaration:generate-whitelist`
already produced `etc/db_schema_whitelist.json`.

---

## Status → Magento state mapping

`StatusMapper` (conservative; unknown statuses are a **no-op** so the webhook
never corrupts an order on a status it doesn't recognise):

| Gateway status                              | Magento state        | Action     |
|---------------------------------------------|----------------------|------------|
| `paid`, `capture(d)`, `success(ed)`, `settlement` | `processing`    | invoice    |
| `authorize(d)`, `pending(_payment)`, `waiting`    | `pending_payment` | none       |
| `failed`, `expired`, `denied`, `cancel(ed/led)`   | `canceled`      | cancel     |
| `hold`, `on_hold`                            | `holded`             | none       |
| _anything else_                             | `holded` (no-op)     | none       |

`ACTION_INVOICE` creates an online-capture invoice for the full order and
moves it to `processing`; `ACTION_CANCEL` cancels the order (restocks items,
voids the payment); `ACTION_NONE` only sets state + status.

Terminal-state guard: an order already in `complete` or `canceled` is never
re-invoiced or re-cancelled, even if the idempotency log were bypassed.

---

## Configuration

**Stores → Configuration → Bixbox → Payment Webhook**

- **General → Enable Webhook Endpoint** — master switch (fail-closed).
- **Security → Shared Secret** — the token the gateway must send in the
  `X-Bixbox-Webhook-Token` header. Empty = refuse all calls.

---

## Installation (into a Magento 2 store)

```bash
cp -r app/code/Bixbox/PaymentWebhook <magento>/app/code/Bixbox/
bin/magento module:enable Bixbox_PaymentWebhook
bin/magento setup:upgrade                          # creates the 2 schema changes
bin/magento setup:di:compile                       # if running in production mode
bin/magento cache:clean
# then set the shared secret in Stores → Configuration → Bixbox → Payment Webhook
```

The OrderSplit module is **not** a dependency; either module can be installed
without the other.

---

## Testing

Pure-logic unit tests run **without a Magento bootstrap**:

```bash
composer install                                    # at the repo root
vendor/bin/phpunit -c phpunit.xml.dist             # runs both Bixbox modules' tests
```

- `NormalizerTest` — 13 tests covering the three brief variations + edge cases.
- `StatusMapperTest` — 8 tests covering every status branch + unknown status.
- `IdempotencyKeyTest` — 11 tests covering canonicalization, key-order
  independence, list-order significance and replay collisions.

The Magento glue is verified by integration testing against a running
Mage-OS 3.0.0 instance (see "Verification" below).

---

## Verification (integration)

Verified end-to-end against the same **Mage-OS 3.0.0** Docker instance used
for the OrderSplit module (PHP 8.5, Magento 2.4.7-based). The module was
`docker cp`'d into the `phpfpm` container, then `module:enable` +
`setup:upgrade` (applies `db_schema.xml`) + `setup:di:compile` + `cache:clean`.

| Check | Result |
|-------|--------|
| `module:enable` + `setup:upgrade` | ✅ module registers & upgrades cleanly |
| Declarative schema: `bixbox_payment_webhook_log` table + unique `payload_hash` index + `payment_id` index | ✅ confirmed in DB |
| Declarative schema: `sales_order_payment.bixbox_payment_id` column + btree index | ✅ confirmed in DB |
| `setup:di:compile` (validates the controller + processor + repository injections) | ✅ compiled, no DI errors |
| PHPUnit unit tests (`Normalizer` / `StatusMapper` / `IdempotencyKey`) | ✅ 32 tests pass |
| `POST /bixbox/webhook` — Variation One (`paid` + `va_code`) → real order | ✅ 200, `action=invoice`, order → `processing`, invoice minted (`total_invoiced=15`) |
| `POST /bixbox/webhook` — Variation One **exact duplicate** | ✅ 200, `idempotent=true`, **no second invoice**, no new log row |
| `POST /bixbox/webhook` — Variation Two (`paid` + `qr_code` + `items[]`) → real order | ✅ 200, `action=invoice`, order → `processing`, invoice minted |
| `POST /bixbox/webhook` — Variation Three (`authorize` + `customer.email`) → real order | ✅ 200, `action=none`, order → `pending_payment`, **no invoice** |
| No `X-Bixbox-Webhook-Token` header | ✅ 401 |
| Wrong `X-Bixbox-Webhook-Token` | ✅ 401 |
| Unknown `payment_id` | ✅ 404 |
| Missing `payment_detail.status` | ✅ 400 |
| Invalid JSON body | ✅ 400 |
| `GET /bixbox/webhook` (HttpPostActionInterface) | ✅ 404 |

Reproducing the integration run (the helper scripts live in the Docker
Magento repo, not this deliverable repo):

```bash
# from the bixbox-magento Docker repo
bin/clinotty php verify_webhook.php     # sets secret, ensures MSI stock, creates 3 tagged orders
bash webhook_curl_test.sh               # curls the 3 variations + duplicate + 6 negative cases
```

> Note: the Mage-OS instance's MSI salable-quantity is empty until the
> `Inventory` indexer is built — `bin/magento indexer:reindex inventory` is
> required before `quote->addProduct()` will accept a product (a known
> environment quirk, documented in `AGENTS.md`).

---

## Assumptions & decisions

1. **The webhook is anonymous but gated by a shared-secret header.** The brief
   doesn't mention auth, but "production-ready" implies the endpoint can't be
   open. A bearer-style header token is the simplest thing a gateway can send;
   the secret is constant-time compared with `hash_equals`. (HMAC body signing
   is a natural future extension — the controller already reads the raw body,
   so it has everything it needs to verify an `X-...-Signature` header.)

2. **`payment_id` is stored on `sales_order_payment.bixbox_payment_id`** (a new
   column + index via declarative schema). Persisting it at order-placement
   time is the gateway integration's job and is **out of scope for the
   webhook handler** — the brief asks the webhook to *identify* the order by
   `payment_id`, not to create the storage path. The integration test sets
   the column manually. (Alternatives considered: reusing `ext_order_id` —
   rejected as conflating external order id with payment id; reusing
   `last_trans_id` — rejected as Magento may overwrite it during auth flow.)

3. **Idempotency key = sha256 of canonicalized JSON** (keys sorted recursively,
   slashes unescaped). Exact-replay dedupe: a second call with the same bytes
   (in any key order) returns the cached result and touches no order. Distinct
   payloads (e.g. a later `paid` after an earlier `authorize`) hash differently
   and are processed. The unique index on `payload_hash` makes the dedupe
   atomic even under concurrent replays (`DuplicateException` → idempotent
   response). This is stricter than `payment_id + status` (which would wrongly
   dedupe two distinct payloads that happen to share a status) and far
   stricter than `payment_id` alone (which would block legitimate status
   updates).

4. **Unknown statuses are rejected at the boundary (400), known-but-unmapped
   statuses are a no-op.** `WebhookProcessor::validate()` uses
   `StatusMapper::isKnown()` to 400 any status word `StatusMapper` doesn't
   recognise — the safer default for a B2B store (forces the gateway to retry
   with a valid status rather than silently no-op'ing). Adjust per merchant
   policy if a no-op is preferred. The `StatusMapper::resolve()` fallback
   (state=holded, action=none) only fires for the empty-status edge case that
   `validate()` already guards.

5. **`items[]` and `customer.email` are accepted but not applied.** The brief
   shows them arriving, but the order already has its items and customer from
   checkout; the webhook's job is the payment state. They are persisted in the
   log row's `payload` column for audit. A future extension could reconcile
   item quantities against the order.

6. **One invoice per `paid` webhook, full order.** Partial captures are out of
   scope; the gateway's `items[]` is not interpreted as a partial-capture
   manifest. `InvoiceService::prepareInvoice` invoices all order items with
   online capture, then the order goes to `processing`.

7. **Terminal-state guard.** Even if the idempotency log were bypassed (e.g. a
   manual DB wipe), an order in `complete` or `canceled` is never re-invoiced
   or re-cancelled by `OrderUpdater` — the action degrades to `none`.

8. **The webhook does not create orders.** It only transitions the state of an
   existing order identified by `payment_id`. Order creation happens at
   checkout (or, in a gateway-redirect flow, by the gateway's return/redirect
   controller — also out of scope here).

---

## Limitations / future work

- **HMAC signature** support (in addition to the shared secret) for gateways
  that sign the body — straightforward now that the controller reads the raw
  body: add an `X-...-Signature` header check + the gateway's public key in
  admin config.
- **Admin grid** for `bixbox_payment_webhook_log` (the collection is already
  provided; only the `ui_component` XML is missing).
- **Config-driven status mapping** so merchants can override `StatusMapper`
  per gateway without code change (currently the map is a static `const`).
- **Partial capture** from `items[]` if a gateway sends a per-item settlement
  manifest.
