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
$count  = M24_Catalog_Hub::count( $hub );
$images = M24_Catalog_Hub::images( $hub );                 // Term-Meta-Bilder (leer ⇒ Platzhalter)
$ph     = max( 1, (int) apply_filters( 'm24_hub_slide_count', 3, $hub ) ); // Platzhalter-Anzahl ohne Bilder
$slides = ! empty( $images ) ? count( $images ) : $ph;
$crumb  = home_url( '/gebrauchtteile/' );

get_header();

// BreadcrumbList (eine Quelle aus dem Plugin).
$ld = array(
	'@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
	'itemListElement' => array(
		array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Gebrauchte Teile', 'item' => $crumb ),
		array( '@type' => 'ListItem', 'position' => 2, 'name' => 'passend für BMW ' . $modell ),
	),
);
?>
<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
<style>
/* Design-Tokens (:root) + Karten-Komponente zentral: assets/css/m24-ci.css. */
.m24hub{font-family:'Saira',Helvetica,Arial,sans-serif;color:var(--text);line-height:1.6}
.m24hub .m24hub-wrap{max-width:1180px;margin:0 auto;padding:0 24px}
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
.m24hub .m24-archiv__grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
@media(max-width:900px){.m24hub .m24-archiv__grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.m24hub .m24-archiv__grid{grid-template-columns:1fr}}
.m24hub .m24hub-legal{background:var(--surface);border-top:1px solid var(--line)}
.m24hub .m24hub-legal .m24hub-wrap{padding:20px 24px;font-size:12.5px;color:var(--muted);max-width:1000px}
.m24hub .m24hub-empty{color:var(--muted);font-style:italic;padding:18px 0}
</style>

<div class="m24hub">
	<div class="m24hub-crumb"><div class="m24hub-wrap"><a href="<?php echo esc_url( $crumb ); ?>">Gebrauchte Teile</a><span class="sep">›</span>passend für BMW <?php echo esc_html( $modell ); ?></div></div>

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
		<div class="m24hub-tcell"><div class="k">Modell</div><div class="v"><?php echo esc_html( $modell ); ?></div></div>
		<div class="m24hub-tcell"><div class="k">Motor</div><div class="v"><?php echo esc_html( $cfg['motor'] ?? '—' ); ?></div></div>
		<div class="m24hub-tcell"><div class="k">Baujahre</div><div class="v"><?php echo esc_html( $cfg['baujahre'] ?? '—' ); ?></div></div>
		<div class="m24hub-tcell"><div class="k"><span class="m24hub-livedot"></span>Aktuell verfügbar</div><div class="v"><?php echo esc_html( sprintf( _n( '%s Teil', '%s Teile', $count, 'm24-plattform' ), number_format_i18n( $count ) ) ); ?></div></div>
	</div></div>

	<?php if ( ! empty( $cfg['intro_html'] ) || ! empty( $cfg['intro'] ) ) : ?>
	<section class="m24hub-intro"><div class="m24hub-wrap">
		<?php if ( ! empty( $cfg['intro_h2'] ) ) : ?><h2><?php echo esc_html( $cfg['intro_h2'] ); ?></h2><?php endif; ?>
		<?php if ( ! empty( $cfg['intro_html'] ) ) : ?>
			<?php echo wp_kses_post( wpautop( $cfg['intro_html'] ) ); ?>
		<?php else : ?>
			<?php foreach ( (array) $cfg['intro'] as $p ) : ?><p><?php echo esc_html( $p ); ?></p><?php endforeach; ?>
		<?php endif; ?>
	</div></section>
	<?php endif; ?>

	<div class="m24hub-trust"><div class="m24hub-wrap">
		<div class="it"><span class="ic">&#9733;</span><div><div class="tt">seit 2006</div><div class="ts">auf BMW spezialisiert</div></div></div>
		<div class="it"><span class="ic">&#9992;</span><div><div class="tt">weltweiter Versand</div><div class="ts">B2B / gewerblich</div></div></div>
		<div class="it"><span class="ic">&#10003;</span><div><div class="tt">geprüfte Teile</div><div class="ts">vor dem Verkauf kontrolliert</div></div></div>
		<div class="it"><span class="ic">&#9737;</span><div><div class="tt">55.000+ Community</div><div class="ts">Werkstätten &amp; Sammler</div></div></div>
	</div></div>

	<section class="m24hub-parts"><div class="m24hub-wrap">
		<div class="head"><h2>Teile passend für BMW <?php echo esc_html( $modell ); ?></h2><span class="count"><?php echo esc_html( sprintf( _n( '%s Teil', '%s Teile', $count, 'm24-plattform' ), number_format_i18n( $count ) ) ); ?> · sortiert nach Neuheit</span></div>
		<?php
		$pq = M24_Catalog_Hub::parts_query( $hub );
		if ( $pq->have_posts() && class_exists( 'M24_Catalog_Archive' ) ) : ?>
			<div class="m24-archiv__grid">
				<?php while ( $pq->have_posts() ) : $pq->the_post(); echo M24_Catalog_Archive::card_html( get_the_ID() ); /* phpcs:ignore */ endwhile; ?>
			</div>
		<?php else : ?>
			<p class="m24hub-empty">Aktuell sind keine Teile für dieses Modell gelistet. Fragen Sie uns — wir haben mehr im Bestand, als online steht.</p>
		<?php endif; wp_reset_postdata(); ?>
	</div></section>

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
