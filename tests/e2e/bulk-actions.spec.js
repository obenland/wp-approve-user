/**
 * Bulk action coverage for the users.php screen.
 *
 * Each describe block creates its own fixture users so the tests can run in
 * any order without bleeding state. The post-action URL assertions verify the
 * "stay on the role view if there are remaining users, otherwise return to All
 * Users" behaviour from has_remaining_users().
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

async function selectRow( page, username ) {
	const row = page.locator( '#the-list tr', { hasText: username } );
	await expect( row ).toBeVisible();
	await row.locator( 'input[type="checkbox"][name="users[]"]' ).check();
}

async function applyBulkAction( page, value ) {
	await page.locator( 'select[name="action"]' ).selectOption( value );
	await page.locator( '#doaction' ).click();
}

test.describe
	.serial( 'WP Approve User — bulk approve from Pending view', () => {
	const stamp = Date.now();
	const userA = `wpau-bulk-pa-${ stamp }`;
	const userB = `wpau-bulk-pb-${ stamp }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
		for ( const user of [ userA, userB ] ) {
			wp(
				`user create ${ user } ${ user }@example.test --role=subscriber --user_pass=${ password } --porcelain`
			);
			wp( `user meta update ${ user } wp-approve-user pending` );
		}
	} );

	test.afterAll( () => {
		for ( const user of [ userA, userB ] ) {
			try {
				wp( `user delete ${ user } --yes` );
			} catch {
				// Best-effort cleanup.
			}
		}
	} );

	test( 'admin bulk approves pending users and notice reflects the count', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php?role=wpau_pending' );

		await selectRow( page, userA );
		await selectRow( page, userB );
		await applyBulkAction( page, 'wpau_bulk_approve' );

		const notice = page.locator( '#setting-error-wpau-approved' );
		await expect( notice ).toContainText( /2 users approved\./i );

		// With no remaining pending users, we should be back on All Users.
		await expect( page ).toHaveURL( /\/wp-admin\/users\.php/ );
		await expect( page ).not.toHaveURL( /role=wpau_pending/ );

		expect( wp( `user meta get ${ userA } wp-approve-user` ) ).toBe(
			'approved'
		);
		expect( wp( `user meta get ${ userB } wp-approve-user` ) ).toBe(
			'approved'
		);
	} );
} );

test.describe
	.serial( 'WP Approve User — bulk approve from Unapproved view', () => {
	const stamp = Date.now();
	const userA = `wpau-bulk-ua-${ stamp }`;
	const userB = `wpau-bulk-ub-${ stamp }`;
	const userC = `wpau-bulk-uc-${ stamp }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
		for ( const user of [ userA, userB, userC ] ) {
			wp(
				`user create ${ user } ${ user }@example.test --role=subscriber --user_pass=${ password } --porcelain`
			);
			wp( `user meta update ${ user } wp-approve-user unapproved` );
		}
	} );

	test.afterAll( () => {
		for ( const user of [ userA, userB, userC ] ) {
			try {
				wp( `user delete ${ user } --yes` );
			} catch {
				// Best-effort cleanup.
			}
		}
	} );

	test( 'admin bulk approves a subset and stays on the unapproved view', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php?role=wpau_unapproved' );

		await selectRow( page, userA );
		await selectRow( page, userB );
		await applyBulkAction( page, 'wpau_bulk_approve' );

		await expect(
			page.locator( '#setting-error-wpau-approved' )
		).toContainText( /2 users approved\./i );

		// One unapproved user remains, so we should stay on the role view.
		await expect( page ).toHaveURL( /role=wpau_unapproved/ );

		expect( wp( `user meta get ${ userA } wp-approve-user` ) ).toBe(
			'approved'
		);
		expect( wp( `user meta get ${ userB } wp-approve-user` ) ).toBe(
			'approved'
		);
		expect( wp( `user meta get ${ userC } wp-approve-user` ) ).toBe(
			'unapproved'
		);
	} );
} );

test.describe
	.serial( 'WP Approve User — bulk unapprove from All Users view', () => {
	const stamp = Date.now();
	const userA = `wpau-bulk-au-${ stamp }`;
	const userB = `wpau-bulk-bu-${ stamp }`;
	const password = 'Correct-Horse-Battery-Staple-1';

	test.beforeAll( () => {
		for ( const user of [ userA, userB ] ) {
			wp(
				`user create ${ user } ${ user }@example.test --role=subscriber --user_pass=${ password } --porcelain`
			);
			wp( `user meta update ${ user } wp-approve-user approved` );
		}
	} );

	test.afterAll( () => {
		for ( const user of [ userA, userB ] ) {
			try {
				wp( `user delete ${ user } --yes` );
			} catch {
				// Best-effort cleanup.
			}
		}
	} );

	test( 'admin bulk unapproves users from All Users view', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/users.php' );

		await selectRow( page, userA );
		await selectRow( page, userB );
		await applyBulkAction( page, 'wpau_bulk_unapprove' );

		await expect(
			page.locator( '#setting-error-wpau-unapproved' )
		).toContainText( /2 users unapproved\./i );

		expect( wp( `user meta get ${ userA } wp-approve-user` ) ).toBe(
			'unapproved'
		);
		expect( wp( `user meta get ${ userB } wp-approve-user` ) ).toBe(
			'unapproved'
		);
	} );
} );
