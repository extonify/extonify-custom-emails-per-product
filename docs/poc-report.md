# POC Report — Prompt 1d (ARCHITECTURE FREEZE: preview slot suppression + send correlation)

Extonify Custom Emails Per Product for WooCommerce · generated 2026-07-26T04:32:01Z

**Architecture frozen.** Supersedes the Prompt 1c report. Reproduce with: `php poc/run-all.php`

---

## Corrections to the Prompt 1c report

The 1c report made three claims that were not true as written. They are corrected
here, and each correction is now backed by an assertion in this run.

| # | 1c claim | Status | What is true now |
|---|----------|--------|------------------|
| 1 | "Preview and customizer … never write a log row, because preview is detected positively and **suppresses registry creation entirely**." | **FALSE as written in 1c** | 1c created a delivery slot for every render and only *recorded* `is_preview` on it. A successful preview's slot reached `awaiting_send`, was never consumed (a preview never fires `woocommerce_email_sent`), and was filed **`unresolved`** at shutdown. **Now:** a preview creates a context frame and **no slot at all**, so preview tokens cannot enter the ledger, reach `awaiting_send`, or appear in `unresolved` — structurally, not by bookkeeping. Asserted for both a successful and an interrupted preview. |
| 2 | "preview auto-recovers: **no preview state remains** (render-scoped, no shutdown needed)" | **INCOMPLETE in 1c** | The 1c assertion only inspected `preview_pending_count()`. An interrupted preview in fact left its **context frame**, its **`open_od` token** and its **`open_footer` token** in place for the rest of the request, so `in_email()` kept answering *yes* while nothing was rendering — the exact invariant ADR-0003 exists to guarantee. **Now:** delivery-side residue is zero the instant the exception unwinds, and frame/token residue is torn down by reconciliation at the next push (plus the shutdown fallback), asserted explicitly rather than inferred. |
| 3 | "**No unresolved contradiction remains** between any ADR and observed WooCommerce behaviour on this runtime." | **FALSE as written in 1c** | Two contradictions were live: ADR-0005 claimed preview writes no record while the code filed preview slots as `unresolved` (above), and finalization selected the *most recent* `awaiting_send` slot, so a second render of the same object completing before `woocommerce_email_sent` stole the real send's attribution. **Now:** both are fixed and proven. What remains is **not** contradiction but a bounded set of **accepted residual risks**, listed below and in `docs/p2-backlog.md`, each requiring third-party code to call WooCommerce internals mid-send. |

## What this pass proves

| Result | Outcome |
|---|---|
| Successful-preview zero-residue | **PASS** — frames 0, slots 0, open order-details tokens 0, open footer tokens 0, pending markers 0, no preview token in unresolved |
| Interrupted-preview zero-residue | **PASS** — slots 0 / markers 0 / no preview token in unresolved **at the instant of interruption**; frames 0 and both open-token counts 0 after reconciliation at the next push, with no manual clearing |
| Same-object interrupted-preview recovery | **PASS** — a real Completed send on the same live singleton, same request, registers a slot and finalizes a delivery record, and is **not** misread as a preview despite core's leaked signal |
| Exact send correlation | **PASS** — the real send finalizes against its own bound token and its own recorded order; the second render mid-send makes no record and is reported unresolved; the rejected most-recent-`awaiting_send` rule provably *would* have picked the wrong render |
| No-send render remains unresolved | **PASS** — both the orphan render and the mid-send second render stay `awaiting_send` and can never be consumed by another send |

**Preview-detection path used by this runtime:** the **core render-time signal**
`woocommerce_is_email_preview` — present on WooCommerce 10.9.4 and preferred, with the
`woocommerce_prepare_email_for_preview` next-render marker retained as fallback *and*
as the authority whenever the core signal is detected leaked (see below).

**Flagged WooCommerce behaviour, new in this pass.**
`EmailPreview::render_preview_email()` calls `clean_up_filters()` **without a
`try`/`finally`**, so an exception thrown mid-preview leaves `woocommerce_is_email_preview`
attached and returning `true` **for the rest of the request** — a subsequent *real* send
reports itself as a preview. Detection therefore demotes the core signal to the
render-scoped marker the moment reconciliation proves it leaked. Both directions are
asserted directly; naively "preferring core's signal" would have silently suppressed a
genuine delivery record.

## Accepted residual risks (deliberately not fixed)

1. **Third-party render from inside the mail callback.** Code hooking `pre_wp_mail`,
   `phpmailer_init`, or replacing `woocommerce_mail_callback` can render the same email
   object *after* binding, creating a newer `awaiting_send` slot. Binding at
   `woocommerce_mail_callback_params` narrows the exposure to exactly this case: the real
   send still finalizes against its own bound token and its own recorded order, and the
   intruding render creates **no** delivery record — it only leaves an extra slot reported
   `unresolved`. **Proven, not argued**, by the exact-send-correlation test in this run.
