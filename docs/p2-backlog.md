# P2 Backlog — deferred to the production phase

The POC architecture is **frozen** after Prompt 1d. Everything below was
deliberately left out of the POC and must be addressed in the production
foundation phase. Nothing here is a known correctness bug in the frozen
architecture; these are scope boundaries, accepted residual risks, and things the
POC could only simulate or observe on a single runtime.

## Accepted residual risks (Prompt 1d — deliberately not fixed)

Every item here needs third-party code to actively call WooCommerce or plugin
internals mid-send, which the Prompt 1d stopping rule classifies as accepted
residual risk. They are recorded so production can decide whether to instrument
them, not because the frozen architecture is wrong.

- **Third-party render from inside the mail callback**
  ([ADR-0005](adr/ADR-0005.md)). Code hooking `pre_wp_mail`, `phpmailer_init`, or
  replacing `woocommerce_mail_callback` can call `get_content()` on the same email
  object **after** the send has been bound at `woocommerce_mail_callback_params`,
  creating a newer `awaiting_send` slot. Correlation still holds — the real send
  finalizes against its own bound token and its own recorded order, and the
  intruding render creates **no** delivery record — but it leaves an extra slot
  reported `unresolved`. **Proven by POC-C's exact-send-correlation test.**
  *Production decision:* whether to meter/log these, and whether the orphan sweep
  below should reap them.
- **A nested full send from inside the mail callback.** Handled by the per-object
  bind **stack** (push at bind, pop at `woocommerce_email_sent`), but **untested**
  — no POC exercises it. Add a regression test in production.
- **Frame-side residue between an interrupted render and the next push**
  ([ADR-0003](adr/ADR-0003.md)). PHP offers no unwind hook at a call site we do not
  own, and WooCommerce's preview renderer has no `finally`, so teardown at the
  *instant* of interruption is not achievable. Residue is bounded by the next push
  (reconciliation) or shutdown, and **no delivery state exists in the window**.
  *Production decision:* whether a production request lifecycle offers an earlier
  reconciliation boundary than "next push".
- **Reconciliation is global to preview frames.** A real send triggered by
  third-party code from *within* a live preview render would have the enclosing
  preview frame reconciled early. WooCommerce never nests renders this way.

### Added in Prompt 2 Phase 0 — gaps the render-context rewrite must close

These three are **not** covered by the Prompt 1d proof. They are recorded here so
the render-context production class (a later prompt, out of scope for the
foundation) is built against them rather than rediscovering them.

- **Preview interrupted BEFORE the context push.** Prompt 1d only proved the case
  where the preview throws *after* `woocommerce_email_order_details` priority 5. If
  a preview throws earlier — during `set_email_type()`, placeholder resolution,
  `style_inline()`, or any filter that runs before the order-details action — the
  pending marker set by `woocommerce_prepare_email_for_preview` is **never
  consumed** and **no frame exists** for reconciliation to find. A same-object real
  send later in the same request would then consume that stale marker, be
  classified as a preview, and write **no delivery record** — a silently dropped
  delivery. **Production must reconcile pending markers, not only frames**: give
  each marker a bounded lifetime (e.g. drop it at the first push that does not
  belong to that object, or stamp it with a request-scoped sequence and expire it),
  and cover it with a regression test that throws before the push.
- **Preview interrupted AFTER the order-details pop but BEFORE the footer (HTML).**
  The frame is already gone at that point, so frame-based reconciliation cannot see
  anything — but the render's `open_footer` token survives, and the next HTML
  render of the same object pops *that* token instead of its own, mis-aligning the
  footer backstop LIFO. **Production fix:** when a preview frame is retired at
  `woocommerce_email_order_details` priority 15, **purge both of its open-token
  entries immediately** so the footer handler is a guaranteed no-op for that token.
  (Prompt 1d already does this for *plain* renders, which never fire the footer;
  extend it to preview renders regardless of format.)
- **Bind the send correlation at the earliest practical filter priority.** The POC
  binds at `woocommerce_mail_callback_params` priority **9999**, chosen so the bind
  happens after third-party params filtering. That is the wrong trade: a
  third-party callback registered at a *lower* priority runs first, and if it calls
  `get_content()` on the same email object it creates a newer `awaiting_send` slot
  **before** the bind — so the bind selects the wrong slot and the real send is
  attributed to the intruder's render. The binding callback returns its params
  unmodified, so it has no reason to run late. **Production must register it at the
  earliest practical priority** and carry a regression test with a competing filter
  registered on the same hook at both a lower and a higher priority.

## Flagged WooCommerce behaviour to re-check on version bumps

- **`woocommerce_is_email_preview` leaks after an interrupted preview** (verified
  WC 10.9.4). `EmailPreview::render_preview_email()` calls `clean_up_filters()`
  **without a `try`/`finally`**, so an exception mid-render leaves the filter
  attached and returning `true` for the rest of the request; a later *real* send
  then reports itself as a preview. The frozen design detects the leak
  (reconciliation finds a stale preview frame while the signal still reads true)
  and demotes the signal to the render-scoped marker. **Re-check whether upstream
  adds the `finally`** — if it does, the demotion path becomes dead code but stays
  correct. Worth an upstream bug report.
- **Orphaned output buffer after an interrupted preview.**
  `wc_get_template_html()`'s `ob_start()` is never closed on the exception path, so
  partial email HTML is flushed later in the request. Cosmetic for the POC; confirm
  it cannot interleave with admin-AJAX responses in production.

## Runtime / execution

- **Action Scheduler integration (currently SIMULATED in-memory).** POC-A S2b
  proves the ADR-0008 single-deferral *semantics* with an in-memory queue only.
  Production must prove: Action Scheduler action **uniqueness**, **identity
  serialisation** into the scheduled args, **cross-request execution**, **order
  reloading** at execution, and **cancellation** on rule disable/delete
  ([ADR-0007](adr/ADR-0007.md), [ADR-0008](adr/ADR-0008.md)).
- ~~**Delayed-delivery snapshot execution** end-to-end~~ **DONE — Prompt 7**
  ([ADR-0015](adr/ADR-0015.md)). Scheduling, snapshot storage on the durable
  tombstone, the six execution-time checks, both cancellation directions, and
  re-arm avoidance are implemented and asserted. The Action Scheduler bullet above
  is discharged for the DELAYED hook specifically: uniqueness is proven NOT to come
  from `$unique` (which ignores the argument set) but from the ADR-0004 claim;
  cross-request execution is driven through the real queue; cancellation on rule
  disable/delete is eager, with execution-time re-validation as the backstop.
- **Orphan/unresolved slot policy.** A render that never sends (e.g. a customizer
  calling `get_content()`) legitimately orphans its slot; the POC reports these
  `unresolved` at shutdown. Production should decide whether to log/meter them and
  whether a per-request sweep is warranted ([ADR-0005](adr/ADR-0005.md)). Note that
  after Prompt 1d an orphan can **never** be consumed by a later send, because
  finalization resolves only the token bound at
  `woocommerce_mail_callback_params` — so this is a reporting/hygiene decision, not
  a correctness one.
- **Rewrite the render-context module as a production class.** `poc/_render-context.php`
  is throwaway procedural harness code holding the frozen design (frames, slot state
  machine, preview suppression, bind stack, reconciliation). Production needs it as a
  real class with a PHPUnit suite covering every POC-C/POC-D assertion, plus the
  untested nested-send-inside-mail-callback case above.

## Persistence

- Production DB schema for the durable **tombstone** and purgeable **detail/
  attempt** stores + versioned migrations ([ADR-0004](adr/ADR-0004.md)).
- Daily **retention purge** recurring action and the **keep-indefinitely** option
  ([ADR-0005](adr/ADR-0005.md)).
- **Privacy exporter/eraser** for detail rows ([ADR-0005](adr/ADR-0005.md)).

## Compatibility matrix (all UNTESTED — [ADR-0006](adr/ADR-0006.md))

- **PHP** 8.0, 8.1, 8.2, 8.4 — POC syntax is statically held to 8.0 but ran on
  **PHP 8.3.6 only**; 8.0 runtime is unproven.
- **WooCommerce floor** — 8.2 is a single-combination observation; confirm across
  versions and consider aligning with the sibling Address Book plugin's floor.
- **HPOS off** (legacy post-based order storage).
- **`email_improvements` off** and **`block_email_editor` on**.
- **Named third-party email customizers** — YayMail, VillaTheme Email Template
  Customizer, Kadence Email Designer, ThemeHigh Email Customizer (free tiers). No
  named claim until each passes the matrix.

## Coverage

- **Front-end endpoint coverage** via true end-to-end tests: My Account
  view-order, thank-you, and order-pay pages. The POC's front-end negative gate is
  **template-level** (`woocommerce_order_details_table` + direct hook firing).
- Real SMTP/delivery and bounce handling (the POC captures `wp_mail`).

## Packaging / release

- `.pot` generation for UI strings, `readme.txt`, sanitized wp.org screenshots,
  and the release zip.
- The single `WC_Email` settings screen + rules-screen admin UI
  ([ADR-0002](adr/ADR-0002.md)).
## Added in Prompt 2 — foundation deferrals

- **Plugin Check has not been run.** The plugin is not installed on this
  runtime and installing it would write outside the project root, which the
  Prompt 2 workspace scope forbids. PHPCS with `WordPress-Extra`,
  `WordPress-Docs` and `PHPCompatibilityWP` passes at 0 errors / 0 warnings,
  which covers a large part of what Plugin Check inspects, but it is **not** a
  substitute. Run Plugin Check before wp.org submission and resolve every
  blocking finding. One finding is already known and expected: **no
  `readme.txt` exists yet** (already deferred under *Packaging / release*).
- **`languages/` is empty and no `.pot` is generated.** `load_plugin_textdomain()`
  is wired and every string is translated, but the catalogue is generated in the
  packaging phase (already listed under *Packaging / release*).
- **The retention purge has no scheduler yet.**
  `DeliveryDetailRepository::purge_older_than()` and the ADR-0005 retention
  settings exist and are tested, but nothing schedules the daily recurring
  action. `Deactivator::RECURRING_HOOKS` already names
  `extonify_wcep_retention_purge` so deactivation unschedules it the moment it
  is registered.
- **Multisite is per-site activation only, and untested.** Network activation is
  refused with an explanatory notice and writes nothing; that policy is
  implemented and documented but has not been exercised on a real multisite
  install (this runtime is single-site).
- **No `dbDelta()` upgrade step has been exercised.** The migration runner
  supports ordered steps but only migration 1 exists, so the additive-change
  path (`TARGET_DB_VERSION` 1 → 2) is structurally present and untested. The
  first real schema change must add an integration test that migrates from 1.

## Added in Prompt 2a

- **`bin/uninstall-smoke.php` needs an explicit opt-in.** It drops all three
  tables. Run it as
  `EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1 php bin/uninstall-smoke.php --i-understand`
  and confirm the database name when prompted. It refuses outright without the
  opt-in, and on this box the strict path (`WP_ENVIRONMENT_TYPE` local/development
  **and** a `_test`-suffixed database) cannot be satisfied — the environment
  reports the WordPress default `production` and the database is named
  `extonify`. **Consider setting `WP_ENVIRONMENT_TYPE=development` in
  `wp-config.php` on development installs**, which is correct independently of
  this plugin.
