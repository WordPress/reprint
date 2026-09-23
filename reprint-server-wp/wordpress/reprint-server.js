( function() {
	'use strict';

	var token = document.getElementById( 'reprint_server_connection_token' );
	var toggle = document.querySelector( '.reprint-server-toggle-token' );
	if ( token && toggle ) {
		toggle.addEventListener( 'click', function() {
			var showing = token.type === 'text';
			token.type = showing ? 'password' : 'text';
			toggle.setAttribute( 'aria-pressed', showing ? 'false' : 'true' );
			toggle.setAttribute(
				'aria-label',
				showing ? toggle.dataset.showLabel : toggle.dataset.hideLabel
			);
		} );
	}

	var generate = document.querySelector( '.reprint-server-generate-token' );
	if ( token && generate ) {
		generate.addEventListener( 'click', function() {
			var bytes = window.crypto.getRandomValues( new Uint8Array( 32 ) );
			token.value = Array.from( bytes, function( byte ) {
				return byte.toString( 16 ).padStart( 2, '0' );
			} ).join( '' );
			if ( token.type === 'password' && toggle ) {
				toggle.click();
			}
			token.focus();
			token.select();
			if ( window.wp && wp.a11y ) {
				wp.a11y.speak( generate.dataset.generatedMessage );
			}
		} );
	}

	var remoteReprintApiUrl = document.getElementById( 'reprint-server-api-url' );
	var copy = document.querySelector( '.reprint-server-copy-url' );
	if ( remoteReprintApiUrl && copy ) {
		copy.addEventListener( 'click', function() {
			var copied;
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				copied = navigator.clipboard.writeText( remoteReprintApiUrl.value );
			} else {
				remoteReprintApiUrl.select();
				copied = Promise.resolve( document.execCommand( 'copy' ) );
			}

			copied.then( function() {
				if ( window.wp && wp.a11y ) {
					wp.a11y.speak( copy.dataset.copiedMessage );
				}
			} );
		} );
	}
}() );
