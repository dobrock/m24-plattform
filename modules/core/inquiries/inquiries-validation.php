<?php
/**
 * M24 Plattform — Inquiries-Modul: Validation
 *
 * Schritt C.1:
 * - Pure-Function-Validator für eingehende Submit-POSTs
 * - Honeypot, DSGVO-Consent, Required-Felder, Email-Format, Items
 * - Returnt entweder validiertes Daten-Array oder WP_Error
 *
 * Wird von C.3 (Submit-Handler) aufgerufen.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries_Validation {

    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_validation', 'Validation-Modul geladen' );
        }
    }

    /**
     * Validiert + sanitisiert ein POST-Array aus dem Anfrage-Formular.
     *
     * @param array $post  i.d.R. $_POST (raw, unslashed wird intern gemacht)
     * @return array|WP_Error  Validiertes Daten-Array für insert_inquiry() oder WP_Error
     */
    public static function validate( array $post ) {

        // 1. Honeypot
        $honeypot = isset( $post['website_confirm'] ) ? trim( wp_unslash( (string) $post['website_confirm'] ) ) : '';
        if ( '' !== $honeypot ) {
            return new WP_Error( 'm24_honeypot', __( 'Honeypot triggered.', 'm24-plattform' ) );
        }

        // 2. DSGVO-Consent
        $consent = isset( $post['dsgvo_consent'] ) ? wp_unslash( (string) $post['dsgvo_consent'] ) : '';
        if ( '1' !== $consent ) {
            return new WP_Error(
                'm24_consent_missing',
                __( 'Bitte stimme der Datenschutzerklärung zu.', 'm24-plattform' )
            );
        }

        // 3. Required-Felder (Vorname/Nachname sind OPTIONAL).
        $required = [ 'email', 'land' ];
        $missing  = [];
        foreach ( $required as $key ) {
            $val = isset( $post[ $key ] ) ? trim( wp_unslash( (string) $post[ $key ] ) ) : '';
            if ( '' === $val ) {
                $missing[] = $key;
            }
        }
        if ( ! empty( $missing ) ) {
            return new WP_Error(
                'm24_required_missing',
                __( 'Bitte fülle alle Pflichtfelder aus.', 'm24-plattform' ),
                [ 'fields' => $missing ]
            );
        }

        // 3b. Privat/Geschaeftlich ist Pflicht (Selector in Modal + Sammelanfrage).
        $biz_raw = isset( $post['biz'] ) ? trim( wp_unslash( (string) $post['biz'] ) ) : '';
        if ( '0' !== $biz_raw && '1' !== $biz_raw ) {
            return new WP_Error(
                'm24_biz_missing',
                __( 'Bitte „Privat" oder „Geschäftlich" wählen.', 'm24-plattform' ),
                [ 'fields' => [ 'biz' ] ]
            );
        }

        // 4. Email-Format
        $email_raw = trim( wp_unslash( (string) $post['email'] ) );
        $email     = sanitize_email( $email_raw );
        if ( '' === $email || ! is_email( $email ) ) {
            return new WP_Error(
                'm24_email_invalid',
                __( 'Bitte gib eine gültige E-Mail-Adresse an.', 'm24-plattform' ),
                [ 'fields' => [ 'email' ] ]
            );
        }

        $land_iso = strtoupper( trim( wp_unslash( (string) $post['land'] ) ) );

        // 4a. Land muss ein bekannter ISO-Code sein (schuetzt das freie
        //     Autocomplete-Feld im Modal vor Tippfehlern/Junk).
        if ( class_exists( 'M24_Inquiry_Frontend' )
            && ! in_array( $land_iso, M24_Inquiry_Frontend::accepted_iso(), true ) ) {
            return new WP_Error(
                'm24_land_unknown',
                __( 'Bitte ein Land aus der Liste wählen.', 'm24-plattform' ),
                [ 'fields' => [ 'land' ] ]
            );
        }

        // 4b. PPWR-Lieferland-Gate (gemeinsam fuer Modal + Sammelanfrage).
        //     blocked = EU-Mitglied ausser DE/NL/BE/FR/ES. Drittlaender erlaubt.
        if ( class_exists( 'M24_PPWR' ) && M24_PPWR::is_blocked( $land_iso ) ) {
            return new WP_Error(
                'm24_ppwr_blocked',
                M24_PPWR::notice(),
                [ 'fields' => [ 'land' ] ]
            );
        }

        // 5. Items dekodieren + sanitisieren
        $items_raw = isset( $post['items_json'] ) ? wp_unslash( (string) $post['items_json'] ) : '';
        if ( '' === trim( $items_raw ) ) {
            return new WP_Error(
                'm24_items_empty',
                __( 'Deine Anfrage enthält keine Positionen.', 'm24-plattform' )
            );
        }
        $decoded = json_decode( $items_raw, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error(
                'm24_items_invalid_json',
                __( 'Die Anfrage-Positionen konnten nicht gelesen werden.', 'm24-plattform' )
            );
        }
        $items = M24_Inquiries_Form::sanitize_items( $decoded );
        if ( empty( $items ) ) {
            return new WP_Error(
                'm24_items_empty',
                __( 'Deine Anfrage enthält keine gültigen Positionen.', 'm24-plattform' )
            );
        }

        // 6. Source validieren
        $source_raw    = isset( $post['inquiry_source'] ) ? sanitize_key( wp_unslash( (string) $post['inquiry_source'] ) ) : '';
        $valid_sources = M24_Inquiries::valid_sources();
        $source        = in_array( $source_raw, $valid_sources, true ) ? $source_raw : M24_Inquiries::SOURCE_CART;

        $source_meta_raw = isset( $post['inquiry_source_meta'] )
            ? wp_unslash( (string) $post['inquiry_source_meta'] )
            : '';
        $source_meta = self::parse_source_meta( $source, $source_meta_raw );

        // 7. Restliche Felder sanitisieren
        $get_text = function( $key ) use ( $post ) {
            return isset( $post[ $key ] )
                ? sanitize_text_field( wp_unslash( (string) $post[ $key ] ) )
                : '';
        };

        $data = [
            'vorname'             => $get_text( 'vorname' ),
            'nachname'            => $get_text( 'nachname' ),
            'email'               => $email,
            'tel'                 => $get_text( 'tel' ),
            'firma'               => $get_text( 'firma' ),
            'anrede'              => $get_text( 'anrede' ),
            'strasse'             => $get_text( 'strasse' ),
            'plz'                 => $get_text( 'plz' ),
            'ort'                 => $get_text( 'ort' ),
            'land'                => $get_text( 'land' ),
            'uid'                 => $get_text( 'uid' ),
            'biz'                 => isset( $post['biz'] ) && '1' === (string) $post['biz'] ? 1 : 0,
            'notes'               => isset( $post['notes'] )
                                        ? sanitize_textarea_field( wp_unslash( (string) $post['notes'] ) )
                                        : '',
            'inquiry_source'      => $source,
            'inquiry_source_meta' => $source_meta,
            'items'               => $items,
        ];

        return $data;
    }

    /**
     * Parst und whitelisted das inquiry_source_meta-Feld pro Source-Typ.
     *
     * Spec v4 §6.2: pro inquiry_source ein eigenes JSON-Schema.
     * Resilient: bei Parse-Fehler oder unbekannter Source → leeres Array,
     * keine User-Fehlermeldung (interne Statistik darf den Submit nicht blockieren).
     *
     * @param string $source   normalisierte Source (cart|product_inquiry|contact_form|blog_inquiry)
     * @param string $raw_json JSON-String aus dem Hidden-Input
     * @return array            sanitisiertes, whitelisted Meta-Array (kann leer sein)
     */
    public static function parse_source_meta( $source, $raw_json ) {
        if ( empty( $raw_json ) ) {
            return [];
        }

        $decoded = json_decode( (string) $raw_json, true );
        if ( ! is_array( $decoded ) ) {
            if ( class_exists( 'M24_Logger' ) ) {
                M24_Logger::warning( 'inquiries_validation', 'source_meta_parse_failed', [
                    'source' => $source,
                    'raw'    => mb_substr( (string) $raw_json, 0, 200 ),
                ] );
            }
            return [];
        }

        $out = [];

        switch ( $source ) {
            case M24_Inquiries::SOURCE_CART:
                if ( isset( $decoded['cart_session_id'] ) ) {
                    $sid = sanitize_text_field( (string) $decoded['cart_session_id'] );
                    if ( strlen( $sid ) > 0 && strlen( $sid ) <= 64 ) {
                        $out['cart_session_id'] = $sid;
                    }
                }
                if ( isset( $decoded['items_total'] ) ) {
                    $n = (int) $decoded['items_total'];
                    if ( $n >= 0 && $n <= 999 ) {
                        $out['items_total'] = $n;
                    }
                }
                if ( isset( $decoded['estimated_value_eur'] ) ) {
                    $v = (float) $decoded['estimated_value_eur'];
                    if ( $v >= 0 && $v <= 9999999 ) {
                        $out['estimated_value_eur'] = round( $v, 2 );
                    }
                }
                break;

            case M24_Inquiries::SOURCE_PRODUCT:
                if ( isset( $decoded['src_url'] ) ) {
                    $u = esc_url_raw( (string) $decoded['src_url'] );
                    if ( $u ) { $out['src_url'] = $u; }
                }
                if ( isset( $decoded['src_pid'] ) ) {
                    $pid = sanitize_text_field( (string) $decoded['src_pid'] );
                    if ( strlen( $pid ) > 0 && strlen( $pid ) <= 64 ) {
                        $out['src_pid'] = $pid;
                    }
                }
                break;

            case M24_Inquiries::SOURCE_CONTACT:
                if ( isset( $decoded['form_url'] ) ) {
                    $u = esc_url_raw( (string) $decoded['form_url'] );
                    if ( $u ) { $out['form_url'] = $u; }
                }
                if ( isset( $decoded['user_message_excerpt'] ) ) {
                    $excerpt = sanitize_text_field( (string) $decoded['user_message_excerpt'] );
                    if ( $excerpt !== '' ) {
                        $out['user_message_excerpt'] = mb_substr( $excerpt, 0, 280 );
                    }
                }
                break;

            case M24_Inquiries::SOURCE_BLOG:
                if ( isset( $decoded['blog_post_id'] ) ) {
                    $id = (int) $decoded['blog_post_id'];
                    if ( $id > 0 ) { $out['blog_post_id'] = $id; }
                }
                if ( isset( $decoded['blog_post_url'] ) ) {
                    $u = esc_url_raw( (string) $decoded['blog_post_url'] );
                    if ( $u ) { $out['blog_post_url'] = $u; }
                }
                if ( isset( $decoded['anchor_block_id'] ) ) {
                    $a = sanitize_text_field( (string) $decoded['anchor_block_id'] );
                    if ( strlen( $a ) > 0 && strlen( $a ) <= 64 ) {
                        $out['anchor_block_id'] = $a;
                    }
                }
                break;
        }

        return $out;
    }
}