- **`reason` and `failure_message` are stored with shape-only sanitisation**
  (`Domain\Text::log_value()`), not `sanitize_text_field()`/
  `sanitize_textarea_field()`, because those strip `<alice@example.test>` from
  an SMTP diagnostic as though it were an HTML tag. Whatever renders these
  values **must escape them at output** — there is no HTML sanitisation on the
  way in, by design.
- **The delivery engine must resolve recipients to one row per address.** The
  storage layer now rejects a comma-joined list outright. When the engine is
  built it has to split `to`/`cc`/`bcc` itself and write one detail row each,
  sharing `delivery_id` and `attempt`.
- **The retention purge still has no scheduler.**
  `purge_older_than( normal, failed, debug )` is implemented and tested against
  all three ADR-0005 windows, but nothing calls it yet.
  `Deactivator::RECURRING_HOOKS` already names `extonify_wcep_retention_purge`.
- **A superseded index is not dropped by `dbDelta()`.** Replacing the
  `created_at`/`is_debug` indexes with the composite `retention` index left the
  old two behind on the development database; they had to be dropped by hand.
  A fresh install is unaffected (verified), and schema v1 is unreleased so no
  user is. **Any future index replacement needs an explicit `DROP INDEX`
  migration step** — `verify_schema()` checks that required objects exist, not
  that superseded ones are gone.

## Added in Prompt 2b — the foundation is frozen after this

- **Maintenance sweep for rows whose order no longer exists (production
  requirement).** `Plugin::on_order_deleted()` is fail-closed: if the child
  delete fails, the tombstones are deliberately left in place rather than
  orphaning their detail rows. There is **no automatic retry** — the hook fires
  on permanent deletion and a deleted order is not deleted again, so those rows
  persist. They remain safely linked to each other and reachable by the privacy
  exporter and eraser, so this is a housekeeping gap, not a correctness or
  privacy one. Production needs a scheduled sweep that removes tombstones (and
  their details) whose `order_id` no longer resolves through `wc_get_order()`.
  Pair it with the retention purge scheduler, which is still unbuilt.
- **`verify_schema()` runs six-plus metadata queries per request.** It is
  memoized per request and only on the paths that touch storage, but once the
  engine is doing real work per order it is worth measuring whether
  `is_operational()` should fall back to the cheap version-plus-tables check and
  reserve full verification for activation, upgrade and an admin diagnostic.
- **The order-linked privacy path calls `wc_get_orders()` twice** (once by
  `billing_email`, once by `customer`). Since Prompt 2d the queries are chunked
  at 100 orders, but the INVOCATION is still not cursor-bounded — see the
  documented bound in [ADR-0010](adr/ADR-0010.md) and the cursor-pagination item
  below.
- **`WP_ENVIRONMENT_TYPE` is unset on this install**, so WordPress reports the
  default `production` and the integration suite must be told
  `WP_ENVIRONMENT_TYPE=development` on every invocation. Setting it once in
  `wp-config.php` is correct independently of this plugin — see
  `docs/testing.md`.

## Added in Prompt 2c — the foundation is frozen after this

- **Third-party redaction is address-based, not semantic.** When a subject
  access request surfaces a row addressed to someone else, the exporter omits the
  contact columns and redacts that person's address from the content fields. It
  cannot redact a third party's *name* if a diagnostic happens to spell it out
  without the address. Bounded in practice — the engine writes SMTP responses and
  its own reasons, both of which quote addresses — but if free-text ever carries
  operator-authored prose, revisit it.
- **`SubjectData` runs two `wc_get_orders()` lookups per call**, and the
  exporter/eraser call it once per page. The order lookup is **chunked** into
  queries of 100 — which is not the same as paginating the request: every
  matching order id is still collected before anything is sliced. See the
  documented bound in [ADR-0010](adr/ADR-0010.md). Consider memoising the
  resolved order and detail id lists per request.
- ~~**The eraser pages by offset over a stable id list.**~~ **CORRECTED in
  Prompt 2d — the claim was false.** The set is *not* stable: erasure nulls
  `recipient`, which is one of the two columns resolution matches on, so rows
  drop out from under the cursor and offset paging silently skips them. The
  eraser now re-resolves ELIGIBLE rows on every invocation and always takes from
  the front ([ADR-0010](adr/ADR-0010.md)); offset paging there is prohibited.
- **Row-level locking is proven on two connections but not under real
  concurrency.** `ParentLockingTest` opens a genuine second `wpdb` connection and
  demonstrates that the parent row lock blocks a competing `FOR UPDATE` until
  timeout. It does not run two interleaved PHP processes. A process-level
  concurrency harness would be stronger; the row lock plus the fail-closed
  cleanup is the guarantee that actually matters.
- **`bin/create-test-db.php` clones the whole install (54 tables, ~16 MB).**
  Fine here; on a large development database prefer a schema-only clone plus a
  seeded fixture set.
- **The test database drifts from the development database over time.** Re-run
  `php bin/create-test-db.php --force` after any WordPress, WooCommerce or
  sibling-plugin change that the integration suite depends on.

## Added in Prompt 2d — pre-release privacy work

The foundation is frozen after this pass. The items below are **pre-release**,
to be done in a dedicated privacy pass, not another foundation round.

- **Cursor-level pagination for privacy resolution.** ADR-0010 states the bound
  honestly: resolution loads all matching order ids and all in-scope detail ids
  per invocation before slicing. Queries are chunked; the invocation is not
  cursor-bounded. This comfortably serves an ordinary customer — a few hundred
  orders and a few thousand rows resolve to tens of kilobytes of ids. It is
  **not** appropriate for tens of thousands of deliveries against one subject: a
  wholesale account with years of history, or a shared operations mailbox CC'd
  on every order. The fix is keyset iteration over `(delivery_id, detail_id)`
  with the ownership predicate pushed into SQL, so no invocation holds more than
  one page of ids. Do this before release if the plugin targets stores of that
  size.
- **Redaction is address-based, not semantic.** Both redaction paths — the
  THIRD_PARTY carve-out under an ORDER_LINKED tombstone, and the foreign-address
  redaction on a DIRECT_LINKED row — find and remove *email addresses*. Neither
  detects a personal NAME written in free text without an accompanying address
  ("rejected by Alice Smith's mail server"). Bounded in practice because the
  engine writes SMTP responses and its own reasons, both of which quote
  addresses. Revisit if free text ever carries operator-authored prose.
- **Schema-only test-database cloning with generated fixtures.**
  `bin/create-test-db.php` currently clones **all structures and all data**,
  which duplicates every real customer record in the source database. That is
  acceptable for a development install seeded with test data and unacceptable
  anywhere near real client data. The intended long-term setup is a
  structure-only clone plus a generated fixture set, so no personal data is ever
  copied. See the warning in `docs/testing.md`.
- **A DIRECT_LINKED subject's export omits nothing about themselves but reveals
  no order context.** Because foreign addresses are redacted, a CC recipient's
  export shows `Order update for [removed]`. That is the correct privacy
  outcome, but it may read oddly in a subject access response. Consider a
  short explanatory note on those items, as the THIRD_PARTY rows already carry.

## Added in Prompt 3 — matching engine deferrals

The engine ([ADR-0011](adr/ADR-0011.md)) is a pure decision layer. Everything
below is either a deliberate scope boundary or a contract it places on a later
prompt.

### Contracts on later prompts

- ~~**The rule editor MUST normalise `trigger_value` on save.**~~ **CLOSED in
  Prompt 3a** — moved to `RuleRepository::insert()`/`::update()` and the read
  side, because the repository is the only boundary *every* writer crosses
  (importers, WP-CLI, migrations, a future REST endpoint, and the editor). See
  ADR-0011 §2. What remains for the editor is **surfacing** the refusal: a
  malformed transition now makes `insert()` return 0 and `update()` return
  false, and the editor must show the merchant *why* rather than reporting a
  generic save failure.
- **Event wiring belongs to the delivery phase.** This prompt registers **no**
  WooCommerce hooks. `RuleMatcher::evaluate_status_change()` and
  `evaluate_refund()` are the entry points a future
  `woocommerce_order_status_changed` / `woocommerce_order_refunded` listener
  calls; POC-A already proved the trigger correctness those hooks need.
- **Consolidation is a delivery decision.** The engine returns BOTH
  `matched_item_ids` (not de-duplicated across line items) and
  `matched_product_ids` (de-duplicated), deliberately without choosing between
  one message per order and one per item.
- **Duplicate-content protection across rules is a delivery concern.** Two
  rules that both match the same item both produce `matched` decisions;
  specificity orders them and never suppresses either.
- **`loggable` is the input to ADR-0005's logging policy.** The delivery phase
  should read it rather than re-deriving loggability from a boolean.

### Scope boundaries

- **A refund trigger evaluates the PARENT ORDER's line items**, not the
  refund's. ADR-0011 §4 defines item matching over the order's line items and
  ADR-0004 keys the identity to the refund id. Narrowing the match to the
  actually-refunded items — "email the customer about the item they got money
  back for" — is a plausible future feature and would need a superseding
  decision, because it changes what `matched_item_ids` means for that family.
- **Full and partial refunds are one family.** `refund` rules carry an empty
  `trigger_value`, so every active refund rule is a candidate for every refund.
  Distinguishing them would need a sub-value and an ADR-0004 identity review.
- **`types` shares specificity rung 1 with `tag`** (ADR-0011 §5). The ladder as
  specified has five rungs and the schema has six include kinds. If a type match
  ever needs its own rung, renumbering the ladder is a superseding ADR — stored
  decisions record the numeric level.
- **`stop_processing` halts within ONE trigger identity.** A halt in the
  `status:` family does not touch the `transition:` family. Cross-family halting
  would make the outcome depend on evaluation order between families.
- **No new reason code for an invalid trigger identity.** Such an event returns
  an empty result; `DeliveryRepository::claim()` independently refuses the
  identity. If the delivery phase needs to surface these to support, it needs a
  code and an ADR-0011 amendment.

### Performance, measured

- **A 10-item order (10 distinct products) against 20 rules costs 37 queries
  cold and 1 warm**, identical at 40 rules — the cost is one rule fetch per
  trigger family plus a constant per DISTINCT product, and does not scale with
  rules (`MatchingPurityTest`). If large orders prove costly in the field, the
  next step is priming the product cache in one batch
  (`_prime_post_caches()` / a single `wc_get_products()` read) rather than ten
  individual `wc_get_product()` calls. Not done now: it trades a real,
  measurable simplicity for an unmeasured gain.
- **`ItemResolver` caches per resolver instance, not globally.** Both trigger
  families of one status change share a pass because they share a matcher. A
  long-running request evaluating many orders should reuse one matcher, or call
  `ItemResolver::flush()` if memory matters.

### Flagged WooCommerce behaviour — re-check on version bumps

- **`wc_get_order_statuses()` INCLUDES `wc-checkout-draft`** whenever
  WooCommerce Blocks is active (verified WC 10.9.4, via
  `Blocks\Domain\Services\DraftOrders` on the `wc_order_statuses` filter). The
  exclusion of draft orders is therefore an **explicit constant**, not an
  emergent property of discovery. Asserted directly by
  `MatchingEngineTest::test_checkout_draft_produces_nothing()`, which fails
  loudly if upstream stops registering it.
