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
			$pr = class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::get( $p->ID ) : array();
			$items[] = array(
				'title' => get_the_title( $p->ID ),
				'url'   => get_permalink( $p->ID ),
				'thumb' => self::thumb( $p->ID ),
				'price' => isset( $pr['brutto_fmt'] ) ? (string) $pr['brutto_fmt'] : '',
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
	public static function matching_vehicles( $post_id, $limit = 6 ) {
		$terms = get_the_terms( $post_id, self::TAX );
		if ( ! $terms || is_wp_error( $terms ) || ! class_exists( 'M24_Search_Query' ) ) {
			return array( 'items' => array(), 'archive' => self::vehicles_archive(), 'model' => '' );
		}
		$model    = $terms[0]->name;
		$cat_ids  = M24_Search_Query::fahrzeug_cat_ids();
		$sold_ids = M24_Search_Query::fahrzeug_sold_cat_ids();
		if ( empty( $cat_ids ) ) {
			return array( 'items' => array(), 'archive' => self::vehicles_archive(), 'model' => $model );
		}
		$q = new WP_Query( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			's'                   => $model,
			'category__in'        => $cat_ids,
			'posts_per_page'      => max( 1, (int) $limit ),
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		) );
		$items = array();
		foreach ( $q->posts as $p ) {
			$cats = wp_get_post_categories( $p->ID );
			$items[] = array(
				'title' => get_the_title( $p->ID ),
				'url'   => get_permalink( $p->ID ),
				'thumb' => self::thumb( $p->ID ),
				'sold'  => ! empty( array_intersect( $cats, $sold_ids ) ),
			);
		}
		wp_reset_postdata();
		return array( 'items' => $items, 'archive' => self::vehicles_archive(), 'model' => $model );
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
				<?php endif; ?>
			</span>
		</a>
		<?php return ob_get_clean();
	}

	/** Inline-Block (zweispaltig). Wird auch vom Lightbox-JS geklont. */
	public static function render_block( $post_id ) {
		$parts    = self::similar_parts( $post_id );
		$vehicles = self::matching_vehicles( $post_id );
		ob_start(); ?>
		<section class="m24-sold-alt" aria-label="<?php esc_attr_e( 'Alternativen', 'm24-plattform' ); ?>">
			<div class="m24-sold-alt-grid">
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
				<div class="m24-sold-col m24-sold-col--cars">
					<h3><?php esc_html_e( 'Passende Fahrzeuge', 'm24-plattform' ); ?></h3>
					<?php if ( ! empty( $vehicles['items'] ) ) : ?>
						<div class="m24-alt-list">
							<?php foreach ( $vehicles['items'] as $it ) {
								echo self::card( $it, ! empty( $it['sold'] ) ? __( 'Sold', 'm24-plattform' ) : __( 'For Sale', 'm24-plattform' ) ); // phpcs:ignore
							} ?>
						</div>
					<?php else : ?>
						<p class="m24-alt-empty"><?php esc_html_e( 'Keine passenden Fahrzeuge.', 'm24-plattform' ); ?></p>
						<a class="m24-alt-all" href="<?php echo esc_url( $vehicles['archive'] ); ?>"><?php esc_html_e( 'Aktuelle Fahrzeuge ansehen →', 'm24-plattform' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php return ob_get_clean();
	}
}
