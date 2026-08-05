<?php
/**
 * A guarded write reports a FACT, not a boolean (ADR-0015 §8.1a, gate 21).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\WriteResult;

/**
 * The value object, and the source-level guarantee that nothing re-merges its
 * outcomes.
 *
 * ⚠ WHY THE SECOND HALF EXISTS. The three call sites Prompt 7B fixed are covered
 * behaviourally by `ScheduledIntegrityTest`, which makes real statements fail. That
 * proves the code as written; it cannot prove anything about the call site somebody
 * adds next month. The scan below is the same shape as the collection census: a new
 * boolean-context use of a guarded write is a FAILING TEST rather than a defect
 * somebody has to notice.
 */
final class WriteResultTest extends UnitTestCase {

	/**
	 * Repository methods whose result may never be read as a boolean.
	 *
	 * @var string[]
	 */
	const GUARDED_WRITES = array( 'transition', 'arm_scheduled', 'record_scheduled' );

	/**
	 * The four outcomes exist, are distinct, and answer the questions callers ask.
	 *
	 * @return void
	 */
	public function test_the_four_outcomes_are_distinct_and_classified() {
		$this->assertSame(
			array( 'changed', 'lost_race', 'query_failed', 'refused' ),
			WriteResult::OUTCOMES,
			'the outcome set changed without this assertion being updated'
		);

		$changed = WriteResult::changed( 'scheduled -> executing' );
		$lost    = WriteResult::lost( 'scheduled -> executing' );
		$failed  = WriteResult::failed( 'scheduled -> executing' );
		$refused = WriteResult::refused( 'executing -> scheduled' );

		$this->assertTrue( $changed->won() );
		$this->assertFalse( $lost->won() );
		$this->assertFalse( $failed->won() );
		$this->assertFalse( $refused->won() );

		// ⚠ THE DISTINCTION THE WHOLE TYPE EXISTS FOR: a lost race is somebody
		// else's success, and only the other two mean nobody owns the delivery.
		$this->assertFalse( $changed->is_shortfall() );
		$this->assertFalse( $lost->is_shortfall(), 'a lost race must never read as a shortfall' );
		$this->assertTrue( $failed->is_shortfall() );
		$this->assertTrue( $refused->is_shortfall() );

		$this->assertTrue( $lost->is_lost_race() );
		$this->assertTrue( $failed->is_query_failed() );
		$this->assertTrue( $refused->is_refused() );

		$this->assertSame( WriteResult::CHANGED, $changed->outcome() );
		$this->assertSame( 'scheduled -> executing', $changed->detail() );
		$this->assertSame( 'query_failed (scheduled -> executing)', $failed->describe() );
		$this->assertSame( 'changed', WriteResult::changed()->describe(), 'a detail-less result still describes itself' );
	}

	/**
	 * ⚠ EVERY GUARDED WRITE'S RESULT IS USED AS A RESULT, NEVER AS A BOOLEAN.
	 *
	 * The forbidden shapes are the ones the defect actually took: `if ( ! $x->…() )`,
	 * `$a = $x->…() && …`, `$x->…() ? … : …`. Each of them collapses `lost_race` and
	 * `query_failed` into one branch, which is the thing this type exists to prevent.
	 *
	 * @return void
	 */
	public function test_no_source_file_reads_a_guarded_write_as_a_boolean() {
		$checked = 0;

		foreach ( $this->source_files() as $path ) {
			$code = $this->strip_comments( (string) file_get_contents( $path ) );

			foreach ( self::GUARDED_WRITES as $method ) {
				$offset = 0;

				while ( true ) {
					$at = strpos( $code, '->' . $method . '(', $offset );

					if ( false === $at ) {
						break;
					}

					$offset = $at + 1;

					// The declarations themselves, and calls on something that is not
					// a repository or logger, are not call sites of interest.
					if ( false !== strpos( substr( $code, max( 0, $at - 60 ), 60 ), 'function ' ) ) {
						continue;
					}

					$close = $this->closing_paren( $code, $at + strlen( '->' . $method ) );

					if ( null === $close ) {
						continue;
					}

					++$checked;

					$before = rtrim( substr( $code, 0, $at ) );
					$after  = ltrim( substr( $code, $close + 1 ) );

					// USED AS A RESULT: a method is called on it, or it is bound to a
					// name that later code has to interrogate.
					if ( 0 === strpos( $after, '->' ) ) {
						continue;
					}

					$statement = substr( $before, strrpos( $before, ';' ) === false ? 0 : (int) strrpos( $before, ';' ) );

					$this->assertMatchesRegularExpression(
						'/(=|return|,|\()\s*[^;]*$/',
						$statement,
						"a guarded write's result is discarded in {$path}"
					);

					$this->assertDoesNotMatchRegularExpression(
						'/(!|&&|\|\|)\s*\$[A-Za-z_>\-\[\]\'"$\w]*$/',
						$before,
						"a guarded write is read as a boolean in {$path} — a lost race and a failed query are not the same fact"
					);

					$this->assertDoesNotMatchRegularExpression(
						'/^(\?|&&|\|\|)/',
						$after,
						"a guarded write is combined into a boolean expression in {$path}"
					);
				}
			}
		}

		// The eight call sites that exist today: one lease, one arm, one arming
		// caller, and five terminal writes inside the logger. A scan that finds
		// fewer is a scan that has stopped looking where the code is.
		$this->assertGreaterThanOrEqual(
			8,
			$checked,
			'the scan found almost no guarded writes, so it is not looking where the code is'
		);

		fwrite( STDERR, "\n[7B gate 21] {$checked} guarded-write call sites scanned: none read as a boolean\n" );
	}

	/**
	 * Every production PHP file.
	 *
	 * @return string[]
	 */
	private function source_files(): array {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$files = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		$this->assertNotEmpty( $files, 'no source files were found to scan' );

		return $files;
	}

	/**
	 * Strip comments, so a docblock describing the old boolean cannot fail the scan.
	 *
	 * @param string $code PHP source.
	 * @return string
	 */
	private function strip_comments( string $code ): string {
		$out = '';

		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out .= "\n";
				continue;
			}

			$out .= is_array( $token ) ? $token[1] : $token;
		}

		return $out;
	}

	/**
	 * The offset of the `)` closing the `(` at or after a position.
	 *
	 * @param string $code PHP source.
	 * @param int    $from Offset of the opening paren.
	 * @return int|null
	 */
	private function closing_paren( string $code, int $from ): ?int {
		$depth = 0;
		$len   = strlen( $code );

		for ( $i = $from; $i < $len; $i++ ) {
			if ( '(' === $code[ $i ] ) {
				++$depth;
				continue;
			}

			if ( ')' === $code[ $i ] ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return null;
	}
}
