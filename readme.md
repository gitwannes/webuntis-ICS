# WebUntis ICS Sync Engine

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1+-777BB4.svg)](https://www.php.net/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3.x-38B2AC.svg)](https://tailwindcss.com)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

**A Lightweight, High-Performance WebUntis Timetable Proxy & iCalendar Sync Engine**

WebUntis ICS Sync Engine is an offline-first PHP backend and web management interface designed to convert public WebUntis timetables into cleanly formatted, deduplicated, and enriched iCalendar (`.ics`) feeds.

By bridging raw WebUntis API JSON payloads with a local mapping dictionary (`webuntisdata.json`), the engine automatically resolves cryptic shortcodes (such as teacher initials or room codes) into full display names, merges multi-period lectures into single calendar blocks, and serves a live proxy feed for external calendar apps.

---

## Key Features

### Offline Dictionary & Shortcode Enrichment
* **Full Name Resolution:** Cross-references raw API shortcodes (e.g., `HOAN`) against `webuntisdata.json` to inject full display names (`Hofmans Anne`) for teachers, rooms, subjects, and departments.
* **Regex Room Scrubbing:** Cleans room telemetry on the fly by stripping seating capacity metadata (e.g., transforming `B.102 (180)` to `B.102`).
* **Substitution & Cancellation Tracking:** Filters out `CANCEL` periods while flagging active substitutions, room swaps, and exam states directly in the calendar description.

### Smart Event Consolidation
* **Parallel & Consecutive Merging:** Eliminates grid clutter by mathematically detecting consecutive identical lessons and merging them into continuous time blocks.
* **Deduplication:** Groups multi-teacher or split-group sessions into unified event payloads without duplicating time slots.

### Transparent Feed Proxy & Telemetry
* **Apache Rewrite Integration:** Uses `.htaccess` rewrite rules to seamlessly serve `driesap.php?feed=1` whenever an external client requests `driesap.ics`.
* **Thread-Safe Audit Logging:** Logs every manual generator trigger, settings change, and remote calendar bot fetch (including IP address and User-Agent) using `LOCK_EX` atomic file writes to `driesap.log`.

### Dual Execution Pipeline
* **Modern Web Dashboard:** Powered by Tailwind CSS and Lucide Icons, providing class discovery, date window controls, instant downloads, and live sync logs.
* **Headless CLI / Cron:** Native Command Line Interface support for automated headless cron updates.

---

## Supported Workflows

* **Remote Calendar Subscription:** Point Google Calendar, Apple Calendar, or Outlook to your proxy feed URL for automatic background syncs.
* **Web Untis API Polling:** Dynamic rolling window fetching (e.g., -1 month to +3 months relative to current date).
* **Manual Web Generation & Download:** On-demand `.ics` generation directly from the web dashboard.
* **Cron Automated Refresh:** Headless backend generation storing output atomically via temporary `.tmp` swaps to prevent web read locks.

---

## Technology Stack

**Backend Engine:**
* PHP 8.1+ (Strict types, cURL, `DateTimeImmutable`, Native iCal RFC 5545 builder)
* Apache HTTP Server (`mod_rewrite` enabled)
* JSON-based Non-Volatile Memory (Config & Class State Caching)

**Frontend Dashboard:**
* HTML5 / Vanilla JS
* Tailwind CSS (CDN)
* Lucide Icons (UI Telemetry)

---

## Architecture Overview

```text
├── .htaccess            # Apache rewrite rules (redirects .ics to PHP proxy)
├── driesap.php          # Core Sync Engine, Web UI, CLI & Feed Proxy Handler
├── driesap_config.json  # Server URL, Class ID, timezone & date window settings
├── driesap_state.json   # Cached class lookup list and last-generated timestamp
├── webuntisdata.json    # Offline dictionary mapping shortcodes to full display names
├── driesap.log          # Thread-safe application and remote sync log
└── driesap.ics          # Output RFC 5545 iCalendar payload
```

## 🔒 Privacy & GDPR Compliance

This tool is engineered for private, personal timetable synchronization. Under EU GDPR regulations and Belgian Data Protection Authority (GBA/APD) guidelines:

* **Household Exemption (Art. 2(2)(c) GDPR):** Processing educational schedule data (including teacher names, room locations, and timetable events) using this engine for personal or family organisation falls squarely under the EU **Household Exemption**.
* **Local Data Sovereignty:** 
  * No schedule telemetry, credentials, or personal data are ever transmitted to third-party analytics or external servers.
  * All raw API responses (`webuntisdata.json`), generated calendars (`driesap.ics`), state caches (`driesap_state.json`), and system logs (`driesap.log`) are stored locally on your own web server.
* **Public Repository & Self-Hosting Guidelines:**
  * **Do Not Commit Personal Data:** Ensure raw `.json` data dumps, cached states, and `.ics` files containing real teacher or student names are kept inside `.gitignore` and never committed to public version control.
  * **Obscure Endpoint Access:** If hosting this proxy on a publicly accessible web server, ensure output `.ics` links are unindexed by search engines to prevent automated harvesting of educational staff data.

## ⚠️ Terms of Use & License

This project is intended strictly for **personal, non-commercial use only**.

* **Non-Commercial:** You may not use, redistribute, or modify this software or any data extracted by it for commercial purposes or financial gain.
* **Data Usage:** This tool is designed to process personal schedule data via private credentials. You are responsible for handling your credentials and any downloaded data in compliance with local privacy laws.
* **No Warranty:** Provided "as-is" without any warranty. Use at your own risk.

*Copyright (c) 2026 https://github.com/gitwannes. All Rights Reserved.*