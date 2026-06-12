/**
 * M24 Plattform — Admin JavaScript
 *
 * Aktuell: AJAX-Call fuer Health-Check (Verbindung testen-Button).
 */
( function( $ ) {
    'use strict';

    $( document ).ready( function() {

        var $button = $( '#m24-test-button' );
        var $result = $( '#m24-test-result' );
        var $detail = $( '#m24-test-detail' );

        if ( ! $button.length ) {
            return;
        }

        $button.on( 'click', function( e ) {
            e.preventDefault();

            // UI: Testing-Zustand
            $button.prop( 'disabled', true );
            $result
                .removeClass( 'ok fail' )
                .addClass( 'testing' )
                .text( M24Admin.i18n.testing )
                .show();
            $detail.hide().text( '' );

            $.ajax( {
                url:      M24Admin.ajaxUrl,
                method:   'POST',
                dataType: 'json',
                data: {
                    action:      'm24_health_check',
                    _ajax_nonce: M24Admin.nonce
                }
            } )
            .done( function( response ) {
                var ok       = response && response.ok;
                var status   = response && response.status ? response.status : '?';
                var elapsed  = response && response.elapsed_ms ? response.elapsed_ms : '?';
                var data     = response && response.data ? response.data : null;
                var errorMsg = response && response.error ? response.error : '';

                if ( ok ) {
                    var msg = M24Admin.i18n.success + '  HTTP ' + status + '  (' + elapsed + ' ms)';
                    if ( data && data.version ) {
                        msg += '  Backend v' + data.version;
                    }
                    $result.removeClass( 'testing fail' ).addClass( 'ok' ).text( msg );
                } else {
                    var failMsg = M24Admin.i18n.error + '  HTTP ' + status + '  (' + elapsed + ' ms)';
                    if ( errorMsg ) {
                        failMsg += '  — ' + errorMsg;
                    }
                    $result.removeClass( 'testing ok' ).addClass( 'fail' ).text( failMsg );
                }

                if ( data ) {
                    $detail.text( JSON.stringify( data, null, 2 ) ).show();
                }
            } )
            .fail( function( xhr, textStatus ) {
                $result
                    .removeClass( 'testing ok' )
                    .addClass( 'fail' )
                    .text( M24Admin.i18n.error + ' — AJAX-Fehler: ' + textStatus );

                if ( xhr.responseText ) {
                    $detail.text( xhr.responseText.substring( 0, 1000 ) ).show();
                }
            } )
            .always( function() {
                $button.prop( 'disabled', false );
            } );
        } );

    } );

} )( jQuery );
