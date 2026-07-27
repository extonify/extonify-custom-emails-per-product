<?php
/**
 * POC-B — SINGLE FIXED WC_Email, RUNTIME-CONFIGURED. NON-PRODUCTION.
 *
 * Proves ADR-0002 (amended, Prompt 1b):
 *  - registered BEFORE the mailer initialises; present in the live
 *    WC()->mailer()->get_emails();
 *  - init_form_fields() exposes ONLY enable + email type; global kill switch;
 *  - runtime state is reset at BOTH entry and finally, every field set
 *    explicitly on every call (proven by a CONTAMINATION test on the LIVE object);
 *  - `email_type` is a STORE SETTING, not a per-delivery runtime argument — HTML
 *    and plain are exercised by temporarily updating the saved setting, which is
 *    restored unchanged.
 *
 * All isolation/contamination runs against the LIVE object from
 * WC()->mailer()->get_emails() (never a clone or a separate `new` instance).
 *
 * @package Extonify\WCEP\POC
 */

require __DIR__ . '/_bootstrap.php';

wcep_poc_section( 'POC-B — SINGLE FIXED WC_Email (ADR-0002 amended, Prompt 1b)' );

class Extonify_WCEP_POC_Custom_Email extends WC_Email {

	public $recipient     = '';
	public $cc            = '';
	public $bcc           = '';
	public $poc_subject   = '';
	public $poc_heading   = '';
	public $poc_content   = '';
	public $matched_items = array();
	public $delivery_ref  = null;

	public function __construct() {
		$this->id             = 'extonify_wcep_custom';
		$this->title          = 'Extonify Custom Emails Per Product';
		$this->description    = 'Global kill switch. All content lives on the rules screen.';
		$this->customer_email = true;
		$this->enabled        = 'yes';
		$this->template_html  = '';
		$this->template_plain = '';
		parent::__construct();
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'    => array( 'title' => 'Enable/Disable', 'type' => 'checkbox', 'label' => 'Enable (global kill switch)', 'default' => 'yes' ),
			'email_type' => array( 'title' => 'Email type', 'type' => 'select', 'options' => $this->get_email_type_options(), 'default' => 'html' ),
		);
	}

	public function get_subject() {
		return $this->poc_subject;
	}
	public function get_heading() {
		return $this->poc_heading;
	}
	public function get_headers() {
		$h = 'Content-Type: ' . $this->get_content_type() . "\r\n";
		if ( '' !== $this->cc ) {
			$h .= 'Cc: ' . $this->cc . "\r\n";
		}
		if ( '' !== $this->bcc ) {
			$h .= 'Bcc: ' . $this->bcc . "\r\n";
		}
		return $h;
	}
	public function get_content_html() {
		return wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $this->get_heading(), 'email' => $this ) )
			. wpautop( wp_kses_post( $this->poc_content ) )
			. wc_get_template_html( 'emails/email-footer.php', array( 'email' => $this ) );
	}
	public function get_content_plain() {
		return $this->get_heading() . "\n\n" . wp_strip_all_tags( $this->poc_content ) . "\n";
	}

	/** Reset ALL runtime state. Does NOT touch email_type (a store setting). */
	public function reset_runtime_state() {
		$this->recipient     = '';
		$this->cc            = '';
		$this->bcc           = '';
		$this->poc_subject   = '';
		$this->poc_heading   = '';
		$this->poc_content   = '';
		$this->matched_items = array();
		$this->object        = null;
		$this->delivery_ref  = null;
	}

	/**
	 * Reset at ENTRY and in FINALLY; set EVERY runtime field explicitly on every
	 * call (never conditionally on whether an argument was supplied). email_type
	 * is NOT a runtime argument.
	 */
	public function poc_trigger( array $args ) {
		try {
			$this->reset_runtime_state(); // entry reset.
			$this->recipient     = $args['recipient'] ?? '';
			$this->cc            = $args['cc'] ?? '';
			$this->bcc           = $args['bcc'] ?? '';
			$this->poc_subject   = $args['subject'] ?? '';
			$this->poc_heading   = $args['heading'] ?? '';
			$this->poc_content   = $args['content'] ?? '';
			$this->matched_items = $args['matched_items'] ?? array();
			$this->object        = $args['object'] ?? null;
			$this->delivery_ref  = $args['delivery_ref'] ?? null;

			if ( ! $this->is_enabled() ) {
				return false; // global kill switch.
			}
			if ( '' === $this->recipient ) {
				return false;
			}
			return $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		} finally {
			$this->reset_runtime_state(); // finally reset.
		}
	}
}

