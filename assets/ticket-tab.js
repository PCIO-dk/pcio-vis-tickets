/* global window, document */
/**
 * Member Event – Tickets: event editor "Tickets" tab.
 *
 * The core event editor renders extension tabs by setting innerHTML of
 * #me-tab-content. That means our container (#pcio-met-editor) is created and
 * destroyed every time the user switches tabs, so we watch the DOM with a
 * MutationObserver and (re)render whenever our container appears.
 *
 * Config is provided on window.PCIO_MET (assigned via wp_add_inline_script).
 */
( function () {
	'use strict';

	var CFG = window.PCIO_MET;
	if ( ! CFG ) {
		return;
	}

	var I = CFG.i18n || {};
	// Live working copy of the current state (kept in sync with the server).
	var state = {
		settings: CFG.settings || {
			max_guests: 0,
			is_soldout: 0,
			hide_remaining: 0,
			max_entrance_per_buyer: 0
		},
		types: Array.isArray( CFG.types ) ? CFG.types.slice() : [],
		capacity: CFG.capacity || null
	};

	var saveTimers = {};

	// ── REST helpers ──────────────────────────────────────────────

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
			if ( res.status === 204 ) {
				return null;
			}
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					var msg = data && data.message ? data.message : ( I.saveError || 'Save failed' );
					var err = new Error( msg );
					err.status = res.status;
					throw err;
				}
				return data;
			} );
		} );
	}

	// ── Small DOM utilities ───────────────────────────────────────

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( k === 'class' ) {
					node.className = attrs[ k ];
				} else if ( k === 'text' ) {
					node.textContent = attrs[ k ];
				} else if ( k === 'html' ) {
					node.innerHTML = attrs[ k ];
				} else if ( k.indexOf( 'data-' ) === 0 ) {
					node.setAttribute( k, attrs[ k ] );
				} else if ( k in node ) {
					node[ k ] = attrs[ k ];
				} else {
					node.setAttribute( k, attrs[ k ] );
				}
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( c == null ) {
				return;
			}
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function field( labelText, control, helpText ) {
		var kids = [ el( 'span', { class: 'pcio-met-field__label', text: labelText } ), control ];
		if ( helpText ) {
			kids.push( el( 'span', { class: 'pcio-met-field__help', text: helpText } ) );
		}
		return el( 'label', { class: 'pcio-met-field' }, kids );
	}

	function statusPill() {
		return el( 'span', { class: 'pcio-met-status', 'aria-live': 'polite' } );
	}

	function flashSaved( pill ) {
		if ( ! pill ) {
			return;
		}
		pill.textContent = I.saved || 'Saved';
		pill.className = 'pcio-met-status pcio-met-status--ok';
		window.clearTimeout( pill._t );
		pill._t = window.setTimeout( function () {
			pill.textContent = '';
			pill.className = 'pcio-met-status';
		}, 1600 );
	}

	function flashError( pill, msg ) {
		if ( ! pill ) {
			window.alert( msg );
			return;
		}
		pill.textContent = msg;
		pill.className = 'pcio-met-status pcio-met-status--err';
	}

	// Cents <-> kroner display.
	function centsToInput( cents ) {
		return ( Math.round( cents ) / 100 ).toString();
	}
	function inputToCents( value ) {
		var n = parseFloat( String( value ).replace( ',', '.' ) );
		if ( isNaN( n ) || n < 0 ) {
			n = 0;
		}
		return Math.round( n * 100 );
	}

	// ── Save scheduling (debounced per field) ─────────────────────

	function schedule( key, fn ) {
		window.clearTimeout( saveTimers[ key ] );
		saveTimers[ key ] = window.setTimeout( fn, 450 );
	}

	function saveSettings( patch, pill ) {
		Object.keys( patch ).forEach( function ( k ) {
			state.settings[ k ] = patch[ k ];
		} );
		schedule( 'settings', function () {
			api( 'PUT', '/events/' + CFG.eventId + '/settings', patch ).then( function ( data ) {
				if ( data ) {
					state.settings = data.settings || state.settings;
					state.capacity = data.capacity || state.capacity;
				}
				flashSaved( pill );
				refreshModeBanner();
				refreshCapacityNote();
			} ).catch( function ( e ) {
				flashError( pill, e.message );
			} );
		} );
	}

	function saveType( id, patch, pill ) {
		var t = findType( id );
		if ( t ) {
			Object.keys( patch ).forEach( function ( k ) {
				t[ k ] = patch[ k ];
			} );
		}
		schedule( 'type-' + id, function () {
			api( 'PUT', '/events/' + CFG.eventId + '/types/' + id, patch ).then( function ( data ) {
				if ( data && data.type ) {
					replaceType( data.type );
				}
				if ( data && data.capacity ) {
					state.capacity = data.capacity;
				}
				flashSaved( pill );
				refreshCapacityNote();
			} ).catch( function ( e ) {
				flashError( pill, e.message );
			} );
		} );
	}

	function findType( id ) {
		for ( var i = 0; i < state.types.length; i++ ) {
			if ( state.types[ i ].id === id ) {
				return state.types[ i ];
			}
		}
		return null;
	}

	function replaceType( type ) {
		for ( var i = 0; i < state.types.length; i++ ) {
			if ( state.types[ i ].id === type.id ) {
				state.types[ i ] = type;
				return;
			}
		}
		state.types.push( type );
	}

	// ── Rendering ─────────────────────────────────────────────────

	var root = null; // current #pcio-met-editor element

	function render( container ) {
		root = container;
		container.innerHTML = '';
		container.appendChild( renderSettings() );
		container.appendChild( renderTypes() );
		refreshModeBanner();
	}

	function renderSettings() {
		var wrap = el( 'section', { class: 'pcio-met-card' } );

		var banner = el( 'div', { class: 'pcio-met-mode', id: 'pcio-met-mode' } );
		wrap.appendChild( banner );

		var grid = el( 'div', { class: 'pcio-met-grid' } );

		// max_guests
		var maxPill = statusPill();
		var maxInput = el( 'input', {
			type: 'number',
			min: '0',
			step: '1',
			value: String( state.settings.max_guests || 0 ),
			class: 'pcio-met-input'
		} );
		maxInput.addEventListener( 'input', function () {
			var v = Math.max( 0, parseInt( maxInput.value, 10 ) || 0 );
			saveSettings( { max_guests: v }, maxPill );
		} );
		grid.appendChild( wrapField( field( I.maxGuests || 'Maximum number of guests', maxInput, I.maxGuestsHelp ), maxPill ) );

		// max_entrance_per_buyer
		var perPill = statusPill();
		var perInput = el( 'input', {
			type: 'number',
			min: '0',
			step: '1',
			value: String( state.settings.max_entrance_per_buyer || 0 ),
			class: 'pcio-met-input'
		} );
		perInput.addEventListener( 'input', function () {
			var v = Math.max( 0, parseInt( perInput.value, 10 ) || 0 );
			saveSettings( { max_entrance_per_buyer: v }, perPill );
		} );
		grid.appendChild( wrapField( field( I.maxPerBuyer || 'Max entrance tickets per buyer', perInput, I.maxPerBuyerHelp ), perPill ) );

		wrap.appendChild( grid );

		// Checkboxes row
		var checks = el( 'div', { class: 'pcio-met-checks' } );

		var soldoutPill = statusPill();
		checks.appendChild( checkbox( I.isSoldout || 'Mark as sold out', !! state.settings.is_soldout, function ( on ) {
			saveSettings( { is_soldout: on ? 1 : 0 }, soldoutPill );
		}, soldoutPill ) );

		var hidePill = statusPill();
		checks.appendChild( checkbox( I.hideRemaining || 'Hide remaining ticket count from buyers', !! state.settings.hide_remaining, function ( on ) {
			saveSettings( { hide_remaining: on ? 1 : 0 }, hidePill );
		}, hidePill ) );

		wrap.appendChild( checks );

		return wrap;
	}

	function wrapField( fieldNode, pill ) {
		return el( 'div', { class: 'pcio-met-field-wrap' }, [ fieldNode, pill ] );
	}

	function checkbox( labelText, checked, onChange, pill ) {
		var input = el( 'input', { type: 'checkbox' } );
		input.checked = checked;
		input.addEventListener( 'change', function () {
			onChange( input.checked );
		} );
		return el( 'label', { class: 'pcio-met-check' }, [
			input,
			el( 'span', { text: labelText } ),
			pill || null
		] );
	}

	function renderTypes() {
		var wrap = el( 'section', { class: 'pcio-met-card' } );

		var head = el( 'div', { class: 'pcio-met-types__head' }, [
			el( 'h3', { class: 'pcio-met-h3', text: I.ticketTypes || 'Ticket types' } ),
			el( 'span', { class: 'pcio-met-capnote', id: 'pcio-met-capnote' } )
		] );
		wrap.appendChild( head );

		var list = el( 'div', { class: 'pcio-met-typelist', id: 'pcio-met-typelist' } );
		wrap.appendChild( list );

		renderTypeList( list );

		var addBtn = el( 'button', {
			type: 'button',
			class: 'pcio-met-btn pcio-met-btn--add',
			text: '+ ' + ( I.addType || 'Add ticket type' )
		} );
		addBtn.addEventListener( 'click', function () {
			addBtn.disabled = true;
			api( 'POST', '/events/' + CFG.eventId + '/types', {
				name: I.newTypeName || 'New ticket',
				is_entrance: 1,
				price_cents: 0,
				qty_offered: 0,
				active: 1
			} ).then( function ( data ) {
				if ( data && data.type ) {
					state.types.push( data.type );
					if ( data.capacity ) {
						state.capacity = data.capacity;
					}
					renderTypeList( document.getElementById( 'pcio-met-typelist' ) );
					refreshCapacityNote();
				}
			} ).catch( function ( e ) {
				window.alert( e.message );
			} ).then( function () {
				addBtn.disabled = false;
			} );
		} );
		wrap.appendChild( addBtn );

		refreshCapacityNote();
		return wrap;
	}

	function renderTypeList( list ) {
		if ( ! list ) {
			return;
		}
		list.innerHTML = '';
		if ( ! state.types.length ) {
			list.appendChild( el( 'p', { class: 'pcio-met-empty', text: I.noTypes || 'No ticket types yet. Add one to start selling.' } ) );
			return;
		}
		state.types.forEach( function ( t ) {
			list.appendChild( renderTypeCard( t ) );
		} );
	}

	function renderTypeCard( t ) {
		var card = el( 'div', { class: 'pcio-met-type', 'data-id': String( t.id ) } );

		var pill = statusPill();

		var header = el( 'div', { class: 'pcio-met-type__head' }, [
			el( 'span', { class: 'pcio-met-type__badge' + ( t.is_entrance ? ' is-entrance' : '' ),
				text: t.is_entrance ? '🎟' : '＋' } ),
			pill
		] );
		card.appendChild( header );

		var grid = el( 'div', { class: 'pcio-met-grid' } );

		// Name
		var nameInput = el( 'input', { type: 'text', class: 'pcio-met-input', value: t.name || '' } );
		nameInput.addEventListener( 'input', function () {
			saveType( t.id, { name: nameInput.value }, pill );
		} );
		grid.appendChild( field( I.name || 'Name', nameInput ) );

		// Price
		var priceInput = el( 'input', {
			type: 'number', min: '0', step: '0.01', class: 'pcio-met-input',
			value: centsToInput( t.price_cents || 0 )
		} );
		priceInput.addEventListener( 'input', function () {
			saveType( t.id, { price_cents: inputToCents( priceInput.value ) }, pill );
		} );
		grid.appendChild( field( I.price || 'Price (DKK)', priceInput ) );

		// Qty offered
		var offeredInput = el( 'input', {
			type: 'number', min: '0', step: '1', class: 'pcio-met-input',
			value: String( t.qty_offered || 0 )
		} );
		offeredInput.addEventListener( 'input', function () {
			var v = Math.max( 0, parseInt( offeredInput.value, 10 ) || 0 );
			saveType( t.id, { qty_offered: v }, pill );
		} );
		grid.appendChild( field( I.qtyOffered || 'Quantity offered', offeredInput ) );

		// Qty sold (readonly)
		var soldInput = el( 'input', {
			type: 'number', class: 'pcio-met-input', readOnly: true, tabIndex: -1,
			value: String( t.qty_sold || 0 )
		} );
		grid.appendChild( field( I.qtySold || 'Sold', soldInput ) );

		// Sales start
		var startInput = el( 'input', {
			type: 'datetime-local', class: 'pcio-met-input',
			value: toLocalInput( t.sales_start )
		} );
		startInput.addEventListener( 'change', function () {
			saveType( t.id, { sales_start: fromLocalInput( startInput.value ) }, pill );
		} );
		grid.appendChild( field( I.salesStart || 'Sales start', startInput ) );

		// Sales stop
		var stopInput = el( 'input', {
			type: 'datetime-local', class: 'pcio-met-input',
			value: toLocalInput( t.sales_stop )
		} );
		stopInput.addEventListener( 'change', function () {
			saveType( t.id, { sales_stop: fromLocalInput( stopInput.value ) }, pill );
		} );
		grid.appendChild( field( I.salesStop || 'Sales stop', stopInput ) );

		card.appendChild( grid );

		// Footer: is_entrance + active + delete
		var footer = el( 'div', { class: 'pcio-met-type__foot' } );

		footer.appendChild( checkbox( I.isEntrance || 'Entrance ticket (counts towards capacity)', !! t.is_entrance, function ( on ) {
			saveType( t.id, { is_entrance: on ? 1 : 0 }, pill );
			var badge = header.querySelector( '.pcio-met-type__badge' );
			if ( badge ) {
				badge.classList.toggle( 'is-entrance', on );
				badge.textContent = on ? '🎟' : '＋';
			}
		} ) );

		footer.appendChild( checkbox( I.active || 'Active', !! t.active, function ( on ) {
			saveType( t.id, { active: on ? 1 : 0 }, pill );
		} ) );

		var delBtn = el( 'button', { type: 'button', class: 'pcio-met-btn pcio-met-btn--danger', text: I.deleteType || 'Delete' } );
		if ( t.qty_sold > 0 ) {
			delBtn.disabled = true;
			delBtn.title = I.cannotDelete || '';
		}
		delBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( I.confirmDelete || 'Delete this ticket type?' ) ) {
				return;
			}
			delBtn.disabled = true;
			api( 'DELETE', '/events/' + CFG.eventId + '/types/' + t.id ).then( function ( data ) {
				state.types = state.types.filter( function ( x ) {
					return x.id !== t.id;
				} );
				if ( data && data.capacity ) {
					state.capacity = data.capacity;
				}
				renderTypeList( document.getElementById( 'pcio-met-typelist' ) );
				refreshCapacityNote();
			} ).catch( function ( e ) {
				delBtn.disabled = false;
				window.alert( e.message );
			} );
		} );
		footer.appendChild( delBtn );

		card.appendChild( footer );
		return card;
	}

	// datetime-local <-> "YYYY-MM-DD HH:MM:SS"
	function toLocalInput( mysql ) {
		if ( ! mysql ) {
			return '';
		}
		return String( mysql ).replace( ' ', 'T' ).slice( 0, 16 );
	}
	function fromLocalInput( value ) {
		if ( ! value ) {
			return null;
		}
		var v = value.replace( 'T', ' ' );
		return v.length === 16 ? v + ':00' : v;
	}

	// ── Live banners ──────────────────────────────────────────────

	function refreshModeBanner() {
		var banner = document.getElementById( 'pcio-met-mode' );
		if ( ! banner ) {
			return;
		}
		var tickets = ( state.settings.max_guests || 0 ) > 0;
		banner.className = 'pcio-met-mode' + ( tickets ? ' is-tickets' : ' is-member' );
		banner.innerHTML = '';
		banner.appendChild( el( 'strong', { text: tickets ? ( I.modeTickets || 'Ticket sales event' ) : ( I.modeMember || 'Member sign-up event' ) } ) );
		banner.appendChild( el( 'span', { class: 'pcio-met-mode__hint', text: I.modeHint || '' } ) );
	}

	function refreshCapacityNote() {
		var note = document.getElementById( 'pcio-met-capnote' );
		if ( ! note ) {
			return;
		}
		var cap = state.capacity;
		if ( ! cap || cap.maxGuests <= 0 ) {
			note.textContent = '';
			return;
		}
		var avail = cap.entranceAvailable < 0 ? '∞' : cap.entranceAvailable;
		note.textContent = ( I.capacityNote || 'Entrance tickets sold:' ) + ' ' +
			cap.entranceSold + ' / ' + cap.maxGuests + ' (' + avail + ')';
	}

	// ── Mount / observe ───────────────────────────────────────────

	function tryMount() {
		var container = document.getElementById( 'pcio-met-editor' );
		if ( container && container.getAttribute( 'data-mounted' ) !== '1' ) {
			container.setAttribute( 'data-mounted', '1' );
			render( container );
		}
	}

	function boot() {
		tryMount();
		var host = document.getElementById( 'me-tab-content' ) || document.body;
		var obs = new MutationObserver( function () {
			tryMount();
		} );
		obs.observe( host, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
