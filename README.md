# Bixbox_OrderSplit

Magento 2 module for **bixbox.co.id** (B2B platform for the paper manufacturing
industry) that fulfils the *Core Task* of the Full Stack Engineer coding test:

> When browsing the product list page or viewing a product detail page,
> customers need to know **which vendor supplies the product** and **which
> warehouse area the product originates from**.
>
> Orders should be split into multiple orders based on **catalog category**,
> **vendor** and **vendor warehouse area**, so customers can better plan their
> production process and financial calculations.

The module ships both behaviours:

| Requirement | How it is delivered |
|-------------|---------------------|
| **A. Show vendor + warehouse area on PLP & PDP** | Two admin-managed `select` product attributes (`vendor`, `warehouse_area`) + a layout-injected block on the product detail page + a thin `list.phtml` override on the product list page. |
| **B. Split orders by (category, vendor, warehouse area)** | An `around` plugin on `Magento\Quote\Model\QuoteManagement::placeOrder` that groups quote items by the composite key and places one order per group. |

---

## Requirements & compatibility

- **Magento 2.4.6+** (latest LTS). Developed and unit-tested on PHP 8.3;
  integration-verified against **Mage-OS 3.0.0** (Magento 2.4.7-based) on **PHP 8.5**.
- **PHP 8.1 / 8.2 / 8.3 / 8.4 / 8.5**.
- Depends on `Magento_Catalog`, `Magento_Quote`, `Magento_Checkout`,
  `Magento_Sales` (declared in `etc/module.xml` `sequence`).

---

## Repository layout

```
.
├── README.md                       ← this file (module overview, architecture, assumptions)
├── composer.json                   ← test-only autoloader (runs the unit tests without Magento)
├── phpunit.xml.dist                ← PHPUnit config (runs both Bixbox modules' tests)
└── app/code/Bixbox/
    ├── OrderSplit/                 ← Core task module (this README, above)
    └── PaymentWebhook/             ← Bonus task module (REST webhook handler; see its own README)
```

The repository ships **only the module** (plus the test scaffolding at the
repo root). Magento core, `vendor/`, Docker volumes, etc. are deliberately
gitignored — drop the module into a real Magento store to run it.

---

## Installation (into a Magento 2 store)

1. Copy `app/code/Bixbox/OrderSplit/` into your Magento root
   (`<magento>/app/code/Bixbox/OrderSplit/`).
2. Enable the module and run the upgrade (this creates the two product
   attributes via a data patch):

   ```bash
   bin/magento module:enable Bixbox_OrderSplit
   bin/magento setup:upgrade
   bin/magento setup:di:compile        # if running in production mode
   bin/magento cache:clean
   ```

3. *(Optional)* Assign values to a few products for testing, from
   **Catalog → Products** (the *Vendor* and *Warehouse Area* attributes appear
   in the *Product Details* group of the product form).

The data patch seeds a few clearly-labelled demo options (`Vendor A/B/C`,
`Warehouse North/South/East`) so the module is verifiable out of the box;
replace them with real values from **Stores → Attributes → Product**.

---

## Configuration

**Stores → Configuration → Bixbox → Order Split**

- **General → Enable Order Splitting** — master switch.
- **Product Page Display** — show the info on the product detail / list pages.
- **Split Criteria** — toggle splitting by *category*, *vendor* and/or
  *warehouse area* independently.

All flags default to enabled.

---

## How order splitting works

1. When an order is placed, `QuoteManagementPlugin::aroundPlaceOrder` loads the
   quote and asks `OrderSplitter::split()` whether a split is needed.
2. For each **top-level** quote item (children of configurable/bundle products
   travel with their parent and are never split off), a descriptor is built
   carrying the product's first category id, its `vendor` label and its
   `warehouse_area` label.
3. `OrderSplitter::computeGroups()` (a pure, unit-tested static method) groups
   the descriptors by a composite key built from **only the enabled
   dimensions**, e.g. `category=4|vendor=Vendor A|warehouse=Warehouse North`.
