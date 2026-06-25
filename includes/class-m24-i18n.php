<?php
/**
 * M24 Plattform — i18n-Fundament (DE/EN).
 *
 * Wiederverwendbare String-Registry + Sprachauflösung (Query → Cookie → Accept-Language → DE).
 * Quellsprache ist Deutsch; Englisch als Zusatz. Bewusst klein gehalten und ausbaufähig:
 * detect_default_lang() ist isoliert → später auf CDN-Header/GeoIP umstellbar.
 *
 * Hinweis: Der Cookie-WRITE passiert in init() (Hook 'init', vor Ausgabe) — resolve_lang()
 * ist seiteneffektfrei (nur Lesen), damit es gefahrlos während des Renderings aufrufbar ist.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_I18n {

    const COOKIE = 'm24_lang';
    const VERSION = '2026-06-de1'; // Consent-/String-Stand (für _m24_consent-Snapshot)

    public static function init() {
        // Cookie aus ?lang setzen, solange noch keine Header raus sind.
        if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $l = self::norm( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
            if ( $l && ! headers_sent() ) {
                setcookie( self::COOKIE, $l, array(
                    'expires'  => time() + 30 * DAY_IN_SECONDS,
                    'path'     => '/',
                    'samesite' => 'Lax',
                    'httponly' => true,
                    'secure'   => is_ssl(),
                ) );
                $_COOKIE[ self::COOKIE ] = $l;
            }
        }
    }

    private static function norm( $v ): string {
        $v = strtolower( trim( (string) $v ) );
        return in_array( $v, array( 'de', 'en' ), true ) ? $v : '';
    }

    /** Aktive Sprache: 1) ?lang  2) Cookie  3) Accept-Language  4) de. Seiteneffektfrei. */
    public static function resolve_lang(): string {
        if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $l = self::norm( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
            if ( $l ) { return $l; }
        }
        if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
            $l = self::norm( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
            if ( $l ) { return $l; }
        }
        return self::detect_default_lang();
    }

    /** Primäres Accept-Language-Tag: beginnt mit 'de' → de, sonst en. Isoliert (CDN/GeoIP-ready). */
    public static function detect_default_lang(): string {
        $al = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) : '';
        $primary = trim( explode( ',', $al )[0] ?? '' );
        if ( 0 === strpos( $primary, 'de' ) ) { return 'de'; }
        if ( '' !== $primary ) { return 'en'; }
        return 'de';
    }

    /** Übersetzung: fehlender Key → DE-Fallback → Key selbst. */
    public static function t( string $key, ?string $lang = null ): string {
        $lang = $lang ?: self::resolve_lang();
        $s    = self::strings();
        if ( isset( $s[ $lang ][ $key ] ) ) { return $s[ $lang ][ $key ]; }
        if ( isset( $s['de'][ $key ] ) ) { return $s['de'][ $key ]; }
        return $key;
    }

    /** Länder ISO-2 ⇒ Klarname je Sprache. DE/AT/CH oben, Rest alphabetisch. Filterbar. */
    public static function countries( ?string $lang = null ): array {
        $lang = $lang ?: self::resolve_lang();
        $de = array(
            'DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz','BE'=>'Belgien','BG'=>'Bulgarien','CY'=>'Zypern',
            'CZ'=>'Tschechien','DK'=>'Dänemark','EE'=>'Estland','ES'=>'Spanien','FI'=>'Finnland','FR'=>'Frankreich',
            'GB'=>'Vereinigtes Königreich','GR'=>'Griechenland','HR'=>'Kroatien','HU'=>'Ungarn','IE'=>'Irland',
            'IT'=>'Italien','LI'=>'Liechtenstein','LT'=>'Litauen','LU'=>'Luxemburg','LV'=>'Lettland','MT'=>'Malta',
            'NL'=>'Niederlande','NO'=>'Norwegen','PL'=>'Polen','PT'=>'Portugal','RO'=>'Rumänien','SE'=>'Schweden',
            'SK'=>'Slowakei','SI'=>'Slowenien','US'=>'USA',
        );
        $en = array(
            'DE'=>'Germany','AT'=>'Austria','CH'=>'Switzerland','BE'=>'Belgium','BG'=>'Bulgaria','CY'=>'Cyprus',
            'CZ'=>'Czechia','DK'=>'Denmark','EE'=>'Estonia','ES'=>'Spain','FI'=>'Finland','FR'=>'France',
            'GB'=>'United Kingdom','GR'=>'Greece','HR'=>'Croatia','HU'=>'Hungary','IE'=>'Ireland','IT'=>'Italy',
            'LI'=>'Liechtenstein','LT'=>'Lithuania','LU'=>'Luxembourg','LV'=>'Latvia','MT'=>'Malta','NL'=>'Netherlands',
            'NO'=>'Norway','PL'=>'Poland','PT'=>'Portugal','RO'=>'Romania','SE'=>'Sweden','SK'=>'Slovakia',
            'SI'=>'Slovenia','US'=>'United States',
        );
        $map = ( 'en' === $lang ) ? $en : $de;
        $top = array( 'DE' => $map['DE'], 'AT' => $map['AT'], 'CH' => $map['CH'] );
        $rest = $map;
        unset( $rest['DE'], $rest['AT'], $rest['CH'] );
        asort( $rest );
        return (array) apply_filters( 'm24_i18n_countries', $top + $rest, $lang );
    }

    /* ── Flaggen (Inline-SVG, ~20px) ─────────────────────────────────────── */

    public static function flag_de(): string {
        return '<svg width="26" height="18" viewBox="0 0 5 3" aria-hidden="true"><rect width="5" height="3" fill="#000"/><rect width="5" height="2" y="1" fill="#D00"/><rect width="5" height="1" y="2" fill="#FFCE00"/></svg>';
    }

    public static function flag_en(): string {
        // Vereinfachter Union Jack.
        return '<svg width="26" height="18" viewBox="0 0 60 36" aria-hidden="true">'
            . '<rect width="60" height="36" fill="#012169"/>'
            . '<path d="M0,0 60,36 M60,0 0,36" stroke="#fff" stroke-width="7"/>'
            . '<path d="M0,0 60,36 M60,0 0,36" stroke="#C8102E" stroke-width="4"/>'
            . '<rect x="25" width="10" height="36" fill="#fff"/><rect y="13" width="60" height="10" fill="#fff"/>'
            . '<rect x="27" width="6" height="36" fill="#C8102E"/><rect y="15" width="60" height="6" fill="#C8102E"/>'
            . '</svg>';
    }

    /** Flag-Umschalter (oben rechts in der Card). Aktive Sprache hervorgehoben, inaktive = ?lang-Link. */
    public static function lang_switcher( ?string $current = null ): string {
        $current = $current ?: self::resolve_lang();
        $row = '<div class="m24b2b-lang" aria-label="Sprache wählen: Deutsch / English">';
        foreach ( array( 'de' => self::flag_de(), 'en' => self::flag_en() ) as $l => $svg ) {
            $label = 'de' === $l ? 'Deutsch' : 'English';
            if ( $l === $current ) {
                $row .= '<span class="m24-flag active" title="' . esc_attr( $label ) . '">' . $svg . '</span>';
            } else {
                $url = esc_url( add_query_arg( 'lang', $l ) );
                $row .= '<a class="m24-flag" href="' . $url . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '">' . $svg . '</a>';
            }
        }
        return $row . '</div>';
    }

    /* ── String-Registry ─────────────────────────────────────────────────── */

    public static function strings(): array {
        return array(
            'de' => array(
                // Registrierung
                'reg_h1'        => 'Händler-Registrierung',
                'reg_sub'       => 'Für den Zugang zu Händlerpreisen. Wir prüfen Ihre Angaben und schalten Sie frei.',
                'f_firma'       => 'Firma',
                'f_anrede'      => 'Anrede',
                'anrede_herr'   => 'Herr',
                'anrede_frau'   => 'Frau',
                'anrede_divers' => 'Divers',
                'f_land'        => 'Land',
                'f_vorname'     => 'Vorname',
                'f_nachname'    => 'Nachname',
                'f_email'       => 'E-Mail',
                'f_telefon'     => 'Telefon',
                'f_uid'         => 'USt-IdNr.',
                'uid_hint'      => '(Pflicht in der EU)',
                'uid_ph'        => 'z. B. DE123456789',
                'consent_ds'    => 'Ich habe die %s gelesen und stimme der Verarbeitung meiner Daten zur Bearbeitung von Registrierung und Anfragen zu.',
                'consent_ds_link' => 'Datenschutzerklärung',
                'consent_agb'   => 'Ich erkenne die %s an.',
                'consent_agb_link' => 'AGB',
                'submit_reg'    => 'Registrierung absenden',
                'reg_ok_title'  => 'Fast geschafft!',
                'reg_ok_text'   => 'Wir haben dir eine E-Mail geschickt. Bitte bestätige deine Registrierung über den Link darin (15 Minuten gültig).',
                'reg_err'       => 'Bitte fülle alle Pflichtfelder korrekt aus (inkl. gültiger USt-IdNr. bei EU-Ländern) und stimme Datenschutz & AGB zu.',
                'req'           => '*',
                // Login
                'login_h1'      => 'Händler-Login',
                'login_sub'     => 'Gib deine E-Mail ein — wir schicken dir einen Login-Link. Kein Passwort nötig.',
                'submit_login'  => 'Login-Link anfordern',
                'login_ok_title'=> 'Check deine Mails',
                'login_ok_text' => 'Wir haben dir einen Login-Link geschickt (15 Minuten gültig).',
                'login_err_link'=> 'Link ungültig oder abgelaufen — fordere einen neuen an.',
                // VIES (Frontend-Feedback)
                'vies_checking' => 'Prüfe USt-IdNr. …',
                'vies_ok'       => '✓ USt-IdNr. gültig',
                'vies_bad'      => '✗ USt-IdNr. nicht gültig (VIES)',
                'vies_na'       => 'konnte gerade nicht geprüft werden',
                // Mails (für nächsten Chunk vorbereitet — Versand bleibt vorerst DE)
                'mail_verify_subject'  => 'Bitte bestätige deine Registrierung – MOTORSPORT24',
                'mail_verify_headline' => 'Registrierung bestätigen',
                'mail_verify_body'     => 'Klicke zum Bestätigen deiner Händler-Registrierung. Der Link ist 15 Minuten gültig.',
                'mail_verify_button'   => 'Registrierung bestätigen',
                'mail_login_subject'   => 'Dein Login-Link – MOTORSPORT24',
                'mail_login_headline'  => 'Login bei MOTORSPORT24',
                'mail_login_body'      => 'Klicke, um dich einzuloggen. Der Link ist 15 Minuten gültig.',
                'mail_login_button'    => 'Jetzt einloggen',
                'mail_approval_subject'=> 'Dein Händler-Zugang ist freigeschaltet – MOTORSPORT24',
                'mail_approval_headline'=> 'Zugang freigeschaltet',
                'mail_approval_body'   => 'Deine Registrierung wurde geprüft und freigeschaltet. Du kannst dich jetzt einloggen und siehst Händlerpreise.',
                'mail_approval_button' => 'Zum Login',
                'mail_rejection_subject'=> 'Zu deiner Händler-Registrierung – MOTORSPORT24',
                'mail_rejection_headline'=> 'Zu deiner Registrierung',
                'mail_rejection_body'  => 'Wir konnten deine Registrierung für den Händlerbereich aktuell nicht freischalten.',
                'mail_rejection_reason'=> 'Grund',
                'mail_rejection_outro' => 'Falls das ein Irrtum ist oder du Angaben ergänzen möchtest, melde dich gern unter %s.',
                'mail_footer_tagline'  => 'Classic & Race Cars and Parts Sales since 2006',
                'mail_footer_imprint'  => 'Impressum',
                'mail_footer_privacy'  => 'Datenschutz',
                'mail_hello'           => 'Hallo %s,',
            ),
            'en' => array(
                'reg_h1'        => 'Dealer registration',
                'reg_sub'       => 'For access to dealer pricing. We review your details and approve your account.',
                'f_firma'       => 'Company',
                'f_anrede'      => 'Salutation',
                'anrede_herr'   => 'Mr',
                'anrede_frau'   => 'Ms',
                'anrede_divers' => 'Diverse',
                'f_land'        => 'Country',
                'f_vorname'     => 'First name',
                'f_nachname'    => 'Last name',
                'f_email'       => 'Email',
                'f_telefon'     => 'Phone',
                'f_uid'         => 'VAT ID',
                'uid_hint'      => '(required in the EU)',
                'uid_ph'        => 'e.g. DE123456789',
                // ENTWURF — juristisch zu prüfen; verlinkt vorerst die deutschen Seiten.
                'consent_ds'    => 'I have read the %s and consent to the processing of my data for handling my registration and enquiries.',
                'consent_ds_link' => 'Privacy Policy (in German)',
                'consent_agb'   => 'I accept the %s.',
                'consent_agb_link' => 'Terms & Conditions (AGB, in German)',
                'submit_reg'    => 'Submit registration',
                'reg_ok_title'  => 'Almost done!',
                'reg_ok_text'   => 'We have sent you an email. Please confirm your registration via the link inside (valid for 15 minutes).',
                'reg_err'       => 'Please complete all required fields correctly (incl. a valid VAT ID for EU countries) and accept the Privacy Policy & Terms.',
                'req'           => '*',
                'login_h1'      => 'Dealer login',
                'login_sub'     => 'Enter your email — we will send you a login link. No password required.',
                'submit_login'  => 'Request login link',
                'login_ok_title'=> 'Check your inbox',
                'login_ok_text' => 'We have sent you a login link (valid for 15 minutes).',
                'login_err_link'=> 'Link invalid or expired — please request a new one.',
                'vies_checking' => 'Checking VAT ID …',
                'vies_ok'       => '✓ VAT ID valid',
                'vies_bad'      => '✗ VAT ID not valid (VIES)',
                'vies_na'       => 'could not be checked right now',
                'mail_verify_subject'  => 'Please confirm your registration – MOTORSPORT24',
                'mail_verify_headline' => 'Confirm registration',
                'mail_verify_body'     => 'Click to confirm your dealer registration. The link is valid for 15 minutes.',
                'mail_verify_button'   => 'Confirm registration',
                'mail_login_subject'   => 'Your login link – MOTORSPORT24',
                'mail_login_headline'  => 'Log in to MOTORSPORT24',
                'mail_login_body'      => 'Click to log in. The link is valid for 15 minutes.',
                'mail_login_button'    => 'Log in now',
                'mail_approval_subject'=> 'Your dealer access is approved – MOTORSPORT24',
                'mail_approval_headline'=> 'Access approved',
                'mail_approval_body'   => 'Your registration has been reviewed and approved. You can now log in and see dealer pricing.',
                'mail_approval_button' => 'Go to login',
                'mail_rejection_subject'=> 'Regarding your dealer registration – MOTORSPORT24',
                'mail_rejection_headline'=> 'Regarding your registration',
                'mail_rejection_body'  => 'We were unable to approve your dealer registration at this time.',
                'mail_rejection_reason'=> 'Reason',
                'mail_rejection_outro' => 'If this is a mistake or you would like to add information, feel free to contact us at %s.',
                'mail_footer_tagline'  => 'Classic & Race Cars and Parts Sales since 2006',
                'mail_footer_imprint'  => 'Imprint',
                'mail_footer_privacy'  => 'Privacy Policy',
                'mail_hello'           => 'Hello %s,',
            ),
        );
    }

    /** Ablehngründe key ⇒ Label je Sprache (Mail nutzt Empfänger-Sprache; Admin/notes_intern = de). */
    public static function reject_reasons( ?string $lang = null ): array {
        $lang = $lang ?: self::resolve_lang();
        $de = array(
            'gewerbe'    => 'Keine gewerbliche Tätigkeit feststellbar',
            'uid'        => 'USt-IdNr. ungültig / nicht verifizierbar',
            'daten'      => 'Angaben unvollständig oder unplausibel',
            'dublette'   => 'Bereits registriert (Dublette)',
            'sortiment'  => 'Sortiment/Branche passt nicht',
            'missbrauch' => 'Verdacht auf Missbrauch/Spam',
            'sonstiges'  => 'Sonstiges',
        );
        $en = array(
            'gewerbe'    => 'No commercial/business activity could be established',
            'uid'        => 'VAT ID invalid / not verifiable',
            'daten'      => 'Details incomplete or implausible',
            'dublette'   => 'Already registered (duplicate)',
            'sortiment'  => 'Product range/industry does not fit',
            'missbrauch' => 'Suspected misuse/spam',
            'sonstiges'  => 'Other',
        );
        return 'en' === $lang ? $en : $de;
    }
}
