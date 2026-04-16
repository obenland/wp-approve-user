/**
 * Row action coverage for the users.php screen.
 *
 * Complements approve-user.spec.js (which already covers the pending → approve
 * row action) by exercising the unapprove → approve flow, the approved →
 * unapprove flow, the per-status visibility rules, and the rule that the
 * current user never sees row actions on their own row.
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

test.describe.serial( 'WP Approve User — row action visibility', () => {
	const stamp = Date.now();
	const pendingUser = `wpau-row-pending-${ stamp }`;
	const approvedUser = `wpau-row-approved-${ stamp }`;
	const unapprovedUser = `wpau-row-unapproved-${ stamp }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
		wp(
			`user create ${ pendingUser } ${ pendingUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ pendingUser } wp-approve-user pending` );

		wp(
			`user create ${ approvedUser } ${ approvedUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ approvedUser } wp-approve-user approved` );

		wp(
			`user create ${ unapprovedUser } ${ unapprovedUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ unapprovedUser } wp-approve-user unapproved` );
	} );

	test.afterAll( () => {
		for ( const user of [ pendingUser, approvedUser, unapprovedUser ] ) {
			try {
				wp( `user delete ${ user } --yes` );
			} catch {
				// Cleanup is best-effort.
			}
		}
	} );

	test( 'pending user shows both Approve and Unapprove row links', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php?role=wpau_pending' );

		const userRow = page.locator( '#the-list tr', {
			hasText: pendingUser,
		} );
		await expect( userRow ).toBeVisible();
		await userRow.hover();

		await expect( userRow.locator( 'a.submitapprove' ) ).toBeVisible();
		await expect( userRow.locator( 'a.submitunapprove' ) ).toBeVisible();
	} );

	test( 'approved user only shows the Unapprove row link', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php' );

		const userRow = page.locator( '#the-list tr', {
			hasText: approvedUser,
		} );
		await expect( userRow ).toBeVisible();
		await userRow.hover();

		await expect( userRow.locator( 'a.submitapprove' ) ).toHaveCount( 0 );
		await expect( userRow.locator( 'a.submitunapprove' ) ).toBeVisible();
	} );

	test( 'unapproved user only shows the Approve row link', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php?role=wpau_unapproved' );

		const userRow = page.locator( '#the-list tr', {
			hasText: unapprovedUser,
		} );
		await expect( userRow ).toBeVisible();
		await userRow.hover();

		await expect( userRow.locator( 'a.submitapprove' ) ).toBeVisible();
		await expect( userRow.locator( 'a.submitunapprove' ) ).toHaveCount( 0 );
	} );

	test( 'admin does not see approve/unapprove links on their own row', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php' );

		const adminRow = page
			.locator( '#the-list tr', { hasText: 'admin' } )
			.first();
		await expect( adminRow ).toBeVisible();
		await adminRow.hover();

		await expect( adminRow.locator( 'a.submitapprove' ) ).toHaveCount( 0 );
		await expect( adminRow.locator( 'a.submitunapprove' ) ).toHaveCount(
			0
		);
	} );

	test( 'admin approves an unapproved user via the row action', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php?role=wpau_unapproved' );

		const userRow = page.locator( '#the-list tr', {
			hasText: unapprovedUser,
		} );
		await expect( userRow ).toBeVisible();
		await userRow.hover();
		await userRow.locator( 'a.submitapprove' ).click();

		await expect(
			page.locator( '#setting-error-wpau-approved' )
		).toContainText( /user approved\./i );

		// Verify the meta state actually flipped.
		const meta = wp( `user meta get ${ unapprovedUser } wp-approve-user` );
		expect( meta ).toBe( 'approved' );
	} );

	test( 'admin unapproves an approved user via the row action', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php' );

		const userRow = page.locator( '#the-list tr', {
			hasText: approvedUser,
		} );
		await expect( userRow ).toBeVisible();
		await userRow.hover();
		await userRow.locator( 'a.submitunapprove' ).click();

		await expect(
			page.locator( '#setting-error-wpau-unapproved' )
		).toContainText( /user unapproved\./i );

		const meta = wp( `user meta get ${ approvedUser } wp-approve-user` );
		expect( meta ).toBe( 'unapproved' );
	} );
} );
