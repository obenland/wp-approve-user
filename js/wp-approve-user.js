document.addEventListener( 'DOMContentLoaded', function () {
	// Select all table rows
	document.querySelectorAll( 'tr' ).forEach( function ( row ) {
		// Check if the row has an element with class 'submitapprove'
		const hasSubmitApprove = row.querySelector( '.submitapprove' ) !== null;
		// Check if the row has an element with class 'submitunapprove'
		const hasSubmitUnapprove =
			row.querySelector( '.submitunapprove' ) !== null;

		// If the row has both 'submitapprove' and 'submitunapprove' elements, change background color to '#FFFFE0'
		if ( hasSubmitApprove && hasSubmitUnapprove ) {
			row.style.backgroundColor = '#FFFFE0';
		}
		// If the row has only 'submitapprove' element, change background color to '#FAAFAA'
		else if ( hasSubmitApprove ) {
			row.style.backgroundColor = '#FAAFAA';
		}
	} );

	// Append options to the select elements with name starting with 'action' inside elements with class 'actions'
	document
		.querySelectorAll( '.actions select[name^="action"]' )
		.forEach( function ( select ) {
			const optionApprove = document.createElement( 'option' );
			optionApprove.value = 'wpau_bulk_approve';
			optionApprove.textContent = wp_approve_user.approve;
			select.appendChild( optionApprove );

			const optionUnapprove = document.createElement( 'option' );
			optionUnapprove.value = 'wpau_bulk_unapprove';
			optionUnapprove.textContent = wp_approve_user.unapprove;
			select.appendChild( optionUnapprove );
		} );
} );
