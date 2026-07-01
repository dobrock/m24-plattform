# Benötigte Binär-Assets für das PDF-Briefpapier (0.11.175)

Diese Dateien sind NICHT im Git (Binaries) und müssen manuell hier abgelegt werden,
damit die Exposé-PDFs Liberation Sans + das Farb-Logo nutzen. Fehlen sie, greift ein
sauberer Fallback (Arial-Stack bzw. bestehendes PNG-Logo) — das PDF bricht nicht.

- assets/fonts/LiberationSans-Regular.ttf   (Paket „fonts-liberation" / GitHub liberationfonts)
- assets/fonts/LiberationSans-Bold.ttf
- assets/img/motorsport24-logo.jpg          (Farbe, 600×135 px)

## Dompdf-Font-Cache
Der Font-Cache liegt in wp-content/uploads/m24-dompdf-fonts/ (beschreibbar, nicht in vendor/).
Nach dem Austausch der TTFs diesen Ordner LEEREN, damit Dompdf die Fonts neu einliest.
