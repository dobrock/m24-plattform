<?php
/**
 * M24 Plattform — Katalog: Bilder & Galerie (Admin Meta-Box)
 * Modul: catalog-gallery.php
 *
 * Einfache Galerie-Verwaltung: mehrere Bilder per WP-Medienauswahl hinzufügen,
 * einzeln entfernen. Gespeichert als CSV von Attachment-IDs in `_m24_galerie`.
 * Reihenfolge = Hinzufügereihenfolge. Drag-Sortierung + „Groß"-Markierung folgen
 * im erweiterten Galerie-Modul. Titelbild bleibt das „Beitragsbild".
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Gallery {

	const NONCE = 'm24_catalog_gallery_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || M24_Catalog_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
		// Paket F: jQuery-UI-Sortable fuer Drag-&-Drop-Reorder der Galerie-Thumbnails.
		wp_enqueue_script( 'jquery-ui-sortable' );
	}

	public static function add_box() {
		add_meta_box( 'm24_teil_galerie', 'Bilder &amp; Galerie', array( __CLASS__, 'render' ), M24_Catalog_CPT::POST_TYPE, 'normal', 'high' );
	}

	public static function render( $post ) {
		wp_nonce_field( 'm24_gal_' . $post->ID, self::NONCE );
		$ids = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $post->ID, '_m24_galerie', true ) ) ) );
		?>
		<p class="description">Titelbild = „Beitragsbild" (rechte Seitenleiste). Hier die weiteren Galeriebilder (Querformat 4:3, vollständig, ungeschnitten). <b>Drag &amp; Drop</b> zum Sortieren.</p>
		<style>
			#m24_galerie_preview .m24-gal-item{cursor:move;user-select:none}
			#m24_galerie_preview .m24-gal-item.ui-sortable-helper{box-shadow:0 4px 12px rgba(0,0,0,.18);transform:scale(1.04)}
			#m24_galerie_preview .m24-gal-placeholder{width:80px;height:60px;border:2px dashed #999;border-radius:5px;background:rgba(0,0,0,.04);box-sizing:border-box}
			#m24_galerie_preview .m24-gal-remove{cursor:pointer}
		</style>
		<input type="hidden" id="m24_galerie_ids" name="m24_galerie" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
		<div id="m24_galerie_preview" style="display:flex;flex-wrap:wrap;gap:8px;margin:10px 0">
			<?php foreach ( $ids as $id ) :
				// „medium" (300px) statt „thumbnail" (150px) → auf 80×60 skaliert scharf; srcset = 2x für HiDPI.
				$src = wp_get_attachment_image_url( $id, 'medium' );
				if ( ! $src ) { $src = wp_get_attachment_image_url( $id, 'full' ); } // kein Upscaling einer Mini-Größe
				$srcset = wp_get_attachment_image_srcset( $id, 'medium' );
				?>
				<div class="m24-gal-item" data-id="<?php echo esc_attr( $id ); ?>" style="position:relative;width:80px;height:60px;border:1px solid #ddd;border-radius:5px;overflow:hidden">
					<img src="<?php echo esc_url( $src ); ?>"<?php echo $srcset ? ' srcset="' . esc_attr( $srcset ) . '" sizes="80px"' : ''; ?> style="width:100%;height:100%;object-fit:cover">
					<button type="button" class="m24-gal-remove" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:3px;cursor:pointer;line-height:1">&times;</button>
				</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="button" id="m24_galerie_add">Bilder hinzufügen</button>
		<script>
		// jQuery(function($){...}) = Short-Hand fuer ready(). WP enqueued jquery-ui-sortable
		// per Default als Footer-Script (in_footer=1) — inline-Script im Body wuerde sonst
		// VOR sortable laufen und der .sortable()-Call wuerde silent failen.
		jQuery( function ( $ ) {
			var sortableAvailable = ( typeof $.fn.sortable === 'function' );
			console.log( '[m24 gallery] init — sortable available:', sortableAvailable );
			var frame;
			function ids() { return $( '#m24_galerie_ids' ).val().split( ',' ).filter( Boolean ); }
			function setIds( arr ) { $( '#m24_galerie_ids' ).val( arr.join( ',' ) ); }
			$( '#m24_galerie_add' ).on( 'click', function ( e ) {
				e.preventDefault();
				frame = wp.media( { title: 'Bilder auswählen', multiple: true, library: { type: 'image' } } );
				frame.on( 'select', function () {
					var sel = frame.state().get( 'selection' ).toJSON();
					var cur = ids();
					sel.forEach( function ( a ) {
						if ( cur.indexOf( String( a.id ) ) === -1 ) {
							cur.push( String( a.id ) );
							// „medium" bevorzugt (scharf auf 80×60), srcset = 2x für HiDPI; kein Upscaling.
							var sz = a.sizes || {};
							var url = ( sz.medium && sz.medium.url ) ? sz.medium.url : ( ( sz.large && sz.large.url ) ? sz.large.url : a.url );
							var cand = function ( k ) { return ( sz[k] && sz[k].url && sz[k].width ) ? ( sz[k].url + ' ' + sz[k].width + 'w' ) : ''; };
							var srcset = [ cand( 'thumbnail' ), cand( 'medium' ), cand( 'large' ) ].filter( Boolean ).join( ', ' );
							var imgAttr = 'src="' + url + '"' + ( srcset ? ( ' srcset="' + srcset + '" sizes="80px"' ) : '' );
							$( '#m24_galerie_preview' ).append(
								'<div class="m24-gal-item" data-id="' + a.id + '" style="position:relative;width:80px;height:60px;border:1px solid #ddd;border-radius:5px;overflow:hidden">' +
								'<img ' + imgAttr + ' style="width:100%;height:100%;object-fit:cover">' +
								'<button type="button" class="m24-gal-remove" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:3px;cursor:pointer;line-height:1">&times;</button></div>'
							);
						}
					} );
					setIds( cur );
				} );
				frame.open();
			} );
			$( '#m24_galerie_preview' ).on( 'click', '.m24-gal-remove', function () {
				var item = $( this ).closest( '.m24-gal-item' );
				var id = String( item.data( 'id' ) );
				setIds( ids().filter( function ( x ) { return x !== id; } ) );
				item.remove();
			} );
			// Paket F: Drag-&-Drop-Sortable. Beim Drop wird die Reihenfolge der
			// data-id-Attribute neu eingelesen und in den Hidden-Input geschrieben.
			if ( sortableAvailable ) {
				$( '#m24_galerie_preview' ).sortable( {
					items: '> .m24-gal-item',
					cursor: 'grabbing',
					placeholder: 'm24-gal-placeholder',
					forcePlaceholderSize: true,
					tolerance: 'pointer',
					cancel: '.m24-gal-remove',
					update: function () {
						var arr = [];
						$( '#m24_galerie_preview > .m24-gal-item' ).each( function () {
							arr.push( String( $( this ).data( 'id' ) ) );
						} );
						setIds( arr );
						console.log( '[m24 gallery] reorder →', arr.join( ',' ) );
					}
				} );
				console.log( '[m24 gallery] sortable bound on #m24_galerie_preview' );
			} else {
				console.warn( '[m24 gallery] sortable NOT bound — jquery-ui-sortable nicht verfuegbar' );
			}
		} );
		</script>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), 'm24_gal_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['m24_galerie'] ) ? wp_unslash( $_POST['m24_galerie'] ) : '';
		update_post_meta( $post_id, '_m24_galerie', M24_Catalog_CPT::sanitize_id_list( $raw ) );
	}
}
