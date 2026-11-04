/* TokenForge Crucible: the Global SCSS section and Your recipes, inside the TokenForge dashboard.
 *
 * Uses the dashboard's wp.hooks filters: tokenforge.sections and tokenforge.nav (the
 * Global SCSS entry), tokenforge.icons (its nav icon), tokenforge.render (its whole section:
 * section bar + panel), tokenforge.section_html (Your recipes, added to the Recipes section).
 * The editor is the shared TokenForgeScssEditor. Loaded before TokenForge's admin.js.
 */
( function () {
	'use strict';
	var hooks = window.wp && wp.hooks;
	var cfg = window.TokenForgeCrucible;
	if ( ! hooks || ! cfg ) { return; }

	var __ = wp.i18n ? wp.i18n.__ : function ( s ) { return s; };
	var ID = 'scss';
	var DRAFT = 'tokenforge-crucible-scss-draft';
	var st = { source: '', rev: '', status: {}, ref: [], recipes: [], recipesOn: true, ed: null, loaded: false, enabled: true, closed: {}, quiet: false, sync: true, syncPull: true, syncCreate: false };

	function api( path, method, body ) {
		// The editor asks TokenForge for its class and variable list; everything else is Crucible's.
		var base = '/menu-data' === path ? cfg.tfRest : cfg.rest;
		return fetch( base + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json(); } );
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// The unsaved-SCSS draft lives in the browser; storage can be blocked, so every call is guarded.
	function draftGet() { try { return window.localStorage.getItem( DRAFT ) || ''; } catch ( e ) { return ''; } }
	function draftSet( v ) {
		try { if ( v ) { window.localStorage.setItem( DRAFT, v ); } else { window.localStorage.removeItem( DRAFT ); } } catch ( e ) {}
	}

	function setStatus( html ) {
		var el = document.querySelector( '[data-crucible-state]' );
		if ( el ) { el.innerHTML = html; }
	}

	function statusHtml() {
		var err = ( st.status || {} ).error;
		if ( err ) {
			return '<span class="tf-scss-err">' + esc( __( 'Line', 'tokenforge-crucible' ) ) + ' ' + ( err.line || '?' ) + ( err.column ? ':' + err.column : '' ) + ': ' + esc( err.message ) + '</span>';
		}
		return st.loaded ? '<span class="tf-scss-ok">' + esc( __( 'Compiled', 'tokenforge-crucible' ) ) + '</span>' : '<span class="tf-muted">' + esc( __( 'Loading…', 'tokenforge-crucible' ) ) + '</span>';
	}

	// Set the editor text without it counting as a user edit (no draft written).
	function setEd( v ) {
		st.quiet = true;
		st.ed.setValue( v );
		st.quiet = false;
	}

	// Show the recovery box when the browser kept SCSS that is neither saved nor in the editor.
	function updateLost() {
		var box = document.querySelector( '[data-crucible-lost]' );
		if ( ! box ) { return; }
		var d = draftGet();
		box.hidden = ! ( st.loaded && d && d !== st.source && ( ! st.ed || d !== st.ed.getValue() ) );
	}

	function load() {
		return api( '/scss' ).then( function ( r ) {
			st.source = r.source || '';
			st.rev = r.rev || '';
			st.status = r.status || {};
			st.ref = r.ref || [];
			st.recipes = r.recipes || [];
			st.recipesOn = false !== r.recipes_on;
			st.enabled = false !== r.enabled;
			st.sync = false !== r.sync;
			st.syncPull = false !== r.sync_pull;
			st.syncCreate = !! r.sync_create;
			st.loaded = true;
			if ( st.ed ) { setEd( st.source ); }
			setStatus( statusHtml() );
			updateLost();
		} );
	}

	function save() {
		var value = st.ed ? st.ed.getValue() : st.source;
		return api( '/scss-save', 'POST', { source: value, rev: st.rev } ).then( function ( r ) {
			if ( r.conflict ) {
				setStatus( '<span class="tf-scss-err">' + esc( __( 'The SCSS changed in the builder. Reload this section to see it.', 'tokenforge-crucible' ) ) + '</span>' );
				return;
			}
			if ( ! r.ok ) {
				setStatus( '<span class="tf-scss-err">' + esc( r.error || __( 'Could not save the SCSS.', 'tokenforge-crucible' ) ) + '</span>' );
				return;
			}
			st.source = r.source;
			st.rev = r.rev;
			st.status = r.status || {};
			draftSet( '' );
			updateLost();
			setStatus( statusHtml() + ' <span class="tf-muted">' + esc( __( 'Saved', 'tokenforge-crucible' ) ) + '</span>' );
		} );
	}

	function check() {
		var value = st.ed ? st.ed.getValue() : st.source;
		return api( '/scss-compile', 'POST', { source: value } ).then( function ( r ) {
			st.status = { error: r.error || null };
			setStatus( statusHtml() );
			var out = document.querySelector( '[data-crucible-out]' );
			if ( out ) { out.hidden = false; out.textContent = r.css || '/* (no output) */'; }
		} );
	}

	// ---- Panels (same structure as the dashboard's panelHtml) ---------------

	function tipIcon( text ) {
		return '<span class="tf-tip" tabindex="0" role="button" aria-label="' + esc( text ) + '" data-tip="' + esc( text ) + '">?</span>';
	}

	function panelHtml( id, title, tip, extra, body, cls ) {
		var closed = !! st.closed[ id ];
		return '<section class="tf-panel' + ( closed ? ' is-collapsed' : '' ) + ( cls || '' ) + '" data-crucible-panel="' + id + '">' +
			'<div class="tf-panel-head" data-crucible-act="collapse" role="button" tabindex="0" aria-expanded="' + ( closed ? 'false' : 'true' ) + '"><span class="tf-chev" aria-hidden="true"></span><h3>' + esc( title ) + '</h3>' + ( tip ? tipIcon( tip ) : '' ) + extra + '</div>' +
			'<div class="tf-panel-body">' + body + '</div></section>';
	}

	function secBar() {
		return '<div class="tf-secbar"><span class="tf-grow"></span>' +
			'<button type="button" class="tf-btn tf-small" data-crucible-act="expandall">' + esc( __( 'Open all', 'tokenforge-crucible' ) ) + '</button>' +
			'<button type="button" class="tf-btn tf-small" data-crucible-act="collapseall">' + esc( __( 'Close all', 'tokenforge-crucible' ) ) + '</button>' +
			'<button type="button" class="tf-btn tf-small" data-crucible-act="resetsec" title="' + esc( __( 'Empty the editor (nothing is saved until you press Save)', 'tokenforge-crucible' ) ) + '">' + esc( __( 'Reset section', 'tokenforge-crucible' ) ) + '</button></div>';
	}

	function scssPanelHtml() {
		var desc = __( 'Your own SCSS, compiled on Save and added last to the stylesheet (unlayered, so it wins). All TokenForge mixins and functions work without an import. Type ?recipe + Tab or ; to expand a recipe. A compile error keeps the last working CSS live.', 'tokenforge-crucible' );
		var sw = '<label class="tf-switch tf-head-switch" title="' + ( st.enabled ? 'On' : 'Off' ) + '"><input type="checkbox" data-crucible-enabled' + ( st.enabled ? ' checked' : '' ) + ' aria-label="' + esc( __( 'Global SCSS on/off', 'tokenforge-crucible' ) ) + '"><i></i></label>';
		var body = '<p class="tf-muted tf-desc">' + esc( desc ) + '</p>' +
			'<div class="tf-scss-lost" data-crucible-lost hidden><p>' + esc( __( 'Your unsaved SCSS: this browser kept SCSS that was never saved.', 'tokenforge-crucible' ) ) + '</p>' +
			'<button type="button" class="tf-btn tf-small" data-crucible-act="restore">' + esc( __( 'Restore it into the editor', 'tokenforge-crucible' ) ) + '</button></div>' +
			'<div class="tf-scss"><div class="tf-scss-bar"><span class="tf-scss-state" data-crucible-state>' + statusHtml() + '</span><span class="tf-grow"></span>' +
			'<button type="button" class="tf-btn tf-small" data-crucible-act="css" title="' + esc( __( 'Show the compiled CSS', 'tokenforge-crucible' ) ) + '">CSS</button>' +
			'<button type="button" class="tf-btn tf-small tf-primary" data-crucible-act="save">' + esc( __( 'Save', 'tokenforge-crucible' ) ) + '</button></div>' +
			'<textarea class="tf-scss-src" data-crucible-scss spellcheck="false" rows="20" aria-label="' + esc( __( 'Global SCSS', 'tokenforge-crucible' ) ) + '"></textarea>' +
			'<pre class="tf-scss-out" data-crucible-out hidden></pre></div>';
		return secBar() + '<div class="' + ( st.enabled ? '' : 'tf-mod-off' ) + '" data-crucible-wrap>' +
			panelHtml( 'scss', __( 'Global SCSS', 'tokenforge-crucible' ), desc, sw, body, st.enabled ? '' : ' is-disabled' ) + syncPanelHtml() + '</div>';
	}

	function syncPanelHtml() {
		var desc = __( 'Each top-level <code>.class { … }</code> block is paired with the builder’s global class of the same name, live in both directions while you work in the builder (nothing is stored until you save). <strong>Global SCSS wins:</strong> what the block sets (spacing, border, radius, typography, colours, size, flex/grid, position …, <code>&amp;:hover</code> states and breakpoint blocks) is what the class shows in its Style fields, and the class’s CSS panel holds only what has no field (custom properties, <code>!important</code>, nested rules like <code>&amp;::before</code> or <code>&amp; .icon</code>), so it can never override the SCSS. <strong>Builder → SCSS:</strong> a value you change in the Style fields or the CSS panel (the CSS panel wins over the Style fields, as in the builder) is rewritten in place inside the block; the rest of your SCSS stays. Mixins, <code>$variables</code> and other SCSS-only code stay SCSS-only.', 'tokenforge-crucible' );
		var sw = function ( key, on, label, head ) {
			return '<label class="tf-switch' + ( head ? ' tf-head-switch' : '' ) + '"><input type="checkbox" data-crucible-sync="' + key + '"' + ( on ? ' checked' : '' ) + ' aria-label="' + esc( label ) + '"><i></i></label>';
		};
		var row = function ( key, on, label ) {
			return '<p class="tf-sync-row">' + sw( key, on, label ) + ' <span>' + esc( label ) + '</span></p>';
		};
		var body = '<p class="tf-muted tf-desc">' + desc + '</p>' +
			row( 'sync_pull', st.syncPull, __( 'Add classes edited in the builder to Global SCSS', 'tokenforge-crucible' ) ) +
			row( 'sync_create', st.syncCreate, __( 'Create a builder class for every class block', 'tokenforge-crucible' ) );
		var extra = '<span class="tf-muted tf-sync-state" data-crucible-sync-state></span>' + sw( 'sync', st.sync, __( 'Sync with builder classes', 'tokenforge-crucible' ), true );
		return panelHtml( 'scss-sync', __( 'Sync with builder classes', 'tokenforge-crucible' ), desc.replace( /<[^>]+>/g, '' ).replace( /&amp;/g, '&' ), extra, body );
	}

	// ---- Your recipes -------------------------------------------------------

	function recipesPanelHtml() {
		var body = '<p class="tf-muted tf-desc">' + esc( __( 'Name + code. Use %root% for the element and {{cursor}} for where the caret lands. A custom recipe with a built-in name replaces it.', 'tokenforge-crucible' ) ) + '</p>' +
			'<div data-crucible-recipes></div>' +
			'<p><button type="button" class="tf-btn" data-crucible-act="recipe-add">' + esc( __( '+ Add recipe', 'tokenforge-crucible' ) ) + '</button> ' +
			'<button type="button" class="tf-btn tf-primary" data-crucible-act="recipes-save">' + esc( __( 'Save recipes', 'tokenforge-crucible' ) ) + '</button> ' +
			'<span class="tf-muted" data-crucible-recipes-state></span></p>';
		return panelHtml( 'recipes', __( 'Your recipes', 'tokenforge-crucible' ), '', '', body );
	}

	// Same row markup as the 0.53.7 dashboard.
	function recipeRowHtml( r ) {
		var name = r ? r.n : '';
		return '<div class="tf-preset tf-recipe"><div class="tf-preset-head"><strong>?' + esc( name || '…' ) + '</strong><button type="button" class="tf-del" data-crucible-act="recipe-remove" title="' + esc( __( 'Remove', 'tokenforge-crucible' ) ) + '">×</button></div>' +
			'<div class="tf-fields"><label class="tf-field"><span class="tf-flabel">' + esc( __( 'Name', 'tokenforge-crucible' ) ) + '</span><input type="text" class="tf-recipe-name" value="' + esc( name ) + '" placeholder="my-recipe" spellcheck="false"></label>' +
			'<label class="tf-field tf-wide"><span class="tf-flabel">' + esc( __( 'Code', 'tokenforge-crucible' ) ) + '</span><textarea rows="5" class="tf-mono tf-recipe-code" spellcheck="false" placeholder="%root% {\n\t{{cursor}}\n}">' + esc( r ? r.code : '' ) + '</textarea></label></div></div>';
	}

	function recipesSetState( text ) {
		var el = document.querySelector( '[data-crucible-recipes-state]' );
		if ( el ) { el.textContent = text; }
	}

	function recipesLoad( host ) {
		api( '/recipes' ).then( function ( r ) {
			host.innerHTML = ( r.recipes || [] ).map( recipeRowHtml ).join( '' );
		} );
	}

	function recipesSave() {
		var rows = document.querySelectorAll( '.tf-recipe' );
		var list = [].map.call( rows, function ( row ) {
			return { n: row.querySelector( '.tf-recipe-name' ).value, code: row.querySelector( '.tf-recipe-code' ).value };
		} );
		return api( '/recipes', 'POST', { recipes: list } ).then( function ( r ) {
			recipesSetState( __( 'Saved', 'tokenforge-crucible' ) + ' · ' + ( r.recipes || [] ).length );
			// The editor completes ?recipe from st.recipes, so fetch the new merged list.
			return api( '/scss' ).then( function ( s ) { st.recipes = s.recipes || st.recipes; st.recipesOn = false !== s.recipes_on; } );
		} );
	}

	// ---- Mounting ------------------------------------------------------------

	function mountAll() {
		var ta = document.querySelector( 'textarea[data-crucible-scss]:not([data-mounted])' );
		if ( ta && window.TokenForgeScssEditor ) {
			ta.setAttribute( 'data-mounted', '' );
			st.ed = window.TokenForgeScssEditor.create( ta, {
				api: api,
				data: function () { return { recipes: st.recipes, recipesOn: st.recipesOn, ref: st.ref }; },
				height: 420,
				onChange: function () {
					if ( st.quiet || ! st.ed ) { return; }
					var v = st.ed.getValue();
					draftSet( v === st.source ? '' : v );
					updateLost();
				},
				onSave: save
			} );
			setEd( st.source );
			if ( ! st.loaded ) { load(); } else { updateLost(); }
		}
		var host = document.querySelector( '[data-crucible-recipes]:not([data-mounted])' );
		if ( host ) {
			host.setAttribute( 'data-mounted', '' );
			recipesLoad( host );
		}
	}

	// ---- Hooks -----------------------------------------------------------------

	hooks.addFilter( 'tokenforge.sections', 'tokenforge-crucible', function ( list ) {
		return list.concat( [ { id: ID, title: __( 'Global SCSS', 'tokenforge-crucible' ), render: 'crucible-scss', panels: [] } ] );
	} );

	hooks.addFilter( 'tokenforge.nav', 'tokenforge-crucible', function ( groups ) {
		var start = groups[ 0 ] && groups[ 0 ][ 1 ];
		if ( ! start ) { return groups; }
		var at = start.map( function ( item ) { return item.id; } ).indexOf( 'recipes' );
		start.splice( at < 0 ? start.length : at, 0, { id: ID, title: __( 'Global SCSS', 'tokenforge-crucible' ) } );
		return groups;
	} );

	hooks.addFilter( 'tokenforge.icons', 'tokenforge-crucible', function ( icons ) {
		icons[ ID ] = '<path d="M8 6 3 12l5 6M16 6l5 6-5 6M14 4l-4 16"/>';
		return icons;
	} );

	hooks.addFilter( 'tokenforge.render', 'tokenforge-crucible', function ( html, render ) {
		return 'crucible-scss' === render ? scssPanelHtml() : html;
	} );

	// Your recipes goes after Built-in recipes, the last panel of the Recipes section.
	hooks.addFilter( 'tokenforge.section_html', 'tokenforge-crucible', function ( html, id ) {
		return 'recipes' === id ? html + recipesPanelHtml() : html;
	} );

	function setClosed( sec, closed ) {
		sec.classList.toggle( 'is-collapsed', closed );
		var head = sec.querySelector( '.tf-panel-head' );
		if ( head ) { head.setAttribute( 'aria-expanded', closed ? 'false' : 'true' ); }
		st.closed[ sec.getAttribute( 'data-crucible-panel' ) ] = closed;
	}

	function onAct( e ) {
		var btn = e.target.closest && e.target.closest( '[data-crucible-act]' );
		if ( ! btn ) { return; }
		var act = btn.getAttribute( 'data-crucible-act' );
		if ( 'collapse' === act ) {
			// The switch and the "?" keep their own behaviour.
			if ( e.target.closest( '.tf-switch, .tf-tip' ) ) { return; }
			e.preventDefault();
			var sec = btn.closest( '.tf-panel' );
			setClosed( sec, ! sec.classList.contains( 'is-collapsed' ) );
			return;
		}
		e.preventDefault();
		if ( 'save' === act ) { save(); }
		if ( 'css' === act ) {
			var out = document.querySelector( '[data-crucible-out]' );
			if ( out && ! out.hidden ) { out.hidden = true; } else { check(); }
		}
		if ( 'restore' === act && st.ed ) { st.ed.setValue( draftGet() ); updateLost(); }
		if ( 'expandall' === act || 'collapseall' === act ) {
			[].forEach.call( document.querySelectorAll( '[data-crucible-panel]' ), function ( p ) { setClosed( p, 'collapseall' === act ); } );
		}
		if ( 'resetsec' === act && st.ed && window.confirm( __( 'Empty the editor? Nothing is saved until you press Save.', 'tokenforge-crucible' ) ) ) { st.ed.setValue( '' ); }
		if ( 'recipes-save' === act ) { recipesSave(); }
		if ( 'recipe-add' === act ) {
			var host = document.querySelector( '[data-crucible-recipes]' );
			if ( host ) { host.insertAdjacentHTML( 'beforeend', recipeRowHtml( null ) ); }
		}
		if ( 'recipe-remove' === act ) { btn.closest( '.tf-recipe' ).remove(); }
	}
	document.addEventListener( 'click', onAct );
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( 'Enter' === e.key || ' ' === e.key ) && e.target.matches && e.target.matches( '.tf-panel-head[data-crucible-act]' ) ) { onAct( e ); }
	} );

	document.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( ! t.classList || ! t.classList.contains( 'tf-recipe-name' ) ) { return; }
		// Same cleanup as 0.53.7: invalid characters become "-" while typing.
		t.value = t.value.toLowerCase().replace( /^\?/, '' ).replace( /[^a-z0-9-]+/g, '-' );
		var hd = t.closest( '.tf-recipe' ).querySelector( '.tf-preset-head strong' );
		if ( hd ) { hd.textContent = '?' + ( t.value || '…' ); }
	} );

	// The head switch: dims the panel now and tells the server (it must honor `enabled` on /scss-save).
	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target.hasAttribute || ! e.target.hasAttribute( 'data-crucible-enabled' ) ) { return; }
		st.enabled = e.target.checked;
		var wrap = e.target.closest( '[data-crucible-wrap]' );
		if ( wrap ) {
			wrap.classList.toggle( 'tf-mod-off', ! st.enabled );
			wrap.querySelector( '.tf-panel' ).classList.toggle( 'is-disabled', ! st.enabled );
		}
		api( '/scss-save', 'POST', { source: st.source, rev: st.rev, enabled: st.enabled } );
	} );

	// Sync switches: save all three values on every change.
	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( ! t.hasAttribute || ! t.hasAttribute( 'data-crucible-sync' ) ) { return; }
		var panel = t.closest( '.tf-panel' );
		var q = function ( k ) { return panel.querySelector( '[data-crucible-sync="' + k + '"]' ).checked; };
		st.sync = q( 'sync' ); st.syncPull = q( 'sync_pull' ); st.syncCreate = q( 'sync_create' );
		var state = panel.querySelector( '[data-crucible-sync-state]' );
		var say = function ( m ) { if ( state ) { state.textContent = m; } };
		say( __( 'Saving…', 'tokenforge-crucible' ) );
		api( '/scss-sync-settings', 'POST', { sync: st.sync, sync_pull: st.syncPull, sync_create: st.syncCreate } ).then( function ( r ) {
			say( r && r.ok ? __( 'Saved', 'tokenforge-crucible' ) : __( 'Could not save', 'tokenforge-crucible' ) );
		}, function () { say( __( 'Could not save', 'tokenforge-crucible' ) ); } );
	} );

	// The dashboard re-renders its panels, so mount whenever a new host appears.
	new MutationObserver( mountAll ).observe( document.body, { childList: true, subtree: true } );
} )();
