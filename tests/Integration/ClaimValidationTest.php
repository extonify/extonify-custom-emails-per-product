<?php
/**
 * Claim input validation and the status allowlist (Prompt 2a Item 6).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * A repository that keeps the log quiet while the rejection paths are
 * exercised.
 */
final class QuietDeliveryRepository extends DeliveryRepository {

	/**
	 * Swallow the log write.
	 *
	 * @param string $message Error detail.
	 * @return void
	 */
	protected function log_error( string $message ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- deliberate no-op override; the message is intentionally discarded in tests.
}

/**
 * `claim()` used to hash and write whatever it was given.
 *
 * A tombstone written under a meaningless identity — order 0, rule 0, an
 * invented mode, an empty trigger — can never be matched by a later call, so
 * duplicate prevention silently stops working for that delivery. That is the
 * worst failure this table can have, and it looks like success.
 */
final class ClaimValidationTest extends IntegrationTestCase {

	/**
	 * Repository under test.
	 *
	 * @var DeliveryRepository
	 */
	private $repo;

	/**
	 * Build the repository.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_repo() {
		$this->repo = new QuietDeliveryRepository();
	}

	/**
	 * Each invalid input, individually, returns FAILED and writes no row.
	 *
	 * @dataProvider invalid_claim_provider
	 *
	 * @param int    $order_id           Order id.
	 * @param int    $rule_id            Rule id.
	 * @param string $mode               Delivery mode.
	 * @param string $trigger_identity   Trigger identity.
	 * @param int    $rule_revision_sent Rule revision.
	 * @return void
	 */
	public function test_invalid_input_is_rejected_and_writes_nothing(
		int $order_id,
		int $rule_id,
		string $mode,
		string $trigger_identity,
		int $rule_revision_sent
	) {
		$before = $this->repo->count();

		$result = $this->repo->claim( $order_id, $rule_id, $mode, $trigger_identity, $rule_revision_sent );

		$this->assertSame( DeliveryRepository::FAILED, $result['result'] );
		$this->assertSame( 0, $result['delivery_id'] );
		$this->assertSame( '', $result['identity_hash'], 'A rejected claim still produced an identity hash.' );
		$this->assertSame( $before, $this->repo->count(), 'A rejected claim wrote a row.' );
	}

	/**
	 * Every individually-invalid input, with the other four kept valid.
	 *
	 * @return array<string,array{0:int,1:int,2:string,3:string,4:int}>
	 */
	public static function invalid_claim_provider() {
		$order    = 4242;
		$rule     = 7;
		$mode     = 'insert';
		$identity = 'status:completed';

		return array(
			'order_id zero'        => array( 0, $rule, $mode, $identity, 0 ),
			'order_id negative'    => array( -5, $rule, $mode, $identity, 0 ),
			'rule_id zero'         => array( $order, 0, $mode, $identity, 0 ),
			'rule_id negative'     => array( $order, -3, $mode, $identity, 0 ),
			'mode invented'        => array( $order, $rule, 'broadcast', $identity, 0 ),
			'mode empty'           => array( $order, $rule, '', $identity, 0 ),
			'identity empty'       => array( $order, $rule, $mode, '', 0 ),
			'identity whitespace'  => array( $order, $rule, $mode, '   ', 0 ),
			'identity no prefix'   => array( $order, $rule, $mode, 'completed', 0 ),
			'identity bad prefix'  => array( $order, $rule, $mode, 'whenever:completed', 0 ),
			'identity empty value' => array( $order, $rule, $mode, 'status:', 0 ),
			'identity over long'   => array( $order, $rule, $mode, 'status:' . str_repeat( 'a', 200 ), 0 ),
			'revision negative'    => array( $order, $rule, $mode, $identity, -1 ),
		);
	}

