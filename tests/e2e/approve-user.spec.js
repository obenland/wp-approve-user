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

test.describe.serial( 'WP Approve User', () => {
	const username = `wpau-e2e-${ Date.now() }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
		wp(
			`user create ${ username } ${ username }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ username } wp-approve-user pending` );
	} );

	test.afterAll( () => {
		wp( `user delete ${ username } --yes` );
	} );

	test( 'pending user is blocked from logging in', async ( { page } ) => {
		await loginAs( page, username, password );
		await expect( page.locator( '#login_error' ) ).toContainText(
			'Your account must be confirmed before you can log in.'
		);
	} );

	test( 'admin approves pending user via row action', async ( { page } ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php?role=wpau_pending' );

		const userRow = page.locator( '#the-list tr', { hasText: username } );
		await expect( userRow ).toBeVisible();

		// WP core hides row actions via `visibility: hidden` until the row is hovered.
		await userRow.hover();
		await userRow.locator( 'a.submitapprove' ).click();

		await expect(
			page.locator( '#setting-error-wpau-approved' )
		).toContainText( /user approved\./i );

		await page.goto( '/wp-admin/users.php?role=wpau_pending' );
		await expect(
			page.locator( '#the-list tr', { hasText: username } )
		).toHaveCount( 0 );
	} );

	test( 'approved user can access wp-admin', async ( { page } ) => {
		await loginAs( page, username, password );
		await expect( page ).toHaveURL( /\/wp-admin\// );
	} );
} );
