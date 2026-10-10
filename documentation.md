# 🎯 Scholarship Tracker Pro · Technical Documentation

Welcome to **Scholarship Tracker Pro**, a modern, minimal, collaborative portal designed for **Student & Counsellor** workflows.

---

## ⚡ Feature Enhancements & Optimizations

### 1. Direct Document Link Badges
- **1-Click Open**: Checklist items display clickable `🔗 Open` badges when a Google Drive / Dropbox URL is attached.
- **Inline Link Editor**: Easily update or attach Google Drive URLs per document item.

### 2. Discussion Feed & Keyboard Shortcuts
- **Enter-to-Post**: Press `Enter` in any discussion input box to send comments immediately.
- **Auto-Scroll**: Auto-scrolls discussion thread to the latest message.

### 3. Deep Search & Instant Clear
- **Multi-Field Search**: Scans across Scholarship Name, University, Country, Tags (e.g. `CS/AI/DS`), TOEFL requirements (`MOI`), Stipends, and Notes text.
- **Clear Button**: Instant clear (`×`) button inside the search input box.

### 4. Expandable Data Table View
- **Inline Table Row Expansion**: Clicking a row in the Data Table View expands an inline drawer displaying Student Notes, Counsellor Feedback, and Document Checklist readiness.

### 5. Smart Deadline & Urgency Sorting
- **Intelligent Priority Ordering**: Groups upcoming active deadlines first (closest deadline to today), followed by rolling/varies deadlines.

### 6. Offline Cache & Auto-Retry Sync
- **Resilient Fallback**: Saves edits to `localStorage` cache if network connection drops, and auto-syncs to MySQL when reconnected.

---

## 📁 File Structure

| File Name | Description |
| :--- | :--- |
| [`tool.html`](file:///c:/Users/Admin/Desktop/scholarship-tool/tool.html) | Optimized HTML5 dashboard UI (Plus Jakarta Sans, Vanilla JS, Font Awesome 6). |
| [`api.php`](file:///c:/Users/Admin/Desktop/scholarship-tool/api.php) | PHP REST API handling CRUD, auto-table migration, and 82 default scholarship seeds. |
| [`config.php`](file:///c:/Users/Admin/Desktop/scholarship-tool/config.php) | Database credentials configuration file. |
| [`schema.sql`](file:///c:/Users/Admin/Desktop/scholarship-tool/schema.sql) | MySQL DDL script with default scholarship seeding SQL. |
| [`documentation.md`](file:///c:/Users/Admin/Desktop/scholarship-tool/documentation.md) | Technical documentation. |

---

## 🌐 cPanel Hosting Setup

1. Upload `tool.html`, `api.php`, `config.php`, `schema.sql` to your cPanel `public_html`.
2. Create a MySQL database and user in cPanel MySQL Wizard.
3. Update `config.php` with your database credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
4. Access `https://yourdomain.com/tool.html`. Tables and 82 default scholarships will be auto-created on first access!