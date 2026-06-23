<?php
/**
 * M24 Plattform — Fahrzeug-Alert: Taxonomie + Rollup (Fundament, keine UI, kein Versand)
 *
 * Zentrale, per Filter `m24_alert_taxonomie` überschreibbare Tag-Landkarte für den
 * Fahrzeug-Alert auf Basis der Interessentenliste (Brevo). Je Tag:
 *   slug, label, ebene (modell|marke|art|global), marke (bmw|porsche|null),
 *   brevo_list_name = "M24 Alert · " + label.
 *
 * Rollup: aus Fahrzeug-/Teil-Eigenschaften (marke, modell/baureihe-slug, nutzungsart)
 * → Set zutreffender Tag-Slugs (Leaf + marke-alle + art + global).
 * Beispiel E30-Renner → [bmw-e30, bmw-alle, rennsport, alle].
 *
 * Reines Daten-/Logikmodul: keine Netzwerkaufrufe, keine Hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Alert_Taxonomie {

	const LIST_NAME_PREFIX = 'M24 Alert · ';
	const FOLDER_NAME      = 'M24 Alert';

	/**
	 * Vollständige Tag-Landkarte (slug → [label, ebene, marke, brevo_list_name]).
	 * Filterbar via `m24_alert_taxonomie`.
	 */
	public static function tags() {
		$defs = array(
			// Modell — BMW
			'bmw-e30'          => array( 'BMW E30',              'modell', 'bmw' ),
			'bmw-e36'          => array( 'BMW E36',              'modell', 'bmw' ),
			'bmw-e46'          => array( 'BMW E46',              'modell', 'bmw' ),
			'bmw-e9x'          => array( 'BMW E9x',              'modell', 'bmw' ),
			'bmw-f'            => array( 'BMW F-Modell',         'modell', 'bmw' ),
			'bmw-g'            => array( 'BMW G-Modell',         'modell', 'bmw' ),
			// Modell — Porsche
			'porsche-911f'     => array( 'Porsche 911 F-Modell', 'modell', 'porsche' ),
			'porsche-911g'     => array( 'Porsche 911 G-Modell', 'modell', 'porsche' ),
			'porsche-964'      => array( 'Porsche 964',          'modell', 'porsche' ),
			'porsche-993'      => array( 'Porsche 993',          'modell', 'porsche' ),
			'porsche-996'      => array( 'Porsche 996',          'modell', 'porsche' ),
			'porsche-997'      => array( 'Porsche 997',          'modell', 'porsche' ),
			'porsche-991'      => array( 'Porsche 991',          'modell', 'porsche' ),
			'porsche-992'      => array( 'Porsche 992',          'modell', 'porsche' ),
			'porsche-sonstige' => array( 'Porsche Sonstige',     'modell', 'porsche' ),
			// Marke
			'bmw-alle'         => array( 'Alle BMW',             'marke',  'bmw' ),
			'porsche-alle'     => array( 'Alle Porsche',         'marke',  'porsche' ),
			// Art
			'strasse'          => array( 'Alle Straßenfahrzeuge', 'art',   null ),
			'rennsport'        => array( 'Alle Rennfahrzeuge',    'art',   null ),
			// Global
			'alle'             => array( 'Alle Fahrzeuge',        'global', null ),
		);

		$out = array();
		foreach ( $defs as $slug => $d ) {
			$out[ $slug ] = array(
				'slug'            => $slug,
				'label'           => $d[0],
				'ebene'           => $d[1],
				'marke'           => $d[2],
				'brevo_list_name' => self::LIST_NAME_PREFIX . $d[0],
			);
		}
		return apply_filters( 'm24_alert_taxonomie', $out );
	}

	/** Existiert dieser Tag in der (gefilterten) Landkarte? */
	public static function is_valid( $slug ) {
		$tags = self::tags();
		return isset( $tags[ $slug ] );
	}

	/* =====================================================================
	 * Rollup
	 * ================================================================== */

	/**
	 * Eingang normalisierte Eigenschaften → Set zutreffender Tag-Slugs.
	 *
	 * @param string      $marke        'bmw'|'porsche'|'' (normalisiert)
	 * @param string|null $modell_slug  Leaf-Slug (z. B. 'bmw-e30') oder null
	 * @param string      $nutzungsart  'strasse'|'rennsport'|''
	 * @return string[]   eindeutige, gültige Tag-Slugs
	 */
	public static function rollup( $marke, $modell_slug, $nutzungsart ) {
		$marke = self::normalize_marke( $marke );
		$tags  = array();

		if ( $modell_slug ) {
			$tags[] = $modell_slug;
		}
		if ( 'bmw' === $marke ) {
			$tags[] = 'bmw-alle';
		} elseif ( 'porsche' === $marke ) {
			$tags[] = 'porsche-alle';
		}
		if ( 'rennsport' === $nutzungsart ) {
			$tags[] = 'rennsport';
		} elseif ( 'strasse' === $nutzungsart ) {
			$tags[] = 'strasse';
		}
		$tags[] = 'alle'; // global immer

		// Nur gültige Slugs, dedupliziert.
		$valid = self::tags();
		$tags  = array_values( array_unique( array_filter( $tags, static function ( $s ) use ( $valid ) {
			return isset( $valid[ $s ] );
		} ) ) );

		return $tags;
	}

	/** Marke aus Freitext normalisieren → 'bmw'|'porsche'|''. */
	public static function normalize_marke( $marke ) {
		$m = mb_strtolower( trim( (string) $marke ) );
		if ( false !== strpos( $m, 'bmw' ) ) {
			return 'bmw';
		}
		if ( false !== strpos( $m, 'porsche' ) ) {
			return 'porsche';
		}
		// Bereits normalisiert übergeben?
		if ( 'bmw' === $m || 'porsche' === $m ) {
			return $m;
		}
		return '';
	}

	/**
	 * Baureihe/Modell-Freitext → Leaf-Slug (oder null). Pro Marke ordnungsabhängige
	 * Regex-Treffer; filterbar via `m24_alert_leaf_map`. Porsche-Fallback: porsche-sonstige.
	 */
	public static function derive_leaf( $marke, $baureihe, $modell ) {
		$marke = self::normalize_marke( $marke );
		if ( '' === $marke ) {
			return null;
		}

		// Normalisierter Heuhaufen: nur a-z0-9, durch Leerzeichen getrennt ("3er E30" → " 3er e30 ").
		$hay = ' ' . mb_strtolower( trim( $baureihe . ' ' . $modell ) ) . ' ';
		$hay = preg_replace( '/[^a-z0-9]+/', ' ', $hay );

		$map = self::leaf_map();
		$sub = isset( $map[ $marke ] ) ? (array) $map[ $marke ] : array();
		foreach ( $sub as $pattern => $leaf ) {
			if ( preg_match( $pattern, $hay ) ) {
				return $leaf;
			}
		}

		// Porsche kennt einen Sammel-Leaf; BMW nicht.
		return ( 'porsche' === $marke ) ? 'porsche-sonstige' : null;
	}

	/**
	 * Konfigurierbare Leaf-Erkennung. Reihenfolge = Priorität (spezifisch zuerst).
	 * Schlüssel = PCRE gegen den normalisierten Heuhaufen, Wert = Leaf-Slug.
	 */
	public static function leaf_map() {
		$map = array(
			'bmw' => array(
				'/\be30\b/'        => 'bmw-e30',
				'/\be36\b/'        => 'bmw-e36',
				'/\be46\b/'        => 'bmw-e46',
				'/\be9[0-9]\b/'    => 'bmw-e9x',
				'/\be9x\b/'        => 'bmw-e9x',
				'/\bf\d{2}\b/'     => 'bmw-f',
				'/\bf modell\b/'   => 'bmw-f',
				'/\bg\d{2}\b/'     => 'bmw-g',
				'/\bg modell\b/'   => 'bmw-g',
			),
			'porsche' => array(
				'/\b964\b/'        => 'porsche-964',
				'/\b993\b/'        => 'porsche-993',
				'/\b996\b/'        => 'porsche-996',
				'/\b997\b/'        => 'porsche-997',
				'/\b991\b/'        => 'porsche-991',
				'/\b992\b/'        => 'porsche-992',
				'/\b911 ?f\b/'     => 'porsche-911f',
				'/\bf modell\b/'   => 'porsche-911f',
				'/\b911 ?g\b/'     => 'porsche-911g',
				'/\bg modell\b/'   => 'porsche-911g',
				'/\bg serie\b/'    => 'porsche-911g',
			),
		);
		return apply_filters( 'm24_alert_leaf_map', $map );
	}

	/* =====================================================================
	 * Integration: Kontext (Inserat/Teil) → Eigenschaften → Tags
	 * ================================================================== */

	/**
	 * Eigenschaften aus dem auslösenden Post ableiten. Fahrzeug: Meta-Felder; sonst best-effort
	 * aus dem Kontakt (kategorien). Filterbar via `m24_alert_context_props`.
	 *
	 * @return array [ 'marke' => string, 'baureihe' => string, 'modell' => string, 'nutzungsart' => string ]
	 */
	public static function props_from_context( $context_id, $contact = array() ) {
		$context_id = (int) $context_id;
		$props      = array( 'marke' => '', 'baureihe' => '', 'modell' => '', 'nutzungsart' => '' );

		if ( $context_id && 'm24_fahrzeug' === get_post_type( $context_id ) ) {
			$props['marke']    = (string) get_post_meta( $context_id, '_m24fz_marke', true );
			$props['baureihe'] = (string) get_post_meta( $context_id, '_m24fz_baureihe', true );
			$props['modell']   = (string) get_post_meta( $context_id, '_m24fz_modell', true );
			$props['nutzungsart'] = ( 'renn' === get_post_meta( $context_id, '_m24fz_template_typ', true ) ) ? 'rennsport' : 'strasse';
		} else {
			// Teile-/Fremdkontext: Nutzungsart aus den Kategorien des Kontakts ableiten.
			$katz = array_map( 'mb_strtolower', (array) ( $contact['kategorien'] ?? array() ) );
			if ( in_array( 'sport', $katz, true ) ) {
				$props['nutzungsart'] = 'rennsport';
			} elseif ( $katz ) {
				$props['nutzungsart'] = 'strasse';
			}
			// Marke best-effort aus den Modell-Strings.
			$props['baureihe'] = implode( ' ', (array) ( $contact['modelle'] ?? array() ) );
		}

		return apply_filters( 'm24_alert_context_props', $props, $context_id, $contact );
	}

	/**
	 * Komfort: auslösender Kontext → fertiges Tag-Set für den Pending-Record.
	 * Filterbar via `m24_alert_tags` (letztes Wort, z. B. für Sonderfälle).
	 */
	public static function tags_for_context( $context_id, $contact = array() ) {
		$p     = self::props_from_context( $context_id, $contact );
		$marke = self::normalize_marke( $p['marke'] ?: $p['baureihe'] );
		$leaf  = self::derive_leaf( $marke, $p['baureihe'], $p['modell'] );
		$tags  = self::rollup( $marke, $leaf, $p['nutzungsart'] );
		return apply_filters( 'm24_alert_tags', $tags, $context_id, $contact, $p );
	}
}
