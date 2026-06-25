<?php
/**
 * M24 Plattform — B2B/Händler-Registrierung + Magic-Link-Login + Confirm (Garage Phase A, Chunk 2).
 *
 * Passwortlos: Registrierung legt einen pending-Händler an und schickt einen Bestätigungslink;
 * Login schickt einen Magic-Link. Beide Tokens 15 Min, Einmal-Nutzung (M24_B2B). Confirm-Klick
 * verifiziert (verify) bzw. loggt ein (login) und setzt den Auth-Cookie.
 *
 * Pflicht-Schutzmaßnahmen:
 *   - Anti-Enumeration: Registrierung/Login antworten IMMER mit derselben Erfolgsmeldung.
 *   - Consent-Snapshot: AGB + Datenschutz als usermeta _m24_consent (mit ts, ip_hash, URLs).
 *   - Nonce + Honeypot + IP-Rate-Limit auf beiden Formularen.
 *   - Seiten noindex + WP-Rocket-Cache-Ausschluss; keine rohen Tokens/IPs geloggt.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_B2B_Auth {

    const AGB_PATH = '/agb/';
    const DS_PATH  = '/datenschutzerklaerung/';

    const OPT_REG_PAGE   = 'm24_haendler_reg_page';
    const OPT_REG2_PAGE  = 'm24_haendler_reg2_page';
    const OPT_LOGIN_PAGE = 'm24_haendler_login_page';

    const RL_MAX    = 5;                    // max Versuche
    const RL_WINDOW = 10 * MINUTE_IN_SECONDS; // je 10 Min / gehashter IP

    /** EU-ISO2 — UID ist in diesen Ländern Pflicht. */
    const EU = array( 'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE' );

    public static function init() {
        add_shortcode( 'm24_haendler_registrierung', array( __CLASS__, 'render_registration_form' ) );
        add_shortcode( 'm24_haendler_registrierung_2', array( __CLASS__, 'render_registration_form_v2' ) );
        add_shortcode( 'm24_haendler_login', array( __CLASS__, 'render_login_form' ) );

        add_action( 'admin_post_nopriv_m24_haendler_register', array( __CLASS__, 'handle_register' ) );
        add_action( 'admin_post_m24_haendler_register', array( __CLASS__, 'handle_register' ) );
        add_action( 'admin_post_nopriv_m24_haendler_login', array( __CLASS__, 'handle_login' ) );
        add_action( 'admin_post_m24_haendler_login', array( __CLASS__, 'handle_login' ) );

        add_action( 'wp_ajax_nopriv_m24_vies', array( __CLASS__, 'ajax_vies' ) );
        add_action( 'wp_ajax_m24_vies', array( __CLASS__, 'ajax_vies' ) );

        add_action( 'template_redirect', array( __CLASS__, 'confirm_intercept' ), 1 );
        add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
        add_action( 'admin_init', array( __CLASS__, 'ensure_pages' ) );

        // noindex + Cache-Ausschluss.
        add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
        add_filter( 'rocket_cache_reject_uri', array( __CLASS__, 'rocket_reject' ) );
    }

    /* ── Seiten/URLs ─────────────────────────────────────────────────────── */

    private static function reg_page_url(): string {
        $id = (int) get_option( self::OPT_REG_PAGE, 0 );
        return $id ? (string) get_permalink( $id ) : home_url( '/haendler-registrierung/' );
    }

    private static function reg2_page_url(): string {
        $id = (int) get_option( self::OPT_REG2_PAGE, 0 );
        return $id ? (string) get_permalink( $id ) : home_url( '/haendler-registrierung-2/' );
    }

    private static function login_page_url(): string {
        $id = (int) get_option( self::OPT_LOGIN_PAGE, 0 );
        return $id ? (string) get_permalink( $id ) : home_url( '/haendler-login/' );
    }

    /** IDs aller Händler-Seiten (reg, reg2, login) — eine Quelle für noindex/Cache. */
    private static function page_ids(): array {
        return array_filter( array(
            (int) get_option( self::OPT_REG_PAGE, 0 ),
            (int) get_option( self::OPT_REG2_PAGE, 0 ),
            (int) get_option( self::OPT_LOGIN_PAGE, 0 ),
        ) );
    }

    public static function ensure_pages() {
        $defs = array(
            self::OPT_REG_PAGE   => array( 'Händler-Registrierung', 'haendler-registrierung', '[m24_haendler_registrierung]' ),
            self::OPT_REG2_PAGE  => array( 'Händler-Registrierung (Variante)', 'haendler-registrierung-2', '[m24_haendler_registrierung_2]' ),
            self::OPT_LOGIN_PAGE => array( 'Händler-Login', 'haendler-login', '[m24_haendler_login]' ),
        );
        foreach ( $defs as $opt => $d ) {
            $id = (int) get_option( $opt, 0 );
            if ( ! ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ) {
                $new = wp_insert_post( array(
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_title'   => $d[0],
                    'post_name'    => $d[1],
                    'post_content' => $d[2],
                ) );
                $id = ( $new && ! is_wp_error( $new ) ) ? (int) $new : 0;
                if ( $id ) {
                    update_option( $opt, $id );
                }
            }
            // wpSEO rendert eigenes robots-Meta und übergeht wp_robots → hier hart auf
            // „noindex, follow" (wpSEO-Code 4) setzen. Idempotent: korrigiert auch bereits
            // angelegte Seiten beim nächsten admin_init.
            if ( $id ) {
                update_post_meta( $id, '_wpseo_edit_robots', '4' );
            }
        }
    }

    public static function robots( $robots ) {
        $ids = self::page_ids();
        if ( $ids && is_page( $ids ) ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
            unset( $robots['index'] );
        }
        return $robots;
    }

    public static function rocket_reject( $uris ) {
        $uris[] = '/haendler-registrierung/(.*)';
        $uris[] = '/haendler-registrierung-2/(.*)';
        $uris[] = '/haendler-login/(.*)';
        return $uris;
    }

    /**
     * Cache-Write der Händler-Seiten zuverlässig verhindern — DONOTCACHEPAGE greift sofort,
     * ohne WP-Rocket-Config-Regeneration (rocket_cache_reject_uri allein griff unzuverlässig).
     */
    public static function no_cache() {
        $ids = self::page_ids();
        if ( $ids && is_page( $ids ) && ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
    }

    /* ── Helfer ──────────────────────────────────────────────────────────── */

    private static function ip_hash(): string {
        return hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'auth' ) );
    }

    private static function rate_ok(): bool {
        $key = 'm24_b2b_rl_' . self::ip_hash();
        $n   = (int) get_transient( $key );
        if ( $n >= self::RL_MAX ) {
            return false;
        }
        set_transient( $key, $n + 1, self::RL_WINDOW );
        return true;
    }

    private static function is_eu( string $land ): bool {
        return in_array( strtoupper( $land ), self::EU, true );
    }

    /* ── VIES (EU-USt-IdNr.-Live-Prüfung) ────────────────────────────────── */

    /**
     * Prüft eine EU-USt-IdNr. live gegen den offiziellen VIES-REST-Service. FAIL-SAFE:
     * jeder Ausfall (Timeout, MS unavailable, Nicht-200) → checked=false/valid=null → blockt NIE.
     *
     * @return array{checked:bool,valid:?bool,name:?string}
     */
    public static function vies_check( string $uid ): array {
        $uid  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $uid ) ); // Leerzeichen/Punkte raus
        $ms   = substr( $uid, 0, 2 );
        $num  = substr( $uid, 2 );
        $fail = array( 'checked' => false, 'valid' => null, 'name' => null );

        if ( ! in_array( $ms, self::EU, true ) || '' === $num ) {
            return $fail;
        }

        $url = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' . rawurlencode( $ms ) . '/vat/' . rawurlencode( $num );
        $res = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
            return $fail;
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $data ) ) {
            return $fail;
        }

        // VIES-REST liefert `valid` (bool); defensiv auch `isValid` akzeptieren.
        $valid = array_key_exists( 'valid', $data ) ? $data['valid'] : ( $data['isValid'] ?? null );
        if ( true === $valid ) {
            $name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
            // VIES gibt bei nicht offengelegtem Namen „---" zurück.
            $name = ( '' !== $name && '---' !== $name ) ? $name : null;
            return array( 'checked' => true, 'valid' => true, 'name' => $name );
        }
        if ( false === $valid ) {
            return array( 'checked' => true, 'valid' => false, 'name' => null );
        }
        return $fail; // MS unavailable / unklar
    }

    public static function ajax_vies() {
        check_ajax_referer( 'm24_vies' );
        $uid = sanitize_text_field( wp_unslash( $_POST['uid'] ?? '' ) );
        wp_send_json( self::vies_check( $uid ) );
    }

    /** WP-Locale aus dem Land (DE/AT → de_DE, sonst de_DE als Default-Sprache der Plattform). */
    private static function locale_for( string $land ): string {
        return in_array( strtoupper( $land ), array( 'DE', 'AT', 'CH', 'LI' ), true ) ? 'de_DE' : 'de_DE';
    }

    private static function lang_for( string $land ): string {
        return in_array( strtoupper( $land ), array( 'DE', 'AT', 'CH', 'LI' ), true ) ? 'de' : 'en';
    }

    /** Auswahl-Länder ISO-2 ⇒ deutscher Klarname. DE zuerst, Rest alphabetisch nach Name. Filterbar. */
    private static function countries(): array {
        $names = array(
            'AT' => 'Österreich', 'BE' => 'Belgien', 'BG' => 'Bulgarien', 'CY' => 'Zypern',
            'CZ' => 'Tschechien', 'DK' => 'Dänemark', 'EE' => 'Estland', 'FI' => 'Finnland',
            'FR' => 'Frankreich', 'GB' => 'Vereinigtes Königreich', 'GR' => 'Griechenland',
            'HR' => 'Kroatien', 'HU' => 'Ungarn', 'IE' => 'Irland', 'IT' => 'Italien',
            'LI' => 'Liechtenstein', 'LT' => 'Litauen', 'LU' => 'Luxemburg', 'LV' => 'Lettland',
            'MT' => 'Malta', 'NL' => 'Niederlande', 'NO' => 'Norwegen', 'PL' => 'Polen',
            'PT' => 'Portugal', 'RO' => 'Rumänien', 'SE' => 'Schweden', 'CH' => 'Schweiz',
            'SK' => 'Slowakei', 'SI' => 'Slowenien', 'ES' => 'Spanien', 'US' => 'USA',
        );
        asort( $names ); // alphabetisch nach Klarname
        $out = array( 'DE' => 'Deutschland' ) + $names; // DE zuerst (selected)
        return (array) apply_filters( 'm24_b2b_countries', $out );
    }

    private static function user_is_haendler( $user ): bool {
        return $user && in_array( M24_B2B::ROLE, (array) $user->roles, true );
    }

    /* ── Formulare ───────────────────────────────────────────────────────── */

    private static function form_css(): string {
        return '<style>'
            . '.td-pb-row .td-pb-span4{display:none!important}'
            . '.td-pb-row .td-pb-span8.td-main-content{width:100%!important;float:none!important}'
            . '.m24b2b{max-width:640px;margin:24px auto;font-family:\'Saira\',Arial,sans-serif;color:#14161a}'
            . '.m24b2b-card{position:relative;background:#fff;border:1px solid #e6e9ee;border-radius:14px;padding:26px 26px 30px;box-shadow:0 1px 3px rgba(20,22,26,.06)}'
            . '.m24b2b-lang{position:absolute;top:18px;right:20px;display:flex;gap:10px;align-items:center}'
            . '.m24-flag{display:inline-flex;line-height:0;opacity:.45;border-bottom:2px solid transparent;padding-bottom:2px;transition:opacity .15s}'
            . '.m24-flag:hover{opacity:.8}'
            . '.m24-flag.active{opacity:1;border-bottom-color:#9a6b25}'
            . '.m24-flag svg{border-radius:2px}'
            . '.m24b2b h2{font-size:24px;margin:0 0 6px;color:#10243a}'
            . '.m24b2b .sub{font-size:14px;color:#5a6474;margin:0 0 18px}'
            . '.m24b2b label{display:block;font-size:13px;font-weight:600;color:#3a414c;margin:12px 0 4px}'
            . '.m24b2b input[type=text],.m24b2b input[type=email],.m24b2b input[type=tel],.m24b2b select{width:100%;font-size:16px;padding:11px 12px;border:1px solid #d6dae0;border-radius:8px;background:#fff;box-sizing:border-box;font-family:inherit}'
            . '.m24b2b .row{display:flex;gap:12px;flex-wrap:wrap}.m24b2b .row>div{flex:1 1 200px;min-width:0}'
            . '.m24b2b .chk{display:flex;gap:9px;align-items:flex-start;margin:14px 0 0;font-size:13px;font-weight:400;color:#3a414c}'
            . '.m24b2b .chk input{margin-top:3px}'
            . '.m24b2b .req{color:#9a6b25}'
            . '.m24b2b .hp{position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden}'
            . '.m24b2b-btn{margin-top:20px;width:100%;background:#1f74c4;color:#fff;border:0;border-radius:8px;padding:14px 16px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;background-image:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%)}'
            . '.m24b2b-note{background:#edf3fb;border:1px solid #cfe0f4;color:#10243a;border-radius:10px;padding:16px 18px;font-size:14.5px;line-height:1.5}'
            . '.m24b2b-err{background:#fdf1f3;border:1px solid #f0c4cc;color:#9a2530;border-radius:8px;padding:10px 14px;font-size:13.5px;margin:0 0 16px}'
            . '.m24b2b-ok{font-size:30px;font-weight:800;color:#9a6b25;margin:0 0 8px}'
            . '.m24-uid-fb{font-size:12px;margin-top:5px;min-height:16px}'
            . '.m24-uid-fb.checking{color:#5a6474}.m24-uid-fb.ok{color:#1a7a3c;font-weight:600}.m24-uid-fb.bad{color:#c8102e;font-weight:600}.m24-uid-fb.neutral{color:#9aa3b0}'
            // Variante 2 — Anfrageformular-Optik: große Felder, Label als Placeholder INNEN.
            . '.m24b2b-v2 .m24f{margin:0 0 12px}'
            . '.m24b2b-v2 input[type=text],.m24b2b-v2 input[type=email],.m24b2b-v2 input[type=tel],.m24b2b-v2 select{font-size:17px;padding:16px 18px;border-radius:12px}'
            . '.m24b2b-v2 select{color:#14161a;background:#fff}'
            . '.m24b2b-v2 .m24-uid-fb{margin:-6px 0 12px}'
            . '</style>';
    }

    public static function render_registration_form(): string {
        $lg   = M24_I18n::resolve_lang();
        $t    = static function ( $k ) use ( $lg ) { return M24_I18n::t( $k, $lg ); };
        $sent = isset( $_GET['gesendet'] ); // phpcs:ignore WordPress.Security.NonceVerification
        ob_start();
        echo self::form_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="m24b2b"><div class="m24b2b-card">';
        echo M24_I18n::lang_switcher( $lg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ( $sent ) {
            echo '<div class="m24b2b-ok">' . esc_html( $t( 'reg_ok_title' ) ) . '</div>';
            echo '<div class="m24b2b-note">' . esc_html( $t( 'reg_ok_text' ) ) . '</div>';
            echo '</div></div>';
            return (string) ob_get_clean();
        }
        if ( isset( $_GET['fehler'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="m24b2b-err">' . esc_html( $t( 'reg_err' ) ) . '</div>';
        }

        $ds_link  = '<a href="' . esc_url( home_url( self::DS_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html( $t( 'consent_ds_link' ) ) . '</a>';
        $agb_link = '<a href="' . esc_url( home_url( self::AGB_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html( $t( 'consent_agb_link' ) ) . '</a>';
        $o        = self::old_values();
        $req      = '<span class="req">' . esc_html( $t( 'req' ) ) . '</span>';
        ?>
        <h2><?php echo esc_html( $t( 'reg_h1' ) ); ?></h2>
        <p class="sub"><?php echo esc_html( $t( 'reg_sub' ) ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="m24_haendler_register">
            <input type="hidden" name="reg_src" value="<?php echo esc_url( get_permalink() ); ?>">
            <input type="hidden" name="m24_lang" value="<?php echo esc_attr( $lg ); ?>">
            <?php wp_nonce_field( 'm24_haendler_register' ); ?>
            <label for="m24firma"><?php echo esc_html( $t( 'f_firma' ) ); ?> <?php echo $req; // phpcs:ignore ?></label>
            <input type="text" id="m24firma" name="firma" value="<?php echo esc_attr( $o['firma'] ); ?>" required>
            <div class="row">
                <div>
                    <label for="m24anrede"><?php echo esc_html( $t( 'f_anrede' ) ); ?></label>
                    <select id="m24anrede" name="anrede">
                        <option value="">—</option>
                        <?php foreach ( array( 'Herr' => $t( 'anrede_herr' ), 'Frau' => $t( 'anrede_frau' ), 'Divers' => $t( 'anrede_divers' ) ) as $val => $lbl ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $o['anrede'], $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="m24land"><?php echo esc_html( $t( 'f_land' ) ); ?> <?php echo $req; // phpcs:ignore ?></label>
                    <select id="m24land" name="land" required>
                        <?php foreach ( M24_I18n::countries( $lg ) as $cc => $cname ) : ?>
                            <option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $o['land'], $cc ); ?>><?php echo esc_html( $cname ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div><label for="m24vorname"><?php echo esc_html( $t( 'f_vorname' ) ); ?> <?php echo $req; // phpcs:ignore ?></label><input type="text" id="m24vorname" name="vorname" value="<?php echo esc_attr( $o['vorname'] ); ?>" required></div>
                <div><label for="m24nachname"><?php echo esc_html( $t( 'f_nachname' ) ); ?> <?php echo $req; // phpcs:ignore ?></label><input type="text" id="m24nachname" name="nachname" value="<?php echo esc_attr( $o['nachname'] ); ?>" required></div>
            </div>
            <div class="row">
                <div><label for="m24email"><?php echo esc_html( $t( 'f_email' ) ); ?> <?php echo $req; // phpcs:ignore ?></label><input type="email" id="m24email" name="email" value="<?php echo esc_attr( $o['email'] ); ?>" required></div>
                <div><label for="m24tel"><?php echo esc_html( $t( 'f_telefon' ) ); ?></label><input type="tel" id="m24tel" name="telefon" value="<?php echo esc_attr( $o['telefon'] ); ?>"></div>
            </div>
            <label for="m24uid"><?php echo esc_html( $t( 'f_uid' ) ); ?> <span class="req" id="m24uidreq"><?php echo esc_html( $t( 'uid_hint' ) ); ?></span></label>
            <input type="text" id="m24uid" name="uid" class="m24-uid" value="<?php echo esc_attr( $o['uid'] ); ?>" placeholder="<?php echo esc_attr( $t( 'uid_ph' ) ); ?>">
            <div class="m24-uid-fb" aria-live="polite"></div>
            <label class="chk"><input type="checkbox" name="consent_ds" value="1" <?php checked( $o['consent_ds'] ); ?> required><span><?php echo wp_kses_post( sprintf( $t( 'consent_ds' ), $ds_link ) ); ?> <?php echo $req; // phpcs:ignore ?></span></label>
            <label class="chk"><input type="checkbox" name="consent_agb" value="1" <?php checked( $o['consent_agb'] ); ?> required><span><?php echo wp_kses_post( sprintf( $t( 'consent_agb' ), $agb_link ) ); ?> <?php echo $req; // phpcs:ignore ?></span></label>
            <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button type="submit" class="m24b2b-btn"><?php echo esc_html( $t( 'submit_reg' ) ); ?></button>
        </form>
        <?php
        echo self::vies_assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div></div>';
        return (string) ob_get_clean();
    }

    /** VIES-Live-Feedback (Blur des .m24-uid-Feldes). Einmal pro Seite ausgeben. */
    private static function vies_assets(): string {
        static $done = false;
        if ( $done ) {
            return '';
        }
        $done  = true;
        $nonce = wp_create_nonce( 'm24_vies' );
        $url   = admin_url( 'admin-ajax.php' );
        $lg    = M24_I18n::resolve_lang();
        $i18n  = array(
            'checking' => M24_I18n::t( 'vies_checking', $lg ),
            'ok'       => M24_I18n::t( 'vies_ok', $lg ),
            'bad'      => M24_I18n::t( 'vies_bad', $lg ),
            'na'       => M24_I18n::t( 'vies_na', $lg ),
        );
        ob_start();
        ?>
        <script>
        (function(){
            var NONCE=<?php echo wp_json_encode( $nonce ); ?>, URL=<?php echo wp_json_encode( $url ); ?>, T=<?php echo wp_json_encode( $i18n ); ?>;
            function bind(inp){
                if(inp.dataset.viesBound) return; inp.dataset.viesBound='1';
                var fb=inp.parentNode.querySelector('.m24-uid-fb');
                inp.addEventListener('blur',function(){
                    if(!fb) return;
                    var v=inp.value.trim();
                    if(v.length<4){ fb.className='m24-uid-fb'; fb.textContent=''; return; }
                    fb.className='m24-uid-fb checking'; fb.textContent=T.checking;
                    fetch(URL,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                        body:'action=m24_vies&_ajax_nonce='+encodeURIComponent(NONCE)+'&uid='+encodeURIComponent(v)})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        if(j&&j.valid===true){ fb.className='m24-uid-fb ok'; fb.textContent=T.ok+(j.name?(' · '+j.name):''); }
                        else if(j&&j.valid===false){ fb.className='m24-uid-fb bad'; fb.textContent=T.bad; }
                        else { fb.className='m24-uid-fb neutral'; fb.textContent=T.na; }
                    })
                    .catch(function(){ fb.className='m24-uid-fb neutral'; fb.textContent=T.na; });
                });
            }
            function init(){ Array.prototype.forEach.call(document.querySelectorAll('.m24-uid'),bind); }
            if(document.readyState!=='loading'){ init(); } else { document.addEventListener('DOMContentLoaded',init); }
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /** Bei Validierungsfehler zwischengespeicherte Eingaben (Transient via ?r=) oder Defaults. */
    private static function old_values(): array {
        $def = array(
            'firma' => '', 'anrede' => '', 'vorname' => '', 'nachname' => '',
            'email' => '', 'telefon' => '', 'land' => 'DE', 'uid' => '',
            'consent_ds' => false, 'consent_agb' => false,
        );
        $tok = isset( $_GET['r'] ) ? sanitize_text_field( wp_unslash( $_GET['r'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( '' === $tok ) {
            return $def;
        }
        $data = get_transient( 'm24_reg_old_' . $tok );
        if ( is_array( $data ) ) {
            delete_transient( 'm24_reg_old_' . $tok );
            return array_merge( $def, $data );
        }
        return $def;
    }

    /**
     * Variante 2 (A/B-Test) — Anfrageformular-Optik: große Felder, Bezeichnung als Placeholder INNEN.
     * Gleicher Handler/Nonce/action/Feldnamen + VIES + Feld-Erhalt wie Variante 1.
     */
    public static function render_registration_form_v2(): string {
        $lg = M24_I18n::resolve_lang();
        $t  = static function ( $k ) use ( $lg ) { return M24_I18n::t( $k, $lg ); };
        ob_start();
        echo self::form_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="m24b2b m24b2b-v2"><div class="m24b2b-card">';
        echo M24_I18n::lang_switcher( $lg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ( isset( $_GET['gesendet'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="m24b2b-ok">' . esc_html( $t( 'reg_ok_title' ) ) . '</div>';
            echo '<div class="m24b2b-note">' . esc_html( $t( 'reg_ok_text' ) ) . '</div>';
            echo '</div></div>';
            return (string) ob_get_clean();
        }
        if ( isset( $_GET['fehler'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="m24b2b-err">' . esc_html( $t( 'reg_err' ) ) . '</div>';
        }

        $ds_link  = '<a href="' . esc_url( home_url( self::DS_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html( $t( 'consent_ds_link' ) ) . '</a>';
        $agb_link = '<a href="' . esc_url( home_url( self::AGB_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html( $t( 'consent_agb_link' ) ) . '</a>';
        $o        = self::old_values();
        $has_old  = isset( $_GET['r'] ); // phpcs:ignore WordPress.Security.NonceVerification
        $land_sel = $has_old ? $o['land'] : '';
        $req      = '<span class="req">' . esc_html( $t( 'req' ) ) . '</span>';
        $star     = ' ' . $t( 'req' );
        ?>
        <h2><?php echo esc_html( $t( 'reg_h1' ) ); ?></h2>
        <p class="sub"><?php echo esc_html( $t( 'reg_sub' ) ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="m24_haendler_register">
            <input type="hidden" name="reg_src" value="<?php echo esc_url( get_permalink() ); ?>">
            <input type="hidden" name="m24_lang" value="<?php echo esc_attr( $lg ); ?>">
            <?php wp_nonce_field( 'm24_haendler_register' ); ?>
            <div class="m24f"><input type="text" name="firma" value="<?php echo esc_attr( $o['firma'] ); ?>" placeholder="<?php echo esc_attr( $t( 'f_firma' ) . $star ); ?>" required></div>
            <div class="row">
                <div class="m24f">
                    <select name="anrede">
                        <option value=""><?php echo esc_html( $t( 'f_anrede' ) ); ?></option>
                        <?php foreach ( array( 'Herr' => $t( 'anrede_herr' ), 'Frau' => $t( 'anrede_frau' ), 'Divers' => $t( 'anrede_divers' ) ) as $val => $lbl ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $o['anrede'], $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="m24f">
                    <select name="land" required>
                        <option value=""><?php echo esc_html( $t( 'f_land' ) . $star ); ?></option>
                        <?php foreach ( M24_I18n::countries( $lg ) as $cc => $cname ) : ?>
                            <option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $land_sel, $cc ); ?>><?php echo esc_html( $cname ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="m24f"><input type="text" name="vorname" value="<?php echo esc_attr( $o['vorname'] ); ?>" placeholder="<?php echo esc_attr( $t( 'f_vorname' ) . $star ); ?>" required></div>
                <div class="m24f"><input type="text" name="nachname" value="<?php echo esc_attr( $o['nachname'] ); ?>" placeholder="<?php echo esc_attr( $t( 'f_nachname' ) . $star ); ?>" required></div>
            </div>
            <div class="row">
                <div class="m24f"><input type="email" name="email" value="<?php echo esc_attr( $o['email'] ); ?>" placeholder="<?php echo esc_attr( $t( 'f_email' ) . $star ); ?>" required></div>
                <div class="m24f"><input type="tel" name="telefon" value="<?php echo esc_attr( $o['telefon'] ); ?>" placeholder="<?php echo esc_attr( $t( 'f_telefon' ) ); ?>"></div>
            </div>
            <div class="m24f"><input type="text" name="uid" class="m24-uid" value="<?php echo esc_attr( $o['uid'] ); ?>" placeholder="<?php echo esc_attr( $t( 'f_uid' ) . ' ' . $t( 'uid_hint' ) ); ?>"><div class="m24-uid-fb" aria-live="polite"></div></div>
            <label class="chk"><input type="checkbox" name="consent_ds" value="1" <?php checked( $o['consent_ds'] ); ?> required><span><?php echo wp_kses_post( sprintf( $t( 'consent_ds' ), $ds_link ) ); ?> <?php echo $req; // phpcs:ignore ?></span></label>
            <label class="chk"><input type="checkbox" name="consent_agb" value="1" <?php checked( $o['consent_agb'] ); ?> required><span><?php echo wp_kses_post( sprintf( $t( 'consent_agb' ), $agb_link ) ); ?> <?php echo $req; // phpcs:ignore ?></span></label>
            <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button type="submit" class="m24b2b-btn"><?php echo esc_html( $t( 'submit_reg' ) ); ?></button>
        </form>
        <?php
        echo self::vies_assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div></div>';
        return (string) ob_get_clean();
    }

    public static function render_login_form(): string {
        $lg = M24_I18n::resolve_lang();
        $t  = static function ( $k ) use ( $lg ) { return M24_I18n::t( $k, $lg ); };
        ob_start();
        echo self::form_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="m24b2b"><div class="m24b2b-card">';
        echo M24_I18n::lang_switcher( $lg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ( isset( $_GET['gesendet'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="m24b2b-ok">' . esc_html( $t( 'login_ok_title' ) ) . '</div>';
            echo '<div class="m24b2b-note">' . esc_html( $t( 'login_ok_text' ) ) . '</div>';
            echo '</div></div>';
            return (string) ob_get_clean();
        }
        ?>
        <h2><?php echo esc_html( $t( 'login_h1' ) ); ?></h2>
        <p class="sub"><?php echo esc_html( $t( 'login_sub' ) ); ?></p>
        <?php if ( isset( $_GET['fehler'] ) && 'link' === $_GET['fehler'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
            <div class="m24b2b-err"><?php echo esc_html( $t( 'login_err_link' ) ); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="m24_haendler_login">
            <input type="hidden" name="m24_lang" value="<?php echo esc_attr( $lg ); ?>">
            <?php wp_nonce_field( 'm24_haendler_login' ); ?>
            <label for="m24loginmail"><?php echo esc_html( $t( 'f_email' ) ); ?> <span class="req"><?php echo esc_html( $t( 'req' ) ); ?></span></label>
            <input type="email" id="m24loginmail" name="email" required>
            <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button type="submit" class="m24b2b-btn"><?php echo esc_html( $t( 'submit_login' ) ); ?></button>
        </form>
        <?php
        echo '</div></div>';
        return (string) ob_get_clean();
    }

    /* ── Handler ─────────────────────────────────────────────────────────── */

    /** Validierungsfehler: Eingaben in Transient sichern und mit ?fehler=1&r=<tok>&lang= zurück. */
    private static function fail_register( string $reg, array $old, string $lang = '' ): void {
        $tok  = wp_generate_password( 20, false );
        set_transient( 'm24_reg_old_' . $tok, $old, 5 * MINUTE_IN_SECONDS );
        $args = array( 'fehler' => '1', 'r' => $tok );
        if ( '' !== $lang ) { $args['lang'] = $lang; }
        wp_safe_redirect( add_query_arg( $args, $reg ) );
        exit;
    }

    /** Redirect-Ziel = die Variante, von der abgeschickt wurde (reg oder reg2), sonst reg. */
    private static function resolve_reg_src(): string {
        $src = isset( $_POST['reg_src'] ) ? esc_url_raw( wp_unslash( $_POST['reg_src'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( '' !== $src ) {
            foreach ( array( self::reg_page_url(), self::reg2_page_url() ) as $a ) {
                if ( untrailingslashit( $src ) === untrailingslashit( $a ) ) {
                    return $a;
                }
            }
        }
        return self::reg_page_url();
    }

    public static function handle_register() {
        check_admin_referer( 'm24_haendler_register' );
        $reg  = self::resolve_reg_src();
        $lang = self::posted_lang();

        // Honeypot + Rate-Limit (Bots/Abuse → still „erfolgreich").
        if ( ! empty( $_POST['website'] ) || ! self::rate_ok() ) {
            wp_safe_redirect( add_query_arg( array( 'gesendet' => '1', 'lang' => $lang ), $reg ) );
            exit;
        }

        $firma    = sanitize_text_field( wp_unslash( $_POST['firma'] ?? '' ) );
        $vorname  = sanitize_text_field( wp_unslash( $_POST['vorname'] ?? '' ) );
        $nachname = sanitize_text_field( wp_unslash( $_POST['nachname'] ?? '' ) );
        $anrede   = sanitize_text_field( wp_unslash( $_POST['anrede'] ?? '' ) );
        $telefon  = sanitize_text_field( wp_unslash( $_POST['telefon'] ?? '' ) );
        $land     = strtoupper( sanitize_text_field( wp_unslash( $_POST['land'] ?? '' ) ) );
        $uid      = sanitize_text_field( wp_unslash( $_POST['uid'] ?? '' ) );
        $email    = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
        $c_ds     = ! empty( $_POST['consent_ds'] );
        $c_agb    = ! empty( $_POST['consent_agb'] );

        // Eingaben für Feld-Erhalt bei Fehler (Telefon ist KEIN Pflichtfeld).
        $old = array(
            'firma' => $firma, 'anrede' => $anrede, 'vorname' => $vorname, 'nachname' => $nachname,
            'email' => $email, 'telefon' => $telefon, 'land' => $land, 'uid' => $uid,
            'consent_ds' => $c_ds, 'consent_agb' => $c_agb,
        );

        $ok = ( '' !== $firma && '' !== $vorname && '' !== $nachname
            && is_email( $email ) && 2 === strlen( $land ) && $c_ds && $c_agb );
        if ( $ok && self::is_eu( $land ) && '' === $uid ) {
            $ok = false; // UID-Pflicht in der EU
        }
        if ( ! $ok ) {
            self::fail_register( $reg, $old, $lang );
        }

        // VIES autoritativ: nur EU + UID gesetzt. FAIL-SAFE — Ausfall blockt NIE.
        $uid_valid        = null;
        $uid_validated_at = null;
        if ( self::is_eu( $land ) && '' !== $uid ) {
            $v = self::vies_check( $uid );
            if ( false === $v['valid'] ) {
                self::fail_register( $reg, $old, $lang ); // ungültige USt-IdNr.
            }
            if ( true === $v['valid'] ) {
                $uid_valid        = 1;
                $uid_validated_at = gmdate( 'Y-m-d H:i:s' );
            }
            // checked===false → uid_valid bleibt NULL, NICHT blocken.
        }

        $existing = get_user_by( 'email', $email );
        if ( $existing ) {
            // Bereits Händler → kein Neuanlegen, stattdessen Login-Link. Andere Rolle → bewusst
            // nichts tun. Nach außen in beiden Fällen dieselbe Erfolgsmeldung (Anti-Enumeration).
            if ( self::user_is_haendler( $existing ) ) {
                $raw = M24_B2B::issue_token( $email, 'login', (int) $existing->ID );
                self::send_magic_mail( $email, $raw, 'login' );
            }
        } else {
            self::create_haendler( $email, $firma, $vorname, $nachname, $anrede, $telefon, $land, $uid, $uid_valid, $uid_validated_at, $lang );
        }

        wp_safe_redirect( add_query_arg( array( 'gesendet' => '1', 'lang' => $lang ), $reg ) );
        exit;
    }

    /** Gewählte Sprache aus dem Formular (Hidden m24_lang), sonst aktuelle Auflösung. */
    private static function posted_lang(): string {
        $l = isset( $_POST['m24_lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['m24_lang'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return in_array( $l, array( 'de', 'en' ), true ) ? $l : M24_I18n::resolve_lang();
    }

    private static function create_haendler( string $email, string $firma, string $vorname, string $nachname, string $anrede, string $telefon, string $land, string $uid, ?int $uid_valid = null, ?string $uid_validated_at = null, string $lang = 'de' ): void {
        $lang   = in_array( $lang, array( 'de', 'en' ), true ) ? $lang : 'de';
        $locale = 'en' === $lang ? 'en_US' : 'de_DE';
        $user_id = wp_insert_user( array(
            'user_login'    => $email,
            'user_email'    => $email,
            'user_pass'     => wp_generate_password( 24, true, true ),
            'display_name'  => $firma,
            'first_name'    => $vorname,
            'last_name'     => $nachname,
            'role'          => M24_B2B::ROLE,
            'locale'        => $locale,
        ) );
        if ( is_wp_error( $user_id ) ) {
            return; // still nach außen „erfolgreich"
        }

        // Profil-Zusatz (nicht in der haendler-Tabelle modelliert) als usermeta.
        update_user_meta( $user_id, '_m24_anrede', $anrede );
        update_user_meta( $user_id, '_m24_telefon', $telefon );

        global $wpdb;
        $wpdb->insert(
            M24_Database::table( 'haendler' ),
            array(
                'wp_user_id'        => (int) $user_id,
                'firma'             => $firma,
                'uid'               => '' !== $uid ? $uid : null,
                'uid_valid'         => $uid_valid,        // 1 (VIES gültig) | null (ungeprüft/Ausfall)
                'uid_validated_at'  => $uid_validated_at, // UTC | null
                'land'              => $land,
                'sprach_praeferenz' => $lang,             // gewählte UI-Sprache (de|en)
                'status'            => 'pending_verification',
                'created_at'        => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        // Consent-Snapshot (DSGVO-Nachweis) inkl. angezeigter Sprache + String-Version.
        update_user_meta( $user_id, '_m24_consent', wp_json_encode( array(
            'agb'         => true,
            'datenschutz' => true,
            'ts'          => gmdate( 'Y-m-d H:i:s' ),
            'ip_hash'     => self::ip_hash(),
            'agb_url'     => self::AGB_PATH,
            'ds_url'      => self::DS_PATH,
            'shown_lang'  => $lang,
            'version'     => M24_I18n::VERSION,
        ) ) );

        $raw = M24_B2B::issue_token( $email, 'verify', (int) $user_id );
        self::send_magic_mail( $email, $raw, 'verify' );
    }

    public static function handle_login() {
        check_admin_referer( 'm24_haendler_login' );
        $login = self::login_page_url();
        $lang  = self::posted_lang();

        if ( ! empty( $_POST['website'] ) || ! self::rate_ok() ) {
            wp_safe_redirect( add_query_arg( array( 'gesendet' => '1', 'lang' => $lang ), $login ) );
            exit;
        }

        $email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
        if ( is_email( $email ) ) {
            $user = get_user_by( 'email', $email );
            if ( $user && self::user_is_haendler( $user ) ) {
                $raw = M24_B2B::issue_token( $email, 'login', (int) $user->ID );
                self::send_magic_mail( $email, $raw, 'login' );
            }
        }
        // Anti-Enumeration: immer dieselbe Antwort.
        wp_safe_redirect( add_query_arg( array( 'gesendet' => '1', 'lang' => $lang ), $login ) );
        exit;
    }

    public static function confirm_intercept() {
        if ( empty( $_GET['m24_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        nocache_headers();
        if ( ! headers_sent() ) {
            header( 'X-Robots-Tag: noindex', true );
        }

        $raw = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['m24_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        $row = M24_B2B::consume_token_any( $raw );
        if ( ! $row ) {
            wp_safe_redirect( add_query_arg( 'fehler', 'link', self::login_page_url() ) );
            exit;
        }

        if ( 'verify' === $row->purpose ) {
            global $wpdb;
            $aff = $wpdb->query( $wpdb->prepare(
                "UPDATE " . M24_Database::table( 'haendler' ) . " SET status = 'verified', updated_at = %s WHERE wp_user_id = %d AND status = 'pending_verification'",
                current_time( 'mysql', true ),
                (int) $row->wp_user_id
            ) );
            if ( $aff > 0 ) {
                self::notify_admin_new_verification( (int) $row->wp_user_id ); // wartet auf Freigabe
            }
        }

        wp_set_auth_cookie( (int) $row->wp_user_id, true );
        wp_safe_redirect( home_url( '/?willkommen=1' ) );
        exit;
    }

    /* ── Magic-Mail (Stil identisch zur DOI-Bestätigungsmail) ────────────── */

    public static function send_magic_mail( string $email, string $rawtoken, string $purpose ): void {
        $url = home_url( '/?m24_confirm=' . rawurlencode( $rawtoken ) );

        if ( 'verify' === $purpose ) {
            $subject = 'Bitte bestätige deine Registrierung – MOTORSPORT24';
            $head    = 'Registrierung bestätigen';
            $text    = 'Klicke zum Bestätigen deiner Händler-Registrierung. Der Link ist 15 Minuten gültig.';
            $cta     = 'Registrierung bestätigen';
        } else {
            $subject = 'Dein Login-Link – MOTORSPORT24';
            $head    = 'Login bei MOTORSPORT24';
            $text    = 'Klicke, um dich einzuloggen. Der Link ist 15 Minuten gültig.';
            $cta     = 'Jetzt einloggen';
        }

        $inner  = '<p style="margin:0 0 14px;">' . esc_html( $text ) . '</p>';
        $inner .= '<p style="margin:24px 0;text-align:center;">'
            . '<a href="' . esc_url( $url ) . '" class="m24-cta" style="display:inline-block;background:#1f74c4;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 30px;border-radius:8px;font-size:15px;">' . esc_html( $cta ) . '</a>'
            . '</p>';
        $inner .= '<p style="margin:0 0 8px;color:#5a6474;font-size:13px;">Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:</p>';
        $inner .= '<p style="margin:0 0 14px;font-size:12px;word-break:break-all;"><a href="' . esc_url( $url ) . '" style="color:#1f74c4;">' . esc_html( $url ) . '</a></p>';
        $inner .= '<p style="margin:0;color:#9aa3b0;font-size:12px;">Wenn du das nicht angefordert hast, ignoriere diese E-Mail einfach.</p>';

        $body    = self::mail_html( $head, $inner );
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::from_header(),
            'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
        );
        wp_mail( $email, $subject, $body, $headers );
    }

    /** CI-Mail-Gerüst — identisch zur DOI-Mail (Gradient-Band, Saira, Logo, Footer). CTA-Gradient als Klasse. */
    private static function mail_html( string $headline, string $inner ): string {
        $font_url = plugins_url( 'assets/fonts/saira-latin.woff2', M24_PLATTFORM_FILE );
        $stack    = "font-family:'Saira', Arial, Helvetica, sans-serif;";
        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            . '<style>@font-face{font-family:\'Saira\';src:url(\'' . esc_url( $font_url ) . '\') format(\'woff2\');font-weight:100 900;font-style:normal;font-display:swap;}'
            . 'body,table,td,h1,div,a,p{' . $stack . '}'
            . 'a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;font-size:inherit!important;font-weight:inherit!important;}'
            . '.m24-cta{background-image:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%)!important;}</style></head>'
            . '<body style="margin:0;padding:0;background:#f2f4f7;' . $stack . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:0;"><tr><td align="center" style="padding:24px 16px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);padding:16px 28px;text-align:right;">'
            . '<img src="' . esc_url( apply_filters( 'm24fz_mail_logo_url', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2023/09/Logo-MOTORSPORT24.de_.gif' ) ) . '" alt="MOTORSPORT24" height="30" style="display:inline-block;height:30px;width:auto;border:0;outline:none;vertical-align:middle;">'
            . '</td></tr>'
            . '<tr><td style="padding:8px 28px 24px;' . $stack . 'color:#10243a;">'
            . '<h1 style="margin:8px 0 16px;font-size:21px;color:#10243a;' . $stack . '">' . esc_html( $headline ) . '</h1>'
            . '<div style="font-size:15px;line-height:1.55;color:#3a414c;' . $stack . '">' . $inner . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 28px;border-top:1px solid #e6e9ee;text-align:center;' . $stack . 'font-size:11px;line-height:1.6;color:#9aa3b0;">'
            . '<div style="color:#7e8794;font-size:11.5px;">Classic &amp; Race Cars and Parts Sales since 2006</div>'
            . '<div style="margin-top:10px;">Unsere Postanschrift lautet:</div>'
            . '<div>MOTORSPORT24 GmbH, Scharfe Lanke 109-131, Haus 113a, 13595 Berlin, Deutschland</div>'
            . '<div style="margin-top:10px;">'
            . '<a href="https://www.motorsport24.de/impressum/" style="color:#1f74c4;text-decoration:none;' . $stack . '">Impressum</a> · '
            . '<a href="https://www.motorsport24.de/datenschutz/" style="color:#1f74c4;text-decoration:none;' . $stack . '">Datenschutz</a> · '
            . '<a href="https://www.motorsport24.de" style="color:#1f74c4;text-decoration:none;' . $stack . '">www.motorsport24.de</a>'
            . '</div>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function from_header(): string {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $host = preg_replace( '/^www\./i', '', $host );
        if ( '' === $host ) {
            $host = 'motorsport24.de';
        }
        $email = apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host );
        $name  = apply_filters( 'm24_brevo_doi_from_name', 'MOTORSPORT24' );
        return $name . ' <' . $email . '>';
    }

    /* ── Freigabe-/Ablehn-Mails + Admin-Benachrichtigung (Garage A3) ─────── */

    /** ISO-2 → deutscher Klarname (öffentlich für die Admin-Liste). */
    public static function country_name( string $iso ): string {
        $iso = strtoupper( trim( $iso ) );
        $m   = self::countries();
        return $m[ $iso ] ?? ( '' !== $iso ? $iso : '—' );
    }

    /** CI-Mail (gleiches Gerüst wie send_magic_mail) versenden. */
    private static function send_ci( string $to, string $subject, string $headline, string $inner ): void {
        if ( ! is_email( $to ) ) {
            return;
        }
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::from_header(),
            'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
        );
        wp_mail( $to, $subject, self::mail_html( $headline, $inner ), $headers );
    }

    public static function send_approval_mail( int $user_id ): void {
        $u = get_userdata( $user_id );
        if ( ! $u ) {
            return;
        }
        $name  = $u->first_name ?: $u->display_name;
        $login = esc_url( home_url( '/haendler-login/' ) );
        $inner = '<p style="margin:0 0 14px;">Hallo ' . esc_html( $name ) . ',</p>'
            . '<p style="margin:0 0 14px;">deine Registrierung wurde geprüft und freigeschaltet. Du kannst dich jetzt einloggen und siehst Händlerpreise.</p>'
            . '<p style="margin:24px 0;text-align:center;"><a href="' . $login . '" class="m24-cta" style="display:inline-block;background:#1f74c4;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 30px;border-radius:8px;font-size:15px;">Zum Login</a></p>';
        self::send_ci( $u->user_email, 'Dein Händler-Zugang ist freigeschaltet – MOTORSPORT24', 'Zugang freigeschaltet', $inner );
    }

    public static function send_rejection_mail( int $user_id, string $grund_text ): void {
        $u = get_userdata( $user_id );
        if ( ! $u ) {
            return;
        }
        $name  = $u->first_name ?: $u->display_name;
        $inner = '<p style="margin:0 0 14px;">Hallo ' . esc_html( $name ) . ',</p>'
            . '<p style="margin:0 0 14px;">wir konnten deine Registrierung für den Händlerbereich aktuell nicht freischalten.</p>'
            . '<p style="margin:0 0 14px;"><strong>Grund:</strong> ' . esc_html( $grund_text ) . '</p>'
            . '<p style="margin:0;">Falls das ein Irrtum ist oder du Angaben ergänzen möchtest, melde dich gern unter <a href="mailto:service@motorsport24.de" style="color:#1f74c4;">service@motorsport24.de</a>.</p>';
        self::send_ci( $u->user_email, 'Zu deiner Händler-Registrierung – MOTORSPORT24', 'Zu deiner Registrierung', $inner );
    }

    /** Admin-Mail bei neuer Verifizierung (wartet auf Freigabe). */
    private static function notify_admin_new_verification( int $user_id ): void {
        $u = get_userdata( $user_id );
        if ( ! $u ) {
            return;
        }
        global $wpdb;
        $h     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . M24_Database::table( 'haendler' ) . " WHERE wp_user_id = %d", $user_id ) );
        $firma = $h ? $h->firma : $u->display_name;
        $land  = $h ? (string) $h->land : '';
        $uid   = $h ? (string) $h->uid : '';
        $vies  = ( $h && null !== $h->uid_valid ) ? ( (int) $h->uid_valid === 1 ? 'gültig' : 'ungültig' ) : 'ungeprüft';
        $to    = apply_filters( 'm24_haendler_notify_email', 'service@motorsport24.de' );
        $link  = admin_url( 'admin.php?page=m24-haendler' );

        $body = '<p>Neue Händler-Registrierung wartet auf Freigabe:</p><ul>'
            . '<li><strong>Firma:</strong> ' . esc_html( $firma ) . '</li>'
            . '<li><strong>E-Mail:</strong> ' . esc_html( $u->user_email ) . '</li>'
            . '<li><strong>Land:</strong> ' . esc_html( self::country_name( $land ) ) . '</li>'
            . '<li><strong>USt-IdNr.:</strong> ' . esc_html( $uid ?: '—' ) . ' (' . esc_html( $vies ) . ')</li>'
            . '</ul><p><a href="' . esc_url( $link ) . '">Im Admin prüfen &amp; freigeben</a></p>';
        wp_mail( $to, 'Neue Händler-Registrierung wartet auf Freigabe', $body, array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from_header() ) );
    }
}
