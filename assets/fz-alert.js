/**
 * M24 Fahrzeug-Alert — Editor-Box: Versand auslösen (REST).
 * Liest rest/nonce/confirm/post aus den data-Attributen der .m24fz-alertbox (kein localize nötig).
 */
( function () {
	'use strict';

	function send( box ) {
		var btn = box.querySelector( '.m24fz-ab-btn' );
		var msg = box.querySelector( '.m24fz-ab-msg' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		var confirmText = box.getAttribute( 'data-confirm' ) || 'Senden?';
		if ( ! window.confirm( confirmText ) ) {
			return;
		}

		btn.disabled = true;
		msg.className = 'm24fz-ab-msg';
		msg.textContent = 'Senden…';

		fetch( box.getAttribute( 'data-rest' ), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': box.getAttribute( 'data-nonce' )
			},
			body: JSON.stringify( { post_id: parseInt( box.getAttribute( 'data-post' ), 10 ) } )
		} )
		.then( function ( r ) {
			return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } );
		} )
		.then( function ( res ) {
			var j = res.body || {};
			if ( res.ok && j.ok ) {
				msg.className = 'm24fz-ab-msg ok';
				msg.textContent = j.message || 'Gesendet.';
				if ( ! j.test ) {
					btn.textContent = 'Erneut senden';
				}
			} else {
				msg.className = 'm24fz-ab-msg fail';
				msg.textContent = j.message ? j.message : 'Versand fehlgeschlagen.';
			}
			btn.disabled = false;
		} )
		.catch( function ( err ) {
			msg.className = 'm24fz-ab-msg fail';
			msg.textContent = 'Netzwerk-Fehler: ' + err;
			btn.disabled = false;
		} );
	}

	function init() {
		var boxes = document.querySelectorAll( '.m24fz-alertbox' );
		Array.prototype.forEach.call( boxes, function ( box ) {
			var btn = box.querySelector( '.m24fz-ab-btn' );
			if ( ! btn || btn.getAttribute( 'data-bound' ) ) {
				return;
			}
			btn.setAttribute( 'data-bound', '1' );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				send( box );
			} );
		} );
	}

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
