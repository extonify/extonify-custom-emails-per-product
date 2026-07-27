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
- **Delayed-delivery snapshot execution** end-to-end ([ADR-0007](adr/ADR-0007.md))
  — scheduling, snapshot storage, execution-time validation, re-arm avoidance.
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

### Required delivery-phase test — `rule_disabled` end to end

**`rule_disabled` is currently unreachable through the immediate path and is not
proven end to end anywhere.** `find_active_for_trigger()` filters
`status = 'active'` in SQL, so the immediate path can never produce it, and
`MatchingEngineTest::test_a_rule_row_marked_inactive_is_reported_as_rule_disabled()`
proves only that the matcher honours a row it was *handed* already marked
inactive — it rewrites the fetched array itself. That is the documented contract
(ADR-0011 §7a), not a defect, but it means the reason code has no end-to-end
coverage.

**The delivery phase must add this test:** fetch candidate rules, disable one in
the **database** without touching the fetched array, run the **real deferred
execution path**, and assert the disabled rule neither sends nor is silently
dropped — it must appear in the log as `rule_disabled`. That test belongs with
the ADR-0007 re-validation it exercises; it cannot be written before the deferred
job exists.

Do **not** solve this inside the engine. Candidate-id persistence with
re-fetching at deferred execution would be a second staleness mechanism running
beside the ADR-0007 snapshot — two sources of truth for one question, the exact
defect eliminated in [ADR-0002](adr/ADR-0002.md) when per-rule `WC_Email`
registration was rejected.

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
