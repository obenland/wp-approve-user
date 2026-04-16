const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'node:child_process' );

function wp( args ) {
	return execSync(
		`npx wp-env --config=.wp-env.multisite.json run cli wp ${ args }`,
		{
			stdio: [ 'ignore', 'pipe', 'inherit' ],
		}
	)
		.toString()
		.trim();
}

function tryWp( args ) {
	try {
		return wp( args );
	} catch {
		return '';
	}
}

// Multisite usernames must be lowercase alphanumeric only — no hyphens.
function makeUsername( suffix ) {
	return `wpaums${ suffix }${ Date.now() }`;
}

async function loginAs( page, username, password ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
}

async function logout( page ) {
	await page.goto( '/wp-login.php?action=logout' );
	const confirmLink = page.locator( 'a', { hasText: 'log out' } );
	if ( await confirmLink.isVisible().catch( () => false ) ) {
		await confirmLink.click();
	}
}

function getSubSiteId() {
	// `wp site list` default table output isn't a stable interface — parse the
	// JSON format instead so formatting or locale changes can't break this.
	try {
		const sites = JSON.parse(
			wp( 'site list --fields=blog_id,url --format=json' )
		);
		for ( const site of sites ) {
			if ( site.url && site.url.includes( '/sub/' ) ) {
				return parseInt( site.blog_id, 10 );
			}
		}
	} catch {
		// Fall through to the zero sentinel below.
	}
	return 0;
}