- **A variation carries NO category or tag term ids** (verified WC 10.9.4).
  `WC_Product_Variation` inherits `get_category_ids()`/`get_tag_ids()` unchanged
  and its data store never populates them, so category and tag targeting must
  resolve through the parent product or it misses every variation purchase.
- **`WC_Product_Variation::get_type()` returns `variation`, never `variable`**,
  so type targeting carries the parent's slug alongside the variation's own.
- **`wc_get_product()` returns a HOLLOW OBJECT for a deleted variation**
  (verified WC 10.9.4) rather than `false`, because
  `WC_Product_Variation_Data_Store_CPT::read()` returns silently on a missing
  post while the ordinary product store throws. Anything asking "does this
  product still exist?" must also check `WC_Data::get_object_read()`. This bit
  the engine (a deleted variation would have been reported as merely
  unmatched, not `product_unavailable`) **and** the test harness's
  verify-after-delete teardown, which reported every deleted variation as a
  leak. Both are fixed; the check is applied to every product type rather than
  only variations, so it does not depend on which store throws today.

## Added in Prompt 3a — matching engine corrections

Prompt 3a closed a family of input-validation and boundary defects that all
shared one failure shape: **a rule silently stops working and leaves no trace in
the decision log.** What is recorded here is what those fixes deliberately left
open.

### Contracts on later prompts

- **No migration normalises `trigger_value` on EXISTING rows.** ADR-0011 §2's
  normalisation now runs on every write and on the read side, but a row written
  by an earlier build — or inserted straight into the table — keeps whatever it
  holds, and the normalised fetch will never find it. No released build has
  stored a rule, so there is nothing to migrate today. **If rules ever become
  importable, or a build ships that wrote rules before this change, add a
  one-shot migration** that re-normalises `trigger_value` per `trigger_type` and
  reports (not deletes) rows whose transition value is unrepairable.
- ~~**An empty `trigger_value` on a `status` rule is still accepted.**~~
  **CLOSED in Prompt 3b** — empty, whitespace-only and non-slug status values
  are refused at the repository boundary, on both polarities and both transition
  sides. Prompt 3c extended the same rule to values sanitisation would otherwise
  have repaired into validity. See ADR-0011 §2.
- **The editor must surface a refused write.** `insert()` returning 0 and
  `update()` returning false now carry real meanings — an unknown trigger type,
  a dead or malformed status value, a malformed transition — that a generic
  "could not save" message would throw away.

### Scope boundaries

- **`variation_unavailable` is an item-level note, not a rule reason.** A `types`
  rule that could not match a degraded item still reports `no_targeting_match`,
  which ADR-0005 does not log; the loggable note on the order-level result is
  what explains it. If support finds that indirection hard to follow in the
  delivery-history panel, the fix is a panel that joins notes to decisions, not a
  new per-rule reason code — that would make the noise floor loggable.
- **A partially resolved item cannot be matched by `types` at all**, including on
  the **exclude** side. An `exclude: {types: [...]}` therefore cannot remove such
  an item. This is the same "unknown is not false" rule applied consistently, and
  it errs toward sending rather than toward silently dropping a delivery — but it
  is a decision, and a merchant relying on a type exclusion for compliance
  reasons would want to know.
- **Specificity kind ties are broken by line-item order.** When one item matches
  at `type` and another at `tag` — both rung 1 — the decision's
  `specificity_kind` names whichever came first in the order. Deterministic, but
  arbitrary; if the panel needs a stable preference between the two kinds, decide
  it in an ADR rather than in the comparator.

### Flagged WooCommerce behaviour — re-check on version bumps

- **⚠ A DELETED VARIATION ERASES ITS ID FROM THE ORDER LINE ITEM'S CRUD
  ACCESSOR** (verified WC 10.9.4). This **contradicts ADR-0011 §4** as originally
  written, which stated that `variation_id` is read from the line item and so
  survives a deleted product. `WC_Order_Item_Product::set_variation_id()` rejects
  any value whose `get_post_type()` is not `product_variation`, and
  `WC_Data::set_props()` swallows the resulting `WC_Data_Exception` per property
  — so `get_variation_id()` returns **0** while `_variation_id` still holds the
  real id in `woocommerce_order_itemmeta`. Unhandled, the line item disguises
  itself as an ordinary parent-product purchase: `variations` rules stop
  matching, and targeting is handed the **parent's** type slug and flags with
  nothing marked degraded. `ItemResolver::variation_id()` recovers the id through
  `wc_get_order_item_meta()` — WooCommerce's own public order-item meta API, and
  the same source the order-item data store reads this property from, so it stays
  inside CRUD. Pinned in both directions by
  `MatchingTargetingTest::test_deleted_variation_with_surviving_parent_is_partially_resolved()`,
  so an upstream fix fails a test rather than leaving dead code. **Worth an
  upstream report:** swallowing the exception silently discards recorded order
  history.

## Added in Prompt 3b — the matching engine is frozen after this

The engine ([ADR-0011](adr/ADR-0011.md)) is closed. Everything below is either
required work for the delivery phase or a finding deliberately left unfixed.

### ~~Required delivery-phase test — `rule_disabled` end to end~~ CLOSED in Prompt 4

**Delivered.** The deferred job now exists, so the test that could not be written
before is written:
`DeferredDeliveryTest::test_a_rule_disabled_during_the_delay_does_not_send()`
fetches candidates, disables one in the **database** without touching any
fetched array, runs the **real** deferred job through its registered Action
Scheduler hook, and asserts the disabled rule neither sends nor claims — while a
still-enabled rule does send, so the absence is the disabling and not a broken
path. It then re-enables the rule and shows it can still claim its **preserved**
identity, proving nothing was silently dropped.

`rule_disabled` itself is exercised by
`DeferredDeliveryTest::test_a_row_marked_inactive_is_recorded_as_rule_disabled()`,
which is the reason code's one legitimate caller: a job that re-read a rule,
found it inactive, and wants the outcome in the log.

### Findings recorded, deliberately not fixed

- ~~**Ordering reads the DECODED column while the decision reads the RAW one.**~~
  **CLOSED in Prompt 3c — it was a live ADR-0011 §5 violation and should never
  have been filed as deferred work.** Targeting is now parsed once, before
  ordering, from the authoritative representation, and the one `Targeting`
  instance serves ordering, validity and evaluation (`Domain\PreparedRule`).
  The entry also **understated the impact**: it claimed the effect was confined
  to log ordering. It was not — at equal priority a malformed rule sorted ahead
  of a valid `stop_processing` rule, so the halt landed elsewhere and later
  rules received `blocked_by_stop_flag` instead of their real reasons. Proved by
  `MatchingEngineTest::test_a_malformed_rule_cannot_sort_ahead_of_a_stop_processing_rule()`.

- **No migration re-encodes existing `targeting` columns as `{}`.** Rows written
  before `Targeting::encode()` existed store an empty document as `[]`, which now
  reads as `targeting_invalid`. No released build has stored a rule, so there is
  nothing to migrate today — but this joins the trigger-value migration already
  recorded above as **one** migration to write if rules ever become importable or
  a build ships that wrote rules before these changes. Note the failure is
  **loud** (a logged `targeting_invalid`), not silent, which is the whole point.

- **`Targeting::encode()` is not used by anything but `RuleRepository`.** The
  future rule editor, importer and any REST endpoint must write the `targeting`
  column through the repository, never with `Json::encode()` directly, or they
  reintroduce the `[]`-versus-`{}` defect at their own boundary.

## Added in Prompt 3c — MATCHING ENGINE FROZEN

### Contract-consistency gate

**Nothing recorded in this file is a known violation of an ADR by current
matching-engine code.** Prompt 3b printed `MATCHING ENGINE FROZEN` while the
ordering-versus-evaluation entry above described a live ADR-0011 §5 violation
filed as deferred work; that was wrong twice over — the freeze should not have
printed, and the entry understated the defect as cosmetic when it in fact moved
the `stop_processing` halt point. Both are corrected. **Deferred *work* is fine
here; a deferred *contract violation* is not, and the freeze gate now checks
it.**

Every remaining entry in this file is one of: work the delivery phase must do,
a scope boundary deliberately chosen, an accepted residual risk requiring
third-party code to misbehave, or flagged upstream WooCommerce behaviour to
re-check on version bumps.

### Findings recorded, deliberately not fixed

- **`Domain\PreparedRule` is the only thing that knows the raw passenger is
  authoritative.** `Json::RAW_SUFFIX` names the convention and
  `RuleRepository::RAW_SUFFIX` aliases it, but any future reader that pulls
  `$rule['targeting']` directly instead of going through `PreparedRule` will
  reintroduce the two-representations defect at its own call site. The delivery
  phase reads rule rows; it must go through `PreparedRule`.

- **`Specificity::sort_rules()` survives only for callers holding plain rows**
  (today: the unit suite). It routes through `PreparedRule` exactly as the
  matcher does, so the two cannot order the same rules differently — but it is a
  second entry point into ordering, and a second entry point is a place for
  drift to start. If nothing outside the tests needs it once the delivery phase
  lands, delete it and keep `Specificity::sort()` alone.

- **`sanitize_text_field()` and `sanitize_key()` are no longer applied to
  trigger values or types.** Validation refuses anything outside `[a-z0-9_-]`
  and the three literal type names, and `$wpdb` prepares every value, so nothing
  depends on sanitisation for safety. A future column added to the trigger path
  must be validated the same way and must **not** reach for a sanitiser to make
  bad input acceptable.

## Added in Prompt 4 — the delivery spine

Separate-mode sending is live ([ADR-0012](adr/ADR-0012.md)). Everything below is
required work for a later prompt or a deliberate scope boundary.

### Contract-consistency gate

**Nothing recorded in this file is a known violation of an ADR by current code.**
Prompt 3c introduced that gate after Prompt 3b froze the engine with a live
ADR-0011 §5 violation filed as deferred work; it still holds.

### Required for the prompts that follow

- **Insert mode and the render-context production class (Prompt 5).** Deliberately
  absent, not stubbed. `DeliveryLogger::MODE` is the single constant a second mode
  has to change, and `Orchestrator::deliverable_in_this_phase()` is the single
  place Prompt 5 removes the `insert` exclusion from (ADR-0012 §9). The
  claim-then-send path insert mode will reuse is already proven here, so Prompt 5
  can concentrate entirely on render correlation.

  **NOTE (Prompt 4a):** as originally shipped this boundary did not exist —
  insert rules were matched, claimed under `mode = separate` and **sent as
  standalone emails**. That was a live ADR-0012 violation and gate 9 was reported
  clean while it stood, because gate 9 was read against the backlog TEXT rather
  than against behaviour. Gate 9 now requires direct examination of current
  behaviour against the ADR.
- **The preview guard is COARSE and Prompt 5 must replace it.**
  `Orchestrator::is_rendering_preview()` reads `woocommerce_is_email_preview` and
  `is_customize_preview()`. That is sufficient for separate mode — which is
  triggered by an order event and never by a render — but insert mode is
  triggered BY a render and needs the full ADR-0005 slot machinery, including the
  verified leak of `woocommerce_is_email_preview` after an interrupted preview.
