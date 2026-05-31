/**
 * Persists dismissal of the "Change From Address" hint notice.
 *
 * Progressive enhancement on top of the server-rendered notice: WordPress core
 * turns `.is-dismissible` notices into ones with a × button, but that dismissal
 * is client-side only and forgotten on reload. Here we hide the no-JS text link
 * (the × replaces it) and, when the × is clicked, send the notice's nonced
 * dismiss URL in the background so the dismissal sticks.
 */
document.addEventListener( 'DOMContentLoaded', function () {
	const notice = document.querySelector( '.wpau-from-address-hint' );
	if ( ! notice ) {
		return;
	}

	const fallback = notice.querySelector( '.wpau-dismiss-from-address-hint' );
	if ( ! fallback ) {
		return;
	}

	// Core draws the × button, so the text link is only the no-JS fallback.
	const dismissUrl = fallback.href;
	fallback.hidden = true;

	notice.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '.notice-dismiss' ) ) {
			return;
		}

		// Core removes the notice from the DOM; record the dismissal too.
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( dismissUrl );
		} else {
			fetch( dismissUrl, { credentials: 'same-origin' } );
		}
	} );
} );