	/**
	 * A valid claim still succeeds — the validation is not simply refusing
	 * everything.
	 *
	 * @return void
	 */
	public function test_a_valid_claim_still_succeeds() {
		$order_id = $this->fake_order_id();

		$result = $this->repo->claim( $order_id, 80, 'insert', 'status:completed', 3 );
		$this->track_delivery( $result['delivery_id'] );

		$this->assertSame( DeliveryRepository::CLAIMED, $result['result'] );
		$this->assertGreaterThan( 0, $result['delivery_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result['identity_hash'] );
	}

	/**
	 * Every ADR-0004 trigger form is accepted, so validation does not
	 * accidentally exclude a legitimate identity the engine will produce.
	 *
	 * @dataProvider valid_identity_provider
	 *
	 * @param string $identity Trigger identity.
	 * @return void
	 */
	public function test_every_adr_trigger_form_is_accepted( string $identity ) {
		$result = $this->repo->claim( $this->fake_order_id(), 81, 'separate', $identity );
		$this->track_delivery( $result['delivery_id'] );

		$this->assertSame( DeliveryRepository::CLAIMED, $result['result'], "Rejected a valid identity: {$identity}" );
	}

	/**
	 * The identity forms ADR-0004 defines.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function valid_identity_provider() {
		return array(
			'status'        => array( 'status:completed' ),
			'hyphen status' => array( 'status:on-hold' ),
			'transition'    => array( 'transition:pending>processing' ),
			'hyphen both'   => array( 'transition:on-hold>completed' ),
			'refund'        => array( 'refund:2191' ),
		);
	}

	/**
	 * set_final_status() accepts only the allowlisted statuses.
	 *
	 * @return void
	 */
	public function test_final_status_allowlist() {
		$claim = $this->repo->claim( $this->fake_order_id(), 82, 'insert', 'status:processing' );
		$this->track_delivery( $claim['delivery_id'] );

		foreach ( DeliveryRepository::FINAL_STATUSES as $status ) {
			$this->assertTrue(
				$this->repo->set_final_status( $claim['delivery_id'], $status ),
				"The allowlisted status {$status} was rejected."
			);
			$this->assertSame( $status, (string) $this->delivery_column( $claim['delivery_id'], 'final_status' ) );
		}
	}

	/**
	 * An unrecognised status is refused and leaves the stored value alone.
	 *
	 * @dataProvider invalid_status_provider
	 *
	 * @param string $status Candidate status.
	 * @return void
	 */
	public function test_unrecognised_final_status_is_refused( string $status ) {
		$claim = $this->repo->claim( $this->fake_order_id(), 83, 'insert', 'status:processing' );
		$this->track_delivery( $claim['delivery_id'] );

		$this->assertTrue( $this->repo->set_final_status( $claim['delivery_id'], 'sent' ) );

		$this->assertFalse( $this->repo->set_final_status( $claim['delivery_id'], $status ) );
		$this->assertSame(
			'sent',
			(string) $this->delivery_column( $claim['delivery_id'], 'final_status' ),
			'A refused status still overwrote the stored value.'
		);
	}

	/**
	 * Statuses nothing downstream could interpret.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function invalid_status_provider() {
		return array(
			'invented'   => array( 'exploded' ),
			'empty'      => array( '' ),
			'whitespace' => array( '   ' ),
			'near miss'  => array( 'sending' ),
		);
	}

	/**
	 * An unknown delivery id is refused rather than silently updating nothing
	 * and reporting success.
	 *
	 * @return void
	 */
	public function test_final_status_rejects_a_bad_delivery_id() {
		$this->assertFalse( $this->repo->set_final_status( 0, 'sent' ) );
		$this->assertFalse( $this->repo->set_final_status( -1, 'sent' ) );
	}

	/**
	 * Case and padding are normalised before the allowlist check, matching how
	 * the identity hash normalises its components.
	 *
	 * @return void
	 */
	public function test_final_status_is_normalised_before_the_allowlist_check() {
		$claim = $this->repo->claim( $this->fake_order_id(), 84, 'insert', 'status:processing' );
		$this->track_delivery( $claim['delivery_id'] );

		$this->assertTrue( $this->repo->set_final_status( $claim['delivery_id'], '  SENT  ' ) );
		$this->assertSame( 'sent', (string) $this->delivery_column( $claim['delivery_id'], 'final_status' ) );
	}
}
