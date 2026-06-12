<?php
/**
 * M24 Plattform — Vollergebnis-Seite, auf eine Gruppe gefiltert.
 * Geladen via M24_Search_Results::template() bei /?s=<q>&m24_group=<gruppe>.
 *
 * Fahrzeuge → Modell-Chips (→ Archiv-Filter); Teile/Verschiedenes → Karten-Grid
 * mit Preis-/Status-Logik. EINE begrenzte Query pro Aufruf (kein Voll-Scan).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$m24_group = M24_Search_Results::current_group();
$m24_q     = M24_Search_Results::current_query();
$m24_limit = 60; // sinnvolle Obergrenze; bei Bedarf spaeter paginieren

$m24_data  = M24_Search_Query::group( $m24_group, $m24_q, $m24_limit );
$m24_labels = array(
	M24_Search_Query::GROUP_FAHRZEUGE     => __( 'Fahrzeuge', 'm24-plattform' ),
	M24_Search_Query::GROUP_TEILE         => __( 'Teile', 'm24-plattform' ),
	M24_Search_Query::GROUP_VERSCHIEDENES => __( 'Verschiedenes', 'm24-plattform' ),
);
$m24_label = isset( $m24_labels[ $m24_group ] ) ? $m24_labels[ $m24_group ] : $m24_group;

get_header();
?>
<div class="td-container">
	<div class="m24-search-page">
		<h1><?php echo esc_html( $m24_label ); ?> <span style="color:#6b7077;font-weight:400;">&ndash; <?php echo esc_html( sprintf( __( 'Suche „%s"', 'm24-plattform' ), $m24_q ) ); ?></span></h1>
		<p class="m24-sp-sub">
			<?php
			printf(
				esc_html( _n( '%d Treffer', '%d Treffer', (int) $m24_data['total'], 'm24-plattform' ) ),
				(int) $m24_data['total']
			);
			if ( (int) $m24_data['total'] > count( $m24_data['items'] ) ) {
				echo ' &middot; ' . esc_html( sprintf( __( 'zeige %d', 'm24-plattform' ), count( $m24_data['items'] ) ) );
			}
			?>
			&middot; <a href="<?php echo esc_url( home_url( '/?s=' . rawurlencode( $m24_q ) ) ); ?>"><?php echo esc_html__( 'alle Gruppen', 'm24-plattform' ); ?></a>
		</p>

		<?php if ( empty( $m24_data['items'] ) ) : ?>
			<p class="m24-sp-empty"><?php echo esc_html__( 'Keine Treffer in dieser Gruppe.', 'm24-plattform' ); ?></p>

		<?php elseif ( M24_Search_Query::GROUP_FAHRZEUGE === $m24_group ) : ?>
			<div class="m24-sp-chips">
				<?php foreach ( $m24_data['items'] as $it ) : ?>
					<a class="m24-sp-chip" href="<?php echo esc_url( $it['url'] ); ?>"><?php echo esc_html( $it['title'] ); ?><?php if ( ! empty( $it['meta'] ) ) : ?> <span style="color:#9a6b25;">(<?php echo esc_html( $it['meta'] ); ?>)</span><?php endif; ?></a>
				<?php endforeach; ?>
			</div>

		<?php else : ?>
			<div class="m24-sp-grid">
				<?php foreach ( $m24_data['items'] as $it ) : ?>
					<a class="m24-sp-card" href="<?php echo esc_url( $it['url'] ); ?>">
						<div class="m24-sp-img"><?php if ( ! empty( $it['thumb'] ) ) : ?><img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy"><?php endif; ?></div>
						<div class="m24-sp-b">
							<h3><?php echo esc_html( $it['title'] ); ?></h3>
							<?php if ( ! empty( $it['sold'] ) ) : ?>
								<span class="m24-sp-sold"><?php echo esc_html__( 'Verkauft', 'm24-plattform' ); ?></span>
							<?php elseif ( ! empty( $it['price'] ) ) : ?>
								<span class="m24-sp-price"><?php echo esc_html( $it['price'] ); ?></span>
							<?php elseif ( ! empty( $it['meta'] ) ) : ?>
								<span style="color:#6b7077;"><?php echo esc_html( $it['meta'] ); ?></span>
							<?php endif; ?>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
get_footer();
