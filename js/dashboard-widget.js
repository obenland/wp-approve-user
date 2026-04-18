( function () {
	'use strict';

	const widget = document.getElementById( 'wpau_pending_users' );
	if ( ! widget ) {
		return;
	}

	const i18n = window.wp_approve_user_dashboard || {};

	widget.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '[data-wpau-action]' );
		if ( ! button || ! widget.contains( button ) ) {
			return;
		}
		event.preventDefault();

		const row = button.closest( '.wpau-pending-row' );
		if ( ! row ) {
			return;
		}
		runAction( row, button.dataset.wpauAction );
	} );

	function runAction( row, action ) {
		const userId = row.dataset.userId;
		const nonce =
			action === 'approve'
				? row.dataset.nonceApprove
				: row.dataset.nonceUnapprove;

		toggleButtons( row, true );
		clearError( row );

		postForm( i18n.ajaxurl, {
			action: 'wpau_dashboard_' + action,
			user_id: userId,
			nonce,
		} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					showError(
						row,
						response &&
							response.data &&
							response.data.code === 'cap'
							? i18n.errorPermission
							: i18n.errorGeneric,
						action
					);
					return;
				}
				removeRow( row, response.data );
			} )
			.catch( function ( error ) {
				/* eslint-disable-next-line no-console */
				console.error( 'WPAU dashboard widget', error );
				showError( row, i18n.errorGeneric, action );
			} );
	}

	function removeRow( row, data ) {
		// Keep the timeout in sync with the CSS .is-removing transition duration.
		row.classList.add( 'is-removing' );
		window.setTimeout( function () {
			row.parentNode.removeChild( row );
			updateFooter( data );
			maybeOfferRefresh( data.pending_count );
		}, 200 );
	}

	function updateFooter( data ) {
		const footer = widget.querySelector( '.wpau-widget-footer' );
		if ( ! footer ) {
			return;
		}
		if ( data.pending_count <= 0 ) {
			footer.parentNode.removeChild( footer );
			return;
		}
		const link = footer.querySelector( 'a' );
		if ( link ) {
			link.textContent = data.pending_label;
		}
	}

	function maybeOfferRefresh( pendingCount ) {
		const list = widget.querySelector( '[data-wpau-container]' );
		if ( ! list || list.children.length > 0 ) {
			return;
		}

		if ( pendingCount <= 0 ) {
			const empty = document.createElement( 'p' );
			empty.className = 'wpau-widget-empty';
			empty.textContent = i18n.emptyMessage;
			list.parentNode.replaceChild( empty, list );
			return;
		}

		const wrap = document.createElement( 'p' );
		wrap.className = 'wpau-widget-refresh';
		const btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button button-secondary';
		btn.textContent = i18n.refreshLabel;
		btn.addEventListener( 'click', refresh );
		wrap.appendChild( btn );
		list.parentNode.replaceChild( wrap, list );
	}

	function refresh( event ) {
		const btn = event.currentTarget;
		btn.disabled = true;

		postForm( i18n.ajaxurl, {
			action: 'wpau_dashboard_refresh',
			nonce: i18n.refreshNonce,
		} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					btn.disabled = false;
					return;
				}
				const list = document.createElement( 'ul' );
				list.className = 'wpau-pending-list';
				list.setAttribute( 'data-wpau-container', '' );
				list.innerHTML = response.data.html;
				const wrap = btn.closest( '.wpau-widget-refresh' );
				wrap.parentNode.replaceChild( list, wrap );
				updateFooter( response.data );
			} )
			.catch( function ( error ) {
				/* eslint-disable-next-line no-console */
				console.error( 'WPAU dashboard widget', error );
				btn.disabled = false;
			} );
	}

	function showError( row, message, action ) {
		toggleButtons( row, false );
		const notice = document.createElement( 'div' );
		notice.className = 'notice notice-error inline wpau-widget-error';
		notice.setAttribute( 'role', 'alert' );
		notice.textContent = message + ' ';
		const retry = document.createElement( 'a' );
		retry.href = '#';
		retry.textContent = i18n.retryLabel;
		retry.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			notice.parentNode.removeChild( notice );
			runAction( row, action );
		} );
		notice.appendChild( retry );
		row.appendChild( notice );
	}

	function clearError( row ) {
		const existing = row.querySelector( '.wpau-widget-error' );
		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}
	}

	function toggleButtons( row, disabled ) {
		row.querySelectorAll( 'button' ).forEach( function ( btn ) {
			btn.disabled = disabled;
		} );
	}

	function postForm( url, params ) {
		const body = new URLSearchParams();
		Object.keys( params ).forEach( function ( key ) {
			if ( params[ key ] !== undefined && params[ key ] !== null ) {
				body.append( key, params[ key ] );
			}
		} );

		return window
			.fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} )
			.then( function ( response ) {
				return response.json().catch( function () {
					// Non-JSON body (HTML error page, auth redirect, proxy
					// interception). Surface the HTTP status so the caller can
					// distinguish transport from application failures.
					const err = new Error( 'Non-JSON response' );
					err.status = response.status;
					throw err;
				} );
			} );
	}
} )();
