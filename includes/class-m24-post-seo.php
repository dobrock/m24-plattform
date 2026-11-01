<?php
/**
 * M24 Plattform — SEO-Titel und -Beschreibung für REGULÄRE BEITRÄGE (post_type 'post')
 * Modul: includes/class-m24-post-seo.php
 *
 * Ausgangslage (01.11.2026): wpSEO nimmt bei Beiträgen ohne eigene Eingabe einfach den
 * Beitragstitel (ohne Marke) und die ersten ~150 Zeichen des Inhalts – oft einen
 * Standard-Einleitungssatz („Als Spezialist für klassische BMW …"). Verkaufsbeiträge
 * („FOR SALE: …", „SOLD: …") sind nicht auf „kaufen" optimiert.
 *
 * Regeln – NUR wenn im wpSEO-Feld des Beitrags nichts eingetragen ist
 * (_wpseo_edit_title / _wpseo_edit_description); manuelle Eingaben haben immer Vorrang:
 *  - Verkauf (Titel beginnt mit FOR SALE/COMING UP FOR SALE/RESERVIERT … oder Text enthält
 *    „Verkauft wird"/„Zum Verkauf steht"/„Wir verkaufen"):  Titel „{Fahrzeug} kaufen | MOTORSPORT24",
 *    Beschreibung „Zu verkaufen: {Fahrzeug}. {Inhalt} Jetzt anfragen – Händler seit 2006 …"
 *  - Verkauft (SOLD/VERKAUFT):  Titel „{Fahrzeug} – verkauft | MOTORSPORT24",
 *    Beschreibung „Verkauft: {Fahrzeug}. {Inhalt} Ähnliches Fahrzeug gesucht? Jetzt anfragen."
 *  - Blog:  Titel „{Titel} | MOTORSPORT24", Beschreibung = erste aussagekräftige Sätze
 *    (Standard-Einleitungssätze werden übersprungen).
 * Die Marke wird weggelassen, wenn der Titel sonst über 65 Zeichen käme.
 * Abschaltbar über Filter 'm24_post_seo_enabled' (false).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Post_SEO {

	const BRAND     = ' | MOTORSPORT24';
	const MAX_TITLE = 65;
	const MAX_DESC  = 158;

	public static function init() {
		add_filter( 'wpseo_set_title', array( __CLASS__, 'title' ), 50 );
		add_filter( 'wpseo_set_desc', array( __CLASS__, 'desc' ), 50 );
	}

	private static function post_id() {
		if ( is_admin() || ! is_singular( 'post' ) ) { return 0; }
		if ( ! apply_filters( 'm24_post_seo_enabled', true ) ) { return 0; }
		return (int) get_queried_object_id();
	}

	/** Art des Beitrags: 'sale' | 'sold' | 'blog' + bereinigter Fahrzeug-/Beitragsname. */
	private static function kind( $id ) {
		$t = trim( wp_strip_all_tags( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( preg_match( '/^\s*(SOLD|VERKAUFT)\s*[:\-–]\s*(.+)$/iu', $t, $m ) ) { return array( 'sold', trim( $m[2] ) ); }
		if ( preg_match( '/^\s*(COMING UP FOR SALE|FOR SALE|ZU VERKAUFEN|RESERVED|RESERVIERT)\s*[:\-–]\s*(.+)$/iu', $t, $m ) ) { return array( 'sale', trim( $m[2] ) ); }
		// Ohne Präfix: nur wenn der Text selbst ein Verkaufsangebot ist (Kategorie allein ist zu unzuverlässig).
		$c = wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) );
		if ( preg_match( '/\b(Verkauft wird|Zum Verkauf steht|Wir verkaufen)\b/iu', $c ) ) { return array( 'sale', $t ); }
		return array( 'blog', $t );
	}

	private static function with_brand( $s ) {
		return ( mb_strlen( $s . self::BRAND ) <= self::MAX_TITLE ) ? $s . self::BRAND : $s;
	}

	public static function title( $title ) {
		$id = self::post_id();
		if ( ! $id || '' !== trim( (string) get_post_meta( $id, '_wpseo_edit_title', true ) ) ) { return $title; }
		list( $k, $name ) = self::kind( $id );
		if ( '' === $name ) { return $title; }
		if ( 'sale' === $k ) { return self::with_brand( $name . ' kaufen' ); }
		if ( 'sold' === $k ) { return self::with_brand( $name . ' – verkauft' ); }
		return self::with_brand( $name );
	}

	public static function desc( $desc ) {
		$id = self::post_id();
		if ( ! $id || '' !== trim( (string) get_post_meta( $id, '_wpseo_edit_description', true ) ) ) { return $desc; }
		list( $k, $name ) = self::kind( $id );
		if ( 'sale' === $k ) {
			$pre = 'Zu verkaufen: ' . $name . '. ';
			$suf = ' Jetzt anfragen – Händler seit 2006, weltweiter Transport.';
		} elseif ( 'sold' === $k ) {
			$pre = 'Verkauft: ' . $name . '. ';
			$suf = ' Ähnliches Fahrzeug gesucht? Jetzt anfragen.';
		} else {
			$pre = '';
			$suf = '';
		}
		$room = self::MAX_DESC - mb_strlen( $pre ) - mb_strlen( $suf );
		$body = $room > 30 ? self::snippet( $id, $room, $name ) : '';
		$out  = trim( $pre . $body . $suf );
		return '' !== $out ? $out : $desc;
	}

	/** Erste aussagekräftige Sätze des Inhalts, auf $max Zeichen gekürzt (Wortgrenze). */
	private static function snippet( $id, $max, $name ) {
		$raw = (string) get_post_field( 'post_content', $id );
		$raw = strip_shortcodes( $raw );
		$raw = preg_replace( '/\[[^\]]*\]/', ' ', $raw ); // Reste fremder Shortcodes (Page-Builder)
		$raw = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $raw );
		$raw = preg_replace( '#</(p|div|h[1-6]|li|br)>|<br\s*/?>#i', '. ', $raw );
		$txt = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' );
		$txt = trim( preg_replace( '/\s+/u', ' ', $txt ) );
		$txt = preg_replace( '/(\.\s*){2,}/u', '. ', $txt );
		$skip = '/(Als Spezialist für klassische BMW|Nicht nur das Fachwissen|Senden Sie uns eine Nachricht|Kontaktieren Sie uns|^\s*Verkauft wird\s*$)/iu';
		$out  = '';
		foreach ( preg_split( '/(?<=[.!?])\s+/u', $txt ) as $s ) {
			$s = trim( $s, " .\t\n" );
			if ( mb_strlen( $s ) < 20 || preg_match( $skip, $s ) ) { continue; }
			if ( '' !== $name && 0 === mb_stripos( $s, $name ) && mb_strlen( $s ) <= mb_strlen( $name ) + 3 ) { continue; } // nur der Titel
			$cand = '' === $out ? $s . '.' : $out . ' ' . $s . '.';
			if ( mb_strlen( $cand ) > $max ) {
				if ( '' === $out ) { $out = self::cut( $s, $max ); }
				break;
			}
			$out = $cand;
		}
		return $out;
	}

	private static function cut( $s, $max ) {
		if ( mb_strlen( $s ) <= $max ) { return $s; }
		$c = mb_substr( $s, 0, $max - 1 );
		$p = mb_strrpos( $c, ' ' );
		return rtrim( false !== $p ? mb_substr( $c, 0, $p ) : $c, ' ,;:–-' ) . '…';
	}
}
