/**
 * Login gate coverage.
 *
 * approve-user.spec.js already covers the pending-user block and the approved
 * user being able to reach wp-admin. This spec adds the unapproved case and
 * asserts that the rejection path adds the shake error class to the form (per
 * shake_error_codes() registering wpau_confirmation_error).
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

async function attemptLogin( page, username, password ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
}

test.describe.serial( 'WP Approve User — login gate', () => {
	const stamp = Date.now();
	const unapprovedUser = `wpau-login-unapproved-${ stamp }`;
	const pendingUser = `wpau-login-pending-${ stamp }`;
	const noMetaUser = `wpau-login-no-meta-${ stamp }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
		wp(
			`user create ${ unapprovedUser } ${ unapprovedUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ unapprovedUser } wp-approve-user unapproved` );

		wp(
			`user create ${ pendingUser } ${ pendingUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ pendingUser } wp-approve-user pending` );

		// Create a user and explicitly strip any wp-approve-user meta so the
		// login path has to hit the empty-meta branch in wp_authenticate_user.
		// Covers the silent-lockout regression from
		// https://github.com/obenland/wp-approve-user/issues/60 item 10.
		wp(
			`user create ${ noMetaUser } ${ noMetaUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta delete ${ noMetaUser } wp-approve-user` );
	} );

	test.afterAll( () => {
		for ( const user of [ unapprovedUser, pendingUser, noMetaUser ] ) {
			try {
				wp( `user delete ${ user } --yes` );
			} catch {
				// Best-effort cleanup.
			}
		}
	} );

	test( 'unapproved user is blocked from logging in', async ( { page } ) => {
		await attemptLogin( page, unapprovedUser, password );

		await expect( page.locator( '#login_error' ) ).toContainText(
			'Your account must be confirmed before you can log in.'
		);
		// We never reached wp-admin.
		await expect( page ).toHaveURL( /wp-login\.php/ );
	} );

	test( 'rejection adds the shake class to the login form', async ( {
		page,
	} ) => {
		await attemptLogin( page, pendingUser, password );

		// shake_error_codes() registers wpau_confirmation_error, which causes
		// core to inject an inline script that adds the shake class to the
		// first form on the page.
		await expect( page.locator( '#loginform' ) ).toHaveClass( /shake/ );
	} );

	test( 'user with no wp-approve-user meta can still log in', async ( {
		page,
	} ) => {
		await attemptLogin( page, noMetaUser, password );

		// Successful login lands in wp-admin — the empty-meta branch is the
		// only thing that lets this request through, since the user has no
		// 'approved' meta to match the pre-fix condition.
		await expect( page ).toHaveURL( /wp-admin/ );
	} );
} );
