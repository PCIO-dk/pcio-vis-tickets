/* global window, document */
/**
 * Tickets extension – guest self-activation ("Use ticket").
 *
 * Ported from the legacy activate_billet.php inline script: per-type
 * quantity selector, drag-to-swipe activation, 5-minute animated countdown
 * overlay (live clock + moving background prevent screenshot fraud).
 * Config on window.PCIO_MET_ACTIVATE. POSTs back to the same URL.
 */
( function () {
	'use strict';

	var CFG = window.PCIO_MET_ACTIVATE;
	if ( ! CFG ) {
		return;
	}
	var I = CFG.i18n || {};
	var DATA = CFG.data;
	var COUNTDOWN_SECS = 5 * 60;
	var PAD = 5;

	var wrap = document.getElementById( 'swipe-wrap' );
	if ( ! wrap ) {
		return; // No swipe control (error/already-used state).
	}
	var thumb = document.getElementById( 'swipe-thumb' );
	var label = document.getElementById( 'swipe-label' );
	var errorBox = document.getElementById( 'error-box' );

	function escHtml( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	document.querySelectorAll( '.ticket-row' ).forEach( function ( row ) {
		var max = parseInt( row.dataset.max, 10 );
		var valEl = row.querySelector( '.qty-val' );
		var minus = row.querySelector( '.qty-minus' );
		var plus = row.querySelector( '.qty-plus' );

		function getVal() {
			return parseInt( valEl.textContent, 10 );
		}
		function setVal( v ) {
			v = Math.max( 0, Math.min( v, max ) );
			valEl.textContent = v;
			minus.disabled = ( v <= 0 );
			plus.disabled = ( v >= max );
			row.classList.toggle( 'qty-zero', v === 0 );
			updateSwipeLabel();
		}

		minus.addEventListener( 'click', function () { setVal( getVal() - 1 ); } );
		plus.addEventListener( 'click', function () { setVal( getVal() + 1 ); } );
	} );

	function getSelections() {
		var sel = {};
		document.querySelectorAll( '.ticket-row' ).forEach( function ( row ) {
			var tid = row.dataset.typeId;
			var qty = parseInt( row.querySelector( '.qty-val' ).textContent, 10 );
			if ( qty > 0 ) {
				sel[ tid ] = qty;
			}
		} );
		return sel;
	}

	function totalSelected() {
		var n = 0;
		var sel = getSelections();
		for ( var k in sel ) {
			n += sel[ k ];
		}
		return n;
	}

	function updateSwipeLabel() {
		var n = totalSelected();
		label.textContent = n > 0
			? '→ ' + ( I.swipeN || 'Swipe to activate %d tickets' ).replace( '%d', n ) + ' →'
			: '→ ' + ( I.swipe || 'Swipe to activate' ) + ' →';
	}
	updateSwipeLabel();

	var dragging = false;
	var startX = 0;
	var thumbLeft = 0;

	function maxLeft() {
		return wrap.offsetWidth - thumb.offsetWidth - PAD * 2;
	}

	function onStart( cx ) {
		if ( totalSelected() < 1 ) {
			return;
		}
		dragging = true;
		startX = cx - thumbLeft;
		thumb.classList.add( 'dragging' );
		errorBox.style.display = 'none';
	}

	function onMove( cx ) {
		if ( ! dragging ) {
			return;
		}
		var pos = Math.max( 0, Math.min( cx - startX, maxLeft() ) );
		thumbLeft = pos;
		thumb.style.left = ( PAD + pos ) + 'px';
		label.style.opacity = String( 1 - pos / maxLeft() );
	}

	function onEnd() {
		if ( ! dragging ) {
			return;
		}
		dragging = false;
		thumb.classList.remove( 'dragging' );
		if ( thumbLeft / maxLeft() >= 0.90 ) {
			doActivate();
		} else {
			resetThumb();
		}
	}

	function resetThumb() {
		thumbLeft = 0;
		thumb.style.left = PAD + 'px';
		label.style.opacity = '1';
	}

	thumb.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); onStart( e.clientX ); } );
	document.addEventListener( 'mousemove', function ( e ) { onMove( e.clientX ); } );
	document.addEventListener( 'mouseup', function () { onEnd(); } );

	thumb.addEventListener( 'touchstart', function ( e ) {
		e.preventDefault();
		onStart( e.touches[0].clientX );
	}, { passive: false } );
	document.addEventListener( 'touchmove', function ( e ) {
		if ( dragging ) {
			e.preventDefault();
			onMove( e.touches[0].clientX );
		}
	}, { passive: false } );
	document.addEventListener( 'touchend', function () { onEnd(); } );

	function doActivate() {
		wrap.style.pointerEvents = 'none';
		thumb.classList.add( 'success' );
		thumb.textContent = '…';
		label.style.opacity = '0';

		fetch( window.location.href, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { data: DATA, selections: getSelections() } )
		} ).then( function ( res ) {
			return res.json();
		} ).then( function ( json ) {
			if ( json.success ) {
				showCountdown( json.activated || [] );
			} else {
				showError( json.error || ( I.failed || 'Activation failed.' ) );
				thumb.classList.remove( 'success' );
				thumb.textContent = '🎫';
				wrap.style.pointerEvents = '';
				resetThumb();
			}
		} ).catch( function () {
			showError( I.failed || 'Activation failed.' );
			thumb.classList.remove( 'success' );
			thumb.textContent = '🎫';
			wrap.style.pointerEvents = '';
			resetThumb();
		} );
	}

	function showError( msg ) {
		errorBox.textContent = msg;
		errorBox.style.display = 'block';
	}

	function showCountdown( activated ) {
		var overlay = document.getElementById( 'cd-overlay' );
		var canvas = document.getElementById( 'cd-canvas' );
		var timerEl = document.getElementById( 'cd-timer' );
		var clockEl = document.getElementById( 'cd-clock' );
		var labelEl = document.getElementById( 'cd-label' );
		var checkEl = document.getElementById( 'cd-check' );
		var summaryEl = document.getElementById( 'cd-summary' );
		var expiredEl = document.getElementById( 'cd-expired' );

		if ( activated.length > 0 ) {
			summaryEl.innerHTML = activated.map( function ( a ) {
				return '<div>' + a.count + '×&nbsp;' + escHtml( a.name ) + '</div>';
			} ).join( '' );
		}

		overlay.style.display = 'block';

		var ctx = canvas.getContext( '2d' );
		var W, H;
		function resize() {
			W = canvas.width = window.innerWidth;
			H = canvas.height = window.innerHeight;
		}
		resize();
		window.addEventListener( 'resize', resize );

		function newParticle( fromBottom ) {
			return {
				x: Math.random() * ( W || 400 ),
				y: fromBottom ? ( H || 800 ) + 20 : Math.random() * ( H || 800 ),
				r: 5 + Math.random() * 18,
				vx: ( Math.random() - 0.5 ) * 0.55,
				vy: -( 0.35 + Math.random() * 0.75 ),
				a: 0.10 + Math.random() * 0.38,
				h: 180 + Math.random() * 100
			};
		}
		var particles = [];
		for ( var i = 0; i < 60; i++ ) {
			particles.push( newParticle( false ) );
		}
		var hue = 0, af;

		function drawFrame() {
			hue = ( hue + 0.35 ) % 360;
			var g = ctx.createLinearGradient( 0, 0, W, H );
			g.addColorStop( 0, 'hsl(' + ( ( 220 + hue * 0.55 ) % 360 ) + ', 65%, 17%)' );
			g.addColorStop( 0.5, 'hsl(' + ( ( 255 + hue * 0.38 ) % 360 ) + ', 58%, 12%)' );
			g.addColorStop( 1, 'hsl(' + ( ( 195 + hue * 0.28 ) % 360 ) + ', 72%, 15%)' );
			ctx.fillStyle = g;
			ctx.fillRect( 0, 0, W, H );
			for ( var j = 0; j < particles.length; j++ ) {
				var p = particles[j];
				ctx.beginPath();
				ctx.arc( p.x, p.y, p.r, 0, 6.2832 );
				ctx.fillStyle = 'hsla(' + p.h + ', 90%, 72%, ' + p.a + ')';
				ctx.fill();
				p.x += p.vx;
				p.y += p.vy;
				p.h = ( p.h + 0.4 ) % 360;
				if ( p.y < -p.r * 2 ) {
					particles[j] = newParticle( true );
					particles[j].x = Math.random() * W;
				}
			}
			af = requestAnimationFrame( drawFrame );
		}
		af = requestAnimationFrame( drawFrame );

		var remaining = COUNTDOWN_SECS;
		function fmt( n ) {
			return String( n ).padStart( 2, '0' );
		}

		var countIv = setInterval( function () {
			remaining--;
			if ( remaining <= 0 ) {
				clearInterval( countIv );
				cancelAnimationFrame( af );
				timerEl.style.display = 'none';
				labelEl.style.display = 'none';
				checkEl.textContent = '✕';
				summaryEl.style.display = 'none';
				expiredEl.style.display = 'block';
				canvas.style.display = 'none';
				overlay.style.background = '#b71c1c';
			} else {
				timerEl.textContent = Math.floor( remaining / 60 ) + ':' + fmt( remaining % 60 );
			}
		}, 1000 );

		function updateClock() {
			var d = new Date();
			clockEl.textContent = fmt( d.getHours() ) + ':' + fmt( d.getMinutes() ) + ':' + fmt( d.getSeconds() );
		}
		updateClock();
		setInterval( updateClock, 1000 );
	}
} )();
