<?php
/**
 * M24 Plattform — Katalog: Preislogik
 * Modul: catalog-pricing.php
 *
 * Verantwortung: einheitliche Preisberechnung und -formatierung für Templates
 * und Admin-Liste. Reiner Helfer — kein init()/keine Hooks.
 *
 *  - regel:       Basiswert = NETTO; brutto = netto × 1,19; beide werden gezeigt.
 *  - paragraf25a: Basiswert = BRUTTO; keine ausweisbare MwSt.
 *
 * Seit Migration 004 ist `_m24_preisoptionen` (JSON-Array) das primaere
 * Preisdatenmodell. `get()` bleibt fuer Backward-Compat erhalten und liefert
 * die Default-Option (= erste Option). `get_options()` liefert alle Optionen.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Pricing {

	const MWST_SATZ = 0.19;

	/** Meta-Key, über den die Standard-Sortierung „teuerste zuerst" läuft. */
	const SORT_META = '_m24_preis_netto';

	/**
	 * Robuste Preis-Sortierung für Listen-Queries (Teile-Archiv + Modell-Hubs).
	 * Aktiv NUR, wenn die Query den Query-Var `m24_price_sort` = 'ASC'|'DESC' trägt.
	 *
	 * LEFT JOIN auf den Preis-Meta-Key → Teile OHNE Preiswert fallen NICHT aus der Liste
	 * (anders als meta_key + meta_value_num, das per INNER JOIN ausschließt). Preislos
	 * (Meta fehlt) UND Preis 0 landen IMMER am Ende — in beide Richtungen. Tie-Breaker:
	 * Datum DESC (stabile Reihenfolge).
	 */
	public static function price_sort_clauses( $clauses, $query ) {
		$dir = $query->get( 'm24_price_sort' );
		if ( 'ASC' !== $dir && 'DESC' !== $dir ) { return $clauses; }
		global $wpdb;
		$val = "CAST(m24price.meta_value AS DECIMAL(12,2))";
		$has = "CASE WHEN m24price.meta_value IS NULL OR {$val} = 0 THEN 0 ELSE 1 END";
		$clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} m24price ON m24price.post_id = {$wpdb->posts}.ID AND m24price.meta_key = '" . esc_sql( self::SORT_META ) . "' ";
		$clauses['orderby'] = "{$has} DESC, {$val} {$dir}, {$wpdb->posts}.post_date DESC";
		return $clauses;
	}

	/** Tooltip-Text am „Verpackung & Transport"-Hinweis (beide Steuer-Varianten). */
	const VERPACKUNG_TIP = 'Nach Erhalt Ihrer Anfrage errechnen wir Ihnen ein Angebot, welches die Kosten für Verpackung und Versand beinhaltet.';

	/** Tooltip-Text am Netto-Hinweis „Export & EU-B2B". */
	const NETTO_EXPORT_TIP = 'Kunden aus Drittländern und Geschäftskunden aus bestätigten EU-Ländern, in die wir liefern, können netto bei uns kaufen.';

	/**
	 * Liefert die note-Bausteine fuer das neue Tooltip-System (Paket B).
	 * Detail-Template baut daraus den finalen `<button>`-Tooltip.
	 *
	 * @param string $modus 'regel'|'paragraf25a'
	 * @return array{lead:string, vut_label:string, vut_tip:string, trail:string}
	 */
	public static function note_parts( $modus ) {
		$modus = ( 'paragraf25a' === $modus ) ? 'paragraf25a' : 'regel';
		return array(
			'lead'      => ( 'paragraf25a' === $modus )
				? 'Differenzbesteuert nach §25a UStG, MwSt. nicht ausweisbar, zzgl. '
				: 'inkl. 19 % MwSt., zzgl. ',
			'vut_label' => 'Verpackung & Transport',
			'vut_tip'   => self::VERPACKUNG_TIP,
			'trail'     => '.',
		);
	}

	/** Deutsche Währungsformatierung. */
	public static function format( $value ) {
		return number_format( (float) $value, 2, ',', '.' ) . ' €';
	}

	/**
	 * Varianten-Label saeubern: literal \uXXXX-Escapes → echtes Zeichen (z.B.
	 * „Stoßstangen" → „Stoßstangen"). Quelle + Render nutzen dies (eine Stelle).
	 */
	public static function clean_label( $s ) {
		$s = (string) $s;
		$cb = function ( $m ) { return mb_convert_encoding( pack( 'H*', $m[1] ), 'UTF-8', 'UTF-16BE' ); };
		// 1) Backslash-Form \uXXXX (eindeutig Escape) → Zeichen.
		if ( false !== strpos( $s, '\\u' ) ) {
			$s = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', $cb, $s );
		}
		// 2) Backslash-LOSE Form (Prod-Daten haben den Backslash verloren). Konservativ NUR
		//    Latin-1-Supplement u00XX → deckt deutsche Umlaute/ß ab, keine Falsch-Treffer bei u+Hex.
		if ( preg_match( '/u00[0-9a-fA-F]{2}/', $s ) ) {
			$s = preg_replace_callback( '/u(00[0-9a-fA-F]{2})/', $cb, $s );
		}
		return trim( (string) $s );
	}

	/**
	 * EINMALIGE Reparatur bereits gespeicherter Varianten-Labels: dekodiert u-Escapes
	 * (mit/ohne Backslash) in `_m24_preisoptionen` und schreibt geaenderte Posts zurueck.
	 * Gezielt ueber meta LIKE '%u00%' → nur betroffene Posts. Idempotent.
	 *
	 * @return array { scanned:int, fixed:int }
	 */
	public static function repair_labels() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_m24_preisoptionen' AND meta_value LIKE '%u00%'" ); // phpcs:ignore WordPress.DB
		$scanned = 0; $fixed = 0;
		foreach ( (array) $rows as $row ) {
			$scanned++;
			$arr = json_decode( (string) $row->meta_value, true );
			if ( ! is_array( $arr ) ) { continue; }
			$dirty = false;
			foreach ( $arr as &$opt ) {
				if ( ! is_array( $opt ) || ! isset( $opt['label'] ) ) { continue; }
				$clean = self::clean_label( (string) $opt['label'] );
				if ( $clean !== (string) $opt['label'] ) { $opt['label'] = $clean; $dirty = true; }
			}
			unset( $opt );
			// JSON_UNESCAPED_UNICODE: echte Zeichen speichern — sonst entfernt update_post_meta()
			// via wp_unslash() den Backslash aus ü und die bare Form „u00fc" bliebe bestehen.
			if ( $dirty ) { update_post_meta( (int) $row->post_id, '_m24_preisoptionen', wp_json_encode( $arr, JSON_UNESCAPED_UNICODE ) ); $fixed++; }
		}
		return array( 'scanned' => $scanned, 'fixed' => $fixed );
	}

	/**
	 * Liest und parsed `_m24_preisoptionen`. Liefert IMMER ein Array (ggf. leer).
	 * Jede Option ist normalisiert auf {label, art_nr, netto|null, brutto}.
	 */
	public static function raw_options( $post_id ) {
		$raw = get_post_meta( $post_id, '_m24_preisoptionen', true );
		if ( '' === $raw || null === $raw ) {
			return array();
		}
		$arr = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $arr ) ) {
			return array();
		}
		$out = array();
		foreach ( $arr as $opt ) {
			if ( ! is_array( $opt ) ) { continue; }
			$out[] = array(
				'label'  => isset( $opt['label'] )  ? self::clean_label( (string) $opt['label'] )  : '',
				'art_nr' => isset( $opt['art_nr'] ) ? (string) $opt['art_nr'] : '',
				'netto'  => ( isset( $opt['netto'] ) && '' !== $opt['netto'] && null !== $opt['netto'] ) ? (float) $opt['netto'] : null,
				'brutto' => ( isset( $opt['brutto'] ) && '' !== $opt['brutto'] && null !== $opt['brutto'] ) ? (float) $opt['brutto'] : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Liefert ein Array mit allen Preisoptionen, mit formatierten Strings:
	 *  [
	 *    'modus'        => 'regel'|'paragraf25a',
	 *    'note'         => Hinweistext (mit Verpackung-Tip),
	 *    'netto_hinweis'=> ggf. Drittland/EU-B2B-Hinweis,
	 *    'options' => [
	 *      ['label', 'art_nr', 'netto'|null, 'netto_fmt'|null, 'brutto', 'brutto_fmt']
	 *    ],
	 *    'agg' => [ 'low' => float, 'high' => float ]   // nur wenn >1 Option
	 *  ]
	 */
	public static function get_options( $post_id ) {
		$modus = get_post_meta( $post_id, '_m24_mwst_modus', true );
		$modus = ( 'paragraf25a' === $modus ) ? 'paragraf25a' : 'regel';

		$verp = '<span class="m24-tip" title="' . esc_attr( self::VERPACKUNG_TIP ) . '">Verpackung &amp; Transport</span>';

		$opts_raw = self::raw_options( $post_id );

		// Backward-Compat: wenn _m24_preisoptionen leer → aus _m24_preis_netto bauen
		if ( empty( $opts_raw ) ) {
			$basis = (float) get_post_meta( $post_id, '_m24_preis_netto', true );
			if ( $basis > 0 ) {
				if ( 'paragraf25a' === $modus ) {
					$opts_raw = array( array( 'label' => '', 'art_nr' => (string) get_post_meta( $post_id, '_m24_artikelnummer', true ), 'netto' => null, 'brutto' => $basis ) );
				} else {
					$opts_raw = array( array( 'label' => '', 'art_nr' => (string) get_post_meta( $post_id, '_m24_artikelnummer', true ), 'netto' => $basis, 'brutto' => round( $basis * ( 1 + self::MWST_SATZ ), 2 ) ) );
				}
			}
		}

		$options = array();
		foreach ( $opts_raw as $o ) {
			$options[] = array(
				'label'      => $o['label'],
				'art_nr'     => $o['art_nr'],
				'netto'      => $o['netto'],
				'netto_fmt'  => ( null !== $o['netto'] ) ? self::format( $o['netto'] ) : null,
				'brutto'     => $o['brutto'],
				'brutto_fmt' => self::format( $o['brutto'] ),
			);
		}

		if ( 'paragraf25a' === $modus ) {
			$note          = 'Differenzbesteuert nach §25a UStG, MwSt. nicht ausweisbar, zzgl. ' . $verp . '.';
			$netto_hinweis = '';
		} else {
			$note          = 'inkl. 19 % MwSt., zzgl. ' . $verp . '.';
			$netto_hinweis = 'Kunden aus Drittländern und Geschäftskunden aus bestätigten EU-Ländern, in die wir liefern, können netto bei uns kaufen.';
		}

		$agg = null;
		if ( count( $options ) > 1 ) {
			$brutto_values = array_map( function( $o ) { return (float) $o['brutto']; }, $options );
			$agg = array(
				'low'  => min( $brutto_values ),
				'high' => max( $brutto_values ),
			);
		}

		return array(
			'modus'         => $modus,
			'note'          => $note,
			'netto_hinweis' => $netto_hinweis,
			'options'       => $options,
			'agg'           => $agg,
		);
	}

	/**
	 * Backward-Compat: liefert die Default-Option (erste) im alten Shape.
	 * Bestehende Templates/Admin-Liste laufen weiter ohne Anpassung.
	 */
	public static function get( $post_id ) {
		$data    = self::get_options( $post_id );
		$default = isset( $data['options'][0] ) ? $data['options'][0] : null;

		if ( ! $default ) {
			return array(
				'modus'         => $data['modus'],
				'brutto'        => 0,
				'brutto_fmt'    => self::format( 0 ),
				'netto'         => null,
				'netto_fmt'     => null,
				'note'          => $data['note'],
				'netto_hinweis' => '',
			);
		}

		return array(
			'modus'         => $data['modus'],
			'brutto'        => $default['brutto'],
			'brutto_fmt'    => $default['brutto_fmt'],
			'netto'         => $default['netto'],
			'netto_fmt'     => $default['netto_fmt'],
			'note'          => $data['note'],
			'netto_hinweis' => ( null !== $default['netto'] ) ? $data['netto_hinweis'] : '',
		);
	}
}

// Preis-Sortierung: greift NUR bei Queries mit Query-Var `m24_price_sort` (sonst No-Op).
add_filter( 'posts_clauses', array( 'M24_Catalog_Pricing', 'price_sort_clauses' ), 10, 2 );