4. If there is a single group, the original quote is placed normally — no
   behaviour change. If there is more than one group, one **duplicate quote**
   per group is created (same customer, billing/shipping address, payment and
   store; only that group's items, re-added via each item's `buyRequest` so
   configurable/bundle options survive), totals are recalculated, and each
   duplicate is placed as its own order.
5. The original quote is then marked inactive (its items were all placed
   across the sub-quotes) and the **first** sub-order id is returned to the
   checkout flow. The customer receives one order-confirmation email per
   sub-order.

The plugin's `$proceed` closure is the already-advanced interception chain, so
calling it for the duplicate quotes does **not** re-enter the plugin — no
recursion guard is required.

---

## Testing

The pure grouping/key logic is covered by PHPUnit unit tests that run **without
a Magento bootstrap**:

```bash
composer install                 # installs PHPUnit only
vendor/bin/phpunit -c phpunit.xml.dist
```

The Magento-bound glue (`QuoteManagementPlugin`, `QuoteDuplicator`,
`Provider`, `VendorInfo`) is verified by integration testing against a running
Mage-OS 3.0.0 instance (see "Verification" below).

---

## Assumptions & decisions

The test brief left several points open; the following reasonable assumptions
were made (as permitted by the brief):

1. **Vendor & warehouse area are modelled as admin-managed `select` product
   attributes.** A `select` gives referential integrity (a product references a
   managed option rather than free text), is filterable in the admin grid, and
   its *label* (e.g. "Vendor A") is what customers see. If the merchant prefers
   free-text, the attribute `input` can be switched to `text` with no code
   change.

2. **"Catalog category" = the product's first assigned category id.** A product
   may belong to several categories; for splitting we use the first id returned
   by `Product::getCategoryIds()`. This is simple, deterministic and
   document-driven. (Alternative considered: splitting by the category the
   customer browsed from — rejected as stateful and surprising for finance
   calculations.)

3. **Only top-level quote items are split.** Children of configurable / bundle
   / grouped products always travel with their parent, so a configurable
   product is never separated from its simple variant.

4. **Payment capture split is out of scope.** Each sub-order inherits the
   original quote's payment method instance. Real per-sub-order payment
   authorisation/capture depends on the gateway and is left to the gateway
   integration; the module guarantees *order* splitting, not *payment*
   splitting.

