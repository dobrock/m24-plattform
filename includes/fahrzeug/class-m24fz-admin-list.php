<?php
/**
 * M24 Fahrzeug — Inserat-Verwaltung (§5)
 * Modul: includes/fahrzeug/class-m24fz-admin-list.php
 *
 * Status-Tabs mit Live-Zählern (Status-Modell §2), Toolbar (Suche Titel/ID, Filter Marke +
 * Baureihe, 5 Sortierungen), Tabelle (Fahrzeug · Status+„Online seit" · Preis inline · Statistik
 * Aufrufe/Merkliste/Anfragen · Kebab-Aktionen). Statuswechsel/Datum/Preis/Trash per AJAX
 * (Nonce + Capability). Keine Karosserie/Baujahr-/Laufleistung-Spalte (§0).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Admin_List {

	const NONCE = 'm24fz_admin';
	const PAGE  = 'm24fz-verwaltung';
	const CAP   = 'manage_options';

	/** Exakter Hook-Suffix von add_submenu_page() — für robustes Enqueue-Gating (überlebt Menü-Umzüge). */
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_m24fz_action', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		// Direkt unter dem Dach „MOTORSPORT24" registriert → sauberer Page-Hook
		// (admin.php?page=m24fz-verwaltung), eine einzige Registrierung, manage_options.
		self::$hook = add_submenu_page(
			'm24-plattform',
			'Inserat-Verwaltung', 'Inserat-Verwaltung', self::CAP, self::PAGE, array( __CLASS__, 'render' )
		);
	}

	/**
	 * CSS/JS sauber enqueuen — NICHT inline im Body (sonst läuft das jQuery-Snippet ggf. vor dem
	 * Footer-jQuery → Handler binden nie). wp_add_inline_script('jquery', …) garantiert die
	 * Reihenfolge. Gating exakt gegen den von add_submenu_page() gelieferten Hook.
	 */
	public static function assets( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook ) { return; }
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', self::js() );
		wp_enqueue_style( 'm24fzv-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;500;600;700;800&display=swap', array(), null );
		wp_register_style( 'm24fzv', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'm24fzv' );
		wp_add_inline_style( 'm24fzv', self::css() );
	}

	/* ── Status-Zähler (Status-Modell §2) ────────────────────────────────────── */

	private static function counts() {
		$ids = get_posts( array( 'post_type' => M24FZ_CPT::PT, 'post_status' => array( 'publish', 'private', 'draft', 'pending' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		$c = array( 'alle' => 0, 'gelistet' => 0, 'reserviert' => 0, 'verkauft' => 0, 'deaktiviert' => 0, 'entwurf' => 0, 'papierkorb' => 0 );
		foreach ( $ids as $pid ) {
			$st = M24FZ_CPT::status( $pid );
			if ( isset( $c[ $st ] ) ) { $c[ $st ]++; }
			$c['alle']++;
		}
		$c['papierkorb'] = count( get_posts( array( 'post_type' => M24FZ_CPT::PT, 'post_status' => 'trash', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) );
		return $c;
	}

	/** Query-IDs für einen Tab + Toolbar (Suche/Marke/Baureihe/Sortierung). */
	private static function query_ids( $filter, $q, $marke, $baureihe, $sort ) {
		$args = array(
			'post_type'      => M24FZ_CPT::PT,
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		// Tab → post_status + Inserat-Meta.
		$meta = array();
		switch ( $filter ) {
			case 'papierkorb':  $args['post_status'] = 'trash'; break;
			case 'entwurf':     $args['post_status'] = array( 'draft', 'pending' ); break;
			case 'deaktiviert': $args['post_status'] = 'private'; break;
			case 'verkauft':    $args['post_status'] = 'publish'; $meta[] = array( 'key' => M24FZ_CPT::INSERAT_META, 'value' => 'verkauft' ); break;
			case 'reserviert':  $args['post_status'] = 'publish'; $meta[] = array( 'key' => M24FZ_CPT::INSERAT_META, 'value' => 'reserviert' ); break;
			case 'gelistet':    $args['post_status'] = 'publish'; $meta[] = array( 'relation' => 'OR', array( 'key' => M24FZ_CPT::INSERAT_META, 'compare' => 'NOT EXISTS' ), array( 'key' => M24FZ_CPT::INSERAT_META, 'value' => '' ) ); break;
			default:            $args['post_status'] = array( 'publish', 'private', 'draft', 'pending' ); break; // alle
		}
		if ( '' !== $marke )    { $meta[] = array( 'key' => '_m24fz_marke', 'value' => $marke ); }
		if ( '' !== $baureihe ) { $meta[] = array( 'key' => '_m24fz_baureihe', 'value' => $baureihe ); }
		if ( $meta ) { $args['meta_query'] = $meta; }
		if ( '' !== $q ) {
			if ( ctype_digit( $q ) ) { $args['post__in'] = array( (int) $q ); }
			else { $args['s'] = $q; }
		}
		// Sortierungen (§5.2).
		switch ( $sort ) {
			case 'alt':       $args['orderby'] = 'date'; $args['order'] = 'ASC'; break;
			case 'preis-ab':  $args['m24_price_sort'] = 'DESC'; break;
			case 'preis-auf': $args['m24_price_sort'] = 'ASC'; break;
			case 'aufrufe':   $args['meta_key'] = '_m24fz_views'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'DESC'; break;
			default:          $args['orderby'] = 'date'; $args['order'] = 'DESC'; break; // neu
		}
		return get_posts( $args );
	}

	/** Distinct-Werte eines Meta-Keys (für Filter-Dropdowns). */
	private static function distinct_meta( $key ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID=pm.post_id AND p.post_type=%s
			 WHERE pm.meta_key=%s AND pm.meta_value<>'' ORDER BY pm.meta_value ASC",
			M24FZ_CPT::PT, $key ) );
		return array_values( array_filter( (array) $rows ) );
	}

	/* ── Render ──────────────────────────────────────────────────────────────── */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.' ); }
		$counts   = self::counts();
		$filter   = isset( $_GET['st'] ) ? sanitize_key( wp_unslash( $_GET['st'] ) ) : 'alle';
		$q        = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$marke    = isset( $_GET['marke'] ) ? sanitize_text_field( wp_unslash( $_GET['marke'] ) ) : '';
		$baureihe = isset( $_GET['baureihe'] ) ? sanitize_text_field( wp_unslash( $_GET['baureihe'] ) ) : '';
		$sort     = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'neu';
		$ids      = self::query_ids( $filter, $q, $marke, $baureihe, $sort );

		$labels = array( 'alle' => 'Alle', 'gelistet' => 'Gelistet', 'reserviert' => 'Reserviert', 'verkauft' => 'Verkauft', 'deaktiviert' => 'Deaktiviert', 'entwurf' => 'Entwurf', 'papierkorb' => 'Papierkorb' );
		$marken = self::distinct_meta( '_m24fz_marke' );
		$baur   = self::distinct_meta( '_m24fz_baureihe' );
		$base   = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap m24fzv">
			<nav class="m24fzv-bc">MOTORSPORT24 <span>›</span> Inserat-Verwaltung</nav>
			<div class="m24fzv-head">
				<div class="m24fzv-head-l">
					<h1>Inserat-Verwaltung <span class="m24fzv-pill"><?php echo (int) $counts['alle']; ?> Inserate</span></h1>
					<p class="m24fzv-sub">Bestand verwalten, Status setzen, Statistiken einsehen — alles an einer Stelle.</p>
				</div>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . M24FZ_CPT::PT ) ); ?>" class="m24fzv-newbtn">＋ Neues Fahrzeug</a>
			</div>

			<div class="m24fzv-tabs"><?php foreach ( $labels as $k => $l ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'st', $k, $base ) ); ?>" data-tab="<?php echo esc_attr( $k ); ?>" class="m24fzv-tab<?php echo $filter === $k ? ' on' : ''; ?>"><?php echo esc_html( $l ); ?> <span class="cnt"><?php echo (int) $counts[ $k ]; ?></span></a>
			<?php endforeach; ?></div>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="m24fzv-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="st" value="<?php echo esc_attr( $filter ); ?>">
				<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Suche: Titel oder Inserat-ID">
				<select name="marke"><option value="">Marke: alle</option><?php foreach ( $marken as $m ) { printf( '<option value="%s"%s>%s</option>', esc_attr( $m ), selected( $marke, $m, false ), esc_html( $m ) ); } ?></select>
				<select name="baureihe"><option value="">Baureihe: alle</option><?php foreach ( $baur as $b ) { printf( '<option value="%s"%s>%s</option>', esc_attr( $b ), selected( $baureihe, $b, false ), esc_html( $b ) ); } ?></select>
				<select name="sort">
					<option value="neu"<?php selected( $sort, 'neu' ); ?>>Neueste zuerst</option>
					<option value="alt"<?php selected( $sort, 'alt' ); ?>>Älteste zuerst</option>
					<option value="preis-ab"<?php selected( $sort, 'preis-ab' ); ?>>Preis absteigend</option>
					<option value="preis-auf"<?php selected( $sort, 'preis-auf' ); ?>>Preis aufsteigend</option>
					<option value="aufrufe"<?php selected( $sort, 'aufrufe' ); ?>>Meiste Aufrufe</option>
				</select>
				<button class="button">Anwenden</button>
			</form>

			<table class="wp-list-table widefat fixed striped m24fzv-table" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
				<thead><tr><th style="width:34%">Fahrzeug</th><th style="width:18%">Status</th><th style="width:18%">Preis</th><th style="width:16%">Statistik</th><th style="width:14%">Aktionen</th></tr></thead>
				<tbody><?php
				if ( empty( $ids ) ) { echo '<tr><td colspan="5">Keine Inserate.</td></tr>'; }
				foreach ( $ids as $id ) { self::row( (int) $id ); }
				?></tbody>
			</table>
		</div>
		<?php
	}

	private static function row( $id ) {
		$is_trash = ( 'trash' === get_post_status( $id ) );
		$st     = $is_trash ? 'papierkorb' : M24FZ_CPT::status( $id );
		$labels = array( 'gelistet' => 'Gelistet', 'reserviert' => 'Reserviert', 'verkauft' => 'Verkauft', 'deaktiviert' => 'Deaktiviert', 'entwurf' => 'Entwurf', 'papierkorb' => 'Papierkorb' );
		$paf    = (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true );
		$preis  = (int) get_post_meta( $id, '_m24fz_preis', true );
		$disabled = ( 'deaktiviert' === $st );
		?>
		<tr data-id="<?php echo (int) $id; ?>">
			<td class="m24fzv-veh">
				<span class="thumb"><?php echo has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, array( 64, 43 ) ) : '<span class="ph">' . esc_html( self::abbr( $id ) ) . '</span>'; ?></span>
				<span class="meta"><?php if ( $is_trash ) : ?><span class="t"><?php echo esc_html( get_the_title( $id ) ); ?></span><?php else : ?><a class="t" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a><?php endif; ?>
				<span class="sub">#<?php echo (int) $id; ?> · <?php echo esc_html( get_post_field( 'post_name', $id ) ); ?></span></span>
			</td>
			<td class="m24fzv-statuscell"><span class="m24fzv-badge st-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $labels[ $st ] ?? $st ); ?></span><span class="m24fzv-online"><?php echo esc_html( $is_trash ? 'Im Papierkorb' : M24FZ_CPT::online_label( $id ) ); ?></span></td>
			<td class="m24fzv-price"><?php self::price_cell( $id, $paf, $preis ); ?></td>
			<td class="m24fzv-stats"><div class="m24fzv-statgrid"><?php printf(
				'<span class="stat"><b>👁 %s</b><i>Aufrufe</i></span><span class="stat"><b>♡ %s</b><i>Merkliste</i></span><span class="stat"><b>✉ %s</b><i>Anfragen</i></span>',
				esc_html( number_format_i18n( M24FZ_Tracking::get( $id, 'view' ) ) ),
				esc_html( number_format_i18n( M24FZ_Tracking::get( $id, 'merken' ) ) ),
				esc_html( number_format_i18n( M24FZ_Tracking::get( $id, 'anfrage' ) ) )
			); ?></div></td>
			<td class="m24fzv-actions">
				<details class="m24fzv-kebab"><summary>⋯ Aktionen</summary><div class="menu">
					<?php if ( $is_trash ) : ?>
						<a href="#" data-do="untrash">Wiederherstellen</a>
						<hr>
						<a href="#" class="danger" data-do="delete">Endgültig löschen</a>
					<?php else : ?>
						<a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank">Zum Inserat</a>
						<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Inserat bearbeiten</a>
						<a href="#" data-do="preis-edit">Preis bearbeiten</a>
						<a href="#" data-do="datum">Datum ändern</a>
						<a href="#" data-do="featured"><?php echo M24FZ_CPT::is_featured( $id ) ? '★ Von Startseite nehmen' : '☆ Auf Startseite (Slider)'; ?></a>
						<hr>
						<?php if ( in_array( $st, array( 'reserviert', 'verkauft' ), true ) ) : ?><a href="#" data-do="gelistet">Wieder gelistet</a><?php endif; ?>
						<a href="#" data-do="verkauft">Verkauft markieren</a>
						<a href="#" data-do="reserviert">Reserviert markieren</a>
						<?php if ( $disabled ) : ?><a href="#" data-do="reaktivieren">Wieder aktivieren</a>
						<?php else : ?><a href="#" data-do="deaktiviert">Inserat deaktivieren</a><?php endif; ?>
						<hr>
						<a href="#" class="danger" data-do="trash">Löschen → Papierkorb</a>
					<?php endif; ?>
				</div></details>
			</td>
		</tr>
		<?php
	}

	/** Kürzel für den dunklen Thumbnail-Platzhalter (Baureihe → sonst Marke → sonst „—"). */
	private static function abbr( $id ) {
		$b = trim( (string) get_post_meta( $id, '_m24fz_baureihe', true ) );
		if ( '' !== $b ) { return mb_substr( $b, 0, 7 ); }
		$m = trim( (string) get_post_meta( $id, '_m24fz_marke', true ) );
		return '' !== $m ? mb_substr( $m, 0, 4 ) : '—';
	}

	private static function price_cell( $id, $paf, $preis ) {
		$sold = M24FZ_CPT::is_sold( $id );
		$cur  = M24FZ_Telemetry::currency_symbol( get_post_meta( $id, '_m24fz_waehrung', true ) );
		if ( $paf ) {
			echo '<span class="val">Preis auf Anfrage</span>';
		} elseif ( $preis > 0 ) {
			printf( '<span class="val%s">%s&nbsp;%s</span>', $sold ? ' sold' : '', esc_html( number_format( $preis, 0, ',', '.' ) ), esc_html( $cur ) );
			$note = (int) get_post_meta( $id, '_m24fz_mwst_ausweisbar', true ) ? 'inkl. 19&nbsp;% MwSt.' : 'Differenzbest. §25a';
			echo '<span class="mwst">' . $note . '</span>'; // phpcs:ignore
		} else {
			echo '<span class="val muted">—</span>';
		}
		echo '<a href="#" class="m24fzv-price-edit" data-do="preis-edit">bearbeiten</a>';
	}

	/* ── AJAX ────────────────────────────────────────────────────────────────── */

	public static function ajax() {
		if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( array( 'message' => 'keine Berechtigung' ) ); }
		check_ajax_referer( self::NONCE, '_nonce' );
		$id   = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		if ( ! $id || M24FZ_CPT::PT !== get_post_type( $id ) ) { wp_send_json_error( array( 'message' => 'ungültig' ) ); }
		$labels = array( 'gelistet' => 'Gelistet', 'reserviert' => 'Reserviert', 'verkauft' => 'Verkauft', 'deaktiviert' => 'Deaktiviert', 'entwurf' => 'Entwurf' );

		switch ( $what ) {
			case 'preis':
				update_post_meta( $id, '_m24fz_preis', (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST['preis'] ?? '' ) ) );
				wp_send_json_success( array( 'priceHtml' => self::price_html_for( $id ) ) );
				break;
			case 'featured':
				$new = M24FZ_CPT::is_featured( $id ) ? '' : '1';
				if ( '' === $new ) { delete_post_meta( $id, '_m24_featured' ); } else { update_post_meta( $id, '_m24_featured', '1' ); }
				wp_send_json_success( array( 'featured' => ( '1' === $new ), 'label' => ( '1' === $new ) ? '★ Von Startseite nehmen' : '☆ Auf Startseite (Slider)' ) );
				break;
			case 'datum':
				$raw = (string) wp_unslash( $_POST['datum'] ?? '' );
				if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}))?/', $raw, $m ) ) { wp_send_json_error( array( 'message' => 'Datum ungültig' ) ); }
				$mysql = $m[1] . ' ' . ( $m[2] ?? '12:00' ) . ':00';
				// post_date frei (auch Zukunft) OHNE „scheduled": WP würde publish+Zukunft → 'future'
				// kippen. Per wp_insert_post_data-Filter den Status hart auf dem Ist-Wert halten (§4).
				$keep = get_post_status( $id );
				if ( 'future' === $keep ) { $keep = 'publish'; }
				$force = static function ( $data ) use ( $keep ) {
					if ( 'future' === $data['post_status'] ) { $data['post_status'] = $keep; }
					return $data;
				};
				add_filter( 'wp_insert_post_data', $force, 99 );
				wp_update_post( array( 'ID' => $id, 'post_date' => $mysql, 'post_date_gmt' => get_gmt_from_date( $mysql ), 'post_status' => $keep, 'edit_date' => true ) );
				remove_filter( 'wp_insert_post_data', $force, 99 );
				wp_send_json_success( array( 'online' => M24FZ_CPT::online_label( $id ), 'date' => get_post_field( 'post_date', $id ) ) );
				break;
			case 'gelistet':
			case 'verkauft':
			case 'reserviert':
			case 'deaktiviert':
				M24FZ_CPT::set_status( $id, $what );
				wp_send_json_success( array( 'status' => $what, 'label' => $labels[ $what ], 'online' => M24FZ_CPT::online_label( $id ), 'disabled' => ( 'deaktiviert' === $what ), 'counts' => self::counts() ) );
				break;
			case 'reaktivieren':
				M24FZ_CPT::reactivate( $id );
				$st = M24FZ_CPT::status( $id );
				wp_send_json_success( array( 'status' => $st, 'label' => $labels[ $st ] ?? $st, 'online' => M24FZ_CPT::online_label( $id ), 'disabled' => false, 'counts' => self::counts() ) );
				break;
			case 'trash':
				wp_trash_post( $id ); // Papierkorb, kein Hard-Delete (§0)
				wp_send_json_success( array( 'trashed' => true, 'counts' => self::counts() ) );
				break;
			case 'untrash':
				wp_untrash_post( $id ); // zurück in den vorherigen Status
				wp_send_json_success( array( 'untrashed' => true, 'counts' => self::counts() ) );
				break;
			case 'delete':
				wp_delete_post( $id, true ); // endgültig (destruktiv, JS bestätigt)
				wp_send_json_success( array( 'deleted' => true, 'counts' => self::counts() ) );
				break;
		}
		wp_send_json_error( array( 'message' => 'unbekannte Aktion' ) );
	}

	private static function price_html_for( $id ) {
		ob_start();
		self::price_cell( $id, (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true ), (int) get_post_meta( $id, '_m24fz_preis', true ) );
		return ob_get_clean();
	}

	/* ── Inline CSS/JS ───────────────────────────────────────────────────────── */

	private static function css() {
		return <<<CSS
/* Scoped Cockpit-Optik (nur .m24fzv) — Saira, M24-Palette, kein globaler CI-Eingriff. */
.m24fzv{--ink:#14161a;--blue:#1763ad;--brass:#9a6b25;--red:#9e2b2b;--line:#e6e6e3;--mut:#787c82;font-family:'Saira',-apple-system,Segoe UI,sans-serif;max-width:1240px}
.m24fzv *{box-sizing:border-box}
.m24fzv-bc{color:var(--mut);font-size:12px;margin:6px 0 4px}.m24fzv-bc span{margin:0 4px;color:#c4c4c0}
.m24fzv-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:14px}
.m24fzv-head h1{font-family:'Saira',sans-serif;font-size:26px;font-weight:800;margin:0;display:flex;align-items:center;gap:10px;padding:0}
.m24fzv-pill{font-size:12px;font-weight:700;color:var(--brass);background:#f6efe3;border:1px solid #ecdcc2;border-radius:999px;padding:3px 11px}
.m24fzv-sub{color:var(--mut);font-size:13px;margin:6px 0 18px}
.m24fzv-newbtn{align-self:center;text-decoration:none;color:#fff;font-weight:700;border-radius:8px;padding:10px 18px;background:linear-gradient(135deg,#1f74c4,#0e447e);white-space:nowrap}
.m24fzv-newbtn:hover{color:#fff;opacity:.94}
/* Tab-Leiste mit Count-Pills + Messing-Unterstrich */
.m24fzv-tabs{display:flex;flex-wrap:wrap;gap:2px;border-bottom:1px solid var(--line);margin:18px 0}
.m24fzv-tab{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;text-decoration:none;color:#50575e;font-weight:600;font-size:13px;border-bottom:3px solid transparent;margin-bottom:-1px}
.m24fzv-tab .cnt{background:#eef0f2;color:#50575e;border-radius:999px;font-size:11px;padding:1px 8px}
.m24fzv-tab:hover{color:var(--ink)}
.m24fzv-tab.on{color:var(--ink);border-bottom-color:var(--brass)}
.m24fzv-tab.on .cnt{background:var(--brass);color:#fff}
/* Toolbar */
.m24fzv-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 18px}
.m24fzv-toolbar input[type=search]{min-width:260px;height:40px;border-radius:8px;border:1px solid #d9d9d6;padding:8px 14px}
.m24fzv-toolbar select{height:40px;border-radius:8px;border:1px solid #d9d9d6;padding:7px 12px;background:#fff}
.m24fzv-toolbar .button{height:40px;border-radius:8px}
/* Karten-Tabelle — luftiger (höhere Spezifität gegen WP-Core .widefat/.wp-list-table radius:0) */
.m24fzv table.m24fzv-table{border:1px solid var(--line);border-radius:12px!important;overflow:hidden;background:#fff;border-collapse:separate;border-spacing:0;margin-top:6px}
.m24fzv-table thead th{background:#fafafa;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7077;padding:12px 14px}
.m24fzv-table tbody td{padding:16px 14px;vertical-align:middle}
.m24fzv-veh{display:flex;gap:12px;align-items:center}
.m24fzv-veh .thumb img,.m24fzv-veh .thumb .ph{width:64px;height:43px;object-fit:cover;border-radius:6px;display:flex;align-items:center;justify-content:center}
.m24fzv-veh .thumb .ph{background:#1e2228;color:#cfae7e;font-size:11px;font-weight:700;text-align:center;line-height:1.1;padding:2px}
.m24fzv-veh .t{font-weight:600;text-decoration:none;color:var(--ink);font-size:14px}
.m24fzv-veh .sub{display:block;color:var(--mut);font-size:12px;margin-top:2px}
/* Status-Badges mit Punkt */
.m24fzv-badge{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
.m24fzv-badge:before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.9}
.m24fzv-badge.st-gelistet{background:#e6f4ea;color:#1a7f37}.m24fzv-badge.st-verkauft{background:#fbe6e6;color:#9e2b2b}
.m24fzv-badge.st-reserviert{background:#f6efe3;color:#9a6b25}.m24fzv-badge.st-deaktiviert{background:#eee;color:#666}
.m24fzv-badge.st-entwurf{background:#e7eaf0;color:#3a4252}.m24fzv-badge.st-papierkorb{background:#f3e0e0;color:#8a3a3a}
.m24fzv-online{display:block;color:var(--mut);font-size:12px;margin-top:5px}
/* Preis */
.m24fzv-price .val{font-weight:700;color:var(--brass);font-size:15px}.m24fzv-price .val.sold{text-decoration:line-through;color:var(--red)}.m24fzv-price .val.muted{color:#9aa0a6}
.m24fzv-price .mwst{display:block;color:var(--mut);font-size:11px;margin-top:1px}
.m24fzv-price-edit{display:inline-block;font-size:12px;margin-top:4px;color:var(--blue);text-decoration:none}
.m24fzv-pin,.m24fzv-din{border-radius:6px;border:1px solid #d9d9d6;padding:5px 8px}
.m24fzv-price-save,.m24fzv-date-save{color:#1a7f37;font-weight:800;text-decoration:none;font-size:15px}
.m24fzv-price-cancel,.m24fzv-date-cancel{color:#9e2b2b;text-decoration:none;font-size:14px}
/* Statistik — Icon + fette Zahl + Label, 2-spaltiges Raster */
.m24fzv-statgrid{display:grid;grid-template-columns:1fr 1fr;gap:6px 14px}
.m24fzv-statgrid .stat{display:flex;align-items:baseline;gap:5px;white-space:nowrap}
.m24fzv-statgrid .stat b{font-size:14px;font-weight:700;color:var(--ink)}
.m24fzv-statgrid .stat i{font-style:normal;font-size:11px;color:var(--mut)}
/* Kebab */
.m24fzv-kebab{position:relative;display:inline-block}
.m24fzv-kebab summary{cursor:pointer;list-style:none;color:var(--blue);font-weight:600;font-size:13px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;background:#fff}
.m24fzv-kebab summary::-webkit-details-marker{display:none}
.m24fzv-kebab[open] summary{background:#f6f7f8}
.m24fzv-kebab .menu{position:absolute;right:0;z-index:20;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 8px 22px rgba(0,0,0,.16);min-width:220px;padding:6px;margin-top:4px}
.m24fzv-kebab .menu a{display:block;padding:8px 11px;text-decoration:none;color:#1d2327;border-radius:6px;font-size:13px}
.m24fzv-kebab .menu a:hover{background:#f0f0f1}
.m24fzv-kebab .menu a.danger{color:var(--red)}
.m24fzv-kebab .menu hr{margin:5px 0;border:0;border-top:1px solid #ececec}
CSS;
	}

	private static function js() {
		return <<<JS
jQuery(function($){
	var nonce=$('.m24fzv-table').data('nonce');
	function post(id,what,extra,cb){ $.post(ajaxurl,$.extend({action:'m24fz_action',_nonce:nonce,post_id:id,what:what},extra||{}),function(r){
		if(r&&r.success){ cb&&cb(r.data); } else { alert((r&&r.data&&r.data.message)||'Fehler'); }
	}); }
	function closeKebab(tr){ tr.find('.m24fzv-kebab').removeAttr('open'); }
	function updateCounts(c){ if(!c){ return; } for(var k in c){ $('.m24fzv-tab[data-tab="'+k+'"] .cnt').text(c[k]); } }

	// Delegation auf JEDES [data-do] (auch Inline-Save/Cancel in Preis-/Status-Spalte).
	$(document).on('click','[data-do]',function(e){
		var doIt=$(this).data('do'); if(!doIt){ return; } e.preventDefault();
		var tr=$(this).closest('tr'), id=tr.data('id'); if(!id){ return; }

		// ── Inline-Preis ──
		if(doIt==='preis-edit'){
			var cell=tr.find('.m24fzv-price'); cell.data('orig',cell.html());
			var cur=(cell.find('.val').text()||'').replace(/[^0-9]/g,'');
			cell.html('<input type="text" class="m24fzv-pin" value="'+cur+'" style="width:96px"> <a href="#" class="m24fzv-price-save" data-do="preis-save" title="Speichern">✓</a> <a href="#" class="m24fzv-price-cancel" data-do="preis-cancel" title="Abbrechen">✕</a>');
			cell.find('.m24fzv-pin').trigger('focus'); closeKebab(tr); return;
		}
		if(doIt==='preis-save'){ var v=tr.find('.m24fzv-pin').val(); post(id,'preis',{preis:v},function(d){ tr.find('.m24fzv-price').html(d.priceHtml).removeData('orig'); }); return; }
		if(doIt==='preis-cancel'){ var c=tr.find('.m24fzv-price'); if(c.data('orig')!==undefined){ c.html(c.data('orig')).removeData('orig'); } return; }

		// ── Inline-Datum (ersetzt prompt) ──
		if(doIt==='datum'){
			var sc=tr.find('.m24fzv-statuscell'); if(sc.data('orig')===undefined){ sc.data('orig',sc.html()); }
			sc.html('<input type="datetime-local" class="m24fzv-din"> <a href="#" class="m24fzv-date-save" data-do="datum-save" title="Speichern">✓</a> <a href="#" class="m24fzv-date-cancel" data-do="datum-cancel" title="Abbrechen">✕</a>');
			sc.find('.m24fzv-din').trigger('focus'); closeKebab(tr); return;
		}
		if(doIt==='datum-save'){ var dv=tr.find('.m24fzv-din').val(); if(!dv){ return; } post(id,'datum',{datum:dv},function(d){ var sc=tr.find('.m24fzv-statuscell'); if(sc.data('orig')!==undefined){ sc.html(sc.data('orig')).removeData('orig'); } tr.find('.m24fzv-online').text(d.online); }); return; }
		if(doIt==='datum-cancel'){ var s=tr.find('.m24fzv-statuscell'); if(s.data('orig')!==undefined){ s.html(s.data('orig')).removeData('orig'); } return; }

		// ── Featured (Startseiten-Slider) ──
		if(doIt==='featured'){ var lnk=$(this); post(id,'featured',{},function(d){ lnk.text(d.label); }); return; }

		// ── Papierkorb ──
		if(doIt==='trash'){ if(!confirm('Inserat in den Papierkorb verschieben? (wiederherstellbar)')){return;} post(id,'trash',{},function(d){ updateCounts(d.counts); tr.fadeOut(200,function(){ $(this).remove(); }); }); return; }
		if(doIt==='untrash'){ post(id,'untrash',{},function(d){ updateCounts(d.counts); tr.fadeOut(200,function(){ $(this).remove(); }); }); return; }
		if(doIt==='delete'){ if(!confirm('Inserat ENDGÜLTIG löschen? Das kann nicht rückgängig gemacht werden.')){return;} post(id,'delete',{},function(d){ updateCounts(d.counts); tr.fadeOut(200,function(){ $(this).remove(); }); }); return; }

		// ── Statuswechsel (inkl. „Wieder gelistet") ──
		if(doIt==='gelistet'||doIt==='verkauft'||doIt==='reserviert'||doIt==='deaktiviert'||doIt==='reaktivieren'){
			post(id,doIt,{},function(d){
				if(d.status){ tr.find('.m24fzv-badge').attr('class','m24fzv-badge st-'+d.status).text(d.label); }
				if(d.online!==undefined){ tr.find('.m24fzv-online').text(d.online); }
				updateCounts(d.counts);
				var menu=tr.find('.m24fzv-kebab .menu');
				// deaktivieren ↔ reaktivieren
				menu.find('[data-do=deaktiviert],[data-do=reaktivieren]').remove();
				menu.find('[data-do=reserviert]').after(d.disabled?'<a href="#" data-do="reaktivieren">Wieder aktivieren</a>':'<a href="#" data-do="deaktiviert">Inserat deaktivieren</a>');
				// „Wieder gelistet" nur bei reserviert/verkauft
				menu.find('[data-do=gelistet]').remove();
				if(d.status==='reserviert'||d.status==='verkauft'){ menu.find('[data-do=verkauft]').before('<a href="#" data-do="gelistet">Wieder gelistet</a>'); }
				closeKebab(tr);
			});
		}
	});

	// Enter = Speichern, Escape = Abbrechen in den Inline-Feldern.
	$(document).on('keydown','.m24fzv-pin,.m24fzv-din',function(e){
		var tr=$(this).closest('tr');
		if(e.key==='Enter'){ e.preventDefault(); tr.find($(this).hasClass('m24fzv-pin')?'[data-do=preis-save]':'[data-do=datum-save]').trigger('click'); }
		else if(e.key==='Escape'){ e.preventDefault(); tr.find($(this).hasClass('m24fzv-pin')?'[data-do=preis-cancel]':'[data-do=datum-cancel]').trigger('click'); }
	});
});
JS;
	}
}
