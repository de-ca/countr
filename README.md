Read this in German: [Lies das auf Deutsch](README-de.md)

![](github-banner.svg)

---

# Countr Analytics – Modern SQLite Web Analytics

<div align="center">

📊 **Single-File SQLite. No Cookies. No Problems.**

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.0.0-orange)](#)

</div>

---

## 📊 Overview

**Countr Analytics** is a modern, SQLite-based web analytics system that requires **no MySQL**. Perfect for shared hosting, runs on any server with **PHP 8.1 or higher**. Just upload, run the setup, and you're done.

### Why Countr Analytics?

| Problem | Solution |
|---------|----------|
| 😫 No MySQL available | ✅ SQLite single-file database |
| 😫 Cookie banners required | ✅ Cookie-free tracking |
| 😫 GDPR issues | ✅ Integrated IP anonymization |
| 😫 Complex installation | ✅ One-click setup wizard |
| 😫 Slow performance | ✅ 10x faster than JSON files |
| 😫 Expensive analytics tools | ✅ Free & Open Source |


## ✨ Features

### 🎯 Core Features
- ✅ **SQLite Database** – Single file, no MySQL needed
- ✅ **No installation** – Just upload and go
- ✅ **Real-time statistics** – Live visitor counting
- ✅ **Responsive design** – Mobile & desktop optimized
- ✅ **GDPR compliant** – IP anonymization by default
- ✅ **Bot detection** – 30+ bot patterns
- ✅ **Modern dashboard** – 5 Chart.js charts
- ✅ **Dark/Light mode** – Automatic detection

### 📈 Tracking Features
- 👥 Visitor counting (unique visitors)
- 👁 Pageviews per page
- 🌐 Browser, OS, and device detection
- 🔗 Referrer tracking
- ⏰ Hourly and daily distribution
- 🟢 Live online counter

### ⚙️ Admin Features
- 📊 5 interactive Chart.js charts
- 📁 CSV / JSON / Excel export
- 🔒 Password-protected admin area (bcrypt)
- ⚡ Rate limiting and abuse protection
- 🗑 Automatic data cleanup
- 🔌 REST API for developers

### 🛡 Security
- IP anonymization (GDPR)
- Bcrypt password hashing
- Recursive directory permissions (755/644)
- Directory protection via .htaccess
- Setup self-deactivation after installation
- No storage of raw IP addresses

---

## 🚀 Installation in 60 Seconds

### 1. Upload
Copy the `countr/` folder to your web server:
```
/var/www/html/countr/
```

### 2. Open
Navigate to `https://yoursite.com/countr/` in your browser.  
The system auto-detects that configuration is missing and redirects you to the setup wizard.

### 3. Setup
- **Step 1:** System check (PHP version, SQLite, write permissions)
- **Step 2:** Configuration (site name, admin password, timezone, options)
- **Step 3:** Completion – copy tracking code

After successful setup, `setup.php` automatically deactivates itself and all permissions are recursively corrected.

### 4. Integration
Add the tracking code to your website. The `track.php` endpoint is served from your Countr installation.

```html
<!-- Simple variant (recommended) -->
<script async src="/countr/track.php?js=1"></script>

<!-- Alternatively, use a full URL if Countr runs on a different (sub)domain: -->
<script async src="https://your-domain.com/countr/track.php?js=1"></script>

<!-- Alternative: Image Pixel -->
<img src="/countr/track.php" width="1" height="1" style="display:none" alt="">

<!-- SPA/React/Vue -->
<script>
fetch('/countr/track.php?page=' + encodeURIComponent(location.pathname));
</script>
```

### 5. Done
View statistics at `https://yoursite.com/countr/`!

> 📦 **Production deployment:** See [DEPLOYMENT.md](../DEPLOYMENT.md) for a detailed deployment checklist including permissions, security hardening, backups, and migration.

---

## 🔧 Technical Details

| Component | Requirement |
|-----------|-------------|
| **PHP** | 8.1 or higher |
| **Database** | SQLite 3 (Single-File: `statix.db`) |
| **Extensions** | PDO, SQLite, JSON (optional: gd for charts) |
| **Storage** | ~50 MB per 100,000 visitors |
| **Performance** | Tracking < 10ms, Dashboard < 500ms |
| **Scalability** | Up to 100,000 visitors/day |
| **Security** | Bcrypt hashing, rate limiting, .htaccess protection |

### File Structure
```
countr/
├── index.php          # Auto-setup + Public Dashboard
├── admin.php          # Password-protected Admin Panel (7 tabs)
├── api.php            # REST API
├── config.php         # Configuration Manager
├── track.php          # Tracking Endpoint
├── setup.php          # Setup Wizard (auto-deactivates)
├── upgrade.php        # Upgrade from older versions
├── statix.db          # SQLite Database
├── .htaccess          # Apache Configuration
├── inc/               # Modular Backend
│   ├── autoload.php   # Autoloader
│   ├── Core/          # Core Classes (Auth, Config, Database, Cache)
│   ├── Tracking/      # Tracking Logic (Visitor, Session, Bot-Detection)
│   ├── Analytics/     # Statistics & Reports
│   ├── Interfaces/    # Interface Definitions
│   └── Utils/         # Utilities (Logger, Security, Validator, Http)
├── assets/            # Frontend Resources
│   ├── css/           # Stylesheets (Dashboard, Admin, Main)
│   └── js/            # JavaScript (Charts, Dashboard, Admin)
├── data/              # Auto-created by Setup
│   ├── visitors/      # Daily Visitor Data
│   ├── sessions/      # Session Management
│   ├── logs/          # Error and Access Logs
│   ├── backups/       # Automatic Backups
│   └── exports/       # CSV/JSON/Excel Exports
├── cache/             # Performance Cache
└── tests/             # Test Suite
```

---

## 📊 Dashboard

The public dashboard shows at a glance:

- **Live Online Counter** – updates every 10 seconds
- **Daily Statistics** – Visitors, Pageviews, Total
- **Last 7 Days** – Bar chart
- **Last 30 Days** – Line chart with trend
- **Today's Distribution** – Area chart by hour
- **Browser Distribution** – Doughnut chart
- **Top Pages** – Ranking of most visited URLs

The admin dashboard additionally offers:
- 7 Tabs (Overview, Visitors, Pages, Statistics, Export, Settings, Logs)
- Detailed view of all visitors with filter & search
- Export functions (CSV, JSON, Excel)
- Settings for tracking, security, and privacy

---

## 🔒 Security & Privacy

### GDPR Compliance
- **IP addresses** are anonymized (last octet removed)
- **Cookie-free tracking** – no consent required
- **No personal data** stored
- **Data retention** configurable (default: 90 days)

### Technical Security
- **Admin area** password-protected with bcrypt
- **Rate limiting** prevents abuse
- **.htaccess** protects all data directories
- **Recursive permissions** (directories 755, files 644)
- **setup.php** self-deactivates after installation
- **No sensitive data** in public dashboard

---

## 🔌 API

If an API key was generated during installation, external systems can access statistics:

```http
GET <YOUR-DOMAIN>/countr/api.php?api_key=wc_XXXX&format=json
```

Response (JSON):
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
<summary><strong>Does it work on shared hosting?</strong></summary>
Yes, Countr Analytics only needs PHP 8.1+ with SQLite3. No MySQL database required.
</details>

<details>
<summary><strong>How many visitors can it handle?</strong></summary>
Up to 100,000 visitors per day. Dashboard load time under 500ms.
</details>

<details>
<summary><strong>Are cookies used?</strong></summary>
No. Session detection uses IP+UserAgent hash, without cookies or Local Storage.
</details>

<details>
<summary><strong>Is it GDPR compliant?</strong></summary>
Yes. By default, IP addresses are anonymized, no cookies are set, and no personal data is stored.
</details>

<details>
<summary><strong>Is Countr Analytics ad-free?</strong></summary>
Yes. 100% ad-free and Open Source. No external calls, no tracking backdoors.
</details>

---

## 🤝 Support

For questions, issues, or feature requests:

- 📧 **Email:** support@countr.online
- 🌐 **Website:** [countr.online](https://countr.online)
- 🐛 **Bug Report:** [GitHub Issues](#)
- 💡 **Roadmap:** [GitHub Projects](#)

---

## 📄 License

Countr Analytics is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

See [LICENSE](LICENSE) for the full license text.

## Modifications
If you modify and redistribute Countr Analytics, you must:
- Keep the GPLv3 license
- State changes made
- Include original copyright notice

---

<div align="center">

**Developed with ❤️ for the Open Source Community**

[⬆ Back to top](#countr-analytics--modern-sqlite-web-analytics)

</div>