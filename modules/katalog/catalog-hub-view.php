<?php
/**
 * M24 Plattform — Modell-Hub-Template (Inhaltsbereich zwischen get_header/get_footer)
 * Modul: modules/katalog/catalog-hub-view.php  ·  Daten/Logik: M24_Catalog_Hub
 *
 * Visuelle Referenz: m3-e30-hub-variante-4b-telemetrie-trust.html. Klassen `m24hub-`-praefixiert
 * (Theme-konfliktfrei). Grid nutzt die bestehende Katalog-Karte (M24_Catalog_Archive::card_html).
 * Slideshow zeigt v1 Platzhalter — Bilder kommen in Phase 2 aus Term-Meta.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$hub    = M24_Catalog_Hub::current();
$cfg    = M24_Catalog_Hub::config( $hub );
$modell = $cfg['modell'] ?? '';
$h1     = M24_Catalog_Hub::h1( $hub );
$count  = M24_Catalog_Hub::count( $hub );                  // Live-Zähler (aktiv) — Telemetrie
$images = M24_Catalog_Hub::images( $hub );                 // Term-Meta-Bilder (leer ⇒ Platzhalter)
$ph     = max( 1, (int) apply_filters( 'm24_hub_slide_count', 3, $hub ) ); // Platzhalter-Anzahl ohne Bilder
$slides = ! empty( $images ) ? count( $images ) : $ph;
$hub_url = M24_Catalog_Hub::url( $hub );
$crumb  = home_url( '/gebrauchtteile/' );

// Paginierte/gefilterte Teile-Liste (Suche ?q=, Sortierung ?sort=, 15%-Verkauft-Deckel).
$list   = M24_Catalog_Hub::listing( $hub );
$ltotal = (int) $list['total'];
$lq_q   = $list['q'];
$lsort  = $list['sort'];
$lkat   = $list['kat'] ?? 'alle';
$cross  = ! empty( $cfg['cross_links'] ) ? (array) $cfg['cross_links'] : array();

// Breadcrumb-/JSON-LD-Label = H1 ohne „Gebrauchtteile/Gebrauchte Teile passend für ".
$crumb_label = trim( preg_replace( '/^Gebraucht(?:e Teile|teile) passend für\s+/u', '', $h1 ) );
if ( '' === $crumb_label ) { $crumb_label = $modell; }

// Header puffern, damit die tagDiv-Logo-H1 zu <div> degradiert wird (genau 1 H1 = Seitentitel).
ob_start();
get_header();
echo M24_Catalog_Hub::demote_logo_h1( ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput

// BreadcrumbList (eine Quelle aus dem Plugin).
$ld = array(
	'@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
	'itemListElement' => array(
		array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Gebrauchte Teile', 'item' => $crumb ),
		array( '@type' => 'ListItem', 'position' => 2, 'name' => $crumb_label ),
	),
);
?>
<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
<style>
/* Design-Tokens (:root) + Karten-Komponente zentral: assets/css/m24-ci.css. */
.m24hub{font-family:'Saira',Helvetica,Arial,sans-serif;color:var(--text);line-height:1.6}
.m24hub .m24hub-wrap{max-width:1116px;margin:0 auto;padding:0 24px} /* logo-bündig: (1440-1116)/2+24=186 = Header/Footer-Kante */
.m24hub .m24hub-crumb{background:var(--surface);border-bottom:1px solid var(--line);font-size:13px;color:var(--muted)}
.m24hub .m24hub-crumb .m24hub-wrap{padding:12px 24px}
.m24hub .m24hub-crumb a{color:var(--blue)}.m24hub .m24hub-crumb .sep{margin:0 8px;color:#bcbcb8}
.m24hub .m24hub-hg{padding:30px 0 18px}
.m24hub .m24hub-eyebrow{font-size:12px;letter-spacing:2.4px;text-transform:uppercase;color:var(--blue);font-weight:600}
.m24hub .m24hub-hg h1{font-size:40px;line-height:1.1;font-weight:700;margin:8px 0 6px}
.m24hub .m24hub-hg .sub{color:#42474d;font-size:17px;max-width:720px}
@media(max-width:700px){.m24hub .m24hub-hg h1{font-size:30px}}
.m24hub .m24hub-full{width:100%;background:#0d0f12}
.m24hub .m24hub-slides{position:relative;width:100%;aspect-ratio:21/9;min-height:320px;max-height:600px}
@media(max-width:700px){.m24hub .m24hub-slides{aspect-ratio:16/10}}
.m24hub .m24hub-slide{position:absolute;inset:0;opacity:0;transition:opacity .6s ease;display:flex;align-items:center;justify-content:center;background:repeating-linear-gradient(135deg,#191c22,#191c22 24px,#1d2128 24px,#1d2128 48px)}
.m24hub .m24hub-slide.on{opacity:1}.m24hub .m24hub-slide img{width:100%;height:100%;object-fit:cover}
.m24hub .m24hub-slide .tag{font-size:13px;letter-spacing:2px;text-transform:uppercase;color:#79859a}
.m24hub .m24hub-arrow{position:absolute;top:50%;transform:translateY(-50%);width:46px;height:46px;border-radius:50%;background:rgba(15,17,20,.5);color:#fff;border:1px solid rgba(255,255,255,.2);font-size:19px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2}
.m24hub .m24hub-arrow:hover{background:var(--blue)}.m24hub .m24hub-arrow.prev{left:22px}.m24hub .m24hub-arrow.next{right:22px}
.m24hub .m24hub-dots{position:absolute;bottom:18px;left:0;right:0;display:flex;gap:8px;justify-content:center;z-index:2}
.m24hub .m24hub-dot{width:9px;height:9px;border-radius:50%;background:rgba(255,255,255,.45);cursor:pointer;transition:all .2s}.m24hub .m24hub-dot.on{background:#fff;width:22px;border-radius:5px}
.m24hub .m24hub-telem{background:var(--ink)}
.m24hub .m24hub-telem .m24hub-wrap{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;padding:0}
@media(max-width:700px){.m24hub .m24hub-telem .m24hub-wrap{grid-template-columns:repeat(2,1fr)}}
.m24hub .m24hub-tcell{padding:16px 22px;border-left:1px solid #262a31}.m24hub .m24hub-tcell:first-child{border-left:none}
.m24hub .m24hub-tcell .k{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--brass);font-weight:600;display:flex;align-items:center;gap:6px}
.m24hub .m24hub-tcell .v{color:#fff;font-size:18px;font-weight:600;margin-top:3px}
.m24hub .m24hub-livedot{width:7px;height:7px;border-radius:50%;background:#3fa45a;display:inline-block;box-shadow:0 0 0 3px rgba(63,164,90,.2)}
.m24hub .m24hub-intro .m24hub-wrap{padding:40px 24px 6px;max-width:880px}
.m24hub .m24hub-intro h2{font-size:24px;font-weight:700;margin-bottom:12px}
.m24hub .m24hub-intro p{color:#33373d;margin-bottom:14px;font-size:16.5px}
.m24hub .m24hub-trust{background:var(--surface);border-top:1px solid var(--line);border-bottom:1px solid var(--line);margin-top:26px}
.m24hub .m24hub-trust .m24hub-wrap{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;padding:20px 24px}
@media(max-width:700px){.m24hub .m24hub-trust .m24hub-wrap{grid-template-columns:repeat(2,1fr)}}
.m24hub .m24hub-trust .it{display:flex;align-items:center;gap:11px}
.m24hub .m24hub-trust .ic{width:34px;height:34px;border-radius:50%;background:#fff;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--brass);font-size:17px;flex:0 0 auto}
.m24hub .m24hub-trust .tt{font-size:14px;font-weight:600;color:var(--text);line-height:1.2}.m24hub .m24hub-trust .ts{font-size:12px;color:var(--muted)}
.m24hub .m24hub-parts .m24hub-wrap{padding:30px 24px 50px}
.m24hub .m24hub-parts .head{display:flex;align-items:baseline;justify-content:space-between;border-bottom:2px solid var(--ink);padding-bottom:10px;margin-bottom:24px}
.m24hub .m24hub-parts h2{font-size:20px;font-weight:700;text-transform:uppercase;letter-spacing:1px}
.m24hub .m24hub-parts .count{color:var(--muted);font-size:14px}
/* Such-/Sortier-Leiste */
.m24hub .m24hub-controls{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:24px}
.m24hub .m24hub-search{position:relative;flex:1 1 320px;max-width:440px}
.m24hub .m24hub-search input{width:100%;padding:11px 14px 11px 40px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:14px;background:#fff;color:var(--text)}
.m24hub .m24hub-search input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(23,99,173,.12)}
.m24hub .m24hub-search .si{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted)}
.m24hub .m24hub-sortwrap{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);white-space:nowrap}
.m24hub .m24hub-sortwrap select{font-family:inherit;font-size:14px;padding:9px 30px 9px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--text);cursor:pointer}
.m24hub .m24hub-resetq{font-size:13px;color:var(--blue);white-space:nowrap}
.m24hub .m24hub-controls-right{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
/* Kategorie-Switch (Rennsport | Gebraucht | Alle) — Stil wie Ansicht-Umschalter */
.m24hub .m24hub-katsw{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#fff}
.m24hub .m24hub-katsw a{padding:8px 14px;cursor:pointer;color:var(--muted);border-left:1px solid var(--line);font-size:13px;font-weight:600;text-decoration:none;line-height:1.4}
.m24hub .m24hub-katsw a:first-child{border-left:none}
.m24hub .m24hub-katsw a.on{background:var(--blue);color:#fff}
.m24hub .m24hub-katsw a:hover:not(.on){background:#f0f0ee}
/* Ansicht-Umschalter (Segmented Control) */
.m24hub .m24hub-viewsw{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#fff}
.m24hub .m24hub-viewsw button{border:none;background:#fff;padding:8px 11px;cursor:pointer;color:var(--muted);display:flex;align-items:center;gap:6px;border-left:1px solid var(--line);font-family:inherit;font-size:12px;font-weight:600}
.m24hub .m24hub-viewsw button:first-child{border-left:none}
.m24hub .m24hub-viewsw button.on{background:var(--blue);color:#fff}
.m24hub .m24hub-viewsw button:hover:not(.on){background:#f0f0ee}
.m24hub .m24hub-viewsw svg{fill:currentColor;flex:0 0 auto}
/* Grid mit Ansichten (Default 4 Spalten) */
.m24hub .m24hub-grid{display:grid;gap:22px}
.m24hub .m24hub-grid.view-3{grid-template-columns:repeat(3,1fr)}
.m24hub .m24hub-grid.view-4{grid-template-columns:repeat(4,1fr)}
.m24hub .m24hub-grid.view-list{grid-template-columns:1fr;gap:12px}
@media(max-width:900px){.m24hub .m24hub-grid.view-3,.m24hub .m24hub-grid.view-4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.m24hub .m24hub-grid.view-3,.m24hub .m24hub-grid.view-4{grid-template-columns:1fr}}
/* Übergangseffekt beim Ansichts-/Sortierwechsel (Stagger) */
@keyframes m24hubCardIn{from{opacity:0;transform:translateY(10px) scale(.985)}to{opacity:1;transform:none}}
.m24hub .m24hub-grid.anim .m24-card{animation:m24hubCardIn .34s ease both}
@media(prefers-reduced-motion:reduce){.m24hub .m24hub-grid.anim .m24-card{animation:none}}
/* Listenansicht: Bild links, Text rechts */
.m24hub .m24hub-grid.view-list .m24-card__link{flex-direction:row;align-items:stretch}
.m24hub .m24hub-grid.view-list .m24-card__media{flex:0 0 200px;width:200px;aspect-ratio:4/3}
@media(max-width:560px){.m24hub .m24hub-grid.view-list .m24-card__media{flex:0 0 120px;width:120px}}
.m24hub .m24hub-grid.view-list .m24-card__body{justify-content:center}
.m24hub .m24hub-grid.view-list .m24-card__title{min-height:0}
/* Pagination */
.m24hub .m24hub-pager{margin-top:34px}
.m24hub .m24hub-pager .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;padding:0 12px;margin:0 3px 6px 0;border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--text);font-weight:600;font-size:14px}
.m24hub .m24hub-pager a.page-numbers:hover{border-color:var(--blue);color:var(--blue)}
.m24hub .m24hub-pager .page-numbers.current{background:var(--blue);border-color:var(--blue);color:#fff}
.m24hub .m24hub-pager .page-numbers.dots{border:none;min-width:auto;padding:0 4px}
/* SEO-Textblock (unter dem Raster, über dem Markenhinweis) */
.m24hub .m24hub-seo{background:#fff;border-top:1px solid var(--line)}
.m24hub .m24hub-seo .m24hub-wrap{padding:32px 24px;max-width:880px}
.m24hub .m24hub-seo h2{font-size:20px;font-weight:700;margin-bottom:10px}
.m24hub .m24hub-seo p{color:#33373d;font-size:15.5px;margin-bottom:10px}
.m24hub .m24hub-seo a{color:var(--blue)}
.m24hub .m24hub-seo .links{font-size:14px;color:var(--muted)}
.m24hub .m24hub-seo .links .sep{margin:0 6px;color:#cfcfca}
/* Markenhinweis: eckiges Vollbreiten-Band, Textspalte = Content-Breite (logo-bündig) */
.m24hub .m24hub-legal{background:var(--surface);border-top:1px solid var(--line);border-radius:0}
.m24hub .m24hub-legal .m24hub-wrap{padding:20px 24px;font-size:12.5px;color:var(--muted)}
.m24hub .m24hub-empty{color:var(--muted);font-style:italic;padding:18px 0}
</style>

<div class="m24hub">
	<div class="m24hub-crumb"><div class="m24hub-wrap"><a href="<?php echo esc_url( $crumb ); ?>">Gebrauchte Teile</a><span class="sep">›</span><?php echo esc_html( $crumb_label ); ?></div></div>

	<div class="m24hub-wrap">
		<div class="m24hub-hg">
			<div class="m24hub-eyebrow">Gebrauchte Teile · seit 2006</div>
			<h1><?php echo esc_html( $h1 ); ?></h1>
			<?php if ( ! empty( $cfg['sub'] ) ) : ?><p class="sub"><?php echo esc_html( $cfg['sub'] ); ?></p><?php endif; ?>
		</div>
	</div>

	<section class="m24hub-full">
		<div class="m24hub-slides" id="m24hub-slides" aria-roledescription="Bildergalerie">
			<?php if ( ! empty( $images ) ) : ?>
				<?php foreach ( $images as $s => $img ) : ?>
					<div class="m24hub-slide<?php echo 0 === $s ? ' on' : ''; ?>"><img src="<?php echo esc_url( $img['url'] ); ?>" alt="<?php echo esc_attr( $img['alt'] ); ?>"<?php echo $img['w'] ? ' width="' . (int) $img['w'] . '" height="' . (int) $img['h'] . '"' : ''; ?> loading="<?php echo 0 === $s ? 'eager' : 'lazy'; ?>" decoding="async"></div>
				<?php endforeach; ?>
			<?php else : ?>
				<?php for ( $s = 0; $s < $slides; $s++ ) : ?>
					<div class="m24hub-slide<?php echo 0 === $s ? ' on' : ''; ?>"><span class="tag"><?php echo esc_html( $modell . ' — Foto ' . ( $s + 1 ) ); ?></span></div>
				<?php endfor; ?>
			<?php endif; ?>
			<button class="m24hub-arrow prev" id="m24hub-prev" aria-label="Vorheriges Bild">&#8249;</button>
			<button class="m24hub-arrow next" id="m24hub-next" aria-label="Nächstes Bild">&#8250;</button>
			<div class="m24hub-dots" id="m24hub-dots"></div>
		</div>
	</section>

	<div class="m24hub-telem"><div class="m24hub-wrap">
		<?php if ( '' !== $modell ) : ?><div class="m24hub-tcell"><div class="k">Modell</div><div class="v"><?php echo esc_html( $modell ); ?></div></div><?php endif; ?>
		<?php if ( ! empty( $cfg['motor'] ) ) : ?><div class="m24hub-tcell"><div class="k">Motor</div><div class="v"><?php echo esc_html( $cfg['motor'] ); ?></div></div><?php endif; ?>
		<?php if ( ! empty( $cfg['baujahre'] ) ) : ?><div class="m24hub-tcell"><div class="k">Baujahre</div><div class="v"><?php echo esc_html( $cfg['baujahre'] ); ?></div></div><?php endif; ?>
		<div class="m24hub-tcell"><div class="k"><span class="m24hub-livedot"></span>Aktuell verfügbar</div><div class="v"><?php echo esc_html( sprintf( _n( '%s Teil', '%s Teile', $count, 'm24-plattform' ), number_format_i18n( $count ) ) ); ?></div></div>
	</div></div>

	<?php if ( ! empty( $cfg['intro_html'] ) ) : ?>
	<section class="m24hub-intro"><div class="m24hub-wrap">
		<?php if ( ! empty( $cfg['intro_h2'] ) ) : ?><h2><?php echo esc_html( $cfg['intro_h2'] ); ?></h2><?php endif; ?>
		<?php echo wp_kses_post( wpautop( $cfg['intro_html'] ) ); ?>
	</div></section>
	<?php endif; ?>

	<div class="m24hub-trust"><div class="m24hub-wrap">
		<div class="it"><span class="ic">&#9733;</span><div><div class="tt">seit 2006</div><div class="ts">auf BMW spezialisiert</div></div></div>
		<div class="it"><span class="ic">&#9992;</span><div><div class="tt">weltweiter Versand</div><div class="ts">B2B / gewerblich</div></div></div>
		<div class="it"><span class="ic">&#10003;</span><div><div class="tt">geprüfte Teile</div><div class="ts">vor dem Verkauf kontrolliert</div></div></div>
		<div class="it"><span class="ic">&#9737;</span><div><div class="tt">55.000+ Community</div><div class="ts">Werkstätten &amp; Sammler</div></div></div>
	</div></div>

	<section class="m24hub-parts"><div class="m24hub-wrap">
		<div class="head">
			<h2>Teile passend für BMW <?php echo esc_html( $modell ); ?></h2>
			<span class="count" id="m24hub-count"><?php echo esc_html( M24_Catalog_Hub::count_label( $ltotal, $lsort ) ); ?></span>
		</div>

		<form class="m24hub-controls" id="m24hub-controls" method="get" action="<?php echo esc_url( $hub_url ); ?>" role="search">
			<div class="m24hub-search">
				<svg class="si" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="16.5" y1="16.5" x2="21" y2="21"></line></svg>
				<input id="m24hub-q" name="q" type="search" value="<?php echo esc_attr( $lq_q ); ?>" placeholder="<?php echo esc_attr( 'In ' . $modell . '-Teilen suchen …' ); ?>" aria-label="<?php echo esc_attr( 'In ' . $modell . '-Teilen suchen' ); ?>">
			</div>
			<div class="m24hub-controls-right">
				<div class="m24hub-katsw" id="m24hub-katsw" role="group" aria-label="Kategorie wählen">
					<?php
					$kat_opts = array( 'rennsport' => 'Rennsport', 'gebraucht' => 'Gebraucht', 'alle' => 'Alle' );
					foreach ( $kat_opts as $kv => $kl ) :
						$href = esc_url( add_query_arg( array_filter( array(
							'kat'  => $kv,
							'q'    => '' !== $lq_q ? $lq_q : null,
							'sort' => 'neu' !== $lsort ? $lsort : null,
						) ), $hub_url ) );
						?>
						<a href="<?php echo $href; ?>" data-kat="<?php echo esc_attr( $kv ); ?>" class="<?php echo $lkat === $kv ? 'on' : ''; ?>" rel="nofollow"><?php echo esc_html( $kl ); ?></a>
					<?php endforeach; ?>
				</div>
				<div class="m24hub-viewsw" id="m24hub-viewsw" role="group" aria-label="Ansicht wählen">
					<button type="button" data-view="view-3" title="3 Spalten" aria-label="3 Spalten"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="1" y="2" width="3.2" height="12" rx="1"></rect><rect x="6.4" y="2" width="3.2" height="12" rx="1"></rect><rect x="11.8" y="2" width="3.2" height="12" rx="1"></rect></svg>3</button>
					<button type="button" data-view="view-4" class="on" title="4 Spalten" aria-label="4 Spalten"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="0.5" y="2" width="2.6" height="12" rx="0.8"></rect><rect x="4.5" y="2" width="2.6" height="12" rx="0.8"></rect><rect x="8.5" y="2" width="2.6" height="12" rx="0.8"></rect><rect x="12.5" y="2" width="2.6" height="12" rx="0.8"></rect></svg>4</button>
					<button type="button" data-view="view-list" title="Listenansicht" aria-label="Listenansicht"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="1" y="2.5" width="14" height="2.4" rx="1"></rect><rect x="1" y="6.8" width="14" height="2.4" rx="1"></rect><rect x="1" y="11.1" width="14" height="2.4" rx="1"></rect></svg>Liste</button>
				</div>
				<div class="m24hub-sortwrap">
					<label for="m24hub-sort">Sortieren:</label>
					<select id="m24hub-sort" name="sort">
						<option value="neu"<?php selected( $lsort, 'neu' ); ?>>Neueste zuerst</option>
						<option value="preis-auf"<?php selected( $lsort, 'preis-auf' ); ?>>Günstigste zuerst</option>
						<option value="preis-ab"<?php selected( $lsort, 'preis-ab' ); ?>>Teuerste zuerst</option>
					</select>
					<noscript><button type="submit" class="m24hub-resetq">Anwenden</button></noscript>
				</div>
			</div>
		</form>

		<div class="m24hub-grid view-4" id="m24hub-grid"><?php echo M24_Catalog_Hub::cards_html( $list ); /* phpcs:ignore WordPress.Security.EscapeOutput */ ?></div>
		<div id="m24hub-pagerwrap"><?php echo M24_Catalog_Hub::pager_html( $list, $hub_url ); /* phpcs:ignore WordPress.Security.EscapeOutput */ ?></div>
	</div></section>

	<?php
	// SEO-Textblock (editierbar im CPT; Fallback wie Mockup). Über dem Markenhinweis.
	$seo_html = ! empty( $cfg['seo_text_html'] )
		? wpautop( $cfg['seo_text_html'] )
		: '<h2>' . esc_html( $h1 ) . ' — laufend wechselnder Bestand</h2>'
			. '<p>' . sprintf( esc_html__( 'Bei MOTORSPORT24 finden Sie regelmäßig wechselnde Gebrauchtteile passend für den BMW %s — von Motorperipherie über Fahrwerk und Bremse bis zu Karosserie- und Interieur-Teilen. Da viele Teile aus einzelnen Rennsport-Umbauten stammen, lohnt sich ein regelmäßiger Blick oder eine gezielte Anfrage zu einem konkreten Teil.', 'm24-plattform' ), esc_html( $modell ) ) . '</p>';
	?>
	<?php if ( ! empty( $cfg['seo_text_html'] ) || ! empty( $cross ) ) : ?>
	<section class="m24hub-seo"><div class="m24hub-wrap">
		<?php echo wp_kses_post( $seo_html ); ?>
		<?php if ( ! empty( $cross ) ) : ?>
			<p class="links">Weitere Übersichten:
				<?php $i = 0; foreach ( $cross as $cl ) :
					if ( empty( $cl['url'] ) ) { continue; }
					echo $i++ ? '<span class="sep">·</span>' : ' ';
					printf( '<a href="%s">%s</a>', esc_url( $cl['url'] ), esc_html( $cl['label'] ?: $cl['url'] ) );
				endforeach; ?>
			</p>
		<?php endif; ?>
	</div></section>
	<?php endif; ?>

	<div class="m24hub-legal"><div class="m24hub-wrap">Alle genannten Marken- und Modellbezeichnungen (z. B. BMW, M3) sind Eigentum der jeweiligen Rechteinhaber und dienen ausschließlich der Beschreibung der Passgenauigkeit bzw. Herkunft der angebotenen Teile. MOTORSPORT24 steht in keiner Geschäftsverbindung zur BMW AG und ist kein autorisierter Händler.</div></div>
</div>

<script>
(function(){
	var root=document.getElementById('m24hub-slides'); if(!root) return;
	var slides=[].slice.call(root.querySelectorAll('.m24hub-slide'));
	var dotsWrap=document.getElementById('m24hub-dots'); var i=0,timer=null;
	var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	slides.forEach(function(_,n){var d=document.createElement('div');d.className='m24hub-dot'+(n===0?' on':'');d.addEventListener('click',function(){go(n);restart();});dotsWrap.appendChild(d);});
	var dots=[].slice.call(dotsWrap.children);
	function go(n){i=(n+slides.length)%slides.length;slides.forEach(function(s,k){s.classList.toggle('on',k===i);});dots.forEach(function(d,k){d.classList.toggle('on',k===i);});}
	function next(){go(i+1);}function prev(){go(i-1);}
	document.getElementById('m24hub-next').addEventListener('click',function(){next();restart();});
	document.getElementById('m24hub-prev').addEventListener('click',function(){prev();restart();});
	function start(){if(!reduce&&slides.length>1){timer=setInterval(next,5000);}}function restart(){clearInterval(timer);start();}
	root.addEventListener('mouseenter',function(){clearInterval(timer);});root.addEventListener('mouseleave',start);
	root.setAttribute('tabindex','0');
	root.addEventListener('keydown',function(e){if(e.key==='ArrowLeft'){prev();restart();}else if(e.key==='ArrowRight'){next();restart();}});
	var x0=null;
	root.addEventListener('touchstart',function(e){x0=e.touches[0].clientX;},{passive:true});
	root.addEventListener('touchend',function(e){if(x0===null)return;var dx=e.changedTouches[0].clientX-x0;if(Math.abs(dx)>40){dx<0?next():prev();restart();}x0=null;},{passive:true});
	start();
})();
</script>
<?php
get_footer();