5. **Shipping is recalculated per sub-quote.** Each duplicate quote collects its
   own totals, so shipping, tax and grand total reflect only that group's
   items. For flat-rate shipping this may result in the customer paying N
   shipping fees; merchants who want a single shipping charge can disable
   shipping as a split dimension (it isn't one) and apply a handling rule.

6. **The customer receives N confirmation emails** (one per sub-order). The
   checkout success page tracks the first sub-order id as the "last order".

7. **Partial-failure handling:** if placing one group's order throws, the error
   is logged and the remaining groups are still placed; the caller still owns
   the original (now inactive) quote.

8. **Product list page override:** Luma's `list.phtml` has no extension point
   for per-product extra attributes, so the module ships a thin override
   (`Bixbox_OrderSplit::product/list.phtml`) that adds a single child-block
   call under each product name. On theme upgrades, reconcile this file with
   the upstream Luma template and re-apply that one line. The override is
   version-pinned to Magento 2.4.6's Luma template.

9. **Quote-item attribute loading:** `vendor` and `warehouse_area` are added to
   Magento's `<quote><item><product_attributes>` list via `catalog_attributes.xml`
   so they are loaded on every quote-item product with no extra DB queries.

---

## Verification (integration)

A local **Mage-OS 3.0.0** instance (Magento-compatible community build, based on
Magento 2.4.7+, PHP 8.5) was brought up via `markshust/docker-magento` and used
to verify the module end-to-end against real Magento data:

| Check | Result |
|-------|--------|
| `bin/magento module:enable Bixbox_OrderSplit` + `setup:upgrade` | ✅ module registers & upgrades cleanly |
| Data patch creates `vendor` + `warehouse_area` as `select` attributes with seeded demo options + values | ✅ attributes (ids 145/146) + 6 options with value text confirmed in `eav_attribute_option(_value)` |
| `bin/magento setup:di:compile` (validates plugin + preference + all constructor injections) | ✅ compiled, no DI errors |
| PHPUnit unit tests (`computeGroups` / `buildKey`) | ✅ 13 tests, 21 assertions |
| `Provider` resolves vendor / warehouse area / first category from real products, fed into `OrderSplitter::computeGroups` | ✅ 3 products with distinct (category, vendor, warehouse) → **3 distinct groups** with correct composite keys |
| Live `placeOrder` split (plugin minting N duplicate quotes + N orders end-to-end) | ✅ a guest quote with BIX-PROD-1 (Vendor A / Warehouse North) + BIX-PROD-2 (Vendor B / Warehouse North) → **2 orders** (#000000012, #000000013); original quote deactivated |
| Live `placeOrder` **no-split path** (single group → original quote placed, plugin returns `[]`) | ✅ a guest quote with BIX-PROD-1 + BIX-PROD-4 (both Paper Rolls / Vendor A / Warehouse North → one composite key) → **1 order** (#000000024); and a mixed quote BIX-PROD-1 + BIX-PROD-4 + BIX-PROD-2 → **2 orders** (PROD-1+4 grouped into #000000025, PROD-2 alone as #000000026) |

The storefront HTML rendering (PDP/PLP vendor info block) is verified by curl
against `https://bixbox.test/` (the domain resolves from the Windows host in
this Docker setup): the `bixbox-vendor-info` block renders under each product
on both the category list and the product detail pages.

> **Note on the shipping-rate fix (commit `5e79226`):** the first end-to-end
> run surfaced a bug — `QuoteDuplicator` cloned the shipping address and copied
> `shipping_method` but never set `collectShippingRates`, so the cloned address
> carried no `quote_shipping_rate` rows and `ShippingMethodValidationRule`
> rejected every duplicate with *"The shipping method is missing."* When all
> groups fail the plugin falls back to placing the original quote, producing a
> single unsplit order. The one-line fix (`setCollectShippingRates(true)` on
> the cloned address) makes `collectTotals()` request and persist carrier rates
> so validation passes.

---

## Limitations / future work

- **Checkout success page only references the first sub-order.**
  `QuoteManagement::placeOrder` has a single-`int` return signature, so the
  plugin (`QuoteManagementPlugin.php:94`) returns only the first sub-order id
  via `reset($orderIds)`. Magento's checkout success page and `checkout/session`
  therefore track that one order as "the last placed order"; the remaining
  sub-orders are placed and confirmed by email but are **not** surfaced on the
  success page or in the customer's "My Orders" immediate redirect. This is the
  Magento-compatible choice (the API contract is one order id), but if the
  merchant wants the customer to see *all* sub-orders on the success page, a
  separate enhancement is required — e.g. persisting the sibling order ids on
  the first order (a custom `related_order_ids` column or the native
  `sales_order_relation`-style link table) and overriding
  `checkout_onepage_success.xml` / the success block to render them. The module
  does **not** do this today.
- Bundle products with complex option trees are re-added via `buyRequest`; if a
  bundle's buy request does not survive cloning (rare), a fallback to
  re-building the request from option codes would be added.
- No MSI-aware logic; Magento's default inventory reservations apply to each
  sub-order.

---

## Bonus task: payment-gateway webhook

The optional **bonus task** (a REST webhook handler for external payment
gateways with dynamic payloads, order lookup by `payment_id`, state
transition and idempotent replay handling) is implemented in a **separate
module**, [`Bixbox_PaymentWebhook`](app/code/Bixbox/PaymentWebhook/README.md),
which ships in this same repo under `app/code/Bixbox/PaymentWebhook/`. It is
independent of `Bixbox_OrderSplit` (no code or schema dependency) and can be
installed on its own. See its own README for the endpoint contract, schema,
status-mapping table and assumptions.
