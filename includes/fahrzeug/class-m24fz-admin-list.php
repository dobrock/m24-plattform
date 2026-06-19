<?php
/**
 * M24 Fahrzeug — Inserat-Verwaltung (Elferspot-Stil)
 * Modul: includes/fahrzeug/class-m24fz-admin-list.php
 *
 * Eigene Admin-Seite: Tabs/Filter nach Status (mit Zählern), Tabelle, Inline-Aktionen
 * (Preis bearbeiten, Verkauft/Reserviert/Deaktivieren) per AJAX (Nonce + Cap), Statistik je Inserat.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Admin_List {

	const NONCE = 'm24fz_admin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_m24fz_action', array( __CLASS__, 'ajax' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . M24FZ_CPT::PT, 'Inserat-Verwaltung', 'Inserat-Verwaltung', 'edit_posts', 'm24fz-verwaltung', array( __CLASS__, 'render' ) );
	}

	private static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.meta_value AS st, COUNT(*) AS n FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID=pm.post_id AND p.post_type=%s AND p.post_status='publish'
			 WHERE pm.meta_key='_m24fz_status' GROUP BY pm.meta_value", M24FZ_CPT::PT ) ); // phpcs:ignore WordPress.DB
		$c = array( 'alle' => 0, 'gelistet' => 0, 'verkauft' => 0, 'reserviert' => 0, 'deaktiviert' => 0, 'entwurf' => 0 );
		foreach ( (array) $rows as $r ) { if ( isset( $c[ $r->st ] ) ) { $c[ $r->st ] = (int) $r->n; $c['alle'] += (int) $r->n; } }
		$c['entwurf'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status='draft'", M24FZ_CPT::PT ) ); // phpcs:ignore WordPress.DB
		return $c;
	}

	public static function render() {
		$counts = self::counts();
		$filter = isset( $_GET['st'] ) ? sanitize_key( wp_unslash( $_GET['st'] ) ) : 'alle';
		$args   = array( 'post_type' => M24FZ_CPT::PT, 'posts_per_page' => 50, 'post_status' => array( 'publish', 'draft' ), 'orderby' => 'date', 'order' => 'DESC' );
		if ( 'entwurf' === $filter ) { $args['post_status'] = 'draft'; }
		elseif ( 'alle' !== $filter ) { $args['post_status'] = 'publish'; $args['meta_query'] = array( array( 'key' => '_m24fz_status', 'value' => $filter ) ); }
		$q = new WP_Query( $args );
		$labels = array( 'alle' => 'Alle', 'gelistet' => 'Gelistet', 'verkauft' => 'Verkauft', 'reserviert' => 'Reserviert', 'deaktiviert' => 'Deaktiviert', 'entwurf' => 'Entwurf' );
		?>
		<div class="wrap"><h1>Inserat-Verwaltung</h1>
		<ul class="subsubsub"><?php $i = 0; foreach ( $labels as $k => $l ) : $i++; ?>
			<li><a href="<?php echo esc_url( add_query_arg( 'st', $k ) ); ?>" class="<?php echo $filter === $k ? 'current' : ''; ?>"><?php echo esc_html( $l ); ?> <span class="count">(<?php echo (int) $counts[ $k ]; ?>)</span></a><?php echo $i < count( $labels ) ? ' |' : ''; ?></li>
		<?php endforeach; ?></ul>
		<table class="wp-list-table widefat fixed striped" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<thead><tr><th>Bild</th><th>Titel</th><th>Karosserie · Baujahr</th><th>Status</th><th>Laufleistung</th><th>Preis</th><th>Statistik</th><th>Aktionen</th></tr></thead>
			<tbody><?php while ( $q->have_posts() ) : $q->the_post(); $id = get_the_ID(); $st = M24FZ_CPT::status( $id ); ?>
				<tr data-id="<?php echo (int) $id; ?>">
					<td><?php echo has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, array( 60, 40 ) ) : '—'; ?></td>
					<td><strong><?php echo esc_html( get_the_title() ); ?></strong></td>
					<td><?php echo esc_html( trim( get_post_meta( $id, '_m24fz_karosserie', true ) . ' · ' . get_post_meta( $id, '_m24fz_baujahr', true ), ' ·' ) ); ?></td>
					<td><span class="m24fz-st m24fz-st-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $labels[ $st ] ?? $st ); ?></span></td>
					<td><?php echo esc_html( M24FZ_Telemetry::laufleistung( get_post_meta( $id, '_m24fz_laufleistung', true ) ) ); ?></td>
					<td><input type="text" class="m24fz-preis-in small-text" value="<?php echo esc_attr( get_post_meta( $id, '_m24fz_preis', true ) ); ?>" style="width:90px"> <a href="#" class="m24fz-do" data-do="preis">€</a></td>
					<td style="font-size:11px;color:#666"><?php printf( 'A %d · M %d · An %d · T %d', M24FZ_Tracking::get( $id, 'view' ), M24FZ_Tracking::get( $id, 'merken' ), M24FZ_Tracking::get( $id, 'anfrage' ), M24FZ_Tracking::get( $id, 'tel' ) ); ?></td>
					<td>
						<a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank">Inserat</a> ·
						<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Bearbeiten</a> ·
						<a href="#" class="m24fz-do" data-do="verkauft">Verkauft</a> ·
						<a href="#" class="m24fz-do" data-do="reserviert">Reserviert</a> ·
						<a href="#" class="m24fz-do" data-do="deaktiviert" style="color:#a00">Deaktivieren</a>
					</td>
				</tr>
			<?php endwhile; wp_reset_postdata(); ?></tbody>
		</table></div>
		<script>
		jQuery(function($){
			var nonce=$('.wp-list-table').data('nonce');
			$('.m24fz-do').on('click',function(e){ e.preventDefault();
				var tr=$(this).closest('tr'), id=tr.data('id'), what=$(this).data('do');
				var preis = what==='preis' ? tr.find('.m24fz-preis-in').val() : '';
				$.post(ajaxurl,{action:'m24fz_action',_nonce:nonce,post_id:id,what:what,preis:preis},function(r){
					if(r&&r.success){ if(r.data.status){ tr.find('.m24fz-st').attr('class','m24fz-st m24fz-st-'+r.data.status).text(r.data.label); } }
					else { alert((r&&r.data&&r.data.message)||'Fehler'); }
				});
			});
		});
		</script>
		<style>.m24fz-st{padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600}.m24fz-st-gelistet{background:#e6f4ea;color:#1a7f37}.m24fz-st-verkauft{background:#fbe6e6;color:#9e2b2b}.m24fz-st-reserviert{background:#fff4d6;color:#9a6b25}.m24fz-st-deaktiviert{background:#eee;color:#666}</style>
		<?php
	}

	public static function ajax() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( array( 'message' => 'keine Berechtigung' ) ); }
		check_ajax_referer( self::NONCE, '_nonce' );
		$id   = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		if ( ! $id || M24FZ_CPT::PT !== get_post_type( $id ) ) { wp_send_json_error( array( 'message' => 'ungültig' ) ); }
		$labels = array( 'gelistet' => 'Gelistet', 'verkauft' => 'Verkauft', 'reserviert' => 'Reserviert', 'deaktiviert' => 'Deaktiviert' );

		if ( 'preis' === $what ) {
			update_post_meta( $id, '_m24fz_preis', (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST['preis'] ?? '' ) ) );
			wp_send_json_success( array( 'ok' => true ) );
		}
		if ( ! isset( $labels[ $what ] ) ) { wp_send_json_error( array( 'message' => 'unbekannte Aktion' ) ); }
		update_post_meta( $id, '_m24fz_status', $what );
		// Verkauft → Kategorie-Flip.
		if ( 'verkauft' === $what ) {
			$kat = (string) get_post_meta( $id, '_m24fz_kat', true );
			if ( isset( M24FZ_CPT::SOLD_MAP[ $kat ] ) ) { wp_set_object_terms( $id, M24FZ_CPT::SOLD_MAP[ $kat ], M24FZ_CPT::TAX, false ); }
		}
		wp_send_json_success( array( 'status' => $what, 'label' => $labels[ $what ] ) );
	}
}
