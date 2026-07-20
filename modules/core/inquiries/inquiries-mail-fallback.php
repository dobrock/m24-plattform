<?php
/**
 * Inquiries — Mail-Fallback (Modul D.2)
 *
 * Lauscht auf den Action-Hook `m24_inquiry_mail_fallback`, der von
 * M24_Inquiries_Push bei 4xx-Antworten oder von M24_Inquiries_Retry_Job
 * (D.3) nach Erschoepfung der Retries gefeuert wird. Baut aus dem
 * Inquiry-CPT eine formattierte HTML-Mail an die in den Settings
 * konfigurierte Fallback-Adresse, damit das Vertriebsteam die Anfrage
 * trotzdem bearbeiten kann.
 *
 * Hook-Schnittstelle:
 *   do_action( 'm24_inquiry_mail_fallback', int $post_id, string $reason );
 *
 * Reason-Beispiele:
 *   - "http_400: validation_failed"
 *   - "http_404: not_found"
 *   - "http_403: forbidden"
 *   - "mapping_failed"
 *   - "max_retries_exhausted" (D.3)
 *
 * Postmeta-Schreibe bei Erfolg:
 *   _m24_mail_fallback_sent_at  (string)  ISO-Timestamp
 *   _m24_mail_fallback_reason   (string)  reason-String wie uebergeben
 *   _m24_mail_fallback_to       (string)  effektiv genutzte Empfaengeradresse
 *
 * CPT-Status nach Erfolg: M24_Inquiries::STATUS_SYNCED_MAIL ('synced_via_mail')
 *
 * Spec-Referenz: M24-Master-Spec-v4.md §6.x (Fallback-Mail), Uebergabe v11 §4.1
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries_Mail_Fallback {

    const HOOK_NAME = 'm24_inquiry_mail_fallback';

    /** Reasons der Front-End-Mails (alle gehen an fallback_mail_to = service@). */
    const REASON_NOTIFY     = 'neue_anfrage';   // Warenkorb-Submit (Push laeuft zusaetzlich)
    const REASON_PRODUCT    = 'produktanfrage'; // Modal "Frage stellen" (E-Mail only, kein Push)
    const REASON_MERKZETTEL = 'merkzettel';     // Warenkorb "Per E-Mail" (E-Mail only)

    /** Front-End-Benachrichtigung (kein echter Push-Fallback)? */
    private static function is_notification( $reason ) {
        return in_array( $reason, array( self::REASON_NOTIFY, self::REASON_PRODUCT, self::REASON_MERKZETTEL ), true );
    }

    /** Einleitungssatz je Reason (gelber/blauer Hinweisblock). */
    private static function intro_for( $reason ) {
        if ( self::REASON_PRODUCT === $reason ) {
            return 'Direkte Produktanfrage über „Frage stellen". Bitte direkt beantworten (Antwort geht an die anfragende Person).';
        }
        if ( self::REASON_MERKZETTEL === $reason ) {
            return 'Per E-Mail gesendeter Merkzettel eines Website-Besuchers. Bitte direkt beantworten.';
        }
        // Desk-Push-Hinweis nur zeigen, wenn der Desk tatsächlich angebunden ist (Konstante gesetzt) — sonst
        // wäre er irreführend. Ohne Desk: kein Erklärtext (die Box wird dann gar nicht gerendert).
        $has_desk = defined( 'M24_DESK_API_TOKEN' ) && '' !== (string) M24_DESK_API_TOKEN;
        return $has_desk ? 'Neue Anfrage über die Website. Der automatische Push ans M24-Desk läuft bereits — diese Mail ist eine Info-Kopie.' : '';
    }

    /** @var bool Schutz gegen doppelte Init */
    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_action( self::HOOK_NAME, [ __CLASS__, 'handle' ], 10, 2 );
        // Benachrichtigungs-Mail bei JEDER Anfrage (Push bleibt primaer). Teilt das
        // Idempotenz-Flag mit handle() -> im echten Fallback-Fall keine zweite Mail.
        add_action( 'm24_inquiry_created', [ __CLASS__, 'notify' ], 10, 1 );
    }

    /**
     * Benachrichtigungs-Mail an fallback_mail_to bei Anfrage-Eingang.
     * Wiederverwendet den bestehenden Mail-Builder (collect/build_*), aendert
     * aber NICHT den CPT-Status (Push bleibt der primaere Uebergabeweg).
     *
     * @param int $post_id
     */
    public static function notify( $post_id ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'm24_inquiry' ) {
            return;
        }
        // Eine Mail pro Anfrage: teilt das Flag mit dem Fallback handle().
        if ( '' !== (string) get_post_meta( $post_id, '_m24_mail_fallback_sent_at', true ) ) {
            return;
        }

        $settings = wp_parse_args( get_option( M24_Settings::OPTION_KEY, [] ), M24_Settings::defaults() );
        $to = isset( $settings['fallback_mail_to'] ) ? sanitize_email( (string) $settings['fallback_mail_to'] ) : '';
        if ( '' === $to || ! is_email( $to ) ) {
            self::log_error( $post_id, 'Benachrichtigung: keine gueltige fallback_mail_to konfiguriert', [] );
            return;
        }

        $sent = self::compose_and_send( self::collect_inquiry_data( $post ), self::REASON_NOTIFY, $to );
        if ( $sent ) {
            update_post_meta( $post_id, '_m24_mail_fallback_sent_at', gmdate( 'c' ) );
            update_post_meta( $post_id, '_m24_mail_fallback_reason',  self::REASON_NOTIFY );
            update_post_meta( $post_id, '_m24_mail_fallback_to',      $to );
            self::log_info( $post_id, 'Benachrichtigungs-Mail gesendet', [ 'to' => $to ] );
        } else {
            self::log_error( $post_id, 'Benachrichtigungs-Mail: wp_mail() returned false', [ 'to' => $to ] );
        }
    }

    /**
     * CPT-lose Front-End-Mail an fallback_mail_to (service@): „Frage stellen"
     * (REASON_PRODUCT) + Warenkorb „Per E-Mail" (REASON_MERKZETTEL). Reused
     * Builder, KEIN CPT, KEIN Push.
     *
     * @param array  $in     Validiertes/aufbereitetes Eingabe-Array (Kontakt + items)
     * @param string $reason REASON_PRODUCT|REASON_MERKZETTEL
     * @return bool
     */
    public static function send_data( array $in, $reason ) {
        $settings = wp_parse_args( get_option( M24_Settings::OPTION_KEY, [] ), M24_Settings::defaults() );
        $to = isset( $settings['fallback_mail_to'] ) ? sanitize_email( (string) $settings['fallback_mail_to'] ) : '';
        if ( '' === $to || ! is_email( $to ) ) {
            self::log_error( 0, 'send_data: keine gueltige fallback_mail_to konfiguriert', [ 'reason' => $reason ] );
            return false;
        }
        return self::compose_and_send( self::normalize_data( $in ), (string) $reason, $to );
    }

    /** Vorschau/Test-Versand der „Neue Anfrage"-Betreiber-Mail (Admin-Tool) — echtes build_html_body. */
    public static function preview_notification( $to ) {
        if ( ! is_email( $to ) ) { return false; }
        $data = self::normalize_data( [
            'anrede' => 'Herr', 'vorname' => 'Max', 'nachname' => 'Mustermann',
            'email' => 'max.mustermann@example.com', 'tel' => '+49 30 1234567',
            'firma' => 'Muster Motorsport GmbH', 'plz' => '13595', 'ort' => 'Berlin', 'land' => 'DE', 'biz' => '1',
            'notes' => 'Beispiel-Anfrage aus dem Vorschau-Tool.',
            'inquiry_source' => 'cart',
            'items' => [
                [ 'art' => 'Bremsscheibe vorn (Muster)', 'qty' => 2, 'price' => '149,90 €', 'src_url' => home_url( '/' ), 'src_art_nr' => 'ART-1001' ],
                [ 'art' => 'Sportfahrwerk-Kit (Muster)', 'qty' => 1, 'price' => '1.290,00 €', 'src_url' => home_url( '/' ), 'src_art_nr' => 'ART-2002' ],
            ],
        ] );
        $subject = '[TEST] ' . self::build_subject( $data, self::REASON_NOTIFY );
        $body    = self::build_html_body( $data, self::REASON_NOTIFY );
        return (bool) wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    /** Validiertes Eingabe-Array -> Builder-Daten-Shape (wie collect_inquiry_data). */
    private static function normalize_data( array $in ) {
        $biz = isset( $in['biz'] ) ? (string) $in['biz'] : '';
        return [
            'post_id'    => 0,
            'post_title' => '',
            'post_date'  => current_time( 'mysql' ),
            'edit_link'  => false,
            'anrede'     => (string) ( $in['anrede']   ?? '' ),
            'vorname'    => (string) ( $in['vorname']  ?? '' ),
            'nachname'   => (string) ( $in['nachname'] ?? '' ),
            'email'      => (string) ( $in['email']    ?? '' ),
            'tel'        => (string) ( $in['tel']      ?? '' ),
            'firma'      => (string) ( $in['firma']    ?? '' ),
            'strasse'    => (string) ( $in['strasse']  ?? '' ),
            'plz'        => (string) ( $in['plz']      ?? '' ),
            'ort'        => (string) ( $in['ort']      ?? '' ),
            'land'       => (string) ( $in['land']     ?? '' ),
            'uid'        => (string) ( $in['uid']      ?? '' ),
            'biz'        => ( '1' === $biz ) ? '1' : '',
            'items'      => ( isset( $in['items'] ) && is_array( $in['items'] ) ) ? $in['items'] : [],
            'source'     => (string) ( $in['inquiry_source'] ?? ( $in['source'] ?? '' ) ),
            'source_meta'=> ( isset( $in['inquiry_source_meta'] ) && is_array( $in['inquiry_source_meta'] ) ) ? $in['inquiry_source_meta'] : [],
            'notes'      => (string) ( $in['notes'] ?? '' ),
            'push_attempts' => 0, 'push_last_status' => 0, 'push_last_error' => '', 'idempotency_key' => '',
        ];
    }

    /** Gemeinsamer Sende-Pfad (Builder + Reply-To + wp_mail). */
    private static function compose_and_send( array $data, $reason, $to ) {
        $subject = self::build_subject( $data, $reason );
        $body    = self::build_html_body( $data, $reason );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        // Display-Name für Mail-Header säubern: NICHT selbst in "…" quoten — sonst quotet PHPMailer (via
        // wp_mail) den bereits gequoteten Namen erneut und escaped die Anführungszeichen → \"Name\". Statt-
        // dessen die Header-Breaker (", \, ,, <, >) entfernen und den blanken Namen übergeben; PHPMailer
        // quotet RFC-5322-konform selbst, falls nötig. wp_unslash entfernt evtl. Slash-Artefakte.
        $clean_name = static function ( $n ) {
            $n = trim( wp_unslash( (string) $n ) );
            $n = str_replace( array( '"', '\\', ',', '<', '>' ), ' ', $n );
            return trim( (string) preg_replace( '/\s+/', ' ', $n ) );
        };
        // Produktanfrage: From-Name = Kundenname, System-Absenderadresse (wp_mail_from) beibehalten.
        if ( self::REASON_PRODUCT === $reason ) {
            $from_name = $clean_name( $data['vorname'] . ' ' . $data['nachname'] );
            $from_name = '' !== $from_name ? $from_name : 'MOTORSPORT24';
            $host = preg_replace( '#^www\.#i', '', (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
            $system_from = apply_filters( 'wp_mail_from', 'wordpress@' . $host );
            $headers[]   = 'From: ' . $from_name . ' <' . $system_from . '>';
        }
        if ( '' !== $data['email'] && is_email( $data['email'] ) ) {
            $rn = $clean_name( $data['vorname'] . ' ' . $data['nachname'] );
            $headers[] = 'Reply-To: ' . ( '' !== $rn ? $rn . ' <' . $data['email'] . '>' : $data['email'] );
        }
        // Angebots-Workflow: Operator-CTA („Angebot erstellen") in die interne Mail — nur wenn Angebote aktiv.
        if ( class_exists( 'M24_Offers' ) && M24_Offers::enabled() ) {
            $first   = ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) ? (array) reset( $data['items'] ) : array();
            $op_data = array(
                'inquiry_id' => (int) ( $data['post_id'] ?? 0 ), // → Operator lädt die Positionen via ?from_inquiry
                'email'      => (string) $data['email'],
                'name'       => trim( (string) $data['vorname'] . ' ' . (string) $data['nachname'] ),
                // Kundentyp aus 'biz' (1/0 — so speichert die Anfrage) ableiten; 'kundentyp' existiert im Anfrage-
                // Datensatz nicht → sonst fiele der CTA-Link (ohne from_inquiry) fälschlich auf b2c.
                'kundentyp'  => ( '1' === (string) ( $data['biz'] ?? '' ) || in_array( (string) ( $data['kundentyp'] ?? '' ), array( 'Geschäftskunde', 'b2b' ), true ) ) ? 'b2b' : 'b2c',
                'land'       => (string) ( $data['land'] ?? '' ),
                'src_modell' => (string) ( $first['src_modell'] ?? '' ),
                'src_pid'    => (string) ( $first['src_pid'] ?? '' ),
                'src_pillar' => (string) ( $first['src_pillar'] ?? '' ),
                'src_url'    => (string) ( $first['src_url'] ?? '' ),
                'src_lang'   => (string) ( $data['lang'] ?? '' ),
            );
            $links = apply_filters( 'm24_inquiry_operator_links', array(), $op_data );
            if ( ! empty( $links ) ) {
                $cta = '<div style="text-align:center;margin:18px 0;">';
                foreach ( $links as $l ) {
                    $cta .= '<a href="' . esc_url( $l['url'] ) . '" style="display:inline-block;background:#9a6b25;color:#fff;text-decoration:none;font-weight:700;padding:11px 22px;border-radius:8px;margin:4px;">' . esc_html( $l['label'] ) . '</a>';
                }
                $cta .= '</div>';
                $body = ( false !== strpos( $body, '</body>' ) ) ? str_replace( '</body>', $cta . '</body>', $body ) : $body . $cta;
            }
        }
        return (bool) wp_mail( $to, $subject, $body, $headers );
    }

    // ────────────────────────────────────────────────────────────────────
    // Hook-Handler
    // ────────────────────────────────────────────────────────────────────

    /**
     * Haupt-Einstieg: laedt das Inquiry, baut die Mail, sendet sie ab,
     * aktualisiert Status + Postmeta, schreibt Logger-Eintrag.
     *
     * @param int    $post_id
     * @param string $reason
     */
    public static function handle( $post_id, $reason ) {
        $post_id = (int) $post_id;
        $reason  = (string) $reason;

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'm24_inquiry' ) {
            self::log_error( $post_id, 'Mail-Fallback: Post nicht gefunden oder falscher Typ', [
                'reason' => $reason,
            ] );
            return;
        }

        // Idempotenz: wenn schon eine Fallback-Mail rausgegangen ist, nicht nochmal.
        $already = (string) get_post_meta( $post_id, '_m24_mail_fallback_sent_at', true );
        if ( $already !== '' ) {
            self::log_warning( $post_id, 'Mail-Fallback bereits gesendet — uebersprungen', [
                'reason'        => $reason,
                'first_sent_at' => $already,
            ] );
            return;
        }

        // Empfaenger aus Settings.
        $settings = wp_parse_args(
            get_option( M24_Settings::OPTION_KEY, [] ),
            M24_Settings::defaults()
        );
        $to = isset( $settings['fallback_mail_to'] ) ? sanitize_email( (string) $settings['fallback_mail_to'] ) : '';
        if ( $to === '' || ! is_email( $to ) ) {
            self::log_error( $post_id, 'Mail-Fallback: keine gueltige fallback_mail_to-Adresse konfiguriert', [
                'reason'        => $reason,
                'configured_to' => $settings['fallback_mail_to'] ?? '(leer)',
            ] );
            return;
        }

        // Builder + Versand (gemeinsamer Pfad mit notify()/send_data()).
        $sent = self::compose_and_send( self::collect_inquiry_data( $post ), $reason, $to );

        if ( ! $sent ) {
            self::log_error( $post_id, 'Mail-Fallback: wp_mail() returned false', [
                'reason' => $reason,
                'to'     => $to,
            ] );
            return;
        }

        // Status + Postmeta updaten.
        self::mark_synced_via_mail( $post_id, $reason, $to );

        self::log_info( $post_id, 'Mail-Fallback erfolgreich gesendet', [
            'reason' => $reason,
            'to'     => $to,
            'subject' => $subject,
        ] );
    }

    // ────────────────────────────────────────────────────────────────────
    // Datensammlung
    // ────────────────────────────────────────────────────────────────────

    /**
     * Liest alle relevanten Felder aus dem Inquiry-CPT — analog zu
     * M24_Inquiries_Push::build_payload(), aber ohne Pflichtfeld-Validation
     * (eine Mail an Vertrieb soll auch bei unvollstaendigen Daten rausgehen).
     *
     * @param WP_Post $post
     * @return array
     */
    private static function collect_inquiry_data( $post ) {
        $post_id = (int) $post->ID;

        $get = function( $key ) use ( $post_id ) {
            return (string) get_post_meta( $post_id, '_m24_' . $key, true );
        };

        $items_raw = get_post_meta( $post_id, '_m24_items', true );
        if ( ! is_array( $items_raw ) ) {
            $items_raw = [];
        }

        $source_meta = [];
        $source_meta_json = (string) get_post_meta( $post_id, '_m24_inquiry_source_meta', true );
        if ( $source_meta_json !== '' ) {
            $decoded = json_decode( $source_meta_json, true );
            if ( is_array( $decoded ) ) {
                $source_meta = $decoded;
            }
        }

        return [
            'post_id'      => $post_id,
            'post_title'   => (string) $post->post_title,
            'post_date'    => (string) $post->post_date,
            'edit_link'    => get_edit_post_link( $post_id, '' ),
            // Kontakt
            'anrede'       => $get( 'anrede' ),
            'vorname'      => $get( 'vorname' ),
            'nachname'     => $get( 'nachname' ),
            'email'        => $get( 'email' ),
            'tel'          => $get( 'tel' ),
            'firma'        => $get( 'firma' ),
            'strasse'      => $get( 'strasse' ),
            'plz'          => $get( 'plz' ),
            'ort'          => $get( 'ort' ),
            'land'         => $get( 'land' ),
            'uid'          => $get( 'uid' ),
            'biz'          => $get( 'biz' ),
            // Inquiry
            'items'        => $items_raw,
            'source'       => $get( 'inquiry_source' ),
            'source_meta'  => $source_meta,
            'notes'        => (string) $post->post_content,
            // Push-Diagnostik (fuer Vertrieb hilfreich, im "Warum-Mail-Block")
            'push_attempts'    => (int) get_post_meta( $post_id, '_m24_push_attempts', true ),
            'push_last_status' => (int) get_post_meta( $post_id, '_m24_push_last_status', true ),
            'push_last_error'  => (string) get_post_meta( $post_id, '_m24_push_last_error', true ),
            'idempotency_key'  => (string) get_post_meta( $post_id, '_m24_idempotency_key', true ),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Mail-Komposition
    // ────────────────────────────────────────────────────────────────────

    private static function build_subject( $data, $reason ) {
        $name = trim( $data['vorname'] . ' ' . $data['nachname'] );
        if ( $name === '' ) {
            $name = $data['email'] !== '' ? $data['email'] : sprintf( 'Anfrage #%d', $data['post_id'] );
        }
        if ( self::REASON_PRODUCT === $reason ) {
            $pt = isset( $data['items'][0]['art'] ) ? trim( (string) $data['items'][0]['art'] ) : '';
            return 'Neue Frage zu ' . ( '' !== $pt ? $pt : 'einem Artikel' );
        }
        if ( self::REASON_MERKZETTEL === $reason ) {
            return sprintf( '[M24 Plattform] Merkzettel-Anfrage von %s', $name );
        }
        if ( self::REASON_NOTIFY === $reason ) {
            return sprintf( '[M24 Plattform] Neue Anfrage von %s', $name );
        }
        return sprintf( '[M24 Plattform] Sammelanfrage von %s — %s', $name, $reason );
    }

    /**
     * Produktanfrage („Frage stellen") → „Variante A"-Mail (kundenfreundlich, gebrandet).
     * Mappt das Builder-Daten-Shape auf m24_render_inquiry_email(). NUR Produkt-Route.
     */
    private static function render_product_email( array $data ) {
        $name       = trim( $data['vorname'] . ' ' . $data['nachname'] );
        $positionen = array();
        foreach ( (array) $data['items'] as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $artnr = '' !== (string) ( $item['src_art_nr'] ?? '' )
                ? (string) $item['src_art_nr']
                : (string) ( $item['src_pid'] ?? '' );
            $positionen[] = array(
                'titel'         => (string) ( $item['art'] ?? '' ),
                'menge'         => (int) ( $item['qty'] ?? 1 ),
                'preis'         => (string) ( $item['price'] ?? '' ),
                'link'          => (string) ( $item['src_url'] ?? '' ),
                'artikelnummer' => $artnr,
            );
        }
        return m24_render_inquiry_email( array(
            'titel'      => 'Neue Teileanfrage',
            'name'       => $name,
            'firma'      => (string) $data['firma'],
            'email'      => (string) $data['email'],
            'land'       => (string) $data['land'],            // ISO2 → im Template ausgeschrieben
            'kundentyp'  => ( '1' === (string) $data['biz'] ) ? 'business' : 'private',
            'positionen' => $positionen,
            'nachricht'  => (string) ( $data['notes'] ?? '' ),
            'anfrage_id' => (int) ( $data['post_id'] ?? 0 ),
            'datum_ts'   => time(),
        ) );
    }

    private static function build_html_body( $data, $reason ) {
        // Produktanfrage: „Variante A"-Template (kundenfrontend, separater Zweck — bleibt).
        if ( self::REASON_PRODUCT === $reason && function_exists( 'm24_render_inquiry_email' ) ) {
            return self::render_product_email( $data );
        }
        $name = trim( $data['vorname'] . ' ' . $data['nachname'] );

        // Front-End-Benachrichtigung (notify/merkzettel) vs. echter Push-Fallback.
        $is_note = self::is_notification( $reason );

        // Inner-Body (ohne Chrome) — wird von der EINEN kanonischen Shell (m24_mail_shell) umschlossen.
        ob_start();
        ?>
<?php // Kein eigener Titel hier: der EINE Titel steht in der Mail-Shell-Headline (m24_mail_shell) → keine Dopplung. ?>
<?php if ( ! $is_note ) : ?>
<div style="font-size:12px;margin:0 0 12px;color:#7e8794;">Grund: <code style="background:#f2f4f7;padding:1px 6px;border-radius:3px;color:#3a414c;"><?php echo esc_html( $reason ); ?></code></div>
<?php endif; ?>

<?php
// Erklär-Box nur, wenn es etwas zu erklären gibt (Desk-Push-Hinweis nur bei angebundenem Desk → sonst leer).
$intro_txt = $is_note ? self::intro_for( $reason ) : 'Diese Anfrage konnte nicht automatisch ans M24-Desk übergeben werden. Die Daten werden hier per Mail bereitgestellt, damit ihr sie manuell bearbeiten könnt.';
?>
<?php if ( '' !== $intro_txt ) : ?>
<div style="padding:14px 16px;margin:8px 0 12px;background:<?php echo $is_note ? '#eef4fb' : '#fdf6e3'; ?>;border-radius:6px;font-size:13px;color:<?php echo $is_note ? '#1b3a5a' : '#5a4a1a'; ?>;"><?php echo esc_html( $intro_txt ); ?></div>
<?php endif; ?>
<p style="font-size:12.5px;color:#7e8794;margin:2px 0 18px;">Antworten an diese Mail gehen direkt an die anfragende Person<?php echo $data['email'] !== '' ? ' (<a href="mailto:' . esc_attr( $data['email'] ) . '" style="color:#0073aa;">' . esc_html( $data['email'] ) . '</a>)' : ''; ?>.</p>

<!-- Kontaktdaten -->
<div style="border-top:1px solid #eee;padding-top:14px;">
<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:8px;">Kontakt</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;">
<?php
$contact_rows = [
    'Anrede'    => $data['anrede'],
    'Name'      => $name,
    'Firma'     => $data['firma'],
    'E-Mail'    => $data['email'],
    'Telefon'   => $data['tel'],
    'Strasse'   => $data['strasse'],
    'PLZ / Ort' => trim( trim( $data['plz'] . ' ' . $data['ort'] ) ),
    'Land'      => ( '' !== (string) $data['land'] && function_exists( 'm24_inquiry_country_name' ) ) ? m24_inquiry_country_name( (string) $data['land'] ) : (string) $data['land'],
    'USt-IdNr.' => $data['uid'],
    'Kunde'     => $data['biz'] === '1' ? 'B2B' : 'B2C',
];
foreach ( $contact_rows as $label => $value ) :
    if ( $value === '' || $value === null ) {
        continue;
    }
?>
<tr>
<td style="padding:4px 0;width:120px;color:#888;vertical-align:top;"><?php echo esc_html( $label ); ?></td>
<td style="padding:4px 0;color:#222;"><?php
    if ( $label === 'E-Mail' && is_email( $value ) ) {
        echo '<a href="mailto:' . esc_attr( $value ) . '" style="color:#0073aa;">' . esc_html( $value ) . '</a>';
    } else {
        echo esc_html( $value );
    }
?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<!-- Items -->
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;">
<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:8px;">Positionen (<?php echo count( $data['items'] ); ?>)</div>
<?php if ( empty( $data['items'] ) ) : ?>
<div style="color:#c0392b;font-size:13px;font-style:italic;">Keine Positionen im Postmeta gefunden.</div>
<?php else : ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;border-collapse:collapse;">
<thead>
<tr style="background:#f7f7f7;">
<th align="left" style="padding:8px;border-bottom:1px solid #ddd;font-weight:600;color:#555;">Bezeichnung</th>
<th align="right" style="padding:8px;border-bottom:1px solid #ddd;font-weight:600;color:#555;width:60px;">Menge</th>
<th align="right" style="padding:8px;border-bottom:1px solid #ddd;font-weight:600;color:#555;width:100px;">Preis</th>
</tr>
</thead>
<tbody>
<?php foreach ( $data['items'] as $idx => $item ) :
    if ( ! is_array( $item ) ) { continue; }
    $art   = isset( $item['art'] )   ? (string) $item['art']   : '';
    $qty   = isset( $item['qty'] )   ? $item['qty']            : '';
    $price = isset( $item['price'] ) ? (string) $item['price'] : '';

    // Source-Meta des Items (alles, was mit src_ beginnt)
    $src_fields = [];
    foreach ( $item as $k => $v ) {
        if ( is_string( $k ) && strpos( $k, 'src_' ) === 0 && $v !== '' && $v !== null ) {
            $src_fields[ $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
        }
    }
?>
<tr>
<td style="padding:8px;border-bottom:1px solid #f0f0f0;vertical-align:top;">
<?php echo esc_html( $art !== '' ? $art : '(leer)' ); ?>
<?php if ( ! empty( $src_fields ) ) : ?>
<div style="font-size:11px;color:#888;margin-top:4px;">
<?php
$labels = array( 'src_art_nr' => 'Artikelnummer', 'src_variant' => 'Variante' );
$pairs  = [];
foreach ( $src_fields as $k => $v ) {
    if ( 'src_url' === $k ) { continue; } // Shop-Link nicht in der internen Position ausweisen
    $lbl     = isset( $labels[ $k ] ) ? $labels[ $k ] : $k;
    $pairs[] = esc_html( $lbl ) . ': ' . esc_html( mb_substr( $v, 0, 80 ) );
}
echo implode( ' &middot; ', $pairs );
?>
</div>
<?php endif; ?>
</td>
<td align="right" style="padding:8px;border-bottom:1px solid #f0f0f0;vertical-align:top;"><?php echo esc_html( (string) $qty ); ?></td>
<td align="right" style="padding:8px;border-bottom:1px solid #f0f0f0;vertical-align:top;"><?php echo esc_html( $price !== '' ? $price : 'auf Anfrage' ); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<!-- Source (lesbar, kein JSON-Dump) -->
<?php
$origin = '';
if ( ! empty( $data['source_meta'] ) && is_array( $data['source_meta'] ) && ! empty( $data['source_meta']['origin'] ) ) {
    $origin = (string) $data['source_meta']['origin'];
}
$origin_map = array( 'guest_garage' => 'Gast-Garage', 'garage' => 'Kunden-Garage' );
$quelle     = isset( $origin_map[ $origin ] ) ? $origin_map[ $origin ] : ( '' !== $origin ? $origin : (string) $data['source'] );
?>
<?php if ( '' !== $quelle ) : ?>
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;font-size:13px;color:#222;">
<span style="color:#888;">Quelle:</span> <strong><?php echo esc_html( $quelle ); ?></strong>
</div>
<?php endif; ?>

<!-- Notizen -->
<?php if ( $data['notes'] !== '' ) : ?>
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;">
<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:8px;">Anmerkungen</div>
<div style="font-size:13px;white-space:pre-wrap;color:#222;"><?php echo esc_html( $data['notes'] ); ?></div>
</div>
<?php endif; ?>

<?php if ( ! $is_note ) : ?>
<!-- Push-Diagnostik -->
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;background:#fafafa;">
<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:8px;">Push-Diagnostik</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:12px;color:#555;">
<tr><td style="padding:2px 0;width:160px;color:#888;">Versuche</td><td style="padding:2px 0;"><?php echo (int) $data['push_attempts']; ?></td></tr>
<tr><td style="padding:2px 0;color:#888;">Letzter HTTP-Status</td><td style="padding:2px 0;"><?php echo (int) $data['push_last_status']; ?></td></tr>
<?php if ( $data['push_last_error'] !== '' ) : ?>
<tr><td style="padding:2px 0;color:#888;vertical-align:top;">Letzter Fehler</td><td style="padding:2px 0;"><code style="font-size:11px;background:#fff;padding:1px 4px;border:1px solid #ddd;border-radius:2px;"><?php echo esc_html( $data['push_last_error'] ); ?></code></td></tr>
<?php endif; ?>
<?php if ( $data['idempotency_key'] !== '' ) : ?>
<tr><td style="padding:2px 0;color:#888;vertical-align:top;">Idempotency-Key</td><td style="padding:2px 0;"><code style="font-size:11px;background:#fff;padding:1px 4px;border:1px solid #ddd;border-radius:2px;"><?php echo esc_html( $data['idempotency_key'] ); ?></code></td></tr>
<?php endif; ?>
</table>
</div>
<?php endif; ?>

<!-- Interner Meta-Footer (Bearbeitungslink / IDs) -->
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;font-size:12px;color:#888;">
<?php if ( $data['edit_link'] ) : ?>
<a href="<?php echo esc_url( $data['edit_link'] ); ?>" style="color:#0073aa;text-decoration:none;">Anfrage in WP-Admin oeffnen &rarr;</a>
<br><br>
<?php endif; ?>
Anfrage-ID: <code><?php echo esc_html( (string) $data['post_id'] ); ?></code> &middot; eingegangen <?php echo esc_html( $data['post_date'] ); ?>
</div>
        <?php
        $inner    = ob_get_clean();
        $headline = $is_note ? ( 'Neue Anfrage von ' . ( $name !== '' ? $name : '(unbekannt)' ) ) : 'Sammelanfrage (Fallback)';
        return function_exists( 'm24_mail_shell' ) ? m24_mail_shell( $headline, $inner ) : $inner;
    }

    // ────────────────────────────────────────────────────────────────────
    // Status-Update
    // ────────────────────────────────────────────────────────────────────

    /**
     * Setzt Inquiry-Status auf synced_via_mail und schreibt Diagnostik-Postmeta.
     * Defensiv: Push-Hooks waehrend des Updates kurz aushaengen, damit der
     * Statuswechsel keinen erneuten Push triggert.
     */
    private static function mark_synced_via_mail( $post_id, $reason, $to ) {
        $iso = gmdate( 'c' );

        update_post_meta( $post_id, '_m24_mail_fallback_sent_at', $iso );
        update_post_meta( $post_id, '_m24_mail_fallback_reason',  $reason );
        update_post_meta( $post_id, '_m24_mail_fallback_to',      $to );

        // wp_update_post triggert transition_post_status. Push-Hook lauscht zwar
        // laut D.1b nur auf Wechsel auf STATUS_PENDING, aber sicherheitshalber
        // wp_update_post in einem Frame, in dem unser eigener Hook nicht greift.
        // (Push-Hook lauscht auf 'transition_post_status' mit eigener Logik —
        // wir verlassen uns darauf, dass er nur bei new_status === STATUS_PENDING
        // aktiv wird. STATUS_SYNCED_MAIL fehlt in der Trigger-Bedingung.)
        $result = wp_update_post( [
            'ID'          => $post_id,
            'post_status' => M24_Inquiries::STATUS_SYNCED_MAIL,
        ], true );

        if ( is_wp_error( $result ) || $result === 0 ) {
            self::log_error( $post_id, 'Mail-Fallback: wp_update_post fehlgeschlagen', [
                'error' => is_wp_error( $result ) ? $result->get_error_message() : 'returned 0',
            ] );
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Logger-Helper (analog zu M24_Inquiries_Push)
    // ────────────────────────────────────────────────────────────────────

    private static function log_info( $post_id, $message, $extra = [] ) {
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_mail_fallback', $message, array_merge( [ 'post_id' => $post_id ], $extra ) );
        }
    }
    private static function log_warning( $post_id, $message, $extra = [] ) {
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::warning( 'inquiries_mail_fallback', $message, array_merge( [ 'post_id' => $post_id ], $extra ) );
        }
    }
    private static function log_error( $post_id, $message, $extra = [] ) {
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::error( 'inquiries_mail_fallback', $message, array_merge( [ 'post_id' => $post_id ], $extra ) );
        }
    }
}
