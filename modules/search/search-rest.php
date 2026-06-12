<?php
/**
 * M24 Plattform — Gruppierte Suche: REST-Endpoint
 * Modul: modules/search/search-rest.php
 *
 * GET /wp-json/m24/v1/search?q=<begriff>
 *   → { q, groups: { fahrzeuge?, teile?, verschiedenes? } }
 *   Jede Gruppe: { items:[...], total:int, all_url, label }. LEERE Gruppen werden
 *   weggelassen (kein leerer Abschnitt im Dropdown). Oeffentlich (Suche ist public).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Search_REST {

	const NS       = 'm24/v1';
	const MIN_CHARS = 2;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route( self::NS, '/search', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'q' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	public static function handle( WP_REST_Request $request ) {
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( mb_strlen( $q ) < self::MIN_CHARS ) {
			return rest_ensure_response( array( 'q' => $q, 'groups' => array() ) );
		}

		$labels = array(
			M24_Search_Query::GROUP_FAHRZEUGE     => __( 'Fahrzeuge', 'm24-plattform' ),
			M24_Search_Query::GROUP_TEILE         => __( 'Teile', 'm24-plattform' ),
			M24_Search_Query::GROUP_VERSCHIEDENES => __( 'Verschiedenes', 'm24-plattform' ),
		);

		$groups = M24_Search_Query::search( $q );
		$out    = array();
		foreach ( $groups as $key => $g ) {
			if ( empty( $g['items'] ) ) { continue; } // leere Gruppe ausblenden
			$g['label']   = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$out[ $key ]  = $g;
		}

		$response = rest_ensure_response( array( 'q' => $q, 'groups' => $out ) );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		return $response;
	}
}
