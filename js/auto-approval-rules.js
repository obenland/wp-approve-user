/**
 * Client-side behaviour for the auto-approval rules settings row.
 *
 * Progressive enhancement on top of the server-rendered list:
 *
 *   - Turns the "Add rule" submit button into a JS-driven clone of an existing
 *     row template, then resets its fields.
 *   - Handles "Remove" clicks without a round-trip (empty rows are dropped
 *     server-side on the next save).
 */
document.addEventListener( 'DOMContentLoaded', function () {
	const container = document.querySelector( '.wpau-auto-approve-rules' );
	if ( ! container ) {
		return;
	}

	const list = container.querySelector( '.wpau-auto-approve-rules-list' );
	const addButton = container.querySelector(
		'button[name="wpau_auto_approve_add_row"]'
	);
	if ( ! list || ! addButton ) {
		return;
	}

	// Make "Add rule" clone a row instead of submitting the form.
	addButton.setAttribute( 'type', 'button' );

	function nextIndex() {
		const rows = list.querySelectorAll( '.wpau-auto-approve-rule' );
		let max = -1;
		rows.forEach( function ( row ) {
			const input = row.querySelector( 'input[type="text"]' );
			if ( ! input ) {
				return;
			}
			const match = input.name.match(
				/auto_approve_rules\]\[(\d+)\]\[value\]/
			);
			if ( match ) {
				const idx = parseInt( match[ 1 ], 10 );
				if ( idx > max ) {
					max = idx;
				}
			}
		} );
		return max + 1;
	}

	function addRow() {
		const template = list.querySelector( '.wpau-auto-approve-rule' );
		if ( ! template ) {
			return;
		}

		const clone = template.cloneNode( true );
		const index = nextIndex();

		clone.querySelectorAll( '[name]' ).forEach( function ( element ) {
			element.name = element.name.replace(
				/auto_approve_rules\]\[\d+\]/,
				'auto_approve_rules][' + index + ']'
			);

			if ( 'INPUT' === element.tagName ) {
				element.value = '';
			}
			if ( 'SELECT' === element.tagName ) {
				element.selectedIndex = 0;
			}
		} );

		clone.querySelectorAll( '[id]' ).forEach( function ( element ) {
			element.id = element.id.replace( /-\d+$/, '-' + index );
		} );
		clone.querySelectorAll( '[for]' ).forEach( function ( element ) {
			element.setAttribute(
				'for',
				element.getAttribute( 'for' ).replace( /-\d+$/, '-' + index )
			);
		} );

		list.appendChild( clone );
		const newInput = clone.querySelector( 'input[type="text"]' );
		if ( newInput ) {
			newInput.focus();
		}
	}

	addButton.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		addRow();
	} );

	list.addEventListener( 'click', function ( event ) {
		const target = event.target.closest( '.wpau-remove-auto-approve-rule' );
		if ( ! target ) {
			return;
		}

		event.preventDefault();

		const rows = list.querySelectorAll( '.wpau-auto-approve-rule' );
		if ( rows.length <= 1 ) {
			// Keep one blank row so the no-JS fallback keeps working.
			const row = target.closest( '.wpau-auto-approve-rule' );
			if ( row ) {
				const input = row.querySelector( 'input[type="text"]' );
				const select = row.querySelector( 'select' );
				if ( input ) {
					input.value = '';
					input.focus();
				}
				if ( select ) {
					select.selectedIndex = 0;
				}
			}
			return;
		}

		const row = target.closest( '.wpau-auto-approve-rule' );
		if ( row && row.parentNode ) {
			row.parentNode.removeChild( row );
		}
	} );

	// Swap the input placeholder as the admin changes the rule type.
	list.addEventListener( 'change', function ( event ) {
		const select = event.target.closest(
			'select.wpau-auto-approve-rule-type'
		);
		if ( ! select ) {
			return;
		}
		const option = select.selectedOptions[ 0 ];
		const placeholder = option ? option.dataset.placeholder : '';
		const row = select.closest( '.wpau-auto-approve-rule' );
		const input =
			row && row.querySelector( '.wpau-auto-approve-rule-value' );
		if ( input && placeholder !== undefined ) {
			input.placeholder = placeholder;
		}
	} );

	// Drag-to-reorder: HTML5 native drag events, no third-party sortable.
	let dragSource = null;

	list.addEventListener( 'dragstart', function ( event ) {
		const row = event.target.closest( '.wpau-auto-approve-rule' );
		if ( ! row ) {
			return;
		}
		dragSource = row;
		row.classList.add( 'is-dragging' );
		if ( event.dataTransfer ) {
			event.dataTransfer.effectAllowed = 'move';
			// Firefox requires a non-empty payload to start the drag.
			event.dataTransfer.setData( 'text/plain', '' );
		}
	} );

	list.addEventListener( 'dragover', function ( event ) {
		if ( ! dragSource ) {
			return;
		}
		/*
		 * preventDefault on dragover declares this list a valid drop target.
		 * Without it the browser refuses the drop and reverts visually.
		 */
		event.preventDefault();

		const row = event.target.closest( '.wpau-auto-approve-rule' );
		if ( ! row || row === dragSource ) {
			return;
		}
		const rect = row.getBoundingClientRect();
		const past = event.clientY - rect.top > rect.height / 2;
		list.insertBefore( dragSource, past ? row.nextSibling : row );
	} );

	/*
	 * preventDefault on drop suppresses the browser's "snap ghost back to
	 * origin" animation — without it the drag image flies back to where the
	 * row started even though the DOM already reflects the new order.
	 */
	list.addEventListener( 'drop', function ( event ) {
		if ( dragSource ) {
			event.preventDefault();
		}
	} );

	list.addEventListener( 'dragend', function () {
		if ( dragSource ) {
			dragSource.classList.remove( 'is-dragging' );
		}
		dragSource = null;
		renumberRows();
	} );

	// After a drop the DOM order is what the admin wants; renumber the
	// form-field indexes so the `auto_approve_rules[N]` keys reflect it.
	function renumberRows() {
		const rows = list.querySelectorAll( '.wpau-auto-approve-rule' );
		rows.forEach( function ( row, index ) {
			row.querySelectorAll( '[name]' ).forEach( function ( element ) {
				element.name = element.name.replace(
					/auto_approve_rules\]\[\d+\]/,
					'auto_approve_rules][' + index + ']'
				);
			} );
			row.querySelectorAll( '[id]' ).forEach( function ( element ) {
				element.id = element.id.replace( /-\d+$/, '-' + index );
			} );
			row.querySelectorAll( '[for]' ).forEach( function ( element ) {
				element.setAttribute(
					'for',
					element
						.getAttribute( 'for' )
						.replace( /-\d+$/, '-' + index )
				);
			} );
		} );
	}
} );
