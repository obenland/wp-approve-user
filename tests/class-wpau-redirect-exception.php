<?php
/**
 * Exception thrown by the wp_redirect filter override used in PHPUnit tests.
 *
 * @package wp-approve-user
 */

/**
 * Captures a redirect location without letting the production `exit()` run.
 *
 * Tests add a `wp_redirect` filter callback that throws this exception, so
 * the caller can inspect the intended target URL via `$exception->location`
 * instead of having PHP terminate mid-test.
 */
class WPAU_Redirect_Exception extends \Exception {

	/**
	 * Redirect location captured from `wp_safe_redirect()`.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Constructor.
	 *
	 * @param string $location Redirect location.
	 */
	public function __construct( $location ) {
		parent::__construct( 'redirect: ' . $location );
		$this->location = $location;
	}
}
