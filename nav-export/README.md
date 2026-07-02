# nav-export — Header/Navigation (read-only Zusammenstellung)

> Rein lesende Extraktion. **Es wurde nichts am Code geändert** — nur Kopien + eine CSS-Zusammenstellung.

## ⚠️ Wichtigste Erkenntnis zuerst

Dieses Repository ist das **Plugin `m24-plattform`**, **nicht das Theme**. Der eigentliche
Kopf-/Navigations-Header wird vom **Theme** gerendert, das **nicht in diesem Repo** liegt.

- **Theme:** **tagDiv „Newspaper"** (Child/Parent nicht hier vorhanden). Der Header ist ein
  **tagDiv-Block-Header** (gebaut mit dem *tagDiv Cloud Library / Theme Panel*, gespeichert als
  Block-Template in der DB — **keine** klassische `header.php` und **kein** `wp_nav_menu()` im Code).
- **Es gibt daher hier NICHT:** `header.php`, `template-parts/header/*`, einen `Walker_Nav_Menu`
  oder einen `wp_nav_menu()`-Aufruf. (Einzige Fundstelle „wp_nav_menu" ist ein **Kommentar** in
  `class-m24-b2b-header-login.php`, der genau erklärt, dass `wp_nav_menu_items` bei tagDiv-Block-
  Menüs **nicht** feuert.)
- **Page-Builder:** tagDiv Composer (Block-Header/-Templates). Content teils WPBakery-Reste; für M24
  bewusst **kein** Page-Builder.
- **Multilingual:** **kein** WPML/Polylang. Übersetzung via **GTranslate** (URL-Translation-Add-on,
  `/en/`-Pfad-Präfix). Der DE/EN-Umschalter ist **plugin-eigen** (siehe unten) und verlinkt echte
  `/en/`-Pendant-URLs.

Weil der Theme-Header nicht editierbar im Code vorliegt, **injiziert das Plugin** seine Header-
Elemente (Konto/Login, Sprach-Switch) per `wp_footer` + JavaScript in die sichtbaren tagDiv-Header-
Actions (neben das Such-Icon). Genau diese Plugin-Schicht ist hier exportiert.

---

## Dateien in diesem Export