- ~~**Placeholders (a later prompt).** Subject, heading and content are sent
  LITERALLY: `{customer_name}` is delivered as those characters.~~
  **⚠ SUPERSEDED 2026-08-01 (Prompt 6A) — DONE in Prompt 6, see
  [ADR-0014](adr/ADR-0014.md).** Subject, heading and content are resolved at send
  time against the live order, single-pass, escaped per destination. An empty
  resolved recipient is decided: the entry is dropped with a note and, if that
  leaves no `to`, the delivery is `skipped` with the reason recorded (ADR-0014 §7).
  `Custom_Email::get_subject()` still deliberately does not call
  `format_string()` — that is WooCommerce's own, differently-spelled substitution
  pass, whose values this plugin does not control.
- ~~**`delay_seconds` and ADR-0007 snapshots.**~~ **DONE — Prompt 7**, see
  [ADR-0015](adr/ADR-0015.md). A delayed rule now claims at scheduling time,
  snapshots onto the DURABLE tombstone, queues an Action Scheduler job, and
  re-validates against the live rule and order before sending. `scheduled` is no
  longer an unused status. `delay_seconds` has left
  `UNIMPLEMENTED_BEHAVIOUR_DEFAULTS` and become a phase discriminator, leaving
  `consolidation` as the only entry.
- **`consolidation` beyond `none`.** One email per rule per trigger. The engine
  returns both `matched_item_ids` and `matched_product_ids` so a later prompt can
  choose per-order or per-item without the engine having pre-judged it.

  **⚠ NOTE (Prompt 5C):** between Prompt 5B and Prompt 5C this was **not** deferred
  in practice. 5B gave the column validated storage without giving either phase a
  filter for it, so a stored `daily` rule was **delivered immediately, once per
  trigger** — `none`'s behaviour under another name — and could halt supported rules
  through `stop_processing`. Both phases now filter it before evaluation from
  `Orchestrator::UNIMPLEMENTED_BEHAVIOUR_DEFAULTS` (ADR-0013 §8a). **The lesson is
  general: adding validated storage for a column is what makes its values reachable,
  so storage and phase filtering must land in the same prompt.**

### Findings recorded, deliberately not fixed

- **Two new line-level PHPCS suppressions**, both for WooCommerce-owned hooks this
  plugin must interoperate with: `woocommerce_email_headers` (applied by every
  core `WC_Email` subclass; dropping it would break SMTP and deliverability
  plugins that add headers through it) and `woocommerce_is_email_preview` (read by
  core in seven places, with no helper function to call instead). Both are
  `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` only,
  on one line each, with the reason inline.
- **A `failed` claim with no `delivery_id` logs to WooCommerce rather than the
  detail store.** There is no parent row to attach a detail row to, and the detail
  repository rightly refuses an orphan. The failure is therefore visible in the
  WooCommerce log (source `extonify-wcep`) but not in the delivery-history panel.
  If the panel needs to show these, it needs a parentless-failure store, which is
  a schema change and an ADR-0009 amendment.
- **The halt record rides on the stop rule's detail rows**, so a halt whose stop
  rule was itself `suppressed` (a repeat of the same identity) writes no new
  record of the blocked ids. The first firing already recorded them, so nothing is
  lost — but a support query that only looks at the latest attempt will not see
  it.
- ~~**`Orchestrator` reads each sending rule's row with a second query**
  (`RuleRepository::find()`), cached per request.~~ **RESOLVED in Prompt 4a — do
  not re-open.** The second read was deleted outright, not optimised:
  [ADR-0012 §10](adr/ADR-0012.md) makes the rows fetched once, phase-filtered and
  carried through claiming and sending, because re-reading let an admin save
  between matching and sending make a rule **match on old targeting and send new
  content**, with `rule_revision_sent` recording a revision that never produced
  the match. The batched `find_by_ids()` this entry once proposed would
  reintroduce exactly that. Measured cost for one delivery on a 10-item order
  against 20 rules is now **41 queries**, against 200 for a per-rule-per-item
  implementation.
- **Deferral is scheduled at `time()`**, so Action Scheduler runs it on the next
  queue pass. ADR-0008 caps it at one deferral and the claim collapses any race,
  so an early run is safe — but a store with a stalled Action Scheduler queue will
  see the deferred delivery sit pending. That is a WooCommerce-wide condition,
  not something this plugin can fix, and it fails in the safe direction.

## Added in Prompt 4a — delivery spine corrections

### Contract-consistency gate

**Nothing recorded in this file, and nothing in current behaviour, is a known
violation of an ADR.** Gate 9 is now checked by reading the ADR against the code,
not only against this file — Prompt 4 reported it clean while insert-mode rules
were being delivered as standalone emails, which the backlog text did not
mention because nobody had noticed it.

### Findings recorded, deliberately not fixed

- **`rule_disabled` is unreachable in production, by design** (ADR-0012 §2a). No
  path hands the matcher a row it has already read and found inactive. The
  rejected alternative — persisting candidate rule ids and comparing them at
  execution — is documented in the ADR along with the three reasons it is
  refused, the decisive one being that the Action Scheduler argument set is the
  deduplication key and cannot carry a variable-length list.

- **PHPCS suppressions in `src/`: exactly TWO, unchanged since Prompt 4.**
  Verified against `c851876`: this pass added **zero** suppressions to any
  pre-existing file, and the two in the new files are the ones already declared.
  Both are single-line, single-sniff
  (`PrefixAllGlobals.NonPrefixedHooknameFound`) and reasoned inline:
  1. `Email\Custom_Email::get_headers()` — `woocommerce_email_headers`, a
     WooCommerce-owned filter every core `WC_Email` subclass applies; dropping it
     breaks SMTP and deliverability plugins that add headers through it.
     **(REMOVED in Prompt 4b: the parent applies that filter now, so this class
     no longer calls `apply_filters()` at all. The count in `src/` is therefore
     ONE, not two — see ADR-0012 §5b.)**
  2. `Delivery\Orchestrator::is_rendering_preview()` —
     `woocommerce_is_email_preview`, read by core in seven places with no helper
     function to call instead.

  Everything else matching `phpcs:ignore` in `src/` is a pre-existing
  `WordPress.DB.*` suppression on a plugin-owned table query.

- **The deferral pre-check still has a race window.** `as_has_scheduled_action()`
  matches arguments exactly, but a concurrent request can pass it before either
  writes. Two deferrals for one identity would then be queued, and both would run
  — at which point the **atomic ADR-0004 claim** collapses them to one send. The
  cost is one wasted job, not a duplicate email. Closing it at the scheduler is
  not possible while `$unique` ignores arguments (see the flagged behaviour in
  ADR-0012).

- **`Orchestrator::deliverable_in_this_phase()` reads two columns that no other
  code in this phase reads.** When Prompt 5 and Prompt 6 land, each must remove
  its own condition — and a rule that matches neither phase's conditions would
  then be silently undeliverable again. The filter should gain an explicit
  "belongs to no phase" branch at that point rather than an implicit one.

## Added in Prompt 4b — DELIVERY SPINE FROZEN

### Contract-consistency gate

Re-run by direct examination against ADR-0008 and ADR-0012. Three contradictions
were found and removed; no ADR clause, class docblock or inline comment now
describes behaviour the code does not have.

1. `DeferredEvaluation` claimed a rule disabled during the delay was reported
   `rule_disabled`. The job re-fetches, so such a rule is simply **absent** and
   nothing is manufactured.
2. `DeferredEvaluation` and ADR-0012 §7 called the Action Scheduler argument set
   "unique per identity". It is a **best-effort** pre-check; the atomic ADR-0004
   claim is the guarantee.
3. ADR-0012's Prompt 4a amendment history said the scheduler is passed
   `$unique = true`. It never was, deliberately.

One wording is now used everywhere — ADR-0008 §4, ADR-0012 §7a/§7b,
`DeferredEvaluation`'s class docblock, `is_scheduled()` and `run()`:

```
Deferred job : re-fetches current active rules. A rule disabled during the delay is
               absent and sends nothing. No rule_disabled tombstone is manufactured.
Scheduler    : the per-identity pending check is best-effort; concurrent duplicate jobs
               remain possible; the atomic claim prevents duplicate delivery.
```

### Findings recorded, deliberately not fixed

- **The header injector runs at `PHP_INT_MIN`, which is first but not
  inviolable.** `Custom_Email::inject_copy_headers()` adds this plugin's Cc and
  Bcc inside `woocommerce_email_headers` so every other callback sees a complete
  block. A third party registering at the same priority *earlier* would run
  before it and see the block without them. There is no priority below
  `PHP_INT_MIN`; the alternative — appending after the filter — is strictly
  worse, because then *no* callback could see them.

- **The Cc/Bcc feature detection is exercised only against WooCommerce 10.9.4.**
  `get_cc_recipient()` / `get_bcc_recipient()` arrived in WC 9.8, and the
  provisional floor is 8.2, so the `method_exists()` fallback branch is reasoned
  and reviewed but not executed by any test on this runtime. Re-check when the
  floor is confirmed under ADR-0006 — that is the prompt that has to install an
  older WooCommerce and actually run it.

- **`recipient_header` is null on every `auto` row, by contract** (ADR-0012 §4a).
  Literal recipients resolve to bare addresses because `wp_mail()` splits `$to`
  on commas without honouring RFC quoting, so a display name containing a comma
  would tear the recipient list apart. The drop is recorded as a resolution note.
  Preserving display names would need the recipient list handed to `wp_mail()` as
  an **array** rather than a comma-joined string — worth revisiting, but it is a
  change to how every delivery is addressed and does not belong in a freeze pass.

- **`DeliveryLogger::record_claim_failure()`'s `delivery_id > 0` branch is still
  unreachable from `DeliveryRepository::claim()`,** which returns `0` on both of
  its failure paths. It is kept rather than removed — the method takes a claim
  array from its caller rather than producing one — and is now held to the same
  verified-write contract as every other write, so if a future repository does
  populate it the rows cannot fail silently.

- **`RunOutcome` is produced but not yet consumed.** Every public orchestrator
  entry point returns one, carrying each rule's action and whether the log
  actually captured it. Nothing reads the aggregate yet; the delivery-history
  phase is what it exists for. Until then its value is that the results are no
  longer discarded.

- **Gate 5 is now a property of the suite, not of a base class.**
  `Tests\Integration\MailGuard`, installed by the bootstrap, short-circuits
  `pre_wp_mail` for every test and throws from `phpmailer_init`. The tally is
  printed per test class at the end of the run. Previously only the classes
  extending `MatchingTestCase` blocked mail at all.

## Added in Prompt 4c — RE-ENTRANCY FREEZE

### Contract-consistency gate

Re-run by direct examination. Prompt 4b's own gate-10 audit turned out to be one
of the contradictions: it described `ItemResolver::$products` while the resolver
also held `$orders`. Everything found is fixed, not deferred:

1. **ADR-0012 §11** claimed a nested send is safe "because `WC_Email::send()`'s
   arguments are all evaluated before the nested event can fire". False —
   `is_enabled()` applies a third-party filter first. Struck in place, with the
   reason recorded, and replaced by §11a.
2. **ADR-0002** required `reset_runtime_state()` "on entry AND in a `finally`".
   The exit half is now a restore; amended in place.
