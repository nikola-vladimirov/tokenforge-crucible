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

	// Export / Import and the Starter reset: the Global SCSS and your recipes parts.
	hooks.addFilter( 'tokenforge.import_labels', 'tokenforge-crucible', function ( labels ) { return Object.assign( {}, labels, { scss: 'Global SCSS' } ); } );
	hooks.addFilter( 'tokenforge.reset_parts', 'tokenforge-crucible', function ( parts ) {
		return parts.concat( [ [ 'scss', 'Global SCSS', 'your SCSS code' ], [ 'recipes', 'Your recipes', 'your own recipes' ] ] );
	} );

	var __ = wp.i18n ? wp.i18n.__ : function ( s ) { return s; };
	var ID = 'scss';
	var Lint = window.TokenForgeScssLint; // assets/admin/scss-lint.js; the Check button and the site report need it.
	var DRAFT = 'tokenforge-crucible-scss-draft:' + cfg.rest; // Per site: subdirectory multisite shares one origin.
	// The front-end TokenForge window renders the dashboard in a shadow root (#tf-dock-host), which
	// document queries, non-composed events (change) and a body MutationObserver cannot see.
	var listeners = [];
	var shadowBound = null;
	function rootNode() { var h = document.getElementById( 'tf-dock-host' ); return ( h && h.shadowRoot ) || document; }
	function q( s ) { return rootNode().querySelector( s ); }
	function qa( s ) { return rootNode().querySelectorAll( s ); }
	function on( type, fn ) { listeners.push( [ type, fn ] ); document.addEventListener( type, fn ); }
	function bindShadow() {
		var r = rootNode();
		if ( r === document || r === shadowBound ) { return; }
		shadowBound = r;
		listeners.forEach( function ( l ) { r.addEventListener( l[0], l[1] ); } );
		if ( cfg.css ) { var l = document.createElement( 'link' ); l.rel = 'stylesheet'; l.href = cfg.css; r.appendChild( l ); }
		new MutationObserver( mountAll ).observe( r, { childList: true, subtree: true } );
	}
	var st = { source: '', rev: '', status: {}, ref: [], recipes: [], recipesOn: true, ed: null, loaded: false, enabled: true, closed: {}, quiet: false, sync: true, syncPull: true, syncCreate: false, ver: { named: [], recent: [] }, verOpen: null, view: 'values', report: null, files: [], file: null };

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
		var el = q( '[data-crucible-state]' );
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
		var box = q( '[data-crucible-lost]' );
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
				// Keep the user's text as the draft, then show the newer saved SCSS; the draft box offers theirs back.
				draftSet( value );
				return load().then( function () {
					setStatus( '<span class="tf-scss-err">' + esc( __( 'The SCSS changed in the builder, so the newer version is shown. Your text is kept as a draft you can restore.', 'tokenforge-crucible' ) ) + '</span>' );
				} );
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
			verLoad(); // the save took a copy: show it under Recent saves.
		} );
	}

	function check() {
		var value = st.ed ? st.ed.getValue() : st.source;
		return api( '/scss-compile', 'POST', { source: value } ).then( function ( r ) {
			st.status = { error: r.error || null };
			setStatus( statusHtml() );
			var out = q( '[data-crucible-out]' );
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
			( Lint ? '<button type="button" class="tf-btn tf-small" data-crucible-act="lint" title="' + esc( Lint.text.title ) + '">' + esc( Lint.text.check ) + '</button>' : '' ) +
			'<button type="button" class="tf-btn tf-small tf-primary" data-crucible-act="save">' + esc( __( 'Save', 'tokenforge-crucible' ) ) + '</button></div>' +
			'<textarea class="tf-scss-src" data-crucible-scss spellcheck="false" rows="20" aria-label="' + esc( __( 'Global SCSS', 'tokenforge-crucible' ) ) + '"></textarea>' +
			'<pre class="tf-scss-out" data-crucible-out hidden></pre><div class="tf-lint" data-crucible-lint aria-live="polite" hidden></div></div>';
		return secBar() + '<div class="' + ( st.enabled ? '' : 'tf-mod-off' ) + '" data-crucible-wrap>' +
			panelHtml( 'scss', __( 'Global SCSS', 'tokenforge-crucible' ), desc, sw, body, st.enabled ? '' : ' is-disabled' ) + syncPanelHtml() + filesPanelHtml() + versionsPanelHtml() + reportPanelHtml() + '</div>';
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

	// ---- SCSS files ------------------------------------------------------------

	// The same rule as the server (tokenforge_crucible_scss_file_name), which checks again.
	var FILE_NAME = /^[a-z0-9][a-z0-9_-]{0,40}(\/[a-z0-9][a-z0-9_-]{0,40}){0,3}$/;
	var fileTimer = null;

	function filesPanelHtml() {
		var desc = __( 'Split your SCSS into files. A line @use "name"; in the Global SCSS, or in another file, is replaced by the file of that name (each file goes in once). A file with "Loads on" is compiled by itself and added only to those pages, after the stylesheet. Classes in files are not paired with builder classes: only the Global SCSS is.', 'tokenforge-crucible' );
		return panelHtml( 'scss-files', __( 'SCSS files', 'tokenforge-crucible' ), desc, '', '<p class="tf-muted tf-desc">' + esc( desc ) + '</p><div data-crucible-files></div>' );
	}

	// The list as a tree: one row for each folder, then its files, each with its depth.
	function filesTree( list ) {
		var rows = [], seen = {};
		list.slice().sort( function ( a, b ) { return a.name < b.name ? -1 : ( a.name > b.name ? 1 : 0 ); } ).forEach( function ( f ) {
			var parts = String( f.name ).split( '/' );
			for ( var i = 0; i < parts.length - 1; i++ ) {
				var dir = parts.slice( 0, i + 1 ).join( '/' );
				if ( ! seen[ dir ] ) { seen[ dir ] = true; rows.push( { depth: i, label: parts[ i ] + '/', file: null } ); }
			}
			rows.push( { depth: parts.length - 1, label: parts[ parts.length - 1 ], file: f } );
		} );
		return rows;
	}

	function fileLoads( f ) {
		return ( f.pages || [] ).map( function ( p ) { return p.title || '#' + p.id; } ).concat( ( f.types || [] ).map( function ( t ) { return t + ' (' + __( 'post type', 'tokenforge-crucible' ) + ')'; } ) ).join( ', ' );
	}

	function fileEditHtml( f ) {
		var use = '@use "' + f.name + '";';
		var chips = f.pages.map( function ( p ) {
			return '<span class="tf-file-chip">' + esc( p.title || '#' + p.id ) + ' <button type="button" class="tf-file-x" data-crucible-act="file-unpage" data-id="' + esc( p.id ) + '" aria-label="' + esc( __( 'Remove', 'tokenforge-crucible' ) ) + '">×</button></span>';
		} ).join( ' ' );
		var b = function ( act, label, cls ) { return '<button type="button" class="tf-btn tf-small' + ( cls || '' ) + '" data-crucible-act="' + act + '">' + esc( label ) + '</button> '; };
		// The line break after the tag keeps a first empty line of the code (the browser drops one).
		return '<div class="tf-file-ed"><h4>' + esc( f.name ) + ' <code>' + esc( use ) + '</code></h4>' +
			'<textarea class="tf-file-code" data-crucible-file-code spellcheck="false" rows="14" aria-label="' + esc( __( 'SCSS of this file', 'tokenforge-crucible' ) ) + '">\n' + esc( f.code ) + '</textarea>' +
			'<p class="tf-file-loads"><strong>' + esc( __( 'Loads on', 'tokenforge-crucible' ) ) + '</strong> ' + tipIcon( __( 'Leave empty for a file you load with a @use line. Add pages or builder templates (a header template loads the file wherever that header shows), or post types, to load this file only there.', 'tokenforge-crucible' ) ) + ' ' + chips +
			' <input type="search" data-crucible-file-search placeholder="' + esc( __( 'Search a page, or type the ID of a page or template', 'tokenforge-crucible' ) ) + '" aria-label="' + esc( __( 'Search a page to load this file on', 'tokenforge-crucible' ) ) + '"></p>' +
			'<p class="tf-file-found" data-crucible-file-found></p>' +
			'<p class="tf-file-types"><label>' + esc( __( 'On every single post of these types', 'tokenforge-crucible' ) ) + ' <input type="text" data-crucible-file-types value="' + esc( f.types.join( ', ' ) ) + '" placeholder="' + esc( __( 'for example: post, product', 'tokenforge-crucible' ) ) + '"></label></p>' +
			'<p>' + b( 'file-save', __( 'Save file', 'tokenforge-crucible' ), ' tf-primary' ) + b( 'file-delete', __( 'Delete file', 'tokenforge-crucible' ) ) + b( 'file-close', __( 'Close', 'tokenforge-crucible' ) ) + '</p></div>';
	}

	function filesHtml() {
		var rows = filesTree( st.files ).map( function ( r ) {
			var pad = ' style="padding-left:' + ( r.depth * 18 ) + 'px"';
			if ( ! r.file ) { return '<li class="tf-file-dir"' + pad + '>' + esc( r.label ) + '</li>'; }
			var loads = fileLoads( r.file );
			return '<li' + pad + '><button type="button" class="tf-btn tf-small" data-crucible-act="file-open" data-name="' + esc( r.file.name ) + '" title="' + esc( '@use "' + r.file.name + '";' ) + '">' + esc( r.label ) + '</button> ' +
				'<span class="tf-muted">' + esc( loads ? __( 'Loads on:', 'tokenforge-crucible' ) + ' ' + loads : __( 'Partial', 'tokenforge-crucible' ) ) + '</span></li>';
		} ).join( '' );
		return ( rows ? '<ul class="tf-files">' + rows + '</ul>' : '<p class="tf-muted">' + esc( __( 'No files yet.', 'tokenforge-crucible' ) ) + '</p>' ) +
			'<p class="tf-ver-new"><input type="text" maxlength="170" data-crucible-file-new placeholder="' + esc( __( 'Name, for example: parts/buttons', 'tokenforge-crucible' ) ) + '" aria-label="' + esc( __( 'File name', 'tokenforge-crucible' ) ) + '"> ' +
			'<button type="button" class="tf-btn" data-crucible-act="file-new">' + esc( __( 'New file', 'tokenforge-crucible' ) ) + '</button> ' +
			'<span class="tf-muted" data-crucible-file-state role="status"></span></p>' + ( st.file ? fileEditHtml( st.file ) : '' );
	}

	// What is typed in the open file, kept before the panel is drawn again.
	function fileRead() {
		var ta = q( '[data-crucible-file-code]' ), ty = q( '[data-crucible-file-types]' );
		if ( st.file && ta ) { st.file.code = ta.value; }
		if ( st.file && ty ) { st.file.types = ty.value.split( ',' ).map( function ( t ) { return t.trim().toLowerCase(); } ).filter( Boolean ); }
	}

	function filesRender( keep ) {
		if ( keep ) { fileRead(); }
		var host = q( '[data-crucible-files]' );
		if ( host ) { host.innerHTML = filesHtml(); }
	}

	function fileSay( text ) {
		var el = q( '[data-crucible-file-state]' );
		if ( el ) { el.textContent = text; }
	}

	function filesLoad() {
		return api( '/scss-files' ).then( function ( r ) {
			st.files = ( r && Array.isArray( r.files ) ) ? r.files : [];
			filesRender( true );
		}, function () {} );
	}

	function fileOpen( name ) {
		return api( '/scss-file' + ( cfg.rest.indexOf( '?' ) < 0 ? '?' : '&' ) + 'name=' + encodeURIComponent( name ) ).then( function ( r ) {
			if ( ! r || ! r.ok || ! r.file ) { fileSay( ( r && ( r.error || r.message ) ) || __( 'That did not work. Reload the page and try again.', 'tokenforge-crucible' ) ); return; }
			st.file = r.file;
			filesRender();
		} );
	}

	// Pages for "Loads on": the site search, plus the number typed as a post ID (builder templates are not in the search).
	function fileSearch( text ) {
		var host = q( '[data-crucible-file-found]' );
		if ( ! host ) { return; }
		text = String( text ).trim();
		var hit = function ( id, title ) {
			return '<button type="button" class="tf-btn tf-small" data-crucible-act="file-page" data-id="' + esc( id ) + '" data-title="' + esc( title ) + '">' + esc( title ) + '</button> ';
		};
		var own = /^\d{1,12}$/.test( text ) && parseInt( text, 10 ) > 0 ? hit( parseInt( text, 10 ), '#' + parseInt( text, 10 ) ) : '';
		host.innerHTML = own;
		if ( ! text ) { return; }
		var url = cfg.rest.replace( 'tokenforge-crucible/v1', 'wp/v2' ) + '/search';
		fetch( url + ( url.indexOf( '?' ) < 0 ? '?' : '&' ) + 'type=post&per_page=8&search=' + encodeURIComponent( text ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( r ) { return r.json(); } ).then( function ( rows ) {
			var box = q( '[data-crucible-file-search]' );
			// An answer to older text is dropped.
			if ( ! Array.isArray( rows ) || ! box || box.value.trim() !== text ) { return; }
			host.innerHTML = own + rows.map( function ( p ) { return hit( parseInt( p.id, 10 ), String( p.title || '#' + p.id ) ); } ).join( '' ) || esc( __( 'Nothing found.', 'tokenforge-crucible' ) );
		}, function () {} );
	}

	function filesAct( act, btn ) {
		var id = parseInt( btn.getAttribute( 'data-id' ), 10 );
		var failed = function ( r ) { fileSay( ( r && ( r.error || r.message ) ) || __( 'That did not work. Reload the page and try again.', 'tokenforge-crucible' ) ); };
		if ( 'file-new' === act ) {
			var name = ( q( '[data-crucible-file-new]' ).value || '' ).trim();
			if ( ! FILE_NAME.test( name ) ) { fileSay( __( 'Use lower case letters, digits, "-" and "_", with "/" for folders (up to four parts).', 'tokenforge-crucible' ) ); return; }
			if ( st.files.some( function ( f ) { return f.name === name; } ) ) { fileOpen( name ); return; }
			st.file = { name: name, code: '', rev: '', pages: [], types: [] };
			filesRender();
		}
		if ( 'file-open' === act ) { fileOpen( btn.getAttribute( 'data-name' ) ); }
		if ( 'file-close' === act ) { st.file = null; filesRender(); }
		if ( ! st.file ) { return; }
		if ( 'file-page' === act && id > 0 ) {
			fileRead();
			if ( ! st.file.pages.some( function ( p ) { return p.id === id; } ) ) { st.file.pages.push( { id: id, title: btn.getAttribute( 'data-title' ) || '#' + id } ); }
			filesRender();
		}
		if ( 'file-unpage' === act ) {
			fileRead();
			st.file.pages = st.file.pages.filter( function ( p ) { return p.id !== id; } );
			filesRender();
		}
		if ( 'file-save' === act ) {
			fileRead();
			var sent = st.file.name;
			fileSay( __( 'Saving…', 'tokenforge-crucible' ) );
			api( '/scss-file', 'POST', { name: sent, code: st.file.code, rev: st.file.rev, pages: st.file.pages.map( function ( p ) { return p.id; } ), types: st.file.types } ).then( function ( r ) {
				if ( ! r || ! r.ok ) { failed( r ); return; }
				st.files = Array.isArray( r.files ) ? r.files : st.files;
				if ( st.file && st.file.name === sent && r.file ) { st.file = r.file; }
				filesRender();
				var err = r.status && r.status.error;
				// The line is the file's own only when the error is in this file (the message names any other file).
				fileSay( err ? __( 'Saved, but the SCSS does not compile:', 'tokenforge-crucible' ) + ' ' + ( err.message || err ) + ( err.line && ( undefined === err.file || err.file === sent ) && ! /\(in the file /.test( err.message || '' ) ? ' (' + __( 'line', 'tokenforge-crucible' ) + ' ' + err.line + ')' : '' ) : __( 'Saved', 'tokenforge-crucible' ) );
			}, function () { failed(); } );
		}
		if ( 'file-delete' === act && window.confirm( __( 'Delete this file? A @use line that names it will stop working. You can put it back from Versions.', 'tokenforge-crucible' ) ) ) {
			api( '/scss-file', 'POST', { name: st.file.name, 'delete': true } ).then( function ( r ) {
				if ( ! r || ! r.ok ) { failed( r ); return; }
				st.files = Array.isArray( r.files ) ? r.files : st.files;
				st.file = null;
				filesRender();
				fileSay( __( 'Deleted', 'tokenforge-crucible' ) );
			}, function () { failed(); } );
		}
	}
	// ---- Versions -------------------------------------------------------------

	function versionsPanelHtml() {
		var desc = __( 'Before every save of the Global SCSS, its switches or your recipes, TokenForge keeps a copy of all its settings (the last 20, under Recent saves). Save a version with a name to keep it for good, see what changed since then, or put parts of it back.', 'tokenforge-crucible' );
		var body = '<p class="tf-muted tf-desc">' + esc( desc ) + '</p>' +
			'<p class="tf-ver-new"><input type="text" maxlength="60" data-crucible-ver-name placeholder="' + esc( __( 'Name, for example: before the new header', 'tokenforge-crucible' ) ) + '" aria-label="' + esc( __( 'Version name', 'tokenforge-crucible' ) ) + '"> ' +
			'<button type="button" class="tf-btn tf-primary" data-crucible-act="ver-save">' + esc( __( 'Save a version', 'tokenforge-crucible' ) ) + '</button> ' +
			'<span class="tf-muted" data-crucible-ver-state role="status"></span></p>' +
			'<div data-crucible-versions></div>' +
			'<p><button type="button" class="tf-btn tf-small" data-act="goto" data-go="transfer">' + esc( __( 'All settings history', 'tokenforge-crucible' ) ) + '</button></p>';
		return panelHtml( 'scss-versions', __( 'Versions', 'tokenforge-crucible' ), desc, '', body );
	}

	// ---- Token lint and the site report (the findings are drawn by scss-lint.js) ----

	// Checks the text in the editor, saved or not.
	function lintRun() {
		var host = q( '[data-crucible-lint]' );
		if ( ! Lint || ! host ) { return; }
		host.hidden = false;
		host.textContent = Lint.text.busy;
		return api( '/lint', 'POST', { source: st.ed ? st.ed.getValue() : st.source } ).then( function ( r ) {
			st.lint = r && r.ok ? r.findings || [] : [];
			host.innerHTML = r && r.ok ? Lint.html( st.lint ) : '<span class="tf-scss-err">' + esc( ( r && ( r.error || r.message ) ) || Lint.text.failed ) + '</span>';
		}, function () { host.textContent = Lint.text.failed; } );
	}

	// Puts one suggested token (or all of them) into the editor; Save stores it like any other edit.
	function lintFix( which ) {
		if ( ! st.ed || ! st.lint ) { return; }
		var v = st.ed.getValue(), out = Lint.apply( v, 'all' === which ? st.lint : [ st.lint[ parseInt( which, 10 ) ] ] );
		if ( out !== v ) {
			st.ed.setValue( out );
			draftSet( out === st.source ? '' : out );
			updateLost();
		}
		lintRun();
	}

	function reportPanelHtml() {
		if ( ! Lint ) { return ''; }
		var desc = __( 'Looks through the Global SCSS and the custom CSS of the builder (elements, classes, page and site CSS) and lists the hard-coded values, each with the token to use instead. Nothing is changed: open the place and replace the value there.', 'tokenforge-crucible' );
		var more = __( 'The same scan also finds the classes of the Global SCSS that nothing on the site uses, the classes that do the same as another one, and what each class weighs. Nothing is removed or merged.', 'tokenforge-crucible' );
		var views = [ [ 'values', __( 'Values', 'tokenforge-crucible' ) ], [ 'unused', __( 'Unused', 'tokenforge-crucible' ) ], [ 'dupes', __( 'Duplicates', 'tokenforge-crucible' ) ], [ 'sizes', __( 'Sizes', 'tokenforge-crucible' ) ] ];
		var body = '<p class="tf-muted tf-desc">' + esc( desc ) + ' ' + esc( more ) + '</p>' +
			'<p><button type="button" class="tf-btn tf-primary" data-crucible-act="site-scan">' + esc( __( 'Scan the site', 'tokenforge-crucible' ) ) + '</button> ' +
			'<span class="tf-muted" data-crucible-report-state role="status"></span></p>' +
			'<p class="tf-report-views">' + views.map( function ( v ) {
				return '<button type="button" class="tf-btn tf-small" data-crucible-act="report-view" data-view="' + v[0] + '" aria-pressed="' + ( v[0] === st.view ? 'true' : 'false' ) + '">' + esc( v[1] ) + '</button>';
			} ).join( ' ' ) + '</p><div class="tf-report" data-crucible-report></div>';
		return panelHtml( 'scss-report', __( 'Site report', 'tokenforge-crucible' ), desc + ' ' + more, '', body );
	}

	// The Unused, Duplicates and Sizes views: the classes part of the report. A report only, nothing is removed.
	function classesHtml( r, view ) {
		var c = r.classes, muted = function ( t ) { return '<p class="tf-muted">' + esc( t ) + '</p>'; };
		if ( ! c ) { return muted( __( 'Scan the site to see this.', 'tokenforge-crucible' ) ); }
		// go: a button that opens the class in the editor above.
		var name = function ( n, go ) {
			return '<code>.' + esc( n ) + '</code>' + ( go ? ' <button type="button" class="tf-btn tf-small" data-crucible-act="class-goto" data-name="' + esc( n ) + '">' + esc( __( 'Go to', 'tokenforge-crucible' ) ) + '</button>' : '' );
		};
		var list = function ( rows ) { return '<ul class="tf-lint-list">' + rows.map( function ( x ) { return '<li>' + x + '</li>'; } ).join( '' ) + '</ul>'; };
		var html = false === c.compiles ? '<p class="tf-scss-err">' + esc( __( 'The Global SCSS does not compile, so its classes could not be read.', 'tokenforge-crucible' ) ) + '</p>' : '';
		if ( 'unused' === view ) {
			var unused = c.unused || [];
			if ( r.capped ) { return html + muted( __( 'The site has too many posts to be sure which classes are unused, so none is listed.', 'tokenforge-crucible' ) ); }
			if ( ! unused.length ) { return html + muted( __( 'No unused classes found.', 'tokenforge-crucible' ) ); }
			/* translators: 1: number of unused classes, 2: number of classes in the Global SCSS */
			html += '<p>' + esc( Lint.fill( __( 'Classes of the Global SCSS found nowhere on the site: %1$s of %2$s.', 'tokenforge-crucible' ), c.unused_total || unused.length, c.total || 0 ) ) + ' ' +
				esc( __( 'Content, the data of the builder, menus and widgets were read. A class added by a script or a template file is not seen, so check before you remove one.', 'tokenforge-crucible' ) ) + '</p>';
			/* translators: %s: number of classes listed */
			if ( ( c.unused_total || 0 ) > unused.length ) { html += muted( Lint.fill( __( 'The first %s are listed.', 'tokenforge-crucible' ), unused.length ) ); }
			return html + list( unused.map( function ( n ) { return name( n, true ); } ) );
		}
		if ( 'dupes' === view ) {
			var d = c.dupes || {}, group = function ( title, groups, go ) {
				return '<h4>' + esc( title ) + ' (' + groups.length + ')</h4>' + ( groups.length ? list( groups.map( function ( g ) {
					return g.map( function ( n ) { return name( n, go ); } ).join( ' = ' );
				} ) ) : muted( __( 'No duplicates found.', 'tokenforge-crucible' ) ) );
			};
			return html + group( __( 'Classes of the builder that do the same', 'tokenforge-crucible' ), d.bricks || [], false ) +
				group( __( 'Classes of the Global SCSS with the same declarations', 'tokenforge-crucible' ), d.scss || [], true );
		}
		var sizes = c.sizes || [];
		/* translators: %s: size in KB */
		return html + '<p>' + esc( Lint.fill( __( 'The compiled Global SCSS is %s KB. Its biggest classes, with the rules inside media queries:', 'tokenforge-crucible' ), c.total_kb || 0 ) ) + '</p>' +
			list( sizes.map( function ( s ) {
				/* translators: %s: size in bytes */
				return name( s.name, true ) + ' <span class="tf-muted">' + esc( Lint.fill( __( '%s bytes', 'tokenforge-crucible' ), s.bytes ) ) + '</span>';
			} ) );
	}

	// Opens the block of a class in the editor.
	function classGoTo( name ) {
		var m = st.ed ? new RegExp( '(^|[\\s},;])\\.' + String( name ).replace( /[^\w-]/g, '' ) + '(?![\\w-])' ).exec( st.ed.getValue() ) : null, panel = q( '[data-crucible-panel="scss"]' );
		if ( ! m ) { return; }
		if ( panel ) { setClosed( panel, false ); }
		st.ed.goTo( m.index + m[1].length );
	}

	function reportHtml( r ) {
		if ( ! r || ! r.time ) { return '<p class="tf-muted">' + esc( __( 'Not scanned yet.', 'tokenforge-crucible' ) ) + '</p>'; }
		if ( 'values' !== st.view ) { return classesHtml( r, st.view ); }
		var lint = r.lint || {}, items = lint.items || [], by = lint.by_place || {}, groups = [], at = {};
		items.forEach( function ( f ) {
			if ( ! ( f.place in at ) ) { at[ f.place ] = groups.length; groups.push( { place: f.place, link: f.link || '', items: [] } ); }
			groups[ at[ f.place ] ].items.push( f );
		} );
		/* translators: 1: date and time of the scan, 2: number of posts read */
		var html = '<p>' + esc( Lint.fill( __( 'Scanned %1$s. Posts read: %2$s.', 'tokenforge-crucible' ), new Date( r.time * 1000 ).toLocaleString(), r.posts || 0 ) ) + ' ' +
			/* translators: 1: number of hard-coded values, 2: how many of them have a token */
			esc( Lint.fill( __( 'Hard-coded values: %1$s. With a token: %2$s.', 'tokenforge-crucible' ), lint.total || 0, lint.with_token || 0 ) ) + '</p>';
		if ( r.capped ) { html += '<p class="tf-muted">' + esc( __( 'The site has more posts than one scan reads, so this covers a part of it.', 'tokenforge-crucible' ) ) + '</p>'; }
		if ( lint.capped ) { html += '<p class="tf-muted">' + esc( __( 'The site has more CSS than one scan checks, so the hard-coded values cover a part of it.', 'tokenforge-crucible' ) ) + '</p>'; }
		/* translators: %s: number of findings listed */
		if ( ( lint.total || 0 ) > items.length ) { html += '<p class="tf-muted">' + esc( Lint.fill( __( 'The first %s are listed.', 'tokenforge-crucible' ), items.length ) ) + '</p>'; }
		return html + groups.map( function ( g ) {
			return '<h4>' + esc( g.place ) + ' (' + ( by[ g.place ] || g.items.length ) + ')' + ( g.link ? ' <a class="tf-lint-edit" href="' + esc( g.link ) + '">' + esc( __( 'Edit', 'tokenforge-crucible' ) ) + '</a>' : '' ) + '</h4>' + Lint.html( g.items, { fix: false } );
		} ).join( '' );
	}

	// scan true: read the site again (POST), else show the stored report.
	function reportLoad( scan ) {
		var say = function ( t ) { var el = q( '[data-crucible-report-state]' ); if ( el ) { el.textContent = t; } };
		if ( scan ) { say( __( 'Scanning…', 'tokenforge-crucible' ) ); }
		return api( '/site-report', scan ? 'POST' : 'GET' ).then( function ( r ) {
			var host = q( '[data-crucible-report]' );
			st.report = r;
			if ( host ) { host.innerHTML = reportHtml( r ); }
			say( '' );
		}, function () { say( __( 'The scan did not work. Try again.', 'tokenforge-crucible' ) ); } );
	}

	function verSay( text ) {
		var el = q( '[data-crucible-ver-state]' );
		if ( el ) { el.textContent = text; }
	}

	function verRender() {
		var host = q( '[data-crucible-versions]' );
		if ( host ) { host.innerHTML = verListHtml(); }
	}

	// Every versions route answers with both lists; a refused call says why.
	function verSet( r ) {
		if ( r && Array.isArray( r.named ) ) {
			st.ver = { named: r.named, recent: r.recent || [] };
			verRender();
			return true;
		}
		verSay( ( r && ( r.error || r.message ) ) || __( 'That did not work. Reload the page and try again.', 'tokenforge-crucible' ) );
		return false;
	}

	function verLoad() { return api( '/versions' ).then( verSet ); }

	function verDiffHtml( d ) {
		if ( ! d ) { return esc( __( 'Loading…', 'tokenforge-crucible' ) ); }
		if ( ! d.ok ) { return '<span class="tf-scss-err">' + esc( d.error || d.message || __( 'This version is gone or damaged.', 'tokenforge-crucible' ) ) + '</span>'; }
		var word = { added: __( 'added since then', 'tokenforge-crucible' ), removed: __( 'removed since then', 'tokenforge-crucible' ), changed: __( 'changed', 'tokenforge-crucible' ) };
		var names = function ( title, rows, pre ) {
			return rows.length ? '<h4>' + esc( title ) + '</h4><ul>' + rows.map( function ( r ) {
				var state = word.hasOwnProperty( r[1] ) ? r[1] : 'changed';
				return '<li><code>' + esc( pre + r[0] ) + '</code> <span class="tf-ver-' + state + '">' + esc( word[ state ] ) + '</span></li>';
			} ).join( '' ) + '</ul>' : '';
		};
		var val = function ( v ) { return null === v ? '<em>' + esc( __( 'not there', 'tokenforge-crucible' ) ) + '</em>' : '<code>' + esc( v ) + '</code>'; };
		var html = names( __( 'Classes', 'tokenforge-crucible' ), d.classes || [], '.' ) + names( __( 'Your recipes', 'tokenforge-crucible' ), d.recipes || [], '?' );
		if ( ( d.tokens || [] ).length ) {
			html += '<h4>' + esc( __( 'Tokens (then → now)', 'tokenforge-crucible' ) ) + '</h4><ul>' + d.tokens.map( function ( t ) {
				return '<li><code>--' + esc( t[0] ) + '</code>: ' + val( t[1] ) + ' → ' + val( t[2] ) + '</li>';
			} ).join( '' ) + '</ul>';
		}
		if ( d.palette ) { html += '<p>' + esc( __( 'The colour families changed.', 'tokenforge-crucible' ) ) + '</p>'; }
		html += names( __( 'SCSS files', 'tokenforge-crucible' ), d.files || [], '' );
		// scss_html and the table of each file are made by WordPress with wp_text_diff(): escaped on the server.
		( d.files || [] ).forEach( function ( f ) {
			if ( f[2] ) { html += '<h4>' + esc( f[0] ) + ', ' + esc( __( 'line by line', 'tokenforge-crucible' ) ) + '</h4><div class="tf-ver-diff">' + f[2] + '</div>'; }
		} );
		if ( d.scss_html ) { html += '<h4>' + esc( __( 'Global SCSS, line by line', 'tokenforge-crucible' ) ) + '</h4><div class="tf-ver-diff">' + d.scss_html + '</div>'; }
		return html ? '<div class="tf-report">' + html + '</div>' : '<span class="tf-muted">' + esc( __( 'Global SCSS, recipes and tokens are the same as now.', 'tokenforge-crucible' ) ) + '</span>';
	}

	function verRestoreHtml( key ) {
		var parts = [ [ 'scss', __( 'Global SCSS', 'tokenforge-crucible' ) ], [ 'recipes', __( 'Your recipes', 'tokenforge-crucible' ) ], [ 'tokens', __( 'Tokens and colour families', 'tokenforge-crucible' ) ], [ 'all', __( 'Everything (all TokenForge settings too)', 'tokenforge-crucible' ) ] ];
		return '<div class="tf-ver-restore"><p>' + esc( __( 'Put back from this version (a copy of how things are now is kept first, under Recent saves):', 'tokenforge-crucible' ) ) + '</p>' + parts.map( function ( p ) {
			return '<label class="tf-sync-row"><input type="checkbox" data-crucible-ver-part="' + p[0] + '"' + ( 'scss' === p[0] ? ' checked' : '' ) + '> <span>' + esc( p[1] ) + '</span></label>';
		} ).join( '' ) + '<p><button type="button" class="tf-btn tf-small tf-danger" data-crucible-act="ver-restore-run" data-key="' + esc( key ) + '">' + esc( __( 'Restore the ticked parts', 'tokenforge-crucible' ) ) + '</button> ' +
			'<button type="button" class="tf-btn tf-small" data-crucible-act="ver-cancel">' + esc( __( 'Cancel', 'tokenforge-crucible' ) ) + '</button></p></div>';
	}

	function verListHtml() {
		var o = st.verOpen;
		var why = { save: __( 'Before a save', 'tokenforge-crucible' ), 'import': __( 'Before an import', 'tokenforge-crucible' ), reset: __( 'Before a reset', 'tokenforge-crucible' ), setup: __( 'Before the setup wizard', 'tokenforge-crucible' ), restore: __( 'Before a restore', 'tokenforge-crucible' ) };
		var rows = function ( list, pre ) {
			return list.map( function ( v ) {
				var key = pre + parseInt( v.id, 10 );
				var open = o && o.key === key ? o : null;
				var b = function ( act, label, cls, more ) { return '<button type="button" class="tf-btn tf-small' + ( cls || '' ) + '" data-crucible-act="' + act + '" data-key="' + key + '"' + ( more || '' ) + '>' + esc( label ) + '</button> '; };
				return '<tr><td>' + esc( new Date( v.time * 1000 ).toLocaleString() ) + '</td><td>' + esc( 'n' === pre ? v.name : ( why.hasOwnProperty( v.trigger ) ? why[ v.trigger ] : v.trigger ) ) + '</td><td>' + esc( v.user ) + '</td><td class="tf-ver-acts">' +
					b( 'ver-diff', __( 'What changed', 'tokenforge-crucible' ), '', ' aria-expanded="' + ( open && 'diff' === open.mode ? 'true' : 'false' ) + '"' ) +
					b( 'ver-restore', __( 'Restore', 'tokenforge-crucible' ), '', ' aria-expanded="' + ( open && 'restore' === open.mode ? 'true' : 'false' ) + '"' ) +
					( 'n' === pre ? b( 'ver-del', __( 'Delete', 'tokenforge-crucible' ), ' tf-danger' ) : b( 'ver-keep', __( 'Keep with a name', 'tokenforge-crucible' ) ) ) + '</td></tr>' +
					( open ? '<tr><td colspan="4">' + ( 'restore' === open.mode ? verRestoreHtml( key ) : verDiffHtml( open.diff ) ) + '</td></tr>' : '' );
			} ).join( '' );
		};
		var table = function ( title, list, pre, none ) {
			return '<h4>' + esc( title ) + '</h4>' + ( list.length ? '<div class="tf-tablewrap"><table class="tf-table tf-ver-table"><tbody>' + rows( list, pre ) + '</tbody></table></div>' : '<p class="tf-muted">' + esc( none ) + '</p>' );
		};
		return table( __( 'Versions with a name', 'tokenforge-crucible' ), st.ver.named, 'n', __( 'None yet.', 'tokenforge-crucible' ) ) +
			table( __( 'Recent saves', 'tokenforge-crucible' ), st.ver.recent, 'h', __( 'Nothing yet. The first copy is taken at your next save.', 'tokenforge-crucible' ) );
	}

	function verAct( act, btn ) {
		var key = btn.getAttribute( 'data-key' ) || '';
		var field = q( '[data-crucible-ver-name]' );
		if ( 'ver-save' === act || 'ver-keep' === act ) {
			var name = field ? field.value.trim() : '';
			if ( ! name ) {
				verSay( __( 'Type a name for the version first.', 'tokenforge-crucible' ) );
				if ( field ) { field.focus(); }
				return;
			}
			api( '/versions', 'POST', 'ver-keep' === act ? { name: name, from: parseInt( key.slice( 1 ), 10 ) } : { name: name } ).then( function ( r ) {
				if ( verSet( r ) ) { field.value = ''; verSay( __( 'Version saved', 'tokenforge-crucible' ) ); }
			} );
		}
		if ( 'ver-del' === act && window.confirm( __( 'Delete this version? This cannot be undone.', 'tokenforge-crucible' ) ) ) {
			api( '/versions/delete', 'POST', { id: parseInt( key.slice( 1 ), 10 ) } ).then( verSet );
		}
		if ( 'ver-cancel' === act ) { st.verOpen = null; verRender(); }
		if ( 'ver-diff' === act || 'ver-restore' === act ) {
			var mode = act.slice( 4 );
			var same = st.verOpen && st.verOpen.key === key && st.verOpen.mode === mode;
			st.verOpen = same ? null : { key: key, mode: mode, diff: null };
			verRender();
			if ( ! same && 'diff' === mode ) {
				api( '/versions/diff' + ( cfg.rest.indexOf( '?' ) < 0 ? '?' : '&' ) + 'key=' + encodeURIComponent( key ) ).then( function ( r ) {
					if ( st.verOpen && st.verOpen.key === key && 'diff' === st.verOpen.mode ) { st.verOpen.diff = r || {}; verRender(); }
				} );
			}
		}
		if ( 'ver-restore-run' === act ) {
			var parts = [].map.call( btn.closest( '.tf-ver-restore' ).querySelectorAll( '[data-crucible-ver-part]:checked' ), function ( c ) { return c.getAttribute( 'data-crucible-ver-part' ); } );
			if ( ! parts.length ) { verSay( __( 'Tick what to restore.', 'tokenforge-crucible' ) ); return; }
			btn.disabled = true;
			api( '/versions/restore', 'POST', { key: key, parts: parts } ).then( function ( r ) {
				st.verOpen = null;
				if ( ! verSet( r ) ) { verRender(); return; }
				verSay( __( 'Restored', 'tokenforge-crucible' ) );
				// Tokens, recipes and settings live in the dashboard's own state: load the page again to show them.
				if ( 'scss' !== parts.join() ) { window.location.reload(); return; }
				// The draft stays: text that was typed and not saved is in no copy, so the editor offers it back.
				load();
			} );
		}
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
		var el = q( '[data-crucible-recipes-state]' );
		if ( el ) { el.textContent = text; }
	}

	function recipesLoad( host ) {
		st.recipesReady = false;
		api( '/recipes' ).then( function ( r ) {
			if ( ! Array.isArray( r.recipes ) ) {
				recipesSetState( r.message || __( 'Could not load the recipes.', 'tokenforge-crucible' ) );
				return;
			}
			host.innerHTML = r.recipes.map( recipeRowHtml ).join( '' );
			st.recipesReady = true;
		} );
	}

	function recipesSave() {
		// Saving before the list loaded would replace every saved recipe with only the rows on screen.
		if ( ! st.recipesReady ) {
			recipesSetState( __( 'The recipes have not loaded yet. Reload the page and try again.', 'tokenforge-crucible' ) );
			return;
		}
		var rows = qa( '.tf-recipe' );
		var list = [].map.call( rows, function ( row ) {
			return { n: row.querySelector( '.tf-recipe-name' ).value, code: row.querySelector( '.tf-recipe-code' ).value };
		} );
		return api( '/recipes', 'POST', { recipes: list } ).then( function ( r ) {
			if ( ! Array.isArray( r.recipes ) ) {
				recipesSetState( r.message || __( 'Could not save the recipes.', 'tokenforge-crucible' ) );
				return;
			}
			recipesSetState( __( 'Saved', 'tokenforge-crucible' ) + ' · ' + r.recipes.length );
			// The editor completes ?recipe from st.recipes, so fetch the new merged list.
			return api( '/scss' ).then( function ( s ) { st.recipes = s.recipes || st.recipes; st.recipesOn = false !== s.recipes_on; } );
		} );
	}

	// ---- Mounting ------------------------------------------------------------

	function mountAll() {
		bindShadow();
		var ta = q( 'textarea[data-crucible-scss]:not([data-mounted])' );
		if ( ta && window.TokenForgeScssEditor ) {
			ta.setAttribute( 'data-mounted', '' );
			st.ed = window.TokenForgeScssEditor.create( ta, {
				api: api,
				codeEditor: cfg.codeEditor || null,
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
		var host = q( '[data-crucible-recipes]:not([data-mounted])' );
		if ( host ) {
			host.setAttribute( 'data-mounted', '' );
			recipesLoad( host );
		}
		var report = q( '[data-crucible-report]:not([data-mounted])' );
		if ( report ) {
			report.setAttribute( 'data-mounted', '' );
			reportLoad( false );
		}
		var files = q( '[data-crucible-files]:not([data-mounted])' );
		if ( files ) {
			files.setAttribute( 'data-mounted', '' );
			filesRender();
			filesLoad();
		}
		var vers = q( '[data-crucible-versions]:not([data-mounted])' );
		if ( vers ) {
			vers.setAttribute( 'data-mounted', '' );
			verRender();
			verLoad();
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
		// The Crucible icon (assets/admin/crucible-icon.svg, 20 px grid) drawn in the nav's 24 px box.
		icons[ ID ] = '<g transform="scale(1.2)" fill="currentColor" stroke="none"><rect x="2" y="2.5" width="16" height="2.5" rx="1.2"/><path d="M3.5 6.5h13l-2 10.5a1.2 1.2 0 0 1-1.2 1H6.7a1.2 1.2 0 0 1-1.2-1z" opacity=".35"/><rect x="5.5" y="8" width="4" height="3.4" rx="1"/><rect x="10.5" y="8" width="4" height="3.4" rx="1" opacity=".7"/><rect x="7.5" y="12.4" width="5" height="2.6" rx="1" opacity=".5"/></g>';
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
		var fix = e.target.closest && e.target.closest( '[data-crucible-lint] [data-lint-fix]' );
		if ( fix ) { e.preventDefault(); lintFix( fix.getAttribute( 'data-lint-fix' ) ); return; }
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
		if ( 'lint' === act ) { lintRun(); }
		if ( 'site-scan' === act ) { reportLoad( true ); }
		if ( 'report-view' === act ) {
			st.view = btn.getAttribute( 'data-view' );
			[].forEach.call( qa( '[data-crucible-act="report-view"]' ), function ( b ) { b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' ); } );
			var rep = q( '[data-crucible-report]' );
			if ( rep ) { rep.innerHTML = reportHtml( st.report ); }
		}
		if ( 'class-goto' === act ) { classGoTo( btn.getAttribute( 'data-name' ) ); }
		if ( 'css' === act ) {
			var out = q( '[data-crucible-out]' );
			if ( out && ! out.hidden ) { out.hidden = true; } else { check(); }
		}
		if ( 'restore' === act && st.ed ) { st.ed.setValue( draftGet() ); updateLost(); }
		if ( 'expandall' === act || 'collapseall' === act ) {
			[].forEach.call( qa( '[data-crucible-panel]' ), function ( p ) { setClosed( p, 'collapseall' === act ); } );
		}
		if ( 'resetsec' === act && st.ed && window.confirm( __( 'Empty the editor? Nothing is saved until you press Save.', 'tokenforge-crucible' ) ) ) { st.ed.setValue( '' ); }
		if ( 'recipes-save' === act ) { recipesSave(); }
		if ( 'recipe-add' === act ) {
			var host = q( '[data-crucible-recipes]' );
			if ( host ) { host.insertAdjacentHTML( 'beforeend', recipeRowHtml( null ) ); }
		}
		if ( 'recipe-remove' === act ) { btn.closest( '.tf-recipe' ).remove(); }
		if ( 0 === act.indexOf( 'ver-' ) ) { verAct( act, btn ); }
		if ( 0 === act.indexOf( 'file-' ) ) { filesAct( act, btn ); }
	}
	on( 'click', onAct );
	on( 'input', function ( e ) {
		var box = e.target.closest && e.target.closest( '[data-crucible-file-search]' );
		if ( ! box ) { return; }
		clearTimeout( fileTimer );
		fileTimer = setTimeout( function () { fileSearch( box.value ); }, 300 );
	} );
	on( 'keydown', function ( e ) {
		if ( ( 'Enter' === e.key || ' ' === e.key ) && e.target.matches && e.target.matches( '.tf-panel-head[data-crucible-act]' ) ) { onAct( e ); }
	} );

	on( 'input', function ( e ) {
		var t = e.target;
		if ( ! t.classList || ! t.classList.contains( 'tf-recipe-name' ) ) { return; }
		// Same cleanup as 0.53.7: invalid characters become "-" while typing.
		t.value = t.value.toLowerCase().replace( /^\?/, '' ).replace( /[^a-z0-9-]+/g, '-' );
		var hd = t.closest( '.tf-recipe' ).querySelector( '.tf-preset-head strong' );
		if ( hd ) { hd.textContent = '?' + ( t.value || '…' ); }
	} );

	// The head switch: dims the panel now and tells the server (it must honor `enabled` on /scss-save).
	on( 'change', function ( e ) {
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
	on( 'change', function ( e ) {
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
