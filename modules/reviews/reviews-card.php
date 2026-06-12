<?php
/**
 * M24 Plattform — Bewertungs-Karte (Trust-Element, Variante C)
 * Modul: modules/reviews/reviews-card.php
 *
 * Kompakte Karte fuer die Teile-Detailseite (rechts im Beschreibungsbereich):
 * Sterne + Schnitt + Anzahl, ein zufaellig gewaehltes kuriertes Zitat + Name/Ort,
 * Link „Alle Google-Bewertungen". Reine ANZEIGE — KEIN Review-/AggregateRating-Schema.
 *
 * Konfiguration via Admin (Option m24_reviews_settings): Schnitt, Anzahl, Google-Link,
 * bis zu 5 kurierte echte Bewertungen. Zufalls-Auswahl pro Seitenaufruf erfolgt
 * clientseitig (Detail-Inline-JS) — cache-fest (WP Rocket friert sonst ein Zitat ein).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Reviews_Card {

	const OPTION    = 'm24_reviews_settings';
	const MAX_ITEMS = 5;

	public static function config() {
		$d = array( 'avg' => '', 'count' => 0, 'url' => '', 'items' => array() );
		$o = get_option( self::OPTION, array() );
		if ( ! is_array( $o ) ) { return $d; }
		return array(
			'avg'   => isset( $o['avg'] ) ? (string) $o['avg'] : '',
			'count' => isset( $o['count'] ) ? (int) $o['count'] : 0,
			'url'   => isset( $o['url'] ) ? (string) $o['url'] : '',
			'items' => isset( $o['items'] ) && is_array( $o['items'] ) ? $o['items'] : array(),
		);
	}

	/** Nur Bewertungen mit nicht-leerem Zitat. */
	public static function valid_items( $cfg ) {
		$out = array();
		foreach ( (array) $cfg['items'] as $it ) {
			if ( '' !== trim( (string) ( $it['quote'] ?? '' ) ) ) {
				$out[] = $it;
			}
		}
		return array_slice( $out, 0, self::MAX_ITEMS );
	}

	/** Karten-HTML oder '' wenn nicht konfiguriert. */
	public static function render_card() {
		$cfg = self::config();
		$items = self::valid_items( $cfg );
		$avg_f = (float) str_replace( ',', '.', $cfg['avg'] );
		if ( empty( $items ) || $avg_f <= 0 ) {
			return '';
		}
		$pct     = max( 0, min( 100, $avg_f / 5 * 100 ) );
		$avg_disp = number_format_i18n( $avg_f, 1 );

		ob_start(); ?>
		<aside class="m24-review-card" aria-label="<?php esc_attr_e( 'Kundenbewertungen', 'm24-plattform' ); ?>">
			<div class="m24-rc-head">
				<span class="m24-stars" aria-hidden="true">★★★★★<span class="m24-stars-fill" style="width:<?php echo esc_attr( round( $pct, 1 ) ); ?>%">★★★★★</span></span>
				<span class="m24-rc-avg"><?php echo esc_html( $avg_disp ); ?></span>
			</div>
			<?php if ( $cfg['count'] > 0 ) : ?>
				<div class="m24-rc-count"><?php echo esc_html( sprintf( _n( 'aus %s Google-Bewertung', 'aus %s Google-Bewertungen', $cfg['count'], 'm24-plattform' ), number_format_i18n( $cfg['count'] ) ) ); ?></div>
			<?php endif; ?>

			<div class="m24-rc-quotes">
				<?php foreach ( $items as $i => $it ) : ?>
					<figure class="m24-rc-item<?php echo 0 === $i ? ' is-active' : ''; ?>">
						<blockquote class="m24-rc-quote">„<?php echo esc_html( trim( (string) $it['quote'] ) ); ?>"</blockquote>
						<figcaption class="m24-rc-author">— <?php echo esc_html( trim( (string) ( $it['name'] ?? '' ) ) ); ?><?php if ( '' !== trim( (string) ( $it['ort'] ?? '' ) ) ) : ?>, <?php echo esc_html( trim( (string) $it['ort'] ) ); ?><?php endif; ?></figcaption>
					</figure>
				<?php endforeach; ?>
			</div>

			<?php if ( '' !== $cfg['url'] ) : ?>
				<a class="m24-rc-link" href="<?php echo esc_url( $cfg['url'] ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'Alle Google-Bewertungen', 'm24-plattform' ); ?> →</a>
			<?php endif; ?>
		</aside>
		<?php
		return ob_get_clean();
	}
}
