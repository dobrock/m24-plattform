<?php
/**
 * M24 Plattform — Admin-Import-Steuerung (Browser-AJAX-Chunk-Loop, kein Plesk/Cron)
 * Modul: modules/importer/import-admin.php
 *
 * Seite „Teile-Katalog → Shopware-Import" mit Buttons. JS ruft wiederholt einen
 * admin-ajax-Endpoint, der je Call ~10 Produkte ueber die BESTEHENDE Importer-Logik
 * verarbeitet (build_worklist + process_chunk + Fast-Skip via existing_sw_ids) und
 * Fortschritt zurueckgibt. Loop bis done=true. Idempotent (Re-Klick skippt in ms).
 *
 * Sicherheit: Nonce + current_user_can('manage_options'). KEIN Action Scheduler,
 * KEIN Konsolen-Pfad. Worklist je Lauf in Transient (Token), Chunk per Offset.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Import_Admin {

	const ACTION    = 'm24_import_chunk';
	const NONCE     = 'm24_import_admin';
	const CHUNK     = 10;
	const WL_PREFIX = 'm24_imp_wl_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=m24_teil',
			'Shopware-Import', 'Shopware-Import',
			'manage_options', 'm24-shopware-import',
			array( __CLASS__, 'render' )
		);
	}

	/* ── AJAX: ein Chunk pro Call ─────────────────────────────────────────────── */
	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'keine Berechtigung', 403 ); }
		check_ajax_referer( self::NONCE, '_nonce' );

		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$valid  = array( 'gebraucht', 'rennsport', 'media' );
		if ( ! in_array( $type, $valid, true ) ) { wp_send_json_error( 'unbekannter Typ' ); }

		// Start: Worklist bauen + cachen.
		if ( 0 === $offset || '' === $token ) {
			$token = substr( md5( uniqid( $type, true ) ), 0, 12 );
			$items = self::worklist( $type );
			set_transient( self::WL_PREFIX . $token, $items, 20 * MINUTE_IN_SECONDS );
		} else {
			$items = get_transient( self::WL_PREFIX . $token );
			if ( ! is_array( $items ) ) { wp_send_json_error( 'Worklist abgelaufen — bitte neu starten' ); }
		}

		$total = count( $items );
		$chunk = array_slice( $items, $offset, self::CHUNK );
		$res   = self::process( $type, $chunk );

		$new_offset = $offset + count( $chunk );
		wp_send_json_success( array(
			'token'       => $token,
			'type'        => $type,
			'total'       => $total,
			'offset'      => $new_offset,
			'processed'   => (int) $res['processed'],
			'new'         => (int) $res['new'],
			'skipped'     => (int) $res['skipped'],
			'img_pending' => (int) $res['img_pending'],
			'unresolved'  => (int) $res['unresolved'],
			'errors'      => (int) ( $res['errors'] ?? 0 ),
			'done'        => $new_offset >= $total,
		) );
	}

	/** Worklist je Typ (Array von Items; Struktur typ-spezifisch). */
	private static function worklist( $type ) {
		if ( 'gebraucht' === $type ) { return M24_Shopware_Gebraucht::build_worklist(); }
		if ( 'rennsport' === $type ) { return M24_Shopware_Rennsport::build_worklist( array( 'z4-gt3', 'e30', 'e36', 'e46', 'e90', 'e92' ) ); }
		// media: Galerie-Rebuild — alle Teile (neu+gebraucht) mit unvollst. Galerie/offenen Bildern.
		return M24_Shopware_Media::rebuild_worklist();
	}

	/** Chunk verarbeiten je Typ. */
	private static function process( $type, array $chunk ) {
		if ( 'gebraucht' === $type ) { return M24_Shopware_Gebraucht::process_chunk( $chunk ); }
		if ( 'rennsport' === $type ) { return M24_Shopware_Rennsport::process_chunk( $chunk ); }
		// media: Galerie-Rebuild aus Shopware (idempotent, Hash-Dedupe).
		return M24_Shopware_Media::rebuild_chunk( $chunk );
	}

	/* ── Seite ────────────────────────────────────────────────────────────────── */
	public static function render() {
		$nonce = wp_create_nonce( self::NONCE );
		?>
		<div class="wrap">
			<h1>Shopware-Import</h1>
			<p>Import läuft direkt hier im Browser (chunkweise, kein Konsolen-/Cron-Zwang). Re-Klick überspringt bereits Importiertes in Millisekunden.</p>
			<p>
				<button class="button button-primary" data-m24imp="gebraucht">Gebraucht importieren / fortsetzen</button>
				<button class="button button-primary" data-m24imp="rennsport">Rennsport importieren</button>
				<button class="button" data-m24imp="media">Bilder / Galerie nachladen</button>
			</p>
			<div id="m24imp-barwrap" style="display:none;max-width:640px">
				<div style="background:#e4e4e0;border-radius:6px;overflow:hidden;height:22px;margin:8px 0">
					<div id="m24imp-bar" style="background:linear-gradient(135deg,#1f74c4,#0e447e);height:100%;width:0;transition:width .2s"></div>
				</div>
				<p id="m24imp-status" style="font-weight:600"></p>
				<p id="m24imp-counts" style="color:#555"></p>
			</div>
		</div>
		<script>
		(function(){
			var AJAX=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, NONCE=<?php echo wp_json_encode( $nonce ); ?>, CHUNK=<?php echo (int) self::CHUNK; ?>;
			var wrap=document.getElementById('m24imp-barwrap'), bar=document.getElementById('m24imp-bar'),
			    st=document.getElementById('m24imp-status'), ct=document.getElementById('m24imp-counts');
			var buttons=document.querySelectorAll('[data-m24imp]'), busy=false;
			function setBusy(b){ busy=b; buttons.forEach(function(x){x.disabled=b;}); }
			function call(type,token,offset){
				var fd=new FormData();
				fd.append('action','<?php echo esc_js( self::ACTION ); ?>'); fd.append('_nonce',NONCE);
				fd.append('type',type); fd.append('token',token); fd.append('offset',offset);
				return fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();});
			}
			function label(t){ return t==='gebraucht'?'Gebraucht':(t==='rennsport'?'Rennsport':'Bilder nachladen'); }
			async function run(type){
				if(busy) return; setBusy(true); wrap.style.display='';
				var token='', offset=0, total=0, acc={new:0,skipped:0,img_pending:0,unresolved:0,errors:0};
				st.textContent=label(type)+': starte …'; bar.style.width='0';
				try{
					while(true){
						var d=await call(type,token,offset);
						if(!d||!d.success){ st.textContent='Fehler: '+((d&&d.data)||'unbekannt'); break; }
						d=d.data; token=d.token; total=d.total; offset=d.offset;
						acc.new+=d.new; acc.skipped+=d.skipped; acc.unresolved+=d.unresolved; acc.errors+=d.errors; acc.img_pending=d.img_pending;
						var pct=total?Math.round(offset/total*100):100;
						bar.style.width=pct+'%';
						st.textContent=label(type)+': '+offset+' / '+total+' ('+pct+'%)';
						ct.textContent='neu/aktualisiert: '+acc.new+' · übersprungen: '+acc.skipped+' · img-pending(letzter Chunk): '+acc.img_pending+(acc.unresolved?(' · unresolved: '+acc.unresolved):'')+(acc.errors?(' · Fehler: '+acc.errors):'');
						if(d.done){ st.textContent=label(type)+': fertig — '+total+' verarbeitet ('+acc.new+' neu/aktualisiert).'; break; }
					}
				}catch(e){ st.textContent='Fehler: '+e.message; }
				setBusy(false);
			}
			buttons.forEach(function(b){ b.addEventListener('click',function(){ run(b.getAttribute('data-m24imp')); }); });
		})();
		</script>
		<?php
	}
}
