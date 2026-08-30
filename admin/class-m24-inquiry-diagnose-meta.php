<?php
/**
 * M24 — Anfrage-Diagnose: Postmeta-Key-Listen + Vergleich.
 * Modul: admin/class-m24-inquiry-diagnose-meta.php
 *
 * Reine Leseansicht (manage_options). Teil b) der Diagnose-Seite listet ALLE
 * Postmeta-Keys einer Anfrage mit auf 300 Zeichen gekuerzten Werten, Teil c)
 * stellt zwei Anfragen nebeneinander und markiert einseitige Keys.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Inquiry_Diagnose_Meta {

	const CUT = 300;

	/**
	 * Alle Postmeta-Keys einer Anfrage als key => gekuerzter Wert.
	 * Leeres Array, wenn der Post nicht existiert.
	 */
	public static function keys( int $post_id ): array {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) { return array(); }
		$raw = get_post_meta( $post_id );
		if ( ! is_array( $raw ) ) { return array(); }
		$out = array();
		foreach ( $raw as $key => $values ) {
			$parts = array();
			foreach ( (array) $values as $v ) { $parts[] = self::fmt( maybe_unserialize( $v ) ); }
			$out[ (string) $key ] = self::cut( implode( ' | ', $parts ) );
		}
		ksort( $out );
		return $out;
	}

	private static function fmt( $value ): string {
		if ( is_scalar( $value ) || null === $value ) { return (string) $value; }
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '(nicht darstellbar)' : (string) $json;
	}

	private static function cut( string $s ): string {
		return mb_strlen( $s ) > self::CUT ? mb_substr( $s, 0, self::CUT ) . ' …' : $s;
	}

	/** Kopfzeile mit Titel/Status der Anfrage — ordnet die ID sofort ein. */
	private static function head( int $post_id ): string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) { return 'Anfrage #' . $post_id . ' — nicht gefunden'; }
		return 'Anfrage #' . $post_id . ' — ' . $post->post_title . ' (' . $post->post_type . ', ' . $post->post_status . ')';
	}

	/** b) Einzelansicht: alle Keys einer Anfrage. */
	public static function render_single( int $post_id ): void {
		$keys = self::keys( $post_id );
		echo '<h2>' . esc_html( self::head( $post_id ) ) . '</h2>';
		if ( empty( $keys ) ) {
			echo '<p><em>Keine Postmeta-Eintraege (oder Anfrage-ID unbekannt).</em></p>';
			return;
		}
		echo '<p style="color:#646970;">' . (int) count( $keys ) . ' Keys, Werte auf ' . (int) self::CUT . ' Zeichen gekuerzt.</p>';
		echo '<table class="widefat striped"><thead><tr><th style="width:260px;">Key</th><th>Wert</th></tr></thead><tbody>';
		foreach ( $keys as $k => $v ) {
			echo '<tr><td><code>' . esc_html( $k ) . '</code></td>'
				. '<td style="word-break:break-word;font-size:12px;">' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** c) Vergleich: beide Key-Listen nebeneinander, einseitige Keys markiert. */
	public static function render_diff( int $a_id, int $b_id ): void {
		$a = self::keys( $a_id );
		$b = self::keys( $b_id );

		$all = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
		sort( $all );

		$only_a = count( array_diff( array_keys( $a ), array_keys( $b ) ) );
		$only_b = count( array_diff( array_keys( $b ), array_keys( $a ) ) );

		echo '<h2>Vergleich</h2>';
		echo '<p style="color:#646970;">' . esc_html( self::head( $a_id ) ) . ' &nbsp;↔&nbsp; ' . esc_html( self::head( $b_id ) ) . '<br>'
			. 'Nur links: <strong>' . (int) $only_a . '</strong> &middot; nur rechts: <strong>' . (int) $only_b . '</strong> &middot; gemeinsam: '
			. (int) ( count( $all ) - $only_a - $only_b ) . '</p>';

		if ( empty( $all ) ) {
			echo '<p><em>Beide Anfragen haben keine Postmeta-Eintraege (oder die IDs sind unbekannt).</em></p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:240px;">Key</th>'
			. '<th>#' . (int) $a_id . '</th>'
			. '<th>#' . (int) $b_id . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $all as $k ) {
			$in_a = array_key_exists( $k, $a );
			$in_b = array_key_exists( $k, $b );
			$mark = ( $in_a && $in_b ) ? '' : ' style="background:#fcf3d4;"';
			$badge = '';
			if ( ! $in_b ) { $badge = ' <span style="color:#8a6d00;font-size:11px;">nur #' . (int) $a_id . '</span>'; }
			if ( ! $in_a ) { $badge = ' <span style="color:#8a6d00;font-size:11px;">nur #' . (int) $b_id . '</span>'; }
			echo '<tr' . $mark . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td><code>' . esc_html( (string) $k ) . '</code>' . $badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td style="word-break:break-word;font-size:12px;">' . ( $in_a ? esc_html( $a[ $k ] ) : '<span style="color:#b32d2e;">—</span>' ) . '</td>';
			echo '<td style="word-break:break-word;font-size:12px;">' . ( $in_b ? esc_html( $b[ $k ] ) : '<span style="color:#b32d2e;">—</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
