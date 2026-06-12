<?php
/**
 * M24 Plattform — Admin: Bewertungs-Karte konfigurieren
 * Modul: admin/class-m24-reviews-settings.php
 *
 * WP-Admin → M24 Plattform → „Bewertungen": Schnitt, Anzahl, Google-Link + bis zu 5
 * kurierte echte Bewertungen (Sterne/Zitat/Name/Ort). Speichert in Option
 * m24_reviews_settings (von M24_Reviews_Card gelesen).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Reviews_Settings {

	const PAGE_SLUG  = 'm24-plattform-reviews';
	const GROUP      = 'm24_reviews_group';
	const CAPABILITY = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'm24-plattform',
			__( 'Bewertungen', 'm24-plattform' ),
			__( 'Bewertungen', 'm24-plattform' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting( self::GROUP, M24_Reviews_Card::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => array(),
		) );
	}

	public static function sanitize( $input ) {
		$out = array(
			'avg'   => isset( $input['avg'] ) ? sanitize_text_field( $input['avg'] ) : '',
			'count' => isset( $input['count'] ) ? max( 0, (int) $input['count'] ) : 0,
			'url'   => isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '',
			'items' => array(),
		);
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array();
		foreach ( array_slice( $items, 0, M24_Reviews_Card::MAX_ITEMS ) as $it ) {
			$quote = isset( $it['quote'] ) ? sanitize_textarea_field( $it['quote'] ) : '';
			$out['items'][] = array(
				'stars' => isset( $it['stars'] ) ? max( 1, min( 5, (int) $it['stars'] ) ) : 5,
				'quote' => $quote,
				'name'  => isset( $it['name'] ) ? sanitize_text_field( $it['name'] ) : '',
				'ort'   => isset( $it['ort'] ) ? sanitize_text_field( $it['ort'] ) : '',
			);
		}
		return $out;
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		$cfg   = M24_Reviews_Card::config();
		$key   = M24_Reviews_Card::OPTION;
		$items = $cfg['items'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bewertungs-Karte', 'm24-plattform' ); ?></h1>
			<p><?php esc_html_e( 'Trust-Element rechts im Beschreibungsbereich der Teile-Detailseiten. Reine Anzeige (kein Schema). Pro Aufruf wird zufällig eine Bewertung gezeigt.', 'm24-plattform' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="m24rev-avg"><?php esc_html_e( 'Schnitt (z. B. 4.9)', 'm24-plattform' ); ?></label></th>
						<td><input type="text" id="m24rev-avg" name="<?php echo esc_attr( $key ); ?>[avg]" value="<?php echo esc_attr( $cfg['avg'] ); ?>" class="small-text" placeholder="4.9"></td>
					</tr>
					<tr>
						<th scope="row"><label for="m24rev-count"><?php esc_html_e( 'Anzahl Bewertungen', 'm24-plattform' ); ?></label></th>
						<td><input type="number" id="m24rev-count" name="<?php echo esc_attr( $key ); ?>[count]" value="<?php echo esc_attr( $cfg['count'] ); ?>" class="small-text" min="0"></td>
					</tr>
					<tr>
						<th scope="row"><label for="m24rev-url"><?php esc_html_e( 'Google-Bewertungen-Link', 'm24-plattform' ); ?></label></th>
						<td><input type="url" id="m24rev-url" name="<?php echo esc_attr( $key ); ?>[url]" value="<?php echo esc_attr( $cfg['url'] ); ?>" class="regular-text" placeholder="https://g.page/r/…"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Kurierte Bewertungen (bis zu 5)', 'm24-plattform' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Leeres Zitat = Slot wird ignoriert. Nur echte Bewertungen verwenden.', 'm24-plattform' ); ?></p>
				<?php for ( $i = 0; $i < M24_Reviews_Card::MAX_ITEMS; $i++ ) :
					$it    = isset( $items[ $i ] ) ? $items[ $i ] : array();
					$stars = isset( $it['stars'] ) ? (int) $it['stars'] : 5;
					$base  = $key . '[items][' . $i . ']';
					?>
					<fieldset style="border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;margin:0 0 12px;max-width:760px;">
						<legend style="padding:0 6px;color:#646970;"><?php echo esc_html( sprintf( __( 'Bewertung %d', 'm24-plattform' ), $i + 1 ) ); ?></legend>
						<p>
							<label><?php esc_html_e( 'Sterne', 'm24-plattform' ); ?>
								<select name="<?php echo esc_attr( $base ); ?>[stars]">
									<?php for ( $s = 5; $s >= 1; $s-- ) : ?>
										<option value="<?php echo (int) $s; ?>" <?php selected( $stars, $s ); ?>><?php echo (int) $s; ?></option>
									<?php endfor; ?>
								</select>
							</label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Name', 'm24-plattform' ); ?>
								<input type="text" name="<?php echo esc_attr( $base ); ?>[name]" value="<?php echo esc_attr( $it['name'] ?? '' ); ?>" placeholder="Max M.">
							</label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Ort', 'm24-plattform' ); ?>
								<input type="text" name="<?php echo esc_attr( $base ); ?>[ort]" value="<?php echo esc_attr( $it['ort'] ?? '' ); ?>" placeholder="München">
							</label>
						</p>
						<p>
							<textarea name="<?php echo esc_attr( $base ); ?>[quote]" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Kurzes Zitat …', 'm24-plattform' ); ?>"><?php echo esc_textarea( $it['quote'] ?? '' ); ?></textarea>
						</p>
					</fieldset>
				<?php endfor; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
