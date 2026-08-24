( function() {
	'use strict';

	var token = document.getElementById( 'site_export_secret' );
	var toggle = document.querySelector( '.site-export-toggle-token' );
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

	var remoteReprintApiUrl = document.getElementById( 'site-export-api-url' );
	var copy = document.querySelector( '.site-export-copy-url' );
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
