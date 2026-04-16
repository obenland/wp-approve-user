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

module.exports = async function globalSetup() {
	// Make sure registration is on across the network so the plugin actually
	// loads. Multisite stores this under the `registration` site option, and
	// `get_option( 'users_can_register' )` is then derived from it via the
	// `option_users_can_register` filter — but that filter only fires if the
	// `users_can_register` row actually exists in `wp_options`, so insert a
	// stub row whose value gets overridden by the filter.
	wp( 'site option update registration user' );
	try {
		wp( 'option add users_can_register 0' );
	} catch {
		// Already set.
	}

	// The plugin's network admin menu only registers under network activation.
	try {
		wp( 'plugin activate wp-approve-user --network' );
	} catch {
		// Already network-active.
	}

	// Reset the captured-mail bucket from any previous run.
	wp( "site option update wpau_captured_mail '[]' --format=json" );

	// Make sure a secondary site exists for the per-site row-action specs.
	const sites = wp( 'site list --field=url' )
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean );

	const hasSub = sites.some( ( url ) => url.includes( '/sub/' ) );

	if ( ! hasSub ) {
		try {
			wp(
				'site create --slug=sub --title="Sub Site" --email=admin@example.com'
			);
		} catch {
			// If the site already exists in some half-created state, ignore.
		}
	}
};
