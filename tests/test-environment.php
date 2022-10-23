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
		$id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => 'roy',
				'post_content' => 'sivan',
				'context'      => 'test',
			)
		);
		$this->assertTrue( is_numeric( $id ) );
	}
}
