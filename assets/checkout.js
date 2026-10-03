/* global window, document, fetch */
/**
 * Member Event – Tickets: public checkout page.
 *
 * Config is on window.PCIO_VIS_Tickets_CHECKOUT (assigned via wp_add_inline_script).
 * The server re-validates everything; this script only improves UX (live total,
 * quantity limits, basic validation) and posts the order, then redirects to the
 * URL the server returns (Stripe for paid, the accepted page for free).
 */
( function () {
	'use strict';

	var CFG = window.PCIO_VIS_Tickets_CHECKOUT;
	if ( ! CFG ) {
		return;
	}
	var I = CFG.i18n || {};

	var form = document.getElementById( 'met-checkout-form' );
	if ( ! form ) {
		return;
	}

	var totalEl  = document.getElementById( 'met-total-amount' );
	var submit   = document.getElementById( 'met-checkout-submit' );
	var errorBox = document.getElementById( 'met-checkout-error' );
	var rows     = Array.prototype.slice.call( document.querySelectorAll( '.met-type' ) );

	function money( cents ) {
		return ( cents / 100 ).toFixed( 2 ) + ' ' + CFG.currency;
	}

	function rowQtyInput( row ) {
		return row.querySelector( '.met-qty__input' );
	}

	function selection() {
		var items = [];
		var totalCents = 0;
		var entrance = 0;
		rows.forEach( function ( row ) {
			var qty = parseInt( rowQtyInput( row ).value, 10 ) || 0;
			if ( qty < 1 ) {
				return;
			}
			var price = parseInt( row.getAttribute( 'data-price' ), 10 ) || 0;
			var isEnt = row.getAttribute( 'data-entrance' ) === '1';
			totalCents += price * qty;
			if ( isEnt ) {
				entrance += qty;
			}
			items.push( { type_id: parseInt( row.getAttribute( 'data-type' ), 10 ), qty: qty } );
		} );
		return { items: items, totalCents: totalCents, entrance: entrance };
	}

	function refresh() {
		var sel = selection();
		if ( totalEl ) {
			totalEl.textContent = sel.totalCents <= 0 && sel.items.length
				? ( I.free || 'Free' )
				: money( sel.totalCents );
		}
		if ( submit ) {
			submit.disabled = sel.items.length === 0;
			submit.textContent = sel.items.length && sel.totalCents <= 0
				? ( I.getFree || 'Get tickets' )
				: ( I.payNow || 'Continue to payment' );
		}
	}

	function clampAdjust( row, delta ) {
		var input = rowQtyInput( row );
		var max = parseInt( row.getAttribute( 'data-max' ), 10 );
		if ( isNaN( max ) || max < 0 ) {
			max = 0;
		}
		var next = ( parseInt( input.value, 10 ) || 0 ) + delta;
		if ( next < 0 ) {
			next = 0;
		}
		if ( next > max ) {
			next = max;
		}

		// Enforce the per-buyer entrance cap across all entrance rows.
		if ( delta > 0 && CFG.maxPerBuyer > 0 && row.getAttribute( 'data-entrance' ) === '1' ) {
			var sel = selection();
			if ( sel.entrance >= CFG.maxPerBuyer ) {
				showError( ( I.maxPerBuyer || 'You can buy at most %d entrance tickets.' ).replace( '%d', CFG.maxPerBuyer ) );
				return;
			}
		}
		clearError();
		input.value = String( next );
		refresh();
	}

	function showError( msg ) {
		if ( ! errorBox ) {
			window.alert( msg );
			return;
		}
		errorBox.textContent = msg;
		errorBox.hidden = false;
	}
	function clearError() {
		if ( errorBox ) {
			errorBox.hidden = true;
			errorBox.textContent = '';
		}
	}

	// Quantity steppers
	rows.forEach( function ( row ) {
		row.querySelectorAll( '.met-qty__btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				clampAdjust( row, btn.getAttribute( 'data-act' ) === 'inc' ? 1 : -1 );
			} );
		} );
	} );

	// Submit
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		clearError();

		var sel = selection();
		if ( ! sel.items.length ) {
			showError( I.selectOne || 'Please select at least one ticket.' );
			return;
		}

		var name  = ( form.querySelector( '#met-name' ).value || '' ).trim();
		var email = ( form.querySelector( '#met-email' ).value || '' ).trim();
		var phone = ( form.querySelector( '#met-phone' ).value || '' ).trim();

		if ( ! name ) {
			showError( I.needName || 'Please enter your name.' );
			return;
		}
		if ( ! email || email.indexOf( '@' ) < 1 ) {
			showError( I.needEmail || 'Please enter a valid email address.' );
			return;
		}

		submit.disabled = true;
		submit.textContent = I.processing || 'Processing…';

		fetch( CFG.rest, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.restNonce || ''
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				nonce: CFG.nonce,
				event_id: CFG.eventId,
				name: name,
				email: email,
				phone: phone,
				items: sel.items
			} )
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( data && data.message ? data.message : ( I.genericErr || 'Something went wrong.' ) );
				}
				return data;
			} );
		} ).then( function ( data ) {
			if ( data && data.redirect ) {
				window.location.href = data.redirect;
			} else {
				throw new Error( I.genericErr || 'Something went wrong.' );
			}
		} ).catch( function ( err ) {
			showError( err.message );
			submit.disabled = false;
			refresh();
		} );
	} );

	refresh();
} )();
