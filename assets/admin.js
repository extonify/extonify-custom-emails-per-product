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

		/*
		 * ⚠ THE RECIPIENTS GO READ-ONLY, NOT DISABLED, AND THEY HAVE NO MIRROR ON
		 * PURPOSE. An insert rule has no recipients of its own — WooCommerce addresses
		 * the email this rule's content joins — but it does not ERASE them either, so
		 * the stored document has to survive the round trip untouched. Disabling these
		 * would drop them from the submission and a hidden mirror could only carry the
		 * value this page was RENDERED with, so a merchant who edited a recipient and
		 * then switched to insert would have silently saved the older copy. A read-only
		 * textarea submits whatever is in it, which is the only version there is.
		 */
		var frozen = document.querySelectorAll( '[data-extonify-wcep-insert-readonly]' );

		for ( i = 0; i < frozen.length; i++ ) {
			frozen[ i ].readOnly = isInsert;
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

	/**
	 * WHAT DECIDES WHERE A PLACEHOLDER LANDS.
	 *
	 * One rule, applied to every surface the same way: the token goes to the LAST
	 * ELEMENT CARRYING `data-extonify-wcep-insertable` THAT RECEIVED FOCUS. The
	 * server puts that marker on the subject, the heading AND the body textarea,
	 * so there is no per-field branch here and there must not be one.
	 *
	 * Two corrections keep "last focused" honest:
	 *
	 *   1. FOCUS MOVING INTO TinyMCE CLEARS THE MEMORY. Focus inside the editor's
	 *      iframe raises no `focusin` this document can see, so without an
	 *      explicit clear the memory is stale from the moment the merchant clicks
	 *      into the body on the Visual tab. `null` means "the visual editor".
	 *   2. WHILE TinyMCE IS SHOWING, IT OWNS THE BODY. The token then goes through
	 *      `mceInsertContent` even when the remembered field IS the body textarea:
	 *      that textarea is hidden behind the iframe, and writing into it would
	 *      look like it worked and be discarded on the editor's next sync.
	 *
	 * ⚠ THE MARKER IS THE ONLY THING CONSULTED, BECAUSE ITS ABSENCE WAS A TIER 1
	 * DEFECT. Before Part G the server emitted it from ONE site — the plain-text row
	 * helper — so it landed on whatever that helper drew (the rule name, the subject
	 * and the heading) and never on the `wp_editor()` body. Focusing the body
	 * therefore never updated this variable, the first branch of `insertToken()`
	 * fired on whichever plain input was touched last, and a placeholder clicked
	 * while the merchant was writing the body was inserted into the HEADING. The
	 * token was not lost, which is worse: nothing on the screen said the merchant's
	 * input had gone somewhere else. It is now a per-field decision on the server,
	 * asserted as a complete map by
	 * `AdminOutputTest::test_every_placeholder_target_is_marked_and_nothing_else_is()`.
	 *
	 * @type {?HTMLElement}
	 */
	var lastField = null;

	/**
	 * The attribute that makes a field insertable. Emitted by `RuleEditor`.
	 *
	 * @type {string}
	 */
	var INSERTABLE = 'data-extonify-wcep-insertable';

	/**
	 * The body's TinyMCE instance, but only while it is actually showing.
	 *
	 * @return {?Object} The editor, or null on the Text tab and with rich editing off.
	 */
	function visualEditor() {
		var id = settings.editorId || '';
		var editor = window.tinymce && id ? window.tinymce.get( id ) : null;

		return editor && ! editor.isHidden() ? editor : null;
	}

	/**
	 * The body `<textarea>`, whether or not TinyMCE is in front of it.
	 *
	 * @return {?HTMLTextAreaElement}
	 */
	function bodyTextarea() {
		return settings.editorId ? document.getElementById( settings.editorId ) : null;
	}

	/**
	 * Track focus across every insertable surface.
	 *
	 * @param {FocusEvent} event The focus event.
	 * @return {void}
	 */
	function rememberField( event ) {
		var target = event.target;

		if ( ! target || ! target.hasAttribute ) {
			return;
		}

		if ( target.hasAttribute( INSERTABLE ) ) {
			lastField = target;
			return;
		}

		// Correction 1, outer-document half: some browsers report focus entering an
		// iframe as a `focusin` on the `<iframe>` element itself. The editor's own
		// `focus` event, bound in `bindPlaceholders()`, is the reliable half.
		if ( 'IFRAME' === target.tagName && settings.editorId
			&& target.id === settings.editorId + '_ifr' ) {
			lastField = null;
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
	 * Insert a placeholder into the surface the rule above selects.
	 *
	 * There are exactly TWO insertion mechanisms, not one per field: TinyMCE's
	 * `mceInsertContent` for the Visual tab, and a caret insert for everything
	 * else — the Text tab's textarea, the subject and the heading.
	 *
	 * @param {string} token The placeholder, e.g. `{order_total}`.
	 * @return {void}
	 */
	function insertToken( token ) {
		var editor = visualEditor();
		var body = bodyTextarea();
		var field = lastField && document.body.contains( lastField ) ? lastField : null;

		// Correction 2: the visual editor owns the body while it is showing.
		if ( editor && ( null === field || field === body ) ) {
			editor.execCommand( 'mceInsertContent', false, token );
			editor.focus();
			return;
		}

		if ( field ) {
			insertIntoField( field, token );
			return;
		}

		// Nothing has been focused yet and the visual editor is not up: the body is
		// the only field a placeholder is worth guessing at.
		if ( body ) {
			insertIntoField( body, token );
		}
	}

	function bindPlaceholders() {
		document.addEventListener( 'focusin', rememberField );

		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '.extonify-wcep-insert' ) : null;

			if ( ! button ) {
				return;
			}

			event.preventDefault();
			insertToken( button.getAttribute( 'data-extonify-wcep-token' ) || '' );
		} );

		/*
		 * Correction 1, the reliable half.
		 *
		 * ⚠ `tinymce-editor-init` FIRES ONCE, AND TAB SWITCHING FIRES NOTHING. The
		 * previous code cleared the memory only at init, which covered the first
		 * Visual tab and nothing after it: Visual → Text → Visual left whatever the
		 * merchant had touched in between as the insertion target. Binding the
		 * editor's own `focus` event instead fires every time the caret enters the
		 * iframe, for the life of the instance.
		 */
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'tinymce-editor-init', function ( event, editor ) {
				lastField = null;

				if ( editor && editor.on ) {
					editor.on( 'focus', function () {
						lastField = null;
					} );
				}
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
