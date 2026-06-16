<?php
/**
 * M24 Plattform — Shopware Admin-API Client
 * Modul: modules/importer/class-m24-shopware-client.php
 *
 * Minimaler OAuth-Wrapper (client_credentials) + JSON-Search-Helper fuer
 * den Gebrauchtteile-Importer (Paket D).
 *
 * Konstanten in wp-config.php erwartet:
 *   M24_SHOPWARE_URL, M24_SHOPWARE_CLIENT_ID, M24_SHOPWARE_SECRET
 *
 * Token-Cache: pro Instanz, mit Sicherheitsmarge (30s vor expires_in renewen).
 * Long-Run-Sicher (Importer kann mehrere Minuten laufen, Token lebt ~10 min).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Shopware_Client {

	private $url;
	private $client_id;
	private $secret;
	private $token            = null;
	private $token_expires_at = 0;

	public function __construct() {
		foreach ( array( 'M24_SHOPWARE_URL', 'M24_SHOPWARE_CLIENT_ID', 'M24_SHOPWARE_SECRET' ) as $c ) {
			if ( ! defined( $c ) || '' === (string) constant( $c ) ) {
				throw new Exception( "Konstante $c fehlt in wp-config.php — siehe Paket-D-Spec." );
			}
		}
		$this->url       = untrailingslashit( (string) M24_SHOPWARE_URL );
		$this->client_id = (string) M24_SHOPWARE_CLIENT_ID;
		$this->secret    = (string) M24_SHOPWARE_SECRET;
	}

	/** Holt frischen Access-Token (Cache + Auto-Refresh). Wirft bei Fehler. */
	private function get_token() {
		if ( $this->token && time() < ( $this->token_expires_at - 30 ) ) {
			return $this->token;
		}
		$res = wp_remote_post( $this->url . '/api/oauth/token', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->client_id,
				'client_secret' => $this->secret,
			) ),
			'timeout' => 30,
		) );
		if ( is_wp_error( $res ) ) {
			throw new Exception( 'OAuth-Anfrage fehlgeschlagen: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			throw new Exception( 'OAuth-Fehler HTTP ' . $code . ': ' . wp_json_encode( $body ) );
		}
		$this->token            = (string) $body['access_token'];
		$this->token_expires_at = time() + (int) ( $body['expires_in'] ?? 600 );
		return $this->token;
	}

	/** Generischer POST-Helper (Auth + JSON-Body + Fehlerbehandlung). */
	public function post( $path, $body ) {
		$token = $this->get_token();
		$res   = wp_remote_post( $this->url . $path, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		) );
		if ( is_wp_error( $res ) ) {
			throw new Exception( 'API-Anfrage fehlgeschlagen: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 ) {
			throw new Exception( 'API-Fehler HTTP ' . $code . ' bei ' . $path . ': ' . wp_json_encode( $decoded ) );
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Search-Helper fuer GEBRAUCHTE TEILE (parents/simple, Porsche raus).
	 * Default-Wurzel + Exclude-UUIDs aus der Spec.
	 *
	 * @param int   $page                Pagination-Seite (1-basiert).
	 * @param int   $limit               Items pro Seite (max 25 sinnvoll).
	 * @param array $exclude_categories  UUIDs die ausgeschlossen werden (clientseitig + per not-Filter).
	 * @return array Raw-Response: { data: [...], total: int, ... }
	 */
	public function search_used_products( $page = 1, $limit = 25, $exclude_categories = array() ) {
		$category_uuid = 'bbf8f7c85c554feca7a8276bf8e8b6c5'; // GEBRAUCHTE TEILE (Wurzel)
		$filter = array(
			array( 'type' => 'equals',   'field' => 'parentId',           'value' => null ),
			array( 'type' => 'contains', 'field' => 'categoriesRo.path', 'value' => $category_uuid ),
		);
		if ( ! empty( $exclude_categories ) ) {
			$not_queries = array();
			foreach ( $exclude_categories as $cat ) {
				$not_queries[] = array( 'type' => 'contains', 'field' => 'categoriesRo.path', 'value' => $cat );
			}
			$filter[] = array(
				'type'     => 'multi',
				'operator' => 'and',
				'queries'  => array( array(
					'type'     => 'not',
					'operator' => 'or',
					'queries'  => $not_queries,
				) ),
			);
		}
		$query = array(
			'filter'           => $filter,
			'associations'     => $this->used_product_associations(),
			'page'             => max( 1, (int) $page ),
			'limit'            => max( 1, min( 100, (int) $limit ) ),
			// total-count-mode: 1 = exact count (Spec: im Dry-Run echte Gesamtzahl).
			'total-count-mode' => 1,
		);
		return $this->post( '/api/search/product', $query );
	}

	/** Gemeinsame Association-Struktur fuer Gebrauchtteile (Voll-Import inkl. Medien). */
	private function used_product_associations() {
		return array(
			'media'      => new \stdClass(),
			'tax'        => new \stdClass(),
			'categories' => new \stdClass(),
			'children'   => array( 'associations' => array( 'options' => new \stdClass() ) ),
			'options'    => new \stdClass(),
		);
	}

	/**
	 * Leichtgewichtige ID-Listung fuer den Enqueue-Schritt: NUR id + productNumber,
	 * KEINE Associations (keine Bilder) → schnell, viele Seiten in Sekunden.
	 *
	 * @param int   $page
	 * @param int   $limit               Items pro Seite (bis 100).
	 * @param array $exclude_categories  UUIDs die ausgeschlossen werden.
	 * @return array Raw-Response: { data: [{id,productNumber}...], total: int }
	 */
	public function search_used_product_ids( $page = 1, $limit = 100, $exclude_categories = array() ) {
		$category_uuid = 'bbf8f7c85c554feca7a8276bf8e8b6c5'; // GEBRAUCHTE TEILE (Wurzel)
		$filter = array(
			array( 'type' => 'equals',   'field' => 'parentId',           'value' => null ),
			array( 'type' => 'contains', 'field' => 'categoriesRo.path', 'value' => $category_uuid ),
		);
		if ( ! empty( $exclude_categories ) ) {
			$not_queries = array();
			foreach ( $exclude_categories as $cat ) {
				$not_queries[] = array( 'type' => 'contains', 'field' => 'categoriesRo.path', 'value' => $cat );
			}
			$filter[] = array(
				'type'     => 'multi',
				'operator' => 'and',
				'queries'  => array( array(
					'type'     => 'not',
					'operator' => 'or',
					'queries'  => $not_queries,
				) ),
			);
		}
		$query = array(
			'filter'           => $filter,
			'includes'         => array( 'product' => array( 'id', 'productNumber' ) ),
			'page'             => max( 1, (int) $page ),
			'limit'            => max( 1, min( 100, (int) $limit ) ),
			'total-count-mode' => 1,
		);
		return $this->post( '/api/search/product', $query );
	}

	/**
	 * Leichte ID-Listung der Produkte EINER beliebigen Kategorie (Subtree via path),
	 * NUR Haupt-Produkte (parentId=null). Fuer den Rennsport-Import (Kategorie-getrieben).
	 *
	 * @param string $category_uuid  Shopware-Kategorie-UUID.
	 * @param int    $page
	 * @param int    $limit          Items pro Seite (bis 100).
	 * @return array Raw-Response: { data: [{id,productNumber}...], total: int }
	 */
	public function search_category_product_ids( $category_uuid, $page = 1, $limit = 100 ) {
		$query = array(
			'filter'           => array(
				array( 'type' => 'equals',   'field' => 'parentId',          'value' => null ),
				array( 'type' => 'contains', 'field' => 'categoriesRo.path', 'value' => (string) $category_uuid ),
			),
			'includes'         => array( 'product' => array( 'id', 'productNumber' ) ),
			'page'             => max( 1, (int) $page ),
			'limit'            => max( 1, min( 100, (int) $limit ) ),
			'total-count-mode' => 1,
		);
		return $this->post( '/api/search/product', $query );
	}

	/**
	 * Voll-Hydrierung einer ID-Liste (mit Medien/Tax/Kategorien-Associations) fuer den
	 * Hintergrund-Worker. Wird pro Batch genau einmal aufgerufen.
	 *
	 * @param array $ids Shopware-Produkt-UUIDs.
	 * @return array data[] der Produkte (kann leer sein).
	 */
	public function fetch_products_by_ids( array $ids ) {
		$ids = array_values( array_filter( array_map( 'strval', $ids ) ) );
		if ( empty( $ids ) ) { return array(); }
		$query = array(
			'filter'       => array(
				array( 'type' => 'equalsAny', 'field' => 'id', 'value' => implode( '|', $ids ) ),
			),
			'associations' => $this->used_product_associations(),
			'limit'        => max( 1, min( 100, count( $ids ) ) ),
		);
		$res = $this->post( '/api/search/product', $query );
		return isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
	}
}
