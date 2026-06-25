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
// Teil A: die ersten 3 (im 3er-Vorschau-Mosaik gezeigten) Außenbilder NICHT in der Galerie doppeln.
$blockIds = M24FZ_Template::block_images( $id, 3 );
if ( $blockIds && $gals ) {
	$bset = array_flip( $blockIds );
	foreach ( $gals as $gk => $gv ) {
		$gv['ids'] = array_values( array_filter( $gv['ids'], static function ( $x ) use ( $bset ) { return ! isset( $bset[ $x ] ); } ) );
		if ( $gv['ids'] ) { $gals[ $gk ] = $gv; } else { unset( $gals[ $gk ] ); }
	}
}
$vids  = array_values( array_filter( (array) get_post_meta( $id, '_m24fz_videos', true ) ) );
$heroI = M24FZ_Template::hero_images( $id );
$daten = M24FZ_Template::daten_rows( $id );
$zust  = M24FZ_Template::chips( $id, '_m24fz_zustand', M24FZ_Telemetry::zustand_options() );
$ausst = array_values( array_filter( array_map( 'trim', (array) get_post_meta( $id, '_m24fz_ausstattung', true ) ) ) ); // Freitext
$marke = trim( (string) get_post_meta( $id, '_m24fz_marke', true ) );
$baujahr = trim( (string) get_post_meta( $id, '_m24fz_baujahr', true ) );
$views = M24FZ_Tracking::get( $id, 'view' );
$badge = $sold ? 'VERKAUFT' : ( $resv ? 'RESERVIERT' : '' );
?>
<div class="m24fz td-container">
	<div class="m24fz-wrap">

		<!-- 1. HERO (eckig) — Beitragsbild als Hintergrund, dunkler Verlauf als Overlay -->
		<section class="m24fz-hero<?php echo $heroI ? ' has-img' : ''; ?>">
			<?php if ( $heroI ) : ?>
				<?php echo wp_get_attachment_image( $heroI[0], 'large', false, array( 'class' => 'm24fz-hero-img', 'fetchpriority' => 'high', 'loading' => 'eager', 'decoding' => 'async', 'sizes' => '100vw' ) ); // phpcs:ignore ?>
				<span class="m24fz-hero-ov" aria-hidden="true"></span>
			<?php endif; ?>
			<div class="m24fz-hero-top">
				<button class="m24fz-pill m24fz-park-open" type="button">♡ Merken</button>
				<button class="m24fz-pill" data-m24fz-share type="button">↗ Teilen</button>
			</div>
			<div class="m24fz-hero-foot">
				<nav class="m24fz-bc"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Start</a> › <a href="<?php echo esc_url( home_url( '/fahrzeuge/' ) ); ?>">Fahrzeuge</a> › <span><?php echo esc_html( $title ); ?></span></nav>
				<div class="m24fz-hero-titlerow">
					<h1 class="m24fz-hero-title"><?php echo esc_html( $title ); ?></h1>
					<?php if ( $heroI ) : ?><button class="m24fz-pill m24fz-gal-launch" type="button">▦ Galerie (<?php echo count( $heroI ); ?>)</button><?php endif; ?>
				</div>
			</div>
		</section>

		<!-- 1b. MOBILE-HEAD (nur ≤768px, Entwurf A: weiße Karte unter dem Hero-Bild) -->
		<section class="m24fz-card m24fz-mhead">
			<?php $eyebrow = implode( ' · ', array_filter( array( $marke, $baujahr ) ) ); ?>
			<?php if ( '' !== $eyebrow ) : ?><div class="m24fz-mh-eyebrow"><?php echo esc_html( $eyebrow ); ?></div><?php endif; ?>
			<h1 class="m24fz-mh-title"><?php echo esc_html( $title ); ?></h1>
			<div class="m24fz-pricebox"><?php echo M24FZ_Template::pricebox_html( $id ); // phpcs:ignore — Status-Pill/Preis/CTAs (geteilte, getestete Logik) ?></div>
		</section>

		<!-- 2. TELEMETRIE (eckig, Messing-Oberlinie) -->
		<?php if ( $cells ) : ?>
		<div class="m24fz-tel-eyebrow">Eckdaten</div>
		<section class="m24fz-tel">
			<?php foreach ( $cells as $c ) : ?>
				<div class="m24fz-tel-cell"><div class="k"><?php echo esc_html( $c['label'] ); ?></div><div class="v"><?php echo esc_html( $c['value'] ); ?></div></div>
			<?php endforeach; ?>
		</section>
		<?php endif; ?>

		<!-- 3. 2/3 + 1/3 -->
		<section class="m24fz-main">
			<div class="m24fz-col">
				<div class="m24fz-card m24fz-infobox">
					<span class="m24fz-kicker">Auf einen Blick</span>
															<?php if ( $keyf ) : ?><ul class="m24fz-keyf"><?php foreach ( $keyf as $k ) : ?><li><?php echo esc_html( $k ); ?></li><?php endforeach; ?></ul><?php endif; ?>
					<?php if ( $zusam ) : ?><?php if ( $keyf ) : ?><hr class="m24fz-hr"><?php endif; ?><div class="m24fz-zus"><?php echo wp_kses_post( wpautop( $zusam ) ); ?></div><?php endif; ?>
				</div>
			</div>
			<aside class="m24fz-side">
				<div class="m24fz-card m24fz-pricebox">
					<?php echo M24FZ_Template::pricebox_html( $id ); // phpcs:ignore ?>
				</div>
			</aside>
		</section>

		<!-- 4. Bilder (3er-Block: Außen ohne Beitragsbild-Dublette) -->
		<?php $block = M24FZ_Template::block_images( $id, 3 ); if ( $block ) : ?>
		<section class="m24fz-card m24fz-photos">
			<figure class="big"><?php echo wp_get_attachment_image( $block[0], 'large', false, array( 'class' => 'm24fz-img', 'loading' => 'lazy', 'sizes' => '(max-width:980px) 100vw, 66vw' ) ); ?></figure>
			<?php foreach ( array_slice( $block, 1, 2 ) as $hi ) : ?><figure class="side"><?php echo wp_get_attachment_image( $hi, 'medium_large', false, array( 'class' => 'm24fz-img', 'loading' => 'lazy', 'sizes' => '33vw' ) ); ?></figure><?php endforeach; ?>
		</section>
		<?php endif; ?>

		<!-- 5. Beschreibung -->
		<?php if ( $besch ) : ?>
		<section class="m24fz-card m24fz-desc">
			<h2>Fahrzeugbeschreibung</h2>
			<div class="m24fz-desc-body clamp"><?php echo wp_kses_post( wpautop( $besch ) ); ?></div>
			<button class="m24fz-more" type="button"><span class="t">Weiterlesen</span><span class="chev" aria-hidden="true">⌄</span></button>
		</section>
		<?php endif; ?>

		<!-- 6. Mediagalerie — Jetpack-Tiled-Mosaik je Kategorie (9 + +X + Fly-out obendrauf) + Video separat -->
		<?php if ( $gals || $vids ) : ?>
		<?php $first = $gals ? array_key_first( $gals ) : 'video'; ?>
		<section class="m24fz-card m24fz-media" id="galerie">
			<div class="m24fz-chips">
				<?php foreach ( $gals as $k => $g ) : ?>
					<button type="button" class="m24fz-chip<?php echo $k === $first ? ' on' : ''; ?>" data-cat="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $g['label'] ); ?> <span class="n"><?php echo count( $g['ids'] ); ?></span></button>
				<?php endforeach; ?>
				<?php if ( $vids ) : ?><button type="button" class="m24fz-chip<?php echo 'video' === $first ? ' on' : ''; ?>" data-cat="video">Video <span class="n"><?php echo count( $vids ); ?></span></button><?php endif; ?>
			</div>

			<?php foreach ( $gals as $k => $g ) : ?>
			<div class="m24fz-galcat" data-catwrap="<?php echo esc_attr( $k ); ?>" data-total="<?php echo count( $g['ids'] ); ?>"<?php echo $k === $first ? '' : ' hidden'; ?>>
				<?php echo M24FZ_Template::tiled_block( $g['ids'], 9 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<?php endforeach; ?>

			<?php if ( $vids ) : ?>
			<div class="m24fz-videos" data-catwrap="video"<?php echo 'video' === $first ? '' : ' hidden'; ?>>
				<?php foreach ( $vids as $vu ) : $yid = M24FZ_Template::yt_id( $vu ); if ( ! $yid ) { continue; } ?>
					<button type="button" class="m24fz-video" data-ytid="<?php echo esc_attr( $yid ); ?>" aria-label="Video abspielen">
						<img src="https://i.ytimg.com/vi/<?php echo esc_attr( $yid ); ?>/hqdefault.jpg" alt="Video-Vorschau" loading="lazy" width="480" height="360" onerror="this.onerror=null;this.src='https://i.ytimg.com/vi/<?php echo esc_attr( $yid ); ?>/mqdefault.jpg'">
						<span class="m24fz-play" aria-hidden="true">▶</span>
					</button>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<!-- 7. Fahrzeugdaten -->
		<?php if ( $daten ) : ?>
		<section class="m24fz-card m24fz-data">
			<h2>Fahrzeugdaten</h2>
			<div class="m24fz-data-grid"><?php foreach ( $daten as $r ) : ?><div class="row"><span class="k"><?php echo esc_html( $r['label'] ); ?></span><span class="v"><?php echo esc_html( $r['value'] ); ?></span></div><?php endforeach; ?></div>
		</section>
		<?php endif; ?>

		<!-- 7b. Zustand & Ausstattung (optional) -->
		<?php if ( $zust || $ausst ) : ?>
		<section class="m24fz-card m24fz-equip">
			<?php if ( $zust ) : ?>
				<h2>Zustand</h2>
				<div class="m24fz-tags"><?php foreach ( $zust as $t ) : ?><span class="m24fz-tag"><?php echo esc_html( $t ); ?></span><?php endforeach; ?></div>
			<?php endif; ?>
			<?php if ( $ausst ) : ?>
				<h2<?php echo $zust ? ' class="mt"' : ''; ?>>Ausstattung</h2>
				<div class="m24fz-tags"><?php foreach ( $ausst as $t ) : ?><span class="m24fz-tag"><?php echo esc_html( $t ); ?></span><?php endforeach; ?></div>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<!-- 8. Ähnliche (CPT + Alt-Beiträge) -->
		<?php $sim = M24FZ_Similar::cards( $id, 6 ); if ( $sim ) : ?>
		<section class="m24fz-card m24fz-similar">
			<h2>Ähnliche Fahrzeuge</h2>
			<?php echo M24FZ_CPT::status_badge_style_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="m24fz-simgrid"><?php foreach ( $sim as $c ) : ?>
				<a class="m24fz-simcard" href="<?php echo esc_url( $c['url'] ); ?>">
					<span class="img"><?php echo $c['thumb'] ? wp_get_attachment_image( $c['thumb'], 'large', false, array( 'loading' => 'lazy', 'sizes' => '(max-width:700px) 50vw, 25vw' ) ) : ''; ?><?php if ( $c['sold'] ) : ?><span class="m24-status-ribbon sold">Verkauft</span><?php elseif ( ! empty( $c['reserved'] ) ) : ?><span class="m24-status-ribbon res">Reserviert</span><?php endif; ?></span>
					<span class="t"><?php echo esc_html( $c['title'] ); ?></span>
				</a>
			<?php endforeach; ?></div>
			<?php if ( $marke ) : ?><a class="m24fz-allmarke" href="<?php echo esc_url( home_url( '/fahrzeuge/' ) ); ?>">Alle <?php echo esc_html( $marke ); ?> ansehen</a><?php endif; ?>
		</section>
		<?php endif; ?>

		<!-- 9. 50/50: Lieferung & Zoll + Off-Market-Stub -->
		<section class="m24fz-5050">
			<div class="m24fz-card m24fz-ship">
				<h2>Lieferung &amp; Zoll</h2>
				<ul>
					<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 4h13v11H1z"/><path d="M14 8h4l3 3v4h-7z"/><circle cx="5.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></svg><span>Europa- &amp; weltweite Lieferung</span></li>
					<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 8 8 11 4.6-3 8-6 8-11V5z"/><path d="m8.5 12 2.5 2.5 4.5-4.5"/></svg><span>Zollabwicklung in Deutschland bei Drittland</span></li>
					<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg><span>Optionale Zolldienstleistung im Empfängerland</span></li>
				</ul>
			</div>
			<?php $om_live = class_exists( 'M24_Brevo_Client' ) && M24_Brevo_Client::offmarket_list_id() > 0; ?>
			<div class="m24fz-card m24fz-offmarket">
				<?php if ( ! $om_live ) : ?><span class="m24fz-badge prep">In Vorbereitung</span><?php endif; ?>
				<h2>Zuerst sehen, was noch keiner sieht</h2>
				<p class="m24fz-om-lead">Die begehrtesten Fahrzeuge wechseln den Besitzer, bevor sie je ein Inserat sehen. Sei zuerst dran.</p>
				<ul class="m24fz-om-benefits">
					<li><span class="m24fz-om-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg></span>Erstzugriff</li>
					<li><span class="m24fz-om-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></span>Diskret vorab</li>
					<li><span class="m24fz-om-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg></span>Ohne Bieterstress</li>
					<li><span class="m24fz-om-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>Info per E-Mail</li>
				</ul>
				<?php if ( $om_live ) : ?>
					<form class="m24fz-offmarket-form" data-pid="<?php echo (int) get_queried_object_id(); ?>">
						<div class="row">
							<input type="email" name="email" placeholder="E-Mail-Adresse" required>
							<button type="submit" class="m24fz-btn m24fz-om-submit">Anmelden</button>
						</div>
						<input type="text" name="website" class="m24fz-anf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
						<label class="m24fz-anf-check m24fz-om-check"><input type="checkbox" name="consent" value="1" required> Ja, informiert mich vorab über Off-Market-Fahrzeuge per E-Mail.</label>
						<p class="m24fz-anf-msg" role="status"></p>
					</form>
				<?php else : ?>
					<div class="row"><input type="email" placeholder="E-Mail-Adresse" disabled><button type="button" class="m24fz-btn" disabled>Anmelden</button></div>
				<?php endif; ?>
			</div>
		</section>

	</div>

	<?php // Lightbox-Container (JS füllt) ?>
	<div class="m24fz-lb" hidden><button class="m24fz-lb-close" type="button">&times;</button><img src="" alt=""><div class="m24fz-lb-frame"></div><button class="m24fz-lb-prev" type="button">‹</button><button class="m24fz-lb-next" type="button">›</button></div>

	<?php M24FZ_Anfrage::modal_html( $id ); // „Jetzt anfragen"-Modal ?>
	<?php M24FZ_Anfrage::il_modal_html( $id ); // „Auf die Interessentenliste"-Modal (getrennt) ?>
	<?php M24FZ_Anfrage::park_modal_html( $id ); // „Fahrzeug parken"-Modal (No-Account-DOI) ?>
</div>
<?php get_footer();
