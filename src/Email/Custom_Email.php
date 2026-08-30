<?php
/**
 * The single fixed WC_Email, runtime-configured per delivery (ADR-0002, ADR-0012 §5).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Email;

use Extonify\WCEP\Delivery\HeaderGuard;
use Extonify\WCEP\Domain\Text;

defined( 'ABSPATH' ) || exit;

/**
 * ONE email class serves every rule.
 *
 * ADR-0002 prohibits registering a `WC_Email` per rule: WooCommerce builds its
 * email list once per request and renders a settings row for each entry, so
 * per-rule registration would make the WooCommerce → Settings → Emails screen
 * grow without bound and would tie a merchant's rules to WooCommerce's own
 * settings storage. Instead there is exactly one entry — a global kill switch —
 * and every per-delivery value is set at runtime.
 *
 * THE COST OF THAT CHOICE IS STATE BLEED, AND IT IS PAID HERE. One object
 * serves every delivery in a request, so a field left set by delivery A is a
 * field delivery B inherits. Two rules make that safe, and both are absolute:
 *
 *   1. `trigger()` sets EVERY runtime field explicitly on entry — never
 *      conditionally on whether the caller supplied it, because "not supplied"
 *      must mean "empty", not "whatever the last delivery used";
 *   2. `trigger()` SAVES the state it found on entry and RESTORES it in a
 *      `finally` — it does not clear (ADR-0012 §11a).
 *
 * RULE 2 SAYS RESTORE, NOT RESET, AND THE DIFFERENCE IS A LOST EMAIL.
 * Clearing isolates CONSECUTIVE deliveries correctly and destroys NESTED ones.
 * `trigger()` is re-entrant in practice: `is_enabled()` applies
 * `woocommerce_email_enabled_{id}` — a third-party filter point — BEFORE the
 * recipient, content and headers are evaluated. A filter there that changes
 * another order's status runs an inner delivery through this same object to
 * completion. Under a clearing `finally` the inner call handed the outer one
 * back an EMPTY object: the outer then saw `recipient === ''`, returned
 * `no_recipient`, and its already-claimed identity was permanently recorded as
 * skipped. A valid customer email, silently never sent and unretryable.
 *
 * Capture-and-restore keeps both guarantees at once. At the top level the
 * captured frame is empty, so the object still ends clean — the isolation
 * guarantee is preserved, not traded away.
 *
 * `email_type` is deliberately NOT runtime state. It is a store setting: the
 * merchant chooses HTML or plain text once, and no rule overrides it.
 *
 * The class is registered through `woocommerce_email_classes` BEFORE the mailer
 * initialises, so it is present in the live `WC()->mailer()->get_emails()` — a
 * later registration silently produces an object nothing can reach.
 */
class Custom_Email extends \WC_Email {

	/**
	 * `trigger()` outcome: WooCommerce reported the message as sent.
	 */
	const SENT = 'sent';

	/**
	 * `trigger()` outcome: the message was handed to the mailer and the mailer
	 * reported it as NOT sent. A transport failure.
	 */
	const NOT_SENT = 'not_sent';

	/**
	 * `trigger()` outcome: `woocommerce_email_enabled_{id}` returned false with
	 * this delivery's order attached (ADR-0012 §5a).
	 *
	 * DISTINCT FROM `NOT_SENT` ON PURPOSE. Nothing failed — a third party looked
	 * at this specific order, rule and recipient list and declined. Reporting it
	 * as a transport failure blamed the mailer for a decision the mailer never
	 * saw, and hid a deliberate refusal among genuine SMTP breakage.
	 */
	const DISABLED_BY_FILTER = 'disabled_by_filter';

	/**
	 * `trigger()` outcome: no direct recipient survived, so there was nobody to
	 * send to. The orchestrator resolves recipients first and normally catches
	 * this earlier; this is the last line of defence.
	 */
	const NO_RECIPIENT = 'no_recipient';

	/**
	 * Lock outcome: this send carried no recipient lock at all.
	 *
	 * Every path but the test send (ADR-0020 §4b).
	 */
	const LOCK_NONE = '';

	/**
	 * Lock outcome: the lock recognised THIS message at the first statement of
	 * `wp_mail()` and enforced the recipient on the arguments the mailer acted on, after
	 * every other `wp_mail` callback had finished with them. The full guarantee held.
	 */
	const LOCK_APPLIED = 'applied';

	/**
	 * Lock outcome: a locked send never reached `woocommerce_mail_callback_params`,
	 * so the lock was never armed.
	 *
	 * The parent did not get as far as the mail callback — normally because
	 * something threw first, in which case the throw is the caller's real news.
	 */
	const LOCK_UNARMED = 'unarmed';

	/**
	 * Lock outcome: the lock was armed and `wp_mail()` WAS NEVER ENTERED AT ALL.
	 *
	 * ⚠ NORMAL, NOT A FAILURE, ON THE STORES WHERE IT HAPPENS. A
	 * `woocommerce_mail_callback` replacement that hands the message to SMTP or to an
	 * API never calls `wp_mail()`, so there is nothing at that step to lock and the
	 * `woocommerce_mail_callback_params` lock — which DID run, since that is where the
	 * arming happens — is the last word on what the custom sender received.
	 *
	 * It is recorded rather than discarded because it is the one distinguishable signal
	 * that this plugin's final guarantee did not reach the transport.
	 *
	 * ⚠ IT USED TO MEAN TWO THINGS AT ONCE, AND PART A2 SPLIT THEM. Until then it also
	 * covered "`wp_mail()` ran and no call carried this message", which was the ordinary
	 * outcome whenever a third party rewrote the subject or the body. Those are different
	 * facts about a store and they now get different answers: this one, and
	 * self::LOCK_UNMATCHED.
	 */
	const LOCK_UNFIRED = 'unfired';

	/**
	 * Lock outcome: the lock was armed, `wp_mail()` WAS entered, and no invocation
	 * carried this message.
	 *
	 * ⚠ RARE AFTER PART A2, AND THAT IS THE WHOLE POINT OF SEPARATING IT. Identification
	 * happens at `PHP_INT_MIN`, before any mutable `wp_mail` filter has run, so the
	 * ordinary cause of a miss — a footer injector, a subject prefixer — no longer
	 * produces one. What is left is a message that was ALREADY different by the time
	 * `wp_mail()` was entered: a `woocommerce_mail_callback` replacement that alters the
	 * message before forwarding it, or a `wp_mail` callback registered at `PHP_INT_MIN`
	 * BEFORE ours.
	 *
	 * ⚠ THE FIRST OF THOSE IS A DECLARED BOUNDARY, NOT A GAP (Part A3, item 3). Past a
	 * mail callback that rewrites what it forwards there is nothing left to identify the
	 * message by, so this plugin does not enforce the recipient at `wp_mail` — the same
	 * kind of statement ADR-0020 §4b makes about `phpmailer_init`. The parameters handed
	 * to that callback are locked; what it does with them is the transport's behaviour.
	 * ⚠ DO NOT READ THIS OUTCOME AS "THE MESSAGE IS STILL SAFE": `wp_mail`'s own filters
	 * run after the parameters, so an injected Bcc can survive here. That is what the
	 * declaration says, and it is why this value is written into the delivery record
	 * rather than treated as a formality. See self::arm_wp_mail_lock().
	 */
	const LOCK_UNMATCHED = 'unmatched';

	/**
	 * Priority the Cc/Bcc injector registers at.
	 *
	 * PHP_INT_MIN so it runs FIRST among `woocommerce_email_headers` callbacks:
	 * every third-party callback must see the complete header block, including
	 * this plugin's Cc and Bcc, rather than a set the plugin appends afterwards
	 * where nothing can inspect or filter it.
	 */
	const HEADER_INJECT_PRIORITY = PHP_INT_MIN;

	/**
	 * The three GLOBAL filters `WC_Email::send()` registers for the duration of
	 * one send, mapped to the method it binds: hook => method.
	 *
	 * They are `wp_mail`-wide, not email-specific, so while they are attached
	 * they govern EVERY message the request sends — including WordPress's own
	 * password resets and other plugins' notifications.
	 */
	const MAIL_FILTERS = array(
		'wp_mail_from'         => 'get_from_address',
		'wp_mail_from_name'    => 'get_from_name',
		'wp_mail_content_type' => 'get_content_type',
	);

	/**
	 * EVERY per-delivery field, with the value that means "not set".
	 *
	 * ONE LIST, USED BY ALL THREE OPERATIONS — capture, apply and restore. It
	 * used to be three hand-maintained lists in three methods, which is the
	 * shape where a newly added field gets into two of them and leaks through
	 * the third. Adding a field here is the whole change.
	 *
	 * `object` is `WC_Email`'s own property; the rest are declared below.
	 */
	const RUNTIME_FIELDS = array(
		'recipient'              => '',
		'cc'                     => '',
		'bcc'                    => '',
		// ADR-0020 §4b, gate 42. Empty on every path but the test send; see
		// self::$locked_recipient.
		'locked_recipient'       => '',
		'delivery_subject'       => '',
		'delivery_heading'       => '',
		'delivery_content'       => '',
		// The SAME body, flattened and resolved for a text/plain destination
		// (ADR-0014 §9). A field of its own rather than something derived at
		// render time, because the two formats escape their placeholder values
		// differently and deriving one from the other would have to un-escape.
		'delivery_content_plain' => '',
		'matched_items'          => array(),
		'delivery_id'            => 0,
		'object'                 => null,
		// INHERITED FROM WC_Email, and the only parent property WooCommerce
		// mutates mid-delivery (ADR-0012 §11c). `get_content()` sets it true and
		// `handle_multipart()` — on `phpmailer_init` — sets it false again, so a
		// nested delivery's multipart handler would otherwise switch the OUTER
		// send off and cost it its plain-text alternative.
		'sending'                => false,
	);

