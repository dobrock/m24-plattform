<?php
/**
 * M24 Plattform — Admin-Seite „Interessenten"
 *
 * Zentrale Übersicht aller Alert-/IL-Anmeldungen aus den Spiegel-Tabellen
 * (wp_m24_il_interessenten + wp_m24_il_interessenten_tags) plus offene DOI-Pending-Einträge.
 * Reine DB-Ansicht — KEIN Brevo-Call beim Laden. Brevo wird nur bei Abmelden/Löschen berührt
 * (über die bestehenden M24_Brevo_IL::mark_unsubscribed()/hard_delete()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Interessenten_Page {

	const PAGE_SLUG  = 'm24-interessenten';
	const CAPABILITY = 'manage_options';
	const PER_PAGE   = 30;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_m24_il_unsub',  array( __CLASS__, 'handle_unsub' ) );
		add_action( 'admin_post_m24_il_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_m24_il_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'm24-plattform',
			__( 'Interessenten', 'm24-plattform' ),
			__( 'Interessenten', 'm24-plattform' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function assets( $hook ) {
		if ( is_string( $hook ) && false !== strpos( $hook, self::PAGE_SLUG ) ) {
			wp_enqueue_style( 'm24fz-saira', plugins_url( 'assets/fonts/saira.css', M24_PLATTFORM_FILE ), array(), null );
		}
	}

	/* ── Filter-Argumente aus dem Request ────────────────────────────────── */

	private static function request_args() {
		return array(
			'vehicle'   => isset( $_REQUEST['f_vehicle'] ) ? (int) $_REQUEST['f_vehicle'] : 0,
			'tag'       => isset( $_REQUEST['f_tag'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['f_tag'] ) ) : '',
			'status'    => isset( $_REQUEST['f_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['f_status'] ) ) : '',
			'kundentyp' => isset( $_REQUEST['f_kundentyp'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['f_kundentyp'] ) ) : '',
			's'         => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);
	}

	/* ── Datenbeschaffung (Spiegel-Tabelle + Pending), gefiltert ─────────── */

	/**
	 * Alle Zeilen (eine je E-Mail) inkl. aggregierter Tags, gefiltert nach $a.
	 * Bestätigte (aktiv/abgemeldet) aus der Tabelle; offene DOI als status=pending.
	 */
	public static function query_rows( $a ) {
		global $wpdb;
		$main = M24_Database::table( 'il_interessenten' );
		$rel  = M24_Database::table( 'il_interessenten_tags' );

		$rows  = array();
		$mrows = $wpdb->get_results( "SELECT email, name, kundentyp, source_inserat_id, status, created_at, consent_at FROM $main", ARRAY_A );
		foreach ( (array) $mrows as $r ) {
			$rows[ strtolower( $r['email'] ) ] = array(
				'email'        => $r['email'],
				'name'         => (string) $r['name'],
				'kundentyp'    => (string) $r['kundentyp'],
				'source_id'    => (int) $r['source_inserat_id'],
				'status'       => (string) $r['status'],
				'angemeldet'   => (string) $r['created_at'],
				'angemeldet_ts'=> $r['created_at'] ? (int) strtotime( (string) $r['created_at'] ) : 0,
				'bestaetigt'   => (string) $r['consent_at'],
				'tags'         => array(),
			);
		}

		// Offene DOI-Pending-Einträge (bestätigte gewinnen bei Kollision).
		if ( class_exists( 'M24_Brevo_IL' ) ) {
			foreach ( M24_Brevo_IL::pending_list() as $p ) {
				$k = strtolower( $p['email'] );
				if ( '' === $k || isset( $rows[ $k ] ) ) {
					continue;
				}
				$rows[ $k ] = array(
					'email'         => $p['email'],
					'name'          => $p['name'],
					'kundentyp'     => $p['kundentyp'],
					'source_id'     => (int) $p['source_id'],
					'status'        => 'pending',
					'angemeldet'    => $p['created'] ? wp_date( 'Y-m-d H:i:s', $p['created'] ) : '',
					'angemeldet_ts' => (int) $p['created'],
					'bestaetigt'    => '',
					'tags'          => array_values( (array) $p['tags'] ),
				);
			}
		}

		// Tags der bestätigten Kontakte aggregieren.
		if ( ! empty( $mrows ) ) {
			$emails = array();
			foreach ( $mrows as $r ) {
				$emails[] = $r['email'];
			}
			$ph    = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
			$trows = $wpdb->get_results( $wpdb->prepare( "SELECT email, tag FROM $rel WHERE email IN ($ph)", $emails ), ARRAY_A );
			foreach ( (array) $trows as $tr ) {
				$k = strtolower( $tr['email'] );
				if ( isset( $rows[ $k ] ) ) {
					$rows[ $k ]['tags'][] = $tr['tag'];
				}
			}
		}

		// Filter anwenden.
		$out = array();
		foreach ( $rows as $r ) {
			if ( '' !== $a['status'] && $r['status'] !== $a['status'] ) {
				continue;
			}
			if ( '' !== $a['kundentyp'] && $r['kundentyp'] !== $a['kundentyp'] ) {
				continue;
			}
			if ( $a['vehicle'] > 0 && (int) $r['source_id'] !== $a['vehicle'] ) {
				continue;
			}
			if ( '' !== $a['tag'] && ! in_array( $a['tag'], $r['tags'], true ) ) {
				continue;
			}
			if ( '' !== $a['s'] ) {
				$hay = strtolower( $r['email'] . ' ' . $r['name'] );
				if ( false === strpos( $hay, strtolower( $a['s'] ) ) ) {
					continue;
				}
			}
			$out[] = $r;
		}
		return $out;
	}

	/** Zähler-Kacheln. */
	public static function counts() {
		global $wpdb;
		$main  = M24_Database::table( 'il_interessenten' );
		$aktiv = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $main WHERE status = 'aktiv'" );
		$ab    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $main WHERE status = 'abgemeldet'" );
		$pend  = 0;
		if ( class_exists( 'M24_Brevo_IL' ) ) {
			$mem    = $wpdb->get_col( "SELECT LOWER(email) FROM $main" );
			$memset = array_flip( (array) $mem );
			foreach ( M24_Brevo_IL::pending_list() as $p ) {
				if ( ! isset( $memset[ strtolower( $p['email'] ) ] ) ) {
					$pend++;
				}
			}
		}
		return array( 'aktiv' => $aktiv, 'pending' => $pend, 'abgemeldet' => $ab );
	}

	/** Distinct auslösende Fahrzeuge (für das Filter-Dropdown). */
	public static function vehicles_list() {
		global $wpdb;
		$main = M24_Database::table( 'il_interessenten' );
		$ids  = $wpdb->get_col( "SELECT DISTINCT source_inserat_id FROM $main WHERE source_inserat_id > 0" );
		$ids  = array_map( 'intval', (array) $ids );
		if ( class_exists( 'M24_Brevo_IL' ) ) {
			foreach ( M24_Brevo_IL::pending_list() as $p ) {
				if ( (int) $p['source_id'] > 0 ) {
					$ids[] = (int) $p['source_id'];
				}
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$out = array();
		foreach ( $ids as $pid ) {
			$title       = get_the_title( $pid );
			$out[ $pid ] = '' !== $title ? $title : ( '#' . $pid );
		}
		asort( $out );
		return $out;
	}

	/* ── Render ──────────────────────────────────────────────────────────── */

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}

		$counts = self::counts();
		$args   = self::request_args();

		$table = new M24_Interessenten_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap m24il-wrap">
			<h1><?php echo esc_html__( 'MOTORSPORT24 — Interessenten', 'm24-plattform' ); ?></h1>
			<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>

			<?php if ( isset( $_GET['m24il_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php
					$done = sanitize_text_field( wp_unslash( $_GET['m24il_done'] ) );
					echo esc_html( 'deleted' === $done ? __( 'Eintrag gelöscht (Brevo + Tabellen).', 'm24-plattform' ) : __( 'Interessent abgemeldet (Status + Brevo-Listen).', 'm24-plattform' ) );
				?></p></div>
			<?php endif; ?>

			<div class="m24il-tiles">
				<div class="m24il-tile"><div class="n"><?php echo (int) $counts['aktiv']; ?></div><div class="l"><?php echo esc_html__( 'Aktiv', 'm24-plattform' ); ?></div></div>
				<div class="m24il-tile"><div class="n y"><?php echo (int) $counts['pending']; ?></div><div class="l"><?php echo esc_html__( 'Pending (DOI offen)', 'm24-plattform' ); ?></div></div>
				<div class="m24il-tile"><div class="n g"><?php echo (int) $counts['abgemeldet']; ?></div><div class="l"><?php echo esc_html__( 'Abgemeldet', 'm24-plattform' ); ?></div></div>
			</div>

			<form method="get" class="m24il-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

				<select name="f_vehicle">
					<option value="0"><?php echo esc_html__( 'Alle Fahrzeuge', 'm24-plattform' ); ?></option>
					<?php foreach ( self::vehicles_list() as $pid => $title ) : ?>
						<option value="<?php echo (int) $pid; ?>" <?php selected( $args['vehicle'], $pid ); ?>><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>

				<select name="f_tag">
					<option value=""><?php echo esc_html__( 'Alle Modellreihen/Tags', 'm24-plattform' ); ?></option>
					<?php if ( class_exists( 'M24_Alert_Taxonomie' ) ) : foreach ( M24_Alert_Taxonomie::tags() as $slug => $t ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $args['tag'], $slug ); ?>><?php echo esc_html( $t['label'] ); ?></option>
					<?php endforeach; endif; ?>
				</select>

				<select name="f_status">
					<option value=""><?php echo esc_html__( 'Alle Status', 'm24-plattform' ); ?></option>
					<?php foreach ( array( 'aktiv' => 'Aktiv', 'pending' => 'Pending', 'abgemeldet' => 'Abgemeldet' ) as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>

				<select name="f_kundentyp">
					<option value=""><?php echo esc_html__( 'Alle Kundentypen', 'm24-plattform' ); ?></option>
					<?php foreach ( array( 'Privat', 'Geschäftskunde' ) as $kt ) : ?>
						<option value="<?php echo esc_attr( $kt ); ?>" <?php selected( $args['kundentyp'], $kt ); ?>><?php echo esc_html( $kt ); ?></option>
					<?php endforeach; ?>
				</select>

				<?php $table->search_box( __( 'Suche E-Mail/Name', 'm24-plattform' ), 'm24il-search' ); ?>

				<button type="submit" class="button"><?php echo esc_html__( 'Filtern', 'm24-plattform' ); ?></button>

				<?php
				$export_url = wp_nonce_url(
					add_query_arg(
						array_merge(
							array( 'action' => 'm24_il_export' ),
							array_filter( array(
								'f_vehicle'   => $args['vehicle'] ?: null,
								'f_tag'       => $args['tag'] ?: null,
								'f_status'    => $args['status'] ?: null,
								'f_kundentyp' => $args['kundentyp'] ?: null,
								's'           => $args['s'] ?: null,
							) )
						),
						admin_url( 'admin-post.php' )
					),
					'm24_il_export'
				);
				?>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php echo esc_html__( 'Als CSV exportieren', 'm24-plattform' ); ?></a>

				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private static function css() {
		return ".m24il-wrap{--brass:#9a6b25}"
			. ".m24il-tiles{display:flex;gap:16px;margin:16px 0 22px;flex-wrap:wrap}"
			. ".m24il-tile{background:#fff;border:1px solid #e6e9ee;border-radius:12px;padding:18px 28px;min-width:160px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.03)}"
			. ".m24il-tile .n{font-family:'Saira',sans-serif;font-size:42px;font-weight:800;line-height:1;color:var(--brass)}"
			. ".m24il-tile .n.y{color:#b87000}.m24il-tile .n.g{color:#8a93a0}"
			. ".m24il-tile .l{font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:#5a6474;margin-top:6px}"
			. ".m24il-filters{background:#fafafa;border:1px solid #e6e9ee;border-radius:12px;padding:14px 16px;margin:0 0 8px}"
			. ".m24il-filters select{margin:0 6px 8px 0;vertical-align:middle}"
			. ".m24il-chip{display:inline-block;font-size:11px;line-height:1;padding:4px 8px;border-radius:999px;margin:2px 3px 2px 0;background:#eef0f3;color:#5a6474;border:1px solid #e0e3e8}"
			. ".m24il-chip.leaf{background:#f6efe2;color:#9a6b25;border-color:#e4d3b0;font-weight:700}"
			. ".m24il-st{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}"
			. ".m24il-st.aktiv{background:#edf7f1;color:#1a7a3c}.m24il-st.pending{background:#fdf5e6;color:#b87000}.m24il-st.abgemeldet{background:#f1f2f4;color:#8a93a0}"
			. ".m24il-wrap td,.m24il-wrap th{vertical-align:middle}";
	}

	/* ── Aktionen ────────────────────────────────────────────────────────── */

	private static function back_url( $done = '' ) {
		$ref = wp_get_referer();
		if ( ! $ref ) {
			$ref = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		}
		$ref = remove_query_arg( array( 'm24il_done', '_wpnonce', 'action', 'email' ), $ref );
		return $done ? add_query_arg( 'm24il_done', $done, $ref ) : $ref;
	}

	public static function handle_unsub() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		$email = sanitize_email( wp_unslash( $_GET['email'] ?? '' ) );
		check_admin_referer( 'm24_il_unsub_' . $email );
		if ( is_email( $email ) && class_exists( 'M24_Brevo_IL' ) ) {
			M24_Brevo_IL::mark_unsubscribed( $email );
		}
		wp_safe_redirect( self::back_url( 'unsub' ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		$email = sanitize_email( wp_unslash( $_GET['email'] ?? '' ) );
		check_admin_referer( 'm24_il_delete_' . $email );
		if ( is_email( $email ) && class_exists( 'M24_Brevo_IL' ) ) {
			M24_Brevo_IL::hard_delete( $email );
		}
		wp_safe_redirect( self::back_url( 'deleted' ) );
		exit;
	}

	public static function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		check_admin_referer( 'm24_il_export' );

		$rows = self::query_rows( self::request_args() );
		$tax  = class_exists( 'M24_Alert_Taxonomie' ) ? M24_Alert_Taxonomie::tags() : array();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=interessenten-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM für Excel
		fputcsv( $out, array( 'E-Mail', 'Name', 'Kundentyp', 'Fahrzeug-ID', 'Fahrzeug', 'Tags', 'Status', 'Angemeldet', 'Bestätigt' ) );
		foreach ( $rows as $r ) {
			$labels = array();
			foreach ( $r['tags'] as $slug ) {
				$labels[] = isset( $tax[ $slug ] ) ? $tax[ $slug ]['label'] : $slug;
			}
			$vtitle = $r['source_id'] ? get_the_title( $r['source_id'] ) : '';
			fputcsv( $out, array(
				$r['email'],
				$r['name'],
				$r['kundentyp'],
				$r['source_id'] ?: '',
				'' !== $vtitle ? $vtitle : ( $r['source_id'] ? '#' . $r['source_id'] : '' ),
				implode( ', ', $labels ),
				$r['status'],
				$r['angemeldet'],
				$r['bestaetigt'],
			) );
		}
		fclose( $out );
		exit;
	}
}

/* ========================================================================= */

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class M24_Interessenten_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'interessent',
			'plural'   => 'interessenten',
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'email'      => __( 'E-Mail', 'm24-plattform' ),
			'name'       => __( 'Name', 'm24-plattform' ),
			'kundentyp'  => __( 'Kundentyp', 'm24-plattform' ),
			'fahrzeug'   => __( 'Auslösendes Fahrzeug', 'm24-plattform' ),
			'tags'       => __( 'Modellreihen / Tags', 'm24-plattform' ),
			'status'     => __( 'Status', 'm24-plattform' ),
			'angemeldet' => __( 'Angemeldet', 'm24-plattform' ),
			'bestaetigt' => __( 'Bestätigt', 'm24-plattform' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'email'      => array( 'email', false ),
			'name'       => array( 'name', false ),
			'kundentyp'  => array( 'kundentyp', false ),
			'status'     => array( 'status', false ),
			'angemeldet' => array( 'angemeldet', true ),
			'bestaetigt' => array( 'bestaetigt', false ),
		);
	}

	public function prepare_items() {
		$args = M24_Interessenten_Page::request_args();
		$rows = M24_Interessenten_Page::query_rows( $args );

		// Sortierung (whitelisted).
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'angemeldet';
		$order   = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( (string) $_REQUEST['order'] ) ) ? 'asc' : 'desc';
		$allowed = array( 'email', 'name', 'kundentyp', 'status', 'angemeldet', 'bestaetigt' );
		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = 'angemeldet';
		}
		usort( $rows, function ( $a, $b ) use ( $orderby ) {
			if ( 'angemeldet' === $orderby ) {
				return (int) $a['angemeldet_ts'] <=> (int) $b['angemeldet_ts'];
			}
			if ( 'bestaetigt' === $orderby ) {
				return strcmp( (string) $a['bestaetigt'], (string) $b['bestaetigt'] );
			}
			return strcasecmp( (string) ( $a[ $orderby ] ?? '' ), (string) ( $b[ $orderby ] ?? '' ) );
		} );
		if ( 'desc' === $order ) {
			$rows = array_reverse( $rows );
		}

		$per_page = M24_Interessenten_Page::PER_PAGE;
		$total    = count( $rows );
		$paged    = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $paged - 1 ) * $per_page;
		$this->items = array_slice( $rows, $offset, $per_page );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'email' );
	}

	public function column_default( $item, $column ) {
		switch ( $column ) {
			case 'name':
				return esc_html( $item['name'] );
			case 'kundentyp':
				return esc_html( $item['kundentyp'] );
			case 'angemeldet':
				return $item['angemeldet'] ? esc_html( mysql2date( 'd.m.Y H:i', $item['angemeldet'] ) ) : '—';
			case 'bestaetigt':
				return $item['bestaetigt'] ? esc_html( mysql2date( 'd.m.Y H:i', $item['bestaetigt'] ) ) : '—';
			default:
				return '';
		}
	}

	public function column_email( $item ) {
		$email = $item['email'];
		$actions = array();

		$unsub = wp_nonce_url(
			add_query_arg( array( 'action' => 'm24_il_unsub', 'email' => $email ), admin_url( 'admin-post.php' ) ),
			'm24_il_unsub_' . $email
		);
		$delete = wp_nonce_url(
			add_query_arg( array( 'action' => 'm24_il_delete', 'email' => $email ), admin_url( 'admin-post.php' ) ),
			'm24_il_delete_' . $email
		);

		if ( 'abgemeldet' !== $item['status'] ) {
			$actions['unsub'] = '<a href="' . esc_url( $unsub ) . '" onclick="return confirm(\'' . esc_js( sprintf( __( '%s wirklich abmelden? (Status → abgemeldet, raus aus den Brevo-Listen)', 'm24-plattform' ), $email ) ) . '\');">' . esc_html__( 'Abmelden', 'm24-plattform' ) . '</a>';
		}
		$actions['delete'] = '<a href="' . esc_url( $delete ) . '" style="color:#b32d2e;" onclick="return confirm(\'' . esc_js( sprintf( __( '%s endgültig löschen? Entfernt den Kontakt aus Brevo und allen Tabellen (DSGVO Art. 17).', 'm24-plattform' ), $email ) ) . '\');">' . esc_html__( 'Löschen', 'm24-plattform' ) . '</a>';

		return '<strong>' . esc_html( $email ) . '</strong>' . $this->row_actions( $actions );
	}

	public function column_fahrzeug( $item ) {
		$pid = (int) $item['source_id'];
		if ( ! $pid ) {
			return '—';
		}
		$title = get_the_title( $pid );
		$edit  = get_edit_post_link( $pid );
		if ( '' === $title ) {
			// Post weg → gespeicherte ID als Fallback.
			return '<span title="' . esc_attr__( 'Inserat nicht mehr vorhanden', 'm24-plattform' ) . '">#' . $pid . '</span>';
		}
		if ( $edit ) {
			return '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
		}
		return esc_html( $title );
	}

	public function column_tags( $item ) {
		$tax = class_exists( 'M24_Alert_Taxonomie' ) ? M24_Alert_Taxonomie::tags() : array();
		$out = '';
		foreach ( (array) $item['tags'] as $slug ) {
			$t     = isset( $tax[ $slug ] ) ? $tax[ $slug ] : null;
			$label = $t ? $t['label'] : $slug;
			$leaf  = $t && 'modell' === $t['ebene'];
			$out  .= '<span class="m24il-chip' . ( $leaf ? ' leaf' : '' ) . '">' . esc_html( $label ) . '</span>';
		}
		return $out ?: '—';
	}

	public function column_status( $item ) {
		$st = $item['status'];
		return '<span class="m24il-st ' . esc_attr( $st ) . '">' . esc_html( ucfirst( $st ) ) . '</span>';
	}

	public function no_items() {
		esc_html_e( 'Keine Interessenten gefunden.', 'm24-plattform' );
	}
}
