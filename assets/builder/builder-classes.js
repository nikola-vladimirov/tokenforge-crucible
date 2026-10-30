( function () {
	'use strict';


	function builderClasses( list ) {
		if ( ! list || ! list.length ) { return 0; }
		var root = document.querySelector( 'div.brx-body.main' );
		var g = root && root.__vue_app__ && root.__vue_app__.config && root.__vue_app__.config.globalProperties;
		if ( ! g ) { return 0; }
		var unref = function ( v ) { return v && v.__v_isRef ? v.value : v; };
		var st = unref( g.$_state );
		var gc = unref( g.$_globalClasses ) || ( st && unref( st.globalClasses ) ); // both name the same list in the builder 2.x
		if ( ! Array.isArray( gc ) ) { return 0; }
		var redraw = false;
		list.forEach( function ( c ) {
			var copy = JSON.parse( JSON.stringify( c.settings || {} ) );
			var cls = gc.find( function ( x ) { return x.id === c.id; } ) || gc.find( function ( x ) { return x.name === c.name; } );
			if ( cls ) { cls.settings = copy; } else { cls = { id: c.id, name: c.name, settings: copy }; gc.push( cls ); }
			// The class open in the panels is the builder's own copy: give it the new settings too.
			if ( st && st.activeClass && st.activeClass.id === cls.id ) {
				st.activeClass = 'function' === typeof g.$_clone ? g.$_clone( cls ) : JSON.parse( JSON.stringify( cls ) );
				redraw = true;
			}
			if ( st && st.globalChanges && Array.isArray( st.globalChanges.modified ) && st.globalChanges.modified.indexOf( cls.id ) < 0 ) { st.globalChanges.modified.push( cls.id ); }
		} );
		if ( redraw && 'function' === typeof g.$_rerenderControls ) { g.$_rerenderControls(); }
		return list.length;
	}

	window.TokenForgeCrucibleBuilderClasses = builderClasses;
}() );