// Register BEFORE the mailer initialises.
add_filter( 'woocommerce_email_classes', function ( $emails ) {
	$emails['Extonify_WCEP_POC_Custom_Email'] = new Extonify_WCEP_POC_Custom_Email();
	return $emails;
} );

$GLOBALS['wcep_b_sent'] = array();
add_action( 'woocommerce_email_sent', function ( $return, $id, $email ) {
	$GLOBALS['wcep_b_sent'][] = array( 'is_bool' => is_bool( $return ), 'id' => $id );
}, 10, 3 );

// --- register-before-init + form fields ------------------------------------
$emails = WC()->mailer()->get_emails(); // first mailer init in this process.
wcep_poc_assert( 'class present in live WC()->mailer()->get_emails() (registered before init)', isset( $emails['Extonify_WCEP_POC_Custom_Email'] ) );
$live = $emails['Extonify_WCEP_POC_Custom_Email'] ?? null;
wcep_poc_assert( 'live instance is our class with the fixed id', $live instanceof Extonify_WCEP_POC_Custom_Email && 'extonify_wcep_custom' === $live->id );
$live->init_form_fields();
$keys = array_keys( $live->form_fields );
sort( $keys );
wcep_poc_assert( 'init_form_fields exposes ONLY [enabled, email_type]', array( 'email_type', 'enabled' ) === $keys, 'keys=' . implode( ',', $keys ) );

// --- basic send + email_sent ------------------------------------------------
wcep_poc_mail_reset();
$GLOBALS['wcep_b_sent'] = array();
$live->enabled = 'yes';
$ok = $live->poc_trigger( array( 'recipient' => 'r@example.test', 'subject' => 'Subj', 'heading' => 'Head', 'content' => 'Body: two-year warranty.' ) );
$m  = wcep_poc_mail_last();
wcep_poc_assert( 'send returned success, runtime subject applied', true === $ok && $m && 'Subj' === $m['subject'] );
$sent = end( $GLOBALS['wcep_b_sent'] );
wcep_poc_assert( 'woocommerce_email_sent fired: boolean + fixed id', 1 === count( $GLOBALS['wcep_b_sent'] ) && $sent['is_bool'] && 'extonify_wcep_custom' === $sent['id'] );

// =========================================================================
// KILL SWITCH.
// =========================================================================
wcep_poc_section( 'KILL SWITCH — enabled / disabled / re-enabled' );
$args = array( 'recipient' => 'ks@example.test', 'subject' => 'ks', 'heading' => 'ks', 'content' => 'ks body' );
wcep_poc_mail_reset();
$live->enabled = 'yes';
$live->poc_trigger( $args );
wcep_poc_assert( 'enabled → mail captured', 1 === count( wcep_poc_mail_all() ) );
wcep_poc_mail_reset();
$live->enabled = 'no';
$rd = $live->poc_trigger( $args );
wcep_poc_assert( 'disabled → ZERO mail (returns false)', 0 === count( wcep_poc_mail_all() ) && false === $rd );
wcep_poc_mail_reset();
$live->enabled = 'yes';
$live->poc_trigger( $args );
wcep_poc_assert( 're-enabled → mail captured', 1 === count( wcep_poc_mail_all() ) );

// =========================================================================
// SINGLETON ISOLATION — A then B on the LIVE object.
// =========================================================================
wcep_poc_section( 'SINGLETON ISOLATION — A then B on the LIVE object' );
wcep_poc_mail_reset();
$live->poc_trigger( array( 'recipient' => 'A@example.test', 'cc' => 'ccA@example.test', 'bcc' => 'bccA@example.test', 'subject' => 'Subject A', 'heading' => 'Heading A', 'content' => 'AAA marker', 'matched_items' => array( 1, 2 ) ) );
$mailA = wcep_poc_mail_last();
wcep_poc_assert( 'A delivered to A', $mailA && 'A@example.test' === $mailA['to'] );
wcep_poc_assert( 'state reset after A (all fields empty/null)', '' === $live->recipient && '' === $live->cc && '' === $live->bcc && '' === $live->poc_content && array() === $live->matched_items && null === $live->object );
wcep_poc_mail_reset();
$live->poc_trigger( array( 'recipient' => 'B@example.test', 'subject' => 'Subject B', 'heading' => 'Heading B', 'content' => 'BBB marker' ) );
$mailB = wcep_poc_mail_last();
wcep_poc_assert( 'B to B only; body BBB not AAA; no A cc/bcc leaked', $mailB && 'B@example.test' === $mailB['to'] && false !== strpos( $mailB['message'], 'BBB marker' ) && false === strpos( $mailB['message'], 'AAA marker' ) && false === strpos( $mailB['headers'], 'ccA@example.test' ) && false === strpos( $mailB['headers'], 'bccA@example.test' ) );

