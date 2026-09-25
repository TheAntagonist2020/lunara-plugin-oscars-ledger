# Lunara Oscars Ledger — database

The validated Academy Awards dataset and its relational schema, reconciled row by row
with the Academy Awards Database. The audit behind it, with every correction and its
evidence, is in [`AUDIT-REPORT.md`](AUDIT-REPORT.md). Dataset version `2026.09.25-1`.

| File | What it is |
| --- | --- |
| `schema.sql` | MySQL 8 / MariaDB 10.6+ schema: ceremonies, classes, categories, titles, people and companies (keyed by IMDb ID), nominations, their title slots, credit slots, credit identities, and the corrections ledger, plus three read views |
| `data.sql.gz` | The full load: 12,138 nominations, 3,516 winners, 5,263 titles, 8,468 people and companies, 372 logged corrections and 1 added award |
| `integrity.sql` | Checks to run after loading. Each "should be empty" query must return nothing |
| `oscars-corrected.xlsx` | The corrected dataset in the source workbook's layout (`full_data` plus `Ceremony_1` … `Ceremony_98`), with **Corrections** and **Needs review** sheets. Changed cells are highlighted, and each carries a comment with its old value |
| `oscars-corrected.tsv` | The same data as the tab-separated file the plugin imports (14 columns, same header) |
| `corrections.json` | Every changed cell (372): row, field, before, after, reason, evidence, and how it was confirmed. Corrections to the same cell apply in file order |
| `additions.json` | Awards the source lacks, appended after its last row (1: the 97th-ceremony Academy Award of Merit for captioning), with evidence |
| `needs-review.json` | 1 question no source settled; left unchanged in the data |
| `tools/` | The audit scripts, kept for provenance: the assembler and its verified decisions (`editor_decisions.json`), the build, the Academy-database reader (`ampas.js`) and the row-by-row reconciliation (`reconcile.py`). They were run from a scratch workspace, so their paths are session-specific |

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

## Reference names and privacy

The plugin repository is public, so it keeps no Wikidata identifiers and no personal
dates taken from Wikidata.

- **The committed name source** is `data/ledger/entities.tsv` (IMDb ID, kind, reference
  name) and `data/ledger/titles.tsv` (IMDb ID, reference title). They were extracted
  once from this directory's `data.sql.gz` by `tests/tools/extract-reference-names.php`;
  from then on the ledger's bundle builder maintains them. For people, a reference name
  is the Wikidata label where the audit found one (else a correction's canonical name,
  else the most-credited label). The ledger uses reference names only to break ties,
  to fix all-capitals casing and to widen search, never as a display name while the
  Academy credits the person.
- **Removed:** Wikidata QIDs, birth years, Wikidata release years, Wikidata links,
  Q-numbers and life spans. They are gone from `data.sql.gz` (titles keep only IMDb ID
  and title; people and companies keep only IMDb ID, kind and name), from `schema.sql`
  (the two tables lost those columns; every other line is unchanged), from the evidence
  (`corrections.json`, `tools/adjudications.json`, `tools/editor_decisions.json`,
  `AUDIT-REPORT.md` and the dump's `ledger_corrections` rows) and from the workbook.
  Evidence still names Wikidata as a source that was consulted, but no longer links to
  it: a Wikidata link became the word "Wikidata", a Q-number was dropped, and a span of
  years became `[years omitted]`. Only evidence text was redacted. No dataset value
  changed: before and after values, the added row, labels and every nomination row are
  the Academy's text and ship byte for byte.
- **How:** `tests/tools/strip-reference-columns.php` rewrote the dump, the schema and
  the evidence once, through the one redactor in `tests/tools/lib/redact-evidence.php`,
  and `--verify-redaction` proves that nothing else changed. `tools/build.py` no longer
  takes a Wikidata input and writes no such column; it reads people's reference names
  from `data/ledger/entities.tsv`. `tests/ledger-privacy-contract.php` keeps the
  repository clean in CI.
- **The workbook** was regenerated from the redacted files with
  `python3 docs/database/tools/make_workbook.py docs/database/oscars-corrected.tsv docs/database/corrections.json docs/database/needs-review.json docs/database/oscars-corrected.xlsx docs/database/additions.json`.
  Only its Corrections sheet's evidence column changed.
- **Audit labels:** the audit numbered its review items with the letter Q and three
  digits, which reads like a Wikidata identifier. In the JSON records
  (`tools/adjudications.json` keys and `tools/editor_decisions.json` items) they now
  use the letter R with the same digits. The kept Python scripts still use the old
  prefix; they are provenance only and are not re-run.
- **Until the ledger regenerates them:** `data.sql.gz` and the workbook still reflect
  the audit's release of this dataset (372 corrections, 1 addition), and the dump's
  `ledger_dataset.source_sha256` hashes the audit's intermediate JSON of the source
  rows, not `data/oscars.csv`. Once the ledger's bundle builder regenerates
  `data.sql.gz` from the ledger, the dump holds the ledger's display names and linked
  identities only, and the builder produces it from then on.
- **Git history** still holds the removed data: commit `d7a3bda` (merged by `021db1f`)
  and the commit of pull request #39, `7429a5a` (merged by `826d537`). Removing it from
  history needs a history rewrite and a force-push, which is the repository owner's
  decision.
