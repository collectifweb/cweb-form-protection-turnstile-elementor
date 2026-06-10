/**
 * Cloudflare Turnstile front-end helper.
 *
 * Loaded before the Cloudflare API (api.js?render=explicit&onload=cwebtsOnload).
 * Renders every .cf-turnstile widget explicitly so we can attach callbacks and
 * reset widgets after a FAILED Elementor AJAX submission (a token is single-use,
 * so a stale token on resubmit would fail with "timeout-or-duplicate").
 *
 * @package CWebTS
 */
( function () {
	'use strict';

	var RENDERED  = 'data-tf-rendered';
	var WIDGET_ID = '__tfWidgetId';

	function buildOptions( el ) {
		var options = {
			sitekey: el.getAttribute( 'data-sitekey' ),
			theme: el.getAttribute( 'data-theme' ) || 'auto',
			size: el.getAttribute( 'data-size' ) || 'flexible',
			appearance: el.getAttribute( 'data-appearance' ) || 'always',
			language: el.getAttribute( 'data-language' ) || 'auto',
			'expired-callback': function () {
				safeReset( el );
			},
			'timeout-callback': function () {
				safeReset( el );
			},
			'error-callback': function () {
				// Returning true lets Turnstile auto-retry; reset clears stale state.
				safeReset( el );
				return true;
			}
		};

		var action = el.getAttribute( 'data-action' );
		if ( action ) {
			options.action = action;
		}

		return options;
	}

	function safeReset( el ) {
		if ( ! window.turnstile || ! el ) {
			return;
		}
		try {
			// Prefer the widget id returned by render(); fall back to the container.
			window.turnstile.reset( el[ WIDGET_ID ] || el );
		} catch ( e ) {} // eslint-disable-line no-empty
	}

	function renderAll() {
		if ( ! window.turnstile ) {
			return;
		}

		var nodes = document.querySelectorAll( '.cf-turnstile:not([' + RENDERED + '])' );

		for ( var i = 0; i < nodes.length; i++ ) {
			var el = nodes[ i ];
			if ( ! el.getAttribute( 'data-sitekey' ) ) {
				continue;
			}
			try {
				el[ WIDGET_ID ] = window.turnstile.render( el, buildOptions( el ) );
				el.setAttribute( RENDERED, '1' );
			} catch ( e ) {} // eslint-disable-line no-empty
		}
	}

	// Called by the Cloudflare API once it has loaded.
	window.cwebtsOnload = renderAll;

	// Render widgets inserted AFTER the Cloudflare API loaded — Elementor popups,
	// lazy-loaded or AJAX-rendered forms. cwebtsOnload fires only once, so without
	// this a late .cf-turnstile would never render while server-side validation
	// still runs on submit — a silent block. Debounced: a burst of mutations
	// schedules a single renderAll (a no-op when nothing new is present, and a
	// no-op until window.turnstile exists, after which cwebtsOnload covers it).
	if ( window.MutationObserver && document.body ) {
		var rescanScheduled = false;

		function scheduleRescan() {
			if ( rescanScheduled ) {
				return;
			}
			rescanScheduled = true;
			window.setTimeout( function () {
				rescanScheduled = false;
				renderAll();
			}, 200 );
		}

		function addsUnrenderedWidget( nodes ) {
			for ( var i = 0; i < nodes.length; i++ ) {
				var node = nodes[ i ];
				if ( 1 !== node.nodeType ) {
					continue;
				}
				if ( node.classList && node.classList.contains( 'cf-turnstile' ) ) {
					return true;
				}
				if ( node.querySelector && node.querySelector( '.cf-turnstile:not([' + RENDERED + '])' ) ) {
					return true;
				}
			}
			return false;
		}

		var cwebtsObserver = new window.MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				if ( addsUnrenderedWidget( mutations[ i ].addedNodes ) ) {
					scheduleRescan();
					return;
				}
			}
		} );

		cwebtsObserver.observe( document.body, { childList: true, subtree: true } );
	}

	function resetWithin( container ) {
		if ( ! container || ! container.querySelectorAll ) {
			return;
		}
		var nodes = container.querySelectorAll( '.cf-turnstile[' + RENDERED + ']' );
		for ( var i = 0; i < nodes.length; i++ ) {
			safeReset( nodes[ i ] );
		}
	}

	// Reset Turnstile after a FAILED Elementor Pro Forms AJAX submission so a retry
	// gets a fresh token. Capture-phase submit is observed even though Elementor
	// calls preventDefault(). Forms are tracked FIFO; correlation across truly
	// simultaneous submissions is best-effort.
	if ( window.jQuery ) {
		var pendingForms = [];

		function trackForm( form ) {
			if ( ! form || pendingForms.indexOf( form ) !== -1 ) {
				return;
			}
			pendingForms.push( form );
			// Stale-clear: drop the marker if no AJAX completion arrives.
			window.setTimeout( function () {
				var idx = pendingForms.indexOf( form );
				if ( idx !== -1 ) {
					pendingForms.splice( idx, 1 );
				}
			}, 15000 );
		}

		document.addEventListener(
			'submit',
			function ( event ) {
				var form = event.target;
				if ( form && 1 === form.nodeType && form.classList && form.classList.contains( 'elementor-form' ) ) {
					trackForm( form );
				}
			},
			true
		);

		window.jQuery( document ).on( 'ajaxComplete', function ( event, xhr, settings ) {
			if ( ! pendingForms.length ) {
				return;
			}

			// Only react to admin-ajax requests (Elementor posts the form there).
			var url = settings && settings.url ? String( settings.url ) : '';
			if ( url && url.indexOf( 'admin-ajax.php' ) === -1 && url.indexOf( 'wp-admin/admin-ajax' ) === -1 ) {
				return;
			}

			var response = xhr && xhr.responseJSON;
			if ( ! response || typeof response.success === 'undefined' ) {
				return;
			}

			// Consume one tracked form (FIFO); only reset when the submission failed,
			// because a failed submission has already spent the Turnstile token.
			var form = pendingForms.shift();
			if ( false === response.success ) {
				resetWithin( form );
			}
		} );
	}
} )();
