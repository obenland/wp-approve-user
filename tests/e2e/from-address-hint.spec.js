/**
 * "Change From Address" hint coverage.
 *
 * A dismissible admin notice at the top of the Approve User settings page
 * points admins at the companion "Change From Address" plugin when they want
 * to customize the sender of approval emails. This spec verifies:
 *
 *   - The hint renders with an action button (install or activate) and core's
 *     × dismiss control when the companion plugin isn't active.
 *   - Dismissing via the × hides the hint and the dismissal persists across
 *     reloads (recorded in the background by the hint script).
 *
 * The dismissal is recorded in per-user meta, so the spec resets that meta
 * before and after to stay independent of run order.
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

function resetDismissal() {
	try {
		wp(
			'user meta delete admin wp-approve-user-from-address-hint-dismissed'
		);
	} catch ( err ) {
		/*
		 * wp-cli exits non-zero when the meta key doesn't exist — expected on a
		 * clean run. Anything else (env down, container missing) is worth a
		 * breadcrumb rather than silence.
		 */
		console.warn(
			`resetDismissal: meta delete returned non-zero (likely "key not set"): ${ err.message }`
		);
	}
}

async function loginAs( page, username, password ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	// Wait for the post-login redirect so the auth cookie is set before the
	// test navigates on; otherwise the next goto can race as logged-out.
	await Promise.all( [
		page.waitForURL( '**/wp-admin/**' ),
		page.locator( '#wp-submit' ).click(),
	] );
}

const SETTINGS_URL = '/wp-admin/options-general.php?page=wp-approve-user';

test.describe.serial( 'WP Approve User — Change From Address hint', () => {
	test.beforeAll( resetDismissal );
	test.afterAll( resetDismissal );

	test( 'shows the hint with an action button and a dismiss control', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( SETTINGS_URL );

		const hint = page.locator( '.wpau-from-address-hint' );
		await expect( hint ).toBeVisible();
		await expect( hint ).toContainText( 'Change From Address' );

		// Admin can install plugins, so the primary action points at the
		// install-plugin route for the change-from-address slug.
		const action = hint.locator( 'a.button' );
		await expect( action ).toBeVisible();
		await expect( action ).toHaveAttribute(
			'href',
			/plugin=change-from-address/
		);

		// With JavaScript on, core's × button is the dismiss control and the
		// no-JS text link is hidden by the hint script.
		await expect( hint.locator( 'button.notice-dismiss' ) ).toBeVisible();
		await expect(
			hint.locator( 'a.wpau-dismiss-from-address-hint' )
		).toBeHidden();
	} );

	test( 'dismissing via the × persists across reloads', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( SETTINGS_URL );

		const hint = page.locator( '.wpau-from-address-hint' );

		// The × records the dismissal in the background, then core removes the
		// notice. Wait for that request so the reload sees the persisted state.
		const dismissed = page.waitForRequest( ( request ) =>
			request.url().includes( 'wpau_dismiss_from_address_hint' )
		);
		await hint.locator( 'button.notice-dismiss' ).click();
		await dismissed;

		await expect( hint ).toHaveCount( 0 );

		// And it stays gone on a fresh load.
		await page.goto( SETTINGS_URL );
		await expect( page.locator( '.wpau-from-address-hint' ) ).toHaveCount(
			0
		);
	} );
} );