test.describe.serial( 'WP Approve User multisite — super admin bypass', () => {
	const username = makeUsername( 'super' );
	const password = 'CorrectHorseBatteryStaple1';

	test.beforeAll( () => {
		wp(
			`user create ${ username } ${ username }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		// Promote to super admin and explicitly mark as unapproved.
		wp( `super-admin add ${ username }` );
		wp( `user meta update ${ username } wp-approve-user unapproved` );
	} );

	test.afterAll( () => {
		tryWp( `super-admin remove ${ username }` );
		tryWp( `user delete ${ username } --yes --network` );
	} );

	test( 'super admin marked unapproved can still log in', async ( {
		page,
	} ) => {
		await loginAs( page, username, password );
		await expect( page ).toHaveURL( /\/wp-admin\// );
		await expect( page.locator( '#login_error' ) ).toHaveCount( 0 );
		await logout( page );
	} );
} );

test.describe
	.serial( 'WP Approve User multisite — network row + bulk actions', () => {
	const rowUser = makeUsername( 'row' );
	const bulkUser = makeUsername( 'bulk' );
	const password = 'CorrectHorseBatteryStaple1';

	test.beforeAll( () => {
		wp(
			`user create ${ rowUser } ${ rowUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ rowUser } wp-approve-user pending` );

		wp(
			`user create ${ bulkUser } ${ bulkUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ bulkUser } wp-approve-user pending` );
	} );

	test.afterAll( () => {
		tryWp( `user delete ${ rowUser } --yes --network` );
		tryWp( `user delete ${ bulkUser } --yes --network` );
	} );

	test( 'pending sub-view renders in network users list with both pending users', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/network/users.php' );

		const pendingLink = page.locator( '.subsubsub li.pending a' );
		await expect( pendingLink ).toContainText( /Pending/ );
		await expect( pendingLink.locator( '.count' ) ).toContainText( /\d+/ );

		await pendingLink.click();
		await expect(
			page.locator( '#the-list tr', { hasText: rowUser } )
		).toBeVisible();
		await expect(
			page.locator( '#the-list tr', { hasText: bulkUser } )
		).toBeVisible();
	} );

	test( 'admin approves pending user via row action on network users list', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/network/users.php?role=wpau_pending' );

		const userRow = page.locator( '#the-list tr', { hasText: rowUser } );
		await expect( userRow ).toBeVisible();

		await userRow.hover();
		await userRow.locator( 'a.submitapprove' ).click();

		await expect(
			page.locator( '#setting-error-wpau-approved' )
		).toContainText( /user approved\./i );

		const meta = wp( `user meta get ${ rowUser } wp-approve-user` );
		expect( meta ).toBe( 'approved' );
	} );

	test( 'bulk approve from network users list approves remaining pending user', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/network/users.php?role=wpau_pending' );

		const userRow = page.locator( '#the-list tr', { hasText: bulkUser } );
		await expect( userRow ).toBeVisible();
		await userRow
			.locator( 'input[type="checkbox"][name="allusers[]"]' )
			.check();

		await page
			.locator( 'select[name="action"]' )
			.selectOption( 'wpau_bulk_approve' );
		await page.locator( '#doaction' ).click();

		await expect(
			page.locator( '#setting-error-wpau-approved' )
		).toContainText( /user approved\./i );

		const meta = wp( `user meta get ${ bulkUser } wp-approve-user` );
		expect( meta ).toBe( 'approved' );
	} );
} );

test.describe.serial( 'WP Approve User multisite — per-site row action', () => {
	const siteUser = makeUsername( 'site' );
	const password = 'CorrectHorseBatteryStaple1';
	let subId;

	test.beforeAll( () => {
		subId = getSubSiteId();
		expect( Number.isInteger( subId ) && subId > 1 ).toBeTruthy();

		// Create the user network-wide, then add to the sub site as pending.
		wp(
			`user create ${ siteUser } ${ siteUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user set-role ${ siteUser } subscriber --url=localhost:8890/sub` );
		wp(
			`user meta update ${ siteUser } wp-approve-user pending --url=localhost:8890/sub`
		);
	} );

	test.afterAll( () => {
		tryWp( `user delete ${ siteUser } --yes --network` );
	} );

	test( 'approve from per-site users screen round-trips id and updates meta', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto(
			`/wp-admin/network/site-users.php?id=${ subId }&role=wpau_pending`
		);

		const userRow = page.locator( '#the-list tr', {
			hasText: siteUser,
		} );
		await expect( userRow ).toBeVisible();

		await userRow.hover();
		const approveLink = userRow.locator( 'a.submitapprove' );
		// The approve link must round-trip the site id query arg so the
		// per-site context survives the action handler.
		await expect( approveLink ).toHaveAttribute(
			'href',
			new RegExp( `id=${ subId }` )
		);
		await approveLink.click();

		// Note: WP core's network/site-users.php has an undefined-variable
		// warning under WP_DEBUG that breaks the wp_safe_redirect after
		// the meta is updated, so we don't assert on the success notice
		// here. The meta change is the load-bearing assertion.
		const meta = wp(
			`user meta get ${ siteUser } wp-approve-user --url=localhost:8890/sub`
		);
		expect( meta ).toBe( 'approved' );
	} );
} );

test.describe( 'WP Approve User multisite — settings page lives under network settings', () => {
	test( 'settings page is reachable under network admin settings', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );

		// Should NOT exist on the per-site Settings menu. Hitting the per-site
		// URL either redirects or shows an error — what matters is that the
		// plugin's settings heading isn't there.
		await page.goto( '/wp-admin/options-general.php?page=wp-approve-user' );
		await expect(
			page.locator( '.wrap h2', { hasText: 'Approve User Settings' } )
		).toHaveCount( 0 );

		// Should exist under network admin settings.
		await page.goto(
			'/wp-admin/network/settings.php?page=wp-approve-user'
		);
		await expect(
			page.locator( 'h2', { hasText: 'Approve User Settings' } )
		).toBeVisible();

		// SITE_NAME placeholder is documented under multisite.
		await expect( page.locator( 'body' ) ).toContainText( 'SITE_NAME' );
	} );
} );

test.describe
	.serial( 'WP Approve User multisite — SITE_NAME placeholder is rendered in approval mail', () => {
	const mailUser = makeUsername( 'mail' );
	const password = 'CorrectHorseBatteryStaple1';
	let previousOption = '';

	test.beforeAll( () => {
		// Snapshot existing settings so we can restore.
		previousOption = tryWp( 'option get wp-approve-user --format=json' );

		// Configure the plugin to send a mail using SITE_NAME. Write the
		// option as a single JSON blob so wp-cli can create it if missing.
		const optionPayload = JSON.stringify( {
			'wpau-send-approve-email': true,
			'wpau-approve-email': 'Welcome USERNAME to SITE_NAME',
			'wpau-send-unapprove-email': false,
			'wpau-unapprove-email': '',
		} );
		wp(
			`option update wp-approve-user '${ optionPayload }' --format=json`
		);

		// Reset captured mail and create a pending user that triggered a
		// "new registration".
		wp( "site option update wpau_captured_mail '[]' --format=json" );

		wp(
			`user create ${ mailUser } ${ mailUser }@example.test --role=subscriber --user_pass=${ password } --porcelain`
		);
		wp( `user meta update ${ mailUser } wp-approve-user pending` );
		wp(
			`user meta update ${ mailUser } wp-approve-user-new-registration 1`
		);
	} );

	test.afterAll( () => {
		tryWp( `user delete ${ mailUser } --yes --network` );
		if ( previousOption ) {
			tryWp(
				`option update wp-approve-user '${ previousOption }' --format=json`
			);
		} else {
			tryWp( 'option delete wp-approve-user' );
		}
		tryWp( "site option update wpau_captured_mail '[]' --format=json" );
	} );

	test( 'approval email contains the substituted site name', async ( {
		page,
	} ) => {
		await loginAs( page, 'admin', 'password' );
		await page.goto( '/wp-admin/network/users.php?role=wpau_pending' );

		const userRow = page.locator( '#the-list tr', {
			hasText: mailUser,
		} );
		await expect( userRow ).toBeVisible();
		await userRow.hover();
		await userRow.locator( 'a.submitapprove' ).click();

		await expect(
			page.locator( '#setting-error-wpau-approved' )
		).toContainText( /user approved\./i );

		// The mail-sent meta confirms wp_mail() ran for this user.
		const sent = wp(
			`user meta get ${ mailUser } wp-approve-user-mail-sent`
		);
		expect( sent ).toBe( '1' );

		// Inspect the captured mail bucket from the mu-plugin shim. The
		// approval flow may dispatch multiple mails (for new registrants
		// it also kicks off a "set your password" message); we want the
		// one whose subject matches the plugin's "Registration approved"
		// template.
		const captured = wp(
			'site option get wpau_captured_mail --format=json'
		);
		const mails = JSON.parse( captured || '[]' );
		const addressed = mails.filter( ( m ) =>
			( m.to || [] ).some( ( addr ) =>
				addr.includes( `${ mailUser }@example.test` )
			)
		);
		const mine = addressed.find( ( m ) =>
			/Registration approved/i.test( m.subject || '' )
		);
		expect( mine ).toBeTruthy();
		// SITE_NAME should have been substituted with a non-empty string,
		// not left as the literal placeholder.
		expect( mine.message ).not.toContain( 'SITE_NAME' );
		expect( mine.message ).toContain( mailUser );
		expect( mine.message ).toMatch( /Welcome .+ to .+/ );
	} );
} );
