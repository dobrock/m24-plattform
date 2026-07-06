<?php
/**
 * M24 — Statistik-Panel (gemeinsame Komponente, VORERST PLATZHALTER).
 *
 * Rechte Spalte der Admin-Seiten Anfragen (m24-anfragen) und Angebote (m24-offers): Kacheln + Zeitraum-Pills
 * + Mini-Trichter mit STATISCHEN Dummy-Werten und Badge „Vorschau — Daten folgen". Zeitraum-Pills schalten
 * optisch (Dummy-Daten je Zeitraum), noch OHNE echte Abfrage. Keine Backend-Aggregation in diesem Schritt.
 *
 * open_layout()/close_layout() klammern die bestehende Karten-Liste in ein zweispaltiges Grid
 * (Karten links, Panel rechts sticky; schmal → untereinander).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Stats_Panel {

	public static function open_layout(): void {
		echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput — statisches CSS
		echo '<div class="m24stats-layout"><div class="m24stats-main">';
	}

	public static function close_layout( string $context = 'offers' ): void {
		echo '</div><aside class="m24stats-side">' . self::panel_html( $context ) . '</aside></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::js(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function panel_html( string $context ): string {
		// Dummy-Startwerte (Monat) — die Pills schalten per JS auf andere Dummy-Sätze.
		return '<div class="m24stats-panel">'
			. '<div class="m24stats-h"><span>Statistik</span><span class="m24stats-badge">Vorschau — Daten folgen</span></div>'
			. '<div class="m24stats-period" data-m24stats-period>'
			. '<button type="button" data-p="w">Woche</button>'
			. '<button type="button" class="on" data-p="m">Monat</button>'
			. '<button type="button" data-p="q">Quartal</button>'
			. '<button type="button" data-p="j">Jahr</button>'
			. '</div>'
			. '<div class="m24stats-range" data-m24stats-range>Juli 2026 · vs. Juni 2026</div>'
			. '<div class="m24stats-tiles">'
			. self::tile( 'Anfragen eingegangen', 'v_anf', '42', 't_anf', '▲ 18 %', true )
			. self::tile( 'Angebote verschickt', 'v_ver', '31', 't_ver', '▲ 9 %', true )
			. '<div class="m24stats-tile"><div class="k">Angebote angenommen</div><div class="v" id="m24s_v_ang">19</div><span class="m24stats-trend down" id="m24s_t_ang">▼ 4 %</span><div class="sub" id="m24s_s_quote">Annahmequote 61 %</div></div>'
			. self::tile( 'Angebotssumme', 'v_asum', '84,2<small> Tsd €</small>', 't_asum', '▲ 12 %', true )
			. '<div class="m24stats-tile money wide"><div class="k">Auftragssumme (angenommen)</div><div class="v" id="m24s_v_osum">51,8<small> Tsd €</small></div><span class="m24stats-trend up" id="m24s_t_osum">▲ 15 %</span><div class="sub">Ø Auftragswert 2.726 € · vs. Vormonat +6 %</div></div>'
			. '</div>'
			. '<div class="m24stats-funnel"><div class="k">Trichter (dieser Zeitraum)</div>'
			. '<div class="frow"><span class="lab">Anfragen</span><div class="bar" style="width:150px"></div><span class="num" id="m24s_f_anf">42</span></div>'
			. '<div class="frow b2"><span class="lab">Angebote</span><div class="bar" style="width:110px"></div><span class="num" id="m24s_f_ver">31</span></div>'
			. '<div class="frow b3"><span class="lab">Aufträge</span><div class="bar" style="width:68px"></div><span class="num" id="m24s_f_ang">19</span></div>'
			. '</div>'
			. '<div class="m24stats-foot">Trend jeweils vs. vorheriger Zeitraum gleicher Länge · Kontext: ' . esc_html( 'inquiries' === $context ? 'Anfragen' : 'Angebote' ) . '</div>'
			. '</div>';
	}

	private static function tile( string $k, string $vid, string $v, string $tid, string $t, bool $up ): string {
		return '<div class="m24stats-tile"><div class="k">' . esc_html( $k ) . '</div><div class="v" id="m24s_' . esc_attr( $vid ) . '">' . $v // phpcs:ignore WordPress.Security.EscapeOutput — kontrollierter Dummy-String
			. '</div><span class="m24stats-trend ' . ( $up ? 'up' : 'down' ) . '" id="m24s_' . esc_attr( $tid ) . '">' . esc_html( $t ) . '</span></div>';
	}

	private static function css(): string {
		return '<style id="m24stats-css">'
			. '.m24stats-layout{display:grid;grid-template-columns:1fr 372px;gap:22px;align-items:start;margin-top:14px;max-width:1400px}'
			. '.m24stats-side{position:sticky;top:32px}'
			. '.m24stats-panel{font-family:Saira,Arial,sans-serif}'
			. '.m24stats-h{display:flex;align-items:center;justify-content:space-between;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#787f87;font-weight:700;margin-bottom:10px}'
			. '.m24stats-badge{background:#f6efe4;color:#9a6b25;border-radius:20px;padding:3px 10px;font-size:9.5px;letter-spacing:.04em}'
			. '.m24stats-period{display:flex;gap:4px;background:#fff;border:1px solid #e8e8ec;border-radius:10px;padding:4px;margin-bottom:14px}'
			. '.m24stats-period button{flex:1;border:0;background:transparent;border-radius:7px;padding:7px 4px;font:600 12.5px Saira,sans-serif;color:#787f87;cursor:pointer}'
			. '.m24stats-period button.on{background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff}'
			. '.m24stats-period button:hover:not(.on){background:#f2f5f8}'
			. '.m24stats-range{font-size:11.5px;color:#787f87;margin:-6px 0 14px;text-align:center}'
			. '.m24stats-tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px}'
			. '.m24stats-tile{background:#fff;border:1px solid #e8e8ec;border-radius:12px;padding:13px 14px}'
			. '.m24stats-tile.wide{grid-column:1/-1}'
			. '.m24stats-tile .k{font-size:11px;color:#787f87;font-weight:600;margin-bottom:6px}'
			. '.m24stats-tile .v{font-size:23px;font-weight:800;line-height:1}'
			. '.m24stats-tile .v small{font-size:14px;font-weight:700;color:#787f87}'
			. '.m24stats-trend{display:inline-flex;align-items:center;gap:3px;font-size:11.5px;font-weight:700;border-radius:20px;padding:2px 8px;margin-top:8px}'
			. '.m24stats-trend.up{background:#e6f4ea;color:#1a7f37}.m24stats-trend.down{background:#fbeae9;color:#b3261e}'
			. '.m24stats-tile .sub{font-size:10.5px;color:#787f87;margin-top:6px}'
			. '.m24stats-tile.money{background:linear-gradient(135deg,#1f74c4,#0e447e);border-color:transparent;color:#fff}'
			. '.m24stats-tile.money .k,.m24stats-tile.money .v,.m24stats-tile.money .sub{color:#fff}.m24stats-tile.money .v small{color:rgba(255,255,255,.8)}'
			. '.m24stats-tile.money .m24stats-trend.up{background:rgba(255,255,255,.18);color:#fff}'
			. '.m24stats-funnel{background:#fff;border:1px solid #e8e8ec;border-radius:12px;padding:14px;margin-top:10px}'
			. '.m24stats-funnel .k{font-size:11px;color:#787f87;font-weight:600;margin-bottom:10px}'
			. '.m24stats-funnel .frow{display:flex;align-items:center;gap:10px;margin-bottom:8px;font-size:12px}'
			. '.m24stats-funnel .frow .bar{height:22px;border-radius:6px;background:linear-gradient(135deg,#1f74c4,#0e447e)}'
			. '.m24stats-funnel .frow .lab{width:80px;font-weight:600}.m24stats-funnel .frow.b2 .bar{opacity:.8}.m24stats-funnel .frow.b3 .bar{background:#9a6b25}'
			. '.m24stats-funnel .frow .num{margin-left:auto;font-weight:700}'
			. '.m24stats-foot{font-size:10.5px;color:#787f87;text-align:center;margin-top:12px}'
			. '@media(max-width:1100px){.m24stats-layout{grid-template-columns:1fr}.m24stats-side{position:static}}'
			. '</style>';
	}

	private static function js(): string {
		return '<script>(function(){'
			. 'var D={'
			. 'w:{range:"KW 27 · vs. KW 26",anf:11,anf_t:"▲ 22 %",anf_u:1,ver:8,ver_t:"▲ 14 %",ver_u:1,ang:5,ang_t:"▲ 25 %",ang_u:1,quote:"Annahmequote 63 %",asum:"21,4",asum_t:"▲ 8 %",asum_u:1,osum:"13,1",osum_t:"▲ 19 %",osum_u:1},'
			. 'm:{range:"Juli 2026 · vs. Juni 2026",anf:42,anf_t:"▲ 18 %",anf_u:1,ver:31,ver_t:"▲ 9 %",ver_u:1,ang:19,ang_t:"▼ 4 %",ang_u:0,quote:"Annahmequote 61 %",asum:"84,2",asum_t:"▲ 12 %",asum_u:1,osum:"51,8",osum_t:"▲ 15 %",osum_u:1},'
			. 'q:{range:"Q3 2026 · vs. Q2 2026",anf:128,anf_t:"▲ 11 %",anf_u:1,ver:97,ver_t:"▲ 7 %",ver_u:1,ang:58,ang_t:"▲ 3 %",ang_u:1,quote:"Annahmequote 60 %",asum:"248",asum_t:"▲ 9 %",asum_u:1,osum:"151",osum_t:"▲ 6 %",osum_u:1},'
			. 'j:{range:"2026 · vs. 2025",anf:503,anf_t:"▲ 27 %",anf_u:1,ver:388,ver_t:"▲ 21 %",ver_u:1,ang:236,ang_t:"▲ 24 %",ang_u:1,quote:"Annahmequote 61 %",asum:"1,02",asum_t:"▲ 23 %",asum_u:1,osum:"0,63",osum_t:"▲ 25 %",osum_u:1}'
			. '};'
			. 'function g(id){return document.getElementById(id);}'
			. 'function tr(id,txt,up){var e=g(id);if(e){e.textContent=txt;e.className="m24stats-trend "+(up?"up":"down");}}'
			. 'function setP(p){var d=D[p];if(!d)return;var u=(p==="j")?" Mio €":" Tsd €";'
			. 'if(g("m24s_v_anf"))g("m24s_v_anf").textContent=d.anf;tr("m24s_t_anf",d.anf_t,d.anf_u);'
			. 'if(g("m24s_v_ver"))g("m24s_v_ver").textContent=d.ver;tr("m24s_t_ver",d.ver_t,d.ver_u);'
			. 'if(g("m24s_v_ang"))g("m24s_v_ang").textContent=d.ang;tr("m24s_t_ang",d.ang_t,d.ang_u);'
			. 'if(g("m24s_s_quote"))g("m24s_s_quote").textContent=d.quote;'
			. 'if(g("m24s_v_asum"))g("m24s_v_asum").innerHTML=d.asum+"<small>"+u+"</small>";tr("m24s_t_asum",d.asum_t,d.asum_u);'
			. 'if(g("m24s_v_osum"))g("m24s_v_osum").innerHTML=d.osum+"<small>"+u+"</small>";tr("m24s_t_osum",d.osum_t,d.osum_u);'
			. 'var r=document.querySelector("[data-m24stats-range]");if(r)r.textContent=d.range;'
			. 'if(g("m24s_f_anf"))g("m24s_f_anf").textContent=d.anf;if(g("m24s_f_ver"))g("m24s_f_ver").textContent=d.ver;if(g("m24s_f_ang"))g("m24s_f_ang").textContent=d.ang;'
			. '}'
			. 'var w=document.querySelector("[data-m24stats-period]");if(w){w.addEventListener("click",function(e){var b=e.target.closest("button[data-p]");if(!b)return;'
			. 'w.querySelectorAll("button").forEach(function(x){x.classList.remove("on");});b.classList.add("on");setP(b.getAttribute("data-p"));});}'
			. '})();</script>';
	}
}
