/**
 * Dashboard widget coverage.
 *
 * Seeds a pending user, loads /wp-admin/, and verifies the "Pending User
 * Approvals" widget renders with the expected count and action link.
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

async function loginAs( page, username, password ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
}

test.describe.serial( 'WP Approve User — dashboard widget', () => {
	const username = `wpau-dash-${ Date.now() }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
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
	} );

	test( 'widget renders with a pending count and review-pending link', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/' );

		const widget = page.locator( '#wpau_pending_users' );
		await expect( widget ).toBeVisible();
		await expect( widget.locator( 'h2, .hndle' ).first() ).toContainText(
			'Pending User Approvals'
		);
		await expect( widget ).toContainText( /awaiting approval/ );

		const reviewLink = widget.locator(
			'a[href*="users.php?role=wpau_pending"]'
		);
		await expect( reviewLink ).toBeVisible();
	} );
} );
