/* ================================================================
   PCIO VIS Tickets — admin settings
   Media picker for the event report logo (Vis → Tickets).
   ================================================================ */

( function () {
    'use strict';

    document.addEventListener( 'click', ( event ) => {
        const pick = event.target.closest( '[data-media-pick]' );
        if ( pick ) {
            event.preventDefault();
            const target  = document.getElementById( pick.dataset.target );
            const preview = document.getElementById( pick.dataset.preview );
            if ( ! target || ! window.wp || ! wp.media ) return;

            const frame = wp.media( {
                title:    pick.dataset.title || 'Select image',
                library:  { type: 'image' },
                multiple: false,
            } );

            frame.on( 'select', () => {
                const attachment = frame.state().get( 'selection' ).first().toJSON();
                target.value = String( attachment.id || 0 );
                if ( preview ) {
                    preview.innerHTML = '';
                    const img = document.createElement( 'img' );
                    img.src = attachment.url;
                    img.alt = '';
                    img.style.maxWidth = '220px';
                    img.style.maxHeight = '80px';
                    preview.appendChild( img );
                }
            } );

            frame.open();
            return;
        }

        const clear = event.target.closest( '[data-media-clear]' );
        if ( clear ) {
            event.preventDefault();
            const target  = document.getElementById( clear.dataset.target );
            const preview = document.getElementById( clear.dataset.preview );
            if ( target ) target.value = '0';
            if ( preview ) {
                const span = document.createElement( 'span' );
                span.className = 'description';
                span.textContent = clear.dataset.emptyText || '';
                preview.innerHTML = '';
                preview.appendChild( span );
            }
        }
    } );
}() );
