/**
 * Rule-based auto-approval coverage.
 *
 *  - The spec stores an `email_domain` rule via the plugin option.
 *  - The spec creates users via WP-CLI with matching and non-matching emails.
 *  - The spec invokes the plugin approval logic via `wp eval`.
 *  - The resulting `wp-approve-user` meta is verified via WP-CLI probes.
 *
 * This covers the rule-gated approval behavior, but it does not exercise the
 * browser-based registration flow through `wp-login.php`.
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

test.describe.serial( 'WP Approve User — auto-approval rules', () => {
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
			// Best-effort.
		}
	} );

	test( 'admin adds an email_domain rule via the settings page', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( SETTINGS_URL );

		await expect(
			page.locator( 'h2', { hasText: 'Approve User Settings' } )
		).toBeVisible();

		// The server always renders at least one blank row; fill it in and save.
		const firstRow = page.locator( '.wpau-auto-approve-rule' ).first();
		await expect( firstRow ).toBeVisible();

		await firstRow
			.locator( 'select' )
			.selectOption( { value: 'email_domain' } );
		await firstRow.locator( 'input[type="text"]' ).fill( 'example.test' );

		await page.locator( '#submit' ).click();

		await expect(
			page.locator( '#setting-error-settings_updated' )
		).toBeVisible();

		// Reload and confirm the stored rule round-trips.
		await page.goto( SETTINGS_URL );
		await expect(
			page
				.locator( '.wpau-auto-approve-rule' )
				.first()
				.locator( 'input[type="text"]' )
		).toHaveValue( 'example.test' );

		// Stored in the canonical shape.
		const stored = getWpauOption();
		expect( stored ).toBeTruthy();
		expect( stored.auto_approve_rules ).toEqual( [
			{ type: 'email_domain', value: 'example.test' },
		] );
	} );

	test( 'user registering with matching email is auto-approved', async () => {
		const username = `wpau-auto-${ Date.now() }`;
		const email = `${ username }@example.test`;

		try {
			wp(
				`user create ${ username } ${ email } --role=subscriber --user_pass=Correct-Horse-Battery-Staple-1 --porcelain`
			);

			/*
			 * `wp user create` runs as the admin context inside wp-cli, which means
			 * user_register() sees `create_users` capabilities and writes 'approved'
			 * *before* the auto-approval handler runs. That is the documented
			 * admin-bypass path, so we explicitly reset the meta to 'pending' and
			 * invoke the auto-approval handler from scratch via `wp eval`.
			 */
			wp( `user meta update ${ username } wp-approve-user pending` );

			const php = `Obenland_Wp_Approve_User::get_instance()->auto_approve_user( (int) get_user_by('login', '${ username }')->ID );`;
			const encoded = Buffer.from( php ).toString( 'base64' );
			execSync(
				`npx wp-env run cli wp eval ${ JSON.stringify(
					`eval(base64_decode('${ encoded }'));`
				) }`,
				{ stdio: [ 'ignore', 'pipe', 'inherit' ] }
			);

			expect( wp( `user meta get ${ username } wp-approve-user` ) ).toBe(
				'approved'
			);
		} finally {
			try {
				wp( `user delete ${ username } --yes` );
			} catch {
				// Best-effort.
			}
		}
	} );

	test( 'user registering with non-matching email stays pending', async () => {
		const username = `wpau-noauto-${ Date.now() }`;
		const email = `${ username }@other.test`;

		try {
			wp(
				`user create ${ username } ${ email } --role=subscriber --user_pass=Correct-Horse-Battery-Staple-1 --porcelain`
			);
			wp( `user meta update ${ username } wp-approve-user pending` );

			const php = `Obenland_Wp_Approve_User::get_instance()->auto_approve_user( (int) get_user_by('login', '${ username }')->ID );`;
			const encoded = Buffer.from( php ).toString( 'base64' );
			execSync(
				`npx wp-env run cli wp eval ${ JSON.stringify(
					`eval(base64_decode('${ encoded }'));`
				) }`,
				{ stdio: [ 'ignore', 'pipe', 'inherit' ] }
			);

			expect( wp( `user meta get ${ username } wp-approve-user` ) ).toBe(
				'pending'
			);
		} finally {
			try {
				wp( `user delete ${ username } --yes` );
			} catch {
				// Best-effort.
			}
		}
	} );
} );
