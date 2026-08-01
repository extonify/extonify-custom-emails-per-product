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

		try {
			return (bool) parent::send( $to, $subject, $message, $headers, $attachments );
		} finally {
			$this->restore_mail_filter_state( $frame );
		}
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