3. **ADR-0012 Consequences** repeated "reset at both ends of `trigger()`".
4. **`Orchestrator::$matcher`**, **`Events::orchestrator()`** and the §11 audit
   table all described the resolver's cache as "keyed by product id and therefore
   correct for every order". That was true of `$products` and false of `$orders`.
5. **The Prompt 4 backlog entry** proposing a batched `find_by_ids()` for "the
   orchestrator's second rule query" is marked RESOLVED — Prompt 4a deleted that
   query for a correctness reason (ADR-0012 §10), and the proposal would have
   reintroduced it.

### Findings recorded, deliberately not fixed

- **The header injector's per-call closure fixes a latent fragility, not a live
  defect.** Re-running `NestedDeliveryTest` against the old
  `array( $this, 'inject_copy_headers' )` registration, the header test **passed**
  — because the injector runs at `PHP_INT_MIN` and has therefore already
  contributed its lines before any third-party callback can nest. The closure is
  still the right shape: the safety currently depends on a priority constant, and
  a future change that moves the injector off `PHP_INT_MIN` would turn a shared
  registration into a silently stripped one. Two of the three re-entrancy tests
  fail against the old code; this one is a guard, and is labelled as such.

- **`ItemResolver::$products` and `$facts` are not invalidated within a request,
  and that is now a decision** (ADR-0012 §11b). A product edited inside the same
  request that later evaluates an order containing it would match on its pre-edit
  facts. No WooCommerce path produces that ordering, whereas order contents
  changing mid-request is a documented lifecycle — which is exactly why the
  order-keyed cache had to go and these two do not. `flush()` remains the escape
  hatch. Re-examine if a bulk product importer ever runs in-process alongside
  order emails.

- **Removing the order cache cost nothing measurable.** A second resolution of
  the same 10-item / 10-product order costs **0 queries** (first pass 10), because
  `WC_Order::get_items()` is memoised on the order object and the item meta is
  already in WordPress's cache. Asserted, not assumed, by
  `OrderMutationTest::test_re_resolving_an_order_costs_no_extra_queries`.

- **`Custom_Email::RUNTIME_FIELDS` is now the single source of truth for
  per-delivery state.** Adding a field anywhere else — a property without an
  entry here — would be captured by none of capture, apply or restore and would
  leak between deliveries. There is no automated guard on that; the isolation and
  re-entrancy tests catch it only for fields they happen to assert on. A
  reflection-based check that every non-settings public property appears in
  `RUNTIME_FIELDS` would close it, and belongs with the insert-mode work that
  adds the next such field.

## Added in Prompt 4d — PARENT STATE FREEZE (final Prompt 4 correction)

**Prompt 4's correction passes end here.** Per this pass's stopping rule, any
further finding that requires third-party code to re-enter the plugin mid-send is
recorded below as accepted residual risk and is not fixed. Insert mode (Prompt 5)
exercises this same code and is a better place to discover anything remaining
than a fifth speculative audit.

### Contract-consistency gate

1. **ADR-0012 §5** still described `trigger()` as resetting all runtime fields in
   a `finally`. Superseded by §11a's capture-and-restore since Prompt 4c; the
   clause is now amended in place with an explicit pointer, so Prompt 5 cannot
   copy the clearing model.
2. **`RuleMatcher`** still said both trigger families resolve the order's items
   once. After the Prompt 4c `$orders` removal, line items are re-read per
   evaluation and only PRODUCT facts are reused. Corrected on both the class
   docblock and `evaluate_status_change()`.

### Flagged WooCommerce behaviour — worth an upstream report

- **⚠ `WC_Email::send()` HAS NO `try`/`finally`.** *(Verified in the bundled
  WC 10.9.4.)* It attaches `wp_mail_from`, `wp_mail_from_name` and
  `wp_mail_content_type`, then applies `woocommerce_mail_content` and invokes the
  mail callback, and only then removes them. Anything that throws in between
  leaves all three attached **for the rest of the request** — and these are
  `wp_mail`-wide hooks, so every later message, including WordPress password
  resets, would carry the store's From identity and `text/html` content type.
  Every `WC_Email` subclass inherits this. **Worth reporting upstream**; a
  `try`/`finally` around the body would close it for everyone.

  This plugin is unusually exposed because ADR-0012 §3 catches `\Throwable` and
  lets the request CONTINUE, where a core email would have died with the
  exception. `Custom_Email::send()` therefore wraps the parent (ADR-0012 §11d)
  rather than waiting for upstream.

- **`WC_Email::$mime_boundary` and `$mime_boundary_header` are declared and never
  assigned** anywhere in WC 10.9.4 — vestigial from the multipart implementation
  that predates `handle_multipart()`. They are excluded from the captured frame
  on that basis, and `ParentStateTest::test_the_multipart_boundary_properties_stay_untouched()`
  asserts it behaviourally so a WooCommerce version that starts using them fails
  the suite rather than leaking.

### Findings recorded, deliberately not fixed

- **`WC_Email::$email_type` can be mutated mid-delivery on the block-editor
  path** — `get_content()` promotes `plain` → `html` when
  `get_block_email_html_content()` returns content. Excluded from the frame for
  three reasons, recorded in ADR-0012 §11c: it is a store setting by ADR-0002 and
  §5, this plugin registers no block templates, and the promotion is idempotent
  and one-way so an outer and an inner delivery of the same object promote
  identically. The residual case — WooCommerce's `block_email_editor` feature on,
  block content returned for one order and not another, **and** nesting — is
  accepted under this pass's stopping rule.

- **The multipart consequence cannot be observed end to end in this suite.**
  Every message is intercepted at `pre_wp_mail`, which short-circuits `wp_mail()`
  before `phpmailer_init` — the hook WooCommerce calls `handle_multipart()` from.
  So WooCommerce's real multipart path never runs anywhere in the suite, and a
  test that merely nested two sends would pass whether or not `sending` is
  framed. `ParentStateTest::test_the_sending_flag_survives_a_nested_delivery()`
  therefore invokes `handle_multipart()` — WooCommerce's own unmodified method —
  at the point `phpmailer_init` would fire. It proves the flag is cleared during
  the inner delivery and restored for the outer one; it does **not** prove core's
  `phpmailer_init` wiring fires as expected. Closing that gap needs a harness
  that intercepts at `phpmailer_init` instead, which would stop `pre_wp_mail`
  from being the single mail tripwire the suite currently relies on.

- **`RUNTIME_FIELDS` completeness is now guarded, with one known blind spot.**
  `ParentStateTest::test_every_property_is_either_framed_or_declared_per_request()`
  fails when any property on the shared object is neither framed nor on the
  documented per-request allow-list, so a property added by this plugin or by a
  WooCommerce upgrade forces a human decision. It cannot detect a WooCommerce
  version that starts *mutating* an already-allow-listed property mid-delivery;
  the boundary-property assertion above covers the two candidates that exist
  today, and any newly mutated one would surface as a delivery bug rather than at
  the guard.

## Added in Prompt 5 — insert mode and the render context

### Contract-consistency gate

Re-run by direct examination against ADR-0003, ADR-0004, ADR-0005 and the new
ADR-0013. One clause needed correcting, and it was found by the code rather than
by reading:

- **ADR-0003's frame does not span all five injection positions.** Verified in
  the WC 10.9.4 templates: `woocommerce_email_order_meta` and
  `woocommerce_email_customer_details` fire *after* `woocommerce_email_order_details`
  returns, so the frame is already popped. ADR-0013 §4a records this, keeps the
  frame's ADR-0003 lifetime (it is the *item-hook* window, which is what ADR-0003
  and the POC both call it), and adds a render record for the email-only
  positions. The storefront guarantee depends on the frame and is unchanged.

Three pre-existing fixtures described rules that ADR-0013 §2 now refuses, and
were corrected rather than the rule being weakened:

1. `RuleRevisionTest::payload()` was an **insert rule with a one-day delay** —
   now a separate rule with no delay; the `delivery_mode` provider entry inverts
   to `insert`.
2. `RuleRepositoryTest::payload()` was an **insert rule**, so every
   trigger-normalisation test in that class was asserting against the empty
   trigger fields ADR-0013 §2 stores — now a separate rule. Insert-mode storage
   has its own suite in `InsertRuleStorageTest`.
3. `DeliveryPhaseTest`'s `insert AND delayed` data set describes a rule that can
   no longer exist. Replaced by
   `test_an_insert_rule_with_a_delay_cannot_be_stored_at_all()`, which asserts
   the stronger guarantee: the repository refuses it, so it never reaches the
   phase filter.

### Findings recorded, deliberately not fixed

- **The whole-render query count carries WooCommerce's own variance.** A native
  render runs core's templates, options and transients, so its absolute count
  moves by a few queries between runs for reasons that have nothing to do with
  this plugin. The gate's two actual claims are asserted exactly instead —
  evaluation runs ONCE per render whatever the positions, and evaluation cost
  does not grow with the candidate set — and the whole-render figure is reported
  as an observation beside them.

- **⚠ `wc_get_template_html()` leaves an output buffer open on an exception.** An
  interrupted render leaks an `ob_start()` level; the interrupted-preview test
  unwinds it so the assertion stays about this plugin. This plugin cannot close a
  buffer it did not open at a call site it does not own. Already recorded under
  the Prompt 1d flagged behaviour; re-confirmed on WC 10.9.4.

- **`native_email_id` verification is capability-detected, not mandatory**
  (ADR-0013 §2). When `WC()->mailer()` is unavailable — a CLI importer, a
  migration, an activation hook — an unrecognised id is accepted and checked
  again at render time against the email actually rendering. Refusing a valid
  rule because WooCommerce had not booted would be the worse failure.

- **A render record outlives its frame, and is retired at finalization,
  reconciliation or shutdown.** A render whose send throws keeps its record until
  the shutdown sweep.

  **⚠ CORRECTED (Prompt 5B, gate 9).** This entry used to continue: *"a stale
  record cannot mis-fire: `current_render()` scans newest-first and matches email
  id, order and audience, so only a later render of the same email for the same
  order and audience could shadow it — and that render pushes a newer record which
  wins."* Every clause of that was wrong by the time it was written, and the last
  one describes the DEFECT rather than the defence — a newer record winning is
  precisely how an inner render captured an outer render's emission (ADR-0013 §5a).
  `current_render()` has not scanned newest-first since Prompt 5B: it considers the
  INNERMOST record only, validates it against the email object, email id, order,
  audience and enclosing depth, and emits nothing when that fails (0/1/2+, ADR-0013
  §4a). And the in-frame positions no longer ask it at all — they resolve through
  the live frame (ADR-0013 §4c), because a leaked inner record could otherwise
  shadow the render that was actually emitting.

- **The five positions are fixed for now.** `Injector::POSITIONS` is a constant
  map; the admin UI that lets a merchant choose among them is Prompt 7's, and an
  unrecognised stored `insert_position` falls back to `after_order_table` rather
  than emitting nothing. That fallback is now a RECORDED DECISION rather than a
  survivor: ADR-0009's write-boundary classification names `insert_position` as its
  one documented repairing column, with the reasoning.

## Prompt 5A — insert finalization and correlation

### Findings recorded, deliberately not fixed

