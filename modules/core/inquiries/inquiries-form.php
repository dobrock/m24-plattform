<?php
/**
 * M24 Plattform — Inquiries-Modul: Form-Renderer
 *
 * Schritt B1: Render-Komponente für das Anfrage-Formular.
 * Submit-Handler kommt in Schritt C.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries_Form {

    private static $initialized = false;
    private static $last_error  = null;
    private static $last_data   = [];

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_shortcode( 'm24_anfrage_form', [ __CLASS__, 'render_shortcode' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_submit' ] );

        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_form', 'Form-Modul geladen', [ 'version' => M24_PLATTFORM_VERSION ] );
        }
    }

    /**
     * Shortcode-Wrapper: [m24_anfrage_form source="cart"]
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'source'    => M24_Inquiries::SOURCE_CART,
            'demo'      => 'true',
        ], $atts, 'm24_anfrage_form' );

        ob_start();
        self::render( [
            'inquiry_source' => sanitize_key( $atts['source'] ),
            'demo_items'     => filter_var( $atts['demo'], FILTER_VALIDATE_BOOLEAN ),
        ] );
        return ob_get_clean();
    }

    /**
     * Render des Anfrage-Formulars.
     *
     * @param array $args  inquiry_source (string), demo_items (bool)
     */
    public static function render( $args = [] ) {
        $source = isset( $args['inquiry_source'] ) ? $args['inquiry_source'] : M24_Inquiries::SOURCE_CART;
        $demo   = ! empty( $args['demo_items'] );

        $valid_sources = M24_Inquiries::valid_sources();
        if ( ! in_array( $source, $valid_sources, true ) ) {
            $source = M24_Inquiries::SOURCE_CART;
        }

        // Items-Quelle in dieser Reihenfolge:
        // 1. POST['items_json'] (Sidebar-Submit oder anderer POST mit Items)
        // 2. demo_items() falls demo=true
        // 3. leeres Array
        $items = [];
        if ( isset( $_POST['items_json'] ) && '' !== trim( wp_unslash( $_POST['items_json'] ) ) ) {
            $decoded = json_decode( wp_unslash( $_POST['items_json'] ), true );
            if ( is_array( $decoded ) ) {
                $items = self::sanitize_items( $decoded );
            }
        }
        if ( empty( $items ) && $demo ) {
            $items = self::demo_items();
        }

        // source_meta aus dem POST übernehmen (Sidebar-Submit hat es mitgeschickt;
        // die Form unten echoed es weiter, damit handle_submit() es bei Schritt 3 sieht).
        // Bei Validation-Fehler-Re-Render ebenso erhalten (siehe unten im Echo-Loop).
        $source_meta_passthrough = '';
        if ( isset( $_POST['inquiry_source_meta'] ) ) {
            $raw_passthrough = (string) wp_unslash( $_POST['inquiry_source_meta'] );
            // Nur durchreichen wenn es valider JSON ist (Schutz vor Junk).
            $decoded_check = json_decode( $raw_passthrough, true );
            if ( is_array( $decoded_check ) ) {
                $source_meta_passthrough = $raw_passthrough;
            }
        }

        // Erfolgs-State: ?inquiry=success&id=NNN nach PRG-Redirect
        if ( isset( $_GET['inquiry'] ) && 'success' === $_GET['inquiry'] ) {
            self::render_success_state();
            return;
        }

        // Validation- oder Storage-Fehler aus dem Submit-Handler (gleicher Page-Load)
        $error_notice = '';
        if ( self::$last_error instanceof WP_Error ) {
            $error_notice = self::$last_error->get_error_message();
            // Echo-Daten ins Form übernehmen, damit User nicht alles neu tippen muss
            foreach ( [ 'vorname', 'nachname', 'email', 'tel', 'firma', 'anrede',
                        'strasse', 'plz', 'ort', 'land', 'uid', 'biz', 'notes' ] as $echo_key ) {
                if ( isset( self::$last_data[ $echo_key ] ) ) {
                    $_POST[ $echo_key ] = self::$last_data[ $echo_key ];
                }
            }
        }

        ?>
        <div class="m24-form-wrap" data-source="<?php echo esc_attr( $source ); ?>">

            <?php if ( $error_notice ) : ?>
                <div class="m24-form__notice m24-form__notice--error" role="alert">
                    <?php echo esc_html( $error_notice ); ?>
                </div>
            <?php endif; ?>

            <form class="m24-form" method="post" action="" novalidate>

                <input type="hidden" name="m24_form_submit" value="1">
                <input type="hidden" name="inquiry_source" value="<?php echo esc_attr( $source ); ?>">
                <input type="hidden" name="inquiry_source_meta" value="<?php echo esc_attr( $source_meta_passthrough ); ?>">
                <input type="hidden" name="items_json" value="<?php echo esc_attr( wp_json_encode( $items ) ); ?>">

                <fieldset class="m24-form__section m24-form__section--customer">
                    <legend class="m24-form__legend"><?php esc_html_e( 'Ihre Kontaktdaten', 'm24-plattform' ); ?></legend>

                    <div class="m24-form__field">
                        <label for="m24-biz-toggle"><?php esc_html_e( 'Anfrage als', 'm24-plattform' ); ?> <span class="m24-form__required">*</span></label>
                        <select name="biz" id="m24-biz-toggle" required>
                            <option value="" <?php self::is_selected( 'biz', '' ); ?>><?php esc_html_e( '— bitte wählen —', 'm24-plattform' ); ?></option>
                            <option value="0" <?php self::is_selected( 'biz', '0' ); ?>><?php esc_html_e( 'Privat', 'm24-plattform' ); ?></option>
                            <option value="1" <?php self::is_selected( 'biz', '1' ); ?>><?php esc_html_e( 'Geschäftlich', 'm24-plattform' ); ?></option>
                        </select>
                    </div>

                    <div class="m24-form__field" data-show-when-biz="true">
                        <label for="m24-firma"><?php esc_html_e( 'Firma', 'm24-plattform' ); ?></label>
                        <input type="text" name="firma" id="m24-firma" autocomplete="organization" value="<?php self::echo_field('firma'); ?>">
                    </div>

                    <div class="m24-form__field">
                        <label for="m24-anrede"><?php esc_html_e( 'Anrede', 'm24-plattform' ); ?></label>
                        <select name="anrede" id="m24-anrede">
                            <option value="" <?php self::is_selected('anrede', ''); ?>><?php esc_html_e( '— bitte wählen —', 'm24-plattform' ); ?></option>
                            <option value="herr" <?php self::is_selected('anrede', 'herr'); ?>><?php esc_html_e( 'Herr', 'm24-plattform' ); ?></option>
                            <option value="frau" <?php self::is_selected('anrede', 'frau'); ?>><?php esc_html_e( 'Frau', 'm24-plattform' ); ?></option>
                            <option value="divers" <?php self::is_selected('anrede', 'divers'); ?>><?php esc_html_e( 'Divers', 'm24-plattform' ); ?></option>
                        </select>
                    </div>

                    <div class="m24-form__row">
                        <div class="m24-form__field">
                            <label for="m24-vorname"><?php esc_html_e( 'Vorname', 'm24-plattform' ); ?></label>
                            <input type="text" name="vorname" id="m24-vorname" autocomplete="given-name" value="<?php self::echo_field('vorname'); ?>">
                        </div>
                        <div class="m24-form__field">
                            <label for="m24-nachname"><?php esc_html_e( 'Nachname', 'm24-plattform' ); ?></label>
                            <input type="text" name="nachname" id="m24-nachname" autocomplete="family-name" value="<?php self::echo_field('nachname'); ?>">
                        </div>
                    </div>

                    <div class="m24-form__field">
                        <label for="m24-email"><?php esc_html_e( 'E-Mail', 'm24-plattform' ); ?> <span class="m24-form__required">*</span></label>
                        <input type="email" name="email" id="m24-email" autocomplete="email" required value="<?php self::echo_field('email'); ?>">
                    </div>

                    <div class="m24-form__field">
                        <label for="m24-land"><?php esc_html_e( 'Land', 'm24-plattform' ); ?> <span class="m24-form__required">*</span></label>
                        <select name="land" id="m24-land" autocomplete="country" required>
                            <option value="DE" <?php self::is_selected('land', 'DE'); ?>>Deutschland</option>
                            <option value="AT" <?php self::is_selected('land', 'AT'); ?>>Österreich</option>
                            <option value="CH" <?php self::is_selected('land', 'CH'); ?>>Schweiz</option>
                            <option value="GB" <?php self::is_selected('land', 'GB'); ?>>Großbritannien</option>
                            <option value="FR" <?php self::is_selected('land', 'FR'); ?>>Frankreich</option>
                            <option value="IT" <?php self::is_selected('land', 'IT'); ?>>Italien</option>
                            <option value="NL" <?php self::is_selected('land', 'NL'); ?>>Niederlande</option>
                            <option value="BE" <?php self::is_selected('land', 'BE'); ?>>Belgien</option>
                            <option value="LU" <?php self::is_selected('land', 'LU'); ?>>Luxemburg</option>
                            <option value="ES" <?php self::is_selected('land', 'ES'); ?>>Spanien</option>
                            <option value="PL" <?php self::is_selected('land', 'PL'); ?>>Polen</option>
                            <option value="CZ" <?php self::is_selected('land', 'CZ'); ?>>Tschechien</option>
                            <option value="DK" <?php self::is_selected('land', 'DK'); ?>>Dänemark</option>
                            <option value="SE" <?php self::is_selected('land', 'SE'); ?>>Schweden</option>
                            <option value="US" <?php self::is_selected('land', 'US'); ?>>USA</option>
                        </select>
                    </div>

                    <div class="m24-form__field" data-show-when-biz="true">
                        <label for="m24-uid"><?php esc_html_e( 'USt-IdNr. (für EU-Firmen)', 'm24-plattform' ); ?></label>
                        <input type="text" name="uid" id="m24-uid" placeholder="z.B. DE123456789" value="<?php self::echo_field('uid'); ?>">
                    </div>
                </fieldset>

                <fieldset class="m24-form__section m24-form__section--items">
                    <legend class="m24-form__legend"><?php esc_html_e( 'Ihre Anfrage-Positionen', 'm24-plattform' ); ?></legend>

                    <?php if ( empty( $items ) ) : ?>
                        <p class="m24-form__hint">
                            <?php esc_html_e( 'Sie haben noch keine Positionen ausgewählt. Nutzen Sie die Sammelanfrage-Sidebar (folgt in Schritt B2), um Pakete oder Artikel hinzuzufügen.', 'm24-plattform' ); ?>
                        </p>
                    <?php else : ?>
                        <ul class="m24-form__items">
                            <?php foreach ( $items as $idx => $item ) : ?>
                                <li class="m24-form__item">
                                    <div class="m24-form__item-art">
                                        <strong><?php echo esc_html( $item['art'] ); ?></strong>
                                    </div>
                                    <div class="m24-form__item-meta">
                                        <span><?php echo esc_html( sprintf( __( 'Menge: %s', 'm24-plattform' ), $item['qty'] ) ); ?></span>
                                        <?php
                                        $item_price = trim( (string) $item['price'] );
                                        $show_price = M24_Inquiries::user_can_see_prices()
                                            || '' === $item_price
                                            || 0 === strcasecmp( $item_price, 'Auf Anfrage' );
                                        $price_display = $show_price ? $item_price : M24_Inquiries::price_login_placeholder();
                                        ?>
                                        <span><?php echo esc_html( sprintf( __( 'Preis: %s', 'm24-plattform' ), $price_display ) ); ?></span>
                                        <span class="m24-form__item-pillar m24-form__item-pillar--<?php echo esc_attr( $item['src_pillar'] ); ?>">
                                            <?php echo esc_html( $item['src_pillar'] ); ?>
                                        </span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </fieldset>

                <fieldset class="m24-form__section m24-form__section--notes">
                    <legend class="m24-form__legend"><?php esc_html_e( 'Notiz an uns', 'm24-plattform' ); ?></legend>
                    <div class="m24-form__field">
                        <textarea name="notes" id="m24-notes" rows="4" placeholder="<?php esc_attr_e( 'Optional: zusätzliche Informationen zu Ihrer Anfrage', 'm24-plattform' ); ?>"><?php echo esc_textarea( isset( $_POST['notes'] ) ? wp_unslash( (string) $_POST['notes'] ) : '' ); ?></textarea>
                    </div>
                </fieldset>

                <fieldset class="m24-form__section m24-form__section--consent">
                    <div class="m24-form__field m24-form__field--checkbox">
                        <label>
                            <input type="checkbox" name="dsgvo_consent" value="1" required>
                            <?php
                            printf(
                                /* translators: %s: link to privacy policy */
                                wp_kses(
                                    __( 'Ich willige ein, dass meine Angaben zur Beantwortung meiner Anfrage gespeichert und verarbeitet werden. Weitere Informationen in der %s.', 'm24-plattform' ),
                                    [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
                                ),
                                '<a href="/datenschutzerklaerung/">' . esc_html__( 'Datenschutzerklärung', 'm24-plattform' ) . '</a>'
                            );
                            ?>
                            <span class="m24-form__required">*</span>
                        </label>
                    </div>

                    <div class="m24-form__honeypot" aria-hidden="true">
                        <label for="m24-website-confirm"><?php esc_html_e( 'Website (bitte nicht ausfüllen)', 'm24-plattform' ); ?></label>
                        <input type="text" name="website_confirm" id="m24-website-confirm" tabindex="-1" autocomplete="off" value="">
                    </div>
                </fieldset>

                <div class="m24-form__actions">
                    <button type="submit" class="m24-form__submit">
                        <?php esc_html_e( 'Anfrage absenden', 'm24-plattform' ); ?>
                    </button>
                </div>

            </form>
        </div>
        <?php
    }

    /**
     * Validiert + sanitisiert ein Items-Array (z.B. aus POST['items_json']).
     * Erwartet pro Item die Keys: art, qty, price, src_url, src_pillar, src_modell,
     * src_pid, src_art_nr (Varianten-Art.-Nr.), src_variant (Varianten-Label).
     * Items mit leerem 'art' werden verworfen.
     *
     * @param array $raw
     * @return array
     */
    public static function sanitize_items( $raw ) {
        $valid_pillars = M24_Inquiries::valid_pillars();
        $clean = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $art = isset( $row['art'] ) ? sanitize_text_field( (string) $row['art'] ) : '';
            if ( '' === $art ) {
                continue;
            }
            $pillar = isset( $row['src_pillar'] ) ? sanitize_key( (string) $row['src_pillar'] ) : '';
            if ( ! in_array( $pillar, $valid_pillars, true ) ) {
                $pillar = M24_Inquiries::PILLAR_GEBRAUCHTTEILE;
            }
            $clean[] = [
                'art'         => $art,
                'qty'         => isset( $row['qty'] )         ? sanitize_text_field( (string) $row['qty'] )    : '1',
                'price'       => isset( $row['price'] )       ? sanitize_text_field( (string) $row['price'] )  : '',
                'src_url'     => isset( $row['src_url'] )     ? esc_url_raw( (string) $row['src_url'] )        : '',
                'src_pillar'  => $pillar,
                'src_modell'  => isset( $row['src_modell'] )  ? sanitize_text_field( (string) $row['src_modell'] )  : '',
                'src_pid'     => isset( $row['src_pid'] )     ? sanitize_text_field( (string) $row['src_pid'] )     : '',
                'src_art_nr'  => isset( $row['src_art_nr'] )  ? sanitize_text_field( (string) $row['src_art_nr'] )  : '',
                'src_variant' => isset( $row['src_variant'] ) ? sanitize_text_field( (string) $row['src_variant'] ) : '',
            ];
        }
        return $clean;
    }

    /**
     * Hardcoded Test-Items für Render-Verifikation in Schritt B1.
     * Werden in Schritt B2 durch Sidebar-Output ersetzt.
     */
    private static function demo_items() {
        return [
            [
                'art'        => 'BMW Triebwerk Benzin S65B40 — 420 PS / M3 E92',
                'qty'        => '1',
                'price'      => '12480.00 EUR',
                'src_url'    => 'https://www.motorsport24.de/bmw-m3-e92-gebrauchtteile/',
                'src_pillar' => M24_Inquiries::PILLAR_GEBRAUCHTTEILE,
                'src_modell' => 'm3-e92',
                'src_pid'    => 'P20240001',
            ],
            [
                'art'        => 'Bodykit DTM 1993 für M3 E30',
                'qty'        => '1',
                'price'      => 'Auf Anfrage',
                'src_url'    => 'https://www.motorsport24.de/rennsport-teile-passend-fuer-m3-e30/',
                'src_pillar' => M24_Inquiries::PILLAR_KATALOG,
                'src_modell' => 'm3-e30',
                'src_pid'    => 'M242812355',
            ],
        ];
    }

    /**
     * Echo-Helper für Text-Inputs: gibt esc_attr-escaped POST-Wert aus.
     */
    private static function echo_field( $key ) {
        $val = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
        echo esc_attr( $val );
    }

    /**
     * Echo-Helper für <select>: druckt ' selected' falls Option dem POST-Wert entspricht.
     * Spezialfall 'land': wenn POST leer, bleibt 'DE' default-selected.
     */
    private static function is_selected( $key, $value ) {
        $current = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
        if ( '' === $current && 'land' === $key && 'DE' === $value ) {
            echo ' selected';
            return;
        }
        if ( $current === $value ) {
            echo ' selected';
        }
    }

    /**
     * Echo-Helper für <input type="checkbox">: druckt ' checked' falls POST-Wert truthy.
     */
    private static function is_checked( $key ) {
        if ( ! empty( $_POST[ $key ] ) ) {
            echo ' checked';
        }
    }

    /**
     * Submit-Handler. Hängt an template_redirect.
     *
     * Bei Erfolg: PRG-Redirect mit ?inquiry=success&id=NNN.
     * Bei Validation-Fehler: $last_error/$last_data setzen, normaler Page-Render
     * zeigt dann die Fehler-Notice + Echo-Werte im Form.
     * Honeypot: silent — keinen Hinweis ans Frontend.
     */
    public static function handle_submit() {
        if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
            return;
        }
        if ( empty( $_POST['m24_form_submit'] ) ) {
            return;
        }

        $result = M24_Inquiries_Validation::validate( $_POST );

        if ( is_wp_error( $result ) ) {
            // Honeypot: silent drop, Bot bekommt nichts mit
            if ( 'm24_honeypot' === $result->get_error_code() ) {
                if ( class_exists( 'M24_Logger' ) ) {
                    M24_Logger::warning( 'inquiries_form', 'Honeypot triggered, silent drop' );
                }
                return;
            }
            // Andere Fehler: Render-Pfad bekommt Error + Echo-Daten
            self::$last_error = $result;
            self::$last_data  = wp_unslash( $_POST );
            return;
        }

        $post_id = M24_Inquiries_Storage::insert_inquiry( $result );

        if ( is_wp_error( $post_id ) ) {
            self::$last_error = $post_id;
            self::$last_data  = wp_unslash( $_POST );
            return;
        }

        // PRG-Pattern: Redirect auf gleiche URL mit ?inquiry=success&id=NNN
        $base = isset( $_SERVER['REQUEST_URI'] ) ? home_url( $_SERVER['REQUEST_URI'] ) : home_url( '/' );
        // Vorhandene Query-Parameter (außer unsere) nicht durchschleppen
        $base = strtok( $base, '?' );
        $redirect = add_query_arg(
            [
                'inquiry' => 'success',
                'id'      => $post_id,
            ],
            $base
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Erfolgs-State nach PRG-Redirect.
     *
     * Rendert grünen Notice-Block + JS-Snippet, das den Sidebar-LocalStorage clear't.
     */
    private static function render_success_state() {
        $inquiry_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        ?>
        <div class="m24-form-wrap m24-form-wrap--success">
            <div class="m24-form__notice m24-form__notice--success" role="status">
                <h3><?php esc_html_e( 'Vielen Dank — Ihre Sammelanfrage ist bei uns eingegangen.', 'm24-plattform' ); ?></h3>
                <p><?php esc_html_e( 'Wir melden uns in Kürze bei Ihnen.', 'm24-plattform' ); ?></p>
                <?php if ( $inquiry_id ) : ?>
                    <p class="m24-form__ref">
                        <?php echo esc_html( sprintf( __( 'Vorgangs-Nr.: #%d', 'm24-plattform' ), $inquiry_id ) ); ?>
                    </p>
                <?php endif; ?>
            </div>
            <script>
                try { localStorage.removeItem('m24_sidebar_items'); } catch(e) {}
                try { localStorage.removeItem('m24_sidebar_session_id'); } catch(e) {}
                document.addEventListener('DOMContentLoaded', function() {
                    var b = document.querySelector('[data-m24-count]');
                    if (b) b.textContent = '0';
                    var s = document.querySelector('[data-m24-action="submit"]');
                    if (s) s.disabled = true;
                });
            </script>
        </div>
        <?php
    }
}
