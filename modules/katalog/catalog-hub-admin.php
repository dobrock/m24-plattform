<?php
/**
 * M24 Plattform — Modell-Hub: Term-Meta-Admin
 * Modul: modules/katalog/catalog-hub-admin.php  ·  Daten/Logik: M24_Catalog_Hub
 *
 * Blendet eine „Modell-Hub"-Sektion in den Term-Editor der Fahrzeug-Taxonomie ein —
 * aber NUR auf dem Primaer-Term eines Hubs (M24_Catalog_Hub::hub_of_term). Felder:
 * Slideshow-Bilder (Mediathek, mehrfach, sortierbar), H1/Sub/Intro/Keyfacts/SEO.
 * Speicherung als Term-Meta (Schluessel via M24_Catalog_Hub::META_PREFIX + meta_keys()).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Hub_Admin {

	const TAX = 'm24_fahrzeugkat';

	public static function init() {
		add_action( self::TAX . '_edit_form_fields', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'edited_' . self::TAX, array( __CLASS__, 'save' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Media-Picker + Sortable nur auf dem Term-Editor unserer Taxonomie laden. */
	public static function enqueue( $hook ) {
		if ( 'term.php' !== $hook ) { return; }
		$tax = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( self::TAX !== $tax ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
	}

	private static function k( $key ) { return M24_Catalog_Hub::META_PREFIX . $key; }
	private static function val( $term_id, $key ) { return (string) get_term_meta( (int) $term_id, self::k( $key ), true ); }

	public static function render( $term ) {
		if ( ! class_exists( 'M24_Catalog_Hub' ) ) { return; }
		$hub = M24_Catalog_Hub::hub_of_term( $term->term_id );
		if ( '' === $hub ) { return; } // nur Primaer-Terme der 5 Hubs

		$tid     = (int) $term->term_id;
		$seed    = M24_Catalog_Hub::hubs()[ $hub ];
		$hub_url = M24_Catalog_Hub::url( $hub );
		$count   = M24_Catalog_Hub::count( $hub );

		// Bild-IDs (gespeichert) → Vorschau-Liste.
		$img_csv = self::val( $tid, 'images' );
		$img_ids = array_values( array_filter( array_map( 'intval', explode( ',', $img_csv ) ) ) );
		?>
		<tr class="form-field m24-hub-head">
			<th colspan="2" style="padding-bottom:0">
				<h2 style="margin:.6em 0 .2em">Modell-Hub <span style="font-weight:400;color:#666">— Landingpage <a href="<?php echo esc_url( $hub_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $hub_url ); ?></a></span></h2>
				<p class="description" style="font-weight:400">Redaktioneller Kopf der Hub-Seite. Leere Felder nutzen den Standardtext. Live-Zähler: <strong><?php echo (int) $count; ?></strong> aktive Teile.</p>
			</th>
		</tr>

		<tr class="form-field">
			<th scope="row"><label>Slideshow-Bilder</label></th>
			<td>
				<input type="hidden" id="m24_hub_images" name="m24_hub_images" value="<?php echo esc_attr( implode( ',', $img_ids ) ); ?>">
				<ul id="m24-hub-imgs" class="m24-hub-imgs">
					<?php foreach ( $img_ids as $id ) :
						$src = wp_get_attachment_image_src( $id, 'thumbnail' );
						if ( ! $src ) { continue; } ?>
						<li class="m24-hub-img" data-id="<?php echo (int) $id; ?>">
							<img src="<?php echo esc_url( $src[0] ); ?>" alt="">
							<button type="button" class="m24-hub-rm" aria-label="Entfernen">&times;</button>
						</li>
					<?php endforeach; ?>
				</ul>
				<button type="button" class="button" id="m24-hub-add">Bilder hinzufügen</button>
				<p class="description">Mehrere möglich · per Drag &amp; Drop sortierbar. Erstes Bild = OG-/Social-Vorschau. Quelle: Mediathek (Alt-Text dort wird als Bild-Alt genutzt).</p>
			</td>
		</tr>

		<?php
		self::text_row( 'h1', 'H1 / Titel-Override', $tid, M24_Catalog_Hub::h1( $hub ) );
		self::text_row( 'sub', 'Sub-Headline', $tid, $seed['sub'] ?? '' );
		self::text_row( 'intro_h2', 'Intro-Überschrift (H2)', $tid, $seed['intro_h2'] ?? '' );
		?>

		<tr class="form-field">
			<th scope="row"><label for="m24_hub_intro">Intro-Text</label></th>
			<td>
				<?php
				wp_editor(
					self::val( $tid, 'intro' ),
					'm24_hub_intro',
					array( 'textarea_name' => 'm24_hub_intro', 'textarea_rows' => 8, 'media_buttons' => false, 'teeny' => true )
				);
				?>
				<p class="description">Leer ⇒ Standard-Intro (sofern hinterlegt).</p>
			</td>
		</tr>

		<?php
		self::text_row( 'modell', 'Keyfact — Modell', $tid, $seed['modell'] ?? '' );
		self::text_row( 'motor', 'Keyfact — Motor', $tid, $seed['motor'] ?? '' );
		self::text_row( 'baujahre', 'Keyfact — Baujahre', $tid, $seed['baujahre'] ?? '' );
		self::text_row( 'seo_title', 'SEO-Title', $tid, '', '≤ ~65 Zeichen · leer ⇒ automatischer Title.' );
		?>

		<tr class="form-field">
			<th scope="row"><label for="m24_hub_seo_desc">SEO-Description</label></th>
			<td>
				<textarea name="m24_hub_seo_desc" id="m24_hub_seo_desc" rows="2" class="large-text"><?php echo esc_textarea( self::val( $tid, 'seo_desc' ) ); ?></textarea>
				<p class="description">≤ ~158 Zeichen · leer ⇒ Anriss aus dem Intro.</p>
			</td>
		</tr>

		<style>
		.m24-hub-imgs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px;padding:0;list-style:none}
		.m24-hub-img{position:relative;width:84px;height:64px;border:1px solid #c3c4c7;border-radius:6px;overflow:hidden;cursor:grab;background:#f0f0f1}
		.m24-hub-img img{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
		.m24-hub-img .m24-hub-rm{position:absolute;top:2px;right:2px;width:18px;height:18px;line-height:16px;padding:0;border:none;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;font-size:13px;cursor:pointer}
		.m24-hub-imgs .ui-sortable-placeholder{visibility:visible !important;border:1px dashed #2271b1;background:#f6f7f7}
		</style>
		<script>
		jQuery(function($){
			var $list=$('#m24-hub-imgs'), $input=$('#m24_hub_images');
			function sync(){ $input.val($list.children('[data-id]').map(function(){return $(this).data('id');}).get().join(',')); }
			if($.fn.sortable){ $list.sortable({items:'> li',tolerance:'pointer',forcePlaceholderSize:true,update:sync}); }
			$('#m24-hub-add').on('click',function(e){
				e.preventDefault();
				var frame=wp.media({title:'Slideshow-Bilder wählen',button:{text:'Übernehmen'},multiple:true,library:{type:'image'}});
				frame.on('select',function(){
					frame.state().get('selection').each(function(att){
						var a=att.toJSON();
						if($list.find('[data-id="'+a.id+'"]').length){ return; }
						var url=(a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url;
						$list.append('<li class="m24-hub-img" data-id="'+a.id+'"><img src="'+url+'" alt=""><button type="button" class="m24-hub-rm" aria-label="Entfernen">&times;</button></li>');
					});
					sync();
				});
				frame.open();
			});
			$list.on('click','.m24-hub-rm',function(){ $(this).closest('[data-id]').remove(); sync(); });
		});
		</script>
		<?php
	}

	/** Eine Standard-Textzeile (Label + Input + optionale Description; placeholder = Default). */
	private static function text_row( $key, $label, $term_id, $placeholder = '', $desc = '' ) {
		$id = 'm24_hub_' . $key;
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" name="<?php echo esc_attr( $id ); ?>" id="<?php echo esc_attr( $id ); ?>" class="large-text"
					value="<?php echo esc_attr( self::val( $term_id, $key ) ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>">
				<?php if ( '' !== $desc ) : ?><p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/** Speichern (Caps via Core-Term-Update bereits geprueft; hier sanitisieren). */
	public static function save( $term_id ) {
		if ( ! current_user_can( 'manage_categories' ) ) { return; }
		if ( ! class_exists( 'M24_Catalog_Hub' ) || '' === M24_Catalog_Hub::hub_of_term( $term_id ) ) { return; }

		// Bilder: CSV aus IDs.
		if ( isset( $_POST['m24_hub_images'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['m24_hub_images'] ) ) ) ) ) );
			self::store( $term_id, 'images', implode( ',', $ids ) );
		}

		foreach ( array( 'h1', 'sub', 'intro_h2', 'modell', 'motor', 'baujahre', 'seo_title' ) as $key ) {
			if ( isset( $_POST[ 'm24_hub_' . $key ] ) ) {
				self::store( $term_id, $key, sanitize_text_field( wp_unslash( $_POST[ 'm24_hub_' . $key ] ) ) );
			}
		}
		if ( isset( $_POST['m24_hub_seo_desc'] ) ) {
			self::store( $term_id, 'seo_desc', sanitize_textarea_field( wp_unslash( $_POST['m24_hub_seo_desc'] ) ) );
		}
		if ( isset( $_POST['m24_hub_intro'] ) ) {
			self::store( $term_id, 'intro', wp_kses_post( wp_unslash( $_POST['m24_hub_intro'] ) ) );
		}
	}

	/** Nicht-leere Werte speichern, leere loeschen (Default greift). */
	private static function store( $term_id, $key, $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( '' === $value ) { delete_term_meta( (int) $term_id, self::k( $key ) ); }
		else { update_term_meta( (int) $term_id, self::k( $key ), $value ); }
	}
}
