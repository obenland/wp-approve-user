<?php
/**
 * Environment test file.
 *
 * @package wp-approve-user
 */

use PHPUnit\Framework\TestCase;

/**
 * These tests prove phpunit test setup works.
 */
class EnvironmentTest extends TestCase {


	/**
	 * This tests makes sure:
	 *
	 * - WordPress functions are defined
	 * - WordPress database can be written to.
	 */
	public function test_wordpress() {
		global $wpdb;

		$this->assertTrue( is_object( $wpdb ) );
		$success = add_option( 'test', 'test' );
		$this->assertTrue( $success );
		$this->assertSame( 'test', get_option( 'test' ) );
	}
}
