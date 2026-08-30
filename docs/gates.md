# The gate index

A gate is a claim this plugin makes about itself that something in the repository has to
keep true. Until Prompt 13C the numbers lived only in the prompts that created them, and
those prompts were never committed — so a release report could cite "gate 44" and nobody
reading the source tree could check what gate 44 was, let alone whether it held. The
Prompt 13B report was right to refuse to assert ten of them for exactly that reason.

**A 50-gate contract nobody can audit from the source tree is not a contract.** This file
is the audit surface: every number, what it claims, and where the evidence is.

> **⚠ THE INDEX IS COMPLETE AS OF PROMPT 13C PART A2. No number is undefined.** Ten rows —
> **1, 2, 3, 4, 8, 11, 44, 46, 47 and 49** — had no committed definition when this file was
> written. Gate 44 was recovered in Part A from the five-version matrix; the other nine were
> **supplied verbatim from the prompt series in Part A2** and are written in below with
> their evidence. They are transcriptions of the defining prompts, not reconstructions from
> guesswork, and this note is here so a future reader knows which they are reading.
>
> **A definition is not a pass.** Recovering what a gate asks for says nothing about whether
> it holds; each still has to be run and reported like any other.

## How to read it

- **Evidence** names a test class, a test method, or the command that produces the
  evidence. `Foo::bar()` means `tests/Integration/FooTest.php` unless the row says
  otherwise.
- **Manual** means there is no automated assertion. Those rows say so in full rather than
  pointing at something adjacent, because "evidenced by a nearby test" is how a gate stops
  being a gate.
- **Command** means the evidence is a command's output rather than an assertion in the
  suite. Those rows name the command and the place each round records its result, because a
  gate whose evidence is nowhere recorded is a gate nobody can check afterwards.
- **Recovered in Part A2** (gates 1, 2, 3, 4, 8, 11, 46, 47, 49) means the row is a
  transcription of the prompt that defined the number, committed here for the first time.
  Gate 44 was recovered in Part A the same way, from the five-version matrix.
- Most gate tests print a `[…]` line to `STDERR` as they pass. Those lines are the
  human-readable evidence and are quoted in `docs/p2-backlog.md`'s round reports; the
  label mapping is at the foot of this file.

Gates 1–49 were defined across Prompts 1d–13B. **Gate 50 is new in Prompt 13C**, so the
contract is 50 gates as of this document, and all 50 are defined here.

---

## The index

