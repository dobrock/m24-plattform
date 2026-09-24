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
		// WP-Rocket-Mobil-Cache einmalig einschalten (siehe rocket_mobile_cache()).
		add_action( 'admin_init', array( __CLASS__, 'rocket_mobile_cache' ) );
	}

	/**
	 * WP Rocket: getrennten Mobil-Cache einschalten — per Code, weil die Oberflaeche
	 * die beiden Schalter in der installierten Rocket-Version nicht mehr zeigt.
	 *
	 * HINTERGRUND (24.09.2026): Das tagDiv Mobile Theme liefert per User-Agent ein
	 * eigenes Mobil-Template. Standen beide Rocket-Optionen auf aus, wurde ein Aufruf,
	 * den das Theme als mobil einstufte, Rocket aber als Desktop, als Desktop-Cache-
	 * Datei gespeichert — und danach bekam jeder Desktop-Besucher die Mobilseite
	 * (Daniels "Ansicht springt beim Klicken auf mobil"). Das Plugin abzuschalten war
	 * keine Loesung: ohne Mobil-Template zeigt das Theme auf dem Handy nur noch den
	 * nackten Fallback. Mit getrennten Cache-Dateien haelt Rocket beides auseinander.
	 *
	 * Laeuft genau einmal (Option m24_rocket_mobile_cache_set), aendert nur diese zwei
	 * Schluessel, leert danach den Cache. Ohne Rocket: nichts.
	 * Zwischenloesung in dieser Datei, weil sie zuverlaessig im Admin geladen wird;
	 * gehoert langfristig in ein eigenes Modul (CC).
	 */
	public static function rocket_mobile_cache() {
		if ( get_option( 'm24_rocket_mobile_cache_set' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts = get_option( 'wp_rocket_settings' );
		if ( ! is_array( $opts ) ) {
			return; // Rocket nicht installiert oder nie konfiguriert.
		}
		$opts['cache_mobile']            = 1;
		$opts['do_caching_mobile_files'] = 1;
		update_option( 'wp_rocket_settings', $opts );

		// Die Sperre wird ERST gesetzt, wenn Rockets Config-Datei neu geschrieben ist. Rocket liest
		// die Schalter von dort, nicht aus der Option — faellt der Aufruf aus (Rocket noch nicht
		// geladen, Funktion nicht vorhanden), waere die Option gesetzt und die Config-Datei alt: der
		// Mobil-Cache bliebe aus, und weil die Sperre steht, versuchte es nie wieder jemand.
		// update_option auf dieselben Werte ist folgenlos, ein zweiter Lauf also unschaedlich.
		if ( ! function_exists( 'rocket_generate_config_file' ) ) {
			return;
		}
		rocket_generate_config_file();
		update_option( 'm24_rocket_mobile_cache_set', gmdate( 'c' ), false );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		// M24_Error_Log kennt nur capture(); ein log() gibt es nicht. Mit method_exists davor lief der
		// Eintrag ins Leere — und weil die Sperre oben genau einmal faellt, haette hinterher nirgends
		// gestanden, ob und wann geschaltet wurde.
		if ( class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'rocket', 'info', 'Mobil-Cache eingeschaltet (cache_mobile + do_caching_mobile_files), Config neu geschrieben, Cache geleert.', array(
				'cache_mobile' => 1, 'do_caching_mobile_files' => 1,
			) );
		}
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
			// Sitemap-Neubau (16.09.2026): raus aus der Leiste. Die Sitemap baut sich
			// selbst, der Knopf wurde im Alltag nie gebraucht — der Platz gehört dem
			// Angebotsbereich, der mehrmals taeglich gebraucht wird. Erreichbar bleibt
			// er unter MOTORSPORT24 -> System -> Sitemap.
			'm24-sitemap-rebuild',
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
		// Angebote zuerst: der Bereich, der im Tagesgeschaeft am haeufigsten gebraucht wird.
		// Nur fuer Operatoren — die Angebotsverwaltung haengt an manage_options.
		if ( current_user_can( 'manage_options' ) ) {
			$bar->add_node( array(
				'id'    => 'm24-angebote',
				'title' => 'Angebote',
				'href'  => admin_url( 'admin.php?page=m24-offers' ),
			) );
			$bar->add_node( array(
				'parent' => 'm24-angebote',
				'id'     => 'm24-angebote-liste',
				'title'  => 'Angebotsuebersicht',
				'href'   => admin_url( 'admin.php?page=m24-offers' ),
			) );
			$bar->add_node( array(
				'parent' => 'm24-angebote',
				'id'     => 'm24-angebote-anfragen',
				'title'  => 'Anfragen',
				'href'   => admin_url( 'admin.php?page=m24-anfragen' ),
			) );
			$bar->add_node( array(
				'parent' => 'm24-angebote',
				'id'     => 'm24-angebote-neu',
				'title'  => 'Neues Angebot',
				'href'   => home_url( '/?m24_offer_new=1' ),
			) );
		}

		$bar->add_node( array(
			'id'    => 'm24-inserate',
			'title' => 'Auto-Verwaltung',
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
			$klar    = array( 'entwurf' => 'Entwurf', 'gelistet' => 'Gelistet', 'reserviert' => 'Reserviert', 'verkauft' => 'Verkauft', 'deaktiviert' => 'Deaktiviert' );
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
