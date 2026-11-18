<?php
/**
 * M24 Plattform — Teilen-Leiste (E-Mail · WhatsApp · Link kopieren) für Seiten außerhalb der Teile-Detailseite.
 * Die Teile-Detailseite bringt ihr eigenes Markup mit (modules/katalog/catalog-template-detail.php).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Share_Bar {

	private static $assets_done = false;

	/** HTML der Leiste. $class: Zusatzklasse für seiten-spezifische Abstände. */
	public static function render( string $url, string $title, string $class = '' ): string {
		$mail = 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $title . "\n\n" . $url );
		$wa   = 'https://wa.me/?text=' . rawurlencode( $title . "\n\n" . $url );
		$svg  = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
		$h    = '';
		if ( ! self::$assets_done ) {
			self::$assets_done = true;
			$h .= '<style>.m24-sharebar{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}'
				. '.m24-sharebar-b{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:9px 4px;border:1px solid rgba(0,0,0,.12);border-radius:8px;background:#fff;color:#1b1e22;font:inherit;font-size:13px;line-height:1.2;text-decoration:none;cursor:pointer;min-height:44px}'
				. '.m24-sharebar-b:hover{border-color:#9aa3ad;color:#1b1e22}.m24-sharebar-b svg{width:20px;height:20px;color:#6b7077}</style>';
		}
		$h .= '<div class="m24-sharebar ' . esc_attr( $class ) . '">'
			. '<a class="m24-sharebar-b" href="' . esc_url( $mail ) . '"><svg ' . $svg . '><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><span>E-Mail</span></a>'
			. '<a class="m24-sharebar-b" href="' . esc_url( $wa ) . '" target="_blank" rel="noopener"><svg ' . $svg . '><path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9L3 21"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/></svg><span>WhatsApp</span></a>'
			. '<button type="button" class="m24-sharebar-b m24-sharebar-copy"><svg ' . $svg . '><path d="M9 15l6-6"/><path d="M11 6l.46-.54a5 5 0 0 1 7.07 7.07L18 13"/><path d="M13 18l-.4.53a5 5 0 0 1-7.12-7.03L6 11"/></svg><span class="m24-sharebar-copy-t">Link kopieren</span></button>'
			. '</div>';
		$h .= "<script>(function(){var en=/^\\/en(\\/|$)/.test(location.pathname);document.querySelectorAll('.m24-sharebar-copy').forEach(function(b){if(b.dataset.m24)return;b.dataset.m24='1';var t=b.querySelector('.m24-sharebar-copy-t');b.addEventListener('click',function(){var u=location.href.split('#')[0].split('?')[0],alt=t.textContent,ok=function(){t.textContent=en?'Copied':'Kopiert';setTimeout(function(){t.textContent=alt;},1800);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(u).then(ok,function(){});}else{var i=document.createElement('input');i.value=u;document.body.appendChild(i);i.select();try{document.execCommand('copy');ok();}catch(e){}document.body.removeChild(i);}});});})();</script>";
		return $h;
	}
}
