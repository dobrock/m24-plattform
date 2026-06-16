<?php
/**
 * M24 Plattform — Modell-Hubs: CPT „m24_modellhub" (backend-editierbare Quelle)
 * Modul: modules/katalog/catalog-hub-cpt.php
 *
 * Ein Post = ein Modell-Hub. EINZIGE Quelle fuer Slug, Term-Mapping, Telemetrie,
 * Intro/SEO-Text, Bilder und Cross-Links. Frontend bleibt /modelle/{slug}/
 * (CPT selbst hat keine eigene oeffentliche URL). M24_Catalog_Hub liest hieraus.
 *
 * Enthaelt den einmaligen Seeder (Flag-geschuetzt), der die 5 Hubs aus den
 * redaktionellen Startwerten anlegt (E30 + E36/E46/E9x/Sonstige).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Hub_CPT {

	const CPT           = 'm24_modellhub';
	const TAX           = 'm24_fahrzeugkat';
	const META          = '_m24_hub_';
	const SEED_FLAG     = 'm24_modellhub_seeded_v1';
	const BACKFILL_FLAG = 'm24_modellhub_terms_backfill_v1';
	const FIX_FLAG      = 'm24_modellhub_terms_fix_v2'; // Korrektur: flacher statt hierarchischer Term
	const MIGRATE_FLAG  = 'm24_modellhub_modelle_v1';   // Base /modelle/ + bmw-Slugs + Z4 + default_kat + Menue

	/** Editierbare Felder (Schluessel ohne Prefix). */
	public static function text_fields() {
		return array( 'modell', 'motor', 'baujahre', 'h1', 'sub', 'intro_h2', 'seo_title', 'seo_desc' );
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );   // vor Hub-Rewrites (init:20)
		// Seed/Backfill auf init:15 — NACH der Taxonomie m24_fahrzeugkat (init:10),
		// sonst liefert get_term_by() false und das Term-Mapping bleibt leer.
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 15 );
		add_action( 'init', array( __CLASS__, 'maybe_backfill' ), 16 );
		add_action( 'init', array( __CLASS__, 'maybe_fix_terms' ), 17 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_modelle' ), 18 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'          => 'Modell-Hubs',
				'singular_name' => 'Modell-Hub',
				'add_new_item'  => 'Neuen Modell-Hub anlegen',
				'edit_item'     => 'Modell-Hub bearbeiten',
				'menu_name'     => 'Modell-Hubs',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => 'dashicons-screenoptions',
			'menu_position'       => 26,
			'supports'            => array( 'title', 'page-attributes' ), // Titel = H1/Label, menu_order = Reihenfolge
			'has_archive'         => false,
			'rewrite'             => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
		) );
	}

	/* ── Meta-Box ─────────────────────────────────────────────────────────────── */

	public static function meta_box() {
		add_meta_box( 'm24_hub_fields', 'Modell-Hub — Inhalt & Mapping', array( __CLASS__, 'render' ), self::CPT, 'normal', 'high' );
	}

	private static function get( $id, $k ) { return get_post_meta( $id, self::META . $k, true ); }

	public static function render( $post ) {
		wp_nonce_field( 'm24_hub_save', 'm24_hub_nonce' );
		$id      = $post->ID;
		$slug    = $post->post_name;
		$sel     = array_map( 'intval', (array) ( self::get( $id, 'terms' ) ?: array() ) );
		$img_csv = (string) self::get( $id, 'images' );
		$img_ids = array_values( array_filter( array_map( 'intval', explode( ',', $img_csv ) ) ) );
		$cross   = self::get( $id, 'cross_links' );
		$cross   = is_array( $cross ) ? $cross : array();
		$terms   = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false, 'orderby' => 'name' ) );
		?>
		<style>
		.m24hb{max-width:880px} .m24hb p.d{color:#666;margin:2px 0 0;font-size:12px}
		.m24hb .row{margin:0 0 16px} .m24hb label.l{display:block;font-weight:600;margin:0 0 4px}
		.m24hb input[type=text],.m24hb textarea{width:100%}
		.m24hb .cols{display:flex;gap:14px}.m24hb .cols>div{flex:1}
		.m24hb select[multiple]{width:100%;min-height:140px}
		.m24hb-imgs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 8px;padding:0;list-style:none}
		.m24hb-img{position:relative;width:84px;height:64px;border:1px solid #c3c4c7;border-radius:6px;overflow:hidden;cursor:grab;background:#f0f0f1}
		.m24hb-img img{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
		.m24hb-img .rm{position:absolute;top:2px;right:2px;width:18px;height:18px;line-height:16px;padding:0;border:none;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;font-size:13px;cursor:pointer}
		.m24hb-cl .clrow{display:flex;gap:8px;margin:0 0 6px}.m24hb-cl .clrow input{flex:1}
		</style>
		<div class="m24hb">
			<div class="row">
				<label class="l" for="m24_hub_slug">URL-Slug</label>
				<input type="text" id="m24_hub_slug" name="m24_hub_slug" value="<?php echo esc_attr( $slug ); ?>" placeholder="z. B. bmw-m3-e30">
				<p class="d">Frontend-URL: <code><?php echo esc_html( home_url( '/modelle/' ) ); ?><strong><?php echo esc_html( $slug ?: '{slug}' ); ?></strong>/</code> · leer ⇒ aus Titel. Nach Slug-Änderung Permalinks neu speichern.</p>
			</div>

			<div class="row">
				<label class="l" for="m24_hub_default_kat">Standard-Kategorie (Selektor-Default)</label>
				<?php $dk = self::get( $id, 'default_kat' ) ?: 'gebraucht'; ?>
				<select id="m24_hub_default_kat" name="m24_hub_default_kat">
					<option value="gebraucht"<?php selected( $dk, 'gebraucht' ); ?>>Gebrauchtteile</option>
					<option value="rennsport"<?php selected( $dk, 'rennsport' ); ?>>Rennsport-Teile</option>
				</select>
				<p class="d">Steuert später den Kategorie-Selektor-Default dieses Hubs.</p>
			</div>

			<div class="row">
				<label class="l" for="m24_hub_terms">Zugeordnete Modell-Terms (Teile-Quelle)</label>
				<select id="m24_hub_terms" name="m24_hub_terms[]" multiple>
					<?php foreach ( $terms as $t ) : ?>
						<option value="<?php echo (int) $t->term_id; ?>"<?php echo in_array( (int) $t->term_id, $sel, true ) ? ' selected' : ''; ?>><?php echo esc_html( $t->name . ' (' . $t->slug . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="d">Mehrfachauswahl (Strg/Cmd). Diese Terms bestimmen, welche Teile der Hub listet.</p>
			</div>

			<div class="row cols">
				<div><label class="l" for="m24_hub_modell">Telemetrie — Modell</label><input type="text" id="m24_hub_modell" name="m24_hub_modell" value="<?php echo esc_attr( self::get( $id, 'modell' ) ); ?>"></div>
				<div><label class="l" for="m24_hub_motor">Motor</label><input type="text" id="m24_hub_motor" name="m24_hub_motor" value="<?php echo esc_attr( self::get( $id, 'motor' ) ); ?>"><p class="d">leer ⇒ Zelle ausgeblendet</p></div>
				<div><label class="l" for="m24_hub_baujahre">Baujahre</label><input type="text" id="m24_hub_baujahre" name="m24_hub_baujahre" value="<?php echo esc_attr( self::get( $id, 'baujahre' ) ); ?>"><p class="d">leer ⇒ Zelle ausgeblendet</p></div>
			</div>

			<div class="row">
				<label class="l" for="m24_hub_h1">H1 / Titel-Override</label>
				<input type="text" id="m24_hub_h1" name="m24_hub_h1" value="<?php echo esc_attr( self::get( $id, 'h1' ) ); ?>" placeholder="Gebrauchtteile passend für BMW …">
			</div>
			<div class="row">
				<label class="l" for="m24_hub_sub">Hero-Sub</label>
				<input type="text" id="m24_hub_sub" name="m24_hub_sub" value="<?php echo esc_attr( self::get( $id, 'sub' ) ); ?>">
			</div>
			<div class="row">
				<label class="l" for="m24_hub_intro_h2">Intro-Überschrift (H2, optional)</label>
				<input type="text" id="m24_hub_intro_h2" name="m24_hub_intro_h2" value="<?php echo esc_attr( self::get( $id, 'intro_h2' ) ); ?>">
			</div>

			<div class="row">
				<label class="l">Intro-Text</label>
				<?php wp_editor( self::get( $id, 'intro' ), 'm24_hub_intro', array( 'textarea_name' => 'm24_hub_intro', 'textarea_rows' => 6, 'media_buttons' => false, 'teeny' => true ) ); ?>
			</div>

			<div class="row">
				<label class="l">Slideshow-Bilder</label>
				<input type="hidden" id="m24_hub_images" name="m24_hub_images" value="<?php echo esc_attr( implode( ',', $img_ids ) ); ?>">
				<ul id="m24-hub-imgs" class="m24hb-imgs">
					<?php foreach ( $img_ids as $iid ) : $src = wp_get_attachment_image_src( $iid, 'thumbnail' ); if ( ! $src ) { continue; } ?>
						<li class="m24hb-img" data-id="<?php echo (int) $iid; ?>"><img src="<?php echo esc_url( $src[0] ); ?>" alt=""><button type="button" class="rm" aria-label="Entfernen">&times;</button></li>
					<?php endforeach; ?>
				</ul>
				<button type="button" class="button" id="m24-hub-add">Bilder hinzufügen</button>
				<p class="d">Mehrere möglich · per Drag sortierbar. Erstes Bild = OG-/Social-Vorschau.</p>
			</div>

			<div class="row">
				<label class="l">SEO-Textblock (unter dem Raster)</label>
				<?php wp_editor( self::get( $id, 'seo_text' ), 'm24_hub_seo_text', array( 'textarea_name' => 'm24_hub_seo_text', 'textarea_rows' => 5, 'media_buttons' => false, 'teeny' => true ) ); ?>
			</div>
			<div class="row cols">
				<div><label class="l" for="m24_hub_seo_title">SEO-Title</label><input type="text" id="m24_hub_seo_title" name="m24_hub_seo_title" value="<?php echo esc_attr( self::get( $id, 'seo_title' ) ); ?>" placeholder="≤ ~65 Zeichen"></div>
				<div><label class="l" for="m24_hub_seo_desc">SEO-Description</label><input type="text" id="m24_hub_seo_desc" name="m24_hub_seo_desc" value="<?php echo esc_attr( self::get( $id, 'seo_desc' ) ); ?>" placeholder="≤ ~158 Zeichen"></div>
			</div>

			<div class="row m24hb-cl">
				<label class="l">Cross-Links (Weitere Übersichten)</label>
				<div id="m24-hub-cl">
					<?php
					$rows = $cross ?: array( array( 'label' => '', 'url' => '' ) );
					foreach ( $rows as $r ) : ?>
						<div class="clrow">
							<input type="text" name="m24_hub_cl_label[]" value="<?php echo esc_attr( $r['label'] ?? '' ); ?>" placeholder="Label (z. B. passend für BMW M3 E46)">
							<input type="text" name="m24_hub_cl_url[]" value="<?php echo esc_attr( $r['url'] ?? '' ); ?>" placeholder="URL (z. B. /modelle/bmw-m3-e46/)">
							<button type="button" class="button m24-cl-rm">−</button>
						</div>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button" id="m24-hub-cl-add">+ Zeile</button>
				<p class="d">Leere Zeilen werden ignoriert. Interne Pfade (z. B. <code>/modelle/bmw-m3-e46/</code>) oder volle URLs.</p>
			</div>
		</div>
		<?php
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::CPT !== $screen->post_type ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'inline_js' ) );
	}

	public static function inline_js() {
		?>
		<script>
		jQuery(function($){
			var $list=$('#m24-hub-imgs'), $input=$('#m24_hub_images');
			function sync(){ $input.val($list.children('[data-id]').map(function(){return $(this).data('id');}).get().join(',')); }
			if($.fn.sortable){ $list.sortable({items:'> li',tolerance:'pointer',update:sync}); }
			$('#m24-hub-add').on('click',function(e){ e.preventDefault();
				var f=wp.media({title:'Slideshow-Bilder',button:{text:'Übernehmen'},multiple:true,library:{type:'image'}});
				f.on('select',function(){ f.state().get('selection').each(function(a){ a=a.toJSON();
					if($list.find('[data-id="'+a.id+'"]').length) return;
					var u=(a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url;
					$list.append('<li class="m24hb-img" data-id="'+a.id+'"><img src="'+u+'" alt=""><button type="button" class="rm">&times;</button></li>');
				}); sync(); });
				f.open();
			});
			$list.on('click','.rm',function(){ $(this).closest('[data-id]').remove(); sync(); });
			$('#m24-hub-cl-add').on('click',function(e){ e.preventDefault();
				$('#m24-hub-cl').append('<div class="clrow"><input type="text" name="m24_hub_cl_label[]" placeholder="Label"><input type="text" name="m24_hub_cl_url[]" placeholder="URL"><button type="button" class="button m24-cl-rm">−</button></div>');
			});
			$('#m24-hub-cl').on('click','.m24-cl-rm',function(){ $(this).closest('.clrow').remove(); });
		});
		</script>
		<?php
	}

	/* ── Speichern ────────────────────────────────────────────────────────────── */

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['m24_hub_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['m24_hub_nonce'] ), 'm24_hub_save' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		foreach ( self::text_fields() as $k ) {
			if ( isset( $_POST[ 'm24_hub_' . $k ] ) ) {
				update_post_meta( $post_id, self::META . $k, sanitize_text_field( wp_unslash( $_POST[ 'm24_hub_' . $k ] ) ) );
			}
		}
		if ( isset( $_POST['m24_hub_intro'] ) ) {
			update_post_meta( $post_id, self::META . 'intro', wp_kses_post( wp_unslash( $_POST['m24_hub_intro'] ) ) );
		}
		if ( isset( $_POST['m24_hub_seo_text'] ) ) {
			update_post_meta( $post_id, self::META . 'seo_text', wp_kses_post( wp_unslash( $_POST['m24_hub_seo_text'] ) ) );
		}
		if ( isset( $_POST['m24_hub_default_kat'] ) ) {
			$dk = sanitize_key( wp_unslash( $_POST['m24_hub_default_kat'] ) );
			update_post_meta( $post_id, self::META . 'default_kat', in_array( $dk, array( 'gebraucht', 'rennsport' ), true ) ? $dk : 'gebraucht' );
		}
		if ( isset( $_POST['m24_hub_terms'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['m24_hub_terms'] ) ) ) );
			update_post_meta( $post_id, self::META . 'terms', $ids );
		} else {
			update_post_meta( $post_id, self::META . 'terms', array() );
		}
		if ( isset( $_POST['m24_hub_images'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['m24_hub_images'] ) ) ) ) ) );
			update_post_meta( $post_id, self::META . 'images', implode( ',', $ids ) );
		}
		// Cross-Links (Label + URL, leere ignorieren).
		$labels = isset( $_POST['m24_hub_cl_label'] ) ? (array) wp_unslash( $_POST['m24_hub_cl_label'] ) : array();
		$urls   = isset( $_POST['m24_hub_cl_url'] ) ? (array) wp_unslash( $_POST['m24_hub_cl_url'] ) : array();
		$cross  = array();
		foreach ( $urls as $i => $u ) {
			$u = trim( esc_url_raw( $u ) );
			$l = trim( sanitize_text_field( $labels[ $i ] ?? '' ) );
			if ( '' !== $u ) { $cross[] = array( 'label' => $l, 'url' => $u ); }
		}
		update_post_meta( $post_id, self::META . 'cross_links', $cross );

		// Slug aus eigenem Feld (sicher, ohne Save-Rekursion).
		if ( isset( $_POST['m24_hub_slug'] ) ) {
			$slug = sanitize_title( wp_unslash( $_POST['m24_hub_slug'] ) );
			if ( '' !== $slug && $slug !== $post->post_name ) {
				remove_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10 );
				wp_update_post( array( 'ID' => $post_id, 'post_name' => $slug ) );
				add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
			}
		}

		if ( class_exists( 'M24_Catalog_Hub' ) ) { M24_Catalog_Hub::flush_registry(); }
		flush_rewrite_rules( false ); // neue/geaenderte Slugs sofort routen
	}

	/* ── Seeder (einmalig) ────────────────────────────────────────────────────── */

	public static function maybe_seed() {
		if ( get_option( self::SEED_FLAG ) ) { return; }
		update_option( self::SEED_FLAG, gmdate( 'c' ) ); // ZUERST sperren (gegen Re-Entry/Doppel-Seed)
		self::seed();
		if ( class_exists( 'M24_Catalog_Hub' ) ) { M24_Catalog_Hub::flush_registry(); }
		flush_rewrite_rules( false );
	}

	/**
	 * Einmaliger Backfill: bereits angelegte Hubs mit LEEREM Term-Mapping aus der
	 * Standard-Zuordnung (Slug → Modell-Term) reparieren. Heilt Bestands-Posts,
	 * bei denen der Seeder vor der Taxonomie-Registrierung lief.
	 */
	public static function maybe_backfill() {
		if ( get_option( self::BACKFILL_FLAG ) ) { return; }
		if ( ! taxonomy_exists( self::TAX ) ) { return; } // sicherheitshalber
		update_option( self::BACKFILL_FLAG, gmdate( 'c' ) );
		$fixed = false;
		foreach ( self::seed_data() as $d ) {
			$post = self::find_by_slug( $d['slug'] );
			if ( ! $post ) { continue; }
			$cur = get_post_meta( $post->ID, self::META . 'terms', true );
			if ( is_array( $cur ) && $cur ) { continue; } // schon befuellt
			$ids = self::term_ids_from_slugs( $d['terms'] );
			if ( $ids ) { update_post_meta( $post->ID, self::META . 'terms', $ids ); $fixed = true; }
		}
		if ( $fixed && class_exists( 'M24_Catalog_Hub' ) ) { M24_Catalog_Hub::flush_registry(); }
	}

	/** Hub-Post per Slug (exakt, ohne get_page_by_path-Eigenheiten). */
	private static function find_by_slug( $slug ) {
		$q = get_posts( array( 'post_type' => self::CPT, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1 ) );
		return $q ? $q[0] : null;
	}

	/** Veroeffentlichte m24_teil im Term (Count, fuer „populaersten Term"-Wahl). */
	private static function term_part_count( $term_id ) {
		$q = new WP_Query( array(
			'post_type' => 'm24_teil', 'post_status' => 'publish', 'fields' => 'ids',
			'posts_per_page' => -1, 'no_found_rows' => true,
			'tax_query' => array( array( 'taxonomy' => self::TAX, 'terms' => (int) $term_id ) ),
		) );
		return count( $q->posts );
	}

	/**
	 * Korrektur (einmalig): Doppel-Terme. Je Hub den POPULAERSTEN Kandidaten-Term
	 * (flacher Hauptbestand statt hierarchischem „BMW M3 …" unter „BMW 3er") waehlen
	 * und m24_hub_terms darauf setzen (ueberschreibt das falsche Backfill-Mapping).
	 */
	public static function maybe_fix_terms() {
		if ( get_option( self::FIX_FLAG ) ) { return; }
		if ( ! taxonomy_exists( self::TAX ) ) { return; }
		update_option( self::FIX_FLAG, gmdate( 'c' ) );
		$cands = apply_filters( 'm24_hub_term_candidates', array(
			'm3-e30'                 => array( 'm3-e30', 'bmw-m3-e30' ),
			'm3-e36'                 => array( 'm3-e36', 'bmw-m3-e36' ),
			'm3-e46'                 => array( 'm3-e46', 'bmw-m3-e46' ),
			'm3-e9x'                 => array( 'm3-e9x', 'bmw-m3-e9x' ),
			'sonstige-bmw-m-modelle' => array( 'sonstige-bmw-m-modelle', 'bmw-m-sonstige' ),
		) );
		$changed = false;
		foreach ( $cands as $hub => $slugs ) {
			$post = self::find_by_slug( $hub );
			if ( ! $post ) { continue; }
			$best = 0; $best_n = -1;
			foreach ( $slugs as $s ) {
				$t = get_term_by( 'slug', $s, self::TAX );
				if ( ! $t || is_wp_error( $t ) ) { continue; }
				$n = self::term_part_count( $t->term_id );
				if ( $n > $best_n ) { $best_n = $n; $best = (int) $t->term_id; }
			}
			if ( $best ) { update_post_meta( $post->ID, self::META . 'terms', array( $best ) ); $changed = true; }
		}
		if ( $changed && class_exists( 'M24_Catalog_Hub' ) ) { M24_Catalog_Hub::flush_registry(); }
	}

	/** Term-IDs aus Slugs (existierende). */
	private static function term_ids_from_slugs( $slugs ) {
		$ids = array();
		foreach ( $slugs as $s ) {
			$t = get_term_by( 'slug', $s, self::TAX );
			if ( $t && ! is_wp_error( $t ) ) { $ids[] = (int) $t->term_id; }
		}
		return $ids;
	}

	/**
	 * Einmalige Migration auf neutrale Base /modelle/ + bmw-Slugs (Option A):
	 * 1) Post-Slugs umbenennen (m3-e30 → bmw-m3-e30 …; sonstige bleibt),
	 * 2) default_kat-Backfill = „gebraucht" für die 5 Bestands-Hubs,
	 * 3) Z4-GT3-Hub (+ Term) anlegen,
	 * 4) Menue-Links generisch von /gebrauchtteile/{hub} auf /modelle/{slug} umstellen.
	 * Reversibel (nur Slugs/URLs), idempotent über MIGRATE_FLAG.
	 */
	public static function maybe_migrate_modelle() {
		if ( get_option( self::MIGRATE_FLAG ) ) { return; }
		if ( ! taxonomy_exists( self::TAX ) || ! post_type_exists( self::CPT ) ) { return; }
		update_option( self::MIGRATE_FLAG, gmdate( 'c' ) );

		// 1) Slugs umbenennen.
		$rename = array( 'm3-e30' => 'bmw-m3-e30', 'm3-e36' => 'bmw-m3-e36', 'm3-e46' => 'bmw-m3-e46', 'm3-e9x' => 'bmw-m3-e9x' );
		foreach ( $rename as $old => $new ) {
			$p = self::find_by_slug( $old );
			if ( $p && ! self::find_by_slug( $new ) ) {
				wp_update_post( array( 'ID' => $p->ID, 'post_name' => $new ) );
			}
		}
		// 2) default_kat = gebraucht (Bestands-Hubs).
		foreach ( array( 'bmw-m3-e30', 'bmw-m3-e36', 'bmw-m3-e46', 'bmw-m3-e9x', 'sonstige-bmw-m-modelle' ) as $slug ) {
			$p = self::find_by_slug( $slug );
			if ( $p && '' === (string) get_post_meta( $p->ID, self::META . 'default_kat', true ) ) {
				update_post_meta( $p->ID, self::META . 'default_kat', 'gebraucht' );
			}
		}
		// 3) Bestehende Cross-Links auf neue /modelle/-URLs umschreiben (kein 301-Hop).
		self::migrate_cross_links();
		// 4) Z4-GT3-Hub.
		self::ensure_z4_hub();
		// 5) Menue.
		self::migrate_menu_urls();

		if ( class_exists( 'M24_Catalog_Hub' ) ) { M24_Catalog_Hub::flush_registry(); }
		flush_rewrite_rules( false );
	}

	/** Z4-GT3-Hub + zugehoerigen Modell-Term idempotent anlegen. */
	private static function ensure_z4_hub() {
		if ( self::find_by_slug( 'bmw-z4-gt3' ) ) { return; }
		// Term sicherstellen.
		$term = get_term_by( 'slug', 'z4-gt3', self::TAX );
		if ( ! $term || is_wp_error( $term ) ) {
			$t = wp_insert_term( 'Z4 GT3', self::TAX, array( 'slug' => 'z4-gt3' ) );
			$term_id = ( ! is_wp_error( $t ) && isset( $t['term_id'] ) ) ? (int) $t['term_id'] : 0;
		} else {
			$term_id = (int) $term->term_id;
		}
		$d   = self::z4_seed();
		$pid = wp_insert_post( array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => $d['h1'],
			'post_name'   => $d['slug'],
			'menu_order'  => 10,
		) );
		if ( ! $pid || is_wp_error( $pid ) ) { return; }
		update_post_meta( $pid, self::META . 'terms', $term_id ? array( $term_id ) : array() );
		foreach ( array( 'modell', 'motor', 'baujahre', 'h1', 'sub', 'intro_h2', 'seo_title', 'seo_desc' ) as $k ) {
			update_post_meta( $pid, self::META . $k, $d[ $k ] ?? '' );
		}
		update_post_meta( $pid, self::META . 'intro', $d['intro'] ?? '' );
		update_post_meta( $pid, self::META . 'seo_text', $d['seo_text'] ?? '' );
		update_post_meta( $pid, self::META . 'images', '' );
		update_post_meta( $pid, self::META . 'cross_links', $d['cross_links'] ?? array() );
		update_post_meta( $pid, self::META . 'default_kat', 'rennsport' );
	}

	/** Pfad-Map alt → neu (Cross-Links + Menue teilen sie). */
	private static function path_map() {
		return array(
			'/gebrauchtteile/m3-e30'                 => '/modelle/bmw-m3-e30',
			'/gebrauchtteile/m3-e36'                 => '/modelle/bmw-m3-e36',
			'/gebrauchtteile/m3-e46'                 => '/modelle/bmw-m3-e46',
			'/gebrauchtteile/m3-e9x'                 => '/modelle/bmw-m3-e9x',
			'/gebrauchtteile/sonstige-bmw-m-modelle' => '/modelle/sonstige-bmw-m-modelle',
		);
	}

	/** Cross-Links aller Hubs von /gebrauchtteile/{hub} auf /modelle/{slug} umschreiben. */
	private static function migrate_cross_links() {
		$map = self::path_map();
		foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
			$cross = get_post_meta( $p->ID, self::META . 'cross_links', true );
			if ( ! is_array( $cross ) || empty( $cross ) ) { continue; }
			$dirty = false;
			foreach ( $cross as &$cl ) {
				$u = isset( $cl['url'] ) ? (string) $cl['url'] : '';
				foreach ( $map as $old => $new ) {
					if ( $u === $old || $u === $old . '/' || 0 === strpos( $u, $old . '/' ) ) {
						$cl['url'] = $new . substr( $u, strlen( $old ) );
						$dirty = true;
						break;
					}
				}
			}
			unset( $cl );
			if ( $dirty ) { update_post_meta( $p->ID, self::META . 'cross_links', $cross ); }
		}
	}

	/** Menue-Items von /gebrauchtteile/{hub} → /modelle/{slug} umstellen (generisch per URL). */
	private static function migrate_menu_urls() {
		$map   = self::path_map();
		$items = get_posts( array( 'post_type' => 'nav_menu_item', 'post_status' => 'any', 'numberposts' => -1 ) );
		foreach ( $items as $it ) {
			$url = (string) get_post_meta( $it->ID, '_menu_item_url', true );
			if ( '' === $url ) { continue; }
			foreach ( $map as $old => $new ) {
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				if ( $path === $old || $path === $old . '/' || 0 === strpos( $path, $old . '/' ) ) {
					$newpath = $new . substr( $path, strlen( $old ) );
					update_post_meta( $it->ID, '_menu_item_url', home_url( $newpath ) );
					break;
				}
			}
		}
		self::add_z4_menu_item();
	}

	/** Z4-GT3-Link best-effort unter „Rennsport Teile" ergaenzen (idempotent). */
	private static function add_z4_menu_item() {
		$z4_url = home_url( '/modelle/bmw-z4-gt3/' );
		// Schon vorhanden?
		foreach ( get_posts( array( 'post_type' => 'nav_menu_item', 'post_status' => 'any', 'numberposts' => -1 ) ) as $it ) {
			if ( home_url( (string) wp_parse_url( (string) get_post_meta( $it->ID, '_menu_item_url', true ), PHP_URL_PATH ) ) === $z4_url ) { return; }
		}
		// Parent „Rennsport Teile" finden.
		$parent = null;
		foreach ( get_posts( array( 'post_type' => 'nav_menu_item', 'post_status' => 'any', 'numberposts' => -1 ) ) as $it ) {
			$title = get_the_title( $it->ID );
			if ( '' !== $title && false !== stripos( $title, 'Rennsport' ) && false !== stripos( $title, 'Teile' ) ) { $parent = $it; break; }
		}
		if ( ! $parent ) { return; } // kein passender Parent → Daniel ergaenzt manuell
		$menus = wp_get_object_terms( $parent->ID, 'nav_menu' );
		if ( empty( $menus ) || is_wp_error( $menus ) ) { return; }
		wp_update_nav_menu_item( (int) $menus[0]->term_id, 0, array(
			'menu-item-title'     => 'BMW Z4 GT3',
			'menu-item-url'       => $z4_url,
			'menu-item-parent-id' => (int) $parent->ID,
			'menu-item-status'    => 'publish',
			'menu-item-type'      => 'custom',
		) );
	}

	/** Existierende Hub-Slugs (Set), um Doppel-Seeds sicher zu vermeiden. */
	private static function existing_slugs() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
			$out[ $p->post_name ] = true;
		}
		return $out;
	}

	public static function seed() {
		$existing = self::existing_slugs();
		foreach ( self::seed_data() as $i => $d ) {
			if ( isset( $existing[ $d['slug'] ] ) ) { continue; } // schon vorhanden
			$existing[ $d['slug'] ] = true;                       // gegen Doppel-Insert im selben Lauf
			$pid = wp_insert_post( array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $d['h1'],
				'post_name'   => $d['slug'],
				'menu_order'  => $i,
			) );
			if ( ! $pid || is_wp_error( $pid ) ) { continue; }
			update_post_meta( $pid, self::META . 'terms', self::term_ids_from_slugs( $d['terms'] ) );
			foreach ( array( 'modell', 'motor', 'baujahre', 'h1', 'sub', 'intro_h2', 'seo_title', 'seo_desc' ) as $k ) {
				update_post_meta( $pid, self::META . $k, $d[ $k ] ?? '' );
			}
			update_post_meta( $pid, self::META . 'intro', $d['intro'] ?? '' );
			update_post_meta( $pid, self::META . 'seo_text', $d['seo_text'] ?? '' );
			update_post_meta( $pid, self::META . 'images', '' );
			update_post_meta( $pid, self::META . 'cross_links', $d['cross_links'] ?? array() );
			update_post_meta( $pid, self::META . 'default_kat', $d['default_kat'] ?? 'gebraucht' );
		}
	}

	/** Z4-GT3 Startwerte (Rennsport-Hub). Quelle fuer ensure_z4_hub(). */
	public static function z4_seed() {
		$p = function ( $s ) { return '<p>' . $s . '</p>'; };
		return array(
			'slug' => 'bmw-z4-gt3', 'terms' => array( 'z4-gt3' ),
			'modell' => 'Z4 GT3 (E89)', 'motor' => 'P65 V8 · 4,4 L', 'baujahre' => 'Renneinsatz 2010–2015',
			'h1' => 'Rennsport-Teile passend für BMW Z4 GT3',
			'sub' => 'Aus eigenen Rennsport-Umbauten – geprüft – mit Historie plus unsere Auswahl an eigenen Rennsport-Teilen.',
			'intro_h2' => 'Teile für den BMW Z4 GT3',
			'intro' => $p( 'Aus eigenen Rennsport-Umbauten und geprüften Beständen. Der Z4 GT3 fuhr ab 2010 nach FIA-GT3-Reglement, befeuert vom 4,4-Liter-V8 P65 — der Motorsport-Variante des S65 aus dem M3 (E9x). Wir führen Teile rund um Antrieb, Aero, Fahrwerk und Karosserie. Ein bestimmtes Teil nicht gelistet? Fragen Sie gezielt an — wir greifen auf einen größeren, nicht vollständig online gelisteten Bestand zu.' ),
			'seo_title' => 'Rennsport-Teile passend für BMW Z4 GT3 | MOTORSPORT24 seit 2006',
			'seo_desc' => 'Rennsport-Teile passend für den BMW Z4 GT3 (P65 V8) — aus eigenen Umbauten, geprüft, weltweiter Versand bei MOTORSPORT24 seit 2006.',
			'seo_text' => '<h2>Rennsport-Teile passend für BMW Z4 GT3 — laufend wechselnder Bestand</h2>'
				. $p( 'Der BMW Z4 GT3 (E89) trat ab 2010 nach FIA-GT3-Reglement an, angetrieben vom 4,4-Liter-V8 P65 — der Rennsport-Ableitung des S65 aus dem M3 E9x. Wir führen Teile rund um Antrieb, Aerodynamik, Fahrwerk und Karosserie. Die Herkunft ist bei jedem Artikel angegeben; der Bestand wechselt laufend.' ),
			'cross_links' => array(
				array( 'label' => 'M3 E9x (S65/P65 V8)', 'url' => '/modelle/bmw-m3-e9x/' ),
			),
		);
	}

	/** Redaktionelle Startwerte (E30 aus Bestand, E36/E46/E9x/Sonstige aus Content-MD v1). */
	public static function seed_data() {
		$p = function ( $s ) { return '<p>' . $s . '</p>'; };
		return array(
			array(
				'slug' => 'bmw-m3-e30', 'terms' => array( 'm3-e30' ),
				'modell' => 'M3 E30', 'motor' => 'S14 2,3 / 2,5 L', 'baujahre' => '1986–1991',
				'h1' => 'Gebrauchtteile passend für BMW M3 E30',
				'sub' => 'Aus eigenen Rennsport-Umbauten – geprüft – mit Historie plus unsere Auswahl an eigenen gebrauchten Rennsport-Teilen.',
				'intro_h2' => 'Teile mit Historie — aus eigenen Rennsport-Umbauten',
				'intro' => $p( 'Unsere Gebrauchtteile passend für den BMW M3 E30 stammen überwiegend aus unseren eigenen Rennsport-Umbauten: Wenn wir einen M3 E30 für den Renneinsatz auf- oder umbauen, werden hochwertige Originalteile fachgerecht ausgebaut, geprüft und hier mit klarer Herkunft angeboten. Dazu kommen ausgewählte Aftermarket-Teile passend für den M3 E30.' )
					. $p( 'So bekommen Sie Teile mit Geschichte statt anonymer Massenware. Seit 2006 beliefern wir Werkstätten, Restauratoren und Rennsport-Teams weltweit. Sie suchen ein bestimmtes Teil, das hier noch nicht gelistet ist? Fragen Sie uns — wir greifen auf einen großen, nicht vollständig online gelisteten Bestand zu.' ),
				'seo_title' => 'Gebrauchtteile passend für BMW M3 E30 | MOTORSPORT24 seit 2006',
				'seo_desc' => '',
				'seo_text' => '<h2>Gebrauchtteile passend für BMW M3 E30 — laufend wechselnder Bestand</h2>'
					. $p( 'Der BMW M3 E30 wurde von 1986 bis 1991 gebaut — vom 2,3-Liter-S14 der ersten Serie bis zur Sport-Evolution mit 2,5 Litern. Wir führen Gebrauchtteile für die verschiedenen Ausbaustufen: Motorperipherie rund um den S14, Fahrwerk und Bremse, Karosserie- sowie Interieur-Teile. Die Herkunft ist bei jedem Artikel angegeben — viele stammen aus eigenen Rennumbauten. Der Bestand wechselt laufend, ein regelmäßiger Blick lohnt sich.' ),
				'cross_links' => array(
					array( 'label' => 'passend für BMW M3 E36', 'url' => '/modelle/bmw-m3-e36/' ),
					array( 'label' => 'passend für BMW M3 E46', 'url' => '/modelle/bmw-m3-e46/' ),
				),
			),
			array(
				'slug' => 'bmw-m3-e36', 'terms' => array( 'm3-e36' ),
				'modell' => 'M3 E36', 'motor' => 'S50 3,0 / 3,2 L', 'baujahre' => '1992–1999',
				'h1' => 'Gebrauchtteile passend für BMW M3 E36',
				'sub' => 'Aus eigenen Rennsport-Umbauten – geprüft – mit Historie plus unsere Auswahl an eigenen gebrauchten Rennsport-Teilen.',
				'intro_h2' => '',
				'intro' => $p( 'Unsere Gebrauchtteile passend für den BMW M3 E36 stammen aus eigenen Rennsport-Umbauten und geprüften Beständen. Der E36 M3 markierte den Sprung vom Vierzylinder zum Reihensechszylinder: zunächst der 3,0-Liter-S50B30 mit 286 PS, ab 1995 der 3,2-Liter-S50B32 mit 321 PS. Wir führen Teile rund um den S50-Motor, Fahrwerk, Bremse, Karosserie und Interieur.' )
					. $p( 'Seit 2006 beliefern wir Werkstätten, Restauratoren und Rennsport-Teams weltweit. Ein bestimmtes E36-Teil ist hier nicht gelistet? Fragen Sie gezielt an — wir greifen auf einen größeren, nicht vollständig online gelisteten Bestand zu.' ),
				'seo_title' => 'Gebrauchtteile passend für BMW M3 E36 | MOTORSPORT24 seit 2006',
				'seo_desc' => '',
				'seo_text' => '<h2>Gebrauchtteile passend für BMW M3 E36 — laufend wechselnder Bestand</h2>'
					. $p( 'Der BMW M3 E36 wurde von 1992 bis 1999 gebaut — vom 3,0-Liter-S50B30 der ersten Serie bis zum 3,2-Liter-S50B32 der zweiten. Neben Limousine, Coupé und Cabrio gab es Sondermodelle wie den M3 GT. Wir führen Gebrauchtteile für Motor, Antrieb, Fahrwerk, Bremse sowie Karosserie- und Interieur-Teile. Die Herkunft ist bei jedem Artikel angegeben; der Bestand wechselt laufend.' ),
				'cross_links' => array(
					array( 'label' => 'passend für BMW M3 E30', 'url' => '/modelle/bmw-m3-e30/' ),
					array( 'label' => 'passend für BMW M3 E46', 'url' => '/modelle/bmw-m3-e46/' ),
				),
			),
			array(
				'slug' => 'bmw-m3-e46', 'terms' => array( 'm3-e46' ),
				'modell' => 'M3 E46', 'motor' => 'S54B32 3,2 L', 'baujahre' => '2000–2006',
				'h1' => 'Gebrauchtteile passend für BMW M3 E46',
				'sub' => 'Aus eigenen Rennsport-Umbauten – geprüft – mit Historie plus unsere Auswahl an eigenen gebrauchten Rennsport-Teilen.',
				'intro_h2' => '',
				'intro' => $p( 'Unsere Gebrauchtteile passend für den BMW M3 E46 kommen aus eigenen Rennsport-Umbauten und geprüften Beständen. Herzstück ist der hochdrehende 3,2-Liter-Reihensechszylinder S54B32 mit 343 PS — im leichtgewichtigen M3 CSL bis 360 PS. Wir führen Teile rund um den S54, Antrieb (inkl. SMG II), Fahrwerk, Bremse, Karosserie und Interieur.' )
					. $p( 'Seit 2006 beliefern wir Werkstätten, Restauratoren und Rennsport-Teams weltweit. Ein bestimmtes E46-Teil ist hier nicht gelistet? Fragen Sie gezielt an.' ),
				'seo_title' => 'Gebrauchtteile passend für BMW M3 E46 | MOTORSPORT24 seit 2006',
				'seo_desc' => '',
				'seo_text' => '<h2>Gebrauchtteile passend für BMW M3 E46 — laufend wechselnder Bestand</h2>'
					. $p( 'Der BMW M3 E46 wurde von 2000 bis 2006 gebaut, angetrieben vom hochdrehenden S54B32 mit 343 PS, in der CSL-Variante 360 PS. Wir führen Gebrauchtteile für Motor, Antrieb, Fahrwerk, Bremse sowie Karosserie- und Interieur-Teile. Die Herkunft ist bei jedem Artikel angegeben; der Bestand wechselt laufend.' ),
				'cross_links' => array(
					array( 'label' => 'passend für BMW M3 E36', 'url' => '/modelle/bmw-m3-e36/' ),
					array( 'label' => 'passend für BMW M3 E9x', 'url' => '/modelle/bmw-m3-e9x/' ),
				),
			),
			array(
				'slug' => 'bmw-m3-e9x', 'terms' => array( 'm3-e9x' ),
				'modell' => 'M3 E9x (E90/E92/E93)', 'motor' => 'S65B40 V8 4,0 L', 'baujahre' => '2007–2013',
				'h1' => 'Gebrauchtteile passend für BMW M3 E9x',
				'sub' => 'Aus eigenen Rennsport-Umbauten – geprüft – mit Historie plus unsere Auswahl an eigenen gebrauchten Rennsport-Teilen.',
				'intro_h2' => '',
				'intro' => $p( 'Unsere Gebrauchtteile passend für den BMW M3 E9x (E90 Limousine, E92 Coupé, E93 Cabrio) stammen aus eigenen Rennsport-Umbauten und geprüften Beständen. Der E9x ist der einzige M3 mit V8: der 4,0-Liter-S65B40 leistet 420 PS und dreht bis rund 8.400/min. Wir führen Teile rund um den S65, Antrieb (inkl. DKG), Fahrwerk, Bremse, Karosserie und Interieur.' )
					. $p( 'Seit 2006 beliefern wir Werkstätten, Restauratoren und Rennsport-Teams weltweit. Ein bestimmtes E9x-Teil ist hier nicht gelistet? Fragen Sie gezielt an.' ),
				'seo_title' => 'Gebrauchtteile passend für BMW M3 E9x | MOTORSPORT24 seit 2006',
				'seo_desc' => '',
				'seo_text' => '<h2>Gebrauchtteile passend für BMW M3 E9x — laufend wechselnder Bestand</h2>'
					. $p( 'Der BMW M3 E9x wurde von 2007 bis 2013 gebaut — als Limousine (E90), Coupé (E92) und Cabrio (E93). Angetrieben vom einzigen V8-M3, dem 4,0-Liter-S65B40 mit 420 PS. Wir führen Gebrauchtteile für Motor, Antrieb (inkl. DKG), Fahrwerk, Bremse sowie Karosserie- und Interieur-Teile. Die Herkunft ist bei jedem Artikel angegeben; der Bestand wechselt laufend.' ),
				'cross_links' => array(
					array( 'label' => 'passend für BMW M3 E46', 'url' => '/modelle/bmw-m3-e46/' ),
					array( 'label' => 'passend für weitere BMW M-Modelle', 'url' => '/modelle/sonstige-bmw-m-modelle/' ),
				),
			),
			array(
				'slug' => 'sonstige-bmw-m-modelle', 'terms' => array( 'sonstige-bmw-m-modelle' ),
				'modell' => 'weitere BMW M-Modelle', 'motor' => '', 'baujahre' => '',
				'h1' => 'Gebrauchte Teile passend für weitere BMW M-Modelle',
				'sub' => 'Aus eigenen Rennsport-Umbauten – geprüft – mit Historie plus unsere Auswahl an eigenen gebrauchten Rennsport-Teilen.',
				'intro_h2' => '',
				'intro' => $p( 'Hier finden Sie Gebrauchtteile passend für weitere BMW M-Modelle, die keinen eigenen Modell-Hub haben — unter anderem M2, M4, M5, M6, Z4 M sowie M-Varianten der X-Reihe. Die Teile stammen aus eigenen Rennsport-Umbauten und geprüften Beständen.' )
					. $p( 'Seit 2006 beliefern wir Werkstätten, Restauratoren und Rennsport-Teams weltweit. Sie suchen ein M-Teil für ein bestimmtes Modell? Fragen Sie gezielt an — wir greifen auf einen größeren, nicht vollständig online gelisteten Bestand zu.' ),
				'seo_title' => 'Gebrauchte Teile passend für weitere BMW M-Modelle | MOTORSPORT24 seit 2006',
				'seo_desc' => '',
				'seo_text' => '<h2>Gebrauchtteile passend für weitere BMW M-Modelle — laufend wechselnder Bestand</h2>'
					. $p( 'In dieser Übersicht bündeln wir Gebrauchtteile passend für weitere BMW M-Modelle abseits der M3-Baureihen E30, E36, E46 und E9x — unter anderem M2, M4, M5, M6, Z4 M sowie M-Varianten der X-Reihe. Motor, Antrieb, Fahrwerk, Bremse, Karosserie und Interieur: die Herkunft ist bei jedem Artikel angegeben; der Bestand wechselt laufend.' ),
				'cross_links' => array(
					array( 'label' => 'passend für BMW M3 E30', 'url' => '/modelle/bmw-m3-e30/' ),
					array( 'label' => 'passend für BMW M3 E36', 'url' => '/modelle/bmw-m3-e36/' ),
					array( 'label' => 'passend für BMW M3 E46', 'url' => '/modelle/bmw-m3-e46/' ),
					array( 'label' => 'passend für BMW M3 E9x', 'url' => '/modelle/bmw-m3-e9x/' ),
				),
			),
		);
	}
}
