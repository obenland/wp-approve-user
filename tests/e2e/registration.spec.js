/**
 * Registration flow coverage.
 *
 * approve-user.spec.js already verifies that the registration form lands a
 * fresh user in the pending bucket. This spec adds:
 *
 *   - the wp-approve-user-new-registration meta is also set,
 *   - the post-registration message tells the user to wait for approval,
 *   - deleting an unapproved new registration triggers the unapprove email
 *     code path. We assert by using a `wp eval` call to add a `pre_wp_mail`
 *     filter that records the most recent recipient into an option, so the
 *     test can verify `wp_mail()` was reached without a real SMTP round-trip.
 */
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'node:child_process' );

function wp( args ) {
	return execSync( `npx wp-env run cli wp ${ args }`, {
		stdio: [ 'ignore', 'pipe', 'inherit' ],
	} )
		.toString()
		.trim();
}

function setWpauOption( value ) {
	const encoded = Buffer.from( JSON.stringify( value ) ).toString( 'base64' );
	const php = `update_option('wp-approve-user', json_decode(base64_decode('${ encoded }'), true));`;
	execSync( `npx wp-env run cli wp eval ${ JSON.stringify( php ) }`, {
		stdio: [ 'ignore', 'pipe', 'inherit' ],
	} );
}

function getWpauOption() {
	try {
		const raw = wp( 'option get wp-approve-user --format=json' );
		return JSON.parse( raw );
	} catch {
		// Option may not exist in the DB yet (plugin defaults are lazy).
		return null;
	}
}

test.describe( 'WP Approve User — registration meta and message', () => {
	test( 'fresh registration sets the new-registration meta and shows approval message', async ( {
		page,
	} ) => {
		const username = `wpau-reg-${ Date.now() }`;

		try {
			await page.goto( '/wp-login.php?action=register' );
			await page.locator( '#user_login' ).fill( username );
			await page
				.locator( '#user_email' )
				.fill( `${ username }@example.test` );
			await page.locator( '#wp-submit' ).click();

			// wp_login_errors() rewrites the "registered" message into one that
			// mentions administrator approval.
			await expect( page.locator( '#login .message' ) ).toContainText(
				/approval|confirmed by an administrator/i
			);

			// user_register() drops both the status and the new-registration
			// flag onto the user.
			expect( wp( `user meta get ${ username } wp-approve-user` ) ).toBe(
				'pending'
			);
			expect(
				wp(
					`user meta get ${ username } wp-approve-user-new-registration`
				)
			).toBe( '1' );
		} finally {
			try {
				wp( `user delete ${ username } --yes` );
			} catch {
				// Best-effort.
			}
		}
	} );
} );

test.describe
	.serial( 'WP Approve User — delete_user triggers unapprove email path', () => {
	const stamp = Date.now();
	const username = `wpau-del-${ stamp }`;
	let original = null;

	test.beforeAll( () => {
		original = getWpauOption();
		setWpauOption( {
			'wpau-send-approve-email': false,
			'wpau-send-unapprove-email': true,
			'wpau-approve-email': '',
			'wpau-unapprove-email':
				'Hi USERNAME, your registration was declined.',
		} );

		// Create a "newly registered" user we can flip to unapproved and
		// then delete to exercise delete_user().
		wp(
			`user create ${ username } ${ username }@example.test --role=subscriber --user_pass=Correct-Horse-Battery-Staple-1 --porcelain`
		);
		wp( `user meta update ${ username } wp-approve-user unapproved` );
		wp(
			`user meta update ${ username } wp-approve-user-new-registration 1`
		);

		// Reset the wp_mail tracking option used as the assertion target.
		try {
			wp( `option delete wpau_test_last_mail_to` );
		} catch {
			// Already absent.
		}
	} );

	test.afterAll( () => {
		try {
			wp( `user delete ${ username } --yes` );
		} catch {
			// Already deleted by the test in most runs.
		}
		try {
			if ( original ) {
				setWpauOption( original );
			} else {
				wp( 'option delete wp-approve-user' );
			}
		} catch {
			// Best-effort.
		}
		try {
			wp( `option delete wpau_test_last_mail_to` );
		} catch {
			// Best-effort.
		}
	} );

	test( 'deleting an unapproved new registration calls wp_mail()', async () => {
		// Resolve the user ID first.
		const userId = parseInt(
			wp( `user get ${ username } --field=ID` ),
			10
		);
		expect( Number.isFinite( userId ) ).toBe( true );

		// Stub wp_mail() via wp-cli eval. We hook pre_wp_mail with a
		// closure that records the recipient into an option and short
		// circuits the actual send. The hook only lives for the duration
		// of this single eval invocation, so it never persists.
		//
		// PHP source is base64-encoded so the surrounding shell never
		// sees the dollar signs in the closure parameters.
		const php = `add_filter('pre_wp_mail', function($null, $atts) { update_option('wpau_test_last_mail_to', is_array($atts['to']) ? $atts['to'][0] : $atts['to']); return true; }, 10, 2); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user(${ userId });`;
		const encoded = Buffer.from( php ).toString( 'base64' );
		const wrapper = `eval(base64_decode('${ encoded }'));`;
		execSync( `npx wp-env run cli wp eval ${ JSON.stringify( wrapper ) }`, {
			stdio: [ 'ignore', 'pipe', 'inherit' ],
		} );

		expect( wp( `option get wpau_test_last_mail_to` ) ).toBe(
			`${ username }@example.test`
		);
	} );
} );
