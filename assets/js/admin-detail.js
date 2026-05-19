/**
 * Better REST API Logs — detail page init.
 *
 * Wires highlight.js syntax highlighting and clipboard.js copy buttons.
 *
 * SECURITY: highlight.js safe path (D-17, RESEARCH §4, T-04-30):
 *   1. Server renders esc_html(body) inside <code class="language-*">.
 *   2. hljs.highlightElement(el) reads el.textContent (browser-decoded).
 *   3. hljs rewrites el.innerHTML with its own internally-escaped output.
 * We NEVER set innerHTML from raw response body bytes.
 *
 * JS standard: ES2022+ class with #private fields per code-standards.md.
 * No jQuery dependency for init logic.
 */
( () => {
	'use strict';

	class DetailPage {
		/** @type {HTMLElement[]} */
		#pendingResets = [];

		/**
		 * Initialise syntax highlighting and clipboard bindings.
		 *
		 * @returns {void}
		 */
		init() {
			this.#applyHighlighting();
			this.#bindClipboard();
		}

		/**
		 * Apply highlight.js to every language-* code block on the page.
		 *
		 * Reads textContent (safe — already server-side esc_html'd) then
		 * hljs rewrites innerHTML with its own escape-safe output.
		 *
		 * @returns {void}
		 */
		#applyHighlighting() {
			if ( typeof window.hljs === 'undefined' ) {
				return;
			}
			document
				.querySelectorAll( '.brl-admin pre code[class*="language-"]' )
				.forEach( ( el ) => window.hljs.highlightElement( el ) );
		}

		/**
		 * Bind clipboard.js to all [data-clipboard-target] buttons.
		 *
		 * On success the button label flips to "Copied!" for 2 s then reverts.
		 *
		 * @returns {void}
		 */
		#bindClipboard() {
			if ( typeof window.ClipboardJS !== 'function' ) {
				return;
			}

			const clipboard = new window.ClipboardJS( '[data-clipboard-target]' );

			clipboard.on( 'success', ( evt ) => {
				evt.clearSelection();
				this.#flashCopied( /** @type {HTMLElement} */ ( evt.trigger ) );
			} );

			// On error, leave the label unchanged — the browser's selection
			// feedback is the fallback; no need to flash an error state.
			clipboard.on( 'error', () => {} );
		}

		/**
		 * Temporarily change a button's label to "Copied!" for 2 seconds.
		 *
		 * @param {HTMLElement} button  The button element that triggered copy.
		 * @returns {void}
		 */
		#flashCopied( button ) {
			const original = button.textContent;
			button.textContent = 'Copied!';
			this.#pendingResets.push( button );
			setTimeout( () => {
				button.textContent = original;
				this.#pendingResets = this.#pendingResets.filter( ( b ) => b !== button );
			}, 2000 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => new DetailPage().init() );
	} else {
		new DetailPage().init();
	}
} )();
