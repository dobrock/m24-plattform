<?php
/**
 * M24 Plattform — Katalog: Übersicht / Archiv (View-Template)
 * Wird via template_include von M24_Catalog_Archive geladen.
 * Reines Rendering; Query/Logik liegen im Controller.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$typ      = M24_Catalog_Archive::current_typ();
$heading  = M24_Catalog_Archive::heading();
$prefix   = M24_Catalog_Archive::current_prefix();
$modell   = M24_Catalog_Archive::current_modell();
$base_url = home_url( '/' . $prefix . '/' );

$intro = ( 'neu' === $typ )
	? 'Neuteile für den Motorsport – passend für BMW M-Modelle, im Rennsport bewährt.'
	: 'Original gebrauchte BMW-Teile, geprüft und sofort verfügbar. Eindeutig per Artikel- und BMW-Teilenummer.';

$big = 999999999;
$pag = paginate_links( array(
	'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
	'format'    => '',
	'current'   => max( 1, (int) get_query_var( 'paged' ) ),
	'total'     => (int) $GLOBALS['wp_query']->max_num_pages,
	'prev_text' => '‹ Zurück',
	'next_text' => 'Weiter ›',
	'type'      => 'list',
) );
?>
<div class="td-container m24-katalog-container">
<div class="m24-archiv">
	<nav class="m24-breadcrumb" aria-label="Brotkrumen">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="m24-breadcrumb__home" aria-label="Start" title="Start"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg></a>
		<span class="m24-breadcrumb__sep">›</span>
		<span class="m24-breadcrumb__current"><?php echo esc_html( $heading ); ?></span>
	</nav>

	<header class="m24-archiv__head">
		<h1 class="m24-archiv__title"><?php echo esc_html( $heading ); ?></h1>
		<p class="m24-archiv__intro"><?php echo esc_html( $intro ); ?></p>
	</header>

	<div class="m24-archiv__toolbar">
		<?php echo M24_Catalog_Archive::toolbar(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</div>

	<?php if ( have_posts() ) : ?>
		<div class="m24-archiv__grid m24-archiv__grid--4" id="m24-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				// Modell-gefiltertes Archiv → dieses Modell als Herkunfts-Kontext an die Breadcrumb mitgeben.
				echo M24_Catalog_Archive::card_html( get_the_ID(), true, (string) $modell ); // phpcs:ignore WordPress.Security.EscapeOutput
			endwhile;
			?>
		</div>
		<?php if ( $pag ) : ?>
			<nav class="m24-pagination" aria-label="Seiten"><?php echo $pag; // phpcs:ignore WordPress.Security.EscapeOutput ?></nav>
		<?php endif; ?>
	<?php else : ?>
		<p class="m24-archiv__empty">Aktuell sind in dieser Kategorie keine Teile verfügbar.<?php
			echo $modell ? ' <a href="' . esc_url( $base_url ) . '">Filter zurücksetzen</a>' : '';
		?></p>
	<?php endif; ?>
</div>
</div>

<?php
$schema = array(
	'@context'        => 'https://schema.org',
	'@type'           => 'BreadcrumbList',
	'itemListElement' => array(
		array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Start', 'item' => home_url( '/' ) ),
		array( '@type' => 'ListItem', 'position' => 2, 'name' => $heading, 'item' => $base_url ),
	),
);
echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>';
?>

<style>
/* Tokens + Saira + Karten-Komponente zentral: assets/css/m24-ci.css. Lokale --m24-* aliasen darauf (eine Wahrheitsquelle). */
.m24-archiv{--m24-ink:var(--ink);--m24-blue:var(--blue);--m24-brass:var(--brass);--m24-red:var(--red);--m24-surface:var(--surface);--m24-muted:var(--muted);width:100%;margin:34px 0 56px;color:var(--m24-ink);font-family:'Saira',sans-serif;}
.m24-archiv *{box-sizing:border-box;}
.m24-breadcrumb{font-size:12px;color:var(--m24-muted);display:flex;align-items:center;gap:8px;margin-bottom:12px;}
.m24-breadcrumb a{color:var(--m24-blue);text-decoration:none;display:inline-flex;align-items:center;}
.m24-breadcrumb svg{width:15px;height:15px;display:block;}
.m24-breadcrumb__current{color:var(--m24-muted);}
.m24-archiv__head{margin-bottom:20px;}
.m24-archiv__title{font-family:'Saira',sans-serif;font-weight:700;font-size:28px;line-height:1.12;margin:0 0 8px;}
.m24-archiv__intro{font-size:16px;color:var(--m24-muted);margin:0;}
.m24-archiv__toolbar{display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;padding:12px 0 18px;border-bottom:1px solid #e4e4e1;margin-bottom:24px;}
.m24-filter{display:flex;align-items:center;gap:8px;}
.m24-controls{display:flex;flex-wrap:wrap;align-items:center;gap:16px;}
.m24-control{display:flex;align-items:center;gap:8px;}
.m24-filter__label{font-size:14px;font-weight:600;}
.m24-filter__select{font:inherit;padding:8px 30px 8px 12px;border:1px solid #cfd2d6;border-radius:8px;background:#fff;color:var(--m24-ink);cursor:pointer;}
.m24-filter__select:focus{outline:2px solid var(--m24-blue);outline-offset:1px;}
/* Toolbar 1:1 zur Modell-Hub-Leiste — identische Klassen + CSS (aus catalog-hub-view.php
   gespiegelt, unter .m24-archiv gescoped, damit der Hub unangetastet bleibt). */
.m24-archiv{--m24hub-ctl-h:38px;}
.m24-archiv .m24hub-controls{display:flex;flex-wrap:nowrap;align-items:center;gap:12px;margin:0;}
.m24-archiv .m24hub-search{position:relative;flex:1 1 auto;min-width:0;}
.m24-archiv .m24hub-search input{width:100%;height:var(--m24hub-ctl-h);padding:0 14px 0 40px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:14px;background:#fff;color:var(--text);box-sizing:border-box;}
.m24-archiv .m24hub-search input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(23,99,173,.12);}
.m24-archiv .m24hub-search .si{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);}
.m24-archiv .m24hub-controls-right{display:flex;flex-wrap:nowrap;align-items:center;gap:10px;flex:0 0 auto;}
.m24-archiv .m24hub-sortwrap{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);white-space:nowrap;flex:0 0 auto;}
.m24-archiv .m24hub-sortwrap select{height:var(--m24hub-ctl-h);font-family:inherit;font-size:14px;line-height:1.2;padding:0 30px 0 12px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--text);cursor:pointer;box-sizing:border-box;}
.m24-archiv .m24hub-resetq{font-size:13px;color:var(--blue);white-space:nowrap;}
.m24-archiv .m24hub-katsw{display:inline-flex;height:var(--m24hub-ctl-h);border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#fff;flex:0 0 auto;box-sizing:border-box;}
.m24-archiv .m24hub-katsw a{display:inline-flex;align-items:center;padding:0 14px;cursor:pointer;color:var(--muted);border-left:1px solid var(--line);font-size:13px;font-weight:600;text-decoration:none;}
.m24-archiv .m24hub-katsw a:first-child{border-left:none;}
.m24-archiv .m24hub-katsw a.on{background:var(--blue);color:#fff;}
.m24-archiv .m24hub-katsw a:hover:not(.on){background:#f0f0ee;}
.m24-archiv .m24hub-katsw .m24hub-katn{font-weight:500;opacity:.7;font-size:12px;margin-left:1px;}
.m24-archiv .m24hub-viewsw{display:inline-flex;height:var(--m24hub-ctl-h);border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#fff;flex:0 0 auto;box-sizing:border-box;}
.m24-archiv .m24hub-viewsw button{border:none;background:#fff;padding:0 11px;cursor:pointer;color:var(--muted);display:flex;align-items:center;gap:6px;border-left:1px solid var(--line);font-family:inherit;font-size:12px;font-weight:600;}
.m24-archiv .m24hub-viewsw button:first-child{border-left:none;}
.m24-archiv .m24hub-viewsw button.on{background:var(--blue);color:#fff;}
.m24-archiv .m24hub-viewsw button:hover:not(.on){background:#f0f0ee;}
.m24-archiv .m24hub-viewsw svg{fill:currentColor;flex:0 0 auto;}
@media(max-width:900px){
	.m24-archiv .m24hub-controls{flex-wrap:wrap;gap:12px;}
	.m24-archiv .m24hub-search{flex:1 1 100%;max-width:none;order:-1;}
	.m24-archiv .m24hub-controls-right{width:100%;justify-content:flex-start;flex-wrap:wrap;}
}
.m24-archiv__grid{display:grid;gap:22px;}
.m24-archiv__grid--2{grid-template-columns:repeat(2,1fr);}
.m24-archiv__grid--3{grid-template-columns:repeat(3,1fr);}
.m24-archiv__grid--4{grid-template-columns:repeat(4,1fr);}
.m24-archiv__grid--list{grid-template-columns:1fr;gap:14px;}
/* .m24-card* (Komponente) zentral in assets/css/m24-ci.css — hier nur Listen-Overrides. */
/* Listenansicht: horizontal */
.m24-archiv__grid--list .m24-card__link{flex-direction:row;align-items:stretch;}
.m24-archiv__grid--list .m24-card__media{width:200px;flex:0 0 200px;aspect-ratio:4/3;}
.m24-archiv__grid--list .m24-card__body{justify-content:center;}
/* Beschreibung: nur in der Listenansicht, hart auf 2 Zeilen geklemmt. */
.m24-card__desc{display:none;}
.m24-archiv__grid--list .m24-card__desc{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-clamp:2;overflow:hidden;color:var(--m24-muted);font-size:14px;line-height:1.45;margin-top:6px;}
.m24-pagination{margin-top:34px;}
.m24-pagination ul{display:flex;flex-wrap:wrap;gap:6px;list-style:none;margin:0;padding:0;justify-content:center;}
.m24-pagination a,.m24-pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;padding:0 12px;border:1px solid #d7dadf;border-radius:8px;text-decoration:none;color:var(--m24-ink);font-weight:600;}
.m24-pagination .current{background:var(--m24-blue);border-color:var(--m24-blue);color:#fff;}
.m24-pagination a:hover{border-color:var(--m24-blue);color:var(--m24-blue);}
.m24-archiv__empty{padding:48px 0;text-align:center;color:var(--m24-muted);font-size:17px;}
.m24-archiv__empty a{color:var(--m24-blue);}
/* Responsive */
@media (max-width:1000px){.m24-archiv__grid--4{grid-template-columns:repeat(3,1fr);}}
@media (max-width:760px){.m24-archiv__grid--3,.m24-archiv__grid--4{grid-template-columns:repeat(2,1fr);}}
@media (max-width:520px){
	.m24-archiv__grid--2,.m24-archiv__grid--3,.m24-archiv__grid--4{grid-template-columns:1fr;}
	.m24-archiv__toolbar{justify-content:flex-start;}
	.m24-gridswitch__btn[data-grid="4"]{display:none;}
	.m24-archiv__grid--list .m24-card__media{width:120px;flex:0 0 120px;}
}
</style>
<script>
(function(){
	var grid = document.getElementById('m24-grid');
	var sw   = document.getElementById('m24-archiv-viewsw');
	if(!grid || !sw){ return; }
	// KEY v2: alte „2er"-Stände (Schlüssel m24_grid) werden ignoriert → Default 4.
	var KEY = 'm24_grid2';
	var map = { 'view-3':'3', 'view-4':'4', 'view-list':'list' };
	function apply(view){
		// Legacy-Werte (0.9.14: '3'/'4'/'list', alt '2') auf view-* normalisieren.
		if(view==='3'||view==='4'||view==='list'){ view='view-'+view; }
		if(!map[view]){ view='view-4'; } // „2"/unbekannt → 4 (nie wiederherstellen)
		var v = map[view];
		['list','2','3','4'].forEach(function(c){ grid.classList.remove('m24-archiv__grid--'+c); });
		grid.classList.add('m24-archiv__grid--'+v);
		sw.querySelectorAll('button[data-view]').forEach(function(b){
			b.classList.toggle('on', b.getAttribute('data-view') === view);
		});
	}
	var saved = null;
	try { saved = localStorage.getItem(KEY); } catch(e){}
	apply(saved || 'view-4');
	sw.addEventListener('click', function(e){
		var b = e.target.closest('button[data-view]');
		if(!b){ return; }
		var view = b.getAttribute('data-view');
		apply(view);
		try { localStorage.setItem(KEY, view); } catch(e){}
	});
})();
</script>
<?php
get_footer();