- **A nested send that throws BETWEEN its reservation and its bind leaks one
  reservation.** The window is `woocommerce_mail_content` → the
  `woocommerce_mail_callback` filter, inside `WC_Email::send()`. If a third party
  throws there and another third party catches it, the enclosing send's
  `woocommerce_mail_callback_params` pops the inner send's leaked reservation
  instead of its own. `bind()` verifies the reservation against the email object,
  so a cross-object leak binds nothing and the enclosing render is reported
  `unresolved` — honest. A SAME-object leak would bind the inner slot. Closing it
  needs re-entrancy handling around a third-party throw mid-send, which is
  exactly the class Prompt 4d's stopping rule sends here. Two nested sends of the
  same email type, one of which throws inside a two-line window and is swallowed.

- **⚠ `woocommerce_mail_content` carries no `$email` argument** (WC 10.9.4,
  `class-wc-email.php:1233`), so the send scope has to come from
  `woocommerce_email_headers` / `woocommerce_email_attachments` — see
  ADR-0013 §5a and §5d. Every native WooCommerce email in 10.9.4 sends through
  `send_notification()` or `send_if_recipient()`, both of which evaluate those as
  the last arguments to `send()`. **Re-check on version bumps:** a future
  subclass calling `send()` with literal headers and attachments would supply no
  scope. *(Updated Prompt 5B: the consequence is now simply that **no token is
  taken**, so that send reserves nothing and its render is reported as an
  abandoned render. The "exactly one eligible slot in the whole ledger" fallback
  this entry used to name no longer exists — see ADR-0013 §5d.)*

- **⚠ `WC_Email::get_content()` DELETES unrecognised HTML entities from
  plain-text bodies** (WC 10.9.4, `$plain_search` pattern `/&[^&\s;]+;/i` →
  `''`). ADR-0013 §4b delivers plain text already decoded, so nothing of ours
  meets that pattern. Worth watching: the same pattern will eat a literal `&`
  that happens to be followed by non-space characters and a semicolon.

- **`Injector::normalize_position()` still repairs rather than refuses.** An
  unrecognised stored `insert_position` falls back to `after_order_table`. That
  is deliberate for now — a position is a presentation choice with a sane
  default, not a targeting decision — but it is the last `sanitize_key()`-then-
  accept in the insert path and belongs to the Prompt 7 rule editor's validation.
  *(Promoted in Prompt 5B from a backlog note to an explicit clause in ADR-0009's
  write-boundary classification, so it is a decision a future reader can disagree
  with rather than the one unexamined survivor of the sweep.)*

## Prompt 5B — render identity, depth safety and the write boundary

### Findings recorded, deliberately not fixed

- **⚠ The exact-token handoff has a one-statement residual window** (ADR-0013
  §5d). A third party that *completes* a render on the same email object between
  the enclosing render's completion and that send's header evaluation gets its
  token taken instead. Both renders are complete, both share the object and the
  email id, and the only remaining discriminator — the order — comes from
  `$email->object`, which WooCommerce has already corrupted after a nested render.
  The window is one PHP statement wide
  (`send( $to, $subject, $content, $headers, $attachments )`) and the failure is a
  mis-attribution between two renders of the *same* email, never a lost delivery.

- **⚠ In-flight refused-frame markers still scale with a third party's recursion
  depth** (ADR-0013 §5c). A marker must be poppable to keep `open_details` and
  `open_footer` LIFO-aligned, so it cannot be capped without corrupting the
  removal of a real frame. Each is a six-key array and two strings, against that
  party's own stack frame, output buffer and `wc_get_order()` object — and the
  query per level, which is the part that reaches the database, is gone. Capping
  the markers is not the fix; the recursion is not ours to stop.

- ~~**A render that leaks its record still blocks the two POST-FRAME positions of
  the render that encloses it.**~~ **CLOSED in Prompt 5C (ADR-0013 §5f).** Deferring
  it was wrong for a reason that only became visible once §5e moved promotion to a
  post-frame position: a render that cannot identify itself there can never become a
  send candidate, so the "safe direction" of losing an insertion was in fact losing
  the whole correlation. A frame closing at `order_details:15` now retires every
  record pushed after its own, which is provable rather than heuristic — a nested
  render's post-frame positions fire immediately after its own `order_details`
  returns, still inside the enclosing render's window. Measured on a 19-deep storm:
  15 leaked records before, 0 after.

- **`suppressed_count` and `MAX(attempt)` can legitimately disagree.** Attempt
  numbers now come from the attempt rows (ADR-0009), and the claim counter counts
  claims — including claims whose row was never written, and claims made while the
  kill switch was off. Neither is wrong; they answer different questions. Any
  future admin UI must not present one as the other.

- **`consolidation` is validated by SHAPE, not by vocabulary** (ADR-0009). The
  storage contract refuses what `sanitize_key()` would have repaired, without
  inventing an enumeration for behaviour that does not exist yet. When
  consolidation lands it must become an enumerated column like `delivery_mode`.

## Prompt 5C — insert correlation and phase isolation

### Findings recorded, deliberately not fixed

- **⚠ The §5d residual survives §5e, in a narrower form.** A third party that
  completes a render on the same email object between the enclosing render's
  TERMINAL POSITION and that send's header evaluation still promotes last and is
  taken. §5e closed every window inside the render (`order_meta`,
  `customer_details`, the footer, and anything nested from them); what remains is
  the one-PHP-statement gap between `get_content()` returning and `get_headers()`
  being evaluated, plus a third party rendering from a lower-priority callback on
  `woocommerce_email_headers` itself. The order cannot separate the two candidates
  because `$email->object` is already corrupted by then (ADR-0013 §5, flagged), and
  the failure is a mis-attribution between two renders of the *same* email — never
  a lost delivery.

- **A custom template that fires neither `customer_details` nor the footer gets no
  correlation at all.** Verified: no WC 10.9.4 order-email template does this, so it
  takes a template override. The render is never promoted, the send identifies
  nothing, and the slot is swept `unresolved` rather than `abandoned` — honest, but
  the merchant sees an unknown outcome for a message that probably went out. A
  future prompt could add a terminal-position backstop at
  `woocommerce_email_footer`-equivalent depth for plain text; there is no such hook
  today.

- **`RenderLedger::$candidates` and `RenderContext::$renders` are not capped.**
  Both are bounded in practice by `MAX_RENDER_DEPTH` for nesting and by the number
  of sends in a request, and §5f now collects leaked records at every frame close —
  but neither has an explicit cap of the kind ADR-0013 §5c gives the diagnostic
  arrays. A third party sending in an unbounded loop grows `$candidates` by one
  entry per send that never sends.

- **Attempt typing costs one extra indexed read per insert record.**
  `has_genuine_attempt()` now runs on every `record_attempt()` rather than only when
  the status is deferred, because `type` needs it too. It is a single
  `LIMIT 1` on the `delivery_id` index inside a transaction that already holds the
  parent lock, and it replaced a `find_by_id()` read that used to happen outside it.

## Prompt 5D — SEND SCOPE FREEZE (the last Prompt 5 correction pass)

### Contract-consistency gate

- **Every comment claiming priority 999 "runs after any third party" was wrong and
  is corrected.** WordPress runs LOWER priority numbers first. The claim appeared in
  `RenderEvents::register()`, in `RenderEvents::on_emissions_end()`, in
  `RenderEvents::on_footer()`, twice in `RenderLedger`'s `$candidates` and
  `promote_render()` docblocks, and in ADR-0013 §5e. Promotion now runs at
  `PHP_INT_MAX` and the *real* guarantee is stated in ADR-0013 §5e — highest
  available priority, with a later registration at the same priority still running
  after ours.
- **ADR-0013 §5e's template table said the two POS receipt templates "fire no
  footer at all".** They fire `woocommerce_pos_email_footer`
  (`customer-pos-completed-order.php:122`, `customer-pos-refunded-order.php:133`).
  Corrected there and in the flagged-behaviour list.

### ⚠ THE STOP RULE — accepted residual risks, CLOSED to further correction

Prompt 5D is the fifth correction pass on Prompt 5 and the last. The same
correlation defect appeared at `current_render()`, `bind()`, `reserve()`,
`take_completed()` and `observe_send()` — one hook further out each round. The
render lifecycle has finitely many hooks; third-party behaviour does not.

**From here on, any finding that requires a third party to send or render from
inside another send's argument evaluation is recorded here as accepted residual
risk and is not fixed.** The list below is that record. None of them loses a
delivery; each is a mis-attribution between two renders of the *same* email
object, produced by a third party doing something WooCommerce gives it no reason
to do.

- **A send that reaches `woocommerce_mail_content` with NO observation frame open
  consumes the innermost frame that is open** — which may belong to an enclosing
  send. Produced by calling `WC_Email::send()` directly with a literal headers
  string, or by an email class that overrides `get_headers()` and
  `get_attachments()` without applying their filters. No WC 10.9.4 email class does
  either. `woocommerce_mail_content` carries no `$email` argument (flagged in
  ADR-0013), so there is nothing at that point to validate against; the pre-5D
  scalar had the identical exposure.
- **A send that fires `woocommerce_email_attachments` without ever firing
  `woocommerce_email_headers`** pairs with the enclosing send's frame instead of
  opening its own. Same cause, same absence of any WC 10.9.4 instance.
- **A nested send that opens a frame and then THROWS before reaching
  `woocommerce_mail_content`, with a third party swallowing the throw**, leaves that
  frame on top. If it carries the same object and email id as the enclosing send,
  the enclosing send's attachments observation pairs with it and reserves the
  nested render's token. The identity check rejects a stale frame of a *different*
  object or email id; it cannot reject one that is identical, which is this defect
  class's permanent limit. The stale frame is otherwise dropped and counted at the
  shutdown sweep, and its slot reported as an abandoned render.
- **A callback registered LATER at `PHP_INT_MAX` still runs after this plugin's
  promotion**, because same-priority callbacks run in registration order. A render
  nested from such a callback promotes after the render enclosing it. This is the
  floor of what any priority can guarantee.
- **The §5d/§5e residual, unchanged:** a third party completing a render on the
  same email object in the one-PHP-statement gap between `get_content()` returning
  and `get_headers()` being evaluated still promotes last and is taken.
- **A nested send that throws between `reserve()` and `bind()` leaks one
  reservation** (recorded in Prompt 5A, still true). The reservation stack stays
  depth-aligned for every send that completes.

### Findings recorded, deliberately not fixed

- **`RenderLedger::$send_frames` has a clearing path but no numeric cap.** One
  frame is popped per `reserve()`, and the whole stack is cleared by `take_open()`
  at shutdown — so it is bounded by live send nesting in every ordinary request.
  It grows only when a third party evaluates `get_headers()` / `get_attachments()`
  **without sending**, repeatedly: each such call costs that party a full
  WordPress filter dispatch and costs this plugin one four-key array. This is the
  same shape as the uncapped `$candidates` and `$renders` recorded in Prompt 5C,
  and is left with them rather than given a third bespoke bound.
- **`rendered_at` / `finalized_at` on the per-attempt snapshot.** ADR-0013 §6c now
  states that attempt numbers are persistence order, not occurrence order, because
  abandoned renders are written at the shutdown sweep. Recording the two timestamps
  would make the true occurrence order recoverable for support tooling and the
  future admin UI. Not built in a correction pass: it is an addition to the audit
  payload and belongs with the admin work that would read it.