| # | Claim | Evidence |
|---|---|---|
| 1 | `composer validate --strict` reports the manifest clean. | **Command.** `composer validate --strict`, run per release round; the result is recorded in that round's `docs/p2-backlog.md` section |
| 2 | `composer phpcs` reports **zero errors and zero warnings**, with **no new undeclared suppressions**. Any new suppression must be single-line, single-sniff, reasoned inline, and listed in the round's report. | **Command.** `composer phpcs` (**80** linted production files — the 78 under `src/` plus `extonify-custom-emails-per-product.php` and `uninstall.php`; ruleset in `phpcs.xml.dist`. ⚠ The count read 78 until Prompt 13C Part G, which was the `src/` count quoted as if it were the linted set, and the progress line `.... 4 / 4` is `parallel=4` **batches**, not files — read the count from `--report=json`, not from the dots); the counts and any new suppression go in that round's `docs/p2-backlog.md` section |
| 3 | `composer test:unit` is green **and is proven not to boot WordPress** — the pure-logic suite must pass with the WordPress bootstrap switched off entirely. | `composer test:unit`; `SuiteIsolationTest` (unit) is the proof of the second half, and `phpunit.xml.dist`'s `unit` testsuite plus `tests/bootstrap.php` are where the separation lives |
| 4 | `composer test:integration` runs the **full** suite — no filters, no exclusions — **finishes**, reports its final totals, and is verified to have run in a **single process**. **Last executed: Prompt 13C Part N** — 930 tests / 17 016 assertions / **0 failures** / 0 skipped on the dev machine (WP 7.1, WC 11.0.1, PHP 8.3.6), single process, 27:21, 0 messages reaching PHPMailer. ⚠ Part N ran it **twice**: the floor corner (gate 45) failed two of Part N's own new tests because WooCommerce 9.6.0 has no block email editor, and fixing those test files after the first green run required a full re-run of gate 4 **and** the whole matrix. ⚠ Part M ran it **twice**: a single-sniff correction to a new `phpcs:ignore` landed after the first green run, so the archive was rebuilt and the suite re-run in full; both were green with identical totals and only the later is quoted. History: Part D 888 / 16 687, Part E 895 / 16 717 (+7 `ReasonTextTest`), Part F 904 / 16 792 (+8 `AdminNavigationTest` +1 gate 47h, −1 replaced), Part G 906 / 16 805 (+2, the insertable map and DOM order), Part H 906 / 16 805 (no test added — Part H changed strings and comments only), Part I 906 / 16 805 (no test added — Part I changed `readme.txt` prose and nothing else), Part J 908 / 16 810 (**+2**: the packaging contract changed, so `ReleaseArchiveTest` gained `test_the_composer_manifest_is_shipped()` and a `vendor/bin/` forbidden pattern), Part L 920 / 16 940 (**+1 test, +11 assertions**: `InsertModeEditorTest`'s admin-insert-target sweep, which searches the whole rendered editor for ten categorical customer-recipient claims rather than asserting one corrected string), Part K 919 / 16 929 (**+11 tests, +119 assertions**: `InsertModeEditorTest`, the ADR-0017 §5a insert-mode honesty file, contributes 11/117 — the other **2** assertions are gate 33b's, because the recipients section became a second named `role="group"` and that test asserts a name and a resolvable IDREF per group), Part M 926 / 16 987 (**+6 tests**: `InsertTargetTest`, ADR-0013 §2a — the first test in the series to ENUMERATE the set the editor offers rather than sample it, which is the assertion whose absence let a Tier 1 defect through 920 tests), Part N 930 / 17 016 (**+4 tests**: ADR-0013 §2a-i — the enumeration now varies the BLOCK EMAIL EDITOR flag, which is the configuration Part M's version never varied and where its own fix re-opened the defect). Part E was the first run of the series with no failure at all: every earlier suite carried one stale-archive failure, which the Part D archive rebuild cleared. ⚠ **A ROUND THAT EDITS THE TREE AFTER ITS SUITE HAS PASSED MUST RUN IT AGAIN, AND THREE ROUNDS HAVE NOW HAD TO.** Part D re-ran after a `readme.txt` fix forced a second archive rebuild; Part G re-ran after a CSS fix, a test extension and four comment corrections landed once `AdminOutputTest` had already executed; Part I **killed a running suite outright** (`SIGTERM`, exit 143) the moment a readme finding forced four more edits, rather than let it certify a superseded tree, and rebuilt the archive before restarting. In each case the LATER run is the one this gate is asserted from and the earlier log is kept but not quoted. | **Command.** `composer test:integration` with the guard variables from `docs/testing.md`; the final totals and the single-process check are recorded in that round's `docs/p2-backlog.md` section. ⚠ A filtered run does not satisfy this gate and cannot: it cannot fail in a class it does not select. ⚠ **AND `composer test:integration` NEEDS `COMPOSER_PROCESS_TIMEOUT=0`.** Composer kills a script at 300 s; this suite takes about half an hour, so without it the run dies mid-class with a non-zero exit that reads exactly like a failing suite. Part H lost a run to it and `docs/testing.md` § *Running it* now carries the variable |
| 5 | No test in either suite ever reaches a real mail transport. | `MailGuard` (a `phpmailer_init` tripwire plus a `pre_wp_mail` short-circuit) + `IntegrationTestCase`; the suite prints a per-class interception census and a "messages reaching PHPMailer: 0" line at the end of every run |
| 6 | Cost is bounded and named: a fan-out of N does not cost N times one delivery, placeholders cost one bounded query per distinct **data class** rather than one per placeholder, the delayed phase's second evaluation costs nothing, and a refused render costs zero queries. | `ConsolidationTest::test_a_fan_out_does_not_multiply_matching_cost`, `PlaceholderTest` (items 9 and 6A-5), `ScheduledDeliveryTest`, `RenderDepthTest`, `MatchingPurityTest`, `OrderMutationTest`, `InsertModeTest`, `DeliveryOrchestrationTest` |
| 7 | **Shipped `src/` count moved 78 → 79 in Prompt 13C Part M** (`src/Email/NativeEmailTargets.php`), and the archive 97 → 98 files. The release archive's `src/` set is the **same set** as the tree's, byte-identical in both directions, and the archive installs. **The shipped set is gated in both directions**: what must be absent (`composer.lock`, `vendor/bin/`, `docs/`, `poc/`, `tests/`, `bin/`, `*.md`, `*.xml.dist`, dev vendor packages, a nested zip) and what must be **present** — `composer.json`, shipped from Prompt 13C Part J on the Plugin Review Team's *"Using composer but no composer.json file"* note, asserted valid JSON declaring `GPL-2.0-or-later`. ⚠ **A file that stops being forbidden must start being required**, or narrowing an exclusion silently creates an ungated file. | `ReleaseArchiveTest` (label `[6B item 4d / gate 7]`), including `test_the_composer_manifest_is_shipped()` + `bin/build-release.sh`, which exits non-zero rather than removing a `vendor/bin/` that is not empty |
| 8 | The POC suite is still green. | **Command.** The scripts under `poc/`, run per release round; the result is recorded in that round's `docs/p2-backlog.md` section. `poc/` is read-only to this plugin's work |
| 9 | **Contract consistency**: nothing in `docs/p2-backlog.md`, and nothing in current behaviour, is a known violation of an ADR — checked by reading the ADR against the **code**, never against the backlog text. Every documented reason code is distinct, explained and actually producible. | `ScheduledExitBranchesTest` (every exit branch and reason code produced); otherwise **manual**, performed per round and recorded in that round's backlog section under "Contract-consistency gate" |
| 10 | **The collection census**: no per-request collection grows without bound. Every ledger, frame stack, note set and token list is enumerated, capped or cleared, and a request that sends N emails leaves no residue proportional to N. | `CollectionCensusTest` (unit, enumerates every collection in `src/`), `SendScopeTest`, `ConsolidationTest::…nested_delivery…`, `RenderContext::open_token_counts()`, `RenderShutdownTest` (the `take_open()` clearing path) |
| 11 | **Zero-leakage.** ⚠ **A ZERO HERE IS ONLY EVIDENCE IF THE RULE THAT WOULD HAVE INJECTED WAS LIVE, AND IF THE PAGE ACTUALLY RENDERED.** Prompt 13C Part K discarded two results before recording one: three 404s at 271 bytes (pretty permalinks, no `.htaccess`) and then a `200` with `Content-Length: 0` (a `wp core download --skip-content` install with **no theme**). **Part L then discarded a third — a clean-looking three-zero result whose control rule matched nothing**, because it was created with a raw `insert()` and `match_all` is written only by `RuleDocuments::targeting()`, so the stored document was `{"include":{}}`. All three read as perfect zeros; the control has caught something on every run. The recorded run pairs the three page fetches with a **positive control** — the counted marker is the body of an active insert rule, and the same order is transitioned with `pre_wp_mail` intercepting, proving the marker DOES reach the email it targets and no other. The storefront and preview assertions are clean: **zero** injected content and **zero** PHP notices on My Account view-order, thank-you and order-pay; and a preview writes no records. | `AdminIsolationTest` (front-end reachability), `PreviewInertnessTest` (rows 40a–40c) and `PreviewTestCase::assertPreviewWroteNothing()`/`assertNoLedgerResidue()` for the preview half; the storefront half is **manual** per release round against the three pages named, recorded in that round's `docs/p2-backlog.md` section |
| 12 | Hostile stored values render inert on every surface, with the value written **past the repository** so the storage-boundary filter is bypassed and the surface has to hold on its own. | `PreviewRenderTest::test_hostile_stored_values_render_inert…`, `AdminOutputTest` (gate 31 rows), `DeliveryHistoryTest` (gate 31 rows) |
| 13 | Every enumerated and identifier column is validated on its **raw** value, refused with no write, and the length bound is the column's own schema width. **New in Prompt 13C Part M:** `native_email_id` is additionally judged on whether the named email can ever FIRE — registered is not reachable (ADR-0013 §2a) — refused as `no_order_details`, capability-detected so an unbooted mailer refuses nothing. **Part N (§2a-i) fixed the evidence rule in both directions:** a template shared across emails can never prove one of them (WooCommerce's block email editor points all 18 at one file that fires the hook behind an id test excluding several), and `never` now requires that nothing further can be included. | `RuleRepositoryTest` (label `[5B item 4 / gate 13]`), `DeliveryPhaseTest`, `ConsolidationTest` |
| 14 | The render-depth bound holds: past it a render costs exactly zero queries and emits nothing, even when it shares the order, email and audience of a permitted render. | `RenderDepthTest` (label `[5B item 2 / gate 14]`) |
| 15 | The behaviour vocabulary is an **exhaustive enumeration**: `UNIMPLEMENTED_BEHAVIOUR_DEFAULTS` is empty, and one trigger splits across phases by stored value rather than by an unimplemented fallthrough. | `DeliveryPhaseTest` (label `[8 item 9 / gate 15]`), `ScheduledDeliveryTest` (label `[P7 item 13 / gate 15]`), `ScheduledExitBranchesTest` |
| 16 | Placeholder **values** are escaped per destination as a security property, not as behaviour — and only a literal boolean decides a filter's answer. | `PlaceholderTest` (label `[6B item 1 / gate 16]`), `ConsolidationTest`, `PlaceholderSyntaxTest` (unit) |
| 17 | The failure boundary around resolution holds in **both** delivery modes: a throw from third-party code during resolution is contained, recorded against the right delivery, and never escapes into the order event. | `PlaceholderContainmentTest` (labels `[6A item 1 / gate 17]`, `[P7 item 11 / gate 17]`), `ConsolidationTest`, `ScheduledDeliveryTest` |
| 18 | A failed or partial delivery is **visible**: the failure is recorded with a true reason, and the notice the merchant actually reads says what the delivery did. | `PlaceholderContainmentTest` (labels `[6B item 2 / gate 18]`, `[6B item 3 / gate 18]`), `DeliveryOutcomeNoticeTest` (label `[P13A item 3 / gate 18]`), `ConsolidationTest` |
| 19 | A `scheduled` tombstone and its Action Scheduler job stay in step in both directions: cancellation on rule disable or delete is **eager**, and no `scheduled` row is ever left without a job. | `ScheduledRevalidationTest`, `ScheduledIntegrityTest`, `ScheduledLifecycleTest`, `ScheduledStateMachineTest`, `ScheduledDeliveryTest` (labels `[P7 item 1/2/5/9/9b / gate 19]`) |
| 20 | The delayed-delivery snapshot is faithful and version-strict: what is queued is what executes, and a malformed snapshot is refused rather than partially read. | `DeliverySnapshotTest` + `DeliverySnapshotStrictTest` (unit), `ScheduledDeliveryTest` (labels `[P7 item 3/4 / gate 20]`) |
| 21 | A guarded write reports a **fact**, not a boolean, and the scheduled state machine's transition table is asserted in both directions — every legal transition succeeds and every illegal one is refused. | `WriteResultTest` (unit; also scans all guarded-write call sites for boolean reads, label `[7B gate 21]`), `ScheduledStateMachineTest`, `ScheduledExitBranchesTest` |
| 22 | Lifecycle completeness: deactivation, uninstall and removal each **finalise** pending deliveries and leave no job pointing at a dropped table. | `ScheduledLifecycleTest` (label `[7B gate 22]`) |
| 23 | The sweep is fair under sustained inflow: an orphan behind a persisted cursor is still reached, and an open cycle is frozen against new arrivals. | `SweepFairnessTest` (label `[7C gate 23]`) |
| 24 | Any mechanism whose absence makes another guarantee false **arms itself from the path that creates the work** — queueing delayed work arms the maintenance action, with no activation and no `admin_init`. | `MaintenanceArmingTest` (label `[7C gate 24]`) |
| 25 | No fan-out message can be attributed to another message's product, a consolidated rule and a plain one on the same order stay separate, and an insert rule cannot consolidate at all. | `ConsolidationTest` (a running gate-25 row table, printed), `InsertModeTest` (label `[8 item 11 / gate 25]`) |
| 26 | The read boundary **judges** a stored value, never repairs it: an invalid stored `consolidation` delivers nothing and cannot halt other rules through `stop_processing`. | `ConsolidationTest` (label `[8A item 2 / gate 26]`), `ConsolidationTest` (unit), `DeliveryPhaseTest` |
| 27 | The cap fallback is **bounded**: only the first `cap` sections carry the merchant's body, a full-set plural is not resolved once per section, and merged notes are capped by the same constant. | `ConsolidationTest` (label `[8B / gate 27]`), `ConsolidationTest` (unit), `TextBoundsTest` (unit) |
| 28 | Every admin entry point is enumerated with its capability check and its nonce, and the enumeration is asserted **complete** — screens, handlers, the dispatcher, the AJAX endpoint, the menu and the order panel. | `AdminAuthorizationTest` (rows 28a–28l, each its own test method) |
| 29 | The editor–validator contract is **proved over the whole emitted space**, not sampled: every targeting and recipients document the form can emit is accepted by the validator, and the empty form emits `{}`. | `RuleDocumentsTest` (unit; rows 29a–29d, 2048 targeting documents and 8 recipients documents enumerated exhaustively) |
| 30 | No refused write reports success, and every refusal names its field: each validated column refused on update and on insert, the editor's own errors, and a valid save that does succeed. | `AdminSaveOutcomeTest` (rows 30a–30e) |
| 31 | Every rendered value is escaped at its point of output for its own context, asserted against hostile values written past the repository — including values this plugin never wrote (a product title). | `AdminOutputTest` (rows 31a–31e, output table printed), `DeliveryHistoryTest` (rows 31a–31d) |
| 32 | Front-end isolation: no admin script, style, screen, handler or AJAX endpoint is reachable from a front-end request, an unauthenticated visitor who guesses the URL gets nothing, and plugin load and registration output nothing at all. | `AdminIsolationTest` (rows 32a–32f) |
| 33 | Accessibility and translatability: every control has a real `<label for>`, every description is tied to its field with `aria-describedby` — **and, new in Prompt 13C Part K, a description may be an IDREF *list* on a named `role="group"`**, which is how the recipients section carries its mode scope *and* its syntax help without either becoming loose text or being read out once per channel (18 descriptions associated, 3 of them on a named group; was 16 and 1), every unnamed control carries screen-reader text, and every user-facing string in `src/Admin/` is translatable with this text domain — **and, new in Prompt 13C Part G, the placeholder reference still FOLLOWS the content fields in the DOM**, so the two-column editor layout stays a CSS concern and tab order still runs fields → reference rather than making a keyboard user pass ~30 placeholder buttons to reach the subject line. | `AdminOutputTest` (rows 33a–33e) |
| 34 | The history surfaces are **read-only**: rendering either one issues `SELECT` statements and nothing else, asserted on table content rather than on row counts. | `DeliveryHistoryTest::…writes_nothing…`, `OrderPanelTest`, instrumentation in `DeliveryHistoryTestCase` |
| 35 | The history listing filters, orders and pages **in SQL**: the per-page statement count does not grow with the page or with the number of deliveries. | `DeliveryHistoryQueryTest`, `DeliveryHistoryTest::…per_page_statement_count…` |
| 36 | Nothing state-changing happens over `GET`: no rendered control is a send link, every write action is POST-confirmed with an action- and subject-specific nonce, and the confirmation screen shows the **resolved** recipients before the click. | `ManualDeliveryTest` (rows 36a–36d), `TestEmailTest::test_a_test_send_refuses_over_get`, `AdminAuthorizationTest` |
| 37 | **Single execution**: a replayed submission produces exactly one email and one attempt row, a deliberate second confirmation does send again, and the confirmation token is consumed by a single statement. | `ManualDeliveryTest` (rows 37a–37d), `TestEmailTest::test_a_replayed_test_submission_sends_exactly_one_email` and `…two_deliberate_confirmations…` |
| 38 | **Refusal completeness**: every refusal reason is reachable, distinct, and phrased in its own sentence, and a refused action sends nothing and writes nothing. | `ManualRefusalTest`, `TestEmailTest::test_every_test_refusal_names_its_own_reason`, `DeliveryOutcomeNoticeTest`, `PreviewRenderTest` |
| 39 | **No parallel send path**: preview, test, manual and automatic sends share targeting, consolidation, placeholder resolution, the email wrapper, rule-usability and the containment boundary. The shared-component table is asserted, not asserted-about. | `ManualRefusalTest::…shared_component_table`, `…fans_out_through_consolidation`, `…throw_is_contained` |
| 40 | A preview writes nothing, schedules nothing and sends nothing — in **both** modes — and leaves no ledger residue. | `PreviewInertnessTest` (rows 40a–40c), instrumentation in `PreviewTestCase` (`assertPreviewWroteNothing()`, `assertNoLedgerResidue()`) |
| 41 | A preview cannot poison a later real send: an interrupted preview of ours, and a `woocommerce_is_email_preview` leaked by somebody else, both still leave the next **real** delivery recording normally. | `PreviewInertnessTest` (rows 41a–41c) |
| 42 | **Tier 1.** A test email reaches the confirmed address and no other, through every filter between this plugin's choice and `wp_mail()`'s arguments — and the lock is scoped to the test path, identifies **this message** — by recipient, subject and body, **before** any mutable `wp_mail` filter has run — while enforcing the recipient **after** they have all finished, pairs the two by real `wp_mail` nesting depth, and leaves nothing registered afterwards. | `TestEmailTest` rows 42 (recipient sources), 42b (the override), 42c (hostile filters, outcome), 42d (scope: an automatic send still honours them), 42e (lock shape; `phpmailer_init` is the stated boundary), 42f (a nested message keeps its own recipient), **42g** (identity, not position — Prompt 13C), **42h** (a replacement mail callback that never calls `wp_mail()`), **42i** (early identify, late enforce: a callback that rewrites the message AND injects a recipient — Part A2), **42j** (a nested `wp_mail()` from an intermediate filter: the stack, not a flag), **42k** (a mail-callback wrapper that mails first AND a filter that rewrites what it forwards), **42l** (nothing left behind — no callback, no stack entry — including through a throw), **42m** (the declared mail-callback boundary, asserted: a wrapper that rewrites before forwarding declines and records `unmatched`), **42n** (true depth — a swallowed throw in a nested `wp_mail()` cannot unlock the outer message — Part A3), **42o** (identity includes the recipient — an archival copy of this message keeps its own address) |
| 43 | **Tier 1.** A test consumes no automatic identity: the real delivery still claims, sends and records, and `test:` is disjoint from every automatic prefix. | `TestEmailTest::test_a_test_consumes_no_identity_and_the_real_delivery_still_sends`, `…test_identity_is_disjoint_from_every_automatic_prefix` |
| 44 | **The declared PHP range is executed, not asserted.** The integration suite runs on every PHP version `readme.txt`'s `Requires PHP` claims support for — at minimum the floor (8.0) and the ceiling in use. | **Manual, per release round.** The runtimes and the invocation are in `docs/testing.md` § *The ADR-0006 version matrix*; the result table is recorded in that round's `docs/p2-backlog.md` section. **Last executed: Prompt 13C Part N** — 8.0.30, 8.1.34, 8.2.29 and 8.4.23, full unfiltered suite on each, **930 tests / 17 016 assertions / 0 failures / 0 skipped on every one**, 397 intercepts each, re-run because Part M added `src/Email/NativeEmailTargets.php` — a new file that READS FROM DISK — so the "no `src/` file moves" reasoning Parts J–L rested on no longer held. (Previously Prompt 13C Part I — 906 / 16 805 / 0 failures / 0 skipped on every one, each run's gate-5 census reporting the same **396** intercepts as gate 4, which is how *no version ran a subset* is asserted rather than assumed. ⚠ **Both traps are in `docs/testing.md`: the runtimes need `-d mysqli.default_socket=` AND `-d memory_limit=2G`, and a runner must check each run's exit code** — Part I lost one batch to a one-second failure the loop reported as success and another to an out-of-memory death 9–21 minutes in, sequentially against the same tree, the same WordPress 7.1 / WooCommerce 11.0.1 and the same `extonify_wcep_test`; 8.3.6 is covered by **gate 4**, not by this gate, on the system runtime. (Part B ran the same four at 888 / 16 687 with one expected stale-archive failure on each.) ⚠ **Neither gate covers the range alone and neither should be quoted as if it did:** this gate is four runs (8.0/8.1/8.2/8.4) and gate 4 is the fifth (8.3). Together they execute 8.0, 8.1, 8.2, 8.3 and 8.4 — five versions from four runs plus one, which is why Part B declined to round four up to five. Statically, `composer phpcs` holds the tree to `testVersion 8.0-` — but only as widely as the installed PHPCompatibility sniffs actually reach, which is why that is not a substitute for executing this gate; see `docs/testing.md` § *The ADR-0006 version matrix* and the coverage caveat under *Gates whose evidence is a command* below |
| 45 | A failure at the declared floor is **investigated to a cause**, not tolerated or tuned away: every floor failure is either a plugin defect fixed at the floor, or a named external cause (WooCommerce version, permalinks, store settings) with the measurement that proves it. | **Manual, per release round.** Worked example: `docs/p2-backlog.md` § *Gate 45 — the floor failures, investigated rather than tolerated* (Prompt 13A, four failures, four causes). **Last executed: Prompt 13C Part N — by EXECUTION against a corner rebuilt from scratch.** WP 6.6.2 / WC 9.6.0 / PHP 8.0.30 static, HPOS on, own database, own copy of the plugin proved byte-identical with `dist/` INCLUDED: **930 tests / 16 967 assertions / 0 FAILURES / 5 named capability skips**, 33:28. ⚠ **THE FIRST FLOOR RUN OF THIS ROUND FAILED TWO TESTS, AND THAT IS THE GATE WORKING.** Both were Part N's own new tests asserting the BLOCK EMAIL EDITOR's presence as a precondition — WooCommerce 9.6.0 contains zero occurrences of `block_email_editor_enabled` and ships no `templates/emails/block/` directory, so the feature does not exist below 9.9. The classifier itself agreed with the test's re-derivation there (10 of 13 offered, 0 unclassifiable, 0 silent) — ⚠ **and that `0 silent` is an agreement measure, not a guarantee**: the re-derivation uses the same located templates and the same substring rule as the classifier, so it cannot see the classifier's own blind spots. **Four paths return a confident answer that can be wrong and carries no warning** — three `RENDERS`, one `NEVER` — recorded with their reachability in `docs/p2-backlog.md` § *Added after Prompt 13C Part O*; earlier wording here and in Part N implied detection never fails silently, and that is withdrawn. Fixed at the floor **without a skip**, and every suite re-run afterwards. Previously: **Prompt 13C Part I — by EXECUTION against the release tree.** 906 tests / 16 759 assertions / **0 FAILURES** / 5 skipped (each named individually in that round's write-up), 27:24, on a floor corner rebuilt from scratch (WP 6.6.2 / WC 9.6.0 / PHP 8.0.30 static, HPOS on, pretty permalinks, own database) and proved identical to the dev tree before the run. The 5 skips were re-identified **by name** from that run's own `--verbose` output and are the same five Part E recorded. ⚠ **PART H'S FIRST FLOOR RUN WAS DISCARDED AND THE REASON IS WORTH KEEPING:** the corner sync excluded `dist/`, so `ReleaseArchiveTest` had no archive to open and **skipped 25 tests** — the run still reported 906 tests and zero failures, which is what it would have reported had the archive been verified. Sync a corner with the documented exclusions only (`--exclude=poc --exclude=.phpunit.result.cache`), and read the skip COUNT, not just the failure count. Previously: **Prompt 13C Part E — by EXECUTION, not inference.** Part D left this resting on Part C's floor run, whose single failure was the stale archive, and called that an inference rather than an execution. Part E rebuilt the floor corner (WP 6.6.2 / WC 9.6.0 / PHP 8.0.30 static, HPOS on, pretty permalinks), proved the tree byte-identical, and ran the full unfiltered suite against the **rebuilt archive**: **895 tests / 16 671 assertions / 0 FAILURES / 5 skipped**, 25:01. There is now no floor failure to investigate — the one Part C recorded was the stale archive and the rebuild cleared it. The 5 skips are capability skips, not failures, and were re-identified verbatim rather than carried forward: 2 × `HeaderInheritanceTest` (WC 9.6.0 has no configurable reply-to) and 3 × `SendScopeTest` (`point_of_sale` off). `PreviewInertnessTest` did **not** skip |
| 46 | **Plugin Check** is run, with **every** finding reported and dispositioned, and **no escaping, nonce or capability finding left open**. **Baseline moved to 0 errors / 9 warnings in Prompt 13C Part J**, when shipping `composer.json` resolved `missing_composer_json_file` and introduced nothing new; it was 0/10 from 13B through Part I. **Re-run in Part K against the `0b18a6d3…` archive and again in Part L against `2c3c166d…`: 0/9 both times, the same three codes at the same counts in the same five files — none of the files either round changed contributes a finding.** | **Command.** Plugin Check against the built archive in a clean directory; the finding count and each disposition are recorded in `docs/p2-backlog.md` (⚠ a stray file in the install directory changes the count — the caveat is recorded there) |
| 47 | **i18n.** A valid POT is generated; every user-facing string is translatable with the correct text domain; no concatenated sentences; plural and context functions used where they are needed. | **Command** for the POT (`languages/`), plus `AdminOutputTest` row 33d (`src/Admin/`, **435** gettext calls across 24 files — it read 411 across 22 until the two files Prompt 13C added under `src/Admin/` — `ReasonText.php` (Part E) and `Tabs.php` (Part F) — brought their own strings with them, and this row was not updated until Part H; the test prints the live figure, so read it from the run rather than from here) and — **new in Prompt 13C Part E** — `ReasonTextTest` rows 47a–47g for `src/Delivery/`, which is where the delivery reason a merchant reads is produced. ⚠ **STATUS: NOT SATISFIED on the wording above, and deliberately not reworded to fit.** Part E made the **19** coded cancellation sentences translatable at READ time, keyed on the `reason_code` that `DeliveryLogger` already persists in the `snapshot` column — no schema change, contrary to what Part D estimated. What remains is **175 untranslated prose literals across 10 of the 19 files in `src/Delivery/`** — composed and interpolated free text with no stored code — measured in Part F with a fragment-aware scanner; row 47g **pins them per file, in both directions**, so neither a rise nor a silent partial fix passes unnoticed. ⚠ **NO FROZEN COMPONENT IS INVOLVED, AND PART E'S REASON FOR STOPPING IS WITHDRAWN.** Part E recorded the blocker as `Orchestrator::lock_note()` being *"inside the frozen mail lock"*; Part F checked it and it is not — `lock_note()` runs **after** `$email->trigger()` returns, in `send_one()`'s recording section, and `src/Email/Custom_Email.php` needs no change at all because its only four prose literals are already wrapped in `__()`. What actually blocks the gate is scale and a shared note pipeline (`join_notes()`, `notes_for()`, `value_notes()`, `Text::note_value()` feed both the admin `reason` column and `log_error()`), which is ordinary development work scheduled for 1.1. Full inventory and reasoning: `docs/p2-backlog.md` § *Added in Prompt 13C Part E* and § *Added in Prompt 13C Part F* |
| 48 | A clean install smokes end to end **through rendered admin screens** — activate, create a rule through the editor, deliver, read the delivery history — and uninstalls cleanly. | `bin/clean-install-smoke.php`, run against a clean WordPress install with the built archive; procedure and caveats in `docs/testing.md` § *Gate 48*. The uninstall half also has `bin/uninstall-smoke.php` |
| 49 | **No-upsell.** No string in the plugin, the readme, the admin screens or the POT suggests a paid tier, a Pro version, a locked feature or a future upgrade (ADR-0001). The round enumerates **where it looked**. | **Manual, per release round**, over the plugin source, `readme.txt`, the rendered admin screens and the POT — with the places searched enumerated in that round's `docs/p2-backlog.md` section |
| 50 | **New in Prompt 13C.** The `shutdown` sweep constructs nothing in a request that never rendered — so `wp plugin uninstall <slug> --deactivate`, which deletes the plugin directory in the same process, exits 0 instead of fatalling at 255 — and the guard does not disable the sweep that does have work. | `RenderShutdownTest` (rows 50, 50b) asserts the mechanism; `bin/wp-cli-uninstall-check.sh` asserts the outcome (exit 0, no fatal, plugin actually uninstalled) against a throwaway install |

---

## Where the definitions came from

Ten numbers — **1, 2, 3, 4, 8, 11, 44, 46, 47 and 49** — were cited by the release process
and defined nowhere in this repository when this file was written. The Prompt 13B report
declined to assert them for that reason, and the refusal was correct: an unverifiable claim
asserted anyway is worse than an absent one.

**All ten are now committed, and here is where each came from:**

- **44** — recovered in Prompt 13C Part A from Prompt 13's five-version matrix and from
  13C's Part B instruction, both of which treat it as *"the suite is executed on the PHP
  versions the readme claims"*. Marked manual, with the round's result table as evidence.
- **1, 2, 3, 4, 8, 11, 46, 47 and 49** — **supplied verbatim from the prompt series in
  Prompt 13C Part A2** and written into the index above with their evidence. The prompts
  that defined them are still not committed to this repository; what is committed is their
  text, transcribed rather than inferred. Nothing in those rows is a guess, and the round
  that supplied them is recorded in `docs/p2-backlog.md` § *Added in Prompt 13C Part A2*.

(Gates **45** and **48** were never in this list. Both are manual and both were already
auditable: each has a worked example committed here — a backlog section and a script — that
states what the gate asks for in its own words.)

**⚠ A definition is not a pass, and this file does not turn one into the other.** Nine of
the ten have not been asserted in a report since they became checkable, and three of them —
**4** (the full unfiltered integration suite), **46** (Plugin Check) and **47** (i18n) — are
the gates the remaining parts of this round exist to run. What changed is that a reader can
now check a report's claim against a definition instead of against nothing.

> The gap was the process's, not the code's. Nothing here ever said the underlying
> properties were unheld — several of them are covered by tests filed under other numbers.
> It said the *numbering* was unauditable, which was a different and entirely fixable
> problem. It is fixed.

---

## Gates whose evidence is a command, not a test

Thirteen gates cannot be asserted by PHPUnit — or not by PHPUnit alone — because what they
measure is the toolchain, the release artefact or the runtime rather than the code. **Every
one of them has to be run and its result recorded per release round; an unrecorded command
is an unevidenced gate.**

| # | Command | Where the result is recorded |
|---|---|---|
| 1 | `composer validate --strict` | that round's section in `docs/p2-backlog.md` |
| 2 | `composer phpcs` — the error, warning and file counts, plus any NEW suppression with its single-sniff justification | that round's section in `docs/p2-backlog.md` |
| 3 | `composer test:unit` (the no-WordPress half is also asserted by `SuiteIsolationTest`) | that round's section in `docs/p2-backlog.md` |
| 4 | `composer test:integration` — **full, unfiltered, finished**, final totals, single process | that round's section in `docs/p2-backlog.md` |
| 8 | The `poc/` suite | that round's section in `docs/p2-backlog.md` |
| 9 | Read each ADR against current behaviour (partly automated by `ScheduledExitBranchesTest`) | that round's "Contract-consistency gate" heading in `docs/p2-backlog.md` |
| 11 | The storefront half: My Account view-order, thank-you and order-pay, checked for injected content and PHP notices (the preview half is in the suite) | that round's section in `docs/p2-backlog.md` |
| 44 | `vendor/bin/phpunit --testsuite integration` on each PHP runtime | the round's matrix table in `docs/p2-backlog.md` |
| 45 | The floor corner, built per `docs/testing.md` | the round's floor-failure analysis in `docs/p2-backlog.md` |
| 46 | Plugin Check against the built archive **in a clean directory** | the finding count and each disposition in `docs/p2-backlog.md` |
| 47 | POT generation, plus the concatenation / plural / context review | that round's section in `docs/p2-backlog.md` |
| 48 | `php bin/clean-install-smoke.php` against a clean install with the built archive | the round's captured log in `docs/p2-backlog.md` |
| 49 | The no-upsell search over source, `readme.txt`, the admin screens and the POT — **enumerating where it looked** | that round's section in `docs/p2-backlog.md` |
| 50 | `bin/wp-cli-uninstall-check.sh <install>` (the outcome half; the mechanism half is in the suite) | the round's captured output in `docs/p2-backlog.md` |

Two standing caveats, both learned the hard way:

- **Gate 2's PHP-compatibility coverage is only as wide as the installed sniffs.** The
  ruleset asks for `testVersion 8.0-`; a PHPCompatibility that does not know 8.x reports
  clean on constructs it cannot see. `phpcs.xml.dist` and `docs/p2-backlog.md` record which
  versions the installed sniffs actually cover.
- **Gate 46 must run in a clean directory.** A stray file in the install directory changes
  the finding count and reads as a plugin regression.

---

## Label mapping

Gate tests print evidence lines to `STDERR`. Some carry the gate number, some carry only
the prompt-item label they were written under, and the round reports quote both — so the
mapping is recorded here rather than inferred.

| Printed label | Gate |
|---|---|
| `[5B item 2 / gate 14]` | 14 |
| `[5B item 4 / gate 13]` | 13 |
| `[5D item 3 / gate 10]` | 10 |
| `[6A item 1 / gate 17]` | 17 |
| `[6A item 5 / gate 6]` | 6 |
| `[6B item 2 / gate 18]`, `[6B item 3 / gate 18]` | 18 |
| `[6B item 4d / gate 7]` | 7 |
| `[8 / gate 6]` | 6 |
| `[8 item 9 / gate 15]` | 15 |
| `[8 item 11 / gate 25]` | 25 |
| `[8A item 2 / gate 26]` | 26 |
| `[8B / gate 27]` | 27 |
| `[P6 item 9 / gate 6]`, `[P7 / gate 6]` | 6 |
| `[P7 item 1/2/5/9/9b / gate 19]`, `[P7 / gate 19]` | 19 |
| `[P7 item 3 / gate 20]`, `[P7 item 4 / gate 20]` | 20 |
| `[P7 item 11 / gate 17]` | 17 |
| `[P7 item 13 / gate 15]` | 15 |
| `[7B gate 21]` | 21 |
| `[7B gate 22]` | 22 |
| `[7C gate 23]` | 23 |
| `[7C gate 24]` | 24 |
| `[P13A item 3 / gate 18]` | 18 |
| `[P9 gate 29]` | 29 |
| `[P10 gate 35]` | 35 |
| `[P12 gates 42-43]` | 42, 43 |
| `[P13C gate 50]` | 50 |
| `[gate 5]` | 5 |

**Item-only labels are not gates.** `[4b item 2]`, `[4c item 1]`, `[4d item 1]`,
`[5A item …]`, `[5B item 1/3/5]`, `[5C item 1/3]`, `[5D item 1/2]`, `[6A item 2/3/3a/4]`,
`[6B item 4a/4b]`, `[8A item 1]`, `[P6 item 1]`–`[P6 item 8]`, `[P7 item 5/8/10/12]`,
`[P10]`, `[P11]`, `[P13A item 1]` and `[P13A item 4]` are **prompt-item** evidence: they
prove a specific correction landed, and no gate number was ever attached to them. They are
listed here so a reader searching for a gate behind one of them stops searching.
