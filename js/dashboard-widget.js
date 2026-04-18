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
		const list = row.parentNode;
		row.classList.add( 'is-removing' );
		window.setTimeout( function () {
			list.removeChild( row );
			appendNextRow( list, data.next_row );
			updateFooter( data );
			maybeOfferRefresh( data.pending_count );
		}, 200 );
	}

	function appendNextRow( list, html ) {
		// Empty html is the documented "queue drained" signal — silent return.
		if ( ! html ) {
			return;
		}
		if ( ! list || list.tagName !== 'UL' ) {
			return;
		}
		const template = document.createElement( 'template' );
		template.innerHTML = html.trim();
		const newRow = template.content.firstElementChild;
		if ( ! newRow ) {
			// Server promised a row but sent markup we can't parse — log so a
			// regression in render_row()/transition_payload() stays observable.
			/* eslint-disable-next-line no-console */
			console.error( 'WPAU dashboard widget', 'invalid next_row markup' );
			return;
		}
		// Skip if the row is already visible (e.g. a concurrent refresh pulled it in).
		if (
			list.querySelector(
				'[data-user-id="' + newRow.dataset.userId + '"]'
			)
		) {
			return;
		}
		list.appendChild( newRow );
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
		clearRefreshError( btn );

		postForm( i18n.ajaxurl, {
			action: 'wpau_dashboard_refresh',
			nonce: i18n.refreshNonce,
		} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					btn.disabled = false;
					showRefreshError( btn );
					return;
				}
				if ( response.data.pending_count > 0 && ! response.data.html ) {
					// Server says users are pending but handed back no rows —
					// that's a backend inconsistency, not a "drained" signal.
					// Keep the refresh button so the admin can try again.
					/* eslint-disable-next-line no-console */
					console.error(
						'WPAU dashboard widget',
						'empty html with pending_count > 0'
					);
					btn.disabled = false;
					showRefreshError( btn );
					return;
				}

				let replacement;
				if ( response.data.pending_count <= 0 ) {
					replacement = document.createElement( 'p' );
					replacement.className = 'wpau-widget-empty';
					replacement.textContent = i18n.emptyMessage;
				} else {
					replacement = document.createElement( 'ul' );
					replacement.className = 'wpau-pending-list';
					replacement.setAttribute( 'data-wpau-container', '' );
					replacement.innerHTML = response.data.html;
				}
				const wrap = btn.closest( '.wpau-widget-refresh' );
				wrap.parentNode.replaceChild( replacement, wrap );
				updateFooter( response.data );
			} )
			.catch( function ( error ) {
				/* eslint-disable-next-line no-console */
				console.error( 'WPAU dashboard widget', error );
				btn.disabled = false;
				showRefreshError( btn );
			} );
	}

	function showRefreshError( btn ) {
		const wrap = btn.closest( '.wpau-widget-refresh' );
		if ( ! wrap || wrap.querySelector( '.wpau-widget-error' ) ) {
			return;
		}
		const notice = document.createElement( 'div' );
		notice.className = 'notice notice-error inline wpau-widget-error';
		notice.setAttribute( 'role', 'alert' );
		notice.textContent = i18n.errorGeneric;
		wrap.appendChild( notice );
	}

	function clearRefreshError( btn ) {
		const wrap = btn.closest( '.wpau-widget-refresh' );
		const existing = wrap && wrap.querySelector( '.wpau-widget-error' );
		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}
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
