/**
 * Settings page coverage.
 *
 * approve-user.spec.js already smoke-tests that the settings page renders and
 * that the approve-email textarea persists. This spec adds:
 *
 *   - All four fields are present.
 *   - Saving persists every field.
 *   - Placeholder help text mentions the documented tokens.
 *   - When "Send Approve Email" is enabled, approving a user actually sets
 *     the wp-approve-user-mail-sent meta (the most reliable signal that the
 *     wp_mail() call ran without standing up a mail server).
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

// Update the wp-approve-user option from a JS object without going through a
// shell-quoted JSON string (the default email bodies can contain single
// quotes). We base64-encode and decode inside a wp eval call.
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

async function loginAs( page, username, password ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
}

const SETTINGS_URL = '/wp-admin/options-general.php?page=wp-approve-user';

test.describe( 'WP Approve User — settings page rendering', () => {
	test( 'renders all four fields and the placeholder help text', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( SETTINGS_URL );

		await expect(
			page.locator( 'h2', { hasText: 'Approve User Settings' } )
		).toBeVisible();

		await expect(
			page.locator( '#wpau-send-approve-email' )
		).toBeVisible();
		await expect( page.locator( '#wpau-approve-email' ) ).toBeVisible();
		await expect(
			page.locator( '#wpau-send-unapprove-email' )
		).toBeVisible();
		await expect( page.locator( '#wpau-unapprove-email' ) ).toBeVisible();
		await expect( page.locator( '#wpau-notify-admin' ) ).toBeVisible();

		// section_description_cb() lists the placeholder tokens as <code>
		// elements inside the section description (immediately after the
		// "Email contents" heading and before the form table).
		const wrap = page.locator( '.wrap' );
		await expect(
			wrap.locator( 'code', { hasText: /^USERNAME$/ } )
		).toHaveCount( 1 );
		await expect(
			wrap.locator( 'code', { hasText: /^BLOG_TITLE$/ } )
		).toHaveCount( 1 );
		await expect(
			wrap.locator( 'code', { hasText: /^BLOG_URL$/ } )
		).toHaveCount( 1 );
		await expect(
			wrap.locator( 'code', { hasText: /^LOGINLINK$/ } )
		).toHaveCount( 1 );
	} );
} );

test.describe.serial( 'WP Approve User — settings persistence', () => {
	let original = null;

	test.beforeAll( () => {
		original = getWpauOption();
	} );

	test.afterAll( () => {
		try {
			if ( original ) {
				setWpauOption( original );
			} else {
				wp( 'option delete wp-approve-user' );
			}
		} catch {
			// Best-effort restore.
		}
	} );

	test( 'saving persists all four fields', async ( { page } ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( SETTINGS_URL );

		const approveBody = `Approved USERNAME for BLOG_TITLE — visit BLOG_URL or login at LOGINLINK.`;
		const unapproveBody = `Sorry USERNAME, your registration for BLOG_TITLE was declined.`;

		await page.locator( '#wpau-send-approve-email' ).check();
		await page.locator( '#wpau-approve-email' ).fill( approveBody );
		await page.locator( '#wpau-send-unapprove-email' ).check();
		await page.locator( '#wpau-unapprove-email' ).fill( unapproveBody );

		await page.locator( '#submit' ).click();
		await expect(
			page.locator( '#setting-error-settings_updated' )
		).toBeVisible();

		// Reload the page and confirm every field round-tripped.
		await page.goto( SETTINGS_URL );
		await expect(
			page.locator( '#wpau-send-approve-email' )
		).toBeChecked();
		await expect(
			page.locator( '#wpau-send-unapprove-email' )
		).toBeChecked();
		await expect( page.locator( '#wpau-approve-email' ) ).toHaveValue(
			approveBody
		);
		await expect( page.locator( '#wpau-unapprove-email' ) ).toHaveValue(
			unapproveBody
		);
	} );

	test( 'toggling the admin notification setting persists across reloads', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( SETTINGS_URL );

		// Default is true — uncheck, save, and verify the off state persists.
		await page.locator( '#wpau-notify-admin' ).uncheck();
		await page.locator( '#submit' ).click();
		await expect(
			page.locator( '#setting-error-settings_updated' )
		).toBeVisible();

		await page.goto( SETTINGS_URL );
		await expect( page.locator( '#wpau-notify-admin' ) ).not.toBeChecked();

		// Check it back on and verify the on state persists.
		await page.locator( '#wpau-notify-admin' ).check();
		await page.locator( '#submit' ).click();
		await expect(
			page.locator( '#setting-error-settings_updated' )
		).toBeVisible();

		await page.goto( SETTINGS_URL );
		await expect( page.locator( '#wpau-notify-admin' ) ).toBeChecked();
	} );
} );

test.describe.serial( 'WP Approve User — approval email dispatch flag', () => {
	const stamp = Date.now();
	const username = `wpau-mail-${ stamp }`;
	const password = 'Correct-Horse-Battery-Staple-1';
	let original = null;

	test.beforeAll( () => {
		original = getWpauOption();
		// Enable the approve email and provide a body that exercises every
		// placeholder so the populate_message() path runs.
		setWpauOption( {
			'wpau-send-approve-email': true,
			'wpau-send-unapprove-email': true,
			'wpau-approve-email':
				'Hi USERNAME, welcome to BLOG_TITLE (BLOG_URL). Login: LOGINLINK',
			'wpau-unapprove-email':
				'Sorry USERNAME, your BLOG_TITLE registration was declined.',
		} );

		wp(
			`user create ${ username } ${ username }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ username } wp-approve-user pending` );
	} );

	test.afterAll( () => {
		try {
			wp( `user delete ${ username } --yes` );
		} catch {
			// Best-effort.
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
	} );

	test( 'approving with email enabled sends mail and sets the mail-sent meta', async () => {
		// We exercise the wpau_approve action programmatically and stub
		// wp_mail() via pre_wp_mail so we don't depend on the wp-env mail
		// transport actually being able to deliver. The stub captures the
		// recipient and subject, and short-circuits to a successful return,
		// which is what makes the plugin set wp-approve-user-mail-sent.
		const userId = parseInt(
			wp( `user get ${ username } --field=ID` ),
			10
		);
		expect( Number.isFinite( userId ) ).toBe( true );

		const php = `add_filter('pre_wp_mail', function($null, $atts) { update_option('wpau_test_last_mail_to', is_array($atts['to']) ? $atts['to'][0] : $atts['to']); update_option('wpau_test_last_mail_subject', $atts['subject']); update_option('wpau_test_last_mail_body', $atts['message']); return true; }, 10, 2); update_user_meta(${ userId }, 'wp-approve-user', 'approved'); do_action('wpau_approve', ${ userId });`;
		const encoded = Buffer.from( php ).toString( 'base64' );
		execSync(
			`npx wp-env run cli wp eval ${ JSON.stringify(
				`eval(base64_decode('${ encoded }'));`
			) }`,
			{ stdio: [ 'ignore', 'pipe', 'inherit' ] }
		);

		// Plugin should have flipped the mail-sent meta now that wp_mail()
		// returned true via our stub.
		expect(
			wp( `user meta get ${ username } wp-approve-user-mail-sent` )
		).toBe( '1' );

		// And the recipient + a placeholder-substituted body should be
		// recorded.
		expect( wp( `option get wpau_test_last_mail_to` ) ).toBe(
			`${ username }@example.test`
		);
		const body = wp( `option get wpau_test_last_mail_body` );
		expect( body ).toContain( username );
		expect( body ).not.toContain( 'USERNAME' );

		// Cleanup the assertion options.
		try {
			wp( `option delete wpau_test_last_mail_to` );
			wp( `option delete wpau_test_last_mail_subject` );
			wp( `option delete wpau_test_last_mail_body` );
		} catch {
			// Best-effort.
		}
	} );
} );
