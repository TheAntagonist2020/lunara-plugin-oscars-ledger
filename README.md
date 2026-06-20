# Lunara Oscar Ledger Plugin

Private WordPress plugin source for the Lunara Film Academy Awards Database / Oscar Ledger.

## Role

This plugin owns the server-side Oscars database, public Oscars routes, title/person/category/ceremony views, ballot/tracker utilities, and related data assets used by the Lunara site.

## Source Locations

- Local source: `G:\lunara-backups\work\academy-awards-table-optimized`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/academy-awards-table-optimized`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `2.7.25`.

## Current Public Surface

- Ceremony pages include a data-derived Ceremony Thesis layer with critical-path navigation and compact major-race briefing cards.
- Ceremony pages include a Major Races proof module for Best Picture, Directing, Actor, and Actress before the complete ceremony ledger.

## Verification

- Run PHP lint on `academy-awards-table.php` and template/include PHP files after edits.
- Confirm representative Oscars routes return `200`.
- Confirm `/oscars/category/best-picture/` and `?history=full` preserve expected Ledger data and links.
- Confirm `/oscars/ceremony/{N}/` and `?ledger=full` preserve the major-races module and full ballot links.
- Flush WordPress cache after deploys and capture visual evidence only under the Desktop `10_VISUAL_EVIDENCE` folder.
