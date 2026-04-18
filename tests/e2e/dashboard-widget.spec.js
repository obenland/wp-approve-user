/**
 * Dashboard widget coverage.
 *
 * Seeds pending users, loads /wp-admin/, and drives the inline approve/reject
 * actions end-to-end through the AJAX handlers.
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
	const stamp = Date.now();
	const approveUser = `wpau-dash-approve-${ stamp }`;
	const rejectUser = `wpau-dash-reject-${ stamp }`;
	const extraUser = `wpau-dash-extra-${ stamp }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
		for ( const username of [ approveUser, rejectUser, extraUser ] ) {
			wp(
				`user create ${ username } ${ username }@example.test --role=subscriber --user_pass=${ password } --porcelain`
			);
			wp( `user meta update ${ username } wp-approve-user pending` );
		}
	} );

	test.afterAll( () => {
		for ( const username of [ approveUser, rejectUser, extraUser ] ) {
			try {
				wp( `user delete ${ username } --yes` );
			} catch {
				// Best-effort.
			}
		}
	} );

	test( 'widget lists pending users with per-row actions and a view-all footer', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/' );

		const widget = page.locator( '#wpau_pending_users' );
		await expect( widget ).toBeVisible();
		await expect( widget.locator( 'h2, .hndle' ).first() ).toContainText(
			'Pending User Approvals'
		);

		const approveRow = widget.locator(
			`li.wpau-pending-row:has-text("${ approveUser }@example.test")`
		);
		await expect( approveRow ).toBeVisible();
		await expect(
			approveRow.locator( '[data-wpau-action="approve"]' )
		).toBeVisible();
		await expect(
			approveRow.locator( '[data-wpau-action="unapprove"]' )
		).toBeVisible();

		await expect( widget.locator( '.wpau-widget-footer' ) ).toContainText(
			/View all \d+ pending/
		);
	} );

	test( 'approve from the widget flips the user and removes the row', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/' );

		const row = page.locator(
			`li.wpau-pending-row:has-text("${ approveUser }@example.test")`
		);
		await expect( row ).toBeVisible();

		await row.locator( '[data-wpau-action="approve"]' ).click();
		await expect( row ).toHaveCount( 0, { timeout: 5000 } );

		const status = wp( `user meta get ${ approveUser } wp-approve-user` );
		expect( status ).toBe( 'approved' );
	} );

	test( 'reject from the widget flips the user to unapproved', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/' );

		const row = page.locator(
			`li.wpau-pending-row:has-text("${ rejectUser }@example.test")`
		);
		await expect( row ).toBeVisible();

		await row.locator( '[data-wpau-action="unapprove"]' ).click();
		await expect( row ).toHaveCount( 0, { timeout: 5000 } );

		const status = wp( `user meta get ${ rejectUser } wp-approve-user` );
		expect( status ).toBe( 'unapproved' );
	} );
} );
