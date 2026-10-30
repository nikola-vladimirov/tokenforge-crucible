/* TokenForge Crucible: the Global SCSS screen. */
( function () {
	'use strict';

	var ta = document.getElementById( 'tokenforge-crucible-scss' );
	if ( ! ta ) {
		return;
	}

	var config = window.TokenForgeCrucible;
	var status = null;
	var editor = null;

	function api( path, method, body ) {
		var menu = '/menu-data' === path;
		var url = ( menu ? config.tfRest : config.crucibleRest ) + path;
		var options = {
			method: menu ? 'GET' : ( method || 'GET' ),
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json'
			}
		};

		if ( 'undefined' !== typeof body && null !== body ) {
			options.body = JSON.stringify( body );
		}

		return window.fetch( url, options ).then( function ( response ) {
			return response.text().then( function ( text ) {
				var data = null;

				if ( text ) {
					try {
						data = JSON.parse( text );
					} catch ( error ) {
						data = null;
					}
				}

				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : ( response.statusText || 'Request failed' ) );
				}

				return data;
			} );
		} );
	}

	function setStatus( message ) {
		if ( status ) {
			status.textContent = message;
		}
	}

	function getValue() {
		return editor && editor.getValue ? editor.getValue() : ta.value;
	}

	function save() {
		return api( '/scss', 'POST', { scss: getValue() } ).then( function () {
			setStatus( 'Saved' );
		}, function ( error ) {
			setStatus( error && error.message ? error.message : 'Save failed' );
		} );
	}

	if ( window.TokenForgeScssEditor ) {
		status = document.createElement( 'p' );
		status.id = 'tokenforge-crucible-status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		ta.parentNode.insertBefore( status, ta.nextSibling );

		editor = window.TokenForgeScssEditor.create( ta, {
			api: api,
			height: 440,
			data: function () {
				return { recipes: [], recipesOn: false, ref: [] };
			},
			onChange: function () {
				setStatus( 'Unsaved changes' );
			},
			onSave: save
		} );
	}

	var button = document.getElementById( 'tokenforge-crucible-save' );
	if ( button ) {
		button.addEventListener( 'click', function () {
			save();
		} );
	}
} )();
