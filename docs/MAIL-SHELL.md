# M24 Mail-Shell — die EINE kanonische Vorlage

Alle transaktionalen E-Mails des Plugins rendern **denselben** Rahmen. Es gibt strukturell
**nur eine Shell**: `m24_mail_shell()` in `includes/inquiry-mail-template.php`.
Referenz-Optik = das IL-/Off-Market-DOI-Design.

## Verwendung

```php
$inner = '<p>Hallo,</p>… beliebiges Body-HTML (Überschrift kommt aus $headline) …';
$html  = m24_mail_shell( 'Betreff-/H1-Zeile', $inner, array(
    'lang'         => 'de',          // optional: Sprach-Switch DE|EN im Footer
    'footer_extra' => '<a …>Opt-out</a>', // optional: HTML über dem Footer-Block (z. B. §7-Link)
) );
wp_mail( $to, $subject, $html, $headers );
```

Jede Mail liefert nur ihren **Body** (`$inner`: Anrede/Text/CTA/Inhalt). Header, Footer und
Container sind überall identisch — keine zweite Shell, kein Sonderlayout.

## Struktur & Tokens

- **Header:** weißes Logo **rechtsbündig** auf blauem 135°-Verlauf `#1f74c4 → #0e447e`
  (Logo via Filter `m24fz_mail_logo_url`, weiße Variante `Logo-MOTORSPORT24.de_.gif`).
- **Body:** Font **Saira** (self-hosted `@font-face`, Fallback Arial/Helvetica), 600px weiße Karte,
  28px L/R-Ränder, `H1` (`$headline`) + `$inner`. Textfarbe `#3a414c`, Überschrift `#10243a`.
- **Footer:** Trennlinie + zentriert:
  - `Classic & Race Cars and Parts Sales since 2006`
  - `Unsere Postanschrift lautet:`
  - `MOTORSPORT24 GmbH, Scharfe Lanke 109-131, Haus 113a, 13595 Berlin, Deutschland`
  - `Impressum · Datenschutz · www.motorsport24.de`
  - `Sprache ändern: DE | EN` (via `M24_I18n::mail_lang_footer()`)
- **Hintergrund:** `#f2f4f7`. **Akzent/Links:** `#1f74c4`.

## Wer nutzt die Shell

Alle diese rendern durch `m24_mail_shell()` (direkt oder per Delegation):

| Mail | Quelle | delegiert via |
|------|--------|---------------|
| Garage-Mail (Teile an Kunden) | `M24_Garage_Cart::handle_send` | direkt |
| Fahrzeug-Alert Preis/Verkauft (+ Bild-Mosaik) | `M24_Garage_Alerts::build_mail` | direkt |
| Neue-Anfrage (Betreiber) | `M24_Inquiries_Mail_Fallback::build_html_body` | direkt |
| Händler-Registrierung-DOI + Magic-Login | `M24_B2B_Auth::mail_html` | Delegation |
| IL-DOI / Off-Market-DOI / Parken-DOI / Reminder | `M24_Brevo_IL::mail_html` | Delegation |

Ausnahme: die kundenseitige Produktanfrage-Mail „Variante A"
(`m24_render_inquiry_email`) bleibt ein eigenes, positions-orientiertes Layout und ist **keine**
Shell-Mail (anderer Zweck: strukturierte Positionsanfrage an den Vertrieb).

## List-Unsubscribe (Header-Hygiene)

`List-Unsubscribe` gehört **nur** auf Marketing-/DOI-Mails (IL-/Off-Market-/Parken-DOI, Reminder,
opt-in Fahrzeug-Alerts) → dort erscheint der Gmail-„Mailing-Liste"-Banner zu Recht.
**1:1-Transaktionsmails (Garage-Mail, Neue-Anfrage) setzen KEIN `List-Unsubscribe`.**
Hinweis: Brevo kann abhängig vom Kontakt-/Listen-Status serverseitig einen eigenen
Unsubscribe-Header ergänzen — das ist eine Brevo-Einstellung des Transaktions-Streams, nicht Code.

## Neue Mails

Immer `m24_mail_shell()` verwenden — **nie** einen eigenen `<html>`/Header/Footer bauen.
Body als `$inner` übergeben, ggf. `lang` + `footer_extra`. So erbt jede neue Mail automatisch
Header, Footer und CI.
