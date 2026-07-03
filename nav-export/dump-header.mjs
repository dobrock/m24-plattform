import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
await p.goto('https://www.motorsport24.de/', { waitUntil: 'networkidle' });
await p.waitForTimeout(2500); // tagDiv baut den Header per JS nach
const out = await p.evaluate(() => {
  const clip = (el, n = 1800) => el ? el.outerHTML.slice(0, n) : null;
  const s = document.querySelector('.tdb-head-search-btn, .tdb_header_search, .tdb-search-icon, .td-icon-search');
  return {
    suche_container: s ? clip(s.closest('.tdb-block-inner, .td-header-menu-wrap, .tdb-header-align, .wpb_wrapper') || s) : 'KEIN Such-Icon',
    suche_pfad: s ? (function(e){let a=[];while(e&&a.length<8){a.unshift(e.tagName.toLowerCase()+(typeof e.className==='string'&&e.className?'.'+e.className.trim().split(/\s+/).join('.'):''));e=e.parentElement;}return a.join(' > ');})(s) : null,
    konto: clip(document.querySelector('.m24hl-acct'), 600),
    konto_parent: (document.querySelector('.m24hl-acct')?.parentElement)?.tagName + '.' + (document.querySelector('.m24hl-acct')?.parentElement?.className || ''),
    switch: clip(document.querySelector('.m24langsw'), 600),
    willkommen: [...document.querySelectorAll('*')].filter(e => [...e.childNodes].some(n => n.nodeType===3 && /Willkommen/i.test(n.nodeValue))).map(e => e.tagName + '.' + (typeof e.className==='string'?e.className:'')).slice(0,6),
  };
});
console.log(JSON.stringify(out, null, 2));
await b.close();
