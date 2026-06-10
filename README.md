# Customer Meta Query Optimization

A single-file WordPress **mu-plugin** that makes WooCommerce REST API
`?customer=X` order queries sargable, eliminating the full-index scan that
caused a production database saturation incident.

**Deliverable:** [`fix-customer-meta-query-cast.php`](fix-customer-meta-query-cast.php) → `wp-content/mu-plugins/`

## The problem

WooCommerce's REST API translates `GET /wp-json/wc/v3/orders?customer=X` into a
`WP_Meta_Query` with `type => 'NUMERIC'`, which generates:

```sql
CAST(wp_postmeta.meta_value AS SIGNED) = X
```

The `CAST()` makes the comparison non-sargable — MySQL cannot use any index on
`meta_value` and scans every `_customer_user` row (roughly one per order) on
every call. On a store with a multi-million-row `wp_postmeta` table, REST
consumers polling orders by customer consumed thousands of seconds of DB time
over a 12-hour window.

## The fix

A filter on the WooCommerce REST order query changes the `_customer_user` meta
query type from `NUMERIC` to `CHAR`, so the query becomes:

```sql
meta_value = 'X'
```

This is semantically identical for all valid user IDs (`_customer_user` stores
integer user IDs as strings) and lets MySQL use a companion composite index.

### The index and the mu-plugin are a pair

The plugin assumes this index exists on the target database:

```sql
ALTER TABLE wp_postmeta
  ADD INDEX idx_customer_user_post (meta_key, meta_value(20), post_id);
```

Without the index, the plugin removes the `CAST()` but the query still scans.
Without the plugin, the index is unused by these queries. Deploy both.

## Installation

1. Confirm the index exists:
   ```sql
   SHOW INDEX FROM wp_postmeta WHERE Key_name = 'idx_customer_user_post';
   ```
2. Copy `fix-customer-meta-query-cast.php` into `wp-content/mu-plugins/`
   (create the directory if needed). No activation step — mu-plugins load
   automatically.
3. Verify it appears under **Plugins → Must-Use** in wp-admin.

## Verifying it's active

On the first REST request where the rewrite fires, the plugin emits a
`customer_meta_query_cast_fixed` event via `Hypercart_Logger` (silently
skipped if the logger isn't installed). The event is deduped by a 30-day
transient **keyed to the WooCommerce version**, so it re-fires immediately
after a WooCommerce upgrade and again whenever the transient expires.
Silence after an upgrade is therefore unambiguous: the query shape changed,
the filter stopped matching, and the performance protection has silently
stopped — time to investigate.

## Scope and safety

- **REST only, by design.** Other code paths that query orders by customer
  (`wc_get_orders( [ 'customer' => X ] )`, admin order lookups) still use the
  slow `CAST()` path and are intentionally untouched.
- **Defensive by default.** The filter rewrites only the exact clause shape
  WooCommerce REST emits: a flat `_customer_user` clause, type `NUMERIC`,
  equality compare, scalar integer value. Range compares (`>`, `BETWEEN`) are
  lexicographic under CHAR (`'10' < '9'`) and are deliberately left on the
  slow-but-correct `CAST()` path — as is anything else unexpected. Never a
  fatal, never a warning; all other clauses pass through byte-identical.
- **Failure mode is performance, not correctness.** If a future WooCommerce
  version changes the query structure, the filter simply stops matching and
  queries revert to the slow-but-correct `CAST()` path.
- **CHAR is the safer comparison.** A malformed (non-numeric) `_customer_user`
  value would never match under CHAR; under NUMERIC it casts to `0` and could
  leak into `?customer=0` results.

## Obsolescence

**This plugin is obsolete after migrating to WooCommerce HPOS** (High-Performance
Order Storage): customer lookups move to `wp_wc_orders.customer_id`, which is
natively indexed. At that point, delete the file and drop
`idx_customer_user_post` once order meta has been fully migrated out of
`wp_postmeta`.

## Testing

A standalone smoke test covers the shape-guard logic — the canonical clause is
rewritten; range compares, array values, malformed clauses, and nested groups
are left untouched. No WordPress install needed:

```bash
php tests/test-filter.php
```

Re-run it after major WooCommerce upgrades, alongside the live SQL checks in
[PROJECT.md](PROJECT.md) Phase 2 (the test verifies the guard logic, not the
query WooCommerce actually emits).

## Project plan

See [PROJECT.md](PROJECT.md) for the full incident background, the decision
record (why this is a standalone mu-plugin rather than a
[Hypercart Query Guard](https://github.com/Hypercart-Dev-Tools) feature or a
third-party plugin fork), the phased verification/deployment checklist, and
risk notes.

## Requirements

- WordPress with WooCommerce (legacy post-based order storage, i.e. pre-HPOS)
- MySQL/MariaDB with the companion index above
- PHP 7.4+ (tested under PHP 8.x)

## License

See [LICENSE](LICENSE).
