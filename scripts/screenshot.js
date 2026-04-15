/**
 * Drive the running wp-env site with a headless browser and capture a screenshot.
 *
 * Usage (from the project root, with `npm run start` already running):
 *
 *   node scripts/screenshot.js /wp-admin/users.php?role=wpau_pending out.png
 *
 * Defaults: path "/", output "screenshot.png", base URL http://localhost:8888.
 * Override base URL with WP_BASE_URL. Logs in as admin/password before navigating,
 * so any wp-admin route works. Pass --no-login to skip the login step (useful for
 * front-end screenshots or the wp-login.php screen itself).
 */
const { chromium } = require( '@playwright/test' );

async function main() {
	const args = process.argv.slice( 2 );
	const skipLogin = args.includes( '--no-login' );
	const positional = args.filter( ( a ) => ! a.startsWith( '--' ) );
	const urlPath = positional[ 0 ] || '/';
	const out = positional[ 1 ] || 'screenshot.png';
	const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';

	const browser = await chromium.launch();
	try {
		const context = await browser.newContext( { baseURL } );
		const page = await context.newPage();

		if ( ! skipLogin ) {
			await page.goto( '/wp-login.php' );
			await page.locator( '#user_login' ).fill( 'admin' );
			await page.locator( '#user_pass' ).fill( 'password' );
			await page.locator( '#wp-submit' ).click();
			// Fail loudly if the admin/password pair is wrong — otherwise we'd
			// silently screenshot the login screen instead of the target route.
			await page.waitForSelector( '#wpadminbar', { timeout: 10000 } );
		}

		await page.goto( urlPath, { waitUntil: 'domcontentloaded' } );
		await page.screenshot( { path: out, fullPage: true } );

		console.log( `Saved ${ out } (${ baseURL }${ urlPath })` );
	} finally {
		await browser.close();
	}
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
