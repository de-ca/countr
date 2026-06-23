# Changelog – Countr Analytics

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),  
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] – 2026-01-15

### Added

- **Initial release** of the file-based web analytics system – no database required
- **Pure JSON file storage** for maximum portability and simple shared hosting
- **Atomic file operations** with `flock()` for race condition protection under concurrent access
- **Real-time tracking** with write buffering (10 hits per batch) to minimize file I/O
- **Full admin dashboard** with 5 tabs:
  - Overview (live data, KPI cards)
  - Visitors (detailed listing, search, filters)
  - Pages (most visited URLs with sorting)
  - Statistics (daily, weekly, monthly analysis)
  - Settings (all tracking and security options)
- **Responsive design** – optimized for mobile, tablet, and desktop
- **Dark mode support** – automatic detection of `prefers-color-scheme: dark`
- **4 interactive Chart.js charts**:
  - Visitors last 7 days (bar chart)
  - Visitors last 30 days (line chart)
  - Today's hourly distribution (area chart)
  - Browser distribution (doughnut chart)
- **Automatic setup system** with 3-step wizard:
  - System check (PHP version, extensions, write permissions)
  - Configuration (site data, admin password, timezone)
  - Completion with tracking code output and setup self-deactivation
- **GDPR-compliant IP anonymization** – last octet removed
- **Cookie-free tracking** – no consent required
- **30+ bot detection patterns** – crawlers, spiders, bots automatically filtered
- **Browser / OS / Device detection** from user-agent string
- **Rate limiting** for abuse protection
- **CSV / JSON / Excel export** of all statistics from the admin area
- **API key generation** for external integrations
- **Demo data generation** (30 days of test data for immediate evaluation)
- **Multi-user support** (prepared, configurable)
- **Automatic backup** of initial state after setup
- **Directory structure with .htaccess protection**:
  - `data/visitors/` – Daily visitor data
  - `data/sessions/` – Session files
  - `data/logs/` – Error and access logs
  - `data/backups/` – Automatic backups
  - `data/exports/` – CSV/JSON/Excel exports
  - `cache/` – Performance cache
- **Apache .htaccess** with rewrite rules, security headers, and GZIP compression
- **Tracking integration** via script tag, image pixel, or JavaScript fetch API

### Security

- **Bcrypt password hashing** for admin access
- **All sensitive directories** protected with `.htaccess` and `index.html`
- **setup.php** self-deactivates after successful installation
- Rate limiting for `track.php` and `admin.php`
- No storage of raw IP addresses (only anonymized hashes)

---

## Legend

| Symbol | Meaning |
|--------|---------|
| 🚀     | New feature |
| 🔒     | Security-related |
| 🐛     | Bug fix |
| ⚡     | Performance improvement |
| 📝     | Documentation |
| 🔧     | Configuration change |
| 🗑️     | Removed |

---

*Countr Analytics is a product developed with ❤️ for the open-source community.*