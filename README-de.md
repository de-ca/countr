Lesen Sie dies auf Englisch: [Read this in English](README.md)

![](github-banner-de.svg)

---

# Countr Analytics – Moderne SQLite Web-Analytics

<div align="center">

📊 **Single-File SQLite. Keine Cookies. Keine Probleme.**

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.0.0-orange)](#)

</div>

---

## 📊 Überblick

**Countr Analytics** ist ein modernes, SQLite-basiertes Web-Analytics-System, das **kein MySQL** benötigt. Perfekt für Shared Hosting, läuft auf jedem Server mit **PHP 8.1 oder höher**. Einfach hochladen, Setup durchlaufen – fertig.

### Warum Countr Analytics?

| Problem | Lösung |
|---------|--------|
| 😫 MySQL nicht verfügbar | ✅ SQLite Single-File Datenbank |
| 😫 Cookie-Banner nötig | ✅ Cookie-freies Tracking |
| 😫 DSGVO-Probleme | ✅ IP-Anonymisierung integriert |
| 😫 Komplexe Installation | ✅ One-Click Setup-Assistent |
| 😫 Langsame Performance | ✅ 10x schneller als JSON-Dateien |
| 😫 Teure Analytics-Tools | ✅ Kostenlos & Open Source |


## ✨ Features

### 🎯 Kernfunktionen
- ✅ **SQLite-Datenbank** – Single-File, kein MySQL nötig
- ✅ **Keine Installation** – Einfach hochladen und loslegen
- ✅ **Echtzeit-Statistiken** – Live-Besucherzählung
- ✅ **Responsive Design** – Mobile & Desktop optimiert
- ✅ **GDPR-konform** – IP-Anonymisierung standardmäßig
- ✅ **Bot-Erkennung** – 30+ Bot-Patterns
- ✅ **Modernes Dashboard** – 5 Chart.js-Diagramme
- ✅ **Dark/Light Mode** – Automatische Erkennung

### 📈 Tracking-Features
- 👥 Besucherzählung (Unique Visitors)
- 👁 Pageviews pro Seite
- 🌐 Browser-, OS- und Geräteerkennung
- 🔗 Referrer-Tracking
- ⏰ Stündliche und tägliche Verteilung
- 🟢 Live-Online-Zähler

### ⚙️ Admin-Funktionen
- 📊 5 interaktive Chart.js-Diagramme
- 📁 CSV-/JSON-/Excel-Export
- 🔒 Passwortgeschützter Admin-Bereich (bcrypt)
- ⚡ Rate Limiting und Missbrauchsschutz
- 🗑 Automatische Datenbereinigung
- 🔌 REST API für Entwickler

### 🛡 Sicherheit
- IP-Anonymisierung (DSGVO)
- Bcrypt-Passwort-Hashing
- Rekursive Verzeichnisrechte (755/644)
- Verzeichnisschutz via .htaccess
- Setup-Selbstdeaktivierung nach Installation
- Keine Speicherung von Roh-IP-Adressen

---

## 🚀 Installation in 60 Sekunden

### 1. Hochladen
Kopieren Sie den `countr/`-Ordner auf Ihren Webserver:
```
/var/www/html/countr/
```

### 2. Aufrufen
Öffnen Sie `https://ihreseite.de/countr/` im Browser.  
Das Setup erkennt automatisch, dass die Konfiguration fehlt, und leitet Sie zum Setup-Assistenten weiter.

### 3. Setup
- **Schritt 1:** System-Prüfung (PHP-Version, SQLite, Schreibrechte)
- **Schritt 2:** Konfiguration (Seitendaten, Admin-Passwort, Zeitzone, Optionen)
- **Schritt 3:** Abschluss – Tracking-Code kopieren

Nach erfolgreichem Setup deaktiviert sich `setup.php` automatisch und alle Rechte werden rekursiv korrigiert.

### 4. Einbinden
Fügen Sie den Tracking-Code in Ihre Webseite ein. Der `track.php`-Endpunkt wird von Ihrer Countr-Installation bereitgestellt.

```html
<!-- Einfache Variante (empfohlen) -->
<script async src="/countr/track.php?js=1"></script>

<!-- Oder mit voller URL, wenn Countr auf einer anderen (Sub-)Domain läuft: -->
<script async src="https://ihre-domain.de/countr/track.php?js=1"></script>

<!-- Alternative: Image Pixel -->
<img src="/countr/track.php" width="1" height="1" style="display:none" alt="">

<!-- SPA/React/Vue -->
<script>
fetch('/countr/track.php?page=' + encodeURIComponent(location.pathname));
</script>
```

### 5. Fertig
Statistiken unter `https://ihreseite.de/countr/` ansehen!

> 📦 **Produktiv-Deployment:** Siehe [DEPLOYMENT.md](../DEPLOYMENT.md) für eine detaillierte Deployment-Checkliste mit Berechtigungen, Sicherheitshärtung, Backups und Migration.

---

## 🔧 Technische Details

| Komponente | Anforderung |
|------------|-------------|
| **PHP** | 8.1 oder höher |
| **Datenbank** | SQLite 3 (Single-File: `statix.db`) |
| **Extensions** | PDO, SQLite, JSON (optional: gd für Charts) |
| **Speicher** | ~50 MB pro 100.000 Besucher |
| **Performance** | Tracking < 10ms, Dashboard < 500ms |
| **Skalierung** | Bis 100.000 Besucher/Tag |
| **Sicherheit** | Bcrypt Hashing, Rate Limiting, .htaccess Schutz |

### Dateistruktur
```
countr/
├── index.php          # Auto-Setup + Öffentliches Dashboard
├── admin.php          # Passwortgeschütztes Admin-Panel (7 Tabs)
├── api.php            # REST API
├── config.php         # Configuration Manager
├── track.php          # Tracking-Endpunkt
├── setup.php          # Installations-Assistent (automatisch deaktiviert)
├── upgrade.php        # Upgrade von älteren Versionen
├── statix.db          # SQLite-Datenbank
├── .htaccess          # Apache-Konfiguration
├── inc/               # Modulares Backend
│   ├── autoload.php   # Autoloader
│   ├── Core/          # Core-Klassen (Auth, Config, Database, Cache)
│   ├── Tracking/      # Tracking-Logik (Visitor, Session, Bot-Detection)
│   ├── Analytics/     # Statistiken & Reports
│   ├── Interfaces/    # Interface-Definitionen
│   └── Utils/         # Utilities (Logger, Security, Validator, Http)
├── assets/            # Frontend-Ressourcen
│   ├── css/           # Stylesheets (Dashboard, Admin, Main)
│   └── js/            # JavaScript (Charts, Dashboard, Admin)
├── data/              # Wird vom Setup automatisch erstellt
│   ├── visitors/      # Tägliche Besucherdaten
│   ├── sessions/      # Session-Verwaltung
│   ├── logs/          # Fehler- und Access-Logs
│   ├── backups/       # Automatische Backups
│   └── exports/       # CSV/JSON/Excel Exports
├── cache/             # Performance-Cache
└── tests/             # Test-Suite
```

---

## 📊 Dashboard

Das öffentliche Dashboard zeigt auf einen Blick:

- **Live-Online-Zähler** – aktualisiert alle 10 Sekunden
- **Tagesstatistik** – Besucher, Pageviews, Gesamt
- **Letzte 7 Tage** – Balkendiagramm
- **Letzte 30 Tage** – Liniendiagramm mit Trend
- **Heutige Verteilung** – Flächendiagramm nach Stunden
- **Browser-Verteilung** – Doughnut-Diagramm
- **Top-Seiten** – Ranking der meistbesuchten URLs

Das Admin-Dashboard bietet zusätzlich:
- 7 Tabs (Übersicht, Besucher, Seiten, Statistiken, Export, Einstellungen, Logs)
- Detailansicht aller Besucher mit Filter & Suche
- Export-Funktionen (CSV, JSON, Excel)
- Einstellungen für Tracking, Sicherheit und Datenschutz

---

## 🔒 Sicherheit & Datenschutz

### DSGVO-Konformität
- **IP-Adressen** werden anonymisiert (letztes Oktett entfernt)
- **Cookie-freies Tracking** – keine Einwilligung nötig
- **Keine personenbezogenen Daten** gespeichert
- **Datenaufbewahrung** konfigurierbar (Standard: 90 Tage)

### Technische Sicherheit
- **Admin-Bereich** passwortgeschützt mit bcrypt
- **Rate Limiting** verhindert Missbrauch
- **.htaccess** schützt alle Datenverzeichnisse
- **Rekursive Rechte** (Verzeichnisse 755, Dateien 644)
- **setup.php** deaktiviert sich nach Installation selbst
- **Keine sensiblen Daten** im öffentlichen Dashboard

---

## 🔌 API

Wenn bei der Installation ein API-Schlüssel generiert wurde, können externe Systeme auf die Statistiken zugreifen:

```http
GET <IHRE-DOMAIN>/countr/api.php?api_key=wc_XXXX&format=json
```

Antwort (JSON):
```json
{
  "today": { "visitors": 145, "pageviews": 312 },
  "online": 12,
  "overall": { "visitors": 12345, "pageviews": 45678 },
  "hourly": { "0": 3, "1": 1, ... "23": 8 },
  "browsers": { "Chrome": 45, "Firefox": 23, ... },
  "last_7_days": { "2026-01-09": 120, ... }
}
```

---

## ❓ FAQ

<details>
<summary><strong>Funktioniert das auf Shared Hosting?</strong></summary>
Ja, Countr Analytics benötigt nur PHP 8.1+ mit SQLite3. Keine MySQL-Datenbank nötig.
</details>

<details>
<summary><strong>Wie viele Besucher kann es verarbeiten?</strong></summary>
Bis zu 100.000 Besucher pro Tag. Dashboard-Ladezeit unter 500ms.
</details>

<details>
<summary><strong>Werden Cookies verwendet?</strong></summary>
Nein. Session-Erkennung erfolgt über IP+UserAgent-Hash, ohne Cookies oder Local Storage.
</details>

<details>
<summary><strong>Ist das DSGVO-konform?</strong></summary>
Ja. Standardmäßig werden IP-Adressen anonymisiert, es werden keine Cookies gesetzt, und keine personenbezogenen Daten gespeichert.
</details>

<details>
<summary><strong>Ist Countr Analytics werbefrei?</strong></summary>
Ja. 100% werbefrei und Open Source. Keine externen Aufrufe, keine Tracking-Backdoors.
</details>

---

## 🤝 Unterstützung

Bei Fragen, Problemen oder Feature-Wünschen:

- 📧 **Email:** support@countr.online
- 🌐 **Website:** [countr.online](https://countr.online)
- 🐛 **Bug-Report:** [GitHub Issues](#)
- 💡 **Roadmap:** [GitHub Projects](#)

---

## 📄 Lizenz

Countr Analytics ist freie Software: Sie können sie unter den Bedingungen
der GNU General Public License, wie von der Free Software Foundation
veröffentlicht, weitergeben und/oder modifizieren, entweder gemäß
Version 3 der Lizenz oder (nach Ihrer Option) jeder späteren Version.

Der vollständige Lizenztext ist in der Datei [LICENSE](LICENSE) zu finden.

## Änderungen
Wenn Sie Countr Analytics modifizieren und weiterverbreiten, müssen Sie:
- Die GPLv3-Lizenz beibehalten
- Die vorgenommenen Änderungen angeben
- Den ursprünglichen Urheberrechtsvermerk einschließen

---

<div align="center">

**Entwickelt mit ❤️ für die Open-Source-Community**

[⬆ Nach oben](#countr-analytics--moderne-sqlite-web-analytics)

</div>