| Datei | Rolle |
|-------|-------|
| `class-m24-b2b-header-login.php` | **Konto/Login-Element „D"** (G2a). Rendert ausgeloggt einen Outline-Chip „Anmelden" → `/haendler-login/` (bestehende Magic-Link-Strecke), eingeloggt Messing-Avatar + „Mein Konto ▾"-Dropdown (Meine Garage, E-Mail-Einstellungen, Abmelden; Admin: WP-Admin). Feature-Flag `m24_magiclink_enabled`. |
| `m24-header-login.js` | Platziert das obige Element als **Sibling vor den Such-Button** in die Header-Actions (Fallback: fixe Ecke) + verdrahtet das Dropdown. |
| `class-m24-login.php` | **Passwordless Magic-Link-Login** („D", separate/neuere Strecke). Header-Chip/Avatar + **Modal**, REST-Request, `/m24-login/{token}`-Verify. Feature-Flag `m24_login_enabled` (Default AUS). Ist es aktiv, tritt das G2a-Element oben zurück (kein Doppel-Login). |
| `m24-login.js` | Header-Injektion + Modal (Focus-Trap) + Request-Fetch für die passwordless-Strecke. |
| `m24-login.css` | Styles für Chip/Avatar/Dropdown **und das Modal** der passwordless-Strecke. |
| `class-m24-i18n.php` | Enthält u. a. den **DE/EN-Sprach-Switch** (`langswitch_urls()`, `langswitch_assets()`, `langswitch_head()`), i18n-Cookie und den GTranslate-Marken/Flaggen-`notranslate`-Footer-Fix. |
| `m24-langswitch.js` | Baut „🌐 DE · EN" und platziert es als Sibling vor den Such-Button (Desktop) + eine Instanz ins Mobil-Menü. |
| `nav-styles.css` | **Zusammengeführte** Header-Styles der beiden injizierten Elemente (im Plugin liegen sie inline in PHP). |

---

## 1) Header-Markup

**Nicht im Repo** (tagDiv-Block-Header, DB-gespeichert). Die einzigen plugin-gerenderten Header-
Fragmente sind:
- `class-m24-b2b-header-login.php` → `render()` gibt `#m24-b2b-login` (versteckt) aus, JS platziert es.
- `class-m24-login.php` → `render()` gibt `#m24-login-modal` + Header-Trigger aus.

## 2) Menü-Ausgabe / Walker

**Kein `wp_nav_menu()` und kein `Walker_Nav_Menu`** im Plugin (tagDiv rendert das Menü als Block).
Beleg — Kommentar in `class-m24-b2b-header-login.php`:

```
// tagDiv nutzt Block-Menüs (.tdb-block-menu / #menu-header-menu-2) → wp_nav_menu_items feuert dort NICHT.
```

Deshalb die JS-Injektion in Container wie `.tdb-head-search-btn`, `.tdb_header_search`,
`.tdb-header-search-wrap`, `.td-header-menu-social`, `#menu-header-menu-2`, `.td-mobile-main-menu`.

## 3) Login-/Account-Logik (ein-/ausgeloggt)

In `class-m24-b2b-header-login.php` → `render()`:
- **Ausgeloggt** (`! is_user_logged_in()`): `.m24hl-chip` „Anmelden" → `wp_logout_url`/`home_url('/haendler-login/')`.
- **Eingeloggt** (`is_user_logged_in()`): `.m24hl-accbtn` (Avatar-Initiale) + `.m24hl-menu`-Dropdown;
  Abmelden via `wp_logout_url( home_url('/') )`; „WP-Admin" nur bei `current_user_can('manage_options')`.

In `class-m24-login.php` (passwordless): analoge Zustände, plus Modal + `wp_set_auth_cookie` im Verify.
Der Break-Glass-Login bleibt immer `wp-login.php` (bzw. `?m24_classic=1`).

## 4) Sprach-Switch (DE/EN)

`class-m24-i18n.php` → `langswitch_urls()` leitet aus dem `/en/`-Pfad die DE- und EN-Ziel-URL der
**aktuellen** Seite ab (Query bleibt erhalten); `m24-langswitch.js` rendert „🌐 DE · EN" mit echten
`<a href>` (kein reiner JS-Toggle) + Hover-Tooltip (🇩🇪 Deutsch / 🇬🇧 English). `hreflang` ist optional
(`m24_hreflang_enabled`, Default AUS — GTranslate gibt i. d. R. selbst Alternates aus).

## 5) CSS / Klassennamen

Siehe `nav-styles.css` (G2a `.m24hl-*` + Switch `.m24langsw-*`) und `m24-login.css` (passwordless
`.m24lg-*`). Zentrale Klassen:
- Konto/Login G2a: `.m24hl-acct`, `.m24hl-chip`, `.m24hl-avatar`, `.m24hl-accbtn`, `.m24hl-menu`, `.m24hl-item`
- Passwordless: `.m24lg-chip`, `.m24lg-avatar`, `.m24lg-accbtn`, `.m24lg-menu`, `.m24lg-modal`
- Sprach-Switch: `.m24langsw`, `.m24langsw-lnk`, `.m24langsw-lnk.is-active`, `.m24langsw-tip`
- tagDiv-Ziel-Container (nur getroffen, nicht besessen): `.tdb-head-search-btn`, `.tdb_header_search`,
  `.tdb-header-search-wrap`, `.td-header-menu-social`, `.td-mobile-main-menu`.

## 6) JS (Dropdown / Mobile / Panel)

`m24-header-login.js`, `m24-login.js`, `m24-langswitch.js` (alle mitkopiert). Muster: sichtbaren
Container über `offsetParent!==null` wählen (nie den `display:none`-Mobil-Wrap), Element als Sibling
vor den Such-Button einfügen, Retry-Interval wegen tagDivs JS-Nachbau, Fallback = fixe Ecke.

## 7) Enqueue-Info (kein `functions.php` — Plugin-Klassen)

Das Plugin hat **keine** `functions.php`; die Assets werden in den Klassen per `wp_enqueue_scripts` geladen:

| Handle | Datei | Quelle |
|--------|-------|--------|
| `m24-header-login` | `assets/js/m24-header-login.js` | `class-m24-b2b-header-login.php` → `assets()` |
| `m24-login` (script + style) | `assets/js/m24-login.js`, `assets/css/m24-login.css` | `class-m24-login.php` → `assets()` (+ `wp_localize_script('m24-login','M24Login',…)`) |
| `m24-langswitch` | `assets/js/m24-langswitch.js` | `class-m24-i18n.php` → `langswitch_assets()` (+ `wp_localize_script('m24-langswitch','M24Lang',…)`) |

Die zugehörigen CSS-Regeln der beiden injizierten Header-Elemente werden **inline** im `<head>`
ausgegeben (`class-m24-b2b-header-login.php` → `styles()`; `class-m24-i18n.php` → `langswitch_head()`) —
für diesen Export in `nav-styles.css` zusammengeführt.

---

## Zusammenhang der Header-Dateien (Kurzüberblick)

```
tagDiv „Newspaper" Block-Header (THEME, DB, NICHT im Repo)
        ▲  injiziert per wp_footer + JS in die sichtbaren Header-Actions
        │
 ┌──────┴───────────────────────────────────────────────────────────┐
 │ PLUGIN m24-plattform                                              │
 │                                                                   │
 │  Sprach-Switch     class-m24-i18n.php  +  m24-langswitch.js       │
 │  Login (G2a)       class-m24-b2b-header-login.php + m24-header-login.js
 │  Login passwordless class-m24-login.php + m24-login.js + m24-login.css
 │                                                                   │
 │  Flags: m24_magiclink_enabled (G2a) · m24_login_enabled (passwordless, Default AUS)
 └───────────────────────────────────────────────────────────────────┘
```

**Für einen echten Header-/Menü-Umbau** brauchst du zusätzlich das **tagDiv-Theme** bzw. den
Block-Header aus dem *tagDiv Cloud Library / Theme Panel* (WP-Admin) — der liegt außerhalb dieses
Plugin-Repos.
