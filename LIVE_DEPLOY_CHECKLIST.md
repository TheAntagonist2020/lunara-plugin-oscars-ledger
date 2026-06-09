# Live Deploy Checklist (Academy Awards Plugin)

Use this every time you push updates to production.

## 1) Confirm you are editing the correct plugin file

- Main plugin file: `academy-awards-table.php`
- Plugin folder root should contain that file and `assets/`, `templates/`, `includes/`, `data/`
- Do not deploy from a nested duplicate folder by mistake.

## 2) Pre-deploy local checks

Run from plugin root:

```powershell
php -l .\academy-awards-table.php
```

Expected: `No syntax errors detected`

## 3) Deploy

Upload the updated plugin folder/files to the live plugin path.

Recommended minimum files when this project changes:

- `academy-awards-table.php`
- `assets/js/admin.js`
- `templates/admin-page.php`
- Any modified files under `assets/`, `templates/`, `includes/`

## 4) Run repair in WordPress Admin

- Open `wp-admin`
- Go to `Academy Awards`
- Click `Repair Tables / Rewrite Rules`
- Wait for success message

## 5) Quick data health checks

If data looks empty, check:

```sql
SELECT COUNT(*) AS source_rows FROM wp_academy_awards;
SELECT COUNT(*) AS fact_rows FROM wp_aat_award_facts;
SELECT COUNT(*) AS ceremony_rows FROM wp_aat_ceremonies;
SELECT COUNT(*) AS category_rows FROM wp_aat_categories;
```

Expected:

- `source_rows > 0`
- projection rows (`fact_rows`, `ceremony_rows`, `category_rows`) should also be > 0

## 6) UI smoke tests

- Main Oscars table page loads rows
- One ceremony page loads
- One category page loads
- One title/person entity page loads
- Admin Academy Awards page stats are non-zero

## 7) Cache refresh (if needed)

If any page still shows stale/empty output after repair:

- clear page cache / CDN cache
- hard-refresh browser