- **`RenderContext`'s three diagnostic arrays survive `shutdown()` on purpose.**
  They are hard-capped at `MAX_DIAGNOSTIC_ENTRIES`, they exist to explain the
  request after it ends, and `shutdown` is when something reads them. Every
  collection that carries *state* is cleared there (ADR-0013 §5h).

## Prompt 6 — placeholders

### Contract-consistency gate

- **`Custom_Email::get_subject()` said "placeholder substitution does not exist
  yet"** and `$delivery_content` said only that it was kses-filtered. Both now
  state what ADR-0014 §9 actually does, including the deliberate continued absence
  of `WC_Email::format_string()` — WooCommerce's own, differently-spelled
  substitution pass, whose values this plugin does not control.
- **`Render\Injector::to_plain_text()`** now delegates to
  `Domain\Text::to_plain_text()`. The flattening rule — and the ⚠ WooCommerce
  behaviour it exists for — had to be shared with separate mode, and two copies of
  a rule about what WooCommerce deletes is one copy too many.

### Findings recorded, deliberately not fixed

- **⚠ `{shipping_method}` costs two queries per delivery, once, whatever the
  placeholder count.** `WC_Order::get_shipping_method()` loads the order's
  **shipping** line items — a line-item type nothing else in the delivery path
  reads. It is memoised per delivery, so occurrences are free; only bodies that
  ask for it pay. Recorded rather than removed: the alternative is reaching around
  WooCommerce's own accessor into the item table, which would break the moment
  shipping storage changes.
- **A placeholder whose underlying field is EMPTY is not recorded.** An order with
  no phone number is not an authoring error, and recording it would bury the real
  §1a signal — an unknown token or a refused key — in noise. The consequence is
  that "blank because empty" and "blank because the field does not exist on this
  order" look the same to a merchant; the rule editor (Prompt 9) is where that
  distinction can be shown before the email is sent.
- **`{product_name}` on a multi-match rule is an authoring choice** (ADR-0014 §5).
  The rule editor should surface `{product_names}` and `{matched_product_list}`
  beside the singular forms and say which is which — **a Prompt 9 note**, recorded
  here because the editor is the only place the distinction can be made visible.
- **The meta allow-filter is per key, in code, with no UI.** Deliberate: a setting
  a merchant can flip without understanding it is how `_stripe_source_id` ends up
  in a customer's inbox. If a UI is ever added it must name the key, show its
  current value, and warn — not offer a blanket "allow private fields" switch.
- **`{store_email}` reads the `woocommerce_email_from_address` setting**, falling
  back to `admin_email`. If a store later gains a per-email from-address, this
  placeholder will keep answering with the global one until it is taught
  otherwise.
