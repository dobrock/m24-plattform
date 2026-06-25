<?php
/**
 * M24 Plattform — Admin-Bar aufräumen.
 *
 * Entfernt Fremd-Plugin-Ballast aus der Admin-Bar und ergänzt schnelle M24-Sprungziele.
 * Hohe Priorität (999), damit die Fremd-Nodes zum Zeitpunkt des Entfernens schon da sind.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Adminbar {

	public static function init() {
		// Eigene Nodes früh genug ergänzen.
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_nodes' ), 999 );
		// Fremd-Ballast NACH allen Registrierungen entfernen (omgf/Live-CSS/WP-Rocket
		// hängen sich spät ein → admin_bar_menu/999 läuft davor und greift ins Leere).
		add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'remove_nodes' ), 99999 );
		add_action( 'admin_post_m24_quickstatus', array( __CLASS__, 'quick_status' ) );
	}

	/** Fremd-Plugin-Nodes entfernen (filterbar). */
	public static function remove_nodes() {
		global $wp_admin_bar;
		if ( ! $wp_admin_bar ) {
			return;
		}
		$remove = apply_filters( 'm24_adminbar_remove_nodes', array(
			'rcb-top-node',              // Cookies (Real Cookie Banner)
			'omgf',                      // OMGF
			'td_live_css_css_writer',    // Live CSS
			'wp-rocket',                 // WP Rocket
			'customize',                 // Anpassen (Customizer)
			'comments',                  // Kommentar-Node (Kommentare site-weit aus)
			'duplicate-post',            // Duplicate Post
			'tdc_edit',                  // Edit with TagDiv Composer
			'tdc_page_mobile_template',  // Mobile page
		) );
		foreach ( (array) $remove as $node_id ) {
			$wp_admin_bar->remove_node( $node_id );
		}
	}

	/**
	 * M24-Sprungziele ergänzen — nur für Redakteure/Admins.
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public static function add_nodes( $bar ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$bar->add_node( array(
			'id'    => 'm24-inserate',
			'title' => 'Inserat-Verwaltung',
			'href'  => admin_url( 'admin.php?page=m24fz-verwaltung' ),
		) );
		$bar->add_node( array(
			'id'    => 'm24-alle-teile',
			'title' => 'Teile-Verwaltung',
			'href'  => admin_url( 'edit.php?post_type=m24_teil' ),
		) );

		self::add_status_node( $bar );
	}

	/**
	 * Status-Schnellzugriff für eine EINZELNE Teil-/Fahrzeug-Seite (Frontend-Detail oder Edit-Screen).
	 * Parent zeigt den aktuellen Status, Children schalten direkt um (admin-post, Nonce).
	 */
	private static function add_status_node( $bar ) {
		// Kontext bestimmen: Frontend-Single oder Edit-Screen (post.php?post=).
		$id = 0;
		$pt = '';
		if ( is_admin() ) {
			$pagenow = $GLOBALS['pagenow'] ?? '';
			if ( 'post.php' === $pagenow && ! empty( $_GET['post'] ) ) {
				$id = (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification
				$pt = get_post_type( $id );
			}
		} elseif ( is_singular( array( 'm24_teil', 'm24_fahrzeug' ) ) ) {
			$id = (int) get_queried_object_id();
			$pt = get_post_type( $id );
		}
		if ( ! $id || ! in_array( $pt, array( 'm24_teil', 'm24_fahrzeug' ), true ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}

		// Aktueller Status + mögliche Aktionen je Typ.
		if ( 'm24_fahrzeug' === $pt ) {
			$cur     = class_exists( 'M24FZ_CPT' ) ? M24FZ_CPT::status( $id ) : '';
			$klar    = array( 'entwurf' => 'Entwurf', 'gelistet' => 'Aktiv', 'reserviert' => 'Reserviert', 'verkauft' => 'Verkauft', 'deaktiviert' => 'Deaktiviert' );
			$actions = array( 'reserviert' => 'Reserviert', 'verkauft' => 'Verkauft', 'gelistet' => 'Aktivieren' );
			$type    = 'fahrzeug';
		} else {
			$cur     = get_post_meta( $id, '_m24_status', true ) ?: 'aktiv';
			$klar    = array( 'aktiv' => 'Aktiv', 'ausgeblendet' => 'Ausgeblendet', 'verkauft' => 'Verkauft' );
			$actions = array( 'verkauft' => 'Verkauft', 'aktiv' => 'Aktivieren', 'ausgeblendet' => 'Ausblenden' );
			$type    = 'teil';
		}
		$cur_label = $klar[ $cur ] ?? ucfirst( (string) $cur );

		$ref = is_admin() ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : ( get_permalink( $id ) ?: home_url( '/' ) );

		$bar->add_node( array(
			'id'    => 'm24-status',
			'title' => 'M24 · Status: ' . $cur_label,
		) );

		foreach ( $actions as $to => $label ) {
			if ( $to === $cur ) {
				$bar->add_node( array(
					'parent' => 'm24-status',
					'id'     => 'm24-status-' . $to,
					'title'  => '✓ ' . $label . ' (aktuell)',
					'href'   => false,
				) );
				continue;
			}
			$href = wp_nonce_url(
				add_query_arg(
					array( 'action' => 'm24_quickstatus', 'pt' => $type, 'post' => $id, 'to' => $to, 'ref' => rawurlencode( $ref ) ),
					admin_url( 'admin-post.php' )
				),
				'm24_quickstatus_' . $id
			);
			$bar->add_node( array(
				'parent' => 'm24-status',
				'id'     => 'm24-status-' . $to,
				'title'  => $label,
				'href'   => $href,
			) );
		}
	}

	/** Handler: Status aus dem Admin-Bar-Schnellzugriff setzen (cap + Nonce), dann zurück zur Seite. */
	public static function quick_status() {
		$id = (int) ( $_GET['post'] ?? 0 );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		check_admin_referer( 'm24_quickstatus_' . $id );

		$pt = sanitize_key( $_GET['pt'] ?? '' );
		$to = sanitize_key( $_GET['to'] ?? '' );
		if ( 'fahrzeug' === $pt && class_exists( 'M24FZ_CPT' ) ) {
			if ( in_array( $to, array( 'reserviert', 'verkauft', 'gelistet' ), true ) ) {
				M24FZ_CPT::set_status( $id, $to );
			}
		} elseif ( 'teil' === $pt && class_exists( 'M24_Catalog_Admin_List' ) ) {
			if ( in_array( $to, array( 'verkauft', 'aktiv', 'ausgeblendet' ), true ) ) {
				M24_Catalog_Admin_List::set_status( $id, $to );
			}
		}

		$ref = isset( $_GET['ref'] ) ? esc_url_raw( wp_unslash( $_GET['ref'] ) ) : '';
		if ( '' === $ref ) {
			$ref = get_permalink( $id ) ?: home_url( '/' );
		}
		wp_safe_redirect( $ref );
		exit;
	}
}
