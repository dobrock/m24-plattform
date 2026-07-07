<?php
/**
 * M24 Plattform — Verkauft-Ansicht: Alternativen-Block (Variante 3, zweispaltig)
 * Modul: modules/sold/sold-alternatives.php
 *
 * Liefert + rendert fuer ein verkauftes Teil:
 *  - links  „Ähnliche Teile": VERFUEGBARE (status=aktiv) Teile gleichen Modells
 *           (m24_fahrzeugkat), ohne sich selbst. Fallback: Link aufs Modell-Archiv.
 *  - rechts „Passende Fahrzeuge": Beitraege aus den 4 Fahrzeug-Kategorien, deren
 *           Titel zum Modell passt (Fahrzeuge tragen ihr Modell im Titel/der Kategorie).
 *           For-Sale/Sold gekennzeichnet. Keine Treffer → dezenter Archiv-Link.
 *
 * Wird inline auf der Detailseite gerendert (immer sichtbar) UND vom Lightbox-JS geklont.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Sold_Alternatives {

	const PT  = 'm24_teil';
	const TAX = 'm24_fahrzeugkat';

	/** Verfuegbare aehnliche Teile gleichen Modells (ohne self/verkauft). */
	public static function similar_parts( $post_id, $limit = 6 ) {
		$terms = get_the_terms( $post_id, self::TAX );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array( 'items' => array(), 'archive' => '', 'model' => '' );
		}
		$term_ids = wp_list_pluck( $terms, 'term_id' );
		$primary  = $terms[0];

		$q = new WP_Query( array(
			'post_type'           => self::PT,
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, (int) $limit ),
			'post__not_in'        => array( (int) $post_id ),
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array( array( 'taxonomy' => self::TAX, 'terms' => $term_ids ) ),
			'meta_query'          => array( array( 'key' => '_m24_status', 'value' => 'aktiv' ) ), // nur verfuegbar
		) );
		$items = array();
		foreach ( $q->posts as $p ) {
			$anfrage = (bool) get_post_meta( $p->ID, '_m24_preis_auf_anfrage', true );
			$pr = ( ! $anfrage && class_exists( 'M24_Catalog_Pricing' ) ) ? M24_Catalog_Pricing::get( $p->ID ) : array();
			$items[] = array(
				'title' => html_entity_decode( get_the_title( $p->ID ), ENT_QUOTES, 'UTF-8' ),
				'url'   => get_permalink( $p->ID ),
				'thumb' => self::thumb( $p->ID ),
				'price' => isset( $pr['brutto_fmt'] ) ? (string) $pr['brutto_fmt'] : '',
				'tax'   => ( ! $anfrage && isset( $pr['brutto_fmt'] ) ) ? m24_tax_label( $p->ID ) : '', // §25a/19 % aus der EINEN Quelle
			);
		}
		wp_reset_postdata();
		return array(
			'items'   => $items,
			'archive' => add_query_arg( 'm24_modell', $primary->slug, home_url( '/gebrauchtteile/' ) ),
			'model'   => $primary->name,
		);
	}

	/** Passende Fahrzeuge: Beitraege der 4 Kategorien, Titel-Match aufs Modell. */
	public static function matching_vehicles( $post_id, $limit = 3 ) {
		$terms = get_the_terms( $post_id, self::TAX );
		if ( ! $terms || is_wp_error( $terms ) || ! class_exists( 'M24_Search_Query' ) ) {
			return array( 'items' => array(), 'archive' => self::vehicles_archive(), 'model' => '' );
		}
		$model    = trim( (string) $terms[0]->name );
		$cat_ids  = M24_Search_Query::fahrzeug_cat_ids();
		$sold_ids = M24_Search_Query::fahrzeug_sold_cat_ids();
		// Nur AKTIVE Angebote: For-Sale-Kategorien (Sold-Kategorien ausschliessen).
		$active_ids = array_values( array_diff( $cat_ids, $sold_ids ) );
		if ( empty( $active_ids ) || '' === $model ) {
			return array( 'items' => array(), 'archive' => self::vehicles_archive(), 'model' => $model );
		}
		$limit = max( 1, (int) $limit );
		// Modell-/titelbasiert statt Volltext: aktive For-Sale-Fahrzeuge holen, dann per Titel-Substring
		// strikt aufs Modell filtern (verhindert unscharfe Volltext-Treffer wie Porsche ↔ „BMW 1er").
		$q = new WP_Query( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'category__in'        => $active_ids,
			'posts_per_page'      => 60, // Pool zum Filtern
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			// Zusaetzlich per Meta als verkauft markierte Fahrzeuge ausschliessen (kein SOLD mehr).
			'meta_query'          => array(
				'relation' => 'OR',
				array( 'key' => '_m24_fahrzeug_verkauft', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_m24_fahrzeug_verkauft', 'value' => '1', 'compare' => '!=' ),
			),
		) );
		$needle = self::norm( $model );
		$items  = array();
		foreach ( $q->posts as $p ) {
			$title = html_entity_decode( get_the_title( $p->ID ), ENT_QUOTES, 'UTF-8' );
			// Sicherheitsnetz: als „SOLD …" betitelte Fahrzeuge nie zeigen (falls Kategorie/Meta ungepflegt).
			if ( preg_match( '/^\s*sold\b/i', $title ) ) { continue; }
			// Modell MUSS im Fahrzeugtitel vorkommen (strikt, kein Volltext-Fuzzy).
			if ( '' === $needle || false === strpos( self::norm( $title ), $needle ) ) { continue; }
			$items[] = array(
				'title' => $title,
				'url'   => get_permalink( $p->ID ),
				'thumb' => self::thumb( $p->ID ),
			);
			if ( count( $items ) >= $limit ) { break; }
		}
		wp_reset_postdata();
		return array( 'items' => $items, 'archive' => self::vehicles_archive(), 'model' => $model );
	}

	/** Normalisiert fuer Substring-Vergleich: lowercase + Mehrfach-Whitespace zu einem Space. */
	private static function norm( $s ) {
		return preg_replace( '/\s+/', ' ', mb_strtolower( trim( (string) $s ) ) );
	}

	private static function vehicles_archive() {
		$cat = get_term_by( 'slug', 'classic-cars-for-sale', 'category' );
		return $cat ? get_category_link( $cat ) : home_url( '/' );
	}

	private static function thumb( $post_id ) {
		$tid = get_post_thumbnail_id( $post_id );
		$url = $tid ? wp_get_attachment_image_url( $tid, 'medium' ) : '';
		return $url ? (string) $url : '';
	}

	/** Card-Markup fuer einen Treffer (Teil oder Fahrzeug). */
	private static function card( $it, $badge = '' ) {
		ob_start(); ?>
		<a class="m24-alt-card" href="<?php echo esc_url( $it['url'] ); ?>">
			<span class="m24-alt-img"><?php if ( ! empty( $it['thumb'] ) ) : ?><img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy"><?php endif; ?></span>
			<span class="m24-alt-b">
				<span class="m24-alt-title"><?php echo esc_html( $it['title'] ); ?></span>
				<?php if ( '' !== $badge ) : ?>
					<span class="m24-alt-badge <?php echo ! empty( $it['sold'] ) ? 'is-sold' : 'is-avail'; ?>"><?php echo esc_html( $badge ); ?></span>
				<?php elseif ( ! empty( $it['price'] ) ) : ?>
					<span class="m24-alt-price"><?php echo esc_html( $it['price'] ); ?></span>
					<?php if ( ! empty( $it['tax'] ) ) : ?><span class="m24-alt-tax"><?php echo $it['tax']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kontrolliertes Label-Markup ?></span><?php endif; ?>
				<?php endif; ?>
			</span>
		</a>
		<?php return ob_get_clean();
	}

	/** Inline-Block (zweispaltig). Wird auch vom Lightbox-JS geklont. */
	public static function render_block( $post_id ) {
		$parts    = self::similar_parts( $post_id );
		$vehicles     = self::matching_vehicles( $post_id );
		$has_vehicles = ! empty( $vehicles['items'] );
		ob_start(); ?>
		<section class="m24-sold-alt" aria-label="<?php esc_attr_e( 'Alternativen', 'm24-plattform' ); ?>">
			<div class="m24-sold-alt-grid<?php echo $has_vehicles ? '' : ' m24-sold-alt-grid--single'; ?>">
				<div class="m24-sold-col m24-sold-col--parts">
					<h3><?php esc_html_e( 'Ähnliche Teile', 'm24-plattform' ); ?></h3>
					<?php if ( ! empty( $parts['items'] ) ) : ?>
						<div class="m24-alt-list">
							<?php foreach ( $parts['items'] as $it ) { echo self::card( $it ); /* phpcs:ignore */ } ?>
						</div>
						<?php if ( '' !== $parts['archive'] ) : ?>
							<a class="m24-alt-all" href="<?php echo esc_url( $parts['archive'] ); ?>"><?php echo esc_html( sprintf( __( 'Alle %s Teile ansehen →', 'm24-plattform' ), $parts['model'] ) ); ?></a>
						<?php endif; ?>
					<?php else : ?>
						<p class="m24-alt-empty"><?php esc_html_e( 'Aktuell keine verfügbaren Teile dieses Modells.', 'm24-plattform' ); ?></p>
						<?php if ( '' !== $parts['archive'] ) : ?>
							<a class="m24-alt-all" href="<?php echo esc_url( $parts['archive'] ); ?>"><?php echo esc_html( sprintf( __( 'Alle %s Teile ansehen →', 'm24-plattform' ), $parts['model'] ) ); ?></a>
						<?php endif; ?>
					<?php endif; ?>
				</div>
				<?php if ( $has_vehicles ) : ?>
				<div class="m24-sold-col m24-sold-col--cars">
					<h3><?php esc_html_e( 'Aktuelle Fahrzeugangebote', 'm24-plattform' ); ?></h3>
					<div class="m24-alt-list">
						<?php foreach ( $vehicles['items'] as $it ) {
							echo self::card( $it, __( 'For Sale', 'm24-plattform' ) ); // phpcs:ignore — nur aktive Angebote, kein SOLD
						} ?>
					</div>
					<a class="m24-alt-all" href="<?php echo esc_url( $vehicles['archive'] ); ?>"><?php esc_html_e( 'Alle aktuellen Fahrzeuge ansehen →', 'm24-plattform' ); ?></a>
				</div>
				<?php endif; ?>
			</div>
		</section>
		<?php return ob_get_clean();
	}
}
