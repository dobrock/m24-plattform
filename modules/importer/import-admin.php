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

	/** @var bool Varianten-Pre-Fill: Force-Resync (überschreibt auch handgepflegte). */
	private static $force = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_m24_import_log', array( __CLASS__, 'ajax_log' ) );
		add_action( 'wp_ajax_m24_import_log_clear', array( __CLASS__, 'ajax_log_clear' ) );
		add_action( 'wp_ajax_m24_opcache_reset', array( __CLASS__, 'ajax_opcache_reset' ) );
	}

	/** Manueller OPcache-Reset (Always-JSON). Gegen stale Bytecode nach Deploys. */
	public static function ajax_opcache_reset() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'keine Berechtigung' ) ); }
		check_ajax_referer( self::NONCE, '_nonce' );
		$ok = class_exists( 'M24_Updater' ) ? M24_Updater::reset_opcache( 'manuell (Diagnose-Button)' ) : ( function_exists( 'opcache_reset' ) && @opcache_reset() ); // phpcs:ignore
		wp_send_json_success( array( 'ok' => (bool) $ok, 'message' => $ok ? 'OPcache geleert — Bytecode wird neu kompiliert.' : 'OPcache nicht aktiv / Funktion fehlt.' ) );
	}

	/** Diagnose: letzte Log-Zeilen + PHP-Limits (Timeout-vs-OOM ohne Konsole sichtbar). */
	public static function ajax_log() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'keine Berechtigung' ) ); }
		check_ajax_referer( self::NONCE, '_nonce' );
		$opcache = class_exists( 'M24_Updater' ) ? M24_Updater::opcache_status() : array();
		wp_send_json_success( array( 'lines' => M24_Import_Log::tail( 80 ), 'limits' => M24_Import_Log::limits(), 'opcache' => $opcache ) );
	}

	/** Diagnose-Log leeren. */
	public static function ajax_log_clear() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'keine Berechtigung' ) ); }
		check_ajax_referer( self::NONCE, '_nonce' );
		M24_Import_Log::clear();
		wp_send_json_success( array( 'ok' => true ) );
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
			if ( ! current_user_can( 'manage_options' ) ) { self::json_error( 'keine Berechtigung' ); } // HTTP 200 → JS retryt nicht
			check_ajax_referer( self::NONCE, '_nonce' );

			$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
			$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
			$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
			self::$force = ! empty( $_POST['force'] ); // Varianten: Force-Resync überschreibt auch gepflegte
			if ( ! in_array( $type, array( 'gebraucht', 'rennsport', 'media', 'varianten', 'seotitel' ), true ) ) { self::json_error( 'unbekannter Typ' ); }

			// Worklist-TTL grosszuegig: Media laeuft 1-Bild-pro-Call → viele Calls/lange Laufzeit.
			$ttl = ( 'media' === $type ) ? 2 * HOUR_IN_SECONDS : 20 * MINUTE_IN_SECONDS;

			// Start NUR bei leerem Token (JS sendet '' im ersten Call). NICHT an offset===0
			// koppeln: beim Media-Flow bleibt offset=0, solange ein Produkt mehrere Bilder
			// laedt — sonst wuerde JEDER Call den Token neu wuerfeln + endlos re-seeden.
			if ( '' === $token ) {
				$token  = substr( md5( uniqid( $type, true ) ), 0, 12 );
				$offset = 0;
				$items  = self::worklist( $type );
				set_transient( self::WL_PREFIX . $token, $items, $ttl );
			} else {
				$items = get_transient( self::WL_PREFIX . $token );
				if ( ! is_array( $items ) ) { self::json_error( 'Worklist abgelaufen — bitte neu starten' ); }
				set_transient( self::WL_PREFIX . $token, $items, $ttl ); // TTL bei Aktivitaet erneuern
			}

			$total = count( $items );

			if ( 'media' === $type ) {
				// 1 SCHRITT pro Call (Seed ODER genau 1 Bild) — minimaler Request, kein FPM-Kill/OOM.
				// Produkt-Cursor: offset bleibt stehen, bis das Produkt fertig ist (alle Bilder).
				$new   = 0; $pending = 0; $errors = 0; $stage = ''; $err = '';
				if ( $offset < $total ) {
					$res     = M24_Shopware_Media::rebuild_step( (int) $items[ $offset ], $token, 8 );
					$stage   = (string) $res['stage'];
					$new     = (int) $res['new'];
					$pending = (int) $res['pending'];
					if ( '' !== (string) $res['error'] ) { $errors = 1; $err = (string) $res['error']; }
					if ( ! empty( $res['product_done'] ) ) { $offset++; } // erst dann naechstes Produkt
				}
				self::json_success( array(
					'token'       => $token, 'type' => $type, 'total' => $total, 'offset' => $offset,
					'processed'   => $offset, 'new' => $new, 'skipped' => 0,
					'img_pending' => $pending, 'unresolved' => 0, 'errors' => $errors,
					'stage'       => $stage, 'error' => $err,
					'done'        => $offset >= $total,
				) );
			}

			// Gebraucht/Rennsport: ZEIT-BUDGET-Chunk (mehrere/Call), unveraendert.
			$budget    = 18.0;
			$cap       = 40;
			$start     = microtime( true );
			$deadline  = $start + $budget;
			$processed = 0;
			$agg = array( 'processed' => 0, 'new' => 0, 'skipped' => 0, 'img_pending' => 0, 'unresolved' => 0, 'errors' => 0 );
			while ( $offset + $processed < $total && $processed < $cap ) {
				$slice = array_slice( $items, $offset + $processed, 5 );
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
		if ( 'varianten' === $type ) { return M24_Shopware_Variants::build_worklist(); }
		if ( 'seotitel' === $type ) { return M24_Catalog_SEO::all_ids(); }
		// media: Galerie-Rebuild — alle Teile (neu+gebraucht) mit unvollst. Galerie/offenen Bildern.
		return M24_Shopware_Media::rebuild_worklist();
	}

	/** Chunk verarbeiten je Typ. $deadline (microtime) begrenzt den schweren Media-Rebuild. */
	private static function process( $type, array $chunk, $deadline = 0.0 ) {
		if ( 'gebraucht' === $type ) { return M24_Shopware_Gebraucht::process_chunk( $chunk ); }
		if ( 'rennsport' === $type ) { return M24_Shopware_Rennsport::process_chunk( $chunk ); }
		if ( 'varianten' === $type ) { return M24_Shopware_Variants::process_chunk( $chunk, self::$force ); }
		if ( 'seotitel' === $type ) { return M24_Catalog_SEO::resync_chunk( $chunk ); }
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
			<p style="margin-top:4px">
				<button class="button" data-m24imp="varianten">Varianten aus Shopware übernehmen</button>
				<label style="margin-left:10px;font-size:13px;color:#555"><input type="checkbox" id="m24imp-force"> Force-Resync <span style="color:#888">(überschreibt auch handgepflegte Varianten)</span></label>
			</p>
			<p style="margin-top:4px">
				<button class="button" data-m24imp="seotitel">SEO-Titel neu generieren</button>
				<span style="margin-left:8px;font-size:13px;color:#888">erzeugt &lt;title&gt; + Meta-Description aller Teile neu aus dem Artikel-Titel</span>
			</p>
			<div id="m24imp-barwrap" style="display:none;max-width:640px">
				<div style="background:#e4e4e0;border-radius:6px;overflow:hidden;height:22px;margin:8px 0">
					<div id="m24imp-bar" style="background:linear-gradient(135deg,#1f74c4,#0e447e);height:100%;width:0;transition:width .2s"></div>
				</div>
				<p id="m24imp-status" style="font-weight:600"></p>
				<p id="m24imp-counts" style="color:#555"></p>
			</div>

			<details id="m24imp-diag" style="margin-top:18px;max-width:760px">
				<summary style="cursor:pointer;font-weight:600">Diagnose / Import-Log <span style="color:#888;font-weight:400">(Timeout vs. Speicher)</span></summary>
				<p id="m24imp-limits" style="color:#555;margin:8px 0;font-family:monospace;font-size:12px"></p>
				<p id="m24imp-opcache" style="color:#555;margin:8px 0;font-family:monospace;font-size:12px"></p>
				<p style="margin:6px 0">
					<button type="button" class="button button-small" id="m24imp-log-refresh">Aktualisieren</button>
					<button type="button" class="button button-small" id="m24imp-log-clear">Log leeren</button>
					<button type="button" class="button button-small" id="m24imp-opcache-reset">OPcache leeren</button>
				</p>
				<pre id="m24imp-log" style="background:#1d2327;color:#c3c4c7;padding:10px;border-radius:6px;max-height:320px;overflow:auto;font-size:12px;line-height:1.5;white-space:pre-wrap">— noch keine Einträge —</pre>
			</details>
		</div>
		<script>
		(function(){
			var AJAX=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, NONCE=<?php echo wp_json_encode( $nonce ); ?>;
			var wrap=document.getElementById('m24imp-barwrap'), bar=document.getElementById('m24imp-bar'),
			    st=document.getElementById('m24imp-status'), ct=document.getElementById('m24imp-counts');
			var buttons=document.querySelectorAll('[data-m24imp]'), busy=false;
			var logBox=document.getElementById('m24imp-log'), limBox=document.getElementById('m24imp-limits'), opBox=document.getElementById('m24imp-opcache');
			function setBusy(b){ busy=b; buttons.forEach(function(x){x.disabled=b;}); }
			function sleep(ms){ return new Promise(function(r){ setTimeout(r,ms); }); }
			// Diagnose-Log abrufen (Limits + letzte Zeilen).
			async function refreshLog(){
				var fd=new FormData(); fd.append('action','m24_import_log'); fd.append('_nonce',NONCE);
				try{
					var r=await fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd});
					var d=JSON.parse(await r.text());
					if(d&&d.success){
						var L=d.data.limits||{};
						limBox.textContent='memory_limit='+L.memory_limit+' · max_execution_time='+L.max_execution_time+'s · post_max_size='+L.post_max_size+' · upload_max='+L.upload_max_filesize+' · peak='+L.memory_peak;
						var O=d.data.opcache||{};
						if(opBox){ opBox.textContent='OPcache: enable='+(O.enable||'?')+' · validate_timestamps='+(O.validate_timestamps||'?')+' · revalidate_freq='+(O.revalidate_freq||'?')+' · reset='+(O.reset_available||'?')+(O.validate_timestamps==='0'?'  ⚠ validate_timestamps=0 → Deploys brauchen OPcache-Reset':''); }
						var lines=d.data.lines||[];
						logBox.textContent=lines.length?lines.join('\n'):'— noch keine Einträge —';
						logBox.scrollTop=logBox.scrollHeight;
					}
				}catch(e){ /* Diagnose ist best-effort */ }
			}
			// Robust: nie hart JSON.parse werfen — HTTP-Status + Nicht-JSON sauber abfangen.
			async function call(type,token,offset){
				var fd=new FormData();
				fd.append('action','<?php echo esc_js( self::ACTION ); ?>'); fd.append('_nonce',NONCE);
				fd.append('type',type); fd.append('token',token); fd.append('offset',offset);
				var fc=document.getElementById('m24imp-force'); if(fc&&fc.checked){ fd.append('force','1'); }
				var resp=await fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd});
				var txt=await resp.text();
				var d=null; try{ d=JSON.parse(txt); }catch(e){ d=null; }
				if(!d){ throw new Error('Server-Antwort kein JSON (HTTP '+resp.status+'): '+txt.replace(/<[^>]*>/g,' ').trim().slice(0,140)); }
				if(!resp.ok && !('success' in d)){ throw new Error('HTTP '+resp.status); }
				return d;
			}
			function label(t){ return t==='gebraucht'?'Gebraucht':(t==='rennsport'?'Rennsport':(t==='varianten'?'Varianten':(t==='seotitel'?'SEO-Titel':'Bilder / Galerie'))); }
			async function run(type){
				if(busy) return; setBusy(true); wrap.style.display='';
				var token='', offset=0, total=0, retries=0, calls=0, acc={new:0,skipped:0,img_pending:0,unresolved:0,errors:0};
				st.textContent=label(type)+': starte …'; bar.style.width='0';
				if(type==='media'){ document.getElementById('m24imp-diag').open=true; refreshLog(); }
				while(true){
					var d;
					try{ d=await call(type,token,offset); retries=0; }
					catch(e){
						retries++;
						if(retries<=2){ st.textContent=label(type)+': Wiederhole Chunk ('+retries+'/2) …'; await sleep(1500*retries); continue; }
						st.textContent='Abgebrochen: '+e.message+' — erneut klicken setzt fort.'; if(type==='media'){ refreshLog(); } break;
					}
					if(!d.success){ st.textContent='Fehler: '+((d.data&&(d.data.message||d.data))||'unbekannt')+' — erneut klicken setzt fort.'; break; }
					d=d.data; token=d.token; total=d.total; offset=d.offset; calls++;
					acc.new+=d.new; acc.skipped+=d.skipped; acc.unresolved+=d.unresolved; acc.errors+=d.errors; acc.img_pending=d.img_pending;
					var pct=total?Math.round(offset/total*100):100;
					bar.style.width=pct+'%';
					var sfx=(type==='media')?(' · Produkt '+(offset+(d.img_pending?1:0))+' '+(d.stage==='seed'?'(lade Bildliste)':(d.img_pending?('· noch '+d.img_pending+' Bilder'):''))):'';
					st.textContent=label(type)+': '+offset+' / '+total+' ('+pct+'%)'+sfx;
					ct.textContent=(type==='media'?'Bilder geladen: ':'neu/aktualisiert: ')+acc.new+' · übersprungen: '+acc.skipped+(d.img_pending?(' · offen (Produkt): '+d.img_pending):'')+(acc.unresolved?(' · unresolved: '+acc.unresolved):'')+(acc.errors?(' · Fehler: '+acc.errors):'');
					if(type==='media' && calls%15===0){ refreshLog(); }
					if(d.done){ st.textContent=label(type)+': fertig — '+total+' verarbeitet ('+acc.new+' Bilder geladen).'; if(type==='media'){ refreshLog(); } break; }
				}
				setBusy(false);
			}
			buttons.forEach(function(b){ b.addEventListener('click',function(){ run(b.getAttribute('data-m24imp')); }); });
			document.getElementById('m24imp-log-refresh').addEventListener('click',refreshLog);
			document.getElementById('m24imp-log-clear').addEventListener('click',async function(){
				var fd=new FormData(); fd.append('action','m24_import_log_clear'); fd.append('_nonce',NONCE);
				try{ await fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd}); }catch(e){}
				refreshLog();
			});
			document.getElementById('m24imp-opcache-reset').addEventListener('click',async function(){
				var b=this; b.disabled=true; var old=b.textContent; b.textContent='leere …';
				var fd=new FormData(); fd.append('action','m24_opcache_reset'); fd.append('_nonce',NONCE);
				try{ var r=await fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd}); var d=JSON.parse(await r.text());
					b.textContent=(d&&d.success&&d.data&&d.data.ok)?'OPcache geleert ✓':'kein OPcache aktiv';
				}catch(e){ b.textContent='Fehler'; }
				setTimeout(function(){ b.textContent=old; b.disabled=false; },2500); refreshLog();
			});
			refreshLog();
		})();
		</script>
		<?php
	}
}
