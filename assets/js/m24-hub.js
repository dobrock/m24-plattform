/**
 * M24 Plattform — Modell-Hub Frontend (View-Umschalter + AJAX-Sortierung/Suche + Effekt)
 * Datei: assets/js/m24-hub.js  ·  Enqueue: modules/katalog/catalog-assets.php (nur Hubs)
 *
 * Progressive Enhancement: Die Seite funktioniert ohne JS (Server-Render von ?sort=/?q=
 * + echte /seite/N/-Pagination). Dieses Script veredelt nur — faengt Klicks/Changes ab,
 * tauscht das Grid per REST (ueber den GANZEN Bestand), aktualisiert die URL via pushState.
 * View-Umschalter (3/4/Liste) ist rein clientseitig (localStorage), Default = 4 Spalten.
 */
( function () {
	'use strict';
	var grid = document.getElementById( 'm24hub-grid' );
	if ( ! grid ) { return; }
	var cfg       = window.M24Hub || {};
	var sw        = document.getElementById( 'm24hub-viewsw' );
	var sortSel   = document.getElementById( 'm24hub-sort' );
	var qInput    = document.getElementById( 'm24hub-q' );
	var countEl   = document.getElementById( 'm24hub-count' );
	var pagerWrap = document.getElementById( 'm24hub-pagerwrap' );
	var form      = document.getElementById( 'm24hub-controls' );
	var VIEW_KEY  = 'm24_view_gebrauchtteile';
	var VIEWS     = [ 'view-3', 'view-4', 'view-list' ];
	var reduce    = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/* ── Ansicht (rein clientseitig, localStorage, Default view-4) ───────────── */
	function applyView( v ) {
		if ( VIEWS.indexOf( v ) < 0 ) { v = 'view-4'; }
		grid.classList.remove( 'view-3', 'view-4', 'view-list' );
		grid.classList.add( v );
		if ( sw ) {
			[].forEach.call( sw.querySelectorAll( 'button' ), function ( b ) {
				b.classList.toggle( 'on', b.getAttribute( 'data-view' ) === v );
			} );
		}
	}
	var savedView;
	try { savedView = localStorage.getItem( VIEW_KEY ); } catch ( e ) {}
	applyView( savedView || 'view-4' );
	if ( sw ) {
		sw.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button[data-view]' );
			if ( ! btn ) { return; }
			var v = btn.getAttribute( 'data-view' );
			applyView( v );
			try { localStorage.setItem( VIEW_KEY, v ); } catch ( e2 ) {}
			animate();
		} );
	}

	/* ── Uebergangseffekt (Stagger-Einfaden); bei reduced-motion aus ─────────── */
	function animate() {
		if ( reduce ) { return; }
		var vis = [].slice.call( grid.querySelectorAll( '.m24-card' ) );
		grid.classList.remove( 'anim' );
		void grid.offsetWidth; // Reflow → Animation neu starten
		grid.classList.add( 'anim' );
		vis.forEach( function ( c, k ) { c.style.animationDelay = Math.min( k * 0.03, 0.3 ) + 's'; } );
		setTimeout( function () {
			grid.classList.remove( 'anim' );
			vis.forEach( function ( c ) { c.style.animationDelay = ''; } );
		}, 700 );
	}

	/* ── AJAX-Trefferliste (serverkorrekt ueber den ganzen Bestand) ──────────── */
	var busy = false;
	function fetchParts( opts ) {
		if ( ! cfg.restUrl ) { return; }
		opts = opts || {};
		var sort  = sortSel ? sortSel.value : 'neu';
		var q     = qInput ? qInput.value.trim() : '';
		var paged = opts.paged || 1;
		var url   = cfg.restUrl + '?hub=' + encodeURIComponent( cfg.hub ) +
			'&sort=' + encodeURIComponent( sort ) +
			'&q=' + encodeURIComponent( q ) +
			'&paged=' + paged;
		busy = true;
		grid.setAttribute( 'aria-busy', 'true' );
		fetch( url, { headers: { 'X-WP-Nonce': cfg.nonce || '' } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				grid.innerHTML = d.cards || '';
				if ( countEl ) { countEl.textContent = d.count; }
				if ( pagerWrap ) { pagerWrap.innerHTML = d.pager || ''; bindPager(); }
				busy = false;
				grid.removeAttribute( 'aria-busy' );
				if ( opts.anim !== false ) { animate(); }
				if ( ! opts.noPush ) { pushUrl( sort, q, d.paged ); }
			} )
			.catch( function () { busy = false; grid.removeAttribute( 'aria-busy' ); } );
	}

	function pushUrl( sort, q, paged ) {
		if ( ! window.history || ! history.pushState || ! cfg.hubUrl ) { return; }
		var base = cfg.hubUrl + ( paged > 1 ? ( 'seite/' + paged + '/' ) : '' );
		var p = [];
		if ( q ) { p.push( 'q=' + encodeURIComponent( q ) ); }
		if ( sort && sort !== 'neu' ) { p.push( 'sort=' + encodeURIComponent( sort ) ); }
		history.pushState( { m24: 1 }, '', base + ( p.length ? ( '?' + p.join( '&' ) ) : '' ) );
	}

	/* ── Sortierung (mit Effekt) ─────────────────────────────────────────────── */
	if ( sortSel ) { sortSel.addEventListener( 'change', function () { fetchParts( { paged: 1 } ); } ); }

	/* ── Suche (debounced; pro Tastendruck KEIN Effekt) ──────────────────────── */
	var t = null;
	if ( qInput ) {
		qInput.addEventListener( 'input', function () {
			clearTimeout( t );
			t = setTimeout( function () { fetchParts( { paged: 1, anim: false } ); }, 320 );
		} );
	}

	/* ── No-JS-Form abfangen (Enter) → AJAX statt Full-Reload ────────────────── */
	if ( form ) {
		form.addEventListener( 'submit', function ( e ) { e.preventDefault(); fetchParts( { paged: 1 } ); } );
	}

	/* ── Pagination: echte Links abfangen ────────────────────────────────────── */
	function pageFromHref( href ) {
		var m = /seite\/(\d+)/.exec( href || '' );
		if ( m ) { return parseInt( m[ 1 ], 10 ); }
		var m2 = /[?&]paged=(\d+)/.exec( href || '' );
		return m2 ? parseInt( m2[ 1 ], 10 ) : 1;
	}
	function bindPager() {
		if ( ! pagerWrap ) { return; }
		[].forEach.call( pagerWrap.querySelectorAll( 'a.page-numbers' ), function ( a ) {
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				fetchParts( { paged: pageFromHref( a.getAttribute( 'href' ) ) } );
				var top = grid.getBoundingClientRect().top + window.pageYOffset - 90;
				window.scrollTo( { top: top, behavior: 'smooth' } );
			} );
		} );
	}
	bindPager();

	/* ── Zurueck/Vorwaerts (popstate) → aus URL re-synchronisieren ───────────── */
	window.addEventListener( 'popstate', function () {
		var u = new URL( window.location.href );
		var sort = u.searchParams.get( 'sort' ) || 'neu';
		var q = u.searchParams.get( 'q' ) || '';
		var pm = /seite\/(\d+)/.exec( u.pathname );
		var paged = pm ? parseInt( pm[ 1 ], 10 ) : 1;
		if ( sortSel ) { sortSel.value = sort; }
		if ( qInput ) { qInput.value = q; }
		fetchParts( { paged: paged, anim: false, noPush: true } );
	} );
}() );