// =========================================================================
// CONTAMINATION — dirty the LIVE object between deliveries; B must be clean.
// =========================================================================
wcep_poc_section( 'CONTAMINATION — external mutation between deliveries' );
// Simulate external code dirtying the shared object AFTER a prior delivery's
// finally-reset and BEFORE the next trigger (the exact case entry-reset defends).
$live->cc          = 'DIRTY-cc@evil.test';
$live->bcc         = 'DIRTY-bcc@evil.test';
$live->poc_content = 'DIRTY-CONTENT';
$live->poc_subject = 'DIRTY-SUBJECT';
$live->poc_heading = 'DIRTY-HEADING';
wcep_poc_mail_reset();
$live->poc_trigger( array( 'recipient' => 'clean-B@example.test', 'subject' => 'Clean Subject', 'heading' => 'Clean Heading', 'content' => 'CLEAN-BODY' ) ); // omits cc/bcc.
$mailC = wcep_poc_mail_last();
wcep_poc_assert( 'contamination: subject is clean, not DIRTY', $mailC && 'Clean Subject' === $mailC['subject'] );
wcep_poc_assert( 'contamination: body is CLEAN-BODY, no DIRTY-CONTENT', $mailC && false !== strpos( $mailC['message'], 'CLEAN-BODY' ) && false === strpos( $mailC['message'], 'DIRTY-CONTENT' ) );
wcep_poc_assert( 'contamination: heading clean, no DIRTY-HEADING', $mailC && false === strpos( $mailC['message'], 'DIRTY-HEADING' ) );
wcep_poc_assert( 'contamination: NO dirty Cc/Bcc in headers (entry-reset + explicit set)', $mailC && false === strpos( $mailC['headers'], 'DIRTY-cc@evil.test' ) && false === strpos( $mailC['headers'], 'DIRTY-bcc@evil.test' ) );

// =========================================================================
// EMAIL_TYPE IS A STORE SETTING — exercise HTML/plain via the saved setting.
// =========================================================================
wcep_poc_section( 'EMAIL_TYPE — store setting only (saved-setting update/restore)' );
$opt      = 'woocommerce_' . $live->id . '_settings';
$orig_opt = get_option( $opt ); // false or array — the untouched baseline.

$apply_format = function ( $type ) use ( $live, $opt ) {
	$s = get_option( $opt, array() );
	if ( ! is_array( $s ) ) {
		$s = array();
	}
	$s['email_type'] = $type;
	update_option( $opt, $s );
	$live->init_settings();
	$live->email_type = $live->get_option( 'email_type', 'html' ); // driven from the saved setting.
};

$apply_format( 'html' );
wcep_poc_mail_reset();
$live->poc_trigger( array( 'recipient' => 'fmt@example.test', 'subject' => 'fmt', 'heading' => 'fmt head', 'content' => 'warranty body' ) );
$htmlMail = wcep_poc_mail_last();
wcep_poc_assert( 'HTML setting → wrapped in store template (template_container)', $htmlMail && false !== strpos( $htmlMail['message'], 'id="template_container"' ) );

$apply_format( 'plain' );
wcep_poc_mail_reset();
$live->poc_trigger( array( 'recipient' => 'fmt@example.test', 'subject' => 'fmt', 'heading' => 'fmt head', 'content' => 'warranty body' ) );
$plainMail = wcep_poc_mail_last();
wcep_poc_assert( 'plain setting → NO HTML wrapper, content present', $plainMail && false === strpos( $plainMail['message'], 'id="template_container"' ) && false !== strpos( $plainMail['message'], 'warranty body' ) );

// Restore the saved setting exactly.
if ( false === $orig_opt ) {
	delete_option( $opt );
} else {
	update_option( $opt, $orig_opt );
}
$live->init_settings();
$live->email_type = $live->get_option( 'email_type', 'html' );
wcep_poc_assert( 'saved email_type setting is UNCHANGED at the end', get_option( $opt ) == $orig_opt );

wcep_poc_cleanup();
wcep_poc_assert( 'cleanup verify-after-delete OK — no fixture leak', ! empty( $GLOBALS['wcep_poc_cleanup_report']['ok'] ), 'leaks=' . implode( ',', $GLOBALS['wcep_poc_cleanup_report']['leaks'] ) );
wcep_poc_summary();
echo "\nPOC-B OK\n";