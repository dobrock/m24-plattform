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

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_m24fz_action', array( __CLASS__, 'ajax' ) );
	}

	public static function menu() {
		// Direkt unter dem Dach „MOTORSPORT24" registriert → sauberer Page-Hook
		// (admin.php?page=m24fz-verwaltung), eine einzige Registrierung, manage_options.
		add_submenu_page(
			'm24-plattform',
			'Inserat-Verwaltung', 'Inserat-Verwaltung', self::CAP, self::PAGE, array( __CLASS__, 'render' )
		);
	}

	/* ── Status-Zähler (Status-Modell §2) ────────────────────────────────────── */

	private static function counts() {
		$ids = get_posts( array( 'post_type' => M24FZ_CPT::PT, 'post_status' => array( 'publish', 'private', 'draft', 'pending' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		$c = array( 'alle' => 0, 'gelistet' => 0, 'reserviert' => 0, 'verkauft' => 0, 'deaktiviert' => 0, 'entwurf' => 0 );
		foreach ( $ids as $pid ) {
			$st = M24FZ_CPT::status( $pid );
			if ( isset( $c[ $st ] ) ) { $c[ $st ]++; }
			$c['alle']++;
		}
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

		$labels = array( 'alle' => 'Alle', 'gelistet' => 'Gelistet', 'reserviert' => 'Reserviert', 'verkauft' => 'Verkauft', 'deaktiviert' => 'Deaktiviert', 'entwurf' => 'Entwurf' );
		$marken = self::distinct_meta( '_m24fz_marke' );
		$baur   = self::distinct_meta( '_m24fz_baureihe' );
		$base   = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<style><?php echo self::css(); // phpcs:ignore ?></style>
		<div class="wrap m24fzv">
			<h1>Inserat-Verwaltung</h1>

			<ul class="subsubsub m24fzv-tabs"><?php $i = 0; foreach ( $labels as $k => $l ) : $i++; ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'st', $k, $base ) ); ?>" class="<?php echo $filter === $k ? 'current' : ''; ?>"><?php echo esc_html( $l ); ?> <span class="count">(<?php echo (int) $counts[ $k ]; ?>)</span></a><?php echo $i < count( $labels ) ? ' |' : ''; ?></li>
			<?php endforeach; ?></ul>

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
		<script><?php echo self::js(); // phpcs:ignore ?></script>
		<?php
	}

	private static function row( $id ) {
		$st     = M24FZ_CPT::status( $id );
		$labels = array( 'gelistet' => 'Gelistet', 'reserviert' => 'Reserviert', 'verkauft' => 'Verkauft', 'deaktiviert' => 'Deaktiviert', 'entwurf' => 'Entwurf' );
		$paf    = (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true );
		$preis  = (int) get_post_meta( $id, '_m24fz_preis', true );
		$disabled = ( 'deaktiviert' === $st );
		?>
		<tr data-id="<?php echo (int) $id; ?>">
			<td class="m24fzv-veh">
				<span class="thumb"><?php echo has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, array( 64, 43 ) ) : '<span class="ph"></span>'; ?></span>
				<span class="meta"><a class="t" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
				<span class="sub">#<?php echo (int) $id; ?> · <?php echo esc_html( get_post_field( 'post_name', $id ) ); ?></span></span>
			</td>
			<td><span class="m24fzv-badge st-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $labels[ $st ] ?? $st ); ?></span><span class="m24fzv-online"><?php echo esc_html( M24FZ_CPT::online_label( $id ) ); ?></span></td>
			<td class="m24fzv-price"><?php self::price_cell( $id, $paf, $preis ); ?></td>
			<td class="m24fzv-stats"><?php printf( '<span title="Aufrufe">👁 %d</span> <span title="Merkliste">♡ %d</span> <span title="Anfragen">✉ %d</span>',
				M24FZ_Tracking::get( $id, 'view' ), M24FZ_Tracking::get( $id, 'merken' ), M24FZ_Tracking::get( $id, 'anfrage' ) ); ?></td>
			<td class="m24fzv-actions">
				<details class="m24fzv-kebab"><summary>⋯ Aktionen</summary><div class="menu">
					<a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank">Zum Inserat</a>
					<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Inserat bearbeiten</a>
					<a href="#" data-do="preis-edit">Preis bearbeiten</a>
					<a href="#" data-do="datum">Datum ändern</a>
					<hr>
					<a href="#" data-do="verkauft">Verkauft markieren</a>
					<a href="#" data-do="reserviert">Reserviert markieren</a>
					<?php if ( $disabled ) : ?><a href="#" data-do="reaktivieren">Wieder aktivieren</a>
					<?php else : ?><a href="#" data-do="deaktiviert">Inserat deaktivieren</a><?php endif; ?>
					<hr>
					<a href="#" class="danger" data-do="trash">Löschen → Papierkorb</a>
				</div></details>
			</td>
		</tr>
		<?php
	}

	private static function price_cell( $id, $paf, $preis ) {
		$sold = M24FZ_CPT::is_sold( $id );
		$cur  = M24FZ_Telemetry::currency_symbol( get_post_meta( $id, '_m24fz_waehrung', true ) );
		if ( $paf ) { echo '<span class="val">Preis auf Anfrage</span>'; }
		elseif ( $preis > 0 ) { printf( '<span class="val%s">%s&nbsp;%s</span>', $sold ? ' sold' : '', esc_html( number_format( $preis, 0, ',', '.' ) ), esc_html( $cur ) ); }
		else { echo '<span class="val muted">—</span>'; }
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
			case 'verkauft':
			case 'reserviert':
			case 'deaktiviert':
				M24FZ_CPT::set_status( $id, $what );
				wp_send_json_success( array( 'status' => $what, 'label' => $labels[ $what ], 'online' => M24FZ_CPT::online_label( $id ), 'disabled' => ( 'deaktiviert' === $what ) ) );
				break;
			case 'reaktivieren':
				M24FZ_CPT::reactivate( $id );
				$st = M24FZ_CPT::status( $id );
				wp_send_json_success( array( 'status' => $st, 'label' => $labels[ $st ] ?? $st, 'online' => M24FZ_CPT::online_label( $id ), 'disabled' => false ) );
				break;
			case 'trash':
				wp_trash_post( $id ); // Papierkorb, kein Hard-Delete (§0)
				wp_send_json_success( array( 'trashed' => true, 'undo' => admin_url( 'edit.php?post_type=' . M24FZ_CPT::PT ) ) );
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
.m24fzv-tabs .current{font-weight:700;border-bottom:2px solid #9a6b25}
.m24fzv-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}
.m24fzv-toolbar input[type=search]{min-width:240px}
.m24fzv-veh{display:flex;gap:10px;align-items:center}
.m24fzv-veh .thumb img,.m24fzv-veh .thumb .ph{width:64px;height:43px;object-fit:cover;border-radius:4px;display:block;background:#eee}
.m24fzv-veh .t{font-weight:600;text-decoration:none}
.m24fzv-veh .sub{display:block;color:#787c82;font-size:12px}
.m24fzv-badge{display:inline-block;padding:2px 9px;border-radius:4px;font-size:12px;font-weight:700}
.m24fzv-badge.st-gelistet{background:#e6f4ea;color:#1a7f37}.m24fzv-badge.st-verkauft{background:#fbe6e6;color:#9e2b2b}
.m24fzv-badge.st-reserviert{background:#fff4d6;color:#9a6b25}.m24fzv-badge.st-deaktiviert{background:#eee;color:#666}.m24fzv-badge.st-entwurf{background:#e7eaf0;color:#3a4252}
.m24fzv-online{display:block;color:#787c82;font-size:12px;margin-top:3px}
.m24fzv-price .val{font-weight:600}.m24fzv-price .val.sold{text-decoration:line-through;color:#9e2b2b}.m24fzv-price .val.muted{color:#9aa0a6}
.m24fzv-price-edit{display:block;font-size:12px;margin-top:2px}
.m24fzv-stats span{margin-right:8px;font-size:13px;color:#50575e;white-space:nowrap}
.m24fzv-kebab{position:relative;display:inline-block}
.m24fzv-kebab summary{cursor:pointer;list-style:none;color:#1763ad;font-weight:600}
.m24fzv-kebab summary::-webkit-details-marker{display:none}
.m24fzv-kebab .menu{position:absolute;right:0;z-index:10;background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.14);min-width:210px;padding:6px}
.m24fzv-kebab .menu a{display:block;padding:7px 10px;text-decoration:none;color:#1d2327;border-radius:5px}
.m24fzv-kebab .menu a:hover{background:#f0f0f1}
.m24fzv-kebab .menu a.danger{color:#9e2b2b}
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
	$(document).on('click','.m24fzv-actions a, .m24fzv-price-edit',function(e){
		var doIt=$(this).data('do'); if(!doIt){ return; } e.preventDefault();
		var tr=$(this).closest('tr'), id=tr.data('id');
		if(doIt==='preis-edit'){
			var cell=tr.find('.m24fzv-price'); var cur=cell.find('.val').text().replace(/[^0-9]/g,'');
			cell.html('<input type="text" class="small-text m24fzv-pin" value="'+cur+'" style="width:90px"> <a href="#" data-do="preis-save">✓</a>');
			cell.find('.m24fzv-pin').focus(); return;
		}
		if(doIt==='preis-save'){ var v=tr.find('.m24fzv-pin').val(); post(id,'preis',{preis:v},function(d){ tr.find('.m24fzv-price').html(d.priceHtml); }); return; }
		if(doIt==='datum'){ var d=prompt('Veröffentlichungsdatum (JJJJ-MM-TT oder JJJJ-MM-TTThh:mm):'); if(!d){return;} post(id,'datum',{datum:d},function(r){ tr.find('.m24fzv-online').text(r.online); }); return; }
		if(doIt==='trash'){ if(!confirm('Inserat in den Papierkorb verschieben? (wiederherstellbar)')){return;} post(id,'trash',{},function(r){ tr.fadeOut(200,function(){ $(this).remove(); }); }); return; }
		// Statuswechsel
		post(id,doIt,{},function(d){
			if(d.status){ tr.find('.m24fzv-badge').attr('class','m24fzv-badge st-'+d.status).text(d.label); }
			if(d.online!==undefined){ tr.find('.m24fzv-online').text(d.online); }
			// Aktion „deaktivieren"↔„reaktivieren" im Menü tauschen
			var menu=tr.find('.m24fzv-kebab .menu');
			menu.find('[data-do=deaktiviert],[data-do=reaktivieren]').remove();
			var ins=d.disabled?'<a href="#" data-do="reaktivieren">Wieder aktivieren</a>':'<a href="#" data-do="deaktiviert">Inserat deaktivieren</a>';
			menu.find('[data-do=reserviert]').after(ins);
			tr.find('.m24fzv-kebab').removeAttr('open');
		});
	});
});
JS;
	}
}