	/**
	 * Carbon-copy addresses for the current delivery, comma-joined.
	 *
	 * @var string
	 */
	public $cc = '';

	/**
	 * Blind-carbon-copy addresses for the current delivery, comma-joined.
	 *
	 * @var string
	 */
	public $bcc = '';

	/**
	 * The ONE address this delivery may reach, or '' when nothing is locked.
	 *
	 * ⚠ SET BY THE TEST SEND AND BY NOTHING ELSE (ADR-0020 §4b, gate 42). Every other
	 * path leaves it empty, and while it is empty this class behaves exactly as it did
	 * before the field existed — `woocommerce_email_recipient_{id}`,
	 * `woocommerce_email_headers` and `woocommerce_email_cc_recipient_{id}` all keep
	 * the last word, because a merchant's own recipient customisation is legitimate on
	 * a real send.
	 *
	 * ⚠ WHY AN OVERRIDE AT THE SOURCE WAS NOT ENOUGH, WHICH IS THE WHOLE POINT.
	 * `TestDelivery` already builds the recipient itself and never reads the rule's
	 * document — that part was right. But `trigger()` passes
	 * `WC_Email::get_recipient()`, which applies
	 * `woocommerce_email_recipient_{$this->id}`, and Cc/Bcc are emitted through
	 * `woocommerce_email_headers`. "Send a copy of every WooCommerce email to the
	 * manager" is an ordinary plugin category, not pathological behaviour — and such a
	 * plugin would add its address to a TEST send, mailing a real person a test message
	 * about a real customer's order. Gate 42 asks for the OUTCOME, not for the
	 * override, so the enforcement has to sit after every filter has run.
	 *
	 * @var string
	 */
	public $locked_recipient = '';

	/**
	 * Subject line for the current delivery.
	 *
	 * Named distinctly from WC_Email's `$subject` SETTING, which holds a
	 * merchant-configured default this class deliberately does not have.
	 *
	 * @var string
	 */
	public $delivery_subject = '';

	/**
	 * Heading for the current delivery.
	 *
	 * @var string
	 */
	public $delivery_heading = '';

	/**
	 * Body for the current delivery: `wp_kses_post`-filtered at the storage
	 * boundary, with its placeholders already resolved for an HTML destination
	 * (ADR-0014 §9).
	 *
	 * @var string
	 */
	public $delivery_content = '';

	/**
	 * Body for the current delivery, already flattened to text and resolved for
	 * a `text/plain` destination (ADR-0014 §9).
	 *
	 * Empty means "not supplied": self::get_content_plain() then flattens
	 * self::$delivery_content itself, which is what every caller that predates
	 * placeholders does.
	 *
	 * @var string
	 */
	public $delivery_content_plain = '';

	/**
	 * Matched item records for the current delivery (ADR-0011 §5). Carried for
	 * templates and diagnostics; not rendered in this prompt.
	 *
	 * @var array[]
	 */
	public $matched_items = array();

	/**
	 * The tombstone id this delivery was claimed under, for correlation.
	 *
	 * @var int
	 */
	public $delivery_id = 0;

	/**
	 * How the recipient lock ended on the LAST send this object performed.
	 *
	 * ⚠ DELIBERATELY NOT IN self::RUNTIME_FIELDS, AND THAT IS NOT AN OVERSIGHT. It is
	 * an OUTCOME of a send rather than an INPUT to one, and `trigger()` restores the
	 * captured frame in a `finally` that runs AFTER the send — so a runtime field would
	 * be wiped before the caller could ever read it.
	 *
	 * Last write wins, which is correct under nesting rather than in spite of it: an
	 * inner send completes — and writes — before the outer send's `finally` writes its
	 * own, so each caller reads the value its own `trigger()` produced.
	 *
	 * @var string One of the five self::LOCK_* constants.
	 */
	private $lock_outcome = self::LOCK_NONE;

	/**
	 * How many identification entries the LAST send had to discard (Part A2, corrected
	 * in Part A3).
	 *
	 * ⚠ ZERO ON EVERY WELL-BEHAVED SEND, AND IT IS A DIAGNOSTIC RATHER THAN AN OUTCOME.
	 * The lock writes one entry per `wp_mail()` invocation at `PHP_INT_MIN` and consumes
	 * it at `PHP_INT_MAX`, so nothing survives the send. A non-zero value counts
	 * invocations that were entered and never completed — a `wp_mail` filter threw and a
	 * third party CAUGHT it around its own nested `wp_mail()` call — each of which leaves
	 * its answer behind.
	 *
	 * ⚠ THE PREVIOUS VERSION OF THIS COMMENT CLAIMED THE MISALIGNMENT "FAILS CLOSED",
	 * AND THAT WAS WRONG IN THE DIRECTION THAT MATTERS (Part A3, item 1, Tier 1). Under
	 * the push/pop pairing it replaced, a stranded NO_MATCH sat on top of the stack and
	 * the OUTER invocation popped it — so the lock declined **on our own message**.
	 * Declining is safe outward: a stranger's mail is never mislabelled. It is not safe
	 * inward, because gate 42's invariant is *the confirmed address and no other*, and
	 * declining leaves whatever the earlier filters did — an injected Bcc among them.
	 * It failed OPEN, on the one message the lock exists to protect.
	 *
	 * The entries are now keyed by real `wp_mail` nesting depth (self::wp_mail_depth()),
	 * so a stranded entry sits at a depth nothing will read again and this counter is a
	 * pure diagnostic rather than a symptom. Recorded so "nothing was left behind" stays
	 * observable instead of being a claim.
	 *
	 * @var int
	 */
	private $lock_stack_residue = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = EmailIdentity::EMAIL_ID;
		$this->title          = __( 'Custom emails per product', 'extonify-custom-emails-per-product' );
		$this->description    = __( 'Global on/off switch for every custom product email. Subject, content and recipients are set on each rule, not here.', 'extonify-custom-emails-per-product' );
		$this->customer_email = true;

		// No template files of this plugin's own: the body is rendered inside
		// WooCommerce's header/footer templates so the store's branding applies
		// (ADR-0012 §6).
		$this->template_html  = '';
		$this->template_plain = '';

		parent::__construct();

