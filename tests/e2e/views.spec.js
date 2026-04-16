/**
 * Coverage for the Users menu badge and the Pending / Unapproved sub-views.
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

test.describe.serial( 'WP Approve User — views and menu badge', () => {
	const stamp = Date.now();
	const pendingA = `wpau-view-pa-${ stamp }`;
	const pendingB = `wpau-view-pb-${ stamp }`;
	const unapproved = `wpau-view-u-${ stamp }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
		for ( const user of [ pendingA, pendingB ] ) {
			wp(
				`user create ${ user } ${ user }@example.test --role=subscriber --user_pass=${ password } --porcelain`
			);
			wp( `user meta update ${ user } wp-approve-user pending` );
		}
		wp(
			`user create ${ unapproved } ${ unapproved }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ unapproved } wp-approve-user unapproved` );
	} );

	test.afterAll( () => {
		for ( const user of [ pendingA, pendingB, unapproved ] ) {
			try {
				wp( `user delete ${ user } --yes` );
			} catch {
				// Best-effort.
			}
		}
	} );

	test( 'Users menu shows the pending count badge', async ( { page } ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/' );

		// admin_menu() appends a count-N <span class="update-plugins"> bubble to
		// the Users menu label. Our fixture seeds at least 2 pending users.
		// admin_menu() prepends a count bubble to the top-level Users link;
		// WordPress mirrors that markup into the first submenu link as well,
		// so we explicitly grab the top-level one.
		const badge = page
			.locator( '#menu-users > a .update-plugins .plugin-count' )
			.first();
		await expect( badge ).toBeVisible();

		const value = parseInt( ( await badge.textContent() ) || '0', 10 );
		expect( value ).toBeGreaterThanOrEqual( 2 );
	} );

	test( 'Pending sub-view shows the right count and filters the list', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php' );

		const pendingLink = page.locator( '.subsubsub li.pending a' );
		await expect( pendingLink ).toContainText( /Pending/ );

		const countText = await pendingLink.locator( '.count' ).textContent();
		const count = parseInt(
			( countText || '' ).replace( /[^0-9]/g, '' ),
			10
		);
		expect( count ).toBeGreaterThanOrEqual( 2 );

		await pendingLink.click();
		await expect( page ).toHaveURL( /role=wpau_pending/ );

		await expect(
			page.locator( '#the-list tr', { hasText: pendingA } )
		).toBeVisible();
		await expect(
			page.locator( '#the-list tr', { hasText: pendingB } )
		).toBeVisible();
		// Unapproved user must not appear in the pending bucket.
		await expect(
			page.locator( '#the-list tr', { hasText: unapproved } )
		).toHaveCount( 0 );
	} );

	test( 'Unapproved sub-view shows the right count and filters the list', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php' );

		const unapprovedLink = page.locator( '.subsubsub li.unapproved a' );
		await expect( unapprovedLink ).toContainText( /Unapproved/ );

		const countText = await unapprovedLink
			.locator( '.count' )
			.textContent();
		const count = parseInt(
			( countText || '' ).replace( /[^0-9]/g, '' ),
			10
		);
		expect( count ).toBeGreaterThanOrEqual( 1 );

		await unapprovedLink.click();
		await expect( page ).toHaveURL( /role=wpau_unapproved/ );

		await expect(
			page.locator( '#the-list tr', { hasText: unapproved } )
		).toBeVisible();
		await expect(
			page.locator( '#the-list tr', { hasText: pendingA } )
		).toHaveCount( 0 );
	} );
} );
