# Änderungsprotokoll – Countr Analytics

Alle wesentlichen Änderungen dieses Projekts werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),  
und dieses Projekt hält sich an [Semantic Versioning](https://semver.org/lang/de/).

---

## [1.0.0] – 2026-01-15

### Hinzugefügt

- **Initiale Version** des dateibasierten Web-Analytics-Systems – keine Datenbank erforderlich
- **Reine JSON-Dateispeicherung** für maximale Portabilität und einfaches Shared-Hosting
- **Atomare Dateioperationen** mit `flock()` für Race-Condition-Schutz unter parallelen Zugriffen
- **Echtzeit-Tracking** mit Write-Buffering (10 Hits pro Batch), um Datei-I/O zu minimieren
- **Vollständiges Admin-Dashboard** mit 5 Tabs:
  - Übersicht (Live-Daten, KPI-Karten)
  - Besucher (detaillierte Auflistung, Suche, Filter)
  - Seiten (meistbesuchte URLs mit Sortierung)
  - Statistiken (Tages-, Wochen-, Monatsauswertung)
  - Einstellungen (alle Tracking- und Sicherheitsoptionen)
- **Responsive Design** – optimiert für Mobilgeräte, Tablets und Desktop
- **Dark Mode Support** – automatische Erkennung von `prefers-color-scheme: dark`
- **4 interaktive Chart.js-Diagramme**:
  - Besucher der letzten 7 Tage (Balkendiagramm)
  - Besucher der letzten 30 Tage (Liniendiagramm)
  - Heutige stündliche Verteilung (Flächendiagramm)
  - Browserverteilung (Doughnut-Diagramm)
- **Automatisches Setup-System** mit 3-Schritt-Assistent:
  - Systemprüfung (PHP-Version, Extensions, Schreibrechte)
  - Konfiguration (Seitendaten, Admin-Passwort, Zeitzone)
  - Abschluss mit Tracking-Code-Ausgabe und Setup-Selbstdeaktivierung
- **GDPR-konforme IP-Anonymisierung** – letztes Oktett wird entfernt
- **Cookie-freies Tracking** – keine Einwilligung erforderlich
- **30+ Bot-Erkennungsmuster** – Crawler, Spider, Bots werden automatisch gefiltert
- **Browser-/OS-/Device-Erkennung** aus User-Agent-String
- **Rate Limiting** zum Schutz vor Missbrauch
- **CSV-/JSON-/Excel-Export** aller Statistiken aus dem Admin-Bereich
- **API-Schlüssel-Generierung** für externe Integrationen
- **Demo-Daten-Generierung** (30 Tage Testdaten für sofortige Auswertung)
- **Multi-User-Support** (vorbereitet, konfigurierbar)
- **Automatisches Backup** des Initialzustands nach dem Setup
- **Verzeichnisstruktur mit .htaccess-Schutz**:
  - `data/visitors/` – Tägliche Besucherdaten
  - `data/sessions/` – Session-Dateien
  - `data/logs/` – Fehler- und Access-Logs
  - `data/backups/` – Automatische Backups
  - `data/exports/` – CSV/JSON/Excel-Exports
  - `cache/` – Performance-Cache
- **Apache .htaccess** mit Rewrite-Rules, Sicherheitsheadern und GZIP-Kompression
- **Tracking-Einbindung** via Script-Tag, Image-Pixel oder JavaScript-Fetch-API

### Sicherheit

- **Bcrypt-Passwort-Hashing** für Admin-Zugang
- **Alle sensiblen Verzeichnisse** mit `.htaccess` und `index.html` geschützt
- **Setup.php** deaktiviert sich nach erfolgreicher Installation selbst
- Rate Limiting für `track.php` und `admin.php`
- Keine Speicherung von Roh-IP-Adressen (nur anonymisierte Hashes)

---

## Legende

| Symbol | Bedeutung |
|--------|-----------|
| 🚀     | Neue Funktion |
| 🔒     | Sicherheitsrelevant |
| 🐛     | Fehlerbehebung |
| ⚡     | Performance-Verbesserung |
| 📝     | Dokumentation |
| 🔧     | Konfigurationsänderung |
| 🗑️     | Entfernt |

---

*Countr Analytics ist ein Produkt mit ❤️ entwickelt für die Open-Source-Community.*