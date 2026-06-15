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
		<?php echo M24_Catalog_Archive::controls_form(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php echo M24_Catalog_Archive::grid_toggle(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</div>

	<?php if ( have_posts() ) : ?>
		<div class="m24-archiv__grid m24-archiv__grid--3" id="m24-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				echo M24_Catalog_Archive::card_html( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput
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
@import url('https://fonts.googleapis.com/css2?family=Saira:wght@400;500;600;700&display=swap');
.m24-archiv{--m24-ink:#14161a;--m24-blue:#1763ad;--m24-brass:#9a6b25;--m24-red:#9e2b2b;--m24-surface:#f4f4f2;--m24-muted:#6b7077;width:100%;margin:34px 0 56px;color:var(--m24-ink);font-family:'Saira',sans-serif;}
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
.m24-gridswitch{display:flex;gap:4px;background:var(--m24-surface);padding:4px;border-radius:10px;}
.m24-gridswitch__btn{font:inherit;font-size:13px;font-weight:600;min-width:38px;padding:7px 10px;border:0;border-radius:7px;background:transparent;color:var(--m24-muted);cursor:pointer;line-height:1;}
.m24-gridswitch__btn[aria-pressed="true"]{background:#fff;color:var(--m24-ink);box-shadow:0 1px 3px rgba(0,0,0,.12);}
.m24-archiv__grid{display:grid;gap:22px;}
.m24-archiv__grid--2{grid-template-columns:repeat(2,1fr);}
.m24-archiv__grid--3{grid-template-columns:repeat(3,1fr);}
.m24-archiv__grid--4{grid-template-columns:repeat(4,1fr);}
.m24-archiv__grid--list{grid-template-columns:1fr;gap:14px;}
.m24-card{background:#fff;border:1px solid #e8e8e6;border-radius:12px;overflow:hidden;transition:box-shadow .18s,transform .18s;}
.m24-card:hover{box-shadow:0 8px 24px rgba(20,22,26,.12);transform:translateY(-2px);}
.m24-card__link{display:flex;flex-direction:column;text-decoration:none;color:inherit;height:100%;}
.m24-card__media{position:relative;display:block;aspect-ratio:4/3;background:var(--m24-surface);overflow:hidden;}
.m24-card__media img{width:100%;height:100%;object-fit:cover;display:block;}
.m24-card__noimg{display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:13px;letter-spacing:.12em;color:#b9bcc0;font-weight:700;}
.m24-card__noimg--ph{background-size:cover;background-position:center;background-repeat:no-repeat;}
.m24-card__badge{position:absolute;top:10px;left:10px;font-size:12px;font-weight:700;letter-spacing:.04em;padding:4px 10px;border-radius:6px;color:#fff;text-transform:uppercase;}
.m24-card__badge--sold{background:var(--m24-red);}
.m24-card__body{display:flex;flex-direction:column;gap:6px;padding:14px 15px 16px;flex:1;}
.m24-card__title{font-size:16px;line-height:1.3;font-weight:600;margin:0;}
.m24-card__meta{font-size:12.5px;color:var(--m24-muted);}
.m24-card__pricewrap{margin-top:auto;padding-top:8px;display:flex;flex-direction:column;}
.m24-card__price{font-size:19px;font-weight:700;color:var(--m24-brass);}
.m24-card__price--ask{font-size:16px;}
.m24-card__pricenote{font-size:11.5px;color:var(--m24-muted);}
/* Listenansicht: horizontal */
.m24-archiv__grid--list .m24-card__link{flex-direction:row;align-items:stretch;}
.m24-archiv__grid--list .m24-card__media{width:200px;flex:0 0 200px;aspect-ratio:4/3;}
.m24-archiv__grid--list .m24-card__body{justify-content:center;}
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
	var sw   = document.querySelector('.m24-gridswitch');
	if(!grid || !sw){ return; }
	var KEY = 'm24_grid';
	var classes = ['list','2','3','4'];
	function apply(v){
		if(classes.indexOf(v) === -1){ v = '3'; }
		classes.forEach(function(c){ grid.classList.remove('m24-archiv__grid--'+c); });
		grid.classList.add('m24-archiv__grid--'+v);
		sw.querySelectorAll('.m24-gridswitch__btn').forEach(function(b){
			b.setAttribute('aria-pressed', b.getAttribute('data-grid') === v ? 'true' : 'false');
		});
	}
	var saved = null;
	try { saved = localStorage.getItem(KEY); } catch(e){}
	apply(saved || '3');
	sw.addEventListener('click', function(e){
		var b = e.target.closest('.m24-gridswitch__btn');
		if(!b){ return; }
		var v = b.getAttribute('data-grid');
		apply(v);
		try { localStorage.setItem(KEY, v); } catch(e){}
	});
})();
</script>
<?php
get_footer();
