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
			try {
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					copied = navigator.clipboard.writeText( remoteReprintApiUrl.value ).then( function() {
						return true;
					} );
				} else {
					remoteReprintApiUrl.focus();
					remoteReprintApiUrl.select();
					copied = Promise.resolve( document.execCommand( 'copy' ) );
				}
			} catch ( error ) {
				showCopyStatus( false );
				return;
			}
			copied.then( showCopyStatus, function() {
				showCopyStatus( false );
			} );
		} );
	}

	function showCopyStatus( copied ) {
		var message = copied ? copy.dataset.copiedMessage : copy.dataset.copyFailedMessage;
		if ( ! copied ) {
			remoteReprintApiUrl.focus();
			remoteReprintApiUrl.select();
		}
		document.querySelector( '.reprint-server-copy-status' ).textContent = message;
	}
}() );
