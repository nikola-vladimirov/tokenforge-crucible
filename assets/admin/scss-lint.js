/**
 * Token lint, shared by the dashboard editor and the dock: shows the findings of
 * POST /lint and puts a suggested token into the SCSS text.
 *
 * A finding: { line, prop, pn, found, use, match, fix, nth }. It is placed by its line, never
 * by a character offset (the server counts bytes, the browser UTF-16 units): on that line,
 * in the declaration of `prop` after pn earlier ones of the same prop, the nth `found`. Text that changed since the check is skipped.
 *
 * window.TokenForgeScssLint = { apply( text, findings ), html( findings, opts ), text, fill( text, a, b ) }.
 */
( function () {
	'use strict';
	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( s ) { return s; };

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } );
	}
	// Puts values into a translated text (%s, or %1$s and %2$s).
	function fill( s, a, b ) { return s.replace( /%(?:([12])\$)?s/g, function ( m, n ) { return '2' === n ? b : a; } ); }

	// Index of the finding's value on its line, or -1 when it is no longer there.
	function at( line, f ) {
		var re = new RegExp( '(^|[^\\w$@-])' + String( f.prop ).replace( /[^\w-]/g, '' ) + '\\s*:', 'gi' ), prop = re.exec( line );
		// Compact SCSS has more declarations of one prop on a line: skip the ones before this finding's.
		for ( var skip = f.pn || 0; prop && skip > 0; skip-- ) { prop = re.exec( line ); }
		if ( ! prop || ! f.found ) { return -1; }
		var from = prop.index + prop[0].length, end = line.slice( from ).search( /[;}]/ ), left = f.nth || 0;
		end = end < 0 ? line.length : from + end;
		for ( var i = line.indexOf( f.found, from ); i > -1 && i + f.found.length <= end; i = line.indexOf( f.found, i + 1 ) ) {
			// A whole value: "4px" is not found inside "24px", "#fff" not inside "#ffffff".
			if ( /[\w.#-]/.test( line.charAt( i - 1 ) ) || ( /\w$/.test( f.found ) && /[\w-]/.test( line.charAt( i + f.found.length ) ) ) ) { continue; }
			if ( 0 === left-- ) { return i; }
		}
		return -1;
	}

	function apply( text, findings ) {
		var lines = String( text ).split( '\n' ), hits = [], taken = {};
		( findings || [] ).forEach( function ( f ) {
			var n = f && f.fix ? f.line - 1 : -1, i = n > -1 && n < lines.length ? at( lines[ n ], f ) : -1;
			// One replacement for a place: a second one there would cut into the token just written.
			if ( i > -1 && ! taken[ n + ':' + i ] ) { taken[ n + ':' + i ] = true; hits.push( { n: n, i: i, f: f } ); }
		} );
		// Right to left, so a replacement never moves one that is still to come.
		hits.sort( function ( a, b ) { return b.n - a.n || b.i - a.i; } ).forEach( function ( h ) {
			lines[ h.n ] = lines[ h.n ].slice( 0, h.i ) + h.f.use + lines[ h.n ].slice( h.i + h.f.found.length );
		} );
		return lines.join( '\n' );
	}

	var text = {
		check: __( 'Check', 'tokenforge-crucible' ),
		title: __( 'Check for hard-coded values that have a token', 'tokenforge-crucible' ),
		busy: __( 'Checking…', 'tokenforge-crucible' ),
		failed: __( 'The check did not work. Try again.', 'tokenforge-crucible' )
	};

	// opts.fix false: a report (the replacement as text, no buttons). Buttons carry data-lint-fix = index or "all".
	function html( findings, opts ) {
		var fix = ! opts || false !== opts.fix, list = findings || [];
		if ( ! list.length ) { return '<p class="tf-lint-none">' + esc( __( 'No hard-coded values found.', 'tokenforge-crucible' ) ) + '</p>'; }
		var can = list.filter( function ( f ) { return f.fix; } ).length;
		/* translators: 1: number of hard-coded values, 2: how many of them have a token */
		var out = fix ? '<p class="tf-lint-sum"><span>' + esc( fill( __( 'Hard-coded values: %1$s. With a token: %2$s.', 'tokenforge-crucible' ), list.length, can ) ) + '</span>' +
			( can ? ' <button type="button" class="tf-btn tf-small tf-lint-btn" data-lint-fix="all">' + esc( __( 'Use all suggested tokens', 'tokenforge-crucible' ) ) + '</button>' : '' ) + '</p>' : '';
		return out + '<ul class="tf-lint-list">' + list.map( function ( f, i ) {
			/* translators: %s: line number */
			var where = '<span class="tf-lint-line">' + esc( fill( __( 'Line %s', 'tokenforge-crucible' ), f.line ) ) + '</span>: <code>' + esc( f.prop + ' ' + f.found ) + '</code> ';
			/* translators: %s: the token to use, for example var(--space-m) */
			var use = esc( fill( __( 'Use %s', 'tokenforge-crucible' ), f.use ) );
			if ( ! f.fix ) {
				/* translators: %s: the closest token or tokens */
				return '<li>' + where + '<span class="tf-lint-near">' + esc( fill( __( 'No token for this value. Closest: %s', 'tokenforge-crucible' ), f.use ) ) + '</span></li>';
			}
			return '<li>' + where + ( fix ? '<button type="button" class="tf-btn tf-small tf-lint-btn" data-lint-fix="' + i + '">' + use + '</button>' : '<span class="tf-lint-use">' + use + '</span>' ) + '</li>';
		} ).join( '' ) + '</ul>';
	}

	window.TokenForgeScssLint = { apply: apply, html: html, text: text, fill: fill };
} )();