- **The plain-text destination cannot carry angle brackets at all** — WooCommerce
  strips every tag from the whole plain body (ADR-0014's flagged list). A merchant
  writing `a < b` sees it in HTML and not in the plain alternative. Not fixable
  from this side without escaping a body that must not be escaped.

## Prompt 6A — the placeholder failure boundary and contract freeze

### Contract-consistency gate (direct examination, gate 9)

- **ADR-0012 §6's first paragraph said content is "sent literally; no placeholder
  substitution exists yet".** False since Prompt 6. Struck through and marked
  **superseded**, pointing at ADR-0014; the rest of §6 (kses at storage,
  WooCommerce's wrapper, plain-text output) is unchanged and still binding.
- **This backlog's own "Placeholders (a later prompt)" entry said the same
  thing.** Struck through and marked superseded, with the question it left open —
  what an empty resolved recipient means — answered against ADR-0014 §7.
- **"Costs no queries" appeared in five places** — `PlaceholderResolver` (twice),
  `PlaceholderValues`, `Orchestrator`, `Injector` and `RenderEvents` — and
  contradicted the ADR-0014 §8 measurement in the same repository. Every one now
  states the measured contract instead: **no per-placeholder and no per-occurrence
  query growth; one bounded, named cost per distinct data class a body reads.**
- **ADR-0014 §6.3 said "scalars only" while the code refused booleans.** PHP counts
  `bool` as a scalar, so the ADR described the code incorrectly. The contract is now
  **string, integer or float**, with the boolean decision stated: `(string) true` is
  `"1"` and `(string) false` is `""`, two spellings one of which is
  indistinguishable from "nothing was stored", so the plugin declines to guess and
  **records the refusal as a boolean** rather than as a non-printable value.
- **The public-key veto diagnostic was false.** A site filter refusing a *public*
  key was logged `refused a protected meta key`, sending a merchant looking for an
  underscore that was not there. Two reasons now: default-deny on a `_`-prefixed
  key, versus `a site filter (extonify_wcep_meta_placeholder_allowed) refused the
  public meta key`.
- **The Prompt 6 "hostile value appears safely in both formats" requirement is
  superseded**, in ADR-0014 §3 rather than left standing beside the honest report
  of it: in plain text WooCommerce **deletes** `<script>alert(1)</script>` outright
  via `wp_strip_all_tags()`. The amended requirement is *neutralised* in both
  formats — escaped and visible in HTML, removed by WooCommerce in plain text.

### Findings recorded, deliberately not fixed

- **⚠ The delivery kill switch does not silence INSERT mode, and that is currently
  correct but is a product question.** `Custom_Email::is_globally_enabled()` reads
  the saved `enabled` setting of *this plugin's own* `WC_Email` — the email insert
  mode never sends. A merchant unticking it therefore stops separate-mode
  deliveries and leaves inserted blocks in native emails. Defensible (the box is
  labelled for one email) and surprising (the box is the only switch there is).
  **Not a mode twin**, so ADR-0014 §11 records it as out of the audit's scope; the
  settings UI prompt should either relabel the box or add a second switch.
- **⚠ Separate mode does not derive attempt `type` from delivery history**
  (ADR-0013 §6b) and does not allocate attempt numbers under the parent lock
  (§6c). Live-correct: ADR-0004 gives separate mode at most one attempt set per
  identity, so `auto` is always right and there is no numbering to race. **A manual
  resend feature must give separate mode the same history-derived typing**, or a
  merchant's first delivery will be recorded as a resend for exactly the reason
  §6b describes.
- **⚠ A meta value that is a boolean, and a meta value that is missing, both render
  blank.** The boolean is recorded and the missing key is not (ADR-0014 §4), which
  is the right split for the log but still leaves two blanks in the email. The rule
  editor is where the difference can be shown before sending.
- **⚠ The per-item binding covers item-scoped placeholders only.** `{order_*}`,
  `{customer_*}` and the plural product forms are order-scoped and identical in
  every block by definition — that is what makes them the plural forms — but a
  merchant reading two blocks that differ in some placeholders and not others has
  to know which is which. A Prompt 9 editor note.
- **⚠ An overlength meta key resolves empty and is recorded with the FULL key in
  the note.** A template carrying twenty distinct multi-kilobyte keys would produce
  a long `reason`; it is bounded by `MAX_NOTES` (20) and by
  `Domain\Text::log_value()` (2000 characters), so the column cannot be overrun —
  but the note is then truncated mid-key. Acceptable: the merchant's fix is visible
  from the first characters.
- **⚠ A `Throwable` from a third party is caught, recorded and swallowed — it is
  never re-thrown.** That is the whole point in a `woocommerce_order_status_changed`
  handler and inside a rendering hook, but it means a genuinely broken site can
  fail every delivery quietly except for the `wc_get_logger()` error and the
  `failed` tombstones. A future admin surface should count them.

## Prompt 7A — THE SCHEDULED STATE MACHINE

Six reported findings were **one defect**: the `scheduled` state had no atomic
transitions and no guaranteed terminal exit. All six are fixed together by
[ADR-0015](adr/ADR-0015.md) §8, not by six patches. Everything below is what was
found alongside and deliberately **not** fixed, plus the contracts this leaves on
later prompts.

### Contract-consistency gate

- **⚠ The prompt's own transition table was incomplete, and adapting silently
  would have weakened an ADR.** It named five edges; `executing → cancelled` and
  `executing → skipped` are also required, and ADR-0015 §8.1 records why. ADR-0015
  §4 mandates `cancelled` with a distinct reason for all six re-validation checks,
  and those checks run **after** the lease is taken — without that edge they would
  have had to record `failed`, which is untrue (nothing failed; the merchant
  disabled the rule). ADR-0012 §2's claiming skips are likewise discovered inside
  the send, under the lease. The alternative — taking the lease *after* the §4
  checks — would have widened the duplicate-email window the lease exists to close.
- **⚠ "The existing daily maintenance action" did not exist.** ADR-0015 §8.3's
  stale-lease sweep needs a recurring action.
  `Deactivator::RECURRING_HOOKS` has named `extonify_wcep_retention_purge` since
  the foundation, but **nothing ever scheduled it and nothing ever handled it** —
  it was a reserved name and a backlog item. A new `Install\Maintenance`
  (`extonify_wcep_maintenance`) was created rather than borrowing a hook whose name
  promises retention work the sweep does not do. **Retention joins that action when
  the settings UI lands**; the hook name is deliberately generic so that addition
  is not a rename.
- **`DeliveryLogger::record_scheduled_cancellation()` and
  `ScheduledDelivery::cancel()` now take a REQUIRED `$from`.** No default, on
  purpose: the two possible answers (`scheduled` for the eager and lifecycle
  paths, `executing` for anything discovered under the lease) are not
  interchangeable, and a default is a decision somebody can skip making.

### Required for the prompts that follow

- **⚠ A MANUAL RESEND FEATURE MUST NOT TREAT `unresolved` AS "RETRY THIS".** ADR-0015
  §8.3 records a swept lease as `unresolved` precisely because nobody knows whether
  the message went out — the worker may have died one microsecond after the mailer
  accepted it. A resend UI that offers `unresolved` deliveries a one-click retry
  turns every stranded lease into a duplicate customer email, which is the failure
  the whole state machine was built to end. Surface it, describe it honestly, and
  make the human choose.
- **The settings UI should expose the lease window.** `LEASE_WINDOW_SECONDS` is
  one hour, argued from Action Scheduler's own 300 s `mark_failures()` period and
  PHPMailer's 300 s SMTP timeout (ADR-0015 §8.3). A store on a host with a much
  longer `max_execution_time`, or one using a queue backend with different timings,
  may want it longer. It must never be shortened below Action Scheduler's own
  failure period.
- **The maintenance sweep has no admin surface.** `leases_recovered`,
  `orphans_requeued` and `orphans_cancelled` go to the WooCommerce log only. Each
  is a delivery that went wrong in a way nothing else can see, so the delivery
  history phase should count them.

### Findings recorded, deliberately not fixed

- **⚠ Tier 3 — a transient re-schedule that ALSO cannot reach Action Scheduler is
  recovered only by the next daily sweep.** ADR-0015 §8.4's rule-3 branch fires when
  `Migrator::is_operational()` is false, which by hypothesis means this plugin's
  tables are unavailable — so a terminal state cannot be written, and if the
  re-schedule *also* fails the tombstone stays `scheduled` with no job. It is logged
  loudly, and §8.3's orphan half recovers it once the schema returns, so the delivery
  is **late rather than lost**. Fixing it further would require writing to a table
  that does not exist. Requires two simultaneous infrastructure failures.
- **⚠ Tier 3 — `unschedule()` costs `MAX_RESCHEDULES + 1` queue lookups.** Args-exact
  matching means a delivery re-scheduled past a transient condition owns a job whose
  arguments differ, so cancelling one delivery must sweep the bounded attempt range
  (4 lookups today). Every caller is a lifecycle or admin path — eager cancellation,
  deactivation, uninstall, order deletion — and order deletion additionally skips
  terminal tombstones, so the normal cost is zero. Not on any delivery path.
- **⚠ Tier 2 — the `lease_sweep` index leads on `final_status`, whose cardinality is
  low.** That is the intended shape: it narrows to deliveries **in flight**, whose
  count is queue depth rather than the lifetime of the store (ADR-0015 §2). On a
  store with a very large simultaneous backlog the residual scan grows with queue
  depth; the batch cap bounds the work per pass regardless.
- **⚠ Tier 2 — deactivation and uninstall cancel in BULK, permanently.** ADR-0015
  §1a's identity-consumption rule means deactivating the plugin permanently cancels
  every in-flight delayed delivery and reactivating does not resume them (§8.7).
  This is correct and is the alternative to a plugin toggled off and on
  re-delivering to every order still inside its delay window — but it is the
  behaviour most likely to be reported as a bug, and **the deactivation confirmation
  UI should say so** when there is one.
- **⚠ Tier 2 — `uninstall.php` finalises tombstones in raw SQL.** The file is
  dependency-free by design (no autoloader, no WooCommerce), which is the documented
  exception to "SQL only in a repository". It therefore writes `cancelled` without
  going through `DeliveryRepository::transition()`, so the permitted-transition
  allowlist does not police it. The statement is guarded `WHERE final_status =
  'scheduled'`, which is the same predicate the transition would apply, and a
  delivery mid-flight or already terminal is left alone. A test asserts the
  outcome; nothing asserts the two stay in step if the allowlist changes.
- **⚠ Tier 2 — a `scheduled` tombstone whose order was TRASHED still holds its
  snapshot until the job runs.** ADR-0015 §4 check 4 cancels it correctly at
  execution, releasing the snapshot then — but a merchant who trashes an order with
  a week-long delay leaves a week of retained rule content. Bounded by queue depth,
  so not unbounded growth; eager cancellation on order trash would close it.
- **⚠ Tier 3 — a third party can still throw between the lease and the send.**
  The lease is taken first (ADR-0015 §8.2), so a worker that dies during §4's
  re-validation strands the delivery until the sweep records `unresolved` — a
  window that a lease-last design would not have. That trade is deliberate and
  argued in §8.2: lease-last would leave the duplicate-email window open, and a
  duplicate reaches a customer where an hour's delay in the log does not.
- **⚠ Tier 3 — `RunOutcome::to_array()` now reports `scheduled`, `claim_failed` and
  `deferred`.** Any consumer that compared the flat array by equality sees new
  keys. Nothing outside the tests consumes it today.

## Prompt 7B — SCHEDULED LIFECYCLE COMPLETENESS (the last delayed-delivery correction round)

Three defects, fixed as three: a boolean that merged **"another actor won"** with
**"the write failed"** ([ADR-0015](adr/ADR-0015.md) §8.1a), an orphan sweep that
could not page past its first hundred rows (§8.3a), and `executing` having no
lifecycle handling at all (§8.8). Everything below is what was found alongside and
deliberately **not** fixed, plus what this leaves for later.

### Contract-consistency gate

- **The three Tier 2 items from the prompt were fixed in this round, not deferred.**
  Detail rows are now written only after ownership is won (`record_scheduled_cancellation()`,
  `record_expired_lease()`, `record_scheduled_failure()`, `record_schedule_throw()`,
  and the new `record_arm_failure()`); the two-connection tests are described as
  **competing writes** rather than interleaved races, and a genuinely interleaved
  one was added (`test_a_lease_blocked_by_a_real_lock_wait_is_not_a_lost_race()`,
  which blocks on an uncommitted row lock and proves the blocked writer reports
  `query_failed`, not a lost race); the packaged `src/` file count is reported from
  the tree rather than from memory.
- **`DeliveryRepository::transition()` and `arm_scheduled()` no longer return
  `bool`.** They return `Domain\WriteResult`. Any future call site that reads one as
  a boolean is a **failing unit test** (`WriteResultTest::test_no_source_file_reads_a_guarded_write_as_a_boolean`),
  not a defect somebody has to notice — the same shape as the collection census.
- **The gate on removing a queued job is `finalized`, never `success`.** `success`
  additionally requires the detail row to have been written, and a delivery that is
  genuinely terminal with a missing log row must still not keep a live job.
- **`find_scheduled_after()` became `find_pending_after()`** and selects both
  `scheduled` and `executing`. The old name described the defect: `executing` was
  invisible to every lifecycle path.

### Required for the prompts that follow

- **⚠ THE SETTINGS/UNINSTALL UI MUST SAY WHAT A SHUTDOWN DOES TO WORK IN FLIGHT.**
  ADR-0015 §8.8: deactivating cancels every `scheduled` delivery permanently and
  records every `executing` one as `unresolved`. The second is the one worth
  wording carefully — it means *we do not know whether that customer got the email*,
  and §8.7's identity rule means reactivating resumes nothing.
- **The maintenance sweep's cycle has no admin surface.**
  `extonify_wcep_sweep_cycle` is an implementation detail of §8.3b, but on a store
  deep enough to need it, "the sweep is currently 400 rows into a 3,000-row cycle"
  is exactly what a support person would want to see. `sweep()` already returns
  `examined`, `cursor`, `high_water` and `cycle_complete` — which is the whole
  progress bar.
- **A store whose pending queue exceeds `SWEEP_THROUGHPUT` (1,000 rows per half per
  run) takes more than one day to examine all of it.** That is the stated bound —
  `ceil(E / T)` runs per cycle (§8.3b) — and the cycle mark is what makes it a bound
  at all rather than a hope. But the daily interval is what turns a 10,000-row queue
  into a ten-day cycle. If real stores get there, raise the page cap or run the sweep
  more often; do not remove the cap, and do not remove the mark.

### Findings recorded, deliberately not fixed

- **⚠ Tier 2 — `uninstall.php` records no per-delivery REASON.** It writes the
  terminal status and releases the snapshot in raw SQL, but a detail row is
  allocated inside a transaction holding the parent lock
  (`DeliveryDetailRepository::record_attempt()`) and cannot be reimplemented in a
  dependency-free file without duplicating the repository it exists to do without.
  This is also why there is no `plugin_uninstalled` reason code: a constant nothing
  can produce is drift (gate 9). The **statuses** are asserted by
  `ScheduledLifecycleTest`; the sentence is simply absent on that path.
- **⚠ Tier 2 — a deactivation that cannot finalise leaves the maintenance action
  queued too.** The hook-wide unschedule is skipped as a whole when any tombstone is
  left pending (§8.8), and that sweep is also what removes
  `extonify_wcep_maintenance`. A daily action for an inactive plugin fires against a
  hook with no handler and does nothing — the strictly safer failure, and it is the
  action that would recover those rows if the plugin comes back — but it is queue
  litter until then.
- **⚠ Tier 2 — the orphan sweep's cursor can delay (never prevent) reaching a row.**
  A row that becomes orphaned BEHIND the cursor waits for the current cycle to close.
  ~~The cursor resets to 0 whenever the candidate set is exhausted, and one wrap is
  allowed inside a run, so the delay is bounded by one cycle.~~ **CORRECTED IN PROMPT
  7C: that was true only of a quiet queue.** Under sustained inflow no page came back
  short, the reset never fired, and the delay was UNBOUNDED — a Tier 1 defect, fixed
  by the cycle high-water mark (ADR-0015 §8.3b). The delay is now `ceil(E / T)`
  maintenance runs of the following cycle, `T` = 1,000 — bounded, but on a very deep
  queue still measured in days.
- **⚠ Tier 2 — the orphan half has no index of its own.** It filters
  `final_status = 'scheduled' AND last_seen_at <= ? AND id BETWEEN ? AND ?` and there
  is no `(final_status, last_seen_at)` index; it uses `lease_sweep`'s leading
  `final_status` column, or the primary key for the bounded id range. The candidate
  set is the pending queue rather than the table, and the page cap bounds the work
  either way, so this is a plan-quality question and not a correctness one. Adding an
  index means a migration and a change to `SchemaVerificationTest`'s exact index set,
  which is not work to do in a correction round.
- **⚠ Tier 3 — the lease half's throughput is reduced by rows it cannot recover.** A
  row whose recovery WRITE fails stays `executing` and stays a candidate, so `P` such
  rows cost `P` of each run's `T` (ADR-0015 §8.3b). It cannot starve the ROW — the
  half reads ascending from 0 every run, so the poisoned rows are examined every time
  — only the budget behind them. Requires a database that accepts reads and refuses
  writes, and every occurrence is logged. Repairing it with a persisted cursor would
  break the stronger property that inflow can never push an older lease backwards.
- **⚠ Tier 3 — a maintenance action deleted by hand stays deleted for up to
  `VERIFY_INTERVAL_SECONDS`.** `Maintenance::ensure_armed()` trusts its autoloaded
  verification stamp for an hour (ADR-0015 §8.3c). Deactivation clears the stamp, and
  a failed arm never writes one, so the window only opens when a merchant or a queue
  purge removes the action from underneath a verification that was true when written.
  One hour matches the lease window: a stranded delivery is already accepted as
  stranded for that long before the sweep may touch it.
- **⚠ Tier 3 — a cycle whose last full page lands exactly on the mark costs one short
  read.** The next run reads one page, finds the frozen set exhausted, closes the
  cycle and stops; the cycle after that gets a full budget. Rolling straight into the
  next cycle in the same run would recover that page but would hand the new cycle a
  partly-spent budget, and `ceil(E / T)` would stop being true of its first run
  (ADR-0015 §8.3b). One indexed SELECT a day is the cheaper side of that trade.
- **⚠ Tier 3 — a write that fails on BOTH the transition and the re-queue leaves a
  `scheduled` row with no job until the next sweep.** `handle_ungranted_lease()`
  re-queues (rule 3) and terminalises loudly when the cap is spent; if that
  cancellation ALSO fails, the row keeps `scheduled` with no job — which is exactly
  the shape §8.3a's orphan half recovers. Requires two simultaneous write failures.
- **⚠ Tier 3 — `record_expired_lease()` serves two events.** The stale-lease sweep
  (§8.3) and shutdown (§8.8) share the `executing → unresolved` transition and differ
  only in the recorded reason code. If a third event ever needs it, give it a reason
  rather than a second method: the transition is the contract, the reason is the
  answer to *why didn't this send?*
- **⚠ Tier 3 — `inspect()` reads `$wpdb->last_error` to tell a missing row from a
  failed read.** `wpdb::query()` clears it through `flush()` before every statement,
  so the value belongs to that read — verified against WordPress 6.8's `wpdb`. A
  drop-in replacement for `$wpdb` that does not clear it would make a missing row
  look like a failed read, which fails **safe** (the caller declines to act rather
  than acting on a guess).
