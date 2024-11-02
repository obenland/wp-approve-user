<?php
/**
 * Tests for upgrade routines.
 *
 * @package WP Approve User
 */

// Load upgrade routines.
require_once __DIR__ . '/../upgrade.php';

/**
 * Test upgrade routine.
 */
class Test_Upgrade_Routine extends WP_UnitTestCase {

	/**
	 * Factory instance.
	 *
	 * @var WP_UnitTest_Factory
	 */
	public $factory;

	/**
	 * Test user IDs.
	 *
	 * @var array
	 */
	private $test_users = array();

	/**
	 * Set up test environment before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Create test users with different approval states.
		$this->test_users[] = $this->create_test_user_with_meta( true );  // Approved user.
		$this->test_users[] = $this->create_test_user_with_meta( true );  // Another approved user.
		$this->test_users[] = $this->create_test_user_with_meta( false ); // Pending user.
		$this->test_users[] = $this->create_test_user_with_meta( false ); // Another pending user.
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Delete test users and their meta.
		foreach ( $this->test_users as $user_id ) {
			delete_user_meta( $user_id, 'wp-approve-user' );
			wp_delete_user( $user_id );
		}

		$this->test_users = array();

		delete_site_option( 'wpau_db_version' );
		unset( $GLOBALS['wpau_db_version'] );

		parent::tear_down();
	}

	/**
	 * Helper function to create a test user with approval meta
	 *
	 * @param bool $approved Whether the user should be approved.
	 * @return int User ID
	 */
	private function create_test_user_with_meta( $approved ) {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		update_user_meta( $user_id, 'wp-approve-user', $approved );

		return $user_id;
	}

	/**
	 * Test upgrade is skipped when versions match.
	 *
	 * @covers ::wpau_upgrade_all
	 */
	public function test_upgrade_all_no_upgrade_needed() {
		// Store original meta values.
		$original_values = array();
		foreach ( $this->test_users as $user_id ) {
			$original_values[ $user_id ] = get_user_meta( $user_id, 'wp-approve-user', true );
		}

		// Set current version equal to required version.
		update_site_option( 'wpau_db_version', 12 );

		// Run upgrade.
		wpau_upgrade_all();

		// Verify no changes were made by comparing with original values.
		foreach ( $this->test_users as $user_id ) {
			$current_value = get_user_meta( $user_id, 'wp-approve-user', true );
			$this->assertSame(
				$original_values[ $user_id ],
				$current_value,
				"Meta value for user $user_id should remain unchanged"
			);
		}
	}

	/**
	 * Test upgrade process when needed.
	 *
	 * @covers ::wpau_upgrade_all
	 */
	public function test_upgrade_all_performs_upgrade() {
		$GLOBALS['wpau_db_version'] = 12;

		// Run upgrade.
		wpau_upgrade_all();

		// Verify approved users were updated.
		$approved_users = array_slice( $this->test_users, 0, 2 );
		foreach ( $approved_users as $user_id ) {
			$meta_value = get_user_meta( $user_id, 'wp-approve-user', true );
			$this->assertEquals( 'approved', $meta_value, 'User should be marked as approved' );
		}

		// Verify pending users were updated.
		$pending_users = array_slice( $this->test_users, 2, 2 );
		foreach ( $pending_users as $user_id ) {
			$meta_value = get_user_meta( $user_id, 'wp-approve-user', true );
			$this->assertEquals( 'pending', $meta_value, 'User should be marked as pending' );
		}

		// Verify database version was updated.
		$this->assertEquals( 12, get_site_option( 'wpau_db_version' ), 'Database version should be updated' );
	}

	/**
	 * Test direct upgrade to version 12.
	 *
	 * @covers ::wpau_upgrade_to_12
	 */
	public function test_upgrade_to_12() {
		// Run the upgrade function directly.
		wpau_upgrade_to_12();

		// Check approved users.
		$approved_users = array_slice( $this->test_users, 0, 2 );
		foreach ( $approved_users as $user_id ) {
			$meta_value = get_user_meta( $user_id, 'wp-approve-user', true );
			$this->assertEquals( 'approved', $meta_value, 'Boolean true should be converted to "approved"' );
		}

		// Check pending users.
		$pending_users = array_slice( $this->test_users, 2, 2 );
		foreach ( $pending_users as $user_id ) {
			$meta_value = get_user_meta( $user_id, 'wp-approve-user', true );
			$this->assertEquals( 'pending', $meta_value, 'Boolean false should be converted to "pending"' );
		}
	}

	/**
	 * Test upgrade with empty meta values.
	 *
	 * @covers ::wpau_upgrade_to_12
	 */
	public function test_upgrade_with_empty_meta() {
		// Create user with empty meta value.
		$user_id            = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->test_users[] = $user_id;
		update_user_meta( $user_id, 'wp-approve-user', '' );

		// Run upgrade.
		wpau_upgrade_to_12();

		// Empty values should become 'pending'.
		$meta_value = get_user_meta( $user_id, 'wp-approve-user', true );
		$this->assertEquals( 'pending', $meta_value, 'Empty meta value should be converted to pending' );
	}
}
