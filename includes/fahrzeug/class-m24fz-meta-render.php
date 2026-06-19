<?php
/**
 * M24 Fahrzeug — Backend-UI der Meta-Box (Felder, Galerie-Uploads je Kategorie, Repeater)
 * Modul: includes/fahrzeug/class-m24fz-meta-render.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Meta_Render {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}
	public static function assets( $hook ) {
		$s = get_current_screen();
		if ( ! $s || M24FZ_CPT::PT !== $s->post_type ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
	}

	private static function g( $id, $k, $d = '' ) { $v = get_post_meta( $id, $k, true ); return '' === $v ? $d : $v; }
	private static function row( $id, $key, $label, $type = 'text', $ph = '' ) {
		printf(
			'<p style="margin:8px 0"><label style="display:block;font-weight:600;font-size:12px;color:#50575e">%s</label><input type="%s" name="%s" value="%s" placeholder="%s" class="widefat"></p>',
			esc_html( $label ), esc_attr( $type ), esc_attr( $key ), esc_attr( self::g( $id, $key ) ), esc_attr( $ph )
		);
	}

	public static function box( $post ) {
		$id = (int) $post->ID;
		wp_nonce_field( M24FZ_Meta::NONCE, M24FZ_Meta::NONCE );
		$typ    = self::g( $id, '_m24fz_template_typ', 'strasse' );
		$status = M24FZ_CPT::status( $id );
		$kat    = self::g( $id, '_m24fz_kat', 'race-cars' );
		$paf    = (int) self::g( $id, '_m24fz_preis_auf_anfrage' );
		?>
		<style>.m24fz-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}.m24fz-sec{border-top:1px solid #e0e0e0;margin-top:14px;padding-top:10px}.m24fz-sec h4{margin:0 0 6px}.m24fz-gal{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}.m24fz-gal img{width:62px;height:42px;object-fit:cover;border-radius:4px;cursor:move;border:1px solid #ccc}.m24fz-gal .rm{position:absolute;top:-6px;right:-6px;background:#a00;color:#fff;border-radius:50%;width:16px;height:16px;line-height:14px;text-align:center;font-size:11px;cursor:pointer}.m24fz-gal span{position:relative;display:inline-block}</style>

		<div class="m24fz-grid">
			<p><label style="font-weight:600;font-size:12px;color:#50575e;display:block">Template-Typ</label>
				<label><input type="radio" name="_m24fz_template_typ" value="strasse" <?php checked( $typ, 'strasse' ); ?>> Straßenfahrzeug</label>
				<label style="margin-left:10px"><input type="radio" name="_m24fz_template_typ" value="renn" <?php checked( $typ, 'renn' ); ?>> Rennfahrzeug</label></p>
			<p><label style="font-weight:600;font-size:12px;color:#50575e;display:block">Aktiv-Kategorie</label>
				<select name="_m24fz_kat" class="widefat"><option value="race-cars" <?php selected( $kat, 'race-cars' ); ?>>Race Cars</option><option value="classic-cars" <?php selected( $kat, 'classic-cars' ); ?>>Classic Cars</option></select></p>
		</div>

		<div class="m24fz-sec"><h4>Status &amp; Preis</h4>
			<p>
				<label><input type="checkbox" name="m24fz_verkauft" <?php checked( 'verkauft', $status ); ?>> Verkauft</label>
				<label style="margin-left:14px"><input type="checkbox" name="m24fz_reserviert" <?php checked( 'reserviert', $status ); ?>> Reserviert</label>
				<label style="margin-left:14px;color:#a00"><input type="checkbox" name="m24fz_deaktiviert" <?php checked( 'deaktiviert', $status ); ?>> Deaktivieren (Frontend weg)</label>
			</p>
			<div class="m24fz-grid">
				<?php self::row( $id, '_m24fz_preis', 'Preis (EUR)', 'text', 'z. B. 189000' ); ?>
				<p style="margin:8px 0"><label style="display:block;font-weight:600;font-size:12px;color:#50575e">&nbsp;</label><label><input type="checkbox" name="_m24fz_preis_auf_anfrage" <?php checked( 1, $paf ); ?>> Preis auf Anfrage</label></p>
			</div>
		</div>

		<div class="m24fz-sec"><h4>Überblick</h4>
			<label style="font-weight:600;font-size:12px;color:#50575e">Keyfacts (3–5 Highlights)</label>
			<div id="m24fz-keyfacts"><?php foreach ( array_pad( (array) get_post_meta( $id, '_m24fz_keyfacts', true ), 3, '' ) as $kf ) : ?>
				<p><input type="text" name="_m24fz_keyfacts[]" value="<?php echo esc_attr( $kf ); ?>" class="widefat"></p>
			<?php endforeach; ?></div>
			<button type="button" class="button" id="m24fz-kf-add">+ Keyfact</button>
			<p style="margin-top:10px"><label style="font-weight:600;font-size:12px;color:#50575e">Zusammenfassung</label><textarea name="_m24fz_zusammenfassung" rows="2" class="widefat"><?php echo esc_textarea( self::g( $id, '_m24fz_zusammenfassung' ) ); ?></textarea></p>
			<p><label style="font-weight:600;font-size:12px;color:#50575e">Fahrzeugbeschreibung</label><textarea name="_m24fz_beschreibung" rows="6" class="widefat"><?php echo esc_textarea( self::g( $id, '_m24fz_beschreibung' ) ); ?></textarea></p>
		</div>

		<div class="m24fz-sec"><h4>Telemetrie</h4>
			<div class="m24fz-grid">
				<?php
				self::row( $id, '_m24fz_baujahr', 'Baujahr' );
				self::row( $id, '_m24fz_laufleistung', 'Laufleistung (km, nur Straße)' );
				self::row( $id, '_m24fz_leistung_ps', 'Leistung (PS)' );
				?>
				<p style="margin:8px 0"><label style="display:block;font-weight:600;font-size:12px;color:#50575e">Getriebe</label>
					<select name="_m24fz_getriebe" class="widefat"><?php foreach ( M24FZ_Telemetry::getriebe_options() as $v => $l ) { printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( self::g( $id, '_m24fz_getriebe' ), $v, false ), esc_html( $l ) ); } ?></select></p>
				<?php
				self::row( $id, '_m24fz_farbe', 'Farbe (nur Straße)' );
				self::row( $id, '_m24fz_tel_opt_label', 'Option-Label (Straße)' );
				self::row( $id, '_m24fz_tel_opt_value', 'Option-Wert (Straße)' );
				?>
			</div>
			<p><label><input type="checkbox" name="_m24fz_wagenpass" <?php checked( 1, (int) self::g( $id, '_m24fz_wagenpass' ) ); ?>> Wagenpass (Renn)</label>
			   <label style="margin-left:14px"><input type="checkbox" name="_m24fz_rennhistorie" <?php checked( 1, (int) self::g( $id, '_m24fz_rennhistorie' ) ); ?>> Rennhistorie (Renn)</label></p>
			<div class="m24fz-grid"><?php for ( $i = 1; $i <= 3; $i++ ) { self::row( $id, "_m24fz_race_opt{$i}_label", "Renn-Option $i Label" ); self::row( $id, "_m24fz_race_opt{$i}_value", "Renn-Option $i Wert" ); } ?></div>
		</div>

		<div class="m24fz-sec"><h4>Mediagalerie (je Kategorie sortierbar)</h4>
			<?php foreach ( array( '_m24fz_gal_aussen' => 'Außen', '_m24fz_gal_innen' => 'Innen', '_m24fz_gal_motor' => 'Motor', '_m24fz_gal_unterboden' => 'Unterboden' ) as $key => $label ) :
				$ids = (array) get_post_meta( $id, $key, true ); ?>
				<div style="margin:8px 0" data-galkey="<?php echo esc_attr( $key ); ?>">
					<strong><?php echo esc_html( $label ); ?></strong>
					<div class="m24fz-gal"><?php foreach ( $ids as $aid ) : $u = wp_get_attachment_image_url( $aid, 'thumbnail' ); if ( ! $u ) { continue; } ?>
						<span data-id="<?php echo (int) $aid; ?>"><img src="<?php echo esc_url( $u ); ?>" alt=""><i class="rm">×</i></span>
					<?php endforeach; ?></div>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $ids ) ) ); ?>">
					<button type="button" class="button m24fz-gal-add">Bilder wählen</button>
				</div>
			<?php endforeach; ?>
			<p style="margin-top:8px"><label style="font-weight:600;font-size:12px;color:#50575e">YouTube-Videos</label></p>
			<div id="m24fz-videos"><?php foreach ( array_pad( (array) get_post_meta( $id, '_m24fz_videos', true ), 1, '' ) as $v ) : ?>
				<p><input type="url" name="_m24fz_videos[]" value="<?php echo esc_attr( $v ); ?>" placeholder="https://youtu.be/…" class="widefat"></p>
			<?php endforeach; ?></div>
			<button type="button" class="button" id="m24fz-vid-add">+ Video</button>
		</div>

		<div class="m24fz-sec"><h4>Fahrzeugdaten</h4>
			<div class="m24fz-grid">
				<?php
				self::row( $id, '_m24fz_marke', 'Marke (für „Ähnliche")' );
				foreach ( array(
					'_m24fz_erstzulassung' => 'Erstzulassung', '_m24fz_modell' => 'Modell', '_m24fz_fin' => 'FIN',
					'_m24fz_karosserie' => 'Karosserie', '_m24fz_baureihe' => 'Baureihe', '_m24fz_hubraum' => 'Hubraum',
					'_m24fz_lenkung' => 'Lenkung', '_m24fz_antrieb' => 'Antrieb', '_m24fz_kraftstoff' => 'Kraftstoff',
					'_m24fz_innenmaterial' => 'Innenmaterial', '_m24fz_innenfarbe' => 'Innenfarbe', '_m24fz_aussenfarbe' => 'Außenfarbe',
					'_m24fz_farbbez_hersteller' => 'Farbbez. Hersteller', '_m24fz_neu_gebraucht' => 'Neu/Gebraucht',
				) as $k => $l ) { self::row( $id, $k, $l ); }
				$countries = M24FZ_Telemetry::countries();
				foreach ( array( '_m24fz_land_erstauslieferung' => 'Land Erstauslieferung', '_m24fz_standort' => 'Standort (Land)' ) as $k => $l ) {
					printf( '<p style="margin:8px 0"><label style="display:block;font-weight:600;font-size:12px;color:#50575e">%s</label><select name="%s" class="widefat"><option value="">—</option>', esc_html( $l ), esc_attr( $k ) );
					foreach ( $countries as $cc => $cn ) { printf( '<option value="%s"%s>%s %s</option>', esc_attr( $cc ), selected( self::g( $id, $k ), $cc, false ), esc_html( M24FZ_Telemetry::flag( $cc ) ), esc_html( $cn ) ); }
					echo '</select></p>';
				}
				self::row( $id, '_m24fz_standort_ort', 'Standort (Ort)' );
				?>
			</div>
		</div>

		<div class="m24fz-sec"><h4>Tracking (editierbar)</h4>
			<div class="m24fz-grid"><?php foreach ( array( '_m24fz_views' => 'Aufrufe', '_m24fz_merkliste_count' => 'In Merkliste', '_m24fz_anfragen_count' => 'Anfragen', '_m24fz_tel_klicks' => 'Tel-Klicks' ) as $k => $l ) { self::row( $id, $k, $l ); } ?></div>
		</div>

		<script>
		jQuery(function($){
			$('#m24fz-kf-add').on('click',function(){ $('#m24fz-keyfacts').append('<p><input type="text" name="_m24fz_keyfacts[]" class="widefat"></p>'); });
			$('#m24fz-vid-add').on('click',function(){ $('#m24fz-videos').append('<p><input type="url" name="_m24fz_videos[]" placeholder="https://youtu.be/…" class="widefat"></p>'); });
			$('.m24fz-gal').sortable({ update:function(){ syncGal($(this).closest('[data-galkey]')); } });
			$(document).on('click','.m24fz-gal .rm',function(){ var box=$(this).closest('[data-galkey]'); $(this).closest('span').remove(); syncGal(box); });
			function syncGal(box){ var ids=[]; box.find('.m24fz-gal span').each(function(){ ids.push($(this).data('id')); }); box.find('input[type=hidden]').val(ids.join(',')); }
			$('.m24fz-gal-add').on('click',function(){
				var box=$(this).closest('[data-galkey]'), gal=box.find('.m24fz-gal');
				var fr=wp.media({title:'Bilder wählen',multiple:true,library:{type:'image'}});
				fr.on('select',function(){ fr.state().get('selection').each(function(a){ a=a.toJSON(); var u=(a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url; gal.append('<span data-id="'+a.id+'"><img src="'+u+'" alt=""><i class="rm">×</i></span>'); }); syncGal(box); });
				fr.open();
			});
		});
		</script>
		<?php
	}
}
