# Lunara Oscars Ledger — database

The validated Academy Awards dataset and its relational schema. The audit behind
it, with every correction and its evidence, is in [`AUDIT-REPORT.md`](AUDIT-REPORT.md).

| File | What it is |
| --- | --- |
| `schema.sql` | MySQL 8 / MariaDB 10.6+ schema: ceremonies, classes, categories, titles, people and companies (keyed by IMDb ID), nominations, their title slots, credit slots, credit identities, and the corrections ledger, plus three read views |
| `data.sql.gz` | The full load: 12,137 nominations, 3,515 winners, 5,263 titles, 8,469 people and companies, 157 logged corrections |
| `integrity.sql` | Checks to run after loading. Each "should be empty" query must return nothing |
| `oscars-corrected.xlsx` | The corrected dataset in the source workbook's layout (`full_data` plus `Ceremony_1` … `Ceremony_98`), with **Corrections** and **Needs review** sheets. Changed cells are highlighted, and each carries a comment with its old value |
| `oscars-corrected.tsv` | The same data as the tab-separated file the plugin imports (14 columns, same header) |
| `corrections.json` | Every changed cell: row, field, before, after, reason, evidence, and how it was confirmed |
| `needs-review.json` | 8 questions no source settled; left unchanged |
| `tools/` | The audit scripts, kept for provenance. They were run from a scratch workspace, so their paths are session-specific |

## Load it

```bash
mysql -e "CREATE DATABASE ledger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
mysql ledger < schema.sql
gunzip -c data.sql.gz | mysql ledger
mysql -t ledger < integrity.sql
```

Tested on MariaDB 10.11 in strict mode. The load takes about 7 seconds. Every
integrity check passes. The one expected row is nomination 4507, where MGM British
and MGM share MGM's company ID; the audit report explains why that is accepted.

## Two names, never confused

`ledger_entities.name` and `ledger_titles.title` hold an entity's canonical name. The
`*_as_credited` columns hold the Academy's historical credit. For example, "Roderick
Jaynes" is credited on two Film Editing nominations and resolves to both Joel and
Ethan Coen through `ledger_credit_identities`.

A credit slot keeps its position even when no IMDb ID is known. A name therefore can
never slide onto a neighbour's ID, which is the defect the site's importer has today.
