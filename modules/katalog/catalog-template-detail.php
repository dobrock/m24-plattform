<?php
/**
 * M24 Plattform — Katalog: Detail-Template (Front-End)  [Design-Spec v2 §5]
 * Modul: catalog-template-detail.php
 *
 * Theme-Container, 50/50-Grid, 4:3-Galerie ungeschnitten. Interaktive Galerie
 * mit Fade-Übergang, Pfeile + Pfeiltasten (Hover) + Lightbox (Bilderreihe rechts).
 * Breadcrumb mit Haus-Icon + BreadcrumbList-Schema. Beschreibung/Passend-für als
 * Tabs. Related-Teile aus derselben Kategorie. Voll responsive.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Template_Detail {

	const PT = 'm24_teil';

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'route' ) );
		add_action( 'wp_head', array( __CLASS__, 'inject_layout_css' ), 100 );
	}

	/**
	 * Katalog-Content (Detail + Archiv) auf Footer-Container-Breite bringen:
	 * weisser Hintergrund, max-width ~1164px, ~43px Side-Padding.
	 * Scoped via body-Classen — kein globaler Eingriff in .td-container.
	 */
	public static function inject_layout_css() {
		$is_detail  = is_singular( self::PT );
		$is_archive = class_exists( 'M24_Catalog_Archive' ) && M24_Catalog_Archive::is_archive();
		if ( ! $is_detail && ! $is_archive ) { return; }
		?>
		<style id="m24-catalog-layout">
		/* Scope ueber eigene Klasse .m24-katalog-container am Detail-/Archiv-
		   Wrapper — Footer-.td-container bleibt unangetastet. Width-Mechanik
		   spiegelt .td-footer-container (1164/43). */
		.td-container.m24-katalog-container{
			width:1164px!important;
			max-width:100%!important;
			margin-left:auto!important;
			margin-right:auto!important;
			padding-left:43px!important;
			padding-right:43px!important;
			background:#fff!important;
			box-sizing:border-box!important;
		}
		@media(max-width:760px){
			.td-container.m24-katalog-container{
				width:auto!important;
				padding-left:16px!important;
				padding-right:16px!important;
			}
		}
		<?php if ( $is_detail ) : ?>
		/* tagDiv-Wrapper .td-theme-wrap nutzt overflow:hidden → bricht position:sticky
		   (.m24-right-inner scrollt weg, top wird negativ). overflow:clip clippt wie hidden
		   (kein horizontaler Scroll), erzeugt aber KEINEN Scroll-Container → Sticky laeuft;
		   die Boundary ueber .right bleibt unberuehrt. Nur Katalog-Detailseiten. */
		.td-theme-wrap{overflow:clip!important}
		<?php endif; ?>
		</style>
		<?php
	}

	public static function route( $template ) {
		if ( ! is_singular( self::PT ) ) {
			return $template;
		}
		$id     = get_queried_object_id();
		$status = get_post_meta( $id, '_m24_status', true ) ?: 'aktiv';

		if ( 'ausgeblendet' === $status && ! current_user_can( 'edit_posts' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return get_query_template( '404' );
		}

		self::render( $id, $status );
		exit;
	}

	private static function images( $id ) {
		$ids  = array();
		$feat = get_post_thumbnail_id( $id );
		if ( $feat ) { $ids[] = (int) $feat; }
		foreach ( array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $id, '_m24_galerie', true ) ) ) ) as $gid ) {
			if ( $gid !== (int) $feat ) { $ids[] = $gid; }
		}
		$out = array();
		foreach ( $ids as $iid ) {
			$full = wp_get_attachment_image_url( $iid, 'large' );
			if ( ! $full ) { continue; }
			$out[] = array( 'full' => $full, 'thumb' => wp_get_attachment_image_url( $iid, 'medium' ) ?: $full );
		}
		return $out;
	}

	private static function render( $id, $status ) {
		$preis      = M24_Catalog_Pricing::get( $id );          // Default-Option (Backward-Compat)
		$opts_data  = M24_Catalog_Pricing::get_options( $id );  // Volle Options-Liste + Aggregate
		$note_parts = M24_Catalog_Pricing::note_parts( $preis['modus'] );
		$artnr      = get_post_meta( $id, '_m24_artikelnummer', true );
		$bmwnr      = get_post_meta( $id, '_m24_bmw_teilenummer', true );
		$stand      = get_post_meta( $id, '_m24_stand', true );
		$hinweis    = get_post_meta( $id, '_m24_hinweis', true );
		$desc       = get_post_meta( $id, '_m24_beschreibung_de', true );
		$typ        = get_post_meta( $id, '_m24_typ', true ) ?: 'gebraucht';
		$rennsport_hinweis_flag = (bool) (int) get_post_meta( $id, '_m24_rennsport_hinweis', true );
		// E (Rennsport-Hinweis): wenn _m24_hinweis leer, Standardtext zeigen wenn
		//   (a) Per-Teil-Checkbox _m24_rennsport_hinweis = 1 (Gebraucht oder Neu), ODER
		//   (b) typ='neu' (Backward-Compat — Rennsport-Teile default-on).
		if ( '' === trim( (string) $hinweis ) && ( $rennsport_hinweis_flag || 'neu' === $typ ) ) {
			$hinweis = m24_rennsport_hinweis();
		}
		// H.3: Logo-Anzeigen-Default = true (Meta leer = Default an).
		$logo_raw     = get_post_meta( $id, '_m24_logo_anzeigen', true );
		$logo_enabled = ( '' === $logo_raw ) ? true : (bool) (int) $logo_raw;
		$leichtbau    = (bool) (int) get_post_meta( $id, '_m24_leichtbau', true );
		$terms      = get_the_terms( $id, M24_Catalog_CPT::TAXONOMY );
		$term_names = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array();
		$verkauft   = ( 'verkauft' === $status );
		$preis_auf_anfrage = (bool) get_post_meta( $id, '_m24_preis_auf_anfrage', true );
		$is_neu     = ( 'neu' === $typ );
		$typ_label  = $is_neu ? 'Rennsport Teile' : 'Gebrauchte Teile';
		$typ_url    = home_url( $is_neu ? '/rennsport-teile/' : '/gebrauchtteile/' );
		$home       = home_url( '/' );

		$imgs   = self::images( $id );
		$shown  = array_slice( $imgs, 0, 5 );           // max. 5 Kacheln, eine Reihe
		$extra  = max( 0, count( $imgs ) - 5 );          // >5 → 5. Kachel abgedunkelt „+N"
		// Bildloser Platzhalter (CSS-Background → kein <img>, nicht zoombar/indexierbar). Zentrale Quelle.
		$noimg_url = function_exists( 'm24_noimg_placeholder_url' ) ? m24_noimg_placeholder_url() : '';

		// „Weitere Teile" = manuelle Pins zuerst, dann Auto-Auffuellung (Modell → Baugruppe),
		// stabile Reihenfolge, nur verfuegbare Teile. Siehe M24_Catalog_Related.
		$related = class_exists( 'M24_Catalog_Related' ) ? M24_Catalog_Related::get( $id, 5 ) : array();

		$ld = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array(
				array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Start', 'item' => $home ),
				array( '@type' => 'ListItem', 'position' => 2, 'name' => $typ_label, 'item' => $typ_url ),
				array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title( $id ) ),
			),
		);

		// Product-Structured-Data (Schema.org) — Offer/price/condition/mpn/sku.
		$product_ld = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => get_the_title( $id ),
			'url'      => get_permalink( $id ),
		);
		if ( $artnr ) {
			$product_ld['sku'] = $artnr;
		}
		if ( $bmwnr ) {
			$product_ld['mpn']   = $bmwnr;
			$product_ld['brand'] = array( '@type' => 'Brand', 'name' => 'BMW' );
		}
		if ( $imgs ) {
			$product_ld['image'] = wp_list_pluck( $imgs, 'full' );
		}
		if ( $desc ) {
			$product_ld['description'] = mb_substr( wp_strip_all_tags( $desc ), 0, 2000 );
		}
		if ( $preis['brutto'] > 0 && ! $preis_auf_anfrage ) {
			$avail  = $verkauft ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock';
			$cond   = $is_neu ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition';
			$opts   = isset( $opts_data['options'] ) ? $opts_data['options'] : array();
			$ncount = count( $opts );
			if ( $ncount > 1 && ! empty( $opts_data['agg'] ) ) {
				$product_ld['offers'] = array(
					'@type'         => 'AggregateOffer',
					'lowPrice'      => number_format( (float) $opts_data['agg']['low'],  2, '.', '' ),
					'highPrice'     => number_format( (float) $opts_data['agg']['high'], 2, '.', '' ),
					'priceCurrency' => 'EUR',
					'offerCount'    => $ncount,
					'availability'  => $avail,
					'itemCondition' => $cond,
					'url'           => get_permalink( $id ),
				);
			} else {
				$product_ld['offers'] = array(
					'@type'         => 'Offer',
					'price'         => number_format( (float) $preis['brutto'], 2, '.', '' ),
					'priceCurrency' => 'EUR',
					'availability'  => $avail,
					'itemCondition' => $cond,
					'url'           => get_permalink( $id ),
				);
			}
		}

		// SEO-Autofill: leere wpSEO-Meta vor wp_head (get_header) befuellen.
		if ( class_exists( 'M24_Catalog_SEO' ) ) { M24_Catalog_SEO::fill_if_empty( $id ); }

		get_header();
		?>
		<style>
		@import url('https://fonts.googleapis.com/css2?family=Saira:wght@400;500;700&display=swap');
		.m24det{--ink:#14161a;--line:rgba(0,0,0,.12);--tx:#1b1e22;--mut:#6b7077;--surf:#f4f4f2;--blue:#1763ad;--blued:#0e447e;--bronze:#9a6b25;--red:#9e2b2b;--m24-sticky-top:100px;width:100%;margin:22px 0 44px;color:var(--tx)}
		.m24det *{box-sizing:border-box}
		.m24det .bc{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--mut);margin-bottom:12px}
		.m24det .bc a{color:var(--blue);text-decoration:none;display:inline-flex;align-items:center}
		.m24det .bc svg{width:15px;height:15px;display:block}
		.m24det h1{font-family:'Saira',sans-serif;font-weight:700;font-size:28px;line-height:1.12;margin:0 0 20px}
		.m24det .m24-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:0 0 20px}
		.m24det .m24-detail-head h1{margin:0;flex:1;min-width:0}
		.m24det .m24-detail-head .m24-detail-logo{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;height:48px}
		.m24det .m24-detail-head .m24-detail-logo img{max-height:42px;max-width:140px;width:auto;height:auto;display:block;object-fit:contain}
		/* „Original BMW-Teil"-Badge (ersetzt das BMW-Rundel — Markenrecht). */
		.m24-original-badge{display:inline-flex;align-items:center;gap:8px;background:#f3f3f0;border:1px solid #d2d2cb;border-radius:8px;padding:8px 14px;line-height:1;color:#111;font-family:"Helvetica Neue",Helvetica,Arial,sans-serif}
		.m24-original-badge__icon{flex:0 0 auto;color:#111}
		.m24-original-badge__text{font-size:15px;font-weight:500;letter-spacing:.3px}
		@media(max-width:767px){.m24-original-badge{padding:7px 12px}.m24-original-badge__text{font-size:14px}}
		.m24det .row{display:grid;grid-template-columns:3fr 2fr;gap:48px}
		.m24det .left,.m24det .right{min-width:0;display:flex;flex-direction:column}
		.m24det .ratio{position:relative;width:100%;aspect-ratio:3/2;background:#ededea;border:1px solid var(--line);border-radius:10px;overflow:hidden;cursor:zoom-in}
		.m24det .ratio img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center;transition:transform 280ms ease,opacity 280ms ease;will-change:transform,opacity}
		.m24det .ratio img.m24-fade{opacity:0}
		.m24det .ratio img.m24-slide-from-right{transform:translateX(100%);opacity:0;transition:none}
		.m24det .ratio img.m24-slide-from-left{transform:translateX(-100%);opacity:0;transition:none}
		.m24det .ratio img.m24-slide-to-left{transform:translateX(-100%);opacity:0}
		.m24det .ratio img.m24-slide-to-right{transform:translateX(100%);opacity:0}
		@media(prefers-reduced-motion:reduce){.m24det .ratio img{transition:none!important}}
		/* Bildloser Platzhalter: reguläre Hauptbild-Fläche (volle Spaltenbreite, 3:2) als CSS-Background
		   (kein <img>) — optisch wie ein echtes Hauptbild, aber nicht zoombar/nicht in Image-Sitemaps. */
		.m24det .m24-noimg-box{width:100%;aspect-ratio:3/2;border:1px solid var(--line);border-radius:10px;background-color:#ededea;background-position:center;background-size:cover;background-repeat:no-repeat;cursor:default}
		/* Related-/Weitere-Teile-Thumbnail-Platzhalter (CSS-Background, kein <img>). */
		.m24det .ritem .rimg.rimg-noimg{background-size:cover;background-position:center;background-repeat:no-repeat}
		.m24det .nav-arrow{position:absolute;top:50%;transform:translateY(-50%);width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.88);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2;color:var(--tx);font-size:18px}
		.m24det .nav-arrow.prev{left:10px}.m24det .nav-arrow.next{right:10px}
		.m24det .thumbs{display:flex;flex-wrap:nowrap;gap:10px;margin-top:10px}
		.m24det .thumbs .t{flex:1 1 0;min-width:0;max-width:120px;aspect-ratio:3/2;border:1px solid var(--line);border-radius:6px;overflow:hidden;position:relative;background:#fff;cursor:pointer}
		.m24det .thumbs .t img{width:100%;height:100%;object-fit:cover;background:#fff}
		.m24det .thumbs .t.active{border-color:var(--line);border-width:1px}
		.m24det .ratio img{outline:none}
		.m24det .ratio img:focus,.m24det .ratio img:focus-visible{outline:none}
		.m24det .thumbs .t:focus,.m24det .thumbs .t:focus-visible{outline:none}
		.m24det .thumbs .more{position:absolute;inset:0;background:rgba(20,22,26,.66);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Saira',sans-serif;font-weight:700;font-size:17px}
		.m24det .pbr{font-family:'Saira',sans-serif;font-weight:700;font-size:26px;color:var(--bronze)}
		.m24det .m24-preis-anfrage{font-family:'Saira',sans-serif;font-weight:700;font-size:22px;color:var(--blued)}
		.m24det .pnet{font-size:13.5px;margin-top:5px}
		.m24det .pnet .lnk{display:inline-flex;align-items:center;gap:4px;color:var(--blue);cursor:help;vertical-align:baseline}
		.m24det .pnet .lnk-i{width:13px;height:13px;flex:0 0 auto;display:block}
		.m24det .pnet .lnk-tx{border-bottom:1px dotted var(--blue)}
		.m24det .pbr .pstar{font-size:.5em;vertical-align:super;font-weight:400;color:var(--mut);margin-left:2px}
		.m24det .pnote{font-size:11.5px;color:var(--mut);margin-top:3px}
		.m24det .m24-tip{border-bottom:1px dotted var(--mut);cursor:help}
		.m24det .vbadge{display:inline-block;background:var(--red);color:#fff;font-family:'Saira',sans-serif;font-weight:700;font-size:14px;letter-spacing:1px;padding:8px 16px;border-radius:7px}
		.m24det .sheet{margin:28px 0 18px}
		.m24det .slabel{display:block;font-family:'Saira',sans-serif;font-size:12px;letter-spacing:1.5px;color:var(--bronze);margin-bottom:4px;text-transform:uppercase}
		.m24det .srow{display:grid;grid-template-columns:110px 1fr;gap:12px;align-items:start;font-size:13.5px;padding:9px 0;border-bottom:1px solid var(--line)}
		.m24det .srow .k{color:var(--mut);white-space:nowrap}
		.m24det .srow .v{font-family:monospace;min-width:0;word-break:break-word}
		.m24det .sheet .slabel + .srow{padding-top:5px}
		/* Sticky rechter Info-Block (Preis + Teile-Daten + Buttons) als EINHEIT:
		   folgt beim Scrollen bis Viewport-Top (Offset --m24-sticky-top = unter Theme-Headerbar).
		   Stop-Boundary = Unterkante von .right (per Grid-Stretch = Unterkante Thumbnail-Strip),
		   dort released der Sticky. Gilt fuer 2- und 3-Button-(Varianten-)Fall. */
		/* T2: EIN sticky-Element (kompakt, content-hoch) — Preis/Buttons + Trennlinie + Trust stacken eng
		   (flex column). Da das Element kuerzer ist als die linke Galerie-Spalte, klebt es beim Scrollen
		   am top und bleibt als Block zusammen gepinnt. KEIN flex:1/Fill (das hob das Pinnen auf, weil
		   der Block dann die ganze Spalte fuellte); Trust liegt IM Element (rutscht nicht weg wie 0.7.2). */
		.m24det .m24-right-inner{position:sticky;top:var(--m24-sticky-top);z-index:1;display:flex;flex-direction:column}
		.m24det .m24-actions-group{display:flex;flex-direction:column}
		.m24det .m24-varianten-wrap{margin:0}
		.m24det .m24-varianten{appearance:none;-webkit-appearance:none;-moz-appearance:none;width:100%;height:46px;padding:0 38px 0 14px;border:0.5px solid var(--line);border-radius:8px;background:#fafafa url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%231b1e22' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") no-repeat right 14px center/12px 8px;font-family:'Saira',sans-serif;font-size:15px;font-weight:500;color:var(--tx);cursor:pointer;line-height:1}
		.m24det .m24-varianten:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
		.m24det .fade{position:relative;max-width:60%;overflow:hidden;white-space:nowrap;font-family:inherit;cursor:help}
		.m24det .fade::after{content:'';position:absolute;right:0;top:0;bottom:0;width:48px;background:linear-gradient(to right,rgba(255,255,255,0),#fff)}
		.m24det .actions{margin-top:10px;padding-top:0}
		.m24det .m24-varianten-wrap .slabel{margin-bottom:12px}
		/* CTA-Buttons: geteiltes Button-System (Tokens aus assets/css/m24-ci.css). */
		.m24det .btn{display:flex;align-items:center;justify-content:center;gap:8px;border-radius:var(--m24-btn-radius,8px);padding:var(--m24-btn-pad,13px 18px);font-size:14px;font-weight:var(--m24-btn-weight,600);line-height:1.1;cursor:pointer;width:100%;font-family:'Saira',sans-serif;text-decoration:none;border:1px solid transparent;transition:filter .15s,box-shadow .15s,transform .15s,background-color .15s}
		.m24det .btn-pri{background:var(--m24-btn-grad,linear-gradient(135deg,#1f74c4,#0e447e));color:#fff;border-color:transparent;margin-bottom:10px}
		.m24det .btn-pri:hover{filter:brightness(1.06);box-shadow:var(--m24-btn-shadow,0 6px 18px rgba(15,68,126,.28));transform:translateY(-1px)}
		.m24det .btn-pri:active{filter:brightness(.97);transform:translateY(0);box-shadow:none}
		.m24det .btn-sec{background:#fff;color:var(--grad-b,#0e447e);border:1px solid var(--blue)}
		.m24det .btn-sec:hover{background:#f0f6fc;transform:translateY(-1px)}
		.m24det .btn-sec:active{background:#e6eff7;transform:translateY(0)}
		.m24det .btn:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
		.m24det .btn .m24-btn-i{width:17px;height:17px;flex:0 0 auto}
		/* Trust-Zeile: zwei zentrierte Icon-Text-Paare, per feiner Linie abgesetzt. Liegt als LETZTES
		   Element INNERHALB der sticky Preisbox (.m24-right-inner), direkt unter den Buttons —
		   kompakt mit ~16px Abstand, scrollt sticky mit (kein margin-top:auto, kein Geschwister). */
		.m24det .m24-trust{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:6px 12px;margin-top:16px;padding-top:14px;border-top:1px solid var(--line);color:var(--mut);font-size:12px}
		.m24det .m24-trust-i{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
		.m24det .m24-trust-svg{width:15px;height:15px;flex:0 0 auto}
		.m24det .m24-trust-dot{color:var(--line)}
		.m24det .m24-tabs{margin-top:32px}
		.m24det .tabbar{display:flex;gap:2px;border-bottom:2px solid var(--line)}
		.m24det .tab{font-family:'Saira',sans-serif;font-weight:600;font-size:15px;padding:12px 24px;border:none;background:none;cursor:pointer;color:var(--mut);border-bottom:3px solid transparent;margin-bottom:-2px}
		.m24det .tab.active{color:var(--ink);border-bottom-color:var(--blue)}
		.m24det .tabpanel{padding:20px 2px;font-size:14px;line-height:1.65}
		.m24det .tabpanel[data-panel="desc"],.m24det .tabpanel[data-panel="fit"],.m24det .tabpanel[data-panel="manufacture"]{font-size:18px;line-height:1.75;max-width:none}
		.m24det .tabpanel[data-panel="desc"] p,.m24det .tabpanel[data-panel="fit"] p,.m24det .tabpanel[data-panel="manufacture"] p{margin:0 0 1.1em}
		.m24det .tabpanel[data-panel="desc"] ul,.m24det .tabpanel[data-panel="desc"] ol,.m24det .tabpanel[data-panel="fit"] ul,.m24det .tabpanel[data-panel="fit"] ol{margin:0 0 1.1em;padding-left:1.5em}
		.m24det .tabpanel[data-panel="desc"] li,.m24det .tabpanel[data-panel="fit"] li{margin:0 0 .4em}
		/* Bewertungs-Karte (Variante C) — rechts im Beschreibungsbereich gefloatet. */
		/* Trust-Karte: Breite an die Action-Button-Spalte (2fr) angeglichen ≈ 66 % der Textspalte (3fr),
		   flach/kompakt — wenig Padding, Kopf eng, Zitat auf 3 Zeilen gekappt → ragt nicht in „Weitere Teile". */
		/* Breite EXAKT = Action-Button-Spalte (2fr der Zeile): calc((100% − gap) × 2/5), gap=48px.
		   Das desc-Panel ist volle Containerbreite, daher relativ dazu rechnen (nicht 66%). */
		.m24det .m24-review-card{box-sizing:border-box;float:right;width:calc((100% - 48px) * 0.4);max-width:none;margin:2px 0 14px 28px;border:1px solid var(--line);border-radius:12px;padding:12px 15px;background:#fbfaf8;box-shadow:0 3px 12px rgba(0,0,0,.05);font-size:14px}
		.m24det .m24-rc-head{display:flex;align-items:baseline;gap:8px}
		/* T4: Sterne via ::before-Content — IMMER genau 5 Glyphen, --rating fuellt anteilig (4,9 = 98%).
		   Robust gegen Theme/WP-Rocket (kein Inline-Width, keine zwei Glyph-Strings nebeneinander). */
		.m24det .m24-stars{position:relative!important;display:inline-block;line-height:1;font-size:16px;white-space:nowrap}
		.m24det .m24-stars::before{content:"\2605\2605\2605\2605\2605";color:#d8d8d8;letter-spacing:1.5px}
		.m24det .m24-stars__fill{position:absolute!important;left:0;top:0;bottom:0;overflow:hidden;width:calc(var(--rating) / 5 * 100%);white-space:nowrap}
		.m24det .m24-stars__fill::before{content:"\2605\2605\2605\2605\2605";color:#f5a623;letter-spacing:1.5px}
		.m24det .m24-rc-avg{font-family:'Saira',sans-serif;font-size:18px;font-weight:700;color:var(--ink)}
		.m24det .m24-rc-count{font-size:12px;color:var(--mut);margin-top:1px}
		.m24det .m24-rc-item{display:none}
		.m24det .m24-rc-item.is-active{display:block}
		.m24det .m24-rc-quote{margin:8px 0 4px;font-size:13.5px;line-height:1.42;color:var(--tx);font-style:italic;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
		.m24det .m24-rc-author{font-size:12.5px;color:var(--mut);font-weight:600}
		.m24det .m24-rc-link{display:inline-block;margin-top:8px;font-size:12.5px;font-weight:700;color:var(--blue);text-decoration:none}
		.m24det .m24-rv-tab-btn{display:none} /* Reviews-Tab nur mobil (siehe Media-Query) */
		.m24det .m24-rc-link:hover{text-decoration:underline}
		.m24det .m24-fit-links{display:flex;flex-wrap:wrap;gap:10px}
		.m24det .m24-fit-chip{display:inline-block;padding:8px 16px;border:1px solid var(--line);border-radius:999px;background:#fafafa;color:var(--ink);font-family:'Saira',sans-serif;font-weight:600;font-size:15px;text-decoration:none;line-height:1.2;transition:border-color .15s ease,background .15s ease}
		.m24det .m24-fit-chip:hover{border-color:var(--blue);background:#fff;color:var(--blue)}
		/* Paket B: barrierearme Tooltips (Hover/Focus auf Desktop, Tap auf Touch, ESC schliesst). */
		.m24det{--m24-tt-width:280px}
		.m24det .m24-tt-wrap{position:relative;display:inline-flex;align-items:center;vertical-align:baseline}
		.m24det .m24-tt-trigger{background:none;border:none;padding:0;margin:0;cursor:help;color:inherit;font:inherit;display:inline-flex;align-items:center;gap:4px;line-height:inherit}
		.m24det .m24-tt-trigger:focus-visible{outline:2px solid var(--blue);outline-offset:3px;border-radius:3px}
		.m24det .m24-tt-trigger .m24-tt-label{border-bottom:1px dotted currentColor}
		.m24det .m24-tt-trigger .m24-tt-icon{width:13px;height:13px;flex-shrink:0}
		.m24det .m24-tt-body{position:absolute;left:50%;top:calc(100% + 8px);transform:translateX(-50%);width:var(--m24-tt-width);max-width:calc(100vw - 20px);background:#14161a;color:#fff;font-size:12.5px;line-height:1.5;font-weight:400;padding:10px 12px;border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,.25);z-index:30;text-align:left;white-space:normal}
		.m24det .m24-tt-body[hidden]{display:none}
		.m24det .m24-tt-body::before{content:'';position:absolute;left:50%;bottom:100%;transform:translateX(-50%);border:6px solid transparent;border-bottom-color:#14161a}
		.m24det .m24-tt-body.m24-tt-flip{top:auto;bottom:calc(100% + 8px)}
		.m24det .m24-tt-body.m24-tt-flip::before{bottom:auto;top:100%;border-bottom-color:transparent;border-top-color:#14161a}
		.m24det .fitchips{display:flex;flex-wrap:wrap;gap:8px}
		.m24det .fitchips span{background:var(--surf);border:1px solid var(--line);border-radius:6px;padding:7px 13px;font-size:13px;font-weight:500}
		.m24det .related{clear:both;margin-top:36px;border-top:1px solid var(--line);padding-top:18px}
		.m24det .related .dl{font-family:'Saira',sans-serif;font-size:12px;letter-spacing:1.5px;color:var(--bronze);margin-bottom:8px}
		/* DESKTOP: urspruengliches Karten-Grid (Bild oben, Titel + Preis darunter). Beschreibung
		   ausgeblendet. MOBIL (<=760px, siehe Media-Query): umschalten auf ¼/¾-Listenzeilen. */
		/* „Weitere Teile": einreihiges Grid — Desktop 5 Spalten, ≤900px 2 Spalten (kein Umbruch/keine Liste). */
		.m24det .related-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-top:12px}
		.m24det .ritem{border:1px solid var(--line);border-radius:10px;overflow:hidden;text-decoration:none;color:inherit;display:block}
		.m24det .ritem .rimg{aspect-ratio:4/3;background:#ededea}
		.m24det .ritem .rimg img{width:100%;height:100%;object-fit:cover;display:block}
		.m24det .ritem .rb{padding:10px 12px}
		.m24det .ritem h4{font-family:'Saira',sans-serif;font-weight:500;font-size:13.5px;margin:0 0 5px;line-height:1.25}
		.m24det .ritem .rdesc{display:none}
		.m24det .ritem .rp{font-family:'Saira',sans-serif;font-weight:700;font-size:14px;color:var(--bronze)}
		@media(max-width:900px){.m24det .related-grid{grid-template-columns:repeat(2,1fr)}}
		.m24-lb{display:none;position:fixed;inset:0;background:rgba(10,11,13,.93);z-index:99999;align-items:center;justify-content:center;padding:30px}
		.m24-lb .lb-stage{flex:1;display:flex;align-items:center;justify-content:center;height:100%}
		.m24-lb-img{max-width:100%;max-height:90vh;object-fit:contain;transition:opacity .3s ease}
		.m24-lb-img.m24-fade{opacity:0}
		.m24-lb-rail{width:112px;height:90vh;overflow-y:auto;display:flex;flex-direction:column;gap:8px;margin-left:18px;flex:0 0 auto}
		.m24-lb-rail img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:5px;cursor:pointer;opacity:.5;border:2px solid transparent}
		.m24-lb-rail img.active{opacity:1;border-color:#fff}
		.m24-lb-close{position:absolute;top:16px;right:22px;color:#fff;font-size:32px;line-height:1;cursor:pointer;background:none;border:none}
		.m24-lb-prev,.m24-lb-next{position:absolute;top:50%;transform:translateY(-50%);width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;border:none;font-size:22px;cursor:pointer}
		.m24-lb-prev{left:12px}.m24-lb-next{right:12px}
		@media(max-width:760px){.m24det .row{grid-template-columns:1fr;gap:24px}.m24det h1{font-size:23px}.m24det .m24-detail-head{flex-wrap:wrap;gap:12px}.m24det .m24-detail-head .m24-detail-logo{height:32px}.m24det .m24-detail-head .m24-detail-logo img{max-height:28px;max-width:96px}.m24det .tabbar{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}.m24det .tabbar::-webkit-scrollbar{display:none}.m24det .tab{padding:12px 16px;font-size:14px;flex:0 0 auto;white-space:nowrap}.m24det .tabpanel[data-panel="fit"]{font-size:16px;line-height:1.7}.m24det .tabpanel[data-panel="desc"]{font-size:16px!important;line-height:1.6!important}.m24det .tabpanel[data-panel="desc"] p,.m24det .tabpanel[data-panel="desc"] li{font-size:16px!important;line-height:1.6!important}.m24-lb-rail{display:none}.m24det .m24-right-inner{position:static}.m24det .m24-review-card{float:none;width:auto;margin:0 0 20px}.m24det .m24-rv-tab-btn{display:block}.m24det .tabpanel[data-panel="desc"] .m24-review-card{display:none!important}.m24det .bc{display:flex!important;flex-wrap:nowrap!important;overflow-x:auto;overflow-y:hidden;max-width:100%;white-space:nowrap;gap:6px;font-size:13px!important;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;scrollbar-width:none;-ms-overflow-style:none;padding-bottom:2px;-webkit-mask-image:linear-gradient(to right,#000 calc(100% - 24px),transparent);mask-image:linear-gradient(to right,#000 calc(100% - 24px),transparent)}.m24det .bc::-webkit-scrollbar{display:none}.m24det .bc a,.m24det .bc>span{flex:0 0 auto;white-space:nowrap;font-size:13px!important}.m24det .m24-detail-head h1{font-size:21px}}
		</style>
		<script type="application/ld+json"><?php echo wp_json_encode( $ld ); ?></script>
		<script type="application/ld+json"><?php echo wp_json_encode( $product_ld ); ?></script>
		<div class="td-container m24-katalog-container">
		<div class="m24det" id="m24det-<?php echo (int) $id; ?>">
			<div class="bc">
				<a href="<?php echo esc_url( $home ); ?>" aria-label="Start" title="Start"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg></a>
				<span>›</span><a href="<?php echo esc_url( $typ_url ); ?>"><?php echo esc_html( $typ_label ); ?></a>
				<?php if ( $terms && ! is_wp_error( $terms ) && isset( $terms[0] ) ) : // Primaer-Modell (erstes Term) — weitere Modelle bleiben uebers Archiv-Filter erreichbar. ?>
					<span>›</span><a href="<?php echo esc_url( add_query_arg( 'm24_modell', $terms[0]->slug, $typ_url ) ); ?>"><?php echo esc_html( $terms[0]->name ); ?></a>
				<?php endif; ?>
				<span>›</span><span><?php echo esc_html( get_the_title( $id ) ); ?></span>
			</div>

			<div class="m24-detail-head">
				<h1><?php echo esc_html( get_the_title( $id ) ); ?></h1>
				<?php
				// Header oben rechts: echtes Original-BMW-Teil → Trust-Badge (Markenrecht, nur gebraucht+Flag).
				// Sonst MOTORSPORT24-Eigenlogo statt leerem Platz. KEIN BMW-Rundel mehr (BMW-Abmahnung 2023).
				if ( ! $is_neu && '1' === get_post_meta( $id, '_m24_original_teil', true ) ) :
					echo m24_render_original_badge( $id ); // phpcs:ignore — statisches Markup, intern gegated
				else : ?>
					<div class="m24-detail-logo">
						<?php // Kein Badge → immer das MOTORSPORT24-Logo (Position nie leer). ?>
						<img src="<?php echo esc_url( m24_detail_logo_url( 'neu' ) ); ?>" alt="MOTORSPORT24" class="skip-lazy" decoding="async" fetchpriority="high" data-no-lazy="1" data-skip-lazy>
					</div>
				<?php endif; ?>
			</div>

			<div class="row">
				<div class="left">
					<?php if ( $imgs ) : ?>
						<div class="ratio">
							<img class="m24-main-img" src="<?php echo esc_url( $imgs[0]['full'] ); ?>" alt="<?php echo esc_attr( get_the_title( $id ) ); ?>">
							<?php if ( count( $imgs ) > 1 ) : ?>
								<span class="nav-arrow prev" aria-label="zurück">&#10094;</span>
								<span class="nav-arrow next" aria-label="weiter">&#10095;</span>
							<?php endif; ?>
						</div>
						<?php if ( count( $imgs ) > 1 ) : // T1: Strip nur bei >1 Bild (sonst Einzelbild-Duplikat) ?>
							<div class="thumbs">
								<?php foreach ( $shown as $i => $im ) : $is_more = ( 4 === $i && $extra > 0 ); // 5. Kachel (Index 4) = more-tile ?>
									<div class="t<?php echo 0 === $i ? ' active' : ''; ?><?php echo $is_more ? ' more-tile' : ''; ?>" data-i="<?php echo (int) $i; ?>">
										<img src="<?php echo esc_url( $im['thumb'] ); ?>" alt="<?php echo esc_attr( get_the_title( $id ) . ' – Bild ' . ( (int) $i + 1 ) ); ?>">
										<?php if ( $is_more ) : ?><span class="more">+<?php echo esc_html( $extra ); ?></span><?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<?php // Bildlos: kleiner, gecroppter Platzhalter als CSS-Background — kein <img> (nicht indexierbar),
						// keine Lightbox/Zoom, keine Thumbnails. ?>
						<div class="m24-noimg-box" role="img" aria-label="<?php esc_attr_e( 'Bild folgt', 'm24-plattform' ); ?>" style="background-image:url('<?php echo esc_url( $noimg_url ); ?>')"></div>
					<?php endif; ?>
				</div>

				<div class="right">
					<div class="m24-right-inner">
					<?php $pos_list = isset( $opts_data['options'] ) ? $opts_data['options'] : array(); ?>
					<?php if ( $verkauft ) : ?>
						<div class="m24-sold-badge"><?php esc_html_e( 'Verkauft', 'm24-plattform' ); ?></div>
					<?php elseif ( $preis_auf_anfrage ) : ?>
						<div class="m24-preis-anfrage"><?php esc_html_e( 'Preis auf Anfrage', 'm24-plattform' ); ?></div>
					<?php else :
						$pos_n = count( $pos_list );
						?>
						<div class="pbr"><span class="m24-brutto-val"><?php echo esc_html( $preis['brutto_fmt'] ); ?></span><sup class="pstar">*</sup></div>
						<?php if ( ! empty( $preis['netto_fmt'] ) ) : ?>
							<div class="pnet">Netto <span class="m24-netto-val"><?php echo esc_html( $preis['netto_fmt'] ); ?></span>
								<?php if ( ! empty( $preis['netto_hinweis'] ) ) : ?>
									<span class="m24-tt-wrap" style="margin-left:7px;font-size:11.5px;color:var(--mut)">
										<button type="button" class="m24-tt-trigger" aria-describedby="m24tt-netto-<?php echo (int) $id; ?>" aria-expanded="false">
											<svg class="m24-tt-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 11v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="7.6" r="1.2" fill="currentColor"/></svg>
											<span class="m24-tt-label">Export &amp; EU-B2B</span>
										</button>
										<span class="m24-tt-body" id="m24tt-netto-<?php echo (int) $id; ?>" role="tooltip" hidden><?php echo esc_html( M24_Catalog_Pricing::NETTO_EXPORT_TIP ); ?></span>
									</span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<div class="pnote">*&nbsp;<?php echo esc_html( $note_parts['lead'] ); ?><span class="m24-tt-wrap">
							<button type="button" class="m24-tt-trigger" aria-describedby="m24tt-vut-<?php echo (int) $id; ?>" aria-expanded="false">
								<span class="m24-tt-label"><?php echo esc_html( $note_parts['vut_label'] ); ?></span>
							</button>
							<span class="m24-tt-body" id="m24tt-vut-<?php echo (int) $id; ?>" role="tooltip" hidden><?php echo esc_html( $note_parts['vut_tip'] ); ?></span>
						</span><?php echo esc_html( $note_parts['trail'] ); ?></div>
					<?php endif; ?>

					<div class="sheet">
						<div class="slabel">TEILE-DATEN</div>
						<?php if ( $artnr ) : ?><div class="srow"><span class="k">Artikelnummer</span><span class="v m24-artnr-value"><?php echo esc_html( $artnr ); ?></span></div><?php endif; ?>
						<?php if ( $bmwnr ) : ?><div class="srow"><span class="k">BMW-Teilenummer</span><span class="v"><?php echo esc_html( $bmwnr ); ?></span></div><?php endif; ?>
						<?php if ( $stand ) : ?><div class="srow"><span class="k">Stand</span><span class="v"><?php echo esc_html( $stand ); ?></span></div><?php endif; ?>
						<?php if ( $hinweis ) : ?><div class="srow"><span class="k">Hinweis</span><span class="v" style="font-family:inherit"><?php echo esc_html( $hinweis ); ?></span></div><?php endif; ?>
					</div>

						<div class="m24-actions-group">
						<?php
						// Bug A: Dropdown listet AUSSCHLIESSLICH vom Nutzer eingegebene Varianten-Labels.
						// Optionen ohne Label (Basis-/SKU-Auto-Option) werden NICHT als wählbarer Eintrag
						// gezeigt; der Basis-Preis bleibt der Default. Original-Index als value (JS-Swap).
						$variant_opts = array();
						foreach ( $pos_list as $oi => $opt ) { if ( '' !== trim( (string) $opt['label'] ) ) { $variant_opts[ $oi ] = $opt; } }
						?>
						<?php if ( ! $verkauft && ! $preis_auf_anfrage && ! empty( $variant_opts ) ) : ?>
							<div class="m24-varianten-wrap">
								<label class="slabel" for="m24-varianten-<?php echo (int) $id; ?>">VARIANTE</label>
								<select class="m24-varianten" id="m24-varianten-<?php echo (int) $id; ?>">
									<option value="" disabled selected>Variante wählen …</option>
									<?php foreach ( $variant_opts as $oi => $opt ) : ?>
										<option value="<?php echo (int) $oi; ?>"
											data-brutto="<?php echo esc_attr( (string) $opt['brutto'] ); ?>"
											data-brutto-fmt="<?php echo esc_attr( $opt['brutto_fmt'] ); ?>"
											data-netto="<?php echo esc_attr( null !== $opt['netto'] ? (string) $opt['netto'] : '' ); ?>"
											data-netto-fmt="<?php echo esc_attr( null !== $opt['netto_fmt'] ? $opt['netto_fmt'] : '' ); ?>"
											data-artnr="<?php echo esc_attr( $opt['art_nr'] ); ?>"
											data-label="<?php echo esc_attr( $opt['label'] ); ?>"
										><?php echo esc_html( $opt['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endif; ?>
						<div class="actions">
							<button type="button" class="btn btn-pri m24-frage" data-id="<?php echo esc_attr( $id ); ?>" data-title="<?php echo esc_attr( get_the_title( $id ) ); ?>" data-artnr="<?php echo esc_attr( $artnr ); ?>" data-url="<?php echo esc_url( get_permalink( $id ) ); ?>" data-price="<?php echo $verkauft ? '' : esc_attr( $preis['brutto_fmt'] ); ?>" data-variant-label="" data-modell="<?php echo esc_attr( ( $terms && ! is_wp_error( $terms ) && isset( $terms[0]->slug ) ) ? $terms[0]->slug : '' ); ?>"><svg class="m24-btn-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>Frage stellen</button>
							<?php if ( $verkauft ) : ?>
								<button type="button" class="btn btn-sec m24-merken is-disabled" disabled aria-disabled="true" title="<?php esc_attr_e( 'Teil verkauft', 'm24-plattform' ); ?>"><svg class="m24-btn-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>Auf den Merkzettel</button>
							<?php else : ?>
								<button type="button" class="btn btn-sec m24-merken" data-id="<?php echo esc_attr( $id ); ?>" data-artnr="<?php echo esc_attr( $artnr ); ?>" data-price="<?php echo esc_attr( $preis['brutto_fmt'] ); ?>" data-variant-label=""><svg class="m24-btn-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>Auf den Merkzettel</button>
							<?php endif; ?>
						</div>
						</div>
						<div class="m24-trust">
							<span class="m24-trust-i"><svg class="m24-trust-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="5"/><path d="M8.6 12.4 7 22l5-2.8L17 22l-1.6-9.6"/></svg> seit 2006</span>
							<span class="m24-trust-dot" aria-hidden="true">·</span>
							<span class="m24-trust-i"><svg class="m24-trust-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h11v9H3z"/><path d="M14 9h3.5L21 12.5V15h-7z"/><circle cx="7" cy="18" r="1.7"/><circle cx="17" cy="18" r="1.7"/></svg> weltweiter Versand</span>
						</div>
					</div>
				</div>
			</div>

			<?php if ( $verkauft && class_exists( 'M24_Sold_Alternatives' ) ) { echo M24_Sold_Alternatives::render_block( $id ); /* phpcs:ignore */ } ?>

			<div class="m24-tabs">
				<?php
				$has_fit     = ( $terms && ! is_wp_error( $terms ) && ! empty( $terms ) );
				// Bewertungs-Karte einmal rendern, zweimal verwenden: Desktop = gefloatet im
				// Beschreibungs-Panel; Mobil (<=760px) = eigener Tab „Google-Bewertung" (per CSS umgeschaltet).
				$review_html = class_exists( 'M24_Reviews_Card' ) ? (string) M24_Reviews_Card::render_card() : '';
				$has_review  = '' !== trim( $review_html );
				?>
				<div class="tabbar">
					<button type="button" class="tab active" data-tab="desc">Beschreibung</button>
					<?php if ( $has_fit ) : ?>
						<button type="button" class="tab" data-tab="fit">Passend für</button>
					<?php endif; ?>
					<?php if ( $leichtbau ) : ?>
						<button type="button" class="tab" data-tab="manufacture">Herstellungshinweise</button>
					<?php endif; ?>
					<?php if ( $has_review ) : ?>
						<button type="button" class="tab m24-rv-tab-btn" data-tab="reviews">Google Bewertungen</button>
					<?php endif; ?>
				</div>
				<div class="tabpanel" data-panel="desc">
					<?php echo $review_html; // phpcs:ignore — Trust-Karte, Desktop rechts gefloatet; mobil ausgeblendet ?>
					<?php echo $desc ? wp_kses_post( wpautop( $desc ) ) : '<span style="color:#6b7077">Keine Beschreibung hinterlegt.</span>'; // phpcs:ignore ?>
				</div>
				<?php if ( $has_fit ) : ?>
					<div class="tabpanel" data-panel="fit" hidden>
						<p style="margin:0 0 1em">Dieses Teil passt für folgende Modelle:</p>
						<div class="m24-fit-links">
							<?php foreach ( $terms as $t ) : ?>
								<a class="m24-fit-chip" href="<?php echo esc_url( add_query_arg( 'm24_modell', $t->slug, $typ_url ) ); ?>"><?php echo esc_html( $t->name ); ?></a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
				<?php if ( $leichtbau ) : ?>
					<div class="tabpanel" data-panel="manufacture" hidden>
						<p style="margin:0"><?php echo esc_html( m24_leichtbau_hinweis() ); ?></p>
					</div>
				<?php endif; ?>
				<?php if ( $has_review ) : ?>
					<div class="tabpanel m24-rv-tab-pane" data-panel="reviews" hidden>
						<?php echo $review_html; // phpcs:ignore — nur mobil sichtbar (Tab) ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $related ) : ?>
				<div class="related">
					<div class="dl"><?php echo ! empty( $term_names ) ? esc_html( 'WEITERE ' . mb_strtoupper( $term_names[0] ) . '-TEILE' ) : esc_html__( 'WEITERE TEILE', 'm24-plattform' ); ?></div>
					<div class="related-grid">
						<?php
						foreach ( $related as $rp ) :
							$rp_id    = (int) $rp;
							$r_anfr   = (bool) get_post_meta( $rp_id, '_m24_preis_auf_anfrage', true );
							$rpr      = M24_Catalog_Pricing::get( $rp_id );
							$r_price  = $r_anfr ? __( 'Preis auf Anfrage', 'm24-plattform' ) : (string) $rpr['brutto_fmt'];
							$r_desc   = trim( preg_replace( '/\s+/', ' ', (string) wp_strip_all_tags( (string) get_post_meta( $rp_id, '_m24_beschreibung_de', true ) ) ) );
							?>
							<a class="ritem" href="<?php echo esc_url( get_permalink( $rp_id ) ); ?>">
								<?php if ( has_post_thumbnail( $rp_id ) ) : ?>
									<div class="rimg"><?php echo get_the_post_thumbnail( $rp_id, 'medium', array( 'alt' => esc_attr( html_entity_decode( get_the_title( $rp_id ), ENT_QUOTES, 'UTF-8' ) ) ) ); // phpcs:ignore ?></div>
								<?php else : // Bildlos → Platzhalter als CSS-Background (kein <img>). ?>
									<div class="rimg rimg-noimg" role="img" aria-label="<?php esc_attr_e( 'Bild folgt', 'm24-plattform' ); ?>" style="background-image:url('<?php echo esc_url( $noimg_url ); ?>')"></div>
								<?php endif; ?>
								<div class="rb">
									<h4><?php echo esc_html( html_entity_decode( get_the_title( $rp_id ), ENT_QUOTES, 'UTF-8' ) ); ?></h4>
									<?php if ( '' !== $r_desc ) : ?><div class="rdesc"><?php echo esc_html( $r_desc ); ?></div><?php endif; ?>
									<div class="rp"><?php echo esc_html( $r_price ); ?></div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="m24-lb">
				<button class="m24-lb-close" aria-label="schließen">&times;</button>
				<button class="m24-lb-prev" aria-label="zurück">&#10094;</button>
				<div class="lb-stage"><img class="m24-lb-img" src="" alt=""></div>
				<div class="m24-lb-rail"></div>
				<button class="m24-lb-next" aria-label="weiter">&#10095;</button>
			</div>
		</div>
		</div>
		<script>
		(function(){
			var root=document.getElementById('m24det-<?php echo (int) $id; ?>');
			if(!root)return;
			// Bewertungs-Karte: pro Aufruf zufaellig eine Bewertung zeigen (cache-fest, clientseitig).
			(function(){root.querySelectorAll('.m24-review-card').forEach(function(card){var its=card.querySelectorAll('.m24-rc-item');if(its.length<2)return;for(var i=0;i<its.length;i++){its[i].classList.remove('is-active');}its[Math.floor(Math.random()*its.length)].classList.add('is-active');});})();
			// Paket B: Tooltips. Hover/Focus = oeffnen (Desktop), Tap = toggeln (Touch),
			// ESC oder Klick ausserhalb = schliessen, Viewport-Flip wenn untere Kante reisst.
			(function(){
				var tts = root.querySelectorAll('.m24-tt-wrap');
				if (!tts.length) return;
				var active = null;
				var coarse = (window.matchMedia && window.matchMedia('(pointer:coarse)').matches);
				function close(tt){
					if (!tt) return;
					var body = tt.querySelector('.m24-tt-body');
					var btn  = tt.querySelector('.m24-tt-trigger');
					if (body) { body.hidden = true; body.classList.remove('m24-tt-flip'); }
					if (btn) btn.setAttribute('aria-expanded','false');
					if (active === tt) active = null;
				}
				function open(tt){
					if (active && active !== tt) close(active);
					var body = tt.querySelector('.m24-tt-body');
					var btn  = tt.querySelector('.m24-tt-trigger');
					if (body) body.hidden = false;
					if (btn) btn.setAttribute('aria-expanded','true');
					active = tt;
					if (body){
						body.classList.remove('m24-tt-flip');
						var r = body.getBoundingClientRect();
						var vh = window.innerHeight || document.documentElement.clientHeight;
						if (r.bottom > vh - 8) body.classList.add('m24-tt-flip');
					}
				}
				tts.forEach(function(tt){
					var btn = tt.querySelector('.m24-tt-trigger');
					if (!btn) return;
					btn.addEventListener('mouseenter', function(){ if (!coarse) open(tt); });
					btn.addEventListener('mouseleave', function(){ if (!coarse) close(tt); });
					btn.addEventListener('focus',      function(){ open(tt); });
					btn.addEventListener('blur',       function(){
						setTimeout(function(){ if (document.activeElement !== btn) close(tt); }, 60);
					});
					btn.addEventListener('click', function(e){
						if (coarse) {
							e.preventDefault();
							if (active === tt) close(tt); else open(tt);
						}
					});
				});
				document.addEventListener('click', function(e){
					if (!active) return;
					if (!active.contains(e.target)) close(active);
				});
				document.addEventListener('keydown', function(e){
					if (e.key === 'Escape' && active) {
						var btn = active.querySelector('.m24-tt-trigger');
						close(active);
						if (btn) btn.focus();
					}
				});
			})();
			// Varianten-Picker (>1 Option): aktualisiert Brutto/Netto/Artikelnummer + data-Attribute der Action-Buttons.
			var varSel = root.querySelector('.m24-varianten');
			if (varSel) {
				varSel.addEventListener('change', function(){
					var o = varSel.selectedOptions[0]; if(!o) return;
					var bruttoFmt = o.dataset.bruttoFmt || '';
					var nettoFmt  = o.dataset.nettoFmt  || '';
					var artnr     = o.dataset.artnr     || '';
					var label     = o.dataset.label     || '';
					var bEl = root.querySelector('.m24-brutto-val'); if(bEl) bEl.textContent = bruttoFmt;
					var nEl = root.querySelector('.m24-netto-val');  if(nEl && nettoFmt) nEl.textContent = nettoFmt;
					var aEl = root.querySelector('.m24-artnr-value'); if(aEl && artnr) aEl.textContent = artnr;
					root.querySelectorAll('.m24-frage, .m24-merken').forEach(function(btn){
						if(artnr)    btn.setAttribute('data-artnr', artnr);
						if(bruttoFmt) btn.setAttribute('data-price', bruttoFmt);
						btn.setAttribute('data-variant-label', label);
					});
				});
			}
			// Tabs
			root.querySelectorAll('.m24-tabs .tab').forEach(function(b){b.addEventListener('click',function(){
				root.querySelectorAll('.m24-tabs .tab').forEach(function(x){x.classList.remove('active');});
				b.classList.add('active');var t=b.dataset.tab;
				root.querySelectorAll('.m24-tabs .tabpanel').forEach(function(p){p.hidden=(p.dataset.panel!==t);});
			});});
			// Galerie
			var imgs=<?php echo wp_json_encode( $imgs ); ?>;
			if(!imgs.length)return;
			var idx=0,hovering=false;
			var mainImg=root.querySelector('.m24-main-img');
			var lb=root.querySelector('.m24-lb'),lbImg=lb.querySelector('.m24-lb-img'),rail=lb.querySelector('.m24-lb-rail');
			function fadeSwap(el,src){if(!el)return;el.classList.add('m24-fade');var pre=new Image();pre.onload=function(){el.src=src;requestAnimationFrame(function(){el.classList.remove('m24-fade');});};pre.src=src;}
			function thumbs(){return root.querySelectorAll('.thumbs .t');}
			function markStrip(){thumbs().forEach(function(t){t.classList.toggle('active',parseInt(t.dataset.i,10)===idx&&!t.classList.contains('more-tile'));});}
			function markRail(){rail.querySelectorAll('img').forEach(function(im){im.classList.toggle('active',parseInt(im.dataset.i,10)===idx);});}
			// Horizontaler Slide (Paket: Galerie-Polish). Richtung aus Index-Differenz —
			// kuerzester Weg auf dem Kreis (wrap-around). prefers-reduced-motion: hart wechseln.
			function slideTo(newIdx){
				newIdx=(newIdx+imgs.length)%imgs.length;
				if(newIdx===idx||imgs.length<2){ if(newIdx!==idx){idx=newIdx;fadeSwap(mainImg,imgs[idx].full);markStrip();} return; }
				var oldIdx=idx;
				var delta=(newIdx-oldIdx+imgs.length)%imgs.length;
				var dir=(delta<=imgs.length/2)?1:-1; // 1=weiter (rechts→links), -1=zurueck (links→rechts)
				idx=newIdx;markStrip();
				var reduce=(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches);
				if(reduce||!mainImg||!mainImg.parentNode){ if(mainImg) mainImg.src=imgs[idx].full; return; }
				// Lokale Refs (oldImg+newImg) — die mainImg-Variable wird sync auf newImg
				// gesetzt, aber das raf-Callback laeuft async → wir referenzieren NICHT mainImg
				// im Callback, sonst landet die slide-to-*-Klasse auf dem falschen Element.
				var oldImg=mainImg;
				var newImg=document.createElement('img');
				newImg.className='m24-main-img '+(dir===1?'m24-slide-from-right':'m24-slide-from-left');
				newImg.alt=oldImg.alt||'';
				newImg.src=imgs[idx].full;
				oldImg.parentNode.appendChild(newImg);
				// Force reflow vor Animation
				void newImg.offsetWidth;
				requestAnimationFrame(function(){
					newImg.classList.remove('m24-slide-from-right','m24-slide-from-left');
					oldImg.classList.add(dir===1?'m24-slide-to-left':'m24-slide-to-right');
				});
				mainImg=newImg;
				setTimeout(function(){ if(oldImg&&oldImg.parentNode) oldImg.parentNode.removeChild(oldImg); },320);
			}
			function setMain(i){ slideTo(i); }
			thumbs().forEach(function(t){t.addEventListener('click',function(){if(t.classList.contains('more-tile')){openLb(parseInt(t.dataset.i,10));}else{setMain(parseInt(t.dataset.i,10));}});});
			var prev=root.querySelector('.nav-arrow.prev'),next=root.querySelector('.nav-arrow.next');
			if(prev)prev.addEventListener('click',function(e){e.stopPropagation();setMain(idx-1);});
			if(next)next.addEventListener('click',function(e){e.stopPropagation();setMain(idx+1);});
			var ratio=root.querySelector('.ratio'),left=root.querySelector('.left');
			if(ratio)ratio.addEventListener('click',function(){openLb(idx);});
			if(left){left.addEventListener('mouseenter',function(){hovering=true;});left.addEventListener('mouseleave',function(){hovering=false;});}
			imgs.forEach(function(im,i){var t=document.createElement('img');t.src=im.thumb;t.dataset.i=i;t.addEventListener('click',function(){lbSet(i);});rail.appendChild(t);});
			function openLb(i){lbSet(i);lb.style.display='flex';document.body.style.overflow='hidden';}
			function closeLb(){lb.style.display='none';document.body.style.overflow='';}
			function lbSet(i){idx=(i+imgs.length)%imgs.length;fadeSwap(lbImg,imgs[idx].full);if(mainImg)mainImg.src=imgs[idx].full;markStrip();markRail();}
			lb.querySelector('.m24-lb-close').addEventListener('click',closeLb);
			lb.querySelector('.m24-lb-prev').addEventListener('click',function(){lbSet(idx-1);});
			lb.querySelector('.m24-lb-next').addEventListener('click',function(){lbSet(idx+1);});
			lb.addEventListener('click',function(e){if(e.target===lb)closeLb();});
			document.addEventListener('keydown',function(e){
				var open=(lb.style.display==='flex');
				var tag=(document.activeElement&&document.activeElement.tagName)||'';
				if(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT')return;
				if(open){if(e.key==='Escape'){closeLb();}else if(e.key==='ArrowLeft'){e.preventDefault();lbSet(idx-1);}else if(e.key==='ArrowRight'){e.preventDefault();lbSet(idx+1);}return;}
				if(e.key!=='ArrowLeft'&&e.key!=='ArrowRight')return;
				e.preventDefault();
				slideTo(e.key==='ArrowLeft'?idx-1:idx+1);
			});
			markStrip();
		})();
		</script>
		<?php
		get_footer();
	}
}
