# Lunara Oscar Ledger Plugin

Private WordPress plugin source for the Lunara Film Academy Awards Database / Oscar Ledger.

## Role

This plugin owns the server-side Oscars database, public Oscars routes, title/person/category/ceremony views, ballot/tracker utilities, and related data assets used by the Lunara site.

## Source Locations

- Local source: `G:\lunara-backups\work\academy-awards-table-optimized`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/academy-awards-table-optimized`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `2.7.9`.

## Verification

- Run PHP lint on `academy-awards-table.php` and template/include PHP files after edits.
- Confirm representative Oscars routes return `200`.
- Confirm `/oscars/category/best-picture/` and `?history=full` preserve expected Ledger data and links.
- Flush WordPress cache after deploys and capture visual evidence only under the Desktop `10_VISUAL_EVIDENCE` folder.
