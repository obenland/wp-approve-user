const { execSync } = require( 'node:child_process' );

module.exports = async function globalSetup() {
	execSync( 'npx wp-env run cli wp option update users_can_register 1', {
		stdio: [ 'ignore', 'pipe', 'inherit' ],
	} );
};
