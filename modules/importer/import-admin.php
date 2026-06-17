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
	const WL_PREFIX = 'm24_imp_wl_';

	/** @var bool Schon eine JSON-Antwort gesendet? (Shutdown-Guard nicht doppelt feuern.) */
	private static $responded = false;

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
		// Immer-JSON-Vertrag: Fatals (Timeout/Memory) NIE als HTML rausgeben.
		@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet
		if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }
		ob_start();
		register_shutdown_function( array( __CLASS__, 'shutdown_guard' ) );

		try {
			if ( ! current_user_can( 'manage_options' ) ) { self::json_error( 'keine Berechtigung', 403 ); }
			check_ajax_referer( self::NONCE, '_nonce' );

			$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
			$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
			$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
			if ( ! in_array( $type, array( 'gebraucht', 'rennsport', 'media' ), true ) ) { self::json_error( 'unbekannter Typ' ); }

			// Start: Worklist bauen + cachen.
			if ( 0 === $offset || '' === $token ) {
				$token = substr( md5( uniqid( $type, true ) ), 0, 12 );
				$items = self::worklist( $type );
				set_transient( self::WL_PREFIX . $token, $items, 20 * MINUTE_IN_SECONDS );
			} else {
				$items = get_transient( self::WL_PREFIX . $token );
				if ( ! is_array( $items ) ) { self::json_error( 'Worklist abgelaufen — bitte neu starten' ); }
			}

			$total = count( $items );

			// ZEIT-BUDGET-Chunk: schwerer Galerie-Rebuild (bis 19 Bilder/Produkt) → 1/Sub,
			// hartes Server-Budget < Web-Timeout. Gebraucht/Rennsport groesser. Bricht ab,
			// sobald Budget/Cap erreicht → done=false + offset zurueck, JS macht weiter.
			$budget = ( 'media' === $type ) ? 12.0 : 18.0; // Worst-Case Media ≈ Budget + 1 Bild-Timeout < 30s
			$sub    = ( 'media' === $type ) ? 1 : 5;
			$cap    = ( 'media' === $type ) ? 6 : 40;
			$start    = microtime( true );
			$deadline = $start + $budget;
			$processed = 0;
			$agg = array( 'processed' => 0, 'new' => 0, 'skipped' => 0, 'img_pending' => 0, 'unresolved' => 0, 'errors' => 0 );
			while ( $offset + $processed < $total && $processed < $cap ) {
				$slice = array_slice( $items, $offset + $processed, $sub );
				if ( empty( $slice ) ) { break; }
				$r = self::process( $type, $slice, $deadline );
				foreach ( array_keys( $agg ) as $k ) { if ( isset( $r[ $k ] ) ) { $agg[ $k ] += (int) $r[ $k ]; } }
				$processed += count( $slice );
				if ( ( microtime( true ) - $start ) >= $budget ) { break; }
			}

			$new_offset = $offset + $processed;
			self::json_success( array(
				'token'       => $token,
				'type'        => $type,
				'total'       => $total,
				'offset'      => $new_offset,
				'processed'   => (int) $agg['processed'],
				'new'         => (int) $agg['new'],
				'skipped'     => (int) $agg['skipped'],
				'img_pending' => (int) $agg['img_pending'],
				'unresolved'  => (int) $agg['unresolved'],
				'errors'      => (int) $agg['errors'],
				'done'        => $new_offset >= $total,
			) );
		} catch ( Throwable $t ) {
			self::json_error( 'Fehler: ' . $t->getMessage() );
		}
	}

	/** JSON-Erfolg: Output-Puffer verwerfen (Notices/echo/BOM) → sauberes JSON. */
	private static function json_success( $data ) {
		self::$responded = true;
		if ( ob_get_length() ) { ob_end_clean(); }
		wp_send_json_success( $data );
	}

	/** JSON-Fehler: immer {message}, Puffer verwerfen. */
	private static function json_error( $msg, $code = 200 ) {
		self::$responded = true;
		if ( ob_get_length() ) { ob_end_clean(); }
		wp_send_json_error( array( 'message' => (string) $msg ), $code );
	}

	/** Shutdown-Guard: faengt PHP-Fatals ab → IMMER JSON statt HTML-Fehlerseite. */
	public static function shutdown_guard() {
		if ( self::$responded ) { return; }
		$e = error_get_last();
		if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) { return; }
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		if ( ! headers_sent() ) { status_header( 200 ); header( 'Content-Type: application/json; charset=utf-8' ); }
		echo wp_json_encode( array( 'success' => false, 'data' => array( 'message' => 'Abbruch (PHP-Fatal): ' . $e['message'] . ' — Chunk kleiner versuchen / erneut klicken.' ) ) );
	}

	/** Worklist je Typ (Array von Items; Struktur typ-spezifisch). */
	private static function worklist( $type ) {
		if ( 'gebraucht' === $type ) { return M24_Shopware_Gebraucht::build_worklist(); }
		if ( 'rennsport' === $type ) { return M24_Shopware_Rennsport::build_worklist( array( 'z4-gt3', 'e30', 'e36', 'e46', 'e90', 'e92' ) ); }
		// media: Galerie-Rebuild — alle Teile (neu+gebraucht) mit unvollst. Galerie/offenen Bildern.
		return M24_Shopware_Media::rebuild_worklist();
	}

	/** Chunk verarbeiten je Typ. $deadline (microtime) begrenzt den schweren Media-Rebuild. */
	private static function process( $type, array $chunk, $deadline = 0.0 ) {
		if ( 'gebraucht' === $type ) { return M24_Shopware_Gebraucht::process_chunk( $chunk ); }
		if ( 'rennsport' === $type ) { return M24_Shopware_Rennsport::process_chunk( $chunk ); }
		// media: Galerie-Rebuild aus Shopware (idempotent, Hash-Dedupe; 12s Bild-Timeout + Per-Produkt-Deadline).
		return M24_Shopware_Media::rebuild_chunk( $chunk, 12, $deadline );
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
			var AJAX=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, NONCE=<?php echo wp_json_encode( $nonce ); ?>;
			var wrap=document.getElementById('m24imp-barwrap'), bar=document.getElementById('m24imp-bar'),
			    st=document.getElementById('m24imp-status'), ct=document.getElementById('m24imp-counts');
			var buttons=document.querySelectorAll('[data-m24imp]'), busy=false;
			function setBusy(b){ busy=b; buttons.forEach(function(x){x.disabled=b;}); }
			function sleep(ms){ return new Promise(function(r){ setTimeout(r,ms); }); }
			// Robust: nie hart JSON.parse werfen — HTTP-Status + Nicht-JSON sauber abfangen.
			async function call(type,token,offset){
				var fd=new FormData();
				fd.append('action','<?php echo esc_js( self::ACTION ); ?>'); fd.append('_nonce',NONCE);
				fd.append('type',type); fd.append('token',token); fd.append('offset',offset);
				var resp=await fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd});
				var txt=await resp.text();
				var d=null; try{ d=JSON.parse(txt); }catch(e){ d=null; }
				if(!d){ throw new Error('Server-Antwort kein JSON (HTTP '+resp.status+'): '+txt.replace(/<[^>]*>/g,' ').trim().slice(0,140)); }
				if(!resp.ok && !('success' in d)){ throw new Error('HTTP '+resp.status); }
				return d;
			}
			function label(t){ return t==='gebraucht'?'Gebraucht':(t==='rennsport'?'Rennsport':'Bilder / Galerie'); }
			async function run(type){
				if(busy) return; setBusy(true); wrap.style.display='';
				var token='', offset=0, total=0, retries=0, acc={new:0,skipped:0,img_pending:0,unresolved:0,errors:0};
				st.textContent=label(type)+': starte …'; bar.style.width='0';
				while(true){
					var d;
					try{ d=await call(type,token,offset); retries=0; }
					catch(e){
						retries++;
						if(retries<=2){ st.textContent=label(type)+': Wiederhole Chunk ('+retries+'/2) …'; await sleep(1500*retries); continue; }
						st.textContent='Abgebrochen: '+e.message+' — erneut klicken setzt fort.'; break;
					}
					if(!d.success){ st.textContent='Fehler: '+((d.data&&(d.data.message||d.data))||'unbekannt')+' — erneut klicken setzt fort.'; break; }
					d=d.data; token=d.token; total=d.total; offset=d.offset;
					acc.new+=d.new; acc.skipped+=d.skipped; acc.unresolved+=d.unresolved; acc.errors+=d.errors; acc.img_pending=d.img_pending;
					var pct=total?Math.round(offset/total*100):100;
					bar.style.width=pct+'%';
					st.textContent=label(type)+': '+offset+' / '+total+' ('+pct+'%)';
					ct.textContent=(type==='media'?'Bilder geladen: ':'neu/aktualisiert: ')+acc.new+' · übersprungen: '+acc.skipped+(acc.img_pending?(' · offen (Chunk): '+acc.img_pending):'')+(acc.unresolved?(' · unresolved: '+acc.unresolved):'')+(acc.errors?(' · Fehler: '+acc.errors):'');
					if(d.done){ st.textContent=label(type)+': fertig — '+total+' verarbeitet ('+acc.new+' neu/aktualisiert).'; break; }
				}
				setBusy(false);
			}
			buttons.forEach(function(b){ b.addEventListener('click',function(){ run(b.getAttribute('data-m24imp')); }); });
		})();
		</script>
		<?php
	}
}
