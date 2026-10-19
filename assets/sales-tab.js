/* global window, document */
/**
 * Member Event – Tickets: event editor "Sales" tab.
 *
 * Same lifecycle as ticket-tab.js: the core event editor renders extension
 * tabs by setting innerHTML of #me-tab-content, so our container
 * (#pcio-met-sales) is created and destroyed on every tab switch. A
 * MutationObserver (re)renders whenever the container appears.
 *
 * Config is provided on window.PCIO_MET_SALES (via wp_add_inline_script).
 * Data comes from GET /tickets/events/{eid}/sales. Refund/resend reuse the
 * existing order endpoints POST /tickets/orders/{id}/refund|resend.
 *
 * Styling: .me-table-card + .me-toolbar come from the shared core stylesheet
 * (members.css, loaded on every vis/ page). The pcio-mets-* rules in
 * sales-tab.css mirror the members list: black gradient header row, filter
 * row beneath it, light rounded card around the whole list.
 */
( function () {
	'use strict';

	var CFG = window.PCIO_MET_SALES;
	if ( ! CFG ) {
		return;
	}

	var I = CFG.i18n || {};
	var sortKey = 'entry_date';
	var sortDir = -1;
	var filters = {
		entry_date: '',
		order_no: '',
		buyer: '',
		email: '',
		products: '',
		amount_cents: '',
		status: ''
	};

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function money( cents, currency ) {
		var v = ( parseInt( cents, 10 ) || 0 ) / 100;
		try {
			return v.toLocaleString( document.documentElement.lang || 'da-DK', {
				style: 'currency',
				currency: currency || 'DKK'
			} );
		} catch ( e ) {
			return v.toFixed( 2 ) + ' ' + ( currency || 'DKK' );
		}
	}

	function api( method, path, body ) {
		return fetch( CFG.rest + path, {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce
			},
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) || 'Request failed' );
					err.status = res.status;
					throw err;
				}
				return data;
			} );
		} );
	}

	function setSort( container, key ) {
		if ( sortKey === key ) {
			sortDir = -sortDir;
		} else {
			sortKey = key;
			sortDir = 1;
		}
		render( container );
	}

	function filteredOrders( container ) {
		return ( container._orders || [] ).filter( function ( o ) {
			for ( var key in filters ) {
				var needle = ( filters[ key ] || '' ).toLowerCase();
				if ( ! needle ) {
					continue;
				}
				var hay = key === 'amount_cents'
					? money( o.amount_cents, o.currency ).toLowerCase() + ' ' + String( o.amount_cents )
					: String( o[ key ] == null ? '' : o[ key ] ).toLowerCase();
				if ( hay.indexOf( needle ) === -1 ) {
					return false;
				}
			}
			return true;
		} );
	}

	function sortedOrders( rows ) {
		var orders = rows.slice();
		orders.sort( function ( a, b ) {
			var va = a[ sortKey ], vb = b[ sortKey ];
			if ( sortKey === 'amount_cents' ) {
				va = parseInt( va, 10 ) || 0;
				vb = parseInt( vb, 10 ) || 0;
			} else {
				va = String( va == null ? '' : va ).toLowerCase();
				vb = String( vb == null ? '' : vb ).toLowerCase();
			}
			if ( va < vb ) {
				return -sortDir;
			}
			if ( va > vb ) {
				return sortDir;
			}
			return 0;
		} );
		return orders;
	}

	function th( key, label ) {
		var arrow = sortKey === key ? ( sortDir === 1 ? ' ▲' : ' ▼' ) : '';
		return '<th class="pcio-mets-sortable"><button type="button" class="pcio-mets-sort" data-sort="' + key + '">' + esc( label ) + esc( arrow ) + '</button></th>';
	}

	function filterCell( key ) {
		return '<td><input type="search" class="pcio-mets-filter" data-filter="' + key + '" value="' + esc( filters[ key ] || '' ) + '" aria-label="Filter"></td>';
	}

	function render( container ) {
		var summary = container._summary || [];
		var orders = sortedOrders( filteredOrders( container ) );
		var total = ( container._orders || [] ).length;
		var currency = total ? container._orders[0].currency : 'DKK';

		var html = '<div class="me-table-card pcio-mets-summary"><div class="me-toolbar"><span class="me-toolbar-info">'
			+ esc( I.summaryTitle || 'Sales by ticket type' ) + '</span></div>'
			+ '<table class="pcio-mets-table"><thead><tr class="pcio-mets-head-row">'
			+ '<th>' + esc( I.colType || 'Ticket type' ) + '</th>'
			+ '<th>' + esc( I.colSold || 'Sold' ) + '</th>'
			+ '<th>' + esc( I.colGross || 'Gross' ) + '</th>'
			+ '<th>' + esc( I.colRefunded || 'Refunded' ) + '</th>'
			+ '</tr></thead><tbody>';
		var totalSold = 0, totalGross = 0, totalRef = 0;
		summary.forEach( function ( row ) {
			totalSold += row.sold;
			totalGross += row.gross;
			totalRef += row.refunded;
			html += '<tr><td>' + esc( row.name ) + '</td><td>' + row.sold + '</td><td>'
				+ esc( money( row.gross, currency ) ) + '</td><td>'
				+ esc( money( row.refunded, currency ) ) + '</td></tr>';
		} );
		html += '</tbody><tfoot><tr><td><strong>Total</strong></td><td><strong>' + totalSold + '</strong></td><td><strong>'
			+ esc( money( totalGross, currency ) ) + '</strong></td><td><strong>'
			+ esc( money( totalRef, currency ) ) + '</strong></td></tr></tfoot></table></div>';

		html += '<div class="me-table-card pcio-mets-card"><div class="me-toolbar"><span class="me-toolbar-info">'
			+ esc( ( I.ordersTitle || 'Orders' ) + ' — ' + orders.length + ' / ' + total )
			+ '</span></div>';
		if ( ! orders.length && ! total ) {
			html += '<p style="padding:16px 20px">' + esc( I.noOrders || 'No orders yet for this event.' ) + '</p>';
		} else {
			html += '<table class="pcio-mets-table"><thead>'
				+ '<tr class="pcio-mets-head-row">'
				+ th( 'entry_date', I.colDate || 'Date' )
				+ th( 'order_no', I.colOrder || 'Order' )
				+ th( 'buyer', I.colBuyer || 'Buyer' )
				+ th( 'email', I.colEmail || 'Email' )
				+ '<th>' + esc( I.colProducts || 'Tickets' ) + '</th>'
				+ th( 'amount_cents', I.colAmount || 'Amount' )
				+ th( 'status', I.colStatus || 'Status' )
				+ '<th>' + esc( I.colStripe || 'Stripe' ) + '</th>'
				+ '<th></th>'
				+ '</tr>'
				+ '<tr class="pcio-mets-filter-row">'
				+ filterCell( 'entry_date' )
				+ filterCell( 'order_no' )
				+ filterCell( 'buyer' )
				+ filterCell( 'email' )
				+ filterCell( 'products' )
				+ filterCell( 'amount_cents' )
				+ filterCell( 'status' )
				+ '<td></td><td></td>'
				+ '</tr></thead><tbody>';
			orders.forEach( function ( o ) {
				html += '<tr data-order="' + o.id + '">'
					+ '<td>' + esc( o.entry_date ) + '</td>'
					+ '<td>' + esc( o.order_no ) + '</td>'
					+ '<td>' + esc( o.buyer ) + '</td>'
					+ '<td>' + esc( o.email ) + '</td>'
					+ '<td>' + esc( o.products ) + '</td>'
					+ '<td>' + esc( money( o.amount_cents, o.currency ) )
					+ ( o.refunded_cents > 0 ? ' <span class="pcio-mets-refunded">(−' + esc( money( o.refunded_cents, o.currency ) ) + ')</span>' : '' )
					+ '</td>'
					+ '<td>' + esc( o.status ) + '</td>'
					+ '<td>' + ( o.stripe_url ? '<a href="' + esc( o.stripe_url ) + '" target="_blank" rel="noopener">' + esc( I.stripeView || 'View' ) + '</a>' : '' ) + '</td>'
					+ '<td class="pcio-mets-actions">'
					+ ( o.can_refund ? '<button type="button" class="me-btn me-btn-sm me-btn-danger" data-act="refund">' + esc( I.refund || 'Refund' ) + '</button> ' : '' )
					+ ( o.can_resend ? '<button type="button" class="me-btn me-btn-sm me-btn-send" data-act="resend">' + esc( I.resend || 'Resend email' ) + '</button>' : '' )
					+ '</td></tr>';
			} );
			html += '</tbody></table>';
		}
		html += '</div>';
		var active = document.activeElement;
		var activeFilter = active && active.dataset ? active.dataset.filter : null;
		var activePos = active && active.selectionStart != null ? active.selectionStart : null;
		container.innerHTML = html;
		if ( activeFilter ) {
			var restored = container.querySelector( '[data-filter="' + activeFilter + '"]' );
			if ( restored ) {
				restored.focus();
				if ( activePos != null ) {
					try {
						restored.setSelectionRange( activePos, activePos );
					} catch ( e ) {
						// Ignore; focus restore is best-effort.
					}
				}
			}
		}
	}

	function reload( container ) {
		container.innerHTML = '<p class="pcio-met-loading">' + esc( I.loading || 'Loading sales…' ) + '</p>';
		api( 'GET', '/events/' + CFG.eventId + '/sales' ).then( function ( data ) {
			container._summary = data.summary || [];
			container._orders = data.orders || [];
			render( container );
		} ).catch( function ( err ) {
			container.innerHTML = '<p class="pcio-met-error">' + esc( ( I.loadError || 'Could not load sales: ' ) + err.message ) + '</p>';
		} );
	}

	function onClick( e ) {
		var sortBtn = e.target.closest( '[data-sort]' );
		if ( sortBtn ) {
			var container = sortBtn.closest( '#pcio-met-sales' );
			if ( container ) {
				setSort( container, sortBtn.getAttribute( 'data-sort' ) );
			}
			return;
		}
		var actBtn = e.target.closest( '[data-act]' );
		if ( ! actBtn ) {
			return;
		}
		var row = actBtn.closest( 'tr[data-order]' );
		var container = actBtn.closest( '#pcio-met-sales' );
		if ( ! row || ! container ) {
			return;
		}
		var orderId = row.getAttribute( 'data-order' );
		var act = actBtn.getAttribute( 'data-act' );
		actBtn.disabled = true;
		var done = function () {
			actBtn.disabled = false;
			reload( container );
		};
		if ( act === 'refund' ) {
			if ( ! window.confirm( I.refundTitle || 'Refund order' ) ) {
				actBtn.disabled = false;
				return;
			}
			api( 'POST', '/orders/' + orderId + '/refund', {} ).then( done ).catch( function ( err ) {
				window.alert( ( I.refundFailed || 'Refund failed.' ) + ' ' + err.message );
				actBtn.disabled = false;
			} );
		} else if ( act === 'resend' ) {
			var emailCell = row.querySelector( 'td:nth-child(4)' );
			var addr = emailCell ? emailCell.textContent.trim() : '';
			var question = ( I.resendConfirm || 'Send tickets again to %s?' ).replace( '%s', addr );
			if ( ! window.confirm( question ) ) {
				actBtn.disabled = false;
				return;
			}
			api( 'POST', '/orders/' + orderId + '/resend', {} ).then( function () {
				window.alert( I.resendDone || 'The tickets have been sent.' );
				done();
			} ).catch( function ( err ) {
				window.alert( ( I.resendFailed || 'Could not resend.' ) + ' ' + err.message );
				actBtn.disabled = false;
			} );
		}
	}

	function onInput( e ) {
		var input = e.target.closest( '[data-filter]' );
		if ( ! input ) {
			return;
		}
		var container = input.closest( '#pcio-met-sales' );
		if ( ! container ) {
			return;
		}
		filters[ input.getAttribute( 'data-filter' ) ] = input.value;
		render( container );
	}

	document.addEventListener( 'click', onClick );
	document.addEventListener( 'input', onInput );

	var observer = new MutationObserver( function () {
		var container = document.getElementById( 'pcio-met-sales' );
		if ( container && ! container.dataset.bound ) {
			container.dataset.bound = '1';
			reload( container );
		}
	} );
	observer.observe( document.documentElement, { childList: true, subtree: true } );

	var initial = document.getElementById( 'pcio-met-sales' );
	if ( initial && ! initial.dataset.bound ) {
		initial.dataset.bound = '1';
		reload( initial );
	}
} )();
