<?php
/**
 * Migration 004 — Preisoptionen-Datenmodell
 *
 * Konvertiert bestehende Single-Preis-Posts (m24_teil) in das neue
 * `_m24_preisoptionen`-JSON-Array. Idempotent: setzt nur, wenn
 * `_m24_preisoptionen` noch nicht vorhanden ist.
 *
 * Pro Post: genau eine Default-Option:
 *   - label '' (Single-Option = kein Frontend-Label noetig)
 *   - art_nr aus _m24_artikelnummer
 *   - netto/brutto abgeleitet aus _m24_preis_netto + _m24_mwst_modus
 *     · regel:        basis = netto, brutto = netto × 1,19
 *     · paragraf25a:  basis = brutto, netto = null
 *
 * Setzt zusaetzlich `_m24_preis_eingabe = 'netto'` (Backward-Compat fuer den
 * neuen Brutto/Netto-Toggle im Backend-Editor — bestehende Posts hatten den
 * Wert als netto gespeichert). Neue Posts werden default auf 'brutto' stehen.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function m24_migration_004() {
    $posts = get_posts( array(
        'post_type'      => 'm24_teil',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_m24_preisoptionen',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ) );

    foreach ( $posts as $post_id ) {
        $basis = (float) get_post_meta( $post_id, '_m24_preis_netto', true );
        if ( $basis <= 0 ) {
            // Posts ohne Preis: leeres Array setzen, damit Re-Run hier nicht schleift.
            update_post_meta( $post_id, '_m24_preisoptionen', wp_json_encode( array() ) );
            continue;
        }

        $modus = get_post_meta( $post_id, '_m24_mwst_modus', true );
        $modus = ( 'paragraf25a' === $modus ) ? 'paragraf25a' : 'regel';

        if ( 'paragraf25a' === $modus ) {
            $brutto = $basis;
            $netto  = null;
        } else {
            $netto  = $basis;
            $brutto = round( $netto * 1.19, 2 );
        }

        $artnr = (string) get_post_meta( $post_id, '_m24_artikelnummer', true );

        $option = array(
            'label'  => '',
            'art_nr' => $artnr,
            'netto'  => $netto,
            'brutto' => $brutto,
        );

        update_post_meta( $post_id, '_m24_preisoptionen', wp_json_encode( array( $option ) ) );
        update_post_meta( $post_id, '_m24_preis_eingabe', 'netto' );
    }

    return true;
}
