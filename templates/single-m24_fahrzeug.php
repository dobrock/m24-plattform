<?php
/**
 * M24 Fahrzeug — Detail-Template (Theme-Header/-Footer bleiben).
 * Aufbau nach CC-Prompt §4. Hero + Telemetrie EckIG (border-radius:0), weiße Karten 12px.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();

$id    = get_queried_object_id();
$typ   = ( 'renn' === get_post_meta( $id, '_m24fz_template_typ', true ) ) ? 'renn' : 'strasse';
$title = get_the_title( $id );
$sold  = M24FZ_CPT::is_sold( $id );
$resv  = M24FZ_CPT::is_reserved( $id );
$cells = M24FZ_Telemetry::strip_cells( $id );
$keyf  = (array) get_post_meta( $id, '_m24fz_keyfacts', true );
$zusam = trim( (string) get_post_meta( $id, '_m24fz_zusammenfassung', true ) );
$besch = trim( (string) get_post_meta( $id, '_m24fz_beschreibung', true ) );
$gals  = M24FZ_Template::galleries( $id );
$heroI = M24FZ_Template::hero_images( $id );
$daten = M24FZ_Template::daten_rows( $id );
$marke = trim( (string) get_post_meta( $id, '_m24fz_marke', true ) );
$views = M24FZ_Tracking::get( $id, 'view' );
$badge = $sold ? 'VERKAUFT' : ( $resv ? 'RESERVIERT' : '' );
?>
<div class="m24fz td-container">
	<div class="m24fz-wrap">

		<!-- 1. HERO (eckig) -->
		<section class="m24fz-hero">
			<div class="m24fz-hero-top">
				<button class="m24fz-pill" data-m24fz-track="merken" type="button">♡ Merken</button>
				<button class="m24fz-pill" data-m24fz-share type="button">↗ Teilen</button>
			</div>
			<div class="m24fz-hero-foot">
				<nav class="m24fz-bc"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Start</a> › <a href="<?php echo esc_url( home_url( '/fahrzeuge/' ) ); ?>">Fahrzeuge</a> › <span><?php echo esc_html( $title ); ?></span></nav>
				<div class="m24fz-hero-title"><?php echo esc_html( $title ); ?><?php if ( $badge ) : ?> <span class="m24fz-badge"><?php echo esc_html( $badge ); ?></span><?php endif; ?></div>
				<?php if ( $heroI ) : ?><button class="m24fz-pill m24fz-gal-launch" type="button">▦ Galerie (<?php echo count( $heroI ); ?>)</button><?php endif; ?>
			</div>
		</section>

		<!-- 2. TELEMETRIE (eckig, Messing-Oberlinie) -->
		<?php if ( $cells ) : ?>
		<section class="m24fz-tel">
			<?php foreach ( $cells as $c ) : ?>
				<div class="m24fz-tel-cell"><div class="k"><?php echo esc_html( $c['label'] ); ?></div><div class="v"><?php echo esc_html( $c['value'] ); ?></div></div>
			<?php endforeach; ?>
		</section>
		<?php endif; ?>

		<!-- 3. 2/3 + 1/3 -->
		<section class="m24fz-main">
			<div class="m24fz-col">
				<div class="m24fz-card">
					<h1 class="m24fz-h1"><?php echo esc_html( $title ); ?></h1>
					<?php $sub = array_filter( array( get_post_meta( $id, '_m24fz_karosserie', true ), get_post_meta( $id, '_m24fz_baujahr', true ), $marke ) ); ?>
					<?php if ( $sub ) : ?><p class="m24fz-sub"><?php echo esc_html( implode( ' · ', $sub ) ); ?></p><?php endif; ?>
					<?php if ( $keyf ) : ?><ul class="m24fz-keyf"><?php foreach ( $keyf as $k ) : ?><li><?php echo esc_html( $k ); ?></li><?php endforeach; ?></ul><?php endif; ?>
					<?php if ( $zusam ) : ?><p class="m24fz-zus"><?php echo esc_html( $zusam ); ?></p><?php endif; ?>
				</div>
			</div>
			<aside class="m24fz-side">
				<div class="m24fz-card m24fz-pricebox">
					<?php echo M24FZ_Template::preis_html( $id ); // phpcs:ignore ?>
					<button class="m24fz-btn" data-m24fz-track="anfrage" type="button">Jetzt anfragen</button>
					<button class="m24fz-btn ghost" data-m24fz-track="merken" type="button">Auf den Merkzettel</button>
					<p class="m24fz-views"><?php echo esc_html( number_format_i18n( $views ) ); ?> Aufrufe insgesamt</p>
				</div>
				<div class="m24fz-card m24fz-seller">
					<strong>MOTORSPORT24 GmbH</strong>
					<span>Internationaler Verkauf von Fahrzeugen seit 2006</span>
				</div>
			</aside>
		</section>

		<!-- 4. Bilder -->
		<?php if ( $heroI ) : ?>
		<section class="m24fz-card m24fz-photos">
			<figure class="big"><?php echo wp_get_attachment_image( $heroI[0], 'large', false, array( 'class' => 'm24fz-img', 'fetchpriority' => 'high', 'sizes' => '(max-width:980px) 100vw, 66vw' ) ); ?><button class="m24fz-pill m24fz-gal-launch" type="button">Galerie öffnen</button></figure>
			<div class="side"><?php foreach ( array_slice( $heroI, 1, 2 ) as $hi ) : ?><figure><?php echo wp_get_attachment_image( $hi, 'medium_large', false, array( 'class' => 'm24fz-img', 'loading' => 'lazy', 'sizes' => '33vw' ) ); ?></figure><?php endforeach; ?></div>
		</section>
		<?php endif; ?>

		<!-- 5. Beschreibung -->
		<?php if ( $besch ) : ?>
		<section class="m24fz-card m24fz-desc">
			<h2>Fahrzeugbeschreibung</h2>
			<div class="m24fz-desc-body clamp"><?php echo wp_kses_post( wpautop( $besch ) ); ?></div>
			<button class="m24fz-more" type="button">Weiterlesen</button>
		</section>
		<?php endif; ?>

		<!-- 6. Mediagalerie mit Chips -->
		<?php if ( $gals ) : ?>
		<section class="m24fz-card m24fz-media">
			<div class="m24fz-chips">
				<?php $first = array_key_first( $gals ); foreach ( $gals as $k => $g ) : ?>
					<button type="button" class="m24fz-chip<?php echo $k === $first ? ' on' : ''; ?>" data-cat="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $g['label'] ); ?></button>
				<?php endforeach; ?>
				<?php $vids = array_filter( (array) get_post_meta( $id, '_m24fz_videos', true ) ); if ( $vids ) : ?><button type="button" class="m24fz-chip" data-cat="video">Video</button><?php endif; ?>
			</div>
			<div class="m24fz-mediagrid">
				<?php foreach ( $gals as $k => $g ) : foreach ( $g['ids'] as $aid ) : ?>
					<a href="<?php echo esc_url( wp_get_attachment_image_url( $aid, 'large' ) ); ?>" class="m24fz-mitem" data-cat="<?php echo esc_attr( $k ); ?>"<?php echo $k === $first ? '' : ' hidden'; ?>><?php echo wp_get_attachment_image( $aid, 'medium_large', false, array( 'loading' => 'lazy', 'sizes' => '(max-width:700px) 50vw, 25vw' ) ); ?></a>
				<?php endforeach; endforeach; ?>
				<?php foreach ( $vids as $vu ) : ?><a href="<?php echo esc_url( $vu ); ?>" class="m24fz-mitem m24fz-video" data-cat="video" hidden target="_blank" rel="noopener">▶ Video</a><?php endforeach; ?>
			</div>
		</section>
		<?php endif; ?>

		<!-- 7. Fahrzeugdaten -->
		<?php if ( $daten ) : ?>
		<section class="m24fz-card m24fz-data">
			<h2>Fahrzeugdaten</h2>
			<div class="m24fz-data-grid"><?php foreach ( $daten as $r ) : ?><div class="row"><span class="k"><?php echo esc_html( $r['label'] ); ?></span><span class="v"><?php echo esc_html( $r['value'] ); ?></span></div><?php endforeach; ?></div>
		</section>
		<?php endif; ?>

		<!-- 8. Ähnliche -->
		<?php $sim = M24FZ_Similar::ids( $id, 3 ); if ( $sim ) : ?>
		<section class="m24fz-card m24fz-similar">
			<h2>Ähnliche Fahrzeuge</h2>
			<div class="m24fz-simgrid"><?php foreach ( $sim as $sid ) :
				$ssold = M24FZ_CPT::is_sold( $sid ); $scc = get_post_meta( $sid, '_m24fz_standort', true ); ?>
				<a class="m24fz-simcard" href="<?php echo esc_url( get_permalink( $sid ) ); ?>">
					<span class="img"><?php echo has_post_thumbnail( $sid ) ? wp_get_attachment_image( get_post_thumbnail_id( $sid ), 'medium_large', false, array( 'loading' => 'lazy', 'sizes' => '(max-width:700px) 50vw, 25vw' ) ) : ''; ?><?php if ( $ssold ) : ?><span class="m24fz-badge sm">Verkauft</span><?php endif; ?></span>
					<span class="meta"><?php echo esc_html( trim( M24FZ_Telemetry::flag( $scc ) . ' ' . get_post_meta( $sid, '_m24fz_baujahr', true ) ) ); ?></span>
					<span class="t"><?php echo esc_html( get_the_title( $sid ) ); ?></span>
				</a>
			<?php endforeach; ?></div>
			<?php if ( $marke ) : ?><a class="m24fz-allmarke" href="<?php echo esc_url( home_url( '/fahrzeuge/' ) ); ?>">Alle <?php echo esc_html( $marke ); ?> ansehen</a><?php endif; ?>
		</section>
		<?php endif; ?>

		<!-- 9. 50/50: Lieferung & Zoll + Off-Market-Stub -->
		<section class="m24fz-5050">
			<div class="m24fz-card m24fz-ship">
				<h2>Lieferung &amp; Zoll</h2>
				<ul><li>🚚 Europa- &amp; weltweite Lieferung</li><li>🛃 Zollabwicklung in Deutschland bei Drittland</li><li>📦 Optionale Zolldienstleistung im Empfängerland</li></ul>
			</div>
			<div class="m24fz-card m24fz-offmarket">
				<span class="m24fz-badge prep">In Vorbereitung</span>
				<h2>Off-Market</h2>
				<p>Fahrzeuge vorgestellt, bevor sie offiziell vermarktet werden.</p>
				<div class="row"><input type="email" placeholder="E-Mail-Adresse" disabled><button type="button" class="m24fz-btn" disabled>Anmelden</button></div>
			</div>
		</section>

	</div>

	<?php // Lightbox-Container (JS füllt) ?>
	<div class="m24fz-lb" hidden><button class="m24fz-lb-close" type="button">&times;</button><img src="" alt=""><button class="m24fz-lb-prev" type="button">‹</button><button class="m24fz-lb-next" type="button">›</button></div>
</div>
<?php get_footer();
