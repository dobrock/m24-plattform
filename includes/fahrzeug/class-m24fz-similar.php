<?php
/**
 * M24 Fahrzeug — „Ähnliche Fahrzeuge"
 * Modul: includes/fahrzeug/class-m24fz-similar.php
 *
 * Gleiche Marke; aktive (gelistet) vorrangig, mit verkauften/reservierten auffüllen (max 3).
 * Reicht das nicht → weitere aktive aus derselben Kategorie (race/classic). Deaktivierte NIE.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Similar {

	/** @return int[] Post-IDs (max $limit). */
	public static function ids( $post_id, $limit = 3 ) {
		$post_id = (int) $post_id;
		$marke   = (string) get_post_meta( $post_id, '_m24fz_marke', true );
		$kat     = (string) get_post_meta( $post_id, '_m24fz_kat', true );
		$out     = array();

		// Reihenfolge der Versuche: Marke aktiv → Marke verkauft/reserviert → Kategorie aktiv.
		$tries = array();
		if ( '' !== $marke ) {
			$tries[] = array( 'marke' => $marke, 'status' => array( 'gelistet' ) );
			$tries[] = array( 'marke' => $marke, 'status' => array( 'verkauft', 'reserviert' ) );
		}
		$tries[] = array( 'kat' => $kat, 'status' => array( 'gelistet' ) );

		foreach ( $tries as $t ) {
			if ( count( $out ) >= $limit ) { break; }
			foreach ( self::query( $t, $post_id, $out, $limit ) as $pid ) {
				if ( ! in_array( $pid, $out, true ) ) { $out[] = $pid; }
				if ( count( $out ) >= $limit ) { break; }
			}
		}
		return array_slice( $out, 0, $limit );
	}

	private static function query( $args, $exclude, $already, $limit ) {
		$meta = array(
			array( 'key' => '_m24fz_status', 'value' => $args['status'], 'compare' => 'IN' ),
		);
		if ( ! empty( $args['marke'] ) ) { $meta[] = array( 'key' => '_m24fz_marke', 'value' => $args['marke'] ); }
		$q = array(
			'post_type'      => M24FZ_CPT::PT,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, $limit - count( $already ) ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => array_merge( array( $exclude ), $already ),
			'orderby'        => 'date', 'order' => 'DESC',
			'meta_query'     => $meta,
		);
		if ( ! empty( $args['kat'] ) ) {
			$q['tax_query'] = array( array( 'taxonomy' => M24FZ_CPT::TAX, 'field' => 'slug', 'terms' => array( $args['kat'], M24FZ_CPT::SOLD_MAP[ $args['kat'] ] ?? $args['kat'] ) ) );
		}
		return array_map( 'intval', get_posts( $q ) );
	}
}