2. **A nested full send from that same position** is handled by the per-object bind
   **stack**, but is not covered by a test in this run.
3. **Frame-side residue between an interrupted preview and the next push.** PHP offers no
   unwind hook at a call site we do not own, and WooCommerce's preview renderer has no
   `finally`, so teardown at the *instant* of interruption is not achievable. The window is
   bounded by the next push or shutdown; **no delivery state exists inside it**.
4. **Reconciliation is global to preview frames.** A real send triggered by third-party
   code from *within* a live preview render would have the enclosing preview frame
   reconciled early. WooCommerce never nests renders this way.
5. **WooCommerce leaves an orphaned output buffer** after an interrupted preview render
   (`wc_get_template_html()`'s `ob_start()` is never closed). Cosmetic — it is why partial
   email HTML appears in POC-C's transcript; no plugin state is affected.

## Standing scope statements (unchanged)

- Action Scheduler integration is **SIMULATED** (in-memory): uniqueness, identity
  serialisation, cross-request execution, order reloading, and cancellation are **not**
  proven here (POC-A S2b).
- Front-end negative coverage is **TEMPLATE-LEVEL**
  (`woocommerce_order_details_table` + direct hook firing); full endpoint coverage is
  deferred to end-to-end tests.
- The WooCommerce floor is **PROVISIONAL** (8.2, single-combination observation).
- PHP 8.0 compatibility is **SYNTAX-ONLY and matrix-UNTESTED** (ran on PHP 8.3.6).

## Full run transcript

```text
########################################################################
# EXTONIFY CUSTOM EMAILS PER PRODUCT — PROMPT 1 (ADR PACK + POC)
########################################################################

========================================================================
PHASE 0 — PREFLIGHT
========================================================================
  PHP version ........... 8.3.6
  WordPress version ..... 7.0.2
  WooCommerce version ... 10.9.4
  PHP target matrix ..... 8.0–8.4 (running 8.3)
  Disposable WC runtime . live local WP+WC via wp-load.php (same approach as the Address Book project; no wp-env needed)

========================================================================
CHECK — WooCommerce active & wc_create_order()
========================================================================
  [PASS] WooCommerce class present  (10.9.4)
  [PASS] wc_create_order() callable
  [PASS] Action Scheduler present (as_schedule_single_action)
  [PASS] created a product fixture  (product_id=2463)
  [PASS] created an order fixture with an item  (order_id=2464)

========================================================================
CHECK — mail capture (pre_wp_mail)
========================================================================
  [PASS] wp_mail short-circuited to true (no real delivery)
  [PASS] mail capture recorded the message
  [PASS] captured subject matches  (POC preflight subject)
  [PASS] captured recipient matches  (sink@example.test)

========================================================================
CHECK — refund tracking + verify-after-delete cleanup
========================================================================
  [PASS] refund fixture created and tracked  (ok)
  [cleanup] verify-after-delete OK: removed & confirmed gone — 2 orders, 1 refunds, 1 products, 0 tables
  [PASS] cleanup verify-after-delete reported OK (no leaks)  (leaks=)
  [PASS] refunded order truly gone  (order_id=2465)
  [PASS] refund truly gone  (refund_id=2466)
  [PASS] probe product truly gone  (product_id=2463)
  [PASS] register_shutdown_function(wcep_poc_cleanup) is wired

-- assertions: 15 passed, 0 failed --

PREFLIGHT-1D PASSED

========================================================================
PHASE 1 — ADR AMENDMENTS
========================================================================
  - docs/adr/ADR-0001.md  — free distribution complete standalone; commercial only via a superseding ADR (1a)
  - docs/adr/ADR-0002.md  — reset at BOTH ends + email_type is a store setting only (1b)
  - docs/adr/ADR-0003.md  — 1d: an INTERRUPTED render must never leave an active frame — push reconciliation + shutdown
  - docs/adr/ADR-0004.md  — tombstone bound = ORDER lifetime, many tombstones/order (1b)
  - docs/adr/ADR-0005.md  — 1d: preview creates NO delivery slot + finalization binds the EXACT token at mail_callback_params
  - docs/adr/ADR-0006.md  — WC floor is PROVISIONAL until matrix-tested (1a)
  - docs/adr/ADR-0007.md  — unchanged
  - docs/adr/ADR-0008.md  — status events preceding line items — single deferred re-evaluation (1a, new)

AMENDED IN THIS PASS (Prompt 1d):
  - docs/adr/ADR-0003.md
  - docs/adr/ADR-0005.md
  - docs/poc-report.md   (three overstated 1c claims corrected)
  - docs/p2-backlog.md   (accepted residual risks + deferrals)

ADR PATCH 1D COMMITTED


========================================================================
POC-A — TRIGGER MATRIX + DELIVERY IDENTITY (ADR-0004 amended, ADR-0008)
========================================================================
  [PASS] tombstone UNIQUE(order,rule,mode,identity) created
  [PASS] separate purgeable detail table created
  [PASS] S1 checkout-draft → 0 claims
  [PASS] S2a transition fired on a zero-item order
  [PASS] S2a NO immediate claim on the itemless targeted status
  [PASS] S2a exactly ONE deferred re-evaluation scheduled
  [PASS] S2b deferred re-eval claimed
  [PASS] S2b exactly ONE claim under the ORIGINAL identity
  [PASS] S2b second deferral suppressed (single-deferral cap)
  NOTE: the deferral queue here is an IN-MEMORY SIMULATION. Action Scheduler
        uniqueness, identity serialisation, cross-request execution, order
        reloading, and cancellation are NOT proven here — deferred to the
        production foundation phase.
  [PASS] S3 → 1 claim, identity status:completed
  [PASS] S4 → 2 distinct: status:processing + status:completed
  [PASS] S5 re-entry → 1 row for status:processing
  [PASS] S5 second processing suppressed (suppressed_count=1)
  [PASS] S6 refund object created
  [PASS] S6 partial refund → 1 claim refund:{id}
  [PASS] S7 double-fire → 1 row survives UNIQUE
  [PASS] S7 second fire suppressed
  [PASS] S8 control: fresh claim → claimed AND exactly 1 mail sent
  [PASS] S8 provoked failure → result FAILED
  [PASS] S8 FAIL-CLOSED: ZERO mail sent on claim failure
  [PASS] S8 no phantom row in real tombstone from the failed claim
  [PASS] S9 setup: 1 claim + 1 detail row
  [PASS] S9 privacy erasure nulled personal fields
  [PASS] S9 retention purge removed detail rows
  [PASS] S9 tombstone SURVIVED purge + erasure
  [PASS] S9 re-fire after purge is SUPPRESSED (not re-sent)
  [PASS] S9 no new detail row from the suppressed re-fire
  [PASS] S10 first pending>processing → transition:pending>processing CLAIMED
  [PASS] S10 two DISTINCT claims on one event (status + transition)
  [PASS] S10 the two claims belong to DIFFERENT rules (101 status, 111 transition)
  [PASS] S10 exact transition repeated → transition:pending>processing SUPPRESSED
  [PASS] S10 still exactly ONE transition row after the repeat

========================================================================
POC-A MATRIX  (event → identity → result)
========================================================================
  SCENARIO                       | EVENT                          | TRIGGER IDENTITY               | RESULT
  ----------------------------------------------------------------------------------------------------------------------
  S1 checkout-draft              | status pending>checkout-draft  | —                            | no-claim (status not targeted)
  S2a true-direct-terminal       | status pending>completed (0 items) | status:completed               | deferred:scheduled
  S2b deferred-recovery (sim)    | deferred re-eval               | status:completed               | claimed
  S3 attach-then-transition      | status pending>completed       | status:completed               | claimed
  S4 pending>processing>completed | status pending>processing      | status:processing              | claimed
  S4 pending>processing>completed | status processing>completed    | status:completed               | claimed
  S5 processing>on-hold>processing | status pending>processing      | status:processing              | claimed
  S5 processing>on-hold>processing | status processing>on-hold      | —                            | no-claim (status not targeted)
  S5 processing>on-hold>processing | status on-hold>processing      | status:processing              | suppressed
  S6 partial-refund              | status pending>completed       | status:completed               | claimed
  S6 partial-refund              | refund #2474                   | refund:2474                    | claimed
  S7 double-fire                 | status pending>processing      | status:processing              | claimed
  S7 double-fire                 | status pending>processing      | status:processing              | suppressed
  S8 fail-closed                 | claim vs missing table         | status:completed               | failed
  S9 tombstone-survival          | status pending>completed       | status:completed               | claimed
  S9 tombstone-survival          | re-fire after purge            | status:completed               | suppressed
  S10 transitions                | status pending>processing      | status:processing              | claimed
  S10 transitions                | status pending>processing      | transition:pending>processing  | claimed
  S10 transitions                | status processing>pending      | —                            | no-claim (status not targeted)
  S10 transitions                | status pending>processing      | status:processing              | suppressed
  S10 transitions                | status pending>processing      | transition:pending>processing  | suppressed
  [cleanup] verify-after-delete OK: removed & confirmed gone — 10 orders, 1 refunds, 1 products, 2 tables
  [PASS] cleanup verify-after-delete OK — no fixture leak  (leaks=)

-- assertions: 33 passed, 0 failed --

POC-A OK


========================================================================
POC-B — SINGLE FIXED WC_Email (ADR-0002 amended, Prompt 1b)
========================================================================
  [PASS] class present in live WC()->mailer()->get_emails() (registered before init)
  [PASS] live instance is our class with the fixed id
  [PASS] init_form_fields exposes ONLY [enabled, email_type]  (keys=email_type,enabled)
  [PASS] send returned success, runtime subject applied
  [PASS] woocommerce_email_sent fired: boolean + fixed id

========================================================================
KILL SWITCH — enabled / disabled / re-enabled
========================================================================
  [PASS] enabled → mail captured
  [PASS] disabled → ZERO mail (returns false)
  [PASS] re-enabled → mail captured

========================================================================
SINGLETON ISOLATION — A then B on the LIVE object
========================================================================
  [PASS] A delivered to A
  [PASS] state reset after A (all fields empty/null)
  [PASS] B to B only; body BBB not AAA; no A cc/bcc leaked

========================================================================
CONTAMINATION — external mutation between deliveries
========================================================================
  [PASS] contamination: subject is clean, not DIRTY
  [PASS] contamination: body is CLEAN-BODY, no DIRTY-CONTENT
  [PASS] contamination: heading clean, no DIRTY-HEADING
  [PASS] contamination: NO dirty Cc/Bcc in headers (entry-reset + explicit set)

========================================================================
EMAIL_TYPE — store setting only (saved-setting update/restore)
========================================================================
  [PASS] HTML setting → wrapped in store template (template_container)
  [PASS] plain setting → NO HTML wrapper, content present
  [PASS] saved email_type setting is UNCHANGED at the end
  [PASS] cleanup verify-after-delete OK — no fixture leak  (leaks=)

-- assertions: 19 passed, 0 failed --

POC-B OK


========================================================================
POC-C — RENDER-SLOT LEDGER + PREVIEW/CORRELATION (ADR-0003/0005 amended, Prompt 1d)
========================================================================

========================================================================
POSITIVE — customer processing email (HTML + PLAIN), live object
========================================================================
  [PASS] HTML: all 5 markers present, matched-item only
  [PASS] PLAIN: all 5 markers present, matched-item only
  [PASS] two real sends finalized 2 records
  [PASS] zero slots remain after the positive sends

========================================================================
NEGATIVE ISOLATION
========================================================================
  [PASS] rule emits NOTHING in Admin New Order
  [PASS] rule emits NOTHING in Customer Completed
  [PASS] rule emits NOTHING in processing email for a different order lacking the product
  [PASS] no slots remain after negative isolation

========================================================================
NESTED — same live singleton, two matched orders
========================================================================
  [PASS] nested: OUTER frame survived inner (one frame, the outer)
  [PASS] nested: OUTER + INNER each finalized exactly once
  [PASS] nested: no cross-finalization + outer token unchanged
  [PASS] nested: zero slots remain

========================================================================
BLOCKER 1 · unmatched nested send
========================================================================
  [PASS] unmatched inner created NO delivery record
  [PASS] unmatched inner did NOT consume the outer slot (outer slot still present after inner)
  [PASS] outer rule finalized ONCE, only on the outer send
  [PASS] outer finalized against the OUTER order id
  [PASS] ledger empty afterwards

========================================================================
BLOCKER 1 · inner fails, outer succeeds
========================================================================
  [PASS] inner finalized as FAILED
  [PASS] outer finalized as SENT (NOT poisoned by inner failure)
  [PASS] ledger empty afterwards

========================================================================
NEGATIVE — front-end order views (TEMPLATE-LEVEL)
========================================================================
  (scope: woocommerce_order_details_table + direct hook firing; full endpoint
   coverage — view-order, thank-you, order-pay — deferred to end-to-end tests)
  [PASS] error capture LIVE (canary) — gate non-vacuous
  [PASS] front-end: no markers, no throw, no our-code diagnostics

========================================================================
BLOCKER 1 (1d) — preview slot suppression + guaranteed frame teardown
========================================================================
  PREVIEW-DETECTION-PATH: core render-time signal — woocommerce_is_email_preview IS exposed by this WooCommerce
   (the fallback marker stays wired regardless: it is what makes detection immune to
    core's leaked preview filter after an interrupted preview — see below)
  [PASS] ledger + frames empty before preview (no reset needed)
  [PASS] preview produced non-empty HTML + positively detected in the render frame
  [PASS] preview detected via the CORE signal on this runtime  (source=core core=true marker=true)
  [PASS] successful preview: FRAME count = 0  (frames=0)
  [PASS] successful preview: DELIVERY SLOT count = 0 (no slot was ever created)
  [PASS] successful preview: open ORDER-DETAILS token count = 0
  [PASS] successful preview: open FOOTER token count = 0
  [PASS] successful preview: preview PENDING MARKER count = 0
  [PASS] successful preview: unresolved contains NO preview token
  [PASS] successful preview created ZERO delivery records
  [PASS] successful preview sent ZERO mail
  [PASS] real send after a successful preview finalized normally
<!DOCTYPE html>
<html lang="en-US">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta content="width=device-width, initial-scale=1.0" name="viewport">
		<title>Extonify</title>
	</head>
	<body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">
		<table width="100%" id="outer_wrapper" role="presentation">
			<tr>
				<td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
				<td width="600">
					<div id="wrapper" dir="ltr">
						<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" id="inner_wrapper" role="presentation">
							<tr>
								<td align="center" valign="top">
																			<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
											<tr>
												<td id="template_header_image">
													<p class="email-logo-text"><a href="http://localhost/extonify" style="color: inherit; text-decoration: none;" target="_blank">Extonify</a></p>												</td>
											</tr>
										</table>
																		<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" role="presentation">
										<tr>
											<td align="center" valign="top">
												<!-- Header -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" role="presentation">
													<tr>
														<td id="header_wrapper">
															<h1>Good things are heading your way!</h1>
														</td>
													</tr>
												</table>
												<!-- End Header -->
											</td>
										</tr>
										<tr>
											<td align="center" valign="top">
												<!-- Body -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body" role="presentation">
													<tr>
														<td valign="top" id="body_content">
															<!-- Content -->
															<table border="0" cellpadding="20" cellspacing="0" width="100%" role="presentation">
																<tr>
																	<td valign="top" id="body_content_inner_cell">
																		<div id="body_content_inner">

<div class="email-introduction"><p>
Hi John,</p>
<p>We have finished processing your order.</p>
	<p>Here’s a reminder of what you’ve ordered:</p>
</div>
  [PASS] interrupted preview threw and was identified as a preview
  [PASS] interrupted preview: DELIVERY SLOT count = 0 (instant — no slot ever created)  (slots=0)
  [PASS] interrupted preview: preview PENDING MARKER count = 0 (instant — consumed at push)
  [PASS] interrupted preview: unresolved contains NO preview token (instant)
  [window] between the interruption and the next push, the interrupted preview
           still holds: frames=1 open_od=1 open_footer=1
           (torn down automatically at the next push — asserted below)
  [PASS] interrupted preview: core preview signal LEAKED true (WC clean_up_filters is not in a finally)  (this is why detection cannot trust the core signal blindly)
  [PASS] stale preview frame was RECONCILED at the next push (no manual clearing)
  [PASS] the real send was NOT misread as a preview despite the leaked core signal
  [PASS] interrupted preview: FRAME count = 0  (frames=0)
  [PASS] interrupted preview: open ORDER-DETAILS token count = 0
  [PASS] interrupted preview: open FOOTER token count = 0
  [PASS] interrupted preview: unresolved STILL contains no preview token
  [PASS] same-object recovery: real Completed email registered a slot + finalized a delivery record
  [PASS] same-object recovery: ledger empty again, no preview state remains

========================================================================
BLOCKER 2 (1d) — exact send correlation (woocommerce_mail_callback_params)
========================================================================
  [PASS] the second render really happened mid-send and produced its own token  (real=rt_16 second=rt_17 slots=2)
  [PASS] the send was BOUND at woocommerce_mail_callback_params, before the second render
  [PASS] the real email finalized against its OWN bound token
  [PASS] the real email finalized against its OWN recorded order
  [PASS] NO LIFO selection occurred: the rejected "most recent awaiting_send" rule WOULD have picked the second render
  [PASS] the second render created NO delivery record (exactly one record for this order)
  [PASS] the second render is reported UNRESOLVED (still awaiting_send, never consumable)
  [PASS] outgoing mail was NOT mutated: exactly one message, carrying no render token

========================================================================
BLOCKER 1 · render without send → orphan slot, unresolved at shutdown
========================================================================
  [PASS] render-without-send produced a token
  [PASS] real send finalized ITS OWN slot
  [PASS] orphan slot was NOT consumed by the real send
  [PASS] orphan slot reported UNRESOLVED at shutdown

========================================================================
HARDENING · foreign-token rejection (tuple-validated pop/cleanup)
========================================================================
  [PASS] foreign token removed NO frame (wrong email object rejected)
  [PASS] valid token + wrong order removed NO frame
  [PASS] both mismatches were RECORDED
  [PASS] the correct frame was left intact during the render
  [cleanup] verify-after-delete OK: removed & confirmed gone — 12 orders, 0 refunds, 2 products, 0 tables
  [PASS] cleanup verify-after-delete OK — no fixture leak  (leaks=)

-- assertions: 64 passed, 0 failed --

POC-C OK

  [shutdown] unresolved records: rt_17(awaiting_send,order=2490) rt_18(awaiting_send,order=2491)
  [shutdown] preview tokens issued: 2 · preview tokens present in unresolved: NONE
  [shutdown] stale preview frames reconciled during the run: 1 · frames left: 0


========================================================================
POC-D — BATCH FINALIZATION + SINGLETON SAFETY (ADR-0005 amended, Prompt 1c)
========================================================================

========================================================================
BATCH — 3 orders via ONE live singleton
========================================================================
  [PASS] exactly THREE records finalized  (records=3)
  [PASS] records carry the THREE correct, distinct order IDs
  [PASS] each record has a DISTINCT render token
  [PASS] double-registration exercised (6 attempts for 3 orders)  (attempts=6)
  [PASS] despite 2 registrations/render, each record holds exactly ONE rule (set dedup)
  [PASS] zero slots remain after the batch

========================================================================
NESTED — same live singleton, inner order mid-render
========================================================================
  [PASS] nested: OUTER frame survived inner (one frame during outer)
  [PASS] nested: OUTER order finalized
  [PASS] nested: INNER order finalized
  [PASS] nested: finalized against DISTINCT recorded orders
  [PASS] nested: zero slots remain

========================================================================
TYPE-GUARD — non-WC_Order email object bails
========================================================================
  [PASS] type-guard: did NOT throw
  [PASS] type-guard: returned null (bailed)
  [PASS] type-guard: nothing finalized

========================================================================
POC-D RECORDS
========================================================================
  ORDER      | RULES    | STATUS | RENDER TOKEN
  --------------------------------------------------------
  2494       | 404      | sent   | rt_1
  2495       | 404      | sent   | rt_3
  2496       | 404      | sent   | rt_5
  2498       | 404      | sent   | rt_8
  2497       | 404      | sent   | rt_7
  [cleanup] verify-after-delete OK: removed & confirmed gone — 5 orders, 0 refunds, 1 products, 0 tables
  [PASS] cleanup verify-after-delete OK — no fixture leak  (leaks=)

-- assertions: 15 passed, 0 failed --

POC-D OK


========================================================================
POC-E — WOOCOMMERCE FLOOR DETECTION
========================================================================
  Runtime WooCommerce version ........... 10.9.4
  EmailPreview class present ............ YES  (Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview)
  EmailPreview::render() present ........ YES
  woocommerce_prepare_email_for_preview . available (filter @since 9.6.0)
  woocommerce_email_sent (hard dep) ..... required (@since 5.6.0)
  'email_improvements' feature enabled .. YES
  'block_email_editor' feature enabled .. no
  HPOS helper present / HPOS enabled .... YES / YES

========================================================================
RECOMMENDED WC FLOOR (PROVISIONAL — ADR-0006 amended)
========================================================================
  Recommended minimum WooCommerce: 8.2  ** PROVISIONAL **

  PROVISIONAL — observed on a SINGLE combination only:
    PHP 8.3.6 / WP 7.0.2 / WC 10.9.4 / HPOS ON / email_improvements ON / block_email_editor OFF
  UNTESTED (must be matrix-tested before the floor is asserted as tested):
    - PHP 8.0, 8.1, 8.2, and 8.4 — syntax is statically held to PHP 8.0, but
      ACTUAL PHP 8.0 RUNTIME compatibility is matrix-UNTESTED; everything here
      executed on PHP 8.3.6 ONLY.
    - legacy post-based order storage (HPOS OFF)
    - email_improvements OFF
    - block_email_editor ON
  Open option (deferred to the release matrix phase): align the final floor
  with the sibling Extonify Address Book plugin to reduce suite-wide test burden.

  Reasoning:
   - HARD dependency: woocommerce_email_sent (@since 5.6.0) drives ADR-0005
     insert-mode finalization. 5.6.0 is far below the recommended floor.
   - All injection/trigger hooks used (woocommerce_email_classes,
     woocommerce_email_order_details [4-arg], before/after_order_table,
     order_meta, customer_details, woocommerce_order_item_meta_end,
     woocommerce_email_footer, order_status_changed, order_fully/partially_
     refunded) are stable and predate 8.2.
   - 8.2 is the HPOS GA baseline: OrderUtil + uniform order CRUD across HPOS
     and legacy storage, which the delivery/claim layer relies on.
   - This floors BELOW the preview surface (9.6.0) deliberately, to maximise
     install reach, with the fallback plan below.

  Capability-detection fallback plan (floor 8.2 < preview 9.6.0):
   - Register the email-preview integration ONLY when the surface exists:
       if ( class_exists( EmailPreview::class ) ) { add_filter(
         'woocommerce_prepare_email_for_preview', … ); }
   - Treat 'email_improvements' and 'block_email_editor' as runtime-detected
     via FeaturesUtil::feature_is_enabled(); never hard-require them.
   - Below 9.6.0: no preview integration is registered; all separate-mode
     sending and insert-mode injection continue to work unchanged.
   - Guard every optional surface behind class_exists/method_exists/
     function_exists so a lower floor degrades features, never fatals.

========================================================================
POC-E ASSERTIONS
========================================================================
  [PASS] WooCommerce version detected  (10.9.4)
  [PASS] preview surface detection works (EmailPreview present on 10.9.4)
  [PASS] preview render entry point present
  [PASS] hard-dependency hook floor (email_sent 5.6.0) is BELOW recommended floor 8.2
  [PASS] recommended floor 8.2 is BELOW preview availability 9.6.0 → capability-detection fallback applies
  [PASS] current runtime (10.9.4) is at/above recommended floor
  [PASS] floor is recorded as PROVISIONAL, observed on a single runtime combination (ADR-0006 amended)
  [PASS] single observed combination: HPOS on, email_improvements on, block editor off  (hpos=1 improvements=1 block=0)
  [PASS] PHP 8.0 RUNTIME compatibility recorded as matrix-UNTESTED (executed on 8.3.6 only)  (running 8.3.6)

-- assertions: 9 passed, 0 failed --

POC-E OK

POC-1D PASSED

========================================================================
PHASE 4 — FREEZE REPORT
========================================================================

[ ENVIRONMENT ]
  PHP version ........... 8.3.6
  WordPress version ..... 7.0.2
  WooCommerce version ... 10.9.4
  Runtime: live local WP+WC via wp-load.php (disposable-runtime approach; no wp-env)
  HPOS: enabled · email_improvements feature: enabled · block_email_editor: disabled

[ AMENDED ADR LIST — one-line summary of each change ]
  - ADR-0001.md  — free distribution complete standalone; commercial only via a superseding ADR (1a)
  - ADR-0002.md  — reset at BOTH ends + email_type is a store setting only (1b)
  - ADR-0003.md  — 1d: an INTERRUPTED render must never leave an active frame — push reconciliation + shutdown
  - ADR-0004.md  — tombstone bound = ORDER lifetime, many tombstones/order (1b)
  - ADR-0005.md  — 1d: preview creates NO delivery slot + finalization binds the EXACT token at mail_callback_params
  - ADR-0006.md  — WC floor is PROVISIONAL until matrix-tested (1a)
  - ADR-0007.md  — unchanged
  - ADR-0008.md  — status events preceding line items — single deferred re-evaluation (1a, new)

[ ASSERTION PROGRESSION — Prompt 1 → 1a → 1b → 1c → 1d ]
  SUITE    | P1     | P1a    | P1b    | P1c    | P1d   
  -----------------------------------------------------
  POC-A    | 10     | 26     | 33     | 33     | 33    
  POC-B    | 14     | 21     | 19     | 19     | 19    
  POC-C    | 28     | 33     | 43     | 40     | 64    
  POC-D    | 8      | 10     | 15     | 15     | 15    
  POC-E    | 6      | 8      | 9      | 9      | 9     
  TOTAL    | 66     | 98     | 119    | 116    | 140   
  (plus Phase 0 preflight: 9 → 15 → 15 → 15 → 15 assertions)

[ PREVIEW-DETECTION PATH USED BY THIS RUNTIME ]
  PREVIEW-DETECTION-PATH: core render-time signal — woocommerce_is_email_preview IS exposed by this WooCommerce
  corroborated by       : [PASS] preview detected via the CORE signal on this runtime  (source=core core=true marker=true)
  why the marker stays  : [PASS] interrupted preview: core preview signal LEAKED true (WC clean_up_filters is not in a finally)  (this is why detection cannot trust the core signal blindly)

[ KEY 1d RESULTS — the five results this pass had to produce ]
  successful-preview zero-residue  : [PASS] successful preview: DELIVERY SLOT count = 0 (no slot was ever created)
                                   : [PASS] successful preview: FRAME count = 0  (frames=0)
                                   : [PASS] successful preview: open FOOTER token count = 0
  interrupted-preview zero-residue : [PASS] interrupted preview: DELIVERY SLOT count = 0 (instant — no slot ever created)  (slots=0)
                                   : [PASS] interrupted preview: FRAME count = 0  (frames=0)
                                   : [PASS] interrupted preview: open FOOTER token count = 0
                                   : [PASS] interrupted preview: unresolved STILL contains no preview token
  same-object recovery             : [PASS] same-object recovery: real Completed email registered a slot + finalized a delivery record
                                   : [PASS] the real send was NOT misread as a preview despite the leaked core signal
  exact send correlation           : [PASS] the real email finalized against its OWN bound token
                                   : [PASS] NO LIFO selection occurred: the rejected "most recent awaiting_send" rule WOULD have picked the second render
                                   : [PASS] the second render created NO delivery record (exactly one record for this order)
  no-send render unresolved        : [PASS] the second render is reported UNRESOLVED (still awaiting_send, never consumable)
                                   : [PASS] orphan slot reported UNRESOLVED at shutdown
                                   : [PASS] orphan slot was NOT consumed by the real send

[ KEY 1c RESULTS — still green ]
  unmatched nested send : [PASS] unmatched inner did NOT consume the outer slot (outer slot still present after inner)
  inner-fails/outer-sent: [PASS] outer finalized as SENT (NOT poisoned by inner failure)
  foreign-token reject  : [PASS] both mismatches were RECORDED
  slot recorded order   : [PASS] nested: finalized against DISTINCT recorded orders

[ ACCEPTED RESIDUAL RISKS — deliberately NOT fixed, recorded in docs/p2-backlog.md ]
  1. THIRD-PARTY RENDER FROM INSIDE THE MAIL CALLBACK. Code hooking pre_wp_mail,
     phpmailer_init or replacing woocommerce_mail_callback can render the same email
     object AFTER binding, creating a newer awaiting_send slot. Binding at
     woocommerce_mail_callback_params narrows exposure to exactly this case: the real
     send still finalizes against its own bound token and its own recorded order, and
     the intruding render makes NO delivery record — it only leaves an extra slot
     reported unresolved. PROVEN by the exact-send-correlation test above.
  2. A NESTED FULL SEND from that same position is handled by the per-object bind
     STACK, but is not covered by a test in this run.
  3. FRAME-SIDE RESIDUE BETWEEN AN INTERRUPTED PREVIEW AND THE NEXT PUSH. PHP has no
     unwind hook at a call site we do not own and WC's preview renderer has no
     finally, so teardown at the INSTANT of interruption is not achievable. The
     window is bounded by the next push or shutdown; NO delivery state exists in it
     (slot count is 0 the instant the exception unwinds — asserted above).
  4. RECONCILIATION IS GLOBAL TO PREVIEW FRAMES. A real send triggered by third-party
     code from WITHIN a live preview render would have the enclosing preview frame
     reconciled early. WooCommerce never nests renders this way.
  5. WC LEAVES AN ORPHANED OUTPUT BUFFER after an interrupted preview render
     (wc_get_template_html's ob_start is never closed). Cosmetic here — it is why
     partial email HTML appears in POC-C's output; no plugin state is affected.

[ STANDING SCOPE STATEMENTS (unchanged, explicit) ]
  - Action Scheduler integration is SIMULATED (in-memory): uniqueness, identity
    serialisation, cross-request execution, order reloading, and cancellation are
    NOT proven here (POC-A S2b) — see docs/p2-backlog.md.
  - Front-end negative coverage is TEMPLATE-LEVEL (woocommerce_order_details_table
    + direct hook firing); full endpoint coverage is deferred to end-to-end tests.
  - The WooCommerce floor is PROVISIONAL (8.2, single-combination observation).
  - PHP 8.0 compatibility is SYNTAX-ONLY and matrix-UNTESTED (ran on PHP 8.3.6).

[ FLAGGED WOOCOMMERCE BEHAVIOUR (documented, not silently adapted) ]
  (a) Verified WC 10.9.4: a nested SAME-singleton render leaves $email->object on
      the INNER order (both email_sent events report it). Finalization therefore
      NEVER reads $email->object for the order — it uses the slot's recorded order
      id. The render-SLOT state machine additionally makes an unmatched/failed inner
      send, and a render that never sends, unable to corrupt another render's
      record. ADR-0005 states this as a hard rule.
  (b) NEW IN 1d — Verified WC 10.9.4: EmailPreview::render_preview_email() calls
      clean_up_filters() WITHOUT a try/finally, so an interrupted preview leaves the
      core woocommerce_is_email_preview filter attached and returning TRUE for the
      REST OF THE REQUEST — a later REAL send then reports itself as a preview.
      Detection prefers core's signal but demotes it to the render-scoped marker the
      moment reconciliation proves it leaked. Asserted directly, both directions.

========================================================================
ARCHITECTURE FROZEN
========================================================================
  - ADR-0001: Naming and trademark-safe identity
  - ADR-0002: Single fixed, runtime-configured `WC_Email`
  - ADR-0003: Email-render context for shared order-item hooks
  - ADR-0004: Delivery identity and duplicate claims
  - ADR-0005: Bounded decision logging
  - ADR-0006: Test-before-advertising compatibility
  - ADR-0007: Scheduled delivery snapshot and rule mutation
  - ADR-0008: Status events preceding line items
  Every claim above is backed by an assertion in THIS run. The two 1c blockers are
  fixed and proven; the remaining exposures are listed under ACCEPTED RESIDUAL RISKS
  and require third-party code to call WooCommerce internals mid-send. Deferrals are
  recorded in docs/p2-backlog.md.

PROMPT 1D COMPLETE
```