		/*
		 * START IN THE DECLARED EMPTY STATE (ADR-0012 §11a).
		 *
		 * `WC_Email` declares `$recipient` and `$object` with no default, so a
		 * fresh object holds `null` for both while `RUNTIME_FIELDS` says the
		 * empty recipient is `''`. That mattered the moment `trigger()` began
		 * CAPTURING the frame it found instead of clearing it: the very first
		 * delivery captured `null`, restored `null`, and the object ended a
		 * request in a state its own contract says is impossible. Normalising
		 * here makes the frame well-formed from birth, which is the invariant
		 * capture-and-restore rests on.
		 */
		$this->reset_runtime_state();
	}

	/**
	 * Settings fields — ONLY the kill switch and the email type (ADR-0002).
	 *
	 * Subject, heading, content, recipients and CC/BCC are rule-owned. Exposing
	 * them here would create a second place to configure the same thing, and the
	 * two would disagree.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'    => array(
				'title'   => __( 'Enable/Disable', 'extonify-custom-emails-per-product' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable custom product emails (global switch)', 'extonify-custom-emails-per-product' ),
				'default' => 'yes',
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'extonify-custom-emails-per-product' ),
				'type'        => 'select',
				'description' => __( 'Applies to every custom product email.', 'extonify-custom-emails-per-product' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Clear every per-delivery field.
	 *
	 * Does NOT touch `email_type`, `enabled` or anything else loaded from the
	 * saved settings — those are store configuration, not delivery state.
	 *
	 * KEPT AS THE "RESTORE TO EMPTY" CASE, not as `trigger()`'s exit path.
	 * `trigger()` restores the frame it captured (ADR-0012 §11a); clearing there
	 * would destroy a nested caller's state.
	 *
	 * @return void
	 */
	public function reset_runtime_state(): void {
		$this->restore_runtime_state( array() );
	}

	/**
	 * Snapshot every per-delivery field exactly as it stands.
	 *
	 * @return array Field name => current value.
	 */
	public function capture_runtime_state(): array {
		$state = array();

		foreach ( self::RUNTIME_FIELDS as $field => $unset ) {
			$state[ $field ] = $this->{$field};
		}

		return $state;
	}

	/**
	 * Put a captured frame back.
	 *
	 * A field missing from `$state` is restored to its "not set" value rather
	 * than left alone, so a partial frame cannot leave a stale value behind.
	 *
	 * @param array $state Frame from self::capture_runtime_state().
	 * @return void
	 */
	public function restore_runtime_state( array $state ): void {
		foreach ( self::RUNTIME_FIELDS as $field => $unset ) {
			$this->{$field} = array_key_exists( $field, $state ) ? $state[ $field ] : $unset;
		}
	}

	/**
	 * Set every per-delivery field from one delivery's arguments.
	 *
	 * EVERY field, unconditionally. "Not supplied" must mean "empty", never
	 * "whatever the last delivery used" — which is what a conditional
	 * `isset()` assignment would produce on a shared object.
	 *
	 * @param array $args Delivery arguments; see self::trigger().
	 * @return void
	 */
	private function apply_runtime_state( array $args ): void {
		$this->recipient        = HeaderGuard::strip( (string) ( $args['recipient'] ?? '' ) );
		$this->cc               = HeaderGuard::strip( (string) ( $args['cc'] ?? '' ) );
		$this->bcc              = HeaderGuard::strip( (string) ( $args['bcc'] ?? '' ) );
		$this->locked_recipient = HeaderGuard::strip( (string) ( $args['locked_recipient'] ?? '' ) );
		$this->delivery_subject = HeaderGuard::strip( (string) ( $args['subject'] ?? '' ) );
		$this->delivery_heading = (string) ( $args['heading'] ?? '' );
		$this->delivery_content = (string) ( $args['content'] ?? '' );

		$this->delivery_content_plain = (string) ( $args['content_plain'] ?? '' );

		$this->matched_items = (array) ( $args['matched_items'] ?? array() );
		$this->delivery_id   = (int) ( $args['delivery_id'] ?? 0 );
		$this->object        = $args['object'] ?? null;

		// NOT a caller-supplied field: WooCommerce owns it. A new delivery
		// simply starts "not sending", and `WC_Email::get_content()` sets it.
		$this->sending = false;
	}

	/**
	 * Send, with WooCommerce's THREE GLOBAL MAIL FILTERS guaranteed to end up
	 * exactly as they started (ADR-0012 §11d).
	 *
	 * ⚠ FLAGGED WOOCOMMERCE BEHAVIOUR, verified in the bundled WC 10.9.4.
	 * `WC_Email::send()` has NO `try`/`finally`:
	 *
	 *     add_filter( 'wp_mail_from',         [ $this, 'get_from_address' ] );
	 *     add_filter( 'wp_mail_from_name',    [ $this, 'get_from_name' ] );
	 *     add_filter( 'wp_mail_content_type', [ $this, 'get_content_type' ] );
	 *     $message = apply_filters( 'woocommerce_mail_content', ... );   // can throw
	 *     $return  = (bool) $mail_callback( ...$params );                // can throw
	 *     remove_filter( ... ); remove_filter( ... ); remove_filter( ... );
	 *
	 * So anything that throws between the registrations and the removals leaves
	 * all three attached **for the rest of the request**. These are `wp_mail`
	 * hooks, not email-specific ones, so every later message in that request —
	 * a WordPress password reset, another plugin's notification, WooCommerce's
	 * own emails — would go out with THIS store's From address, From name and
	 * `text/html` content type.
	 *
	 * IT IS THIS PLUGIN'S PROBLEM IN PARTICULAR because ADR-0012 §3 catches
	 * `\Throwable` and lets the request CONTINUE. A core email would have died
	 * with the exception; this plugin survives it and keeps serving the request
	 * with three foreign filters attached. That is why the wrapper lives here
	 * rather than being left to upstream.
	 *
	 * NOT A REIMPLEMENTATION. The parent's body is called unchanged; only the
	 * registry state around it is preserved, so future WooCommerce changes to
	 * the send path are inherited.
	 *
	 * The same mechanism covers NESTING, which needs no separate code: an inner
	 * send records "already registered" on entry and puts the registration back
	 * on exit, instead of removing the outer send's.
	 *
	 * `clear_alt_body_field()` is deliberately NOT replicated — see
	 * self::capture_mail_filter_state().
	 *
	 * @param string $to          Recipient.
	 * @param string $subject     Subject.
	 * @param string $message     Body.
	 * @param string $headers     Headers.
	 * @param array  $attachments Attachments.
	 * @return bool
	 */
	public function send( $to, $subject, $message, $headers, $attachments ) {
		$frame = $this->capture_mail_filter_state();

		/*
		 * ⚠ THE RECIPIENT LOCK, APPLIED AT THE LAST POINT THIS PLUGIN OWNS
		 * (ADR-0020 §4b, gate 42).
		 *
		 * `$to` arriving here has already been through `WC_Email::get_recipient()` and
		 * therefore through `woocommerce_email_recipient_{$this->id}`; `$headers` has
		 * already been through `woocommerce_email_headers` and through this class's own
		 * Cc/Bcc injection, including `woocommerce_email_cc_recipient_{$this->id}` where
		 * WooCommerce offers it. So this is the first place where "what will actually be
		 * mailed" is known, and it is the only place a guarantee about it can be made.
		 *
		 * ⚠ THE STRIP IS NOT AN OPTIMISATION. A test send resolves no `cc` and no `bcc`,
		 * so on an unfiltered store there is nothing to remove — the strip exists for the
		 * store where a third party ADDED one, which is the entire threat.
		 */
		$lock = (string) $this->locked_recipient;

		if ( '' !== $lock ) {
			$to      = $lock;
			$headers = self::without_copy_headers( $headers );
		}

		/*
		 * ⚠ AND ONCE MORE INSIDE THE PARENT, BECAUSE THE PARENT HAS ONE MORE FILTER.
		 * `WC_Email::send()` applies `woocommerce_mail_callback_params` to
		 * `array( $to, $subject, $message, $headers, $attachments )` immediately before
		 * calling the mailer — after everything above. At `PHP_INT_MAX` this callback
		 * runs last, so the arguments the mailer receives are the locked ones whatever
		 * else is registered.
		 *
		 * ⚠ IT IS GUARDED ON `$email !== $this`, WHICH IS LOAD-BEARING RATHER THAN
		 * DEFENSIVE. That filter is global: a third-party callback that sends ANOTHER
		 * WooCommerce email from inside this send would otherwise have its recipient
		 * rewritten to this test's address. The parent passes the sending `WC_Email`
		 * as the second argument, so the frame that installed the lock is the only one
		 * it applies to.
		 *
		 * A FRESH CLOSURE PER CALL, for the reason `get_headers()` gives: an
		 * object-method callable has one identity on this shared instance, so a nested
		 * send's `remove_filter()` would remove the outer send's registration.
		 *
		 * ⚠ AND IT IS STILL NOT THE LAST WORD, WHICH IS THE PROMPT 13B FIX. Everything
		 * above runs BEFORE `wp_mail()` is entered, and `wp_mail()`'s very first
		 * statement is `apply_filters( 'wp_mail', compact( 'to', 'subject', 'message',
		 * 'headers', 'attachments' ) )` — a filter that can replace `to` and re-add
		 * `Cc:`/`Bcc:` header lines after every guarantee this class had made. The
		 * pair of callbacks below closes that step; self::arm_wp_mail_lock() explains
		 * why it identifies THIS MESSAGE at `PHP_INT_MIN` and enforces the recipient at
		 * `PHP_INT_MAX`, rather than doing both at either end.
		 *
		 * ⚠ THE FINGERPRINT IS TAKEN FROM THE ARRAY THIS CALLBACK RETURNS, WHICH IS THE
		 * ONE THE MAILER IS HANDED. Arming happens AFTER `enforce_recipient_lock()` for
		 * that reason alone — the two touch different elements, so the order changes no
		 * behaviour, but taking the fingerprint from anything other than the outgoing
		 * arguments would be fingerprinting a message nobody sends.
		 */
		$enforce = null;

		/*
		 * THE LOCK'S WHOLE STATE FOR THIS SEND, IN ONE PLACE AND OWNED BY THIS FRAME.
		 *
		 * ⚠ A LOCAL, NOT A PROPERTY, AND THAT IS THE ISOLATION THIS CLASS IS BUILT ON.
		 * One `Custom_Email` object serves every delivery in the request (ADR-0002), so a
		 * property here would be shared by a send nested inside another send — and the
		 * two would interleave their pushes and pops on ONE stack, each popping the
		 * other's entry. A frame-local structure, captured by reference into this
		 * send's own callbacks, gives every arming its own stack for free.
		 *
		 * @var array{identify:callable|null, enforce:callable|null,
		 *            matches:array<int,bool>, armed:bool, seen:bool, fired:bool,
		 *            residue:int}
		 */
		$shot = array(
			'identify' => null,
			'enforce'  => null,
			// One entry per `wp_mail()` invocation in progress, KEYED BY ITS OWN NESTING
			// DEPTH rather than stacked — see self::wp_mail_depth().
			'matches'  => array(),
			'armed'    => false,
			'seen'     => false,
			'fired'    => false,
			'residue'  => 0,
		);

		if ( '' !== $lock ) {
			$enforce = function ( $params, $email = null ) use ( $lock, &$shot ) {
				// The filter is global; only ever touch this frame's own send.
				if ( $email !== $this ) {
					return $params;
				}

				$params = self::enforce_recipient_lock( $params, $lock );

				self::arm_wp_mail_lock( $shot, $lock, is_array( $params ) ? $params : array() );

				return $params;
			};

			add_filter( 'woocommerce_mail_callback_params', $enforce, PHP_INT_MAX, 2 );
		}

		try {
			return (bool) parent::send( $to, $subject, $message, $headers, $attachments );
		} finally {
			/*
			 * ⚠ REMOVED HERE AND ONLY HERE, BOTH CALLBACKS AND THEIR BOOKKEEPING WITH
			 * THEM. Since Part A3 the pair does NOT retire itself on a match — identity,
			 * not a spent shot, is what keeps it off other people's mail — so this is the
			 * single place the registration ends, and it is unconditional. It runs on
			 * every exit: the send that locked its message, the send whose message never
			 * reached `wp_mail()` at all (an SMTP replacement), and the send something
			 * threw out of. Left attached, they would be live for the next unrelated
			 * message the request sends: identity makes that harmless in practice, but a
			 * filter left registered past the scope that owns it is a defect on its own
			 * terms.
			 *
			 * ⚠ AND IT RUNS ON A THROW, WHICH IS WHY THE ENTRIES ARE DISCARDED HERE
			 * RATHER THAN BY THE CALLBACKS ALONE. A `wp_mail` filter that throws never
			 * reaches `PHP_INT_MAX`, so its entry would otherwise outlive the invocation
			 * that wrote it. It is counted on the way out, not dropped.
			 */
			self::disarm_wp_mail_lock( $shot );

			if ( null !== $enforce ) {
				remove_filter( 'woocommerce_mail_callback_params', $enforce, PHP_INT_MAX );
			}

			/*
			 * ⚠ RECORDED RATHER THAN DISCARDED, INCLUDING — ESPECIALLY — THE BORING
			 * ANSWER. "Armed and `wp_mail()` never entered" is the SMTP-sender case and
			 * is normal, but it is the one distinguishable signal that this plugin's last
			 * guarantee did not reach the transport, and a merchant reading a delivery
			 * record is entitled to know which of the answers they got. `Orchestrator`
			 * turns anything but self::LOCK_APPLIED into a delivery note.
			 */
			$this->lock_outcome       = self::lock_outcome_for( $lock, $shot );
			$this->lock_stack_residue = (int) $shot['residue'];

			$this->restore_mail_filter_state( $frame );
		}
	}

	/**
	 * How the recipient lock ended on the last send this object performed.
	 *
	 * @return string One of the five self::LOCK_* constants.
	 */
	public function lock_outcome(): string {
		return $this->lock_outcome;
	}

	/**
	 * How many `wp_mail` stack entries the last send had to discard.
	 *
	 * Zero on every well-behaved send; see self::$lock_stack_residue for the one
	 * shape that produces more.
	 *
	 * @return int
	 */
	public function lock_stack_residue(): int {
		return $this->lock_stack_residue;
	}

	/**
	 * Classify one send's lock outcome from the three facts `send()` observed.
	 *
	 * ⚠ FOUR ANSWERS FOR A LOCKED SEND, NOT THREE, AND THE FOURTH IS PART A2's. `seen`
	 * separates "`wp_mail()` was never entered" — the SMTP-sender case, normal — from
	 * "`wp_mail()` ran and nothing in it carried this message", which says something
	 * quite different about the store. Collapsing them was how a rewritten subject and a
	 * replacement transport came to look identical in the delivery record.
	 *
	 * @param string $lock The address that was locked, or '' for an unlocked send.
	 * @param array  $shot The lock's frame-local state; see self::send().
	 * @return string One of the five self::LOCK_* constants.
	 */
	private static function lock_outcome_for( string $lock, array $shot ): string {
		if ( '' === $lock ) {
			return self::LOCK_NONE;
		}

		if ( empty( $shot['armed'] ) ) {
			return self::LOCK_UNARMED;
		}

		if ( ! empty( $shot['fired'] ) ) {
			return self::LOCK_APPLIED;
		}

		return empty( $shot['seen'] ) ? self::LOCK_UNFIRED : self::LOCK_UNMATCHED;
	}

	/**
	 * Arm the `wp_mail` lock for THIS MESSAGE: identify early, enforce late.
	 *
	 * ⚠ TWO QUESTIONS AT TWO MOMENTS, AND THE PREVIOUS DESIGN ASKED BOTH AT THE WRONG
	 * ONE (PART A2, ITEM 1, TIER 1). The fingerprint was computed from the parameters
	 * handed to the mail callback — correct — but COMPARED inside a single callback at
	 * `PHP_INT_MAX`, which runs after every other `wp_mail` filter:
	 *
	 *     wp_mail() entered with the intended message
	 *       priority 10   third party prefixes the subject / appends a footer
	 *                     third party appends a customer to `to`, adds a Bcc
	 *       PHP_INT_MAX   our callback: the fingerprint no longer matches → declines
	 *       → the message leaves with the injected recipients
	 *
	 * The source used to classify that as Tier 3 — "a footer injector or a subject
	 * prefixer makes the fingerprint miss, so the lock declines and nothing is worse than
	 * before". **In isolation that is true; it is not the shape stores ship.**
	 * Subject/body rewriting and recipient injection are both ordinary `wp_mail`
	 * behaviours and they frequently arrive in the SAME plugin — brand every outgoing
	 * message, and Bcc the archive. When they coincide, the branding is what defeats the
	 * identification and the injection is what the identification existed to stop: a
	 * confirmed test send reaches an address the merchant did not choose. Tier 1, on a
	 * mainline shape.
	 *
	 * ⚠ THE INVARIANT:
	 *
	 *     Identify the intended invocation BEFORE mutable `wp_mail` filters run;
	 *     enforce the recipient AFTER they have finished.
	 *
	 * So there are two scoped callbacks rather than one:
	 *
	 *     PHP_INT_MIN   arguments are still pristine → compare the fingerprint
	 *                   → record MATCH or NO_MATCH AT THIS INVOCATION'S OWN DEPTH
	 *       … third-party filters mutate subject, body and recipients, and may
	 *         themselves call wp_mail() (which records and consumes its own,
	 *         one level deeper) …
	 *     PHP_INT_MAX   read the entry at this invocation's own depth
	 *                   MATCH    → to = confirmed address, strip every Cc and Bcc
	 *                   NO_MATCH → return untouched
	 *
	 * ⚠ KEYED BY DEPTH, NOT STACKED, AND PART A3 IS WHY THE DISTINCTION IS TIER 1. A
	 * filter at any priority between the two may call `wp_mail()` itself, and the nested
	 * invocation completes before the outer chain resumes — so LIFO push/pop LOOKS like
	 * depth-keying and is not. It is only equivalent while every invocation reaches BOTH
	 * ends. A third party that wraps its own nested `wp_mail()` in `try`/`catch` —
	 * ordinary defensive code — breaks it: the nested call records at `PHP_INT_MIN`,
	 * something throws before `PHP_INT_MAX`, the catch swallows it, and its answer is
	 * left on top for the OUTER invocation to pop. Our own message then went out
	 * unlocked, with whatever the intervening filters had added.
	 *
	 * self::wp_mail_depth() reads the depth from the PHP call stack, which unwinds
	 * however a call ends. This is `RenderLedger`'s rule — depth-keyed, not
	 * identity-keyed — on a different surface, and the correction is the same one that
	 * file has already had to make: a stack is only a depth key while nothing skips
	 * a level.
	 *
	 * ⚠ WHY A SCOPED PAIR AND NOT A REGISTRATION HELD OPEN AND UNCONDITIONAL.
	 * `wp_mail` carries NO identifying argument — just the message's own fields — so
	 * unlike `woocommerce_mail_callback_params` there is no `$email` to guard on. A lock
	 * that rewrote every `wp_mail()` call made inside `WC_Email::send()` would redirect
	 * somebody else's mail to this test's address, and third-party callbacks on
	 * `woocommerce_mail_content` routinely send other messages (ADR-0002, ADR-0013 §5a).
	 * That is the same tier of harm this lock exists to prevent, pointing the other way.
	 * The fingerprint is what keeps the scope on our own message — and since Part A3 it
	 * is the ONLY thing that does, because the pair no longer retires itself on a match.
	 *
	 * ⚠ THE SCOPE USED TO BE POSITIONAL, AND POSITION IS NOT IDENTITY (PROMPT 13C,
	 * ITEM 1, TIER 1). Until that change the shot fired on WHATEVER `wp_mail` CALL CAME
	 * NEXT, on the reasoning that `WC_Email::send()` invokes the mail callback on the
	 * statement after the params filter returns:
	 *
	 *     $mail_callback        = apply_filters( 'woocommerce_mail_callback', 'wp_mail', $this );
	 *     $mail_callback_params = apply_filters( 'woocommerce_mail_callback_params', [...], $this );
	 *     $return               = (bool) call_user_func_array( $mail_callback, $mail_callback_params );
	 *
	 * The first line is the hole. `$mail_callback` IS REPLACEABLE, and a replacement that
	 * sends a message of its own through `wp_mail()` **before** forwarding ours consumed
	 * the shot on that message: an unrelated email redirected to the test address, and
	 * the real test message left unguarded for the rest of its journey. The fingerprint
	 * closed that, and it still does — a non-matching invocation passes through UNTOUCHED
	 * with the lock still armed.
	 *
	 * ⚠ THE FINGERPRINT IS STILL TAKEN FROM THE OUTGOING PARAMETERS, AND COMPARED
	 * AGAINST THE PRISTINE ARGUMENTS. `sha256` over the RECIPIENT, the SUBJECT and the
	 * BODY as they stand in the parameters the mailer is handed, against the same three
	 * fields at the first statement of `wp_mail()`, before any callback has touched them.
	 * Between those two moments only a `woocommerce_mail_callback` replacement can
	 * intervene, and one that rewrites the message before forwarding is the case
	 * self::LOCK_UNMATCHED names.
	 *
	 * ⚠ THE RECIPIENT JOINED THE TRIPLE IN PART A3 AND IT CLOSED A TIER 1 DEFECT. `to`
	 * is the field third parties rewrite, which is why it was left out — but at
	 * `PHP_INT_MIN` it has not been rewritten yet, and it is the only field that tells
	 * our message apart from a DELIBERATE COPY of it. An archival wrapper that mails a
	 * byte-identical copy elsewhere before forwarding had that copy matched and its
	 * recipient rewritten to the merchant. See self::message_fingerprint().
	 *
	 * ⚠ NO MARKER IS EMBEDDED IN THE MESSAGE, AND THAT WAS DECIDED BEFORE (Prompt 5B,
	 * for the render token). A fingerprint READS the outgoing message and mutates
	 * nothing, so there is no marker a stripping failure could ship to a real recipient.
	 * That objection is why "add a header and match on it" is not the design here either.
	 *
	 * ⚠ THE DECLARED BOUNDARY AT THE MAIL CALLBACK (PART A3, ITEM 3; ADR-0020 §4b).
	 * When a replacement `woocommerce_mail_callback` ALTERS the message before forwarding
	 * it, this plugin can no longer identify its own message and DOES NOT enforce the
	 * recipient at `wp_mail`. The parameters handed to that callback are locked; what it
	 * does with them is the transport's behaviour, exactly as `phpmailer_init` is below.
	 *
	 * ⚠ THIS IS A DECLARATION, NOT AN INFERENCE, AND THE INFERENCE IT REPLACES WAS
	 * INCOMPLETE. The old reasoning was "the params lock already delivered merchant-only
	 * to the callback, so the message is still safe". **The parameters are not the last
	 * mutation point**: `wp_mail`'s own filters run after them, so a Bcc injected at
	 * priority 10 survives on a message the lock could not identify. There is nothing
	 * left to reason from — the content is no longer the content we handed over and the
	 * recipient may have been rewritten too — so the honest answer is to say where the
	 * guarantee stops rather than to infer through it. The send records
	 * self::LOCK_UNMATCHED and the delivery record says so.
	 *
	 * REACHABILITY, STATED PLAINLY: the common `woocommerce_mail_callback` replacements
	 * are SMTP and API senders that transmit directly and never re-enter `wp_mail()` (
	 * self::LOCK_UNFIRED). This shape needs a replacement that both REWRITES AND
	 * FORWARDS, together with a separate recipient-injecting `wp_mail` filter.
	 *
	 * ⚠ TWO RESIDUALS BEYOND THAT, BOTH TIER 3, BOTH STATED SO THEY ARE NOT
	 * REDISCOVERED:
	 *
	 *   1. a `wp_mail` invocation whose RECIPIENT, subject AND body are all
	 *      byte-identical to ours at `PHP_INT_MIN` is indistinguishable at this lock's
	 *      chosen identity boundary and is locked. ⚠ THAT IS NOT A NO-OP, AND CALLING IT
	 *      ONE WOULD BE WRONG: the identity deliberately excludes HEADERS, so such an
	 *      invocation can carry its own `Cc` or `Bcc` — its primary recipient is
	 *      unchanged, but those copy recipients are stripped;
	 *   2. a `wp_mail` callback registered at `PHP_INT_MIN` **before ours** runs before
	 *      ours, because WordPress runs same-priority callbacks in registration order. It
	 *      would see the message first and could mutate it into a miss — the declared
	 *      boundary's outcome by another route. ⚠ THIS IS THE SAME RESIDUAL ADR-0013 §5e
	 *      ALREADY ACCEPTS FOR THE RENDER CONTEXT'S TERMINAL PROMOTION, pointing the
	 *      other way: there a callback registered later at `PHP_INT_MAX` runs after ours,
	 *      here one registered earlier at `PHP_INT_MIN` runs before ours. One fact —
	 *      registration order breaks ties — and no priority number can beat it.
	 *
	 * ARMED ONCE PER SEND. A third party may run the whole params filter again from
	 * inside itself; the null check keeps that from stacking a second pair the `finally`
	 * would only remove one of.
	 *
	 * ⚠ THE BOUNDARY THIS LOCK STOPS AT, STATED RATHER THAN LEFT TO BE DISCOVERED
	 * (ADR-0020 §4b, docs/p2-backlog.md). The guarantee is over THE ARGUMENTS
	 * `wp_mail()` ACTS ON: exactly one `to`, no `Cc`, no `Bcc`, after every filter
	 * WordPress applies to them. `phpmailer_init` fires later still, and this plugin
	 * DELIBERATELY does not lock there:
	 *
	 *   1. `$phpmailer` is the `$GLOBALS['phpmailer']` SINGLETON, reused and cleared by
	 *      every `wp_mail()` call, and the hook carries nothing else — so there is no
	 *      way to tell our own message from any other's. That is the same
	 *      "identity cannot distinguish nested calls through one singleton" fact
	 *      `RenderLedger` is built around;
	 *   2. the pair above does not transfer. Between `wp_mail` and `phpmailer_init`
	 *      WordPress fires `pre_wp_mail`, `wp_mail_from`, `wp_mail_from_name`,
	 *      `wp_mail_content_type` and `wp_mail_charset` — hooks mail-logging and SMTP
	 *      plugins genuinely use — and none of them carries the message's own fields, so
	 *      an invocation could not even be identified there, let alone bracketed;
	 *   3. `wp_mail()` is PLUGGABLE. The stores most likely to rewrite recipients run a
	 *      mail-router plugin that replaces it outright, and then `phpmailer_init` never
	 *      fires at all — a lock there would be missing exactly where it was wanted.
	 *
	 * Past `wp_mail()`'s arguments the transport belongs to the site, and the residual
	 * is recorded as Tier 3 rather than papered over.
	 *
	 * @param array  $shot   The lock's frame-local state, BY REFERENCE so `send()`'s
	 *                       `finally` sees every field the callbacks write; see
	 *                       self::send() for its shape.
	 * @param string $lock   The one address this delivery may reach.
	 * @param array  $params The mail-callback parameters the mailer will be handed:
	 *                       `[ $to, $subject, $message, $headers, $attachments ]`.
	 * @return void
	 */
	private static function arm_wp_mail_lock( array &$shot, string $lock, array $params ): void {
		if ( null !== $shot['identify'] ) {
			return;
		}

		$fingerprint   = self::message_fingerprint( $params[0] ?? '', $params[1] ?? '', $params[2] ?? '' );
		$shot['armed'] = true;

		/*
		 * ⚠ IDENTIFY ONLY. It returns `$args` byte-for-byte as it received them: a
		 * callback at `PHP_INT_MIN` is the first thing every message in the request
		 * meets, and this one is registered while a send is in progress, so anything it
		 * changed would change SOMEBODY ELSE'S mail as readily as ours.
		 *
		 * `seen` is set for every invocation, matching or not, because "was `wp_mail()`
		 * entered at all" is the fact that separates self::LOCK_UNFIRED from
		 * self::LOCK_UNMATCHED.
		 */
		$shot['identify'] = static function ( $args ) use ( &$shot, $fingerprint ) {
			$shot['seen'] = true;

			$depth = self::wp_mail_depth();

			/*
			 * ⚠ AN ENTRY ALREADY AT THIS DEPTH IS A STRANDED ONE, AND IT IS COUNTED
			 * RATHER THAN QUIETLY REPLACED. The only way one survives is an invocation
			 * that reached `PHP_INT_MIN` and never reached `PHP_INT_MAX` — it threw, and
			 * somebody caught it. Overwriting is the CORRECT answer for the invocation
			 * starting now; losing the fact that it happened is not.
			 */
			if ( array_key_exists( $depth, $shot['matches'] ) ) {
				++$shot['residue'];
			}

			$shot['matches'][ $depth ] = is_array( $args )
				&& self::message_fingerprint( $args['to'] ?? '', $args['subject'] ?? '', $args['message'] ?? '' ) === $fingerprint;

			return $args;
		};

		$shot['enforce'] = static function ( $args ) use ( &$shot, $lock ) {
			/*
			 * ⚠ READ AT THIS INVOCATION'S OWN DEPTH, AND CONSUMED THERE. A deeper
			 * invocation's answer sits at a deeper key and is never visible here, however
			 * that invocation ended — which is the whole of the Part A3 item 1 fix.
			 *
			 * A MISSING entry is neither `true` nor a match, so an invocation whose
			 * `PHP_INT_MIN` callback somebody removed makes the lock DECLINE rather than
			 * fire on a message it never identified.
			 */
			$depth = self::wp_mail_depth();
			$match = $shot['matches'][ $depth ] ?? null;

			unset( $shot['matches'][ $depth ] );

			if ( true !== $match || ! is_array( $args ) ) {
				return $args;
			}

			$shot['fired'] = true;

			$args['to'] = $lock;

			/*
			 * ⚠ ONLY WHEN THE KEY IS SET, MIRRORING `wp_mail()` ITSELF. It reads each
			 * field back with `if ( isset( $atts['headers'] ) )` and otherwise keeps the
			 * argument it was called with — which is the block this class already
			 * stripped. Writing a key that was removed would replace those headers with
			 * an empty block and take `From:` and `Content-Type:` with it.
			 */
			if ( isset( $args['headers'] ) ) {
				$args['headers'] = self::without_copy_headers( $args['headers'] );
			}

			/*
			 * ⚠ IT DOES NOT RETIRE ITSELF, AND THAT CHANGED IN PART A3. It used to, on
			 * the "fires once" reasoning inherited from the positional shot — and that
			 * was the last positional artefact left in a mechanism whose whole premise is
			 * now identity. It cost something real: a replacement mail callback that
			 * FORWARDS THE SAME MESSAGE TWICE (a retry) had its second attempt leave with
			 * whatever a `wp_mail` filter injected, because the pair had already gone.
			 *
			 * Identity does the job the retirement used to pretend to do. What is left
			 * registered will only ever act on an invocation carrying this send's own
			 * recipient, subject and body — which is this message, whatever else the send
			 * mails before or after it. The `finally` in self::send() is what bounds the
			 * registration, and it is unconditional.
			 */
			return $args;
		};

		add_filter( 'wp_mail', $shot['identify'], PHP_INT_MIN, 1 );
		add_filter( 'wp_mail', $shot['enforce'], PHP_INT_MAX, 1 );
	}

	/**
	 * How many `wp_mail()` calls are on the PHP call stack right now.
	 *
	 * ⚠ THE POINT IS THAT THE CALL STACK UNWINDS AND OUR OWN BOOKKEEPING DOES NOT
	 * (PART A3, ITEM 1). The lock pairs one identification with one enforcement, and
	 * until Part A3 it paired them by push/pop — correct only while every invocation
	 * reaches both ends. A third party that wraps its own nested `wp_mail()` in
	 * `try`/`catch` breaks that: the nested call pushes at `PHP_INT_MIN`, throws before
	 * `PHP_INT_MAX`, is swallowed, and leaves its answer on top of the stack for the
	 * OUTER invocation to pop. The outer message — ours — then went out unlocked.
	 *
	 * PHP's own call stack has no such failure mode: a frame is gone the instant its
	 * call terminates, by return or by throw. Keying on the number of `wp_mail` frames
	 * therefore gives the outer invocation the same key at both ends whatever happened
	 * inside it, and a stranded entry sits at a deeper key that nothing will read again.
	 *
	 * ⚠ THE BACKTRACE IS NOT A FALLBACK AFTER THE GOOD OPTIONS TURNED OUT UNAVAILABLE.
	 * It is the ONLY source of this fact that unwinds correctly at all. Both cheaper
	 * candidates carry the very defect the depth key exists to survive — verified in the
	 * bundled WordPress source, not assumed — checked on 7.0.4 and re-checked unchanged on
	 * 7.1 after that install auto-updated mid-round:
	 *
	 *     $wp_current_filter        unsuitable — stays dirty after a thrown callback
	 *     WP_Hook::$nesting_level   unsuitable — private AND not exception-safe, same flaw
	 *     debug_backtrace()         chosen — PHP's real call stack unwinds on return
	 *                                        and on throw
	 *
	 *   1. `$GLOBALS['wp_current_filter']`, counting its `wp_mail` entries, looks exactly
	 *      equivalent and is far cheaper — `apply_filters()` pushes the hook name on entry
	 *      and pops it on exit. The pop is a plain statement after the callback loop with
	 *      no `try`/`finally` around it (`wp-includes/plugin.php`), so a throw leaves the
	 *      entry on that array for the rest of the request;
	 *   2. `WP_Hook::$nesting_level` is exactly this count, and it is **not maintained
	 *      correctly through a throw either** — which an earlier version of this comment
	 *      got wrong. `WP_Hook::apply_filters()` does `$nesting_level++` before the
	 *      callback loop and `--$this->nesting_level` after it, with no `try`/`finally`
	 *      between (`wp-includes/class-wp-hook.php`), so a throwing callback skips the
	 *      decrement exactly as it skips the pop above. Being `private` would have made
	 *      it unusable regardless; being unsound makes it useless as well.
	 *
	 * That is the whole argument for paying for a backtrace: **the two things that look
	 * like a nesting counter are both counters of "levels entered", and this lock needs a
	 * counter of "levels still open".** Only the call stack is the second thing.
	 *
	 * ⚠ COST, STATED RATHER THAN WAVED AWAY. `debug_backtrace()` walks the whole stack
	 * and is genuinely expensive. It runs at most twice per `wp_mail()` invocation and
	 * ONLY while a locked send is in flight — the test-send path, which is one confirmed
	 * click. `DEBUG_BACKTRACE_IGNORE_ARGS` is what keeps it from copying every argument
	 * of every frame, which is where the real cost of a backtrace lives.
	 *
	 * A pluggable `wp_mail()` replacement is still the global function `wp_mail`, so a
	 * replaced mailer counts the same. A frame count of 0 — somebody applying the
	 * `wp_mail` filter directly, without `wp_mail()` — is a valid key like any other:
	 * both callbacks compute it in the same chain and therefore agree.
	 *
	 * @return int
	 */
	private static function wp_mail_depth(): int {
		$depth = 0;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- The PHP call stack is the only source of wp_mail() nesting depth that unwinds on a throw; see above. Test-send path only.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( 'wp_mail' === ( $frame['function'] ?? '' ) && ! isset( $frame['class'] ) ) {
				++$depth;
			}
		}

		return $depth;
	}

	/**
	 * Remove both `wp_mail` callbacks and return the stack to baseline.
	 *
	 * ⚠ ONE CALLER, SINCE PART A3: `send()`'s `finally`. The enforcing callback used to
	 * call this too, retiring the pair the moment it had locked its own message — and that
	 * "fires once" behaviour was the last positional artefact in a mechanism whose premise
	 * is identity. It cost a re-forwarded message its lock, so it is gone, and cleanup now
	 * has a single owner that runs on every exit: the send that locked its message, the
	 * send whose message never reached `wp_mail()`, and the send something threw out of.
	 *
	 * It stays IDEMPOTENT anyway. A method that removes filters and resets state is one
	 * whose second call must be a no-op whoever makes it, and the null checks cost
	 * nothing.
	 *
	 * What the `finally` discards is COUNTED rather than dropped silently; see
	 * self::$lock_stack_residue for the only shape that produces a non-zero count.
	 *
	 * @param array $shot The lock's frame-local state, BY REFERENCE.
	 * @return void
	 */
	private static function disarm_wp_mail_lock( array &$shot ): void {
		if ( null !== $shot['identify'] ) {
			remove_filter( 'wp_mail', $shot['identify'], PHP_INT_MIN );
			$shot['identify'] = null;
		}

		if ( null !== $shot['enforce'] ) {
			remove_filter( 'wp_mail', $shot['enforce'], PHP_INT_MAX );
			$shot['enforce'] = null;
		}

		$shot['residue'] += count( $shot['matches'] );
		$shot['matches']  = array();
	}

	/**
	 * A fingerprint of ONE message: its recipient, its subject and its body.
	 *
	 * ⚠ THE RECIPIENT IS IN IT, AND LEAVING IT OUT WAS A TIER 1 DEFECT (PART A3,
	 * ITEM 2). Subject and body alone were chosen because `to` is the field the whole
	 * mechanism exists because third parties rewrite — but the comparison happens on the
	 * PRISTINE arguments, where `to` is still the confirmed address the
	 * `woocommerce_mail_callback_params` lock put there, and where it is the ONLY field
	 * that separates our message from a deliberate copy of it.
	 *
	 * Without it, an archival wrapper —
	 *
	 *     wp_mail( 'archive@example.test', $subject, $body, ... );   // the copy
	 *     return wp_mail( $to, $subject, $body, ... );               // the forward
	 *
	 * — produced a copy that matched, and the lock rewrote its recipient. An email
	 * addressed deliberately to the archive was delivered to the merchant instead: the
	 * exact harm this mechanism exists to prevent, pointing outward. The residual that
	 * excused it ("a copy of this very message, not a stranger's mail") was reasoning
	 * about the CONTENT of a message when the thing at stake was its ADDRESS.
	 *
	 * `headers` is still excluded — every deliverability plugin appends to it — and so
	 * is `attachments`, which is routinely empty and therefore identical across
	 * unrelated messages. Recipient, subject and body are jointly what a merchant would
	 * call "this message".
	 *
	 * ⚠ LENGTH-PREFIXED, so a field that happens to contain the separator cannot be
	 * rearranged into a different triple with the same digest.
	 *
	 * A non-scalar field — `to` may legitimately be an array, and a filter is free to
	 * return one for any of them — fingerprints as the empty string. Our own outgoing
	 * `to` is a string (the params lock wrote it), so an array recipient cannot collide
	 * with it: the mismatch fails to self::LOCK_UNMATCHED rather than to a wrong match.
	 *
	 * @param mixed $to      Recipient, as the arguments carry it.
	 * @param mixed $subject Subject.
	 * @param mixed $message Body.
	 * @return string 64 lowercase hex characters.
	 */
	private static function message_fingerprint( $to, $subject, $message ): string {
		$to      = is_scalar( $to ) ? (string) $to : '';
		$subject = is_scalar( $subject ) ? (string) $subject : '';
		$message = is_scalar( $message ) ? (string) $message : '';

		return hash(
			'sha256',
			strlen( $to ) . ':' . $to
			. "\0" . strlen( $subject ) . ':' . $subject
			. "\0" . strlen( $message ) . ':' . $message
		);
	}

	/**
	 * Re-assert the lock on the exact arguments the mailer is about to receive.
	 *
	 * @param mixed  $params Mail-callback parameters: `[ $to, $subject, $message,
	 *                       $headers, $attachments ]`.
	 * @param string $lock   The one address this delivery may reach.
	 * @return mixed
	 */
	private static function enforce_recipient_lock( $params, string $lock ) {
		if ( ! is_array( $params ) ) {
			return $params;
		}

		$params[0] = $lock;

		if ( array_key_exists( 3, $params ) ) {
			$params[3] = self::without_copy_headers( $params[3] );
		}

		return $params;
	}

	/**
	 * One header block with every `Cc:` and `Bcc:` line removed.
	 *
	 * ⚠ IT ACCEPTS AN ARRAY AS WELL AS A STRING, because `woocommerce_email_headers`
	 * is a filter and a third party may return either — `wp_mail()` accepts both, so
	 * "it is always a string" would be an assumption about other people's code.
	 *
	 * Matching is anchored per line and case-insensitive, exactly as
	 * `self::has_header_line()` matches, so `Bcc:` cannot be mistaken for `Cc:` and a
	 * differently-cased header is still recognised.
	 *
	 * @param mixed $headers Header block.
	 * @return mixed The same shape, without copy recipients.
	 */
	private static function without_copy_headers( $headers ) {
		if ( is_array( $headers ) ) {
			$kept = array();

			foreach ( $headers as $key => $line ) {
				if ( ! is_scalar( $line ) || 1 !== preg_match( '/^b?cc\s*:/i', trim( (string) $line ) ) ) {
					$kept[ $key ] = $line;
				}
			}

			return $kept;
		}

		$lines   = preg_split( '/\r\n|\r|\n/', (string) $headers );
		$kept    = array();
		$folding = false;

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			/*
			 * ⚠ CONTINUATION LINES GO WITH THE HEADER THEY BELONG TO. RFC 5322 folds a
			 * long value onto following lines that begin with whitespace, so dropping
			 * `Cc:` and keeping its continuation would leave a dangling fragment that
			 * the next parser reads as a header of its own.
			 */
			if ( $folding && 1 === preg_match( '/^[ \t]/', $line ) ) {
				continue;
			}

			$folding = 1 === preg_match( '/^b?cc\s*:/i', $line );

			if ( ! $folding ) {
				$kept[] = $line;
			}
		}

		// Rebuilt with the CRLF WooCommerce's own header block uses, and with the
		// trailing terminator preserved so appending stays well-formed.
		$block = implode( "\r\n", $kept );

		return '' === trim( $block ) ? '' : rtrim( $block, "\r\n" ) . "\r\n";
	}

	/**
	 * Record whether THIS OBJECT's callback is attached to each mail filter.
	 *
	 * WHY PHPMAILER'S `AltBody` IS NOT ALSO CAPTURED. `WC_Email::send()` clears
	 * it after the mail callback, and that clearing is skipped on a throw — but
	 * it cannot leak, and this was checked on this runtime rather than assumed:
	 * `wp_mail()` sets `$phpmailer->Body = ''` and `$phpmailer->AltBody = ''`
	 * (wp-includes/pluggable.php) BEFORE it fires `phpmailer_init`, which is the
	 * only hook that sets `AltBody`. Every `wp_mail()` call therefore starts from
	 * an empty one, and a stale value cannot reach a later message. Clearing it
	 * here would be dead code, so it is absent by decision rather than oversight.
	 *
	 * @return array<string,bool> Hook => was this object's callback attached.
	 */
	private function capture_mail_filter_state(): array {
		$frame = array();

		foreach ( self::MAIL_FILTERS as $hook => $method ) {
			$frame[ $hook ] = false !== has_filter( $hook, array( $this, $method ) );
		}

		return $frame;
	}

	/**
	 * Put each mail filter back exactly as it was on entry.
	 *
	 * Two directions, and both are load-bearing:
	 *
	 *   - attached now but NOT before → this send leaked it; remove it;
	 *   - attached before but NOT now → an OUTER send owns it and something
	 *     removed it (a nested `WC_Email::send()` completing normally does
	 *     exactly this); put it back.
	 *
	 * @param array $frame Frame from self::capture_mail_filter_state().
	 * @return void
	 */
	private function restore_mail_filter_state( array $frame ): void {
		foreach ( self::MAIL_FILTERS as $hook => $method ) {
			$callback = array( $this, $method );
			$before   = ! empty( $frame[ $hook ] );
			$now      = false !== has_filter( $hook, $callback );

			if ( $before && ! $now ) {
				// WooCommerce registers these at the default priority; matching
				// it is what makes this a restore rather than a re-registration
				// somewhere else in the chain.
				add_filter( $hook, $callback );
				continue;
			}

			if ( ! $before && $now ) {
				remove_filter( $hook, $callback );
			}
		}
	}

	/**
	 * Send one delivery.
	 *
	 * @param array $args {
	 *     Every field is read; a missing one becomes empty, never inherited.
	 *
	 *     @type string    $recipient     Comma-joined `to` addresses.
	 *     @type string    $cc            Comma-joined `cc` addresses.
	 *     @type string    $bcc           Comma-joined `bcc` addresses.
	 *     @type string    $subject       Subject line.
	 *     @type string    $heading       Email heading.
	 *     @type string    $content       Body, already kses-filtered, with
	 *                                    placeholders resolved for HTML.
	 *     @type string    $content_plain The same body resolved for text/plain;
	 *                                    derived from $content when absent.
	 *     @type array[]   $matched_items Matched item records.
	 *     @type int       $delivery_id   Owning tombstone id.
	 *     @type \WC_Order $object        The order.
	 * }
	 * @return string One of self::SENT, self::NOT_SENT, self::DISABLED_BY_FILTER
	 *                or self::NO_RECIPIENT. A STRING, NOT A BOOLEAN: `false`
	 *                conflated "the mailer failed" with "a filter declined this
	 *                delivery", and the caller recorded both as a transport
	 *                failure on a consumed identity.
	 */
	public function trigger( array $args ): string {
		/*
		 * CAPTURE THE FRAME THIS CALL IS INTERRUPTING (ADR-0012 §11a).
		 *
		 * At the top level this is the empty state and the `finally` below is
		 * indistinguishable from a reset. Under nesting it is the outer
		 * delivery's recipient, subject, content, order and delivery id — every
		 * one of which the outer call still needs when control returns to it.
		 */
		$previous = $this->capture_runtime_state();

		/*
		 * ⚠ CLEARED HERE BECAUSE THEY ARE NOT PART OF THE CAPTURED FRAME. Two of the
		 * returns below never reach `send()` at all, and without these lines they would
		 * report the PREVIOUS delivery's lock outcome — the state bleed this whole class
		 * is organised against, in the two fields that deliberately sit outside
		 * self::RUNTIME_FIELDS.
		 */
		$this->lock_outcome       = self::LOCK_NONE;
		$this->lock_stack_residue = 0;

		try {
			$this->apply_runtime_state( $args );

			/*
			 * THE PER-DELIVERY FILTER, NOT THE GLOBAL SWITCH (ADR-0012 §5a).
			 *
			 * `is_enabled()` applies `woocommerce_email_enabled_{id}` with
			 * `$this->object` attached, so it is answering "may THIS delivery
			 * go out" — which is why it is checked here, after every runtime
			 * field is set, and not before. The global switch is a different
			 * question with a different answer: the orchestrator reads the saved
			 * setting through `is_globally_enabled()` before it claims anything,
			 * so a filter that returns false for one order cannot make the
			 * feature look switched off for the whole store.
			 */
			if ( ! $this->is_enabled() ) {
				return self::DISABLED_BY_FILTER;
			}

			if ( '' === $this->recipient ) {
				return self::NO_RECIPIENT;
			}

			$sent = (bool) $this->send(
				$this->get_recipient(),
				$this->get_subject(),
				$this->get_content(),
				$this->get_headers(),
				$this->get_attachments()
			);

			return $sent ? self::SENT : self::NOT_SENT;
		} finally {
			/*
			 * RESTORE, NOT RESET. An exception thrown by a third-party filter
			 * inside send() must not leave this object addressed to the last
			 * customer — and a nested delivery must not leave the delivery that
			 * was interrupted holding an empty object. `$previous` is empty at
			 * the top level, so both requirements are the same statement.
			 */
			$this->restore_runtime_state( $previous );
		}
	}

	/**
	 * Render one delivery's message in BOTH formats WITHOUT sending it
	 * (ADR-0020 §1, §3).
	 *
	 * ⚠ IT IS self::trigger()'s BODY WITH THE SEND REMOVED, AND THAT IS THE POINT
	 * (ADR-0020 §6). The same `capture_runtime_state()` / `apply_runtime_state()` /
	 * `restore_runtime_state()` triple, and the same `WC_Email::get_content()` — so a
	 * preview cannot show a merchant a wrapper, a heading or a body that differs from
	 * what the send would produce. A second renderer is how a preview starts lying.
	 *
	 * ⚠ `get_content()`, NOT `get_content_html()` / `get_content_plain()` DIRECTLY, AND
	 * THE FIRST DRAFT OF THIS METHOD GOT THAT WRONG. `WC_Email::get_content()` is what
	 * `send()` calls, and for a PLAIN body it does more than fetch it:
	 *
	 *     wordwrap( preg_replace( $this->plain_search, $this->plain_replace,
	 *               wp_strip_all_tags( $this->get_content_plain() ) ), 70 )
	 *
	 * — the pass that turns `&mdash;` into `—`, `&#036;` into `$`, and deletes every
	 * other entity outright (ADR-0014 §9a). Calling the builder directly showed the
	 * merchant the PRE-processed text, full of raw entities no customer would ever
	 * receive. Caught by reading the first captured sample rather than by a failing
	 * assertion, which is exactly why the sample is in the report.
	 *
	 * It also means the preview inherits `get_content()`'s block-email branch, so a
	 * store using WooCommerce's block email editor previews what that editor produces
	 * rather than what this method would have assembled instead.
	 *
	 * ⚠ NO `is_enabled()` CHECK, DELIBERATELY. That filter decides whether a delivery
	 * may GO OUT; nothing is going out here. Refusing to render a preview because a
	 * third party declined this delivery would leave the merchant unable to see the
	 * content they are trying to debug (ADR-0020 §3b takes the same position for the
	 * global switch).
	 *
	 * ⚠ NO `self::send()`, NO `get_headers()`, NO `wp_mail()`. This method cannot
	 * reach a transport: it calls the two content builders and returns strings.
	 *
	 * ⚠ THE RESTORE IS IN A `finally`, WHICH IS THE WHOLE DIFFERENCE FROM CORE'S OWN
	 * PREVIEW. `EmailPreview::render_preview_email()` mutates the live registered
	 * email object and cleans up on the success path only (WC 11.0.1, verified), so an
	 * interrupted preview leaves it addressed to the preview's subject. A throw from a
	 * template, a filter or the inliner must not leave this shared object holding a
	 * preview's state for the rest of the request.
	 *
	 * @param array $args Delivery arguments; see self::trigger().
	 * @return array{subject:string, heading:string, html:string, plain:string}
	 */
	public function render_preview( array $args ): array {
		$previous = $this->capture_runtime_state();
		$type     = $this->email_type;

		try {
			$this->apply_runtime_state( $args );

			// ⚠ `text/html` FOR THE INLINER'S SAKE. `style_inline()` consults
			// `get_content_type()`, which reads this property, and returns the content
			// untouched for anything that is not an HTML type — so an `email_type` of
			// `plain` would hand back a preview with none of the store's email CSS.
			$this->email_type = 'html';

			$html = $this->style_inline( $this->get_content() );

			$this->email_type = 'plain';

			$plain = $this->get_content();

			return array(
				'subject' => $this->get_subject(),
				'heading' => $this->get_heading(),
				'html'    => (string) $html,
				'plain'   => $plain,
			);
		} finally {
			$this->email_type = $type;

			$this->restore_runtime_state( $previous );
		}
	}

	/**
	 * The GLOBAL kill switch: the merchant's saved setting, and nothing else
	 * (ADR-0012 §5a).
	 *
	 * DELIBERATELY NOT `is_enabled()`. That method applies
	 * `woocommerce_email_enabled_{id}`, which is handed `$this->object` — so a
	 * third party can legitimately answer `true` for the store and `false` for
	 * order #123. Reading it with no order attached made that per-order `false`
	 * look like "the merchant switched the feature off", and the orchestrator
	 * then skipped every rule on the order without claiming or recording
	 * anything. The two questions are now asked separately, each where its
	 * answer is meaningful.
	 *
	 * `$this->enabled` is the value `WC_Email` loads from the saved settings; it
	 * is a string, `'yes'` or `'no'`, exactly as core compares it.
	 *
	 * @return bool
	 */
	public function is_globally_enabled(): bool {
		return 'yes' === $this->enabled;
	}

	/**
	 * The name of the per-delivery enable filter, for diagnostics.
	 *
	 * @return string
	 */
	public static function enabled_filter(): string {
		return 'woocommerce_email_enabled_' . EmailIdentity::EMAIL_ID;
	}

	/**
	 * Subject for the current delivery.
	 *
	 * ALREADY RESOLVED AND ALREADY HEADER-STRIPPED. The orchestrator substitutes
	 * this rule's placeholders in the HEADER context before calling
	 * self::trigger() (ADR-0014 §9), so every value in it has been through
	 * `HeaderGuard` at substitution and the whole string again in
	 * self::apply_runtime_state().
	 *
	 * ⚠ `WC_Email::format_string()` IS STILL DELIBERATELY NOT APPLIED. That is
	 * WooCommerce's OWN `{site_title}`-style placeholder pass, driven by
	 * `$this->placeholders` — a second, differently-spelled substitution engine
	 * whose values this plugin does not control and whose rules are not
	 * ADR-0014's. One resolver, one grammar, one escaping contract.
	 *
	 * @return string
	 */
	public function get_subject(): string {
		return $this->delivery_subject;
	}

	/**
	 * Heading for the current delivery.
	 *
	 * @return string
	 */
	public function get_heading(): string {
		return $this->delivery_heading;
	}

	/**
	 * Headers: WOOCOMMERCE'S OWN, plus this plugin's Cc and Bcc (ADR-0012 §5b).
	 *
	 * THIS METHOD USED TO REIMPLEMENT `WC_Email::get_headers()` AND GOT THE
	 * STORE'S IDENTITY WRONG. It composed Content-Type, Cc and Bcc and stopped —
	 * so it never emitted **Reply-To**, and every message this plugin sent
	 * ignored the merchant's configured reply address. Customer replies went to
	 * the From address instead of wherever the store had directed them. That was
	 * not an edge case: it was every delivery.
	 *
	 * So the parent builds the headers now, and this class only adds what is
	 * genuinely its own. Everything the parent knows about sender identity is
	 * inherited by construction and stays inherited when WooCommerce changes it:
	 *
	 *   - **Reply-To** — the enabled/disabled setting, the configured address,
	 *     the configured name, and core's fallback to `From name <From address>`
	 *     when reply-to is off;
	 *   - **Cc/Bcc** — emitted by the parent on WooCommerce versions whose
	 *     `email_improvements` path is active, in which case this class adds
	 *     nothing and the `woocommerce_email_cc_recipient_{id}` filter governs;
	 *   - **Content-Type** — from the store's `email_type` setting.
	 *
	 * **From** is not a header this method writes at all, on any WooCommerce
	 * version: `WC_Email::send()` binds `get_from_address()` and
	 * `get_from_name()` onto `wp_mail_from` / `wp_mail_from_name` for the
	 * duration of the send, so the store's sender identity applies to this
	 * plugin's messages exactly as it does to core's. Asserted, not assumed —
	 * see `HeaderInheritanceTest`.
	 *
	 * THE CC/BCC INJECTION RUNS INSIDE THE PARENT'S FILTER, AT
	 * `HEADER_INJECT_PRIORITY`, rather than being appended to the parent's
	 * return value. Appending afterwards would leave `woocommerce_email_headers`
	 * unable to see this plugin's Cc and Bcc — an SMTP or deliverability plugin
	 * reading the block would be reading an incomplete one. The filter is still
	 * applied EXACTLY ONCE, by the parent.
	 *
	 * Every value has already passed `HeaderGuard` at resolution AND again in
	 * `trigger()`, so nothing reaching this point can carry a line break.
	 *
	 * @return string
	 */
	public function get_headers(): string {
		/*
		 * A FRESH CLOSURE PER CALL, NEVER `array( $this, 'inject_copy_headers' )`
		 * (ADR-0012 §11a).
		 *
		 * WordPress keys a registration on the callback's identity, and an
		 * object-method callable has ONE identity for every call on this shared
		 * instance. So a nested delivery's `remove_filter()` would remove the
		 * registration the OUTER call is still relying on, and a third level
		 * would remove it before the outer had ever used it. Two closures are
		 * two distinct callbacks, so each frame adds and removes only its own.
		 *
		 * Both frames' closures running against one header build is harmless:
		 * the injector is idempotent, because it skips any line already present.
		 */
		$inject = function ( $headers, $email_id = '', $mail_object = null, $email = null ) {
			return $this->inject_copy_headers( $headers, $email_id, $mail_object, $email );
		};

		add_filter( 'woocommerce_email_headers', $inject, self::HEADER_INJECT_PRIORITY, 4 );

		try {
			return (string) parent::get_headers();
		} finally {
			// Removed in a `finally` for the same reason `trigger()` restores in
			// one: a throw from another callback must not leave this object
			// injecting into some other email's headers.
			remove_filter( 'woocommerce_email_headers', $inject, self::HEADER_INJECT_PRIORITY );
		}
	}

	/**
	 * Add this plugin's Cc and Bcc to the header block the parent built.
	 *
	 * NEVER DUPLICATES. WooCommerce emits its own `Cc:`/`Bcc:` lines from
	 * `$this->cc`/`$this->bcc` when its `email_improvements` path is active
	 * (WC 9.8+), and on those versions this method finds the lines already
	 * present and leaves them alone — so the
	 * `woocommerce_email_cc_recipient_{id}` filter keeps the last word, exactly
	 * as it does for a core email.
	 *
	 * FEATURE-DETECTED, NOT ASSUMED. Below the provisional WooCommerce floor
	 * (8.2) neither `get_cc_recipient()` nor the improvements path exists, so
	 * the already-validated, already-stripped runtime values are written
	 * directly. The degradation is that the WooCommerce recipient filters are
	 * unavailable to filter — never that the Cc and Bcc are silently dropped.
	 *
	 * @param mixed  $headers      Header block built so far.
	 * @param string $email_id     Email id the parent is building for.
	 * @param mixed  $mail_object  Object the email relates to; unused, the
	 *                             parameter exists to keep the filter signature
	 *                             positional.
	 * @param mixed  $email        The `WC_Email` instance.
	 * @return string
	 */
	public function inject_copy_headers( $headers, $email_id = '', $mail_object = null, $email = null ): string {
		$headers = (string) $headers;

		// The filter is global. Only ever touch this object's own headers.
		if ( $email !== $this && (string) $email_id !== (string) $this->id ) {
			return $headers;
		}

		foreach ( array( 'Cc', 'Bcc' ) as $line ) {
			if ( self::has_header_line( $headers, $line ) ) {
				continue;
			}

			$value = $this->copy_recipients( $line );

			if ( '' !== $value ) {
				$headers .= $line . ': ' . $value . "\r\n";
			}
		}

		return $headers;
	}

	/**
	 * The Cc or Bcc value for the current delivery, through WooCommerce's own
	 * accessor where one exists.
	 *
	 * @param string $line `Cc` or `Bcc`.
	 * @return string
	 */
	private function copy_recipients( string $line ): string {
		$accessor = 'Cc' === $line ? 'get_cc_recipient' : 'get_bcc_recipient';
		$property = 'Cc' === $line ? $this->cc : $this->bcc;

		if ( method_exists( $this, $accessor ) ) {
			return (string) $this->{$accessor}();
		}

		return HeaderGuard::strip( (string) $property );
	}

	/**
	 * Whether a header block already carries one named header line.
	 *
	 * Anchored per line and case-insensitive, so `Bcc:` cannot satisfy a check
	 * for `Cc:` and a differently-cased core header is still recognised.
	 *
	 * @param string $headers Header block.
	 * @param string $name    Header name.
	 * @return bool
	 */
	private static function has_header_line( string $headers, string $name ): bool {
		return 1 === preg_match( '/^' . preg_quote( $name, '/' ) . ':/mi', $headers );
	}

	/**
	 * HTML body, wrapped in WooCommerce's own header and footer templates so the
	 * store's branding applies (ADR-0012 §6).
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			'emails/email-header.php',
			array(
				'email_heading' => $this->get_heading(),
				'email'         => $this,
			)
		)
		. wpautop( wp_kses_post( $this->delivery_content ) )
		. wc_get_template_html( 'emails/email-footer.php', array( 'email' => $this ) );
	}

	/**
	 * Plain-text body.
	 *
	 * ⚠ THIS USED TO DELETE THE MERCHANT'S ENTITIES, AND THE FIX IS ADR-0014 §9a.
	 * It ran `wp_strip_all_tags( wp_kses_post( … ) )` with NO decode step, and
	 * `WC_Email::get_content()` then runs every plain body through
	 * `$plain_search`/`$plain_replace`, whose last-but-one pattern is
	 * `/&[^&\s;]+;/i` → `''`: **every entity WooCommerce does not explicitly
	 * handle is deleted outright**. A rule body containing `caf&eacute;` reached
	 * the customer as `caf`. Prompt 5A fixed exactly this defect for INSERT mode
	 * (ADR-0013 §4b) and separate mode kept it; both now flatten through the one
	 * `Domain\Text::to_plain_text()`.
	 *
	 * A PRE-RENDERED plain body is preferred when the caller supplied one, because
	 * placeholder VALUES must be substituted into text rather than flattened after
	 * substitution — flattening afterwards would strip a customer's name of its
	 * own angle brackets and mangle every `&` in a value (ADR-0014 §9).
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		$body = '' !== $this->delivery_content_plain
			? $this->delivery_content_plain
			: Text::to_plain_text( wp_kses_post( $this->delivery_content ) );

		return $this->get_heading() . "\n\n" . $body . "\n";
	}
}
