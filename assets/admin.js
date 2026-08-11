/**
 * Extonify Custom Emails Per Product — admin script.
 *
 * Hand-written, dependency-free, no build step (ADR-0017 §6). Everything here is
 * PROGRESSIVE ENHANCEMENT: with the script switched off the editor still renders
 * every stored target as a removable checkbox, still submits, and still refuses an
 * impossible combination at the storage boundary. What the script adds is search,
 * click-to-insert, the delete confirmation, and the insert-mode control locking.
 *
 * @package Extonify\WCEP
 */

( function () {
	'use strict';

	var settings = window.extonifyWcepAdmin || {};
	var i18n = settings.i18n || {};

	/**
	 * Announce a change to assistive technology.
	 *
	 * The picker adds and removes controls without a page load, and a change nobody
	 * can see is a change nobody is told about.
	 *
	 * @param {string} message Text to announce.
	 */
	function announce( message ) {
		var region = document.getElementById( 'extonify-wcep-live' );

		if ( ! region ) {
			region = document.createElement( 'div' );
			region.id = 'extonify-wcep-live';
			region.className = 'screen-reader-text';
			region.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( region );
		}

		region.textContent = message;
	}

	// ---------------------------------------------------------------------
	// Delete confirmation
	// ---------------------------------------------------------------------

	function bindConfirmations() {
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '.extonify-wcep-confirm' ) : null;

			if ( ! link ) {
				return;
			}

			var message = link.getAttribute( 'data-extonify-wcep-confirm' ) || '';

			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	}

	// ---------------------------------------------------------------------
	// Insert mode: lock the controls ADR-0013 §2 and ADR-0016 §2 forbid
	// ---------------------------------------------------------------------

	/**
	 * Swap which of each control/mirror pair submits.
	 *
	 * A disabled input submits nothing, so the hidden mirrors carry the explicit
	 * `0` / `none` while the real controls are locked. The server renders the pair
	 * already correct for the stored mode, so this only has to react to a change.
	 */
	function applyDeliveryMode() {
		var select = document.getElementById( 'extonify-wcep-delivery_mode' );

		if ( ! select ) {
			return;
		}

		var isInsert = 'insert' === select.value;
		var i;

		var locked = document.querySelectorAll( '[data-extonify-wcep-insert-locked]' );

		for ( i = 0; i < locked.length; i++ ) {
			locked[ i ].disabled = isInsert;
		}

		var mirrors = document.querySelectorAll( '[data-extonify-wcep-insert-mirror]' );

		for ( i = 0; i < mirrors.length; i++ ) {
			mirrors[ i ].disabled = ! isInsert;
		}

		// Rows that only apply to one mode are hidden rather than removed, so the
		// values they hold still submit and the server still judges them.
		var scoped = document.querySelectorAll( '[data-extonify-wcep-mode]' );

		for ( i = 0; i < scoped.length; i++ ) {
			scoped[ i ].hidden = scoped[ i ].getAttribute( 'data-extonify-wcep-mode' ) !== select.value;
		}
	}

	function applyTriggerType() {
		var select = document.getElementById( 'extonify-wcep-trigger_type' );

		if ( ! select ) {
			return;
		}

		var rows = document.querySelectorAll( '[data-extonify-wcep-trigger]' );

		for ( var i = 0; i < rows.length; i++ ) {
			rows[ i ].hidden = rows[ i ].getAttribute( 'data-extonify-wcep-trigger' ) !== select.value;
		}
	}

	function bindModeSwitches() {
		var mode = document.getElementById( 'extonify-wcep-delivery_mode' );
		var trigger = document.getElementById( 'extonify-wcep-trigger_type' );

		if ( mode ) {
			mode.addEventListener( 'change', applyDeliveryMode );
			applyDeliveryMode();
		}

		if ( trigger ) {
			trigger.addEventListener( 'change', applyTriggerType );
			applyTriggerType();
		}
	}

	// ---------------------------------------------------------------------
	// Placeholder insertion
	// ---------------------------------------------------------------------

	var lastField = null;

	function rememberField( event ) {
		var target = event.target;

		if ( target && target.hasAttribute && target.hasAttribute( 'data-extonify-wcep-insertable' ) ) {
			lastField = target;
		}
	}

	/**
	 * Insert text at the caret of a plain input or textarea.
	 *
	 * @param {HTMLInputElement|HTMLTextAreaElement} field The field.
	 * @param {string}                               text  Text to insert.
	 */
	function insertIntoField( field, text ) {
		var start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
		var end = typeof field.selectionEnd === 'number' ? field.selectionEnd : field.value.length;

		field.value = field.value.slice( 0, start ) + text + field.value.slice( end );
		field.selectionStart = start + text.length;
		field.selectionEnd = field.selectionStart;
		field.focus();
	}

	/**
	 * Insert a placeholder wherever the merchant last was.
	 *
	 * The Visual tab is TinyMCE, the Text tab is a textarea, and the subject and
	 * heading are plain inputs. Each needs its own insertion, and picking the wrong
	 * one silently drops the token.
	 *
	 * @param {string} token The placeholder, e.g. `{order_total}`.
	 */
	function insertToken( token ) {
		var editorId = settings.editorId || '';
		var editor = window.tinymce && editorId ? window.tinymce.get( editorId ) : null;

		if ( lastField && document.body.contains( lastField ) ) {
			insertIntoField( lastField, token );
			return;
		}

		if ( editor && ! editor.isHidden() ) {
			editor.execCommand( 'mceInsertContent', false, token );
			return;
		}

		var textarea = editorId ? document.getElementById( editorId ) : null;

		if ( textarea ) {
			insertIntoField( textarea, token );
		}
	}

	function bindPlaceholders() {
		document.addEventListener( 'focusin', rememberField );

		// The editor's own iframe steals focus, so a click inside it clears the
		// "last plain field" memory and insertion falls through to TinyMCE.
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '.extonify-wcep-insert' ) : null;

			if ( ! button ) {
				return;
			}

			event.preventDefault();
			insertToken( button.getAttribute( 'data-extonify-wcep-token' ) || '' );
		} );

		if ( window.jQuery && window.jQuery( document ) ) {
			window.jQuery( document ).on( 'tinymce-editor-init', function () {
				lastField = null;
			} );
		}
	}

	// ---------------------------------------------------------------------
	// Targeting pickers
	// ---------------------------------------------------------------------

	function chipExists( list, id ) {
		return !! list.querySelector( 'input[value="' + String( id ).replace( /"/g, '' ) + '"]' );
	}

	/**
	 * Add one selected target as a checked checkbox.
	 *
	 * ⚠ BUILT WITH `createElement` AND `textContent`, NEVER `innerHTML`. The label
	 * comes from the database and is therefore untrusted here exactly as it is on
	 * the server; assigning it as markup would be a DOM XSS with a capability check
	 * in front of it.
	 *
	 * @param {HTMLElement} list  The chip list.
	 * @param {string}      name  The input name.
	 * @param {number}      id    Target id.
	 * @param {string}      label Human label.
	 */
	function addChip( list, name, id, label ) {
		var domId = 'extonify-wcep-chip-js-' + name.replace( /[^a-z0-9]/gi, '' ) + '-' + id;

		var item = document.createElement( 'li' );
		item.className = 'extonify-wcep-chip';

		var input = document.createElement( 'input' );
		input.type = 'checkbox';
		input.name = name;
		input.value = String( id );
		input.checked = true;
		input.id = domId;

		var text = document.createElement( 'label' );
		text.setAttribute( 'for', domId );
		text.textContent = label;

		item.appendChild( input );
		item.appendChild( text );
		list.appendChild( item );
	}

	function closeResults( results ) {
		results.hidden = true;
		results.textContent = '';
	}

	function renderResults( picker, results, search, rows ) {
		var list = picker.querySelector( '[data-extonify-wcep-chips]' );
		var name = search.getAttribute( 'data-name' ) || '';

		results.textContent = '';

		if ( ! rows.length ) {
			var empty = document.createElement( 'li' );
			empty.setAttribute( 'aria-disabled', 'true' );
			empty.textContent = i18n.noResults || '';
			results.appendChild( empty );
			results.hidden = false;
			return;
		}

		rows.forEach( function ( row ) {
			var option = document.createElement( 'li' );
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'aria-selected', 'false' );
			option.tabIndex = -1;
			option.textContent = row.label;

			option.addEventListener( 'click', function () {
				if ( chipExists( list, row.id ) ) {
					announce( i18n.alreadyAdded || '' );
				} else {
					addChip( list, name, row.id, row.label );
					announce( i18n.added || '' );
				}

				closeResults( results );
				search.value = '';
				search.setAttribute( 'aria-expanded', 'false' );
				search.focus();
			} );

			results.appendChild( option );
		} );

		results.hidden = false;
		search.setAttribute( 'aria-expanded', 'true' );
	}

	function search( picker, term ) {
		var url = settings.ajaxUrl
			+ '?action=' + encodeURIComponent( settings.action || '' )
			+ '&nonce=' + encodeURIComponent( settings.nonce || '' )
			+ '&kind=' + encodeURIComponent( picker.getAttribute( 'data-kind' ) || '' )
			+ '&term=' + encodeURIComponent( term );

		return window.fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data ) {
					return [];
				}

				return payload.data.results || [];
			} );
	}

	function bindPickers() {
		var pickers = document.querySelectorAll( '[data-extonify-wcep-picker]' );

		for ( var i = 0; i < pickers.length; i++ ) {
			( function ( picker ) {
				var box = picker.querySelector( '[data-extonify-wcep-search]' );
				var results = picker.querySelector( '.extonify-wcep-results' );

				if ( ! box || ! results ) {
					return;
				}

				var timer = null;

				box.addEventListener( 'input', function () {
					window.clearTimeout( timer );

					var term = box.value.trim();

					if ( term.length < ( settings.minChars || 2 ) ) {
						closeResults( results );
						box.setAttribute( 'aria-expanded', 'false' );
						return;
					}

					timer = window.setTimeout( function () {
						search( picker, term )
							.then( function ( rows ) {
								renderResults( picker, results, box, rows );
							} )
							.catch( function () {
								results.textContent = '';
								var failed = document.createElement( 'li' );
								failed.setAttribute( 'aria-disabled', 'true' );
								failed.textContent = i18n.failed || '';
								results.appendChild( failed );
								results.hidden = false;
							} );
					}, 250 );
				} );

				// Keyboard operation: Down moves into the list, Escape closes it,
				// Enter activates. A mouse-only picker is not an accessible one.
				box.addEventListener( 'keydown', function ( event ) {
					if ( 'Escape' === event.key ) {
						closeResults( results );
						box.setAttribute( 'aria-expanded', 'false' );
						return;
					}

					if ( 'ArrowDown' === event.key && ! results.hidden ) {
						var first = results.querySelector( '[role="option"]' );

						if ( first ) {
							event.preventDefault();
							first.focus();
						}
					}
				} );

				results.addEventListener( 'keydown', function ( event ) {
					var current = document.activeElement;

					if ( ! current || 'option' !== current.getAttribute( 'role' ) ) {
						return;
					}

					if ( 'Enter' === event.key || ' ' === event.key ) {
						event.preventDefault();
						current.click();
						return;
					}

					if ( 'ArrowDown' === event.key && current.nextElementSibling ) {
						event.preventDefault();
						current.nextElementSibling.focus();
						return;
					}

					if ( 'ArrowUp' === event.key ) {
						event.preventDefault();

						if ( current.previousElementSibling ) {
							current.previousElementSibling.focus();
						} else {
							box.focus();
						}

						return;
					}

					if ( 'Escape' === event.key ) {
						closeResults( results );
						box.setAttribute( 'aria-expanded', 'false' );
						box.focus();
					}
				} );

				document.addEventListener( 'click', function ( event ) {
					if ( ! picker.contains( event.target ) ) {
						closeResults( results );
						box.setAttribute( 'aria-expanded', 'false' );
					}
				} );
			}( pickers[ i ] ) );
		}
	}

	function init() {
		bindConfirmations();
		bindModeSwitches();
		bindPlaceholders();

		if ( window.fetch ) {
			bindPickers();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
