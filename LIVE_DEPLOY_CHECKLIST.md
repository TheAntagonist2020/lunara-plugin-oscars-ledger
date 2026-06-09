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

## 4) Configure API keys

If the release uses dynamic metadata or poster sync:

- Open `wp-admin`
- Go to `Academy Awards -> Poster Library`
- Enter and save your `OMDb API Key`
- Enter and save your `TMDB API Key`

## 5) Run repair in WordPress Admin

- Open `wp-admin`
- Go to `Academy Awards`
- Click `Repair Tables / Rewrite Rules`
- Wait for success message

## 6) Quick data health checks

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

## 7) Poster sync checks

If the release changes poster handling:

- Open `Academy Awards -> Poster Library`
- Run `Sync posters from published reviews`
- Run `Import missing posters from APIs`
- Confirm the page shows `OMDb Status: Configured` and `TMDB Status: Configured`

## 8) UI smoke tests

- Main Oscars table page loads rows
- One ceremony page loads
- One category page loads
- One title/person entity page loads
- One title page shows expected poster/backdrop coverage
- Admin Academy Awards page stats are non-zero

## 9) Cache refresh (if needed)

If any page still shows stale/empty output after repair:

- clear page cache / CDN cache
- hard-refresh browser

## 10) Rollback prep

Before deploying, note the current live commit or plugin snapshot so rollback is immediate if needed.

