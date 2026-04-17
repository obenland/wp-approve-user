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
	await page.waitForURL( /\/wp-admin\// );
}

async function fetchRestNonce( page ) {
	await page.goto( '/wp-admin/' );
	return page.evaluate( async () => {
		const response = await fetch(
			'/wp-admin/admin-ajax.php?action=rest-nonce',
			{
				credentials: 'same-origin',
			}
		);
		return response.text();
	} );
}

test.describe.serial( 'Abilities API', () => {
	const username = `wpau-abilities-${ Date.now() }`;
	const password = 'Correct-Horse-Battery-Staple-1';
	let userId = 0;
	let abilitiesAvailable = true;

	test.beforeAll( async ( { request } ) => {
		// Detect whether the Abilities API REST namespace is exposed. Older WP (< 6.9) returns 404.
		const response = await request.get(
			'/wp-json/wp-abilities/v1/abilities'
		);
		if ( response.status() === 404 ) {
			abilitiesAvailable = false;
			return;
		}

		userId = Number(
			wp(
				`user create ${ username } ${ username }@example.test --role=subscriber --user_pass=${ password } --porcelain`
			)
		);
		wp( `user meta update ${ username } wp-approve-user pending` );
	} );

	test.afterAll( () => {
		if ( ! abilitiesAvailable || ! userId ) {
			return;
		}
		wp( `user delete ${ username } --yes` );
	} );

	test( 'approve ability flips user meta to approved', async ( { page } ) => {
		test.skip(
			! abilitiesAvailable,
			'Abilities API REST route is not registered on this WordPress version.'
		);

		await loginAs( page, 'admin', 'password' );
		const nonce = await fetchRestNonce( page );

		const result = await page.evaluate(
			async ( { id, restNonce } ) => {
				const response = await fetch(
					'/wp-json/wp-abilities/v1/wp-approve-user/approve/run',
					{
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': restNonce,
						},
						// eslint-disable-next-line camelcase -- REST body key matches ability input schema.
						body: JSON.stringify( { user_id: id } ),
					}
				);
				return {
					status: response.status,
					body: await response.json(),
				};
			},
			{ id: userId, restNonce: nonce }
		);

		expect( result.status ).toBe( 200 );
		expect( result.body ).toMatchObject( {
			success: true,
			// eslint-disable-next-line camelcase -- Response key matches ability output schema.
			user_id: userId,
			status: 'approved',
		} );

		const meta = wp( `user meta get ${ userId } wp-approve-user` );
		expect( meta ).toBe( 'approved' );
	} );
} );
