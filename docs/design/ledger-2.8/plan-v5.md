# Academy Awards database module: implementation plan, revision 5

Oscars Ledger plugin 2.7.92 → 2.8.0 … 2.8.5 (and later patch bumps), theme 3.2.90 → 3.2.91 and 3.2.92.

This revision replaces `plan-v4.md` (revision 4). It is self-contained: a builder implements from this file alone. It merges the data-layer, read-API and explorer designs (`design-0.md`, `design-1.md`, `design-2.md`). It resolves every issue in the first critique (`critique.md`), in the second critique (of revision 2), in the third critique (of revision 3) and in the fourth critique (of revision 4: no blocker, six majors, ten minors), and it applies the fifteen decisions the lead made under Dalton's delegation (§14.1). Those decisions are applied, not re-opened. It also takes in the reconciled dataset of PR #39 (372 cell corrections, 1 appended row, 1 open needs-review item; §1). §17 maps every issue of the fourth critique and every item of that data update to its patch; §16 keeps the mapping of the earlier critiques.

**This file is committed under `docs/design/ledger-2.8/` (U01), and the public-repository privacy contract scans it (§4.14).** It therefore quotes no Wikidata QID, no Wikidata-derived year and no person's life span. Where earlier revisions quoted such values as examples, this revision describes them instead.

Every claim about existing code was checked in this session against:
- `/home/user/lunara-plugin-oscars-ledger` (main at `021db1f`; `d7a3bda` is the audited-dataset commit merged by `021db1f`). The checkout branch `claude/sweet-cannon-ugwj4q` is at `7429a5a` (PR #39, open, docs only): `git diff 021db1f 7429a5a --name-only` lists only `docs/database/` files, so every code citation below holds for both, and every dataset figure below is measured on `7429a5a`;
- `/home/user/lunara-theme-blocks` (3.2.90; main at `4e30f60`, the merge of PR #213, whose last content commit is `be26e77`);
- a WordPress 6.8 core tree in the session scratchpad, read only for core behaviour (`wp-includes/rest-api/class-wp-rest-server.php`, `wp-includes/option.php`);
- `/home/user/lunara-plugin-core` (`ab69773`, read only for field and post-type definitions);
- read-only anonymous GETs of lunarafilm.com on 2026-09-24 (listed in §1).

Citations are `file:line`. `main:` means `academy-awards-table.php`, and `builder:` means `includes/class-aat-entity-graph-builder.php`. Theme files are prefixed "theme".

---

## 0. What this plan decides

1. **One corrected relational store, derived once.**
   - `data/oscars.csv` stays byte-identical.
   - The audited overlay ships in `data/ledger/`. It has two parts: cell corrections (`corrections.json`) and appended rows (`additions.json`). Appended rows continue `source_row` numbering after the last upstream row.
   - One deriver (`AAT_Ledger_Deriver`) builds every table from CSV + overlay + reference files: the normalized `aat_ledger_*` tables, four API read tables, the legacy master and its eight `aat_*` projections.
   - All 25 tables go live together in one `RENAME`, from a lock-guarded, resumable WP-Cron job. The job validates everything before the swap, and every phase (prepare included) derives at most one table per tick, so memory stays bounded. It is queued automatically on a version change. The ledger tables are created by the job's prepare step in cron, never on a public request (§4.3).
   - Every count the system checks comes from the generated manifest or from generated bundle files. No count is hard-coded outside them. The only literals left are the tests that pin the **upstream** file itself (`data/oscars.csv`: 12,137 rows, 3,515 winners; §10), which the overlay never changes.
   - The overlay files are read **exactly as shipped** (§4.1): `corrections.json` is an array of cell corrections applied in file order, and one cell may receive several of them, each with its own before-check; `additions.json` is an array of `{row, reason, evidence, verification}` objects whose `row` carries the 14 source columns as typed JSON values, and whose `source_row` is implied by file position.
2. **Every public credit link is the ledger's slot link** (§5).
   - A row whose names and IDs cannot be paired by position is rendered from the ledger's positional slots when the ledger is live, and as plain text otherwise. That includes the title-page nominee line, which revision 3 missed (§5.3).
   - For every other row, a pre-swap proof (V11) shows that pairing the master's lists by position gives exactly the slot links.
   - No label-based re-routing and no relabelling of a positional ID: `canonicalize_name_entity_id_for_label()` is off while the master is ledger-derived, a single ID relabels a credit only when the credit names one nominee, and public pages never guess a link from a label in any state.
   - Before the swap (and after a rollback to the pre-ledger data), a generated **legacy link guard** renders as plain text every (ID, credited label) pair that the ledger knows is wrong or under review, every first-label-wins (ID, label) pair that is not a credited alias of that ID, and **any** link to an ID the ledger links nowhere. It is applied before any relabel; the never-link rule sits in `build_entity_url_from_id()` itself, through which every entity URL of the plugin is built (templates, cached payloads, search feed, portal), in `get_title_visual_package()` (no poster for a never-link title), and in the DataTables payload (§5.6; fourth critique: poster grids and film cells bypassed the closures).
3. **No Wikidata years, QIDs or birth years anywhere public.** That covers pages, JSON-LD, the API, TMDb/OMDb lookups, the builder, the deployed bundle, **and the public GitHub repository**: `docs/database/data.sql.gz`, `docs/database/schema.sql` and the design drafts are rewritten without those columns; evidence-bearing text is redacted (Wikidata links reduced to the word "Wikidata", Q-numbers and year spans removed) in the repository and the bundle alike, and dataset values (correction before and after values, appended cells) are never touched, because they are the Academy's own text; a CI contract keeps them out (§4.14). Git history still holds commit `d7a3bda`; purging it is Dalton's call (§14.2).
   - A film's year is the Academy's own label for its first ceremony, verbatim (`1932/33` is never flattened to `1932`), on every surface that prints it, including the theme's `/film/` and `/talent/` award history.
   - External lookups receive a 4-digit year only for single-year labels.
4. **IMDb IDs are the relational bridge.**
   - Nominations are keyed by a stable `nomination_id`, which equals the live master id and is preserved through a persistent content-key table. Appended rows get new ids.
   - Credit and title slots are positional.
   - Needs-review identities that are unresolved at release time are **unlinked everywhere, in every state**. They are carried as `review_flag` in the API and schema.
5. **A single dataset stamp versions every plugin cache and the theme's Oscars caches.** The stamp is computed before the swap and written into the stage meta, so it goes live in the same `RENAME` as the data; the API token reads it from the live meta row (§4.7). The token also includes `AAT_VERSION`, so every release rotates edge-cached API responses (§6.5). A contract fails CI when API code or its main-class collaborators change without a version bump.
6. **The kill switch freezes, it does not revert** (§4.6.10). **A rollback pins only the bundle it rolled back**: a fix-forward release with a different bundle imports automatically, with no shell or admin action (§4.6.9).
7. **Public read API `lunara-ledger/v1`.**
   - Anonymous, GET-only and edge-cacheable, with JSON Schema 2020-12 contracts.
   - CORS `*`. Server-side use is recommended in the docs.
   - The rate limiter ships **log-only**, with seven daily buckets of evidence in `/status`.
8. **Explorer at `/oscars/explore/`, named "Oscar Ledger Explorer".** It is server-rendered and progressively enhanced. Pivot links are `rel=nofollow`, and robots.txt disallows multi-ID and paged states. The filter panel is server-rendered open and never changes state after first paint.
9. **Two theme releases.**
   - 3.2.91 ships before the swap. It fixes theme-side positional pairing, unflattened year labels (award history and JSON-LD), and stamps the theme's long-lived Oscars transients and the live-search key.
   - 3.2.92 ships after the explorer. It integrates the explorer.
10. **Merge equals deploy for both repos** (decision 14).
    - Each release is proven by a version probe.
    - If the version is not live within 10 minutes of the merge, the agent reports it to the owner and stops the train.
    - The first swap (R2) proceeds automatically when R1's production dry run matches the local simulation within the manifest's bounds. The match compares only sourced facts; production's media-recovered links are classified and bounded separately (§4.6.6).
    - When the dry run does not match, `/status/report` lists every differing item, and the agent runs a defined remediation loop alone: each production edit becomes an evidenced overlay correction or an evidenced accepted-drift entry, the bundle is regenerated and the dry run repeats (§4.6.6, U29). Nothing in that loop needs the owner.
    - A failed R2 check is classified by data-layer truth first. Only a wrong data layer (`/status` state, counts or the live sentinel checks the server runs against the ledger tables) rolls the dataset back. Page-level failures on a correct data layer are render faults and are fixed forward, as are cache-timing, builder and slug failures (§12 R2).
11. **Known divergences are written down, not hidden** (§14.3). They are Lunara Core's `ledger_entry` REST exposure and its primary-entity-only award links on `/talent/` and `/film/`.

---

## 1. Facts this plan rests on

| Fact | How established |
|---|---|
| RFC parse of `data/oscars.csv` (tab delimiter, enclosure `"`, escape disabled) gives 12,137 upstream rows and 3,515 winners. The cells hold 194 backslash-quote sequences in 84 cells and no other backslashes. These **upstream** figures are the only counts a test may pin as a literal (§10); everything derived from the overlay comes from the manifest | Re-run this session with a Python replica; CSV unchanged since (`sha256` fad75163…4a95, pinned at `tests/reporting-integrity-contract.php:30`) |
| `docs/database/corrections.json` (PR #39) is a JSON **array** of 372 cell corrections with keys `nomination_id` (= 1-based `source_row`), `field`, `before`, `after`, `reason`, `evidence`, `verification`, and `imdb_id` on 278 entries (a string on 253, `null` on 25). Reasons: wrong_id 235, encoding 84, retired_id 38, credit_text 10, missing_id 5. Fields: NomineeIds 274, Citation 41, Note 28, Nominees 16, Name 7, FilmId 4, Film 2. They touch 363 distinct cells: **9 cells receive two corrections** (NomineeIds of source rows 2112, 2228, 2343, 3151, 3393, 4000, 4746, 4860 and 4861), and each second correction's `before` equals the first one's `after`. Applied in file order, each with its before-check, all 372 match and no backslash remains, so the global backslash-quote decode is a no-op. Longest `verification` 137 characters (hence `schema.sql`'s widening to 255), `reason` 11, `field` 10 | Python replica this session over `7429a5a` |
| `docs/database/needs-review.json` (PR #39) is an array of **1** item `{rows, imdb_id, label, question, tried}`: rows [5671], nm0239470, label `RICHARD DUBOIS`, one (source_row, ID) pair. The seven other items of the `021db1f` file were settled by cell corrections, not by `resolution` blocks (e.g. row 1575's co0141760 → co0003606, and row 2112's `co0040435|?` → `?|?` → `?|co0103139`). The open pair must be served unlinked in every state (decision 5). Row 5671's upstream `?|?|nm0230800|nm0239470` is corrected to `?|?|?|nm0239470`, so masking leaves it with no ID at all | Parsed this session |
| The 372-correction, 1-addition overlay serializes (header, upstream rows in `source_row` order, then the addition; Winner `True` or empty; LF endings) to `3ecfa6fe…9d7f`, equal to `sha256(docs/database/oscars-corrected.tsv)` and to `ledger_dataset.corrected_sha256` in `data.sql.gz`. It holds 12,138 rows and 3,516 winners (98 ceremonies, 66 categories, 90 unofficial nominations), which `docs/database/README.md` and `integrity.sql:7-11` report as the Academy's own count. The hash and the counts are a **baseline only**: no test pins them, and every count reaches the code and the tests through the manifest | Python replica this session |
| The category `SCIENTIFIC AND TECHNICAL AWARD (Academy Award of Merit)` exists upstream (32 rows, ceremonies 51–90). **Source row 10475** (86th, "To all those who built and operated film laboratories, for over a century of service to the motion picture industry.") has empty Film, FilmId, Name, Nominees and NomineeIds, Detail `Special Photographic`, and is the **only** upstream row with empty Film, Name and Nominees. PR #39's one addition (implied `source_row` 12138, the 97th-ceremony Academy Award of Merit for captioning) has the same shape with an empty Detail, so the corrected data has **2 citation-only rows** (10475 and 12138). Its nomination key differs from every upstream key, and its Year `2024` equals the label of the 138 upstream ceremony-97 rows | `oscars-corrected.tsv` and replica scan this session |
| **The plugin repository is public.** The GitHub API reports `TheAntagonist2020/lunara-plugin-oscars-ledger` with `visibility: public` | `curl https://api.github.com/repos/TheAntagonist2020/lunara-plugin-oscars-ledger` this session |
| **Tracked files expose Wikidata QIDs and birth years.** `docs/database/data.sql.gz` (rebuilt by PR #39 from the 372-correction overlay) holds 8,468 `INSERT INTO ledger_entities` rows, 7,028 with a birth year and 8,272 with a QID in their 4th and 5th values, and 5,263 `ledger_titles` rows, 5,222 with a Wikidata year and 5,258 with a QID in their 3rd and 4th values. `docs/database/schema.sql:67-68` defines `release_year` and `wikidata_qid` on `ledger_titles`, and `:78-79` `birth_year` and `wikidata_qid` on `ledger_entities`; `:153` widens `verification` to `VARCHAR(255)`. `docs/database/tools/build.py:4`, `:14-18` and `:142-184` take an untracked `wikidata.json` input and emit them. The design drafts that revision 2 would commit carry them too: `ledger-api-design/schemas/entity.schema.json` (`wikidata_qid`, `wikidata_release_year`, `wikidata_birth_year`, 10 hits), `ledger-api-design/reference_serializer.py` (5 hits), `proto/sim.php` (1 hit) | `zcat`/`grep`/Python count this session |
| **Evidence carries QIDs and life spans; so, once, does the Academy's own text.** In `docs/database/corrections.json`, 90 corrections cite Wikidata; their `evidence` holds 21 Wikidata page URLs and 123 further Q-number tokens (26 distinct QIDs). The §4.14 patterns change 214 `evidence` strings, 0 `verification` strings and **0 `before` or `after` values**. `docs/database/tools/adjudications.json` changes in 2 `note` values, and its keys carry the audit's review-item labels (the letter Q and three digits, e.g. the key of the row-8104 decision); `docs/database/tools/editor_decisions.json` (new in PR #39) carries 7 such labels in `items` arrays, and 6 real QIDs and 2 parenthesized year spans in `evidence`; its `slots`, `cells` and `additions` hold dataset values. `needs-review.json` and `additions.json` match nothing today. `AUDIT-REPORT.md` matches on lines 116, 139 and 145. `data.sql.gz` repeats the corrections evidence in 90 `INSERT INTO ledger_corrections` lines that mention Wikidata. `oscars-corrected.xlsx` repeats the evidence on its Corrections sheet. **A dataset value matches as well:** `data/oscars.csv:11337` (source_row 11336, a SciTech Note) contains a company founder's life span in parentheses, the only upstream cell the year-span patterns match; a correction or addition that carries such text is Academy text, not evidence (fourth critique) | Python regex count this session (the §4.14 patterns) |
| **Reference names are Wikidata labels for people.** `docs/database/tools/build.py:177-184` writes a person's name as a correction's `canonical_name` override when one exists, else the Wikidata label when one exists, else the most-credited label; titles and companies use the most-credited label. `entities.tsv` is extracted from that output (U00) | Read this session |
| **Positional hazard.** `normalize_imdb_entity_ids()` (`main:3563-3595`) drops `?` and other placeholders, splits comma-joined slots and de-duplicates. It runs on import and on every read, via `apply_row_hotfixes()` (`main:3465-3476`) from `normalize_awards_row()` (`main:3615`). Measured over the 372-correction corrected TSV with a Python replica: 242 rows with NomineeIds whose `|`-split Nominees count differs from the normalized ID count. 239 have a `?` slot (SciTech 146, Title 61, Production 24, Special 7, Writing 1), 2 have comma-joined slots (`source_row` 8165 and 9615, Roderick Jaynes). 1 title row has a `?` FilmId (`source_row` 543, from a FilmId correction). **0 rows** have equal counts together with a `?` or comma slot | Replica scan this session; the deriver re-measures with the real normalizer (V11) |
| 527 rows have Nominees but no NomineeIds (514 SciTech, 12 Special, 1 Title), unchanged by PR #39 | Same scan |
| **Unguarded positional renderers in the plugin:** `$aat_render_pipe_links` (`templates/hub-page.php:444-485`, `$ids[$index]` with no count check), `$aat_build_person_link_items` (`:351-422`, same), `$aat_resolve_entry_name_link` (`:249-301`, single-ID branches link a name when there is one ID, whatever the label count). Both of the first two fall back to `get_name_entity_link_by_label()` when a value has no ID and then **replace the credited text with the entity's stored label** (`:397-404`, `:468-475`). `$aat_render_pipe_links($value_list, $id_list = '', $class)` receives no row (`:444`); its call sites are the ballot title and credit lines (`:2021`, `:2026`); `$aat_build_person_link_items($entry)` is called by the winner circle (`:2839`) and nominee people (`:2910`); `$aat_resolve_entry_name_link` is called by `$aat_enrich_winner_entry_links` (`:317`), which the latest-winner card uses (`:2365`). The label fallback `get_name_entity_link_by_label()` (`main:7814-7925`) first queries `aat_entities` by `sort_label` (`main:7856-7880`), then scans the master and pairs by index with **no** count check (`main:7897-7913`), then calls `canonicalize_name_entity_id_for_label()` (`main:7916`). Its only public callers are those two hub closures; the others are admin classifiers (`main:10424`, `:10707`) | Read in a prior session and this session |
| **The title-page nominee line bypasses the count guard** (third critique). `$resolve_title_nominee_display` (`templates/entity-page.php:209-260`) computes `$single_nominee_id` whenever the normalized ID list has one entry (`:215`), whatever the number of nominee labels, and then replaces the credited Name with that ID's display name (`:248-253`) before `$render_linked_pipe` (`:75-104`) pairs one label with one ID. Called at `:1108-1110`. Live on 2026-09-24: `/oscars/title/tt0031385/` links "Denham" to `/oscars/name/nm0914249/` (row 938, `Denham|A. W. Watkins` with `?|nm0914249`); `/oscars/title/tt0036868/` links "Samuel Goldwyn - United Artists" to `/oscars/company/co0058013/` (row 2111 credits "Samuel Goldwyn Productions"; co0058013 is the before-ID of its wrong_id correction); `/oscars/title/tt0145781/` links "United States Army" to `/oscars/company/co0141760/` (row 1575 credits "United States Army Pictorial Service"; co0141760 was then an open needs-review pair and is now the before-ID of a wrong_id correction to co0003606); `/oscars/title/tt0268126/` shows only "Charlie Kaufman" for row 9026 (`Charlie Kaufman|Donald Kaufman`, `nm0442109|?`); `/oscars/title/tt0036910/` shows only "J. Arthur Rank" for row 2112 | Read this session; live anonymous GETs of 2026-09-24 |
| **Label lookups land on first-label-wins labels.** The legacy rebuild registers an entity with the first label it pairs by index (`main:1134-1157`, pairing at `main:1358-1360`), so a misaligned row gives an ID a label that is not one of its credits. Example: row 3258 (`Radio Corporation of America|Watson Jones`, `?|nm0429444`) gives nm0429444 the label "Radio Corporation of America", and `/oscars/category/scientific-or-technical-award-class-iii/` links "Radio Corporation of America" to `/oscars/name/nm0429444/` through the label lookup on the ID-less rows 1883 and 2274. On that hub, 6 distinct name anchors point to IDs that no Class III row of the upstream file carries | Read this session; live GET and a scope count this session |
| **IDs the ledger links nowhere.** With PR #39's overlay (372 corrections, 1 open needs-review item), 33 IDs appear in the upstream FilmId/NomineeIds but in no linked ledger identity: 13 companies (e.g. co0058013 on 33 rows and co0080422 on 86 rows, each replaced everywhere), 16 people (among them nm0239470, whose only credit is the flagged pair, and nm0230800, the other before-ID of row 5671) and **4 titles**, all FilmId corrections: tt0046593 (row 3349, ceremony 29), tt0169446 (row 6429, ceremony 55), tt0094416 (row 7876, ceremony 67) and tt3034436 (row 543). The rows carrying them sit in 51 ceremonies and 20 categories; a greedy cover reaches every never-link ID with 22 ceremony hubs, 12 category hubs and 29 title pages. The set is generated (§4.4.10) | Python replica this session |
| **Production has drifted from the CSV in documented ways.** The builder notes that the live master "lost roughly 688 flags" and has "mojibake in display names" (`builder:826-836`), and offers a set-only winner backfill (`UPDATE … SET winner = 1`, `builder:941-955`) and a name-repair tool. The importer notes a partial upload on 2026-09-19 (`main:16463-16464`). `get_awards_row_fingerprint()` hashes all 14 fields, winner and names included (`main:3242-3252`). `backfill_key()` keys a row on (ceremony, canonical category, sorted tt/nm/co tokens, detail) (`builder:922-929`) | Read this session |
| **Name-only rows.** 345 International Feature rows have Name (the country) and empty Nominees and NomineeIds (e.g. source_row 3470, "West Germany"). `normalize_awards_row()` copies Name into `nominees` (`main:3654-3656`), so the master has one credit label and no ID, while the ledger has no credit slot. The ballot prints that label as the credit (`templates/hub-page.php:2022-2026`) | Python count and read this session |
| **Label re-routing of positional IDs.** `templates/entity-page.php:75-104` (`$render_linked_pipe`) pairs by index when the counts are equal, then passes every ID through `canonicalize_name_entity_id_for_label($id, $label)` (`:87-88`; defined `main:7937-7985`). That function keeps the ID only when `get_projected_entity_label($id)` (`main:1651`) matches the credited label, otherwise it returns any other `aat_entities` row whose `label` equals the credited text and has rows. It is called for title-page nominee lines (`:1110`) and name-page film lines (`:1115`). With credit-mode names this diverts correct links: source row 1814 (Minstrel Man, tt0037076) credits 'Paul Webster' as nm0916990, which is credited 'Paul Francis Webster' 15 times and 'Paul Webster' once, while nm0916986 (Atonement, row 9635) is credited 'Paul Webster' once; row 2564 (Sunset Blvd., tt0043014) credits 'Arthur Schmidt' as nm0772834 (credited 'Arthur Schmidt' 1, 'Arthur P. Schmidt' 1), while nm0772831 is credited 'Arthur Schmidt' 3 times. Today both title pages link the right IDs (live probe: 'Paul Webster' → `/oscars/name/nm0916990/`, 'Arthur Schmidt' → `/oscars/name/nm0772834/`) | Read and measured this session; live GETs of `/oscars/title/tt0037076/` and `/oscars/title/tt0043014/` |
| Rows reaching templates carry no `id`: `get_awards_row_fields_sql()` (`main:3196-3198`); `get_category_latest_winner()` selects no `id` and no `citation`, keeps only the first tt and does not normalize (`main:2993-3036`, the SELECT at `:3010`) | Read this session |
| **Hub pages never render a citation.** `templates/hub-page.php` contains no `citation` token. `$aat_winner_primary` (`:111-143`) falls back to `film`, so a citation-only row gets no primary label. Live: the 86th card on `/oscars/category/scientific-and-technical-award-academy-award-of-merit/` shows only "Winner" and "Special Photographic" (row 10475's Detail). The entity page does render citations (`templates/entity-page.php:1121-1122`) | `grep` and live GET this session |
| `get_ballot_category_groups()` excludes classes Special and SciTech (`main:7711`). Rows 965 (Special) and 276 (SciTech) therefore reach hub pages through the winner and people renderers (e.g. category-hub winner circle `hub-page.php:2836-2839`, nominee people `:2906-2910`), not the ceremony ballot. Row 8165 (Production) reaches the ceremony-69 ballot at `:2026` in full-ballot mode (`?ledger=full`, `:1213-1214`). Live today, `/oscars/category/special-award/` links 'The Motion Picture Relief Fund' → nm0380965 and 'Ralph Morgan' → nm0088759 | Read in a prior session; live GET this session |
| **Count-guarded consumers** (safe once V11 holds, subject to the canonicalize and legacy-guard fixes in §5): `templates/entity-page.php:75-104` (but **not** its caller `:209-260`, which is unguarded, above); `assets/js/academy-awards-table.js:524-551` and `:552-570`; `map_pipe_value_to_id()` (`main:5853-5868`); `build_nominee_name_index()` (`main:3736-3775`, used only by the legacy writer resolvers `main:4557-5295`); theme `inc/frontend.php:2572-2582` (used at `:2616`); theme `inc/oscars-portal.php:609-624` | Read this session |
| **Positional suggestions in DataTables.** For rows whose name and ID counts differ, `renderNomineeCell` (`assets/js/academy-awards-table.js:558`) prints the name list followed by `renderProfilePills(ids)` (`:471-485`), pills labelled "Lunara 1…N" in ID order. For row 965, "Lunara 1" (nm0380965, Jean Hersholt) follows "The Motion Picture Relief Fund". `ajax_get_awards_datatable()` (`main:16223`) returns uncached row data (only `records_total` is a transient) | Read this session |
| **Unguarded positional consumers in the theme:** `lunara_oscar_nominee_id_for_label()` (`functions.php:12706-12725`, pairs by index, then falls back to the first ID; the only definition, so it is live code); `lunara_resolve_oscars_winner_person_id()` (`inc/oscars-data.php:169-188`, its single-ID branch returns `ids[0]` when Name is empty, whatever the label count), which feeds `lunara_get_oscars_entry_person_url()` (`:194-209`) and the portal winner circle (`templates/table-display.php:425-453`); `lunara_oscars_person_index_absorb()` (`inc/oscars-portal.php:625-631`), whose single-ID branch maps the whole Name string to the one ID whatever the name count (15 corrected rows, all company-plus-person credits keyed by long Name strings) | Read this session |
| Lunara Core field types: `release_year` is ACF `number`, min 1888, max 2100 (`lunara-plugin-core/includes/class-lunara-entities.php:160-167`). `ceremony_year` is ACF `number`, min 1929, max 2100, labelled "Ceremony Year" (`:385-392`). `ledger_entry` is registered `public => false`, `show_in_rest => true` (`:94-109`) | Read in a prior session |
| **Theme year flattening:** award history `$year = ! empty( $row['year'] ) ? (int) $row['year'] : 0;` (theme `inc/entity-surfaces.php:235`) printed at `:244`; JSON-LD `datePublished = (string) (int) $year` (`:444-446`); `normalize_year()` keeps the first 4-digit year (`inc/debrief-resolver.php:69`, `:271-273`, display only, `:79`). Live, `/film/cavalcade/` prints `1932` in all four award-history rows and `datePublished: "1932"`. Movie archive and filmography lanes order by `meta_value_num` on `release_year` (`inc/entity-surfaces.php:179-181`, `:321-324`); MySQL casts `'1932/33'+0` to 1932, so a verbatim label still sorts. Theme ledger-entry queries count `post_status != 'trash'` and sort by `ceremony_number` (`:28-79`). The JSON-LD award strings print the row year verbatim (`:374-401`). Other `release_year` displays print the value verbatim (`single-movie.php:19`, `single-review.php:317`, `inc/debrief.php:1263`, `inc/debrief-method.php:400`, `inc/entity-surfaces.php:262`, `inc/live-search.php:220`); the admin auto-link helper compares it with a review's year (`inc/control-desk.php:7723-7727`) | Read this session; live GET of `/film/cavalcade/` |
| **Theme Oscars transients outlive a swap.** `lunara_oscars_person_index_v1` (theme `inc/oscars-portal.php:642`, 12 h, `:671`), `lunara_oscars_rotating_showcase_v4_{day}_{limit}` (`inc/oscars-data.php:862`, 1 day, `:939`), `lunara_home_ledger_story_cards_v2` (`:1219`, 6 h, `:1327`), `lunara_home_oscar_spotlight_v1` (`:1342`, 12 h, `:1685`) and `lunara_home_deep_cuts_v1` (`:1701`, 1 day, `:1882`). None carries a dataset version. They are deleted by `lunara_invalidate_oscars_data_caches` (`inc/queries.php:420-445`) and `lunara_flush_oscars_home_transients` (`inc/oscars-data.php:483-509`) on `aat_after_data_import`, and the person index only by the daily warm (`inc/oscars-portal.php:910-926`, delete at `:915`). **The person index is built only by that warmer**: the render path gets `[]` on a miss and falls back to the film poster (`:636-651`), so a stamped key would render without portraits until the next warm unless a swap schedules `lunara_oscars_portal_warm_visuals_now` (`:926`, already used by `:941-942`). `lunara_invalidate_oscars_data_caches` (`inc/queries.php:445`) and `lunara_oscars_board_art_invalidate` (`inc/oscars-portal.php:945`) listen only to `aat_after_data_import`. A request that read pre-swap tables and writes after those deletes keeps stale data for the full TTL. `tests/site-studio-oscars-winner-cases.php:28` seeds the unstamped showcase key. The `functions.php` copies (`:7349`, `:7474`, `:7835`, `:8024`) are `function_exists`-guarded and dead, because `functions-loader.php` loads `inc/` first (`functions.php:27`) | Read this session |
| **Graph builder:** `run_step()` runs stages movies → people → studios → ledger → verify (`builder:193-245`; `step_entities` advances movies → people → studios at `:284`, `step_studios` advances to `ledger` at `:369-370` and `:383-384`). `step_studios` finds a studio term by its **label** with `term_exists($label, 'lunara_studio')` and inserts a new term when the label is new, then sets `_lunara_entity_id` on whatever term it found (`:387-399`), so a relabelled company gets a second term, and a new name equal to another company's old name re-points that company's term. `lunara_studio` is a public taxonomy with rewrite slug `studio` (Core `includes/class-lunara-entities.php:118-133`); WordPress has no old-slug redirect for terms. For an existing `ledger_entry` post, `step_ledger` only counts an update and never refreshes `post_title` (`:452-455`); relationships are appended row by row (`:497-508`) after `start_run` deleted them. `step_entities` (`builder:264-364`) retitles with `wp_update_post(array('ID' => $post_id, 'post_title' => $label))` only (`:324-327`), so slugs never change, and it records no old title. Release year = `max(1888, (int) MIN(c.sort_year))` over `award_facts.film_entity_id` only (`:294-311`), written on every run whatever the existing value (`:347-350`). `step_ledger` (`:404-515`) writes `movie` and `person` only when non-zero (`:480-485`), `ceremony_year = (int) sort_year` (`:448`, `:488-490`) and `person` only for an `nm` `primary_entity_id`. `start_run` deletes `directors` and `principal_cast` on every movie (`:587-598`); `cron_step` reschedules at +30 s (`:644-649`). `auto_resync` runs on `aat_after_data_import` at priority 20 (`:59`, `:790-796`). The daily builder heartbeat (`:803-820`) calls `resync_from_master()` (`:774-782`) → `lunara_rebuild_reporting_tables()` (`main:1060-1062`) when master and facts counts differ | Read this session |
| **Media-recovered links exist only in production.** The legacy rebuild recovers nominee IDs for rows whose normalized `nominee_ids` is empty from the production media library: `$recover_nominee_ids_from_profile_media` (`main:1196-1227`) over `build_profile_media_person_label_id_index()` (`main:957-…`), applied at `main:1350-1356`, then fed to the nominee loop (`main:1358-1388`) and `facts.primary_entity_id` (`main:1394`). The offline builder and CI have no media library | Read this session |
| **Accent folding differs offline.** `normalize_entity_name_key()` (`main:3715-3731`) calls `remove_accents` only when it exists (`:3721`). The reporting-integrity stubs (`tests/reporting-integrity-contract.php:221-290`) define neither `remove_accents` nor `get_option`. Four IDs have credited forms that differ only by accents: nm0005838 Georges Perinal/Périnal, nm0306223 Jose Antonio Garcia/José Antonio García, nm0380057 Martin Hernandez/Martín Hernández, nm3234869 Ludwig Goransson/Göransson. The corrected TSV contains exactly 39 distinct non-ASCII characters (U+00AE, U+00C0–U+00FC Latin-1 letters, U+0101, U+0113, U+011B, U+0161, U+017E, U+2018, U+2019) | Read and measured this session |
| **The Control Desk drift SQL uses the legacy stats formula.** The expected-stats subquery is a `UNION ALL` of `facts.film_entity_id` and nominee rows (`main:17569-17591`), which the ledger's DISTINCT title-slot ∪ nominee stats cannot equal | Read this session |
| `rebuild_reporting_tables()` is reached from `main:759` (activate), `:882` (upgrade), `:1683` (public empty-facts path), `:12209`, `:12302` (person-credit corrections), `:16576` (full import), `:16899` (delta), `:16927` (`ajax_repair_schema`), `:17210` (bundled import), `:18068` (clear) and `builder:778` | Prior-session grep |
| Nothing inline guards the upgrade path: `maybe_upgrade_schema()` (`main:774`) runs on `plugins_loaded` for every request (`main:601`) and gates dbDelta on `aat_schema_checked_version` (`:779-788`), so every request that arrives before the option is written repeats the dbDelta passes, and calls `rebuild_reporting_tables()` synchronously at `:882` on every version change. `ensure_projection_data_available()` (`main:1668`) rebuilds inline from a public request when facts are empty (`:1668-1690`) | Prior session |
| 13 tests hard-code `2.7.92`: ceremony-payload-hygiene, company-studio-credit-classifier, existing-people-* ×5, manual-profile-image-batch-import, oscars-read-api, person-credit-full-row-resolver, person-credit-reconciliation-audit, person-credit-review-queue, person-portrait-import-queue. The 14th hit is the historical comment at `tests/schema-dbdelta-contract.php:16`, which must not change | Prior-session `grep -c` |
| `tests/category-names-runtime.php:5-8` extracts **public** methods only. `tests/entity-category-labels-runtime.php:6-8` also accepts private ones | Prior session |
| `tests/reporting-integrity-contract.php:217-290` and `tools/verify-live-dataset.php:37-125` load the whole plugin with stubs for add_action, add_filter, apply_filters, add_shortcode, register_activation_hook, wp_next_scheduled, wp_schedule_event, wp_strip_all_tags, sanitize_text_field and sanitize_textarea_field only; the test then invokes `get_bundled_award_group_census()` and asserts 12,137 rows and 3,515 winners of the **upstream** file (`:300-330`) | Read this session |
| `tests/schema-dbdelta-contract.php:19-30` captures every `CREATE TABLE ` in the main file and `includes/*.php`. Each must name `$[a-z_]+`, `` `$[a-z_]+` `` or `{$[a-z_]+}`, and there must be at least 21 | Prior session |
| `tests/public-query-path-contract.php:109-111`: the count of `AAT_BUNDLED_CSV_PATH` in the main file equals its uses in `ajax_import_bundled_data…ajax_clear_data` plus 1. `:97-98` pins the DataTables path's `'id, ' . $this->get_awards_row_fields_sql()` | Prior session |
| `tests/oscars-read-api-contract.php:108-113` requires at least as many `$this->clear_oscars_read_api_caches();` calls as hub grid sites plus 1 | Prior session |
| `tests/credit-structure-contract.php:37-44` pins six markers in `assets/js/academy-awards-table.js` (`const nominees = this.splitPipe(row && row.nominees`, `nominees.map((nominee, index)`, `const officialCredit = row && row.name`, `normalizeCreditPeople`, the `\band\b` replace, `aat-credit-line`) and, at `:48-49`, two in `$aat_winner_primary` (`$category === 'MUSIC (ORIGINAL SONG)' && $detail !== ''`, `strpos($category, 'WRITING (') === 0`) | Read this session |
| Existing DDL puts one column or index per line, and multi-column keys are written `(a, b)` (`main:248-377`) | Prior session |
| `wpdb` removes strict SQL modes, so oversize values truncate silently. Maximum lengths over the corrected TSV: Film token 127, Nominees token 111, Name 203, Detail 69, Category 137, Note 1,805, Citation 701, id slot 19. At most 4 title slots and 13 credit slots | Prior session |
| Year digit-strips (`preg_replace('/[^0-9]/'…)`) at `main:4280, 4365, 8410, 8540, 8551, 8769, 9048, 9090`. `:9048` feeds `get_title_visual_package()`'s `release_year` (`main:9427-9460`) and the TMDb `primary_release_year` search (`main:9183-9194`) | Prior session |
| `tools/` ships (not in `.deployignore`); `docs/`, `tests/`, `*.sql` and `*.xlsx` are deployignored (`.deployignore`) | Read this session |
| CI (`.github/workflows/lint.yml`) runs PHP **8.2**. `actions/checkout@v4` has no `fetch-depth` (`:12`), so only the merge commit is present and `origin/main` history is absent. It runs every `tests/*.php` except two with `set -e`, `php -l` on every `*.php` **including `docs/`**, `node --check` on every `*.js` (not `.cjs`), and a CSS brace check | Read this session |
| Local tooling: PHP 8.4.19 only; `mysqld`/`mariadb` present; the `docker` CLI is present but no daemon runs | Prior session |
| Critic's measurements: `proto/mem.php` (parse + overlay + hash) 0.21 s / 22.0 MB; `proto/sim.php` (partial derivation) 110 MB / 2.4 s | `critique.md` |
| **DLu/oscar_data licence: BSD 2-Clause, "Copyright (c) 2022, David V. Lu!!".** Clause 2 requires the notice, conditions and disclaimer to be reproduced in documentation or other materials that accompany a redistribution. The README says the data is parsed from the Academy's Awards Database and merged with IMDb identifiers. The licence covers DLu's compilation; the Academy's own texts remain the Academy's | `https://raw.githubusercontent.com/DLu/oscar_data/main/LICENSE`, re-fetched this session (the GitHub API for that repository is not enabled for this session) |
| No `robots_txt` filter exists in the theme, the plugin or Core. Live `/robots.txt` is WordPress's virtual file (it carries Jetpack's `Sitemap:` lines and `Disallow: /wp-admin/`), so the `robots_txt` filter reaches it. `/oscars/ceremonies/` sends no `Content-Security-Policy` header | Prior-session grep; live GETs this session |
| The live plugin directory is `academy-awards-table-optimized/` | Theme `docs/SESSION-LOG.md` top entry |
| AGENTS.md: `:72` "Deployment is Dalton's button, always"; `:81` "Auto-deploy stays off"; `:87` no cache clearing as a fix; `:90-94` bump the cache version when a cached payload's shape changes; `:167` "Do not open a PR unless asked, and never deploy". The SESSION-LOG top entry records Dalton's instruction ("open draft PRs and merge them and get it live on the site") and the theme auto-deploy switch, and says the plugin connections are unconfirmed. Decision 14 (the lead reports Dalton confirmed both) supersedes that; AGENTS.md is Dalton's to edit | Read this session |
| Theme search cache key `'lunara_ls_' . md5(mb_strtolower($q) . '\|' . ($ledger_on ? '1' : '0'))`; no test pins it | Theme `inc/live-search.php:176-177` |
| The theme ratchet counts the literal `academy` + `_awards` in every theme file outside `tests/`, pinned at 22 | Theme `tests/oscars-read-path-ratchet.ps1:47-82` (prior session) |
| No theme test pins `datePublished`, `normalize_year`, `lunara_entity_render_award_history`, `lunara_oscar_nominee_id_for_label` or `lunara_resolve_oscars_winner_person_id`; `tests/fixtures/debrief-resolver-harness.php` uses only 4-digit years | Prior-session grep |
| **Database-generated columns.** Every legacy projection table has `updated_at datetime DEFAULT CURRENT_TIMESTAMP` (`main:253, 263, 274, 345, 358, 372`) or `created_at` (`main:294, 319`); facts and nominees have an `AUTO_INCREMENT id` (`main:281, 309`); the master has `created_at` (`main:698`, `:820`). A checksum over all columns would differ on every load | Read this session |
| **Hub CSS budget.** `tests/ceremony-payload-hygiene-contract.php:56` requires `assets/css/hub-polish.css` < 7,000 bytes; the file is 6,976 bytes. `assets/css/academy-awards-table.css` (187,829 bytes) has no byte budget; four tests pin some of its rules (`tests/inner-page-visual-rhythm-contract.php:8`, `person-profile-visual-integrity-contract.php:9`, `related-review-media-guards.php:36`, `winner-circle-media-contract.php:5`) | `wc -c` and grep this session |
| **Decade bucketing on hubs** is `floor(intval(year label) / 10) × 10` over `get_ceremony_year()`, which returns the ceremony's year label (`main:2914-2935`). Ceremony 3 (`1929/30`) is therefore in the 1920s: 1920s = ceremonies 1–3, 1930s = 4–12, 1940s = 13–22 | Read and computed this session |
| **Jetpack Boost 4.7.1 defers JavaScript** on the site (theme `docs/PERFORMANCE-PLUGIN-AUDIT-2026-09-17.md:58`). Boost moves inline scripts to the end of `<body>` unless the tag carries `data-jetpack-boost="ignore"`. No theme or plugin file uses that attribute today | Read and grep this session |
| **Entity URLs bypass the two template closures** (fourth critique). `get_entity_url()` (`main:2502-2504`) only delegates to `build_entity_url_from_id()` (`main:7802-7809`). Templates call it directly: `templates/hub-page.php:815` and `:858` (review cards), `:1769` (best-picture cards, which prefer the payload's pre-built `film_url`), `:2100` (ceremony highlights grid), `:3055` (category highlights grid); `templates/table-display.php:253`, `:363` and `:393` (portal). Cached payloads carry URLs built by `build_entity_url_from_id()`: the ceremony rollup (`main:2610`, `:2622`, `:2643`, cached one hour at `:2728`) and the latest winner (`main:3030`). `$aat_enrich_winner_entry_links` falls back to the payload's `film_url` when its own closure returns '' (`hub-page.php:313-315`), and the era spotlight reads that result (`:2793`); the latest-winner film chip prints `$latest_winner['film_url']` (`:2677`). DataTables' `renderFilmCell` pairs `film` with `film_id` by index (`assets/js/academy-awards-table.js:617-643`); `buildEntityUrl('?')` returns '' (`:453-462`). The theme's search builds title URLs from a single `film_id` itself (theme `inc/frontend.php:2211-2231` and `:2596-2610`). Live, as the critic recorded: `/oscars/ceremony/55/` links "Just Another Missing Kid" (row 6429) to `/oscars/title/tt0169446/`, the before-ID of its FilmId correction; rows 3349 and 7876 behave the same way on ceremonies 29 and 67. `get_title_visual_package()` (`main:9427`) would also attach that wrong title's poster to the card | Read this session; replica check of rows 6429, 3349 and 7876 |
| **A stub harness renders the real entity template.** `tests/entity-category-labels-runtime.php:54` extracts only `format_category_display`, `get_category_url`, `get_entity_rows` and five `is_*_entity_id` methods; the eval'd stub class (`:57-70`) adds hand-written methods (including `build_entity_url_from_id` at `:64`) but no `get_entity_url` and no resolver method; `:83` includes the real `templates/entity-page.php`, and its rows carry `nominees` and `nominee_ids` (`:72-76`), so the title-page nominee line runs | Read this session |
| **Hub anchors.** The only `id="ceremony-category-…"` source is the ceremony ballot (`templates/hub-page.php:1993`), and the ballot excludes classes Special and SciTech (`main:7711-7712`). Category-hub history cards are `<article class="aat-category-ceremony-row aat-ledger-card…">` with no id (`hub-page.php:2823`); every winner row of the ceremony renders inside (`:2835-2839`). `tests/inner-page-visual-rhythm-contract.php:35` pins the class substring | Read this session |
| **Person strips accept only people.** `$aat_build_person_link_items` links an item only when its ID matches `^(nm\d{7,9}|lnm-[a-z0-9-]+)$` (`hub-page.php:393-395`); resolver links also carry company IDs (row 526: United Artists, co0026841) and the title-primary fill-in tt. The latest-winner card renders `person_url` as a person chip (`hub-page.php:2683-2684`), set from the name link in `$aat_enrich_winner_entry_links` (`:335-341`) | Read this session |
| **The TMDb fallback accepts any year.** When `/find` fails, the TMDb search (`main:9183-9213`) accepts an exact title match of the context year, or of any year when the year is empty, and otherwise `results[0]` (`:9209-9211`). The OMDb audit compares digit-stripped years (`main:8410`, compared at `:8420`). `:8480` and `:8521` compare the admin form's year with the dataset's, both digit-stripped | Read this session |
| **Jetpack Boost critical CSS.** The site's only recorded fix for a stale-critical-CSS CLS regression (phone homepage 0.72) is a Boost regeneration in wp-admin (theme `docs/SESSION-LOG.md:203`, `:227` "Dalton: regenerate Boost critical CSS"). Boost injects its stored block as `<style id="jetpack-boost-critical-css">` (theme `tests/journal-archive-payload-gate-runtime.js:62`). The theme's pattern for route-owned first paint is its own synchronous seed plus route stylesheets kept out of Boost's async deferral (theme `inc/frontend.php:1091`, `:1271-1281`), and a browser regression that replays an archived production Boost block and proves its removal moves geometry by at most one pixel (theme `docs/CHANGELOG.md:1655-1665`) | Read this session |
| **The Atomic edge caches anonymous `/wp-json/` GETs** (`x-ac` MISS then HIT on `lunara/v1/search`; theme `docs/SESSION-LOG.md:42`). The WordPress.com plugin list, read through IsOnWP diagnose, reported the live plugin version at the last deploy (`:39`) | Read this session |
| **Core CORS.** For every REST request core sends `Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type`, filtered by `rest_allowed_cors_headers` (since 5.5; `$request` argument since 6.3), and `Access-Control-Expose-Headers` filtered by `rest_exposed_cors_headers` (WordPress 6.8 `class-wp-rest-server.php:395-434`). `If-None-Match` and `If-Modified-Since` are not CORS-safelisted request headers | Read this session in a WordPress 6.8 tree |
| **Option autoload.** `wp_set_option_autoload($option, $autoload)` exists since WordPress 6.4 (`wp-includes/option.php:537-548`); `update_option()` changes autoload only together with the value | Read this session in a WordPress 6.8 tree |
| **The master id is an AUTO_INCREMENT column** (`id mediumint(9) NOT NULL AUTO_INCREMENT`, `main:805`), which the job sets explicitly. `tests/multi-film-label-contract.php:72-78` asserts that the raw `esc_html(' ' . $latest_winner['detail'])` marker is **absent**; the template prints `esc_html(' ' . $aat_film_display($latest_winner['detail']))` (`hub-page.php:2640`) | Read this session |
| **PR #39's derived artifacts.** `data.sql.gz` has 373 `ledger_corrections` lines (372 cells plus the addition, recorded with field `'(row)'` and the row as JSON), keeps the flagged identity (`INSERT INTO ledger_credit_identities VALUES (5671,4,'nm0239470')`), and its `ledger_dataset` row carries `source_sha256` 577a326c… (the audit's intermediate JSON, not the CSV). `integrity.sql:7-11` hard-codes 12138, 3516, 98, 66 and 90. The workbook's Corrections sheet spans `A1:I374` and `full_data` `A1:N12139` (`xl/worksheets/sheet1.xml`, `sheet3.xml`). `docs/database/tools/make_workbook.py` takes `additions.json` as an optional fifth argument | `zcat`, `ZipArchive`-equivalent read this session |
| **Upstream-count literals in tests.** `tests/reporting-integrity-contract.php:80-81` (12,138 file lines, 3,515 winners of `data/oscars.csv`), `:326-327` (the stubbed census of the upstream file: 12,137 and 3,515), `tests/winner-backfill-identity-contract.php:60` (12,137 rows of `data/oscars.csv`) and `tests/source-data-validation-contract.php:63-65` (an external source workbook) | `grep` this session |

---

## 2. Architecture

```
data/oscars.csv (unchanged)            data/ledger/corrections.json (cell overlay)   data/ledger/additions.json (appended rows)
data/ledger/entities.tsv, titles.tsv (the only committed name source), name-overrides.tsv, needs-review.json,
data/ledger/accepted-drift.json, simulation.json, legacy-link-guard.json, manifest.json          data/LICENSE-oscar_data.txt
(the evidence-bearing values of every authored JSON file are redacted; dataset values never are, §4.14)
                 │
                 ▼  AAT_Ledger_Source  (pure PHP: RFC parse, codec checks, before-checked cell overlay,
                 │                      backslash-quote decode, appended rows, corrected sha = manifest)
                 ▼  AAT_Ledger_Deriver (the ONE derivation: a model, then per-table emitters; pure except the master bridge)
   ledger model ──► emit(table, from, limit) and checksum(table) for each of 25 stage tables, in deterministic order
                 │
                 ▼  AAT_Ledger_Importer (WP-Cron ticks, GET_LOCK, per-table resumable prepare and load → validate V1–V13
                 │                       → provenance-aware diff + automatic bounds + simulation match → one RENAME → verify → finalize)
   LIVE (one statement swaps all 25):
     aat_ledger_{meta,ceremonies,classes,categories,titles,entities,aliases,nominations,
                 nomination_titles,nomination_credits,credit_identities,corrections}      (relational)
     aat_ledger_{group_stats,entity_stats,search,nomination_text}                          (API read tables)
     legacy master + aat_{ceremonies,categories,entities,award_facts,award_nominees,
                          ceremony_stats,category_stats,entity_stats}                     (mirrors)
   PERSISTENT: aat_ledger_datasets, aat_ledger_award_keys (written only by F3, after the RENAME), aat_ledger_report_pages
                 │
     ┌───────────┼──────────────────────────────┬────────────────────────────┐
     ▼           ▼                              ▼                            ▼
 legacy pages,  resolve_credit_links()     lunara-ledger/v1 REST  ◄── AAT_Ledger_Service ──► /oscars/explore/
 search, graph  (link overrides from        (reads aat_ledger_* only)   (in-process facade)   (server render +
 builder,        aat_ledger_meta; legacy          ▲                                            JS calling REST)
 Control Desk    link guard; §5)                  └── standalone app (server-side fetch recommended; CORS *)
 theme: lunara_oscars_reader() → is_explorer_request(), get_explorer_url(), get_dataset_stamp(), credit_pair_is_guarded() only
```

**States.** `AAT_Ledger::state()` ∈ `legacy | live | frozen | rolled_back` (§4.6.10). `AAT_Ledger::master_is_ledger()` is true in `live` and `frozen`, the two states in which the legacy master and projections are the ledger's. Two further predicates separate what revision 3 conflated: `AAT_Ledger::refuses_legacy_writes()` (true in `live`, `frozen` and `rolled_back`; it gates the legacy writers and the legacy rebuild) and `AAT_Ledger::accepts_import($bundle_id)` (false only in `frozen`, or for the pinned rolled-back bundle; §4.6.1).

**Stamp and token.**
- The stamp is `substr(sha256(bundle_id . ':' . swap_seq), 0, 12)` for the swap that is about to happen (`swap_seq` = the live value + 1; for a rollback to the pre-ledger data, `bundle_id` is the empty string). **The job computes it in prepare and writes it into the stage (or restore-source) `aat_ledger_meta` row `stamp`, so the stamp goes live in the same `RENAME` as the tables it describes** (§4.6.7). F2 then copies the same value into the autoloaded option `aat_ledger_live`.
- `AAT_Ledger::stamp()` returns the option's stamp in every state except `legacy`, where it returns ''. Every swap, re-assert and rollback writes a new stamp. Plugin transients use `$key . '__' . $stamp`; the theme's long-lived Oscars transients do the same from 3.2.91 (§8.1). Between the `RENAME` and F2 (milliseconds) a transient may be written under the old stamp from new tables; that key is never read again after F2.
- `AAT_Ledger::live_meta_stamp()` reads the `stamp` row of the **live** `aat_ledger_meta` table once per request (static cache, one primary-key SELECT) and is what the API token and the explorer's `data-ledger-token` use, so a token always describes the tables it was read with (third critique).
- API token = `substr(sha256(live_meta_stamp . '|' . AAT_VERSION . '|' . AAT_Ledger::API_REVISION . '|' . home_url('/') . '|' . get_entity_base_slug()), 0, 16)`.
- Object-cache keys, ETags, cursor binding, `v=` immutable URLs and the explorer's `data-ledger-token` all use the token.
- Because the token includes `AAT_VERSION`, every plugin release rotates it (§6.5).

**Revision constants** live in `includes/class-aat-ledger.php`, the only ledger class loaded on every request: `AAT_Ledger::DERIVER_REVISION` (integer, currently 5; revision 4 of this plan changed display-name rule 3 and link-override membership, and revision 5 the shipped overlay formats, the per-entry corrections rows and the title list of the guard's `pairs`) and `AAT_Ledger::API_REVISION` (`'1.0.0'` from R3; `'0.1.0'` in R1 and R2). The deriver and the API reference these constants and define no copies.

---

## 3. Release train

| Release | Repo / version | Content | Live proof | Undo |
|---|---|---|---|---|
| **R1** | plugin 2.8.0 | Ledger data layer, dormant. Tables, bundle, deriver, job in **dry_run** mode. `/status`, `/status/report` and `/license` routes. Cron heartbeat evidence. Dataset meta marker. Legacy-path hardening. Read-side fixes (citation-only rows included). Positional guards: mismatched rows stop pairing by index, including the title-page nominee line; public pages stop guessing links from labels; the legacy link guard unlinks known-wrong, flagged and first-label-wins pairs and every link to an ID the ledger links nowhere. DataTables stops showing numbered pills. Builder fixes that do not depend on the ledger. Public-repo privacy clean-up, including evidence redaction (U00) | §12 R1: the plugin list, then `/status`, shows 2.8.0; `validated_dry_run` with `simulation_match: true`; cron evidence; generated credit-link probes (legacy mode); row 10475's citation on its category hub | Revert commit (no data changed) |
| **R1b** (only if needed) | plugin 2.8.1 | The remediation loop's regenerated bundle, still `dry_run` (U29). Repeats at most twice more | The same R1 checks, with `simulation_match: true` | Revert commit |
| **R1T** | theme 3.2.91 | Theme positional guards (`functions.php:12706-12725`, `inc/oscars-data.php:169-188`, `inc/oscars-portal.php:625-631`) and legacy-guard consult. Year labels never flattened (award history `inc/entity-surfaces.php:235`, JSON-LD, Debrief resolver). Dataset-stamped theme Oscars transients and live-search key. `aat_ledger_swapped` listener that invalidates, flushes and schedules the portal warm | `lunara-canary-verify.sh 3.2.91` exit 0; runtime tests | Hatch / revert |
| **R2** | plugin 2.8.1 | Manifest `mode: swap`. Slug migration active. Builder resync | `current`, new stamp, live sentinels ok; sentinel titles and links on stamp-matched pages; slug probes; builder converged | Data-layer failure (class D): next patch with manifest `mode: rollback`, then an automatic fix-forward swap. Render, cache, builder or slug failure on a correct data layer: re-probe, then fix forward in code. **Never a code revert below 2.8.0** (§4.6.9) |
| **R3** | plugin 2.8.2 | Full read API `lunara-ledger/v1` and JSON Schemas; rate limiter log-only | Status `ready: true`; MISS→HIT; 304; CORS `*`; spot checks; full-sync verifier exit 0 | Revert commit (the API is additive; the data stays) |
| **R4** | plugin 2.8.3 | Explorer E1 at `/oscars/explore/`, plus robots.txt rules | `verify-explorer-live.sh 2.8.3`; live browser gate at 390 px against the measured baseline | Revert commit (the route disappears at the next version-gated rewrite flush) |
| **R5** | theme 3.2.92 | Explorer detection, dossier-emitter exclusion, footer and Control Desk links | `lunara-canary-verify.sh 3.2.92`; body class and link probes | Hatch / revert |
| **R6** | plugin 2.8.4 | E2: `?view=table` → 302 to the explorer, in-plugin link repoints, SRI on the remaining DataTables tags, daily redirect-health record | Redirect probes; canary clean | Revert commit |
| **R7** | plugin 2.8.5 | E3: DataTables client and CDN removed, only when the content census is clean | No `cdn.datatables.net` in page sources | Revert commit |
| later | plugin 2.8.6+ | The 302 becomes a 301 after 7 clean days (U27). Limiter enforcement only after the REMOTE_ADDR evidence (U28). Both preconditions are read from `/status` daily buckets, and a scheduled routine wakes the train to check them (§7.9) | Per unit | Per unit |

**Ordering constraints.**
- R1T must be live before R2 merges, because R2's builder resync writes verbatim labels into `release_year` and `ceremony_year`, and R1T is what prints them unflattened.
- R2 merges only when R1's automatic gate is met (§12 R2).
- R4 follows R3; R5 follows R4.

**Numbering rule.** A remediation dry run (R1b), a fix-forward or a rollback release takes the next patch number, and every later row shifts by one. The version numbers in this plan assume no such release. The five version markers must always agree (`tests/sql-performance-contract.php:28-37`).

**Deploy rule (every release; decision 14).** Merge the draft PR once its gates pass. Merge = deploy for both the theme and the Oscars plugin connections. Probe the version:
- plugin: the WordPress.com plugin list, read through IsOnWP diagnose (`academy-awards-table-optimized/`), is the **primary** probe for every plugin release (fourth critique: the edge caches `/wp-json/` GETs, §1). `/wp-json/lunara-ledger/v1/status` → `software.plugin_version` (from R1) is the confirmation. It is requested only after the plugin list shows the new version, then at most once a minute for up to 3 minutes, because its `s-maxage=60` copy can still carry the previous version or a cached 404; cache-busting stays forbidden.
- theme: `lunara-build`.

If the plugin list does not show the new version within 10 minutes of the merge, or `/status` still disagrees 3 minutes after the plugin list agrees, **report it to the owner and stop the train.** No "ask Dalton to deploy" step remains. This rule is also the proof of the plugin connection's auto-deploy setting, which the SESSION-LOG top entry called unconfirmed: if R1 is not live in 10 minutes, the train stops there with a report, and nothing else has changed. The first release's SESSION-LOG entry records decision 14 (§12).

---

## 4. Data layer (R1, R2)

### 4.1 Bundle: `data/ledger/` (deployed; `data/` is not deployignored)

| File | Format | Content |
|---|---|---|
| `manifest.json` | JSON, generated | Identity, hashes, expected counts, simulation summary and baseline, change bounds, assertions, accepted exceptions, mode |
| `corrections.json` | JSON array, byte copy of the **redacted** `docs/database/corrections.json` (§4.14) | Cell corrections (`nomination_id` = `source_row`), applied in file order; a cell may receive several (format below) |
| `additions.json` | JSON array, byte copy of the redacted `docs/database/additions.json` | Appended rows as `{row, reason, evidence, verification}`; `source_row` implied by position (format below) |
| `needs-review.json` | JSON array, byte copy of the redacted `docs/database/needs-review.json` | Open identity questions; `rows` are `source_row` values; optional `resolution` (below) |
| `accepted-drift.json` | JSON object, byte copy of the redacted `docs/database/accepted-drift.json` | Evidenced production-drift items, ID pins, restored rows and retired production-only rows produced by the remediation loop (§4.6.6); `{"schema": "lunara-oscars-ledger-drift/1", "items": [], "id_pins": [], "restored": [], "retire": []}` at launch |
| `entities.tsv` | Unquoted TSV, header exactly `imdb_id	kind	reference_name` | Every nm/co ID the corrected rows reference, and no other. **The only committed source of entity reference names** (§4.14). For people these are Wikidata labels where the audit found one (`docs/database/tools/build.py:163-171`); they are used only to break ties, fix ALL-CAPS casing and widen search, never as a display name while any Academy credit exists (§4.4.2) |
| `titles.tsv` | Unquoted TSV, header exactly `imdb_id	reference_title` | Every tt ID the corrected rows reference, and no other. The only committed source of title reference names |
| `name-overrides.tsv` | Unquoted TSV, header `imdb_id	display_name	reason` | **Header only at launch** (decision 1) |
| `simulation.json` | JSON, generated | The local simulation's per-item change lists, used by production's provenance-aware match (§4.6.6) |
| `legacy-link-guard.json` | JSON, generated | While the master is not ledger-derived: the (ID, folded label) pairs that must render as plain text, and the IDs that must never be linked (§5.6) |
| `../LICENSE-oscar_data.txt` | Text | The BSD 2-Clause notice of DLu/oscar_data, verbatim (decision 10). It sits at `data/LICENSE-oscar_data.txt`, next to the CSV it covers |

**No Wikidata years, QIDs or birth years** (decision 2):
- The bundle builder reads reference names only from `data/ledger/entities.tsv` and `data/ledger/titles.tsv`. It never reads `docs/database/data.sql.gz`.
- U00 creates those two files once, by extracting `imdb_id`, `kind` and `name` from the `ledger_entities` INSERTs and `imdb_id` and `title` from the `ledger_titles` INSERTs of the current `data.sql.gz`, and then rewrites `data.sql.gz` and `schema.sql` without the Wikidata columns (§4.14).
- Evidence-bearing values (the per-file key list of §4.14 item 6) are redacted in the repository and the bundle alike: the bundle builder rewrites the authored JSON inputs in place with the one redactor, in the shipped layout (below), before copying them, and `--check` fails when an authored file is not already in redacted form. Dataset values (`before`, `after`, appended `row` cells, needs-review `label`, drift keys and values) are never redacted (fourth critique).
- From then on the builder **maintains** the two TSVs: an ID the corrected rows reference but the TSV lacks (new from a correction or an addition) is added with an empty reference name, with `kind` from the prefix (`nm` person, `co` company); an unreferenced row is dropped; rows are sorted by `imdb_id`. `--check` fails when the committed TSV differs from the maintained one, so a new ID always arrives in the same commit as its correction.

**Corrections format**, read exactly as shipped (PR #39). `docs/database/corrections.json` is a JSON **array**. Each element has exactly the keys `nomination_id` (a positive JSON integer: the upstream `source_row`), `field` (one of the 14 header names), `before` and `after` (strings), `reason`, `evidence` and `verification` (non-empty strings), and optionally `imdb_id` (a string or `null`); any other shape refuses with `correction_invalid`. The rules:
- Entries apply in **file order**. **A cell may receive several corrections**: each entry's before-check runs against the value that the previous entry for the same (`nomination_id`, `field`) left, so a chain such as row 2112's `co0040435|?` → `?|?` → `?|co0103139` applies as two before-checked steps (9 such cells today, §1). A chain whose second `before` does not equal its first `after` refuses with `overlay_before_mismatch`, like any other stale before-value.
- `reason` is free text. The codec does not enumerate it (today: wrong_id, encoding, retired_id, credit_text, missing_id), and `manifest.overlay.corrections.by_reason` counts whatever occurs.
- `imdb_id` is informational; the derivation never reads it.
- `manifest.overlay.corrections` records `count` (entries), `cells` (distinct cells) and `multi_corrected_cells`, all generated.

**Additions format** (decision 15), read exactly as shipped (PR #39). `docs/database/additions.json` is a JSON **array**; each element is one appended row:

```json
[
 {
  "row": {
   "Ceremony": 97, "Year": "2024", "Class": "SciTech",
   "CanonicalCategory": "SCIENTIFIC AND TECHNICAL AWARD (Academy Award of Merit)",
   "Category": "SCIENTIFIC AND TECHNICAL AWARD (Academy Award of Merit)",
   "Film": null, "FilmId": null, "Name": null, "Nominees": null, "NomineeIds": null,
   "Winner": true, "Detail": null, "Note": null,
   "Citation": "To all those who have developed and supported captioning technology, whether open or closed, for film."
  },
  "reason": "missing_row",
  "evidence": "The Academy Awards Database lists this 97th-ceremony (2024) Academy Award of Merit … (full text in the file)",
  "verification": "found by the full reconciliation with the Academy Awards Database; confirmed by two independent reviewers (source lens and context lens)"
 }
]
```

The rules for an addition:
- The file is an array, and every element has exactly the keys `row`, `reason`, `evidence` and `verification`; anything else refuses with `addition_invalid`. `[]` means no additions.
- **`source_row` is implied by position**: the element at 0-based index k has `source_row` = `manifest.source.rows + 1 + k` (12,138 for today's one element). The file carries no `source_row`; the builder writes `first_source_row` and `last_source_row` into the manifest, so a gap cannot occur and `addition_row_gap` is retired.
- `row` has exactly the 14 header keys (in any order; every serialization uses the header order). One function, `AAT_Ledger_Source::addition_cell($header, $value)`, turns each value into a cell, and the builder, the codec and the Python reference implement the same table: `null` → the empty string; a string → itself; for `Ceremony` only, a JSON integer 1–999 → its decimal digits; for `Winner` only, `true` → `True` and `false` → the empty string. Any other type (another integer or boolean, a float, an array, an object) refuses with `addition_cell_type`, and a string Winner must be `True` or empty.
- `reason`, `evidence` and `verification` are non-empty strings.
- The converted cells pass the same codec shape rules as upstream rows (§4.2). No backslash may appear at all.
- Its `Year` must equal the year label that upstream rows give its `Ceremony` (`addition_year_mismatch`). A ceremony number absent upstream is allowed only when it is greater than every upstream ceremony. Its `CanonicalCategory` must keep exactly one class, the rule every category already obeys (§4.4.1).
- Its `nomination_key` (the §4.5 formula, over the converted cells) must differ from every upstream row's key (`addition_duplicates_upstream`). If upstream later adds the same row, the import refuses until the addition is removed. The id stays preserved because the key is the same.
- No cell correction may target an addition's implied `source_row` (`correction_targets_addition`). An addition is corrected by editing it in place.
- If `docs/database/additions.json` does not exist when U01 starts, U01 creates it as `[]`. It exists today with one element.
- A row whose converted Film, Name and Nominees are all empty is a **citation-only row**; every surface labels it by its Citation (§4.10). Row 10475 is the upstream precedent, and the captioning addition is the second.

**Shipped JSON layout.** The five authored JSON files (`corrections.json`, `additions.json`, `needs-review.json`, `tools/adjudications.json`, `tools/editor_decisions.json`) are in one layout: one-space indentation, `": "` between key and value, unescaped slashes and Unicode, no trailing newline. `AAT_Ledger_Json::encode_shipped()` reproduces it: `json_encode` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION`, then every leading run of 4n spaces becomes n spaces. Decoding and re-encoding all five PR #39 files reproduces them byte for byte (checked this session with PHP 8.4). The builder writes an authored file only through this encoder, and the bundle contract asserts the round trip for each file, so a redaction rewrite changes exactly the redacted values and nothing else. `accepted-drift.json`, which the builder creates, uses the same layout.

**Needs-review resolution format** (decision 5). An item is **unresolved** unless it carries:

```json
"resolution": {"status": "confirmed" | "corrected", "evidence": "…", "verification": "…"}
```

Removing a resolved item from the file is also allowed. The builder enforces three rules:
- For `corrected`, every row in `rows` must no longer contain `imdb_id` in its corrected NomineeIds, so the overlay must carry the fix (`review_resolution_unapplied`).
- For every unresolved item, each (row, ID) pair must exist in the corrected NomineeIds (`needs_review_stale`).
- `confirmed` and removed items produce linked identities; unresolved items produce flagged, unlinked identities (§4.4.4).

**Manifest keys** (the bundle builder generates all of them; the manifest is never hand-edited):
- `schema` = `lunara-oscars-ledger-bundle/3`, `dataset_version` (`YYYY.MM.DD-N`), `mode` ∈ `dry_run | swap | rollback`, `deriver_revision` (must equal `AAT_Ledger::DERIVER_REVISION`).
- `source` = `{file: "data/oscars.csv", sha256, codec: "tsv-rfc4180-noescape+backslash-quote/1", rows, header[14]}`.
- `overlay`:
  - `corrections {file, sha256, count, cells, multi_corrected_cells, by_reason}` (`count` = entries, `cells` = distinct (source_row, field) pairs);
  - `additions {file, sha256, count, first_source_row, last_source_row, citation_only}`, with `first_source_row` = `source.rows + 1` whenever `count > 0`.
- `corrected_sha256`: the TSV serialization of upstream rows **plus** additions (§4.2 rule 5).
- `reference`:
  - `entities {file, sha256, rows, without_reference}`;
  - `titles {file, sha256, rows, without_reference}`;
  - `name_overrides {file, sha256, rows}`;
  - `needs_review {file, sha256, items, unresolved_items, flagged_pairs}`;
  - `accepted_drift {file, sha256, items, id_pins, restored, retire}`.
- `licence {file: "data/LICENSE-oscar_data.txt", sha256, spdx: "BSD-2-Clause", copyright: "Copyright (c) 2022, David V. Lu!!", upstream: "https://github.com/DLu/oscar_data"}`.
- `display_name_policy` = `credit_mode/1`; `year_policy` = `eligibility_label/1`; `fold_policy` = `lunara-fold/1` (§4.4.8).
- `derived_sha256`.
- `expected`:
  - nominations (= source.rows + additions.count), winners, unofficial, ceremonies, categories, classes, citation_only;
  - titles, entities, entities_linked, nomination_titles, nomination_credits, credit_identities, flagged_identities, corrections (= corrections.count + additions.count);
  - `projection {master, ceremonies, categories, entities, facts, nominees, ceremony_stats, category_stats, entity_stats}`;
  - `read {group_stats, entity_stats, search, nomination_text}`;
  - `link_overrides {credits, titles, ambiguous}`;
  - `legacy_guard {file, sha256, pairs, label_pairs, never_link, collateral}` (§5.6).
- `simulation`:
  - `baseline`: `{kind: "legacy"}` or `{kind: "bundle", bundle_id, commit, summary: "docs/database/baselines/<bundle_id>.json.gz", summary_sha256}` (§4.6.6);
  - `file: "data/ledger/simulation.json"`, `sha256`;
  - the summary counts: labels_changed, title_labels_changed, sourced_links_added, sourced_links_removed, entities_added, entities_removed, master_rows_changed, film_entity_changes, primary_entity_changes, ids_preserved, ids_new, ids_retired.
- `simulation_tolerance` = `{abs: 5, rel: 0.02}`.
- `idless` (the bound inputs for media-recovered links; §4.6.6): `{rows, credit_labels, distinct_label_keys}` over rows whose legacy-parse normalized `nominee_ids` is empty, counting `split_visible_person_credit_labels()` labels and their distinct `normalize_entity_name_key()` values, computed through the bridge under the builder's stubs. Offline, `remove_accents` is absent (`main:3721`), so accent variants count as separate keys; that can only raise the count, so it stays an upper bound on production's media-index matches, which fold accents.
- `change_bounds`, generated (§4.6.6; decision 4):
  - each sourced `max_*` metric = `max(sim + abs, ceil(sim × (1 + rel)))`;
  - `max_ids_new` = additions.count + `accepted_drift.restored` count; `max_ids_retired` = `accepted_drift.retire` count (0 at launch; fourth critique: a production-only row had no remediation class);
  - `max_unsourced_links_removed` = `idless.credit_labels`; `max_media_entities` = `idless.distinct_label_keys`; `max_media_labels_changed` = `idless.distinct_label_keys`; `max_media_primary_entity_changes` = `idless.rows`;
  - `max_slug_changes` = simulation labels_changed + title_labels_changed (posts), and `max_term_slug_changes` = the simulation's company labels changed (studio terms, §4.12);
  - `allow_shrink` = false.
- `assertions`: human-authored sentinel facts, listed in §4.6.5 V3. They name identities and verbatim strings, never counts.
- `accepted_exceptions`: the duplicate entity in `source_row` 4507 (MGM British and MGM share MGM's company ID).
- `reswap_of` (optional, normally absent): a pinned `bundle_id` that this manifest may re-swap although its own `bundle_id` equals it. Written only by `--reswap-of=<id>`, for the case in §4.6.9 where a rollback was caused by code outside the bundle.

**Identity and queueing.**
- `bundle_id` = sha256 of the sorted list of every `data/ledger/` file sha (manifest excluded, so `accepted-drift.json` is included), `corrected_sha256`, `derived_sha256`, `deriver_revision`, `display_name_policy`, `year_policy` and `fold_policy`. The mode and `reswap_of` are excluded.
- Queue key = `bundle_id:mode`.
- A `deriver_revision` that differs from `AAT_Ledger::DERIVER_REVISION` refuses with `deriver_revision_mismatch`. A deriver code change therefore forces a regenerated manifest, and the new `bundle_id` re-imports automatically.

**Row numbering.**
- Every row number in `data/ledger` is a `source_row`: the upstream file line − 1, or ≥ `source.rows + 1` for additions.
- `AUDIT-REPORT.md` uses spreadsheet rows, which are one higher: "row 527 Moulton" is `source_row` 526.

**Generation** (`tests/tools/build-ledger-bundle.php`, not deployed, CLI guard):
- Inputs: `data/oscars.csv`, `docs/database/{corrections.json, additions.json, needs-review.json, accepted-drift.json}`, `docs/database/tools/{adjudications.json, editor_decisions.json}` (step 0 only), `docs/database/schema.sql` (the SQL-dump column lists, below), `data/ledger/{entities.tsv, titles.tsv, name-overrides.tsv}`, and the recorded baseline (below). If `docs/database/accepted-drift.json` is absent, the builder creates it with empty lists in the shipped layout.
- **Step 0, redaction:** the builder applies `redact_evidence()` (§4.14 item 6) to the **evidence-bearing values only** (the per-file key list of §4.14 item 6) of those four JSON files and of `docs/database/tools/{adjudications.json, editor_decisions.json}`, never to `before`, `after`, `row` cells, `label`, drift keys or any other value, and rewrites any file that changed through `AAT_Ledger_Json::encode_shipped()` (the shipped layout above), so the diff touches only the redacted values. In `--check` mode it rewrites nothing and fails with `evidence_not_redacted` when any file would change.
- **`--baseline=legacy|<bundle_id>` is required when generating.** The agent reads it from production first: `/wp-json/lunara-ledger/v1/status` → `ingest.live.bundle_id` (`null` means `legacy`; before R1 is live, `legacy`). For `<bundle_id>`, the builder reads `docs/database/baselines/<bundle_id>.json.gz` (below) and refuses when it is absent.
- It runs source and deriver without a database, then writes the bundle, fills every hash, count and simulation value, and writes the review artifacts:
  - `docs/database/oscars-corrected.tsv`, regenerated so it always equals `corrected_sha256`;
  - **`docs/database/data.sql.gz`, regenerated from the deriver's model** (fourth critique: the public SQL drifted from the ledger) by `tests/tools/lib/sql-dump.php`, in the column lists that `docs/database/schema.sql` declares (as rewritten by U00: no Wikidata columns; a table or column the emitter cannot map fails with `sql_dump_schema_mismatch`). It holds the ledger's own values: display titles and display names (not Wikidata labels), the verbatim slots, **linked identities only** (a flagged needs-review identity, and an entity with no linked credit, are absent; `needs-review.json` lists them), one `ledger_corrections` row per correction entry with its redacted evidence and one per addition (field `'(row)'`, after = the addition's TSV line), and a `ledger_dataset` row whose `source_sha256` is `manifest.source.sha256`, `corrected_sha256` is `manifest.corrected_sha256`, counts are `manifest.expected`, and `loaded_at` is `NOW()`. The SQL text is deterministic; the file is `gzencode($sql, 9)`;
  - **`docs/database/integrity.sql`'s expected-count literals** (nominations, winners, ceremonies, categories, unofficial nominations, today at `:7-11`) are rewritten from `manifest.expected`; every other line is kept;
  - `docs/database/display-name-changes.tsv` (`imdb_id, legacy_label, new_label, name_source, reference_name`), published for the owner to read after the fact (decision 1);
  - `docs/database/slug-collisions.tsv`: label changes where the new label's slug equals another entity's old-label slug, e.g. `jean-hersholt`;
  - `docs/database/link-overrides.tsv` (`source_row, list, labels, slots`): every row that cannot be paired by position (§5);
  - `tests/fixtures/ledger/live-probe-cases.json`: the pages the live credit-link probe visits and, per page, the allowed IDs and allowed anchor texts (§5.5);
  - `tests/fixtures/ledger/decades.json`: `{decade: [ceremony…]}` from `film_year_start` (§6.3), shared by the API and hub tests;
  - `docs/database/baselines/<own bundle_id>.json.gz`: this bundle's **derivation summary**, so a later bundle can use it as its baseline without git history. It holds, per entity, (imdb_id, kind, display name); per nomination, (source_row, nomination_key, master-row sha1, film_entity_id, primary_entity_id); and the sorted linked (nomination_id, imdb_id) pairs. The builder deletes every other file in `docs/database/baselines/` except the recorded baseline's.
- `--check` regenerates everything in memory **with the baseline recorded in the committed manifest** (never `origin/main`, never git history) and exits non-zero if any byte differs from the committed files. A `*.gz` artifact is compared **after `gzdecode`** (zlib builds may compress differently between CI and local PHP; the decompressed bytes are what is pinned), and every `*_sha256` of a gzipped artifact hashes the decompressed bytes. CI runs it through `tests/ledger-bundle-contract.php`; it needs only the checked-out tree, so CI's shallow checkout is enough.
- An **independent** implementation, `tests/tools/corrected_tsv_reference.py` (about 70 lines of Python 3 standard library), parses the CSV, applies the corrections in file order with before-checks (a cell corrected twice included), decodes `\"`, appends the additions through the same typed-value table as `addition_cell()`, and prints the sha256. The bundle contract requires it to equal `corrected_sha256`. A replica of exactly these steps reproduced `3ecfa6fe…9d7f` on PR #39's files this session.
- `docs/database/oscars-corrected.xlsx` (fourth critique: stale workbook): the builder regenerates it with `python3 docs/database/tools/make_workbook.py docs/database/oscars-corrected.tsv docs/database/corrections.json docs/database/needs-review.json docs/database/oscars-corrected.xlsx docs/database/additions.json` whenever the overlay changes and `openpyxl` is importable, and otherwise fails generation with `workbook_tool_missing` if the workbook is stale. `--check` does not rebuild it (openpyxl output is not byte-stable); it reads, through `ZipArchive`, the `<dimension ref>` of the `Corrections` and `full_data` sheets (resolved through `xl/workbook.xml` and its rels) and fails with `workbook_stale` unless their data-row counts equal `manifest.expected.corrections` and `manifest.expected.nominations` (today `A1:I374` and `A1:N12139`). The privacy contract scans its XML parts (§4.14 item 8).

### 4.2 Codec rules (`AAT_Ledger_Source`, pure PHP; any violation refuses the import)

1. Every manifest hash equals `hash_file('sha256', …)`.
2. The header equals `source.header`. Every row has exactly 14 cells. No cell contains tab, CR or LF.
3. After the RFC parse, every backslash in an upstream cell is immediately followed by `"`. Addition cells contain no backslash.
4. Corrections are applied in file order, in the shipped format (§4.1). Each one first checks `(string) cells[source_row][field] === (string) before` against the cell's current value, i.e. after every earlier entry for the same cell, so a cell corrected twice is checked twice. Then every backslash-quote in every upstream cell is replaced by `"`, and no backslash may remain.
5. Additions are appended in file order after the upstream rows, with the implied `source_row` of §4.1 and each `row` value converted by `addition_cell()` (a type outside its table refuses with `addition_cell_type`, a malformed element with `addition_invalid`), and the other checks in §4.1. The TSV serialization (header first, upstream rows in `source_row` order, then additions; Winner `True` or empty; LF endings) hashes to `corrected_sha256`.
6. Shapes:
   - Ceremony `^\d{1,3}$`; Year `^\d{4}(/\d{2})?$`; Winner ∈ {`True`, ``};
   - FilmId tokens `^tt\d{7,10}$` or `?`;
   - NomineeIds slot tokens `^(nm|co)\d{7,10}$` or `?`, and a slot may be comma-joined.
7. Parity: a non-empty FilmId has as many pipes as Film, and a non-empty NomineeIds as many as Nominees. At most 255 slots.
8. Every referenced tt/nm/co has exactly one row in `titles.tsv`/`entities.tsv`, and every row there is referenced.
9. `mb_strtolower` must exist (display-name rules 4 and 6 use it; folding does not, §4.4.8).
10. Labels never become PHP array keys. Numeric titles such as `'10'`, `'1917'` and `'2010'` would turn into integers, so the code uses lists of tuples or `k:` prefixes.
11. **Length:** every derived value fits its column (§4.3) in characters, or the import refuses with `length_overflow`. This guards against wpdb's non-strict silent truncation.
12. Needs-review rules as in §4.1.
13. **Fold coverage:** every non-ASCII character in any corrected cell or reference name is a key of `AAT_Ledger_Text::FOLD_MAP` or a member of `AAT_Ledger_Text::FOLD_PASSTHROUGH`, or the import refuses with `fold_unmapped_char` (§4.4.8).
14. **Privacy:** no **evidence-bearing value** (the per-file key list of §4.14 item 6: `evidence` and `verification` of corrections and additions; `question`, `tried` and `resolution.evidence`/`resolution.verification` of needs-review items; `reason` and `evidence` of every accepted-drift entry) and no reference name matches `AAT_Ledger_Source::PRIVACY_PATTERNS` (the §4.14 item 6 patterns), or the import refuses with `privacy_violation`. `before`, `after`, the appended `row` cells, the needs-review `label` and the drift keys and values are dataset values and are never tested (fourth critique: source_row 11336's Note carries a life span, and a verified correction that quotes it must ship verbatim). The builder redacts before this can happen; the rule is defence in depth for a hand-edited deploy.
15. **Accepted drift:** every item has a non-empty `class`, `reason` and `evidence`, a `metric` from the §4.6.6 list and a 40-hex `prod_sha1`; every ID pin names an upstream `source_row` once and a positive `live_id` once; every `restored` entry names an upstream `source_row` that no pin names; every `retire` entry (§4.6.6, class `production_only`) names a positive `live_id` once, that no pin names, with a 40-hex `prod_sha1`. Otherwise `accepted_drift_invalid`.
16. **Correction shape:** every correction element has the keys and types of §4.1, or `correction_invalid`.

TSV reference files are parsed only with `explode("\t", rtrim($line, "\n"))` plus a column-count check. `fgetcsv` is never used on an unquoted TSV.

### 4.3 Tables (dbDelta; no FK, CHECK, ENUM or VIEW; one column or index per line)

**Naming.**
- One constant, `AAT_Ledger::TABLE_PREFIX = 'aat_ledger_'`, resolved only through `AAT_Ledger::table($short)` = `$wpdb->prefix . 'aat_ledger_' . $short`. The API store and the builder call it.
- The DDL lives in `includes/class-aat-ledger-tables.php` (`AAT_Ledger_Tables`).
- Tables are created by `AAT_Ledger_Tables::ensure()` (dbDelta over the 16 swapped ledger tables and the 3 persistent ones), and **only from the import job's prepare step 0** (cron, under the import lock) and from `wp aat ledger import` (third critique: 18 dbDelta passes on the first public request after a deploy). `maybe_upgrade_schema()` and `activate()` gain no ledger DDL, so the public path after a deploy stays at option reads. `ensure()` is idempotent and cheap on repeat (dbDelta issues no ALTER when nothing changed, which the MariaDB gate asserts).
- Nothing reads a ledger table before the first `ensure()`: every reader is behind `is_live()` or `master_is_ledger()`, which are false until a swap, and the first swap happens after prepare step 0.

**Swap set (25):**
- `aat_ledger_` + meta, ceremonies, classes, categories, titles, entities, aliases, nominations, nomination_titles, nomination_credits, credit_identities, corrections, group_stats, entity_stats, search, nomination_text;
- the legacy master;
- `aat_ceremonies`, `aat_categories`, `aat_entities`, `aat_award_facts`, `aat_award_nominees`, `aat_ceremony_stats`, `aat_category_stats`, `aat_entity_stats`.

**Persistent (never swapped):** `aat_ledger_datasets`, `aat_ledger_award_keys` (the id map; written only by F3 after a RENAME, §4.5), `aat_ledger_report_pages` (the last report, one row per section and page, §4.8). Every mode computes its id map in the stage table `aat_ledger_award_keys_lstg`, created `LIKE` the persistent table in prepare step 2 and dropped only after F3 has copied it (or with the rest of the stage when the job ends without a swap).

**Suffixes:**

| Suffix | Meaning |
|---|---|
| `_lstg` | stage |
| `_lprv` | previous dataset |
| `_ltmp` | temporary, during a 3-way RENAME |
| `_lbad` | failed post-swap verify |
| `_ldft` | drifted live tables displaced by a re-assert (§4.6.8) |
| `_l0` | the pre-ledger snapshot, kept after the first swap (§4.6.9) |

The job refuses if any name exceeds 64 characters; with `wp_` the longest is 37.

**DDL.** Every statement puts one column or index definition per line (dbDelta splits definitions on newlines; first critique). Every statement uses `PRIMARY KEY  (` with two spaces and `$charset_collate`, and names a `$ledger_*_table` variable. Multi-column keys use the existing `(a, b)` style (`main:303-306`). `tests/ledger-ddl-contract.php` asserts the one-per-line rule, and the MariaDB gate asserts a second dbDelta pass issues no ALTER.

```sql
CREATE TABLE $ledger_meta_table (
  meta_key varchar(64) NOT NULL,
  meta_value longtext NOT NULL,
  PRIMARY KEY  (meta_key)
) $charset_collate;
-- rows: bundle_id, dataset_version, stamp (written in prepare, §2), swap_seq, derived_sha256, projection_sha256,
--       builder_inputs_sha256, consumer_sha256, master_signature, deriver_revision, link_overrides (JSON, §4.4.6),
--       validation (JSON of V1–V13, including the V10 per-table crc_xor that a re-assert compares with, §4.6.8),
--       sentinels (JSON of the V3 results, §4.6.5)

CREATE TABLE $ledger_ceremonies_table (
  ceremony_no smallint(5) unsigned NOT NULL,
  year_label varchar(20) NOT NULL DEFAULT '',
  film_year_start smallint(5) unsigned NOT NULL DEFAULT 0,
  film_year_end smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (ceremony_no),
  UNIQUE KEY year_label (year_label)
) $charset_collate;

CREATE TABLE $ledger_classes_table (
  class_code varchar(16) NOT NULL,
  sort_order tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (class_code)
) $charset_collate;

CREATE TABLE $ledger_categories_table (
  category_id smallint(5) unsigned NOT NULL,
  canonical_name varchar(191) NOT NULL DEFAULT '',
  slug varchar(191) NOT NULL DEFAULT '',
  class_code varchar(16) NOT NULL DEFAULT '',
  PRIMARY KEY  (category_id),
  UNIQUE KEY canonical_name (canonical_name),
  UNIQUE KEY slug (slug),
  KEY class_code (class_code)
) $charset_collate;

CREATE TABLE $ledger_titles_table (
  imdb_id varchar(16) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  title_source varchar(16) NOT NULL DEFAULT '',
  reference_title varchar(255) DEFAULT NULL,
  first_ceremony smallint(5) unsigned NOT NULL DEFAULT 0,
  first_year_label varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (imdb_id),
  KEY title (title(100)),
  KEY first_ceremony (first_ceremony)
) $charset_collate;

CREATE TABLE $ledger_entities_table (
  imdb_id varchar(16) NOT NULL,
  kind varchar(10) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  name_source varchar(16) NOT NULL DEFAULT '',
  reference_name varchar(255) DEFAULT NULL,
  linked_credits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (imdb_id),
  KEY kind (kind),
  KEY name (name(100))
) $charset_collate;

CREATE TABLE $ledger_aliases_table (
  alias_id int(10) unsigned NOT NULL,
  imdb_id varchar(16) NOT NULL DEFAULT '',
  alias varchar(255) NOT NULL DEFAULT '',
  folded varchar(255) NOT NULL DEFAULT '',
  credits int(10) unsigned NOT NULL DEFAULT 0,
  shared_credit tinyint(1) NOT NULL DEFAULT 0,
  first_ceremony smallint(5) unsigned NOT NULL DEFAULT 0,
  last_ceremony smallint(5) unsigned NOT NULL DEFAULT 0,
  is_display tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (alias_id),
  KEY imdb_id (imdb_id),
  KEY folded (folded(100))
) $charset_collate;

CREATE TABLE $ledger_nominations_table (
  nomination_id int(10) unsigned NOT NULL,
  source_row int(10) unsigned NOT NULL,
  nomination_key char(40) NOT NULL DEFAULT '',
  is_added tinyint(1) NOT NULL DEFAULT 0,
  ceremony_no smallint(5) unsigned NOT NULL,
  category_id smallint(5) unsigned NOT NULL,
  category_as_given varchar(255) NOT NULL DEFAULT '',
  is_winner tinyint(1) NOT NULL DEFAULT 0,
  is_official tinyint(1) NOT NULL DEFAULT 1,
  credit_line varchar(600) DEFAULT NULL,
  detail varchar(600) DEFAULT NULL,
  note text,
  citation text,
  PRIMARY KEY  (nomination_id),
  UNIQUE KEY source_row (source_row),
  UNIQUE KEY nomination_key (nomination_key),
  KEY ceremony_category_winner (ceremony_no, category_id, is_winner),
  KEY category_ceremony (category_id, ceremony_no),
  KEY winner_ceremony (is_winner, ceremony_no)
) $charset_collate;

CREATE TABLE $ledger_nomination_titles_table (
  nomination_id int(10) unsigned NOT NULL,
  ordinal tinyint(3) unsigned NOT NULL,
  imdb_id varchar(16) DEFAULT NULL,
  title_as_credited varchar(255) NOT NULL DEFAULT '',
  detail varchar(300) DEFAULT NULL,
  PRIMARY KEY  (nomination_id, ordinal),
  KEY imdb_nomination (imdb_id, nomination_id)
) $charset_collate;

CREATE TABLE $ledger_nomination_credits_table (
  nomination_id int(10) unsigned NOT NULL,
  ordinal tinyint(3) unsigned NOT NULL,
  name_as_credited varchar(255) NOT NULL DEFAULT '',
  id_slot varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (nomination_id, ordinal)
) $charset_collate;

CREATE TABLE $ledger_credit_identities_table (
  nomination_id int(10) unsigned NOT NULL,
  ordinal tinyint(3) unsigned NOT NULL,
  imdb_id varchar(16) NOT NULL DEFAULT '',
  position tinyint(3) unsigned NOT NULL DEFAULT 1,
  review_flag varchar(16) NOT NULL DEFAULT '',
  PRIMARY KEY  (nomination_id, ordinal, imdb_id),
  KEY entity_nomination (imdb_id, nomination_id),
  KEY review_flag (review_flag)
) $charset_collate;

CREATE TABLE $ledger_corrections_table (
  correction_id int(10) unsigned NOT NULL,
  nomination_id int(10) unsigned DEFAULT NULL,
  source_row int(10) unsigned DEFAULT NULL,
  scope varchar(8) NOT NULL DEFAULT 'cell',
  field varchar(40) NOT NULL DEFAULT '',
  before_value text,
  after_value text,
  reason varchar(60) NOT NULL DEFAULT '',
  evidence text,
  verification varchar(255) NOT NULL DEFAULT '',
  imdb_id varchar(16) DEFAULT NULL,
  PRIMARY KEY  (correction_id),
  KEY nomination_id (nomination_id)
) $charset_collate;

CREATE TABLE $ledger_group_stats_table (
  ceremony_no smallint(5) unsigned NOT NULL,
  category_id smallint(5) unsigned NOT NULL,
  nominations smallint(5) unsigned NOT NULL DEFAULT 0,
  winners smallint(5) unsigned NOT NULL DEFAULT 0,
  official smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (ceremony_no, category_id),
  KEY category_ceremony (category_id, ceremony_no)
) $charset_collate;

CREATE TABLE $ledger_entity_stats_table (
  imdb_id varchar(16) NOT NULL,
  kind varchar(10) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  nominations smallint(5) unsigned NOT NULL DEFAULT 0,
  wins smallint(5) unsigned NOT NULL DEFAULT 0,
  official_nominations smallint(5) unsigned NOT NULL DEFAULT 0,
  first_ceremony smallint(5) unsigned NOT NULL DEFAULT 0,
  last_ceremony smallint(5) unsigned NOT NULL DEFAULT 0,
  categories smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (imdb_id),
  KEY kind_wins (kind, wins, nominations),
  KEY kind_nominations (kind, nominations, wins)
) $charset_collate;

CREATE TABLE $ledger_search_table (
  search_id int(10) unsigned NOT NULL,
  imdb_id varchar(16) NOT NULL DEFAULT '',
  kind varchar(10) NOT NULL DEFAULT '',
  label varchar(255) NOT NULL DEFAULT '',
  folded varchar(255) NOT NULL DEFAULT '',
  is_canonical tinyint(1) NOT NULL DEFAULT 0,
  nominations smallint(5) unsigned NOT NULL DEFAULT 0,
  wins smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (search_id),
  KEY imdb_id (imdb_id),
  KEY folded (folded(64), nominations)
) $charset_collate;

CREATE TABLE $ledger_nomination_text_table (
  nomination_id int(10) unsigned NOT NULL,
  haystack text NOT NULL,
  PRIMARY KEY  (nomination_id)
) $charset_collate;

CREATE TABLE $ledger_datasets_table (
  dataset_row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  dataset_version varchar(40) NOT NULL DEFAULT '',
  bundle_id char(64) NOT NULL DEFAULT '',
  stamp char(12) NOT NULL DEFAULT '',
  swap_seq int(10) unsigned NOT NULL DEFAULT 0,
  mode varchar(10) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  source_sha256 char(64) NOT NULL DEFAULT '',
  corrections_sha256 char(64) NOT NULL DEFAULT '',
  additions_sha256 char(64) NOT NULL DEFAULT '',
  corrected_sha256 char(64) NOT NULL DEFAULT '',
  derived_sha256 char(64) NOT NULL DEFAULT '',
  nomination_count int(10) unsigned NOT NULL DEFAULT 0,
  winner_count int(10) unsigned NOT NULL DEFAULT 0,
  plugin_version varchar(20) NOT NULL DEFAULT '',
  loaded_at datetime DEFAULT NULL,
  swapped_at datetime DEFAULT NULL,
  report longtext,
  PRIMARY KEY  (dataset_row_id),
  KEY bundle_id (bundle_id),
  KEY stamp (stamp),
  KEY status (status)
) $charset_collate;

CREATE TABLE $ledger_award_keys_table (
  nomination_key char(40) NOT NULL,
  nomination_id int(10) unsigned NOT NULL,
  first_dataset_version varchar(40) NOT NULL DEFAULT '',
  last_dataset_version varchar(40) NOT NULL DEFAULT '',
  retired_in varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (nomination_key),
  UNIQUE KEY nomination_id (nomination_id)
) $charset_collate;

CREATE TABLE $ledger_report_pages_table (
  section varchar(64) NOT NULL,
  page smallint(5) unsigned NOT NULL,
  job_id varchar(40) NOT NULL DEFAULT '',
  items smallint(5) unsigned NOT NULL DEFAULT 0,
  body longtext NOT NULL,
  PRIMARY KEY  (section, page)
) $charset_collate;
-- one row per /status/report section and page of the latest report (fourth critique: a request decoded the
-- whole stored report); written by the diff phase, read by one primary-key SELECT per request (§4.8)
```

Stage tables are created with `` CREATE TABLE `$stage_table` LIKE `$live_table` ``, so `tests/schema-dbdelta-contract.php`'s variable rule holds. The legacy master and the eight projections keep their DDL unchanged, so `tests/sql-performance-contract.php:39-54` stays green.

**Checksum column lists** (third critique: generated columns). `AAT_Ledger_Tables::checksum_columns($short)` declares, per swapped table, the ordered column list that V10 and the daily drift hash cover. It **excludes every database-generated column**: `updated_at` on `aat_ceremonies`, `aat_categories`, `aat_entities` and the three stats tables, `created_at` on `aat_award_facts`, `aat_award_nominees` and the master, and the `AUTO_INCREMENT id` of facts and nominees (§1). The master's `id` is the **one declared exception**: its DDL is `mediumint(9) NOT NULL AUTO_INCREMENT` (`main:805`), but the job sets it explicitly (`id = nomination_id`) and it is the join key of every projection, so it is checksummed (fourth critique: §4.3 and the DDL contract disagreed). The ledger tables have no generated column. `tests/ledger-ddl-contract.php` asserts that no declared list names a column whose DDL has `DEFAULT CURRENT_TIMESTAMP` or `AUTO_INCREMENT`, **except the master's `id`, the only entry of `AAT_Ledger_Tables::CHECKSUM_EXCEPTIONS`**, and that every other column of each table is listed.

**Deliberate departures from `docs/database/schema.sql`** (as rewritten by U00, §4.14):
- No foreign keys: InnoDB FKs follow renamed tables, and dbDelta does not manage them.
- No CHECK or ENUM (dbDelta cannot diff them) and no views.
- IDs are `varchar(16)`. `category_id` is explicit.
- Added: `source_row`, `nomination_key`, `is_added`, `id_slot`, `position`, `review_flag`, `reference_*`, `name_source`/`title_source`, `linked_credits`, `first_ceremony`/`first_year_label`, `scope`, the aliases table and the four read tables.
- **No** `wikidata_qid`, `birth_year` or release-year columns anywhere (decision 2); U00 removes them from `schema.sql` too.

### 4.4 Derivation (`AAT_Ledger_Deriver`, the only definition)

The deriver has two layers:
- `model()` builds the ledger model: ceremonies, classes, categories, titles, entities, aliases, nominations, slots, identities, corrections and link overrides. It reads the corrected rows (§4.2), the reference files and the id map (§4.5).
- `emit($table, $from, $limit)` yields the planned rows of one stage table in deterministic order. `checksum($table)` streams the whole table and returns `{count, crc_xor}`.

Every emitted row passes the §4.2 length rule. Nothing holds all 25 tables in memory at once (§4.6.4).

#### 4.4.1 Ledger rows
Same rules as `docs/database/tools/build.py`:
- **Ceremonies:** the first Year per Ceremony. `film_year_end` = `a[0][:2] . a[1]` for split labels, else the start year.
- **Categories:** the Class of the first row seen; exactly one class each, or refuse. `category_id` is ordered by (class sort_order, canonical_name).
- **Nominations:**
  - `is_winner` = (Winner is `True`);
  - `is_official` = 0 when Note matches `/NOT AN OFFICIAL NOMINATION/i`;
  - `is_added` = 1 for additions;
  - credit_line, detail, note and citation are verbatim, with NULL for empty values. The double `NOTE: NOTE:` prefix on 12067 and 12068 stays verbatim (decision 7).
- **Slots:**
  - one title slot per Film token, with `imdb_id` NULL for `?`, empty or missing. The title `detail` is set only when the class is Acting or Music and count(Detail) equals count(Film);
  - one credit slot per Nominees token, `?` included;
  - one identity per comma-joined ID in the slot, with `review_flag = 'needs_review'` for unresolved needs-review (source_row, ID) pairs (§4.4.4);
  - one corrections row per correction entry (`scope = 'cell'`; a cell corrected twice gives two rows, in file order), plus one per addition (`scope = 'row'`, `field = '*'`, before NULL, after = the addition's TSV line).
- **Titles:** `first_ceremony` = the lowest ceremony in which the tt appears in any title slot; `first_year_label` = that ceremony's `year_label`, verbatim.
- **Entities:** `linked_credits` = the number of identities with an empty `review_flag`.
- **`derived_sha256`** hashes these rows, with nominations identified by `source_row`, so it does not depend on the database. It includes the folded columns, because `fold()` is self-contained (§4.4.8). The only WordPress-dependent values, `sanitize_title` slugs and the legacy `sort_label` (`normalize_entity_name_key`, which uses `remove_accents` in production), are excluded and validated in production by V8 and V10.

#### 4.4.2 Display names (`credit_mode/1`, decision 1)
1. An override wins. `name-overrides.tsv` is empty at launch.
2. Votes are labels from slots whose **linked** identity list is exactly `[id]`. "Roderick Jaynes" is not a vote for either Coen, and a flagged slot casts no vote.
3. With no votes, use the Academy's first shared label, i.e. the credited text of the entity's first shared slot in `source_row` order (`shared_credit`; third critique). The reference name is used only when the entity has no Academy credit text at all (`reference`), which the codec's parity rule makes impossible for nm/co IDs today; the branch exists for completeness. Reference names are Wikidata labels for people (§4.1), so they never outrank the Academy's own credit.
4. Candidates are the votes that contain a lowercase letter, or all votes if none do.
5. Rank by count desc, then label == reference desc, then last ceremony desc, then byte order asc. A tie broken by the reference label records `reference_tie`.
6. An ALL-CAPS pick equal to the reference name after `mb_strtolower` becomes the reference name (`reference_case`). Otherwise the result is `credit_mode`, or `allcaps_credit` when the pick has no lowercase letter.
7. Titles follow the same rule over title slots (`title_source`).
8. Aliases: every distinct credited form per ID (exact bytes), with credits, shared_credit, first and last ceremony, `is_display` and `folded` (§4.4.8).
9. `name_source` ∈ `override | credit_mode | allcaps_credit | reference_tie | reference_case | shared_credit | reference`. Rules 5 and 6 are the only other places the reference name is consulted.

Pinned results (identity sentinels; not counts):

| ID | Name |
|---|---|
| nm0001053 | Ethan Coen |
| nm0001054 | Joel Coen |
| nm0380965 | Jean Hersholt |
| nm0604960 | Ralph Morgan |
| nm0413164 | Fred Jackman |
| nm0914249 | A. W. Watkins |
| nm0095104 | Bono (`reference_tie`) |
| nm0875308 | Kazu Hiro (`reference_tie`) |
| nm0569222 | Barney "Chick" McGill |
| nm6855916 | SZA |
| nm0001801 | Robert Towne |
| nm0916990 | Paul Francis Webster |
| tt2175842 | Maggie Simpson in "The Longest Daycare" |

The full change list is `docs/database/display-name-changes.tsv`. Its row count is `manifest.simulation.labels_changed`. It is published for the owner to read after the fact, with no approval gate (decision 1). Credit-mode names are why positional IDs must never be re-routed by label while the master is ledger-derived (§5.2 rule 6): nm0916990 is displayed "Paul Francis Webster" but credited "Paul Webster" on row 1814.

#### 4.4.3 Year labels (`eligibility_label/1`, decision 2)
- A film's year is `first_year_label`, verbatim. The ledger stores, and every surface shows, `1932/33` as `1932/33`.
- Split labels are never flattened.
- No Wikidata year is read, stored or emitted.
- External lookups (TMDb/OMDb) receive `external_lookup_year(label)`: the 4-digit label for single-year labels, '' for split labels (§4.10).

#### 4.4.4 Needs-review identities (decision 5)
- For every unresolved (source_row, ID) pair, the identity row carries `review_flag = 'needs_review'`.
- Flagged identities cast no display-name votes, count in no stats, do not appear in `aat_ledger_search` and are excluded from API ID filters.
- The **master bridge input masks them.** The NomineeIds token for a flagged ID is replaced by `?` before `build_import_db_row`, so the master and every projection omit the pair. Because masking turns a `?` slot into a count mismatch, the row joins the link-override set (§5), where the flagged slot renders as plain text.
- An entity whose only credits are flagged gets `linked_credits = 0`. It is left out of `aat_entities`, `aat_ledger_entity_stats` and search, so `/oscars/name|company/{id}/` returns a genuine 404 and the API entity route returns 404.
- The API carries the flag on the nomination record (§6.4). No surface ever shows a flagged identity as a link. Before the swap and after a rollback to the pre-ledger data, the legacy link guard enforces the same thing on the unmasked master (§5.6).

#### 4.4.5 Master mirror
- Each corrected row (after masking) goes through `ledger_bridge_import_row($cells_by_header)`, which calls `build_import_db_row` (`main:3690`) unchanged. It is stored with `id = nomination_id`.
- **V5** (`normalizer_divergence`) refuses when the normalized `film_id` tt set differs from the row's title-slot IDs, or when the normalized `nominee_ids` set is not contained in the row's linked identities plus the title-primary fill-in tt (`main:3670-3682`). A future hotfix that changes an ID must therefore move into `corrections.json`.
- **Normalizer idempotence:** `normalize_awards_row` applied to the stored row must return identical `nominees`, `nominee_ids`, `film` and `film_id`, or the import refuses (`normalizer_not_idempotent`). Readers re-normalize on read (e.g. `main:7735`, `:3234`), and §5's keys depend on stable values.

#### 4.4.6 Link overrides and positional safety (§5)
For every nomination, the deriver compares the stored master lists with the ledger slots:
- credits: `L = explode('|', nominees)`, trimmed and non-empty, against `I = explode('|', nominee_ids)`;
- titles: `film` against `film_id`.

The rules:
- **V11 (`positional_pairing_unsafe`):** if `count(L) === count(I) > 0`, then for every i, `I[i]` must be the single linked identity of credit slot `i+1`, and the credit-slot count must equal `count(L)`. For title-primary fill-in rows, the title slots are compared instead. Any violation refuses the import and lists the rows. The measured count today is 0 (§1).
- **Membership of the link-override set** (third critique: ID-less rows) is exactly:
  - `count(I) > 0` and `count(L) !== count(I)` (misaligned rows, e.g. 965, 276, 8165, 9026, 938, and after PR #39's corrections 2112 and 4746, whose corrected IDs are `?|co0103139` and `?|co0076018`); or
  - masking removed at least one ID from the row (§4.4.4) and the row has at least one ledger slot on that list (e.g. 5671, the one open needs-review item, which masking leaves with no ID), whatever the resulting counts.
  - A row with `count(I) === 0` and no masking (527 Nominees-only SciTech/Special/Title rows; the 345 International Feature rows whose country `normalize_awards_row()` copies from Name into `nominees`, `main:3654-3656`) is **not** a member. The resolver renders its `L` as plain text in every state (§5.2 step 4), so "West Germany" on source_row 3470 keeps printing on the ballot.
- A member's entry maps `link_key` to the ordered slot list `[[as_credited, [[imdb_id, display_name] …linked only]] …]`. The key is `md5(list . "\x1f" . (int) ceremony . "\x1f" . strtoupper(trim(canonical_category)) . "\x1f" . trim(values) . "\x1f" . trim(ids))`, with `list` ∈ {`credits`, `titles`}.
- Two nominations with the same key and different slot lists produce an entry marked `ambiguous`, which renders as plain text. `manifest.expected.link_overrides.ambiguous` counts them.
- The whole set is stored as JSON in the swapped `aat_ledger_meta` row `link_overrides`, so it goes live atomically with the swap.

#### 4.4.7 Projections (same DDL as `main:227-377`)

| Table | Rule |
|---|---|
| `aat_ceremonies` | Verbatim `year_label`; `ceremony_label` = `ordinal()`; `sort_year` = `film_year_start` |
| `aat_categories` | `slug` = `sanitize_title(canonical)`; `display_category` = `format_category_display(canonical)`; `award_class` |
| `aat_entities` | Every tt in any title slot with its display title, plus every nm/co identity with `linked_credits > 0` with its display name; `sort_label` = `normalize_entity_name_key(label)` |
| `aat_award_facts` | `source_award_id` = nomination_id; `film_entity_id` = the first non-null title slot; `primary_entity_id` = the first element of the normalized master `nominee_ids`; `primary_label` = master name; the legacy `nominee_count` formula |
| `aat_award_nominees` | One row per distinct ID in the normalized master `nominee_ids`, in legacy order. `nominee_ordinal` = the ID's first ledger slot (0 for the fill-in tt). `entity_label` = the slot's credited name. No media-library links. Row 4507 gives one MGM row. Flagged pairs are absent (masked) |
| stats tables | As today, except `aat_entity_stats` counts DISTINCT nominations over title slots ∪ nominee rows (this removes the legacy double counts and adds titles that appear only in second or later positions, e.g. tt0019553). The Control Desk drift check therefore needs its `ledger` variant (§4.9 item 8) |

#### 4.4.8 API read tables and folding
- `group_stats`: GROUP BY ceremony, category.
- `entity_stats`: titles via DISTINCT (nomination, tt) over title slots; people and companies via DISTINCT (nomination, id) over **linked** identities. Row 4507 needs the DISTINCT.
- `search`: one row per (imdb_id, distinct folded label) over the display name (`is_canonical` 1), every credited alias from linked slots and the reference name when it differs (a Wikidata label for people, §4.1; it only widens search, e.g. a legal name next to a stage name, and is never returned as the entity's `name`). When several forms fold to the same value, the row's `label` is the display name if it is one of them, else the most-credited form, ties broken by byte order. Entities with `linked_credits = 0` are excluded. `search_id` is deterministic, sorted by (imdb_id, folded). Example: nm0005838 has one search row, folded `georges perinal`, label = its display name.
- `nomination_text`: a folded haystack of credit_line, detail, category_as_given, credited names, display names of linked identities, credited titles, display titles and citation.
- **Folding is `AAT_Ledger_Text::fold()`** (`fold_policy` `lunara-fold/1`), in `class-aat-ledger.php` and the only fold in the ledger code. It is **self-contained and locale-independent**; it never calls `remove_accents`, `mb_strtolower`, `iconv`, `Normalizer` or `setlocale`, so CI (PHP 8.2), the local builder (8.4) and production give byte-identical output:
  1. `strtr($s, self::FOLD_MAP)`: a vendored map, derived from WordPress's `remove_accents()` table (GPLv2+, attributed in the docblock), covering every letter in U+00C0–U+017F (upper and lower case to lower-case ASCII, e.g. `É`→`e`, `ß`→`ss`, `Æ`/`æ`→`ae`, `Ø`/`ø`→`o`, `ð`→`d`), plus U+2018/U+2019/U+201C/U+201D → `'`/`"` and U+2013/U+2014 → `-`;
  2. `strtolower()` on the result (ASCII only, locale-independent in PHP ≥ 8.2);
  3. whitespace runs collapsed to one space, trimmed.
  - Any other non-ASCII character passes through unchanged. `FOLD_PASSTHROUGH` lists the ones the dataset uses that are deliberately kept (today only `®`, U+00AE). Codec rule 13 refuses a dataset character that is in neither list, so a new character forces a reviewed map change rather than silently different counts.
- Because the fold is self-contained, `manifest.expected.read.search` is exact in CI and in production. `tests/ledger-display-name-runtime.php` asserts one search row each for nm0005838, nm0306223, nm0380057 and nm3234869 and that their accented and unaccented aliases share it.

#### 4.4.9 Hashes that gate consumers
- `projection_sha256`: a hash over the planned master mirror and the eight projection tables.
- `builder_inputs_sha256`: a hash over what the graph builder reads. That is, for every tt: (tt, display title, `first_year_label`); for every nm/co with `linked_credits > 0`: (id, kind, display name); and for every nomination: (nomination_id, ceremony, year_label, dated category label, winner, film_entity_id, primary_entity_id, primary_label).
- `consumer_sha256` = sha256(`projection_sha256` | `builder_inputs_sha256`). It gates `aat_after_data_import` (§4.6.7 F5).

#### 4.4.10 Simulation lists and the legacy link guard (generated by the builder)
- The builder runs the local simulation (`tests/tools/ledger-dry-run.php`) against the recorded baseline (§4.6.6) and writes `data/ledger/simulation.json`: `{baseline, lists {labels_changed: [[imdb_id, old_label, new_label]…], title_labels_changed: [[tt, old, new]…], entities_added: [id…], entities_removed: [id…], master_rows_changed: [source_row…], film_entity_changes: [source_row…], primary_entity_changes: [source_row…]}, counts {…the manifest summary…}}`. Every list is sorted.
- The builder writes `data/ledger/legacy-link-guard.json` (§5.6) with three sorted, distinct lists, all computed from the **legacy parse** (escape `\\`, then `build_import_db_row`) against the ledger:
  - **`pairs`** `[imdb_id, fold(label)]`: every (upstream row, list, position i) where the legacy row has equal counts on that list (credits or titles) and its pair (`L[i]`, `I[i]`) is not the ledger's slot identity for that row. That is the before-side of every ID correction (wrong_id and retired_id alike; a missing_id correction has no before-ID), every unresolved needs-review pair, and anything else a V11-style comparison finds.
  - **`label_pairs`** `[imdb_id, fold(label)]` (third critique): every (entity, label) that the **local legacy simulation's** `aat_entities` holds (first-label-wins, `main:1134-1157`, over the index pairing of `main:1358-1360`) where `fold(label)` is not the fold of any credited alias of that ID in the ledger. Example: nm0429444 with "Radio Corporation of America" (from row 3258), nm0914249 with "Denham" (row 938).
  - **`never_link`** `[imdb_id]` (third critique): every nm/co ID of the legacy parse's normalized `nominee_ids` whose ledger `linked_credits` is 0 (or that the ledger lacks), and every tt of its FilmId that no ledger title slot carries: wrong_id and retired_id before-IDs that the overlay removes everywhere (e.g. co0058013, co0080422, and the four tt of the FilmId corrections, e.g. tt0169446) and IDs whose only credits are flagged (nm0239470). 33 IDs with PR #39's overlay (§1); the builder generates the list and the manifest carries its count, so no test pins it.
  - `collateral` counts other legacy aligned pairs with the same (ID, folded label) key whose link is correct; they render as plain text too while the guard is active (fail-safe: a lost link, never a wrong one).
- The builder writes `tests/fixtures/ledger/live-probe-cases.json` (§5.5) from the same data.

### 4.5 Nomination identity
- `nomination_key` = sha1 of the 14 decoded cells joined by `0x1f`. For upstream rows the cells are taken before the cell overlay; for additions they are the addition's converted cells (`addition_cell()`, §4.1). Keys are distinct: the count equals `manifest.expected.nominations`.
- The mapping lives in `aat_ledger_award_keys` (persistent), and **only F3 writes it**, after a RENAME (below).
- **Matching** (every forward import; fourth critique). The passes run for every bundle row whose `nomination_key` the persistent table does not hold, against the live rows whose id no persistent key claims. On the first import that is every row on both sides (award_keys empty, live master non-empty); after a blocked or failed swap it is again every row, because nothing was persisted. Passes run in order over the rows still unmatched on both sides, and each accepts only 1:1 matches:
  - pass 0, **pins**: every `accepted-drift.json` `id_pins` entry `{source_row, live_id, prod_sha1}` maps that row to that live id, provided the live row exists and `sha1` of its 14 trimmed master fields (the fingerprint fields of `main:3243-3252`, joined by `0x1f`) equals `prod_sha1`; otherwise the job fails with `id_pin_stale`, so a pin never survives a later production edit unseen;
  - pass 1: the legacy parse of each row (escape `\\`, then `build_import_db_row`) against live rows by `get_awards_row_fingerprint()` (`main:3243-3252`). Additions are matched too, in case an admin had already added one by hand;
  - pass 2: the legacy key without `nominees` and `nominee_ids`;
  - pass 3, **tolerant** (third critique: documented production drift in winner flags and display names, §1): the builder's `backfill_key(ceremony, canonical_category, film_id . ' ' . nominee_ids, detail)` formula (`builder:922-929`), reimplemented as `AAT_Ledger_Importer::tolerant_key()` because the builder's method is private and pinned byte-identical (`tests/winner-backfill-identity-contract.php:15-20`). A runtime test asserts the two agree on 200 sample rows through reflection. Every pass-3 match is recorded with the names of the master fields that differ (e.g. `winner`, `name`);
  - remaining rows: `max(live max id, persistent keys max) + 1`, then onwards, in `source_row` order.
- **Where allocations are written** (fourth critique: a blocked swap left permanent allocations that no later pin could move). In **every** mode (dry run and swap alike), prepare step 1 writes the complete id map (persisted keys reused, pass results, new allocations) to the stage table `aat_ledger_award_keys_lstg` with `INSERT IGNORE`, durable and idempotent across ticks. Stage `nomination_id` values are read from it. **F3** (§4.6.7), after the RENAME, copies it into `aat_ledger_award_keys` with one idempotent `INSERT … SELECT … ON DUPLICATE KEY UPDATE last_dataset_version = VALUES(last_dataset_version)` and sets `retired_in` on persisted keys whose id no bundle row carries; only then is the `_lstg` table dropped. A swap that blocks, fails or is killed before the RENAME therefore leaves the persistent table exactly as it was, so a pin or restored entry added by the remediation loop applies on the next run. A job that dies between the RENAME and F3 resumes at F3 (status `swapped` is persisted), and the idle `_lstg` sweep (§4.6.1 item 5) never drops a stage table of a job in status `swapped`. A re-assert (§4.6.8) and a restore allocate nothing.
- Live ids with no bundle row are `ids_retired`. Bounds (§4.1): `ids_retired ≤ max_ids_retired`, and every retired live id must be named by an `accepted-drift.json` `retire` entry whose `prod_sha1` still equals the live row's (§4.6.6), otherwise `blocked` with `ids_retired_unexplained`; `ids_new ≤ additions.count + restored.count`, where `ids_new` counts only rows that matched no live row. Every upstream row not listed in `accepted-drift.json` `restored` must map to a live id; any other unmatched upstream row gives `ids_new_upstream > 0` and a `blocked` status.
- **The mapping report** (`/status/report?section=id_map`, §4.8) lists, within its bound: every unmatched upstream `source_row` with its best live candidate (the live row of the same ceremony and canonical category with the most equal fields) and the names of the fields that differ; every unmatched live id with its ceremony and category; and every pass-3 match with its differing field names. That is what the remediation loop acts on (§4.6.6).
- The API exposes `id` = nomination_id, `source_line` = source_row + 1 for upstream rows and `null` for additions, and `added`. Fixtures and tests key on `source_row`.

### 4.6 Import job (`AAT_Ledger_Importer` + `AAT_Ledger`)

#### 4.6.1 Triggers (nothing runs inline on a public request)
- **Version check** (`AAT_Ledger::maybe_check_bundle()`), from `init` at priority 20 and from every heartbeat run. The autoloaded option `aat_ledger_bundle_checked` holds `{version, queue_key, outcome, pin}`. The check runs when **any** of these holds, each decided from autoloaded options and the kill-switch flag alone (no file read):
  - `version !== AAT_VERSION`;
  - `outcome === 'deferred_frozen'` and the kill switch is no longer on;
  - `outcome === 'refused_pinned'` and the current rollback pin differs from the recorded `pin`.

  When it runs, it:
  - reads `manifest.json` (a few KB) and computes the queue key;
  - copies `legacy-link-guard.json` (a few hundred short keys, verified against `manifest.expected.legacy_guard.sha256`) into the option `aat_ledger_legacy_link_guard` (§5.6), **autoloaded only while the state is `legacy` or `rolled_back`**; in `live` and `frozen`, where the guard is off, it is written with autoload off (fourth critique: small costs on public paths);
  - records `aat_ledger_code_seen_at = time()` (used for `Last-Modified`, §6.5);
  - calls `request_import('upgrade')` unless the queue key equals the last processed one (outcome `current`);
  - then writes the option with the outcome `request_import()` returned. **An outcome of `deferred_frozen` or `refused_pinned` records the refusal, not a completed check**, so the check re-runs as soon as the refusing condition changes (third critique: the fix-forward that never ran).
- **`request_import($trigger)`** returns an outcome:
  - `deferred_frozen` in the `frozen` state, and in `rolled_back` while the kill switch is on (the switch holds everything; §4.6.10);
  - `refused_pinned` when the bundle's `bundle_id` equals `aat_ledger_rollback_pin.bundle_id` and the manifest's `reswap_of` is not that id (§4.6.9);
  - `already_queued` when the same queue key is queued or running;
  - otherwise `queued`: it writes the job state and schedules `aat_ledger_tick` unless one is already scheduled.
  - **It is accepted in `legacy`, `live` and `rolled_back`.** In `rolled_back` the writers and the builder stay refused (§4.6.10); only the import of a *different* bundle is allowed, which is exactly the fix-forward path.
- **Hourly heartbeat** `aat_ledger_heartbeat`, scheduled from an `init` callback, not at load. Each run:
  1. records `cron.heartbeat_runs[]`, the last 48 timestamps, and increments today's count in `cron.daily[7]` (§4.6.11);
  2. restarts jobs stalled for more than 15 minutes when the lock is free;
  3. repeats the F4 exact singleton deletes once after each swap (F6);
  4. once every 24 hours, in `live` only, re-hashes the bundle and compares live counts and the per-table checksums over the declared column lists (§4.3; generated columns excluded) with meta, plus the `link_overrides` hash, and re-runs the live sentinels (§4.6.5). On drift it records the drifted tables and, per drifted table, the drifted-row count, and queues a **re-assert**: `request_reassert()`, the dedicated job mode of §4.6.8, never a re-import through the swap gate (decision 3: every 24 hours, without exception; fourth critique: the swap gate's V13 made a re-assert of the first bundle impossible). Drift found by the first daily check after a re-assert, i.e. drift that reappeared within 26 hours of the re-assert, sets the flag `drift_recurring` in `/status` and in the report to the owner; the re-assert still runs every 24 hours while drift persists. An untouched live dataset reports no drift (the MariaDB gate asserts two consecutive heartbeat days with no drift);
  5. drops `_lstg` tables older than 24 hours when idle (no job in a non-terminal status; a job in status `swapped` keeps its `aat_ledger_award_keys_lstg` until F3 has copied it);
  6. rolls the log-only limiter counters into `abuse` (hourly figures and 7 daily buckets, §6.6);
  7. once every 24 hours, fires `do_action('aat_ledger_daily')`. R6 hooks the redirect-health probe to it (§7.9).
- **WP-CLI** `wp aat ledger status|import [--now]|rollback|export|drop-l0`, registered through `add_action('cli_init', …)`.
- **Load-time code.** `AAT_Ledger::init()` and every other load-time `init()` contain only `add_action` and `add_filter`. The heavy classes (source, deriver, importer, tables, slug planner) load only through `AAT_Ledger::load_pipeline()`, which runs in cron, CLI, the admin run-now handler and the census branch. Public requests never load them. The only ledger files loaded on every request are `class-aat-ledger.php`, `class-aat-ledger-slug-redirects.php` and the API (from R4 also the explorer) files.

#### 4.6.2 Lock
- `SELECT GET_LOCK('aat_ledger_import', 0)` must return 1. It is released in a `finally`, and automatically when the connection closes.
- Fallback: an `INSERT IGNORE` lease row `aat_ledger_lease` in options, taken over after `2 × budget + 60 s`.
- The legacy cron rebuild path (legacy mode only, §4.9 item 2) takes the same lock.

#### 4.6.3 State
`aat_ledger_job` (not autoloaded) holds:
- job_id, queue_key, bundle_id, mode (`dry_run | swap | rollback` from the manifest, or `reassert` from the heartbeat, §4.6.8), trigger, status, phase, plugin_version, attempt, ticks, started_at, last_tick_at;
- `prepare {ids_allocated, stage_created, checksummed: [table…]}`;
- `tables {name: {planned, crc_xor, done, validated}}`, the id map summary, last_error `{code, phase, at}` and the report.

#### 4.6.4 Tick and memory
1. Budget = `clamp(apply_filters('aat_ledger_tick_budget_seconds', 20), 5, 25)`.
2. `wp_raise_memory_limit('cron')`, then take the lock.
3. On a version or queue-key change, abort and drop the stage.
4. Verify files, load the source (measured 0.21 s / 22 MB by `proto/mem.php`) and build `model()`. The model holds only ledger-level rows, never the 25 planned tables.
5. Run the phase. **Every phase touches at most one table's derivation per tick:**
   - **prepare**, resumable in steps recorded in `aat_ledger_job.prepare`:
     - step 0: `AAT_Ledger_Tables::ensure()` (dbDelta for the 18 ledger tables; §4.3);
     - step 1: if live meta `bundle_id` matches and live is consistent → status `current` (a `reassert` job skips this test: it runs precisely because live is not consistent); if `_lprv` meta matches and passes V1/V4 → `swap` in the restore direction; otherwise drop and recreate `aat_ledger_award_keys_lstg` `LIKE` the persistent table and write the id map into it (§4.5, every mode; a `reassert` job copies the persistent rows for the bundle's keys and refuses with `reassert_id_missing` when a key is absent);
     - step 2: drop the 25 swap-set `_lstg` tables (never `aat_ledger_award_keys_lstg`, which step 1 owns), then `CREATE TABLE \`$stage_table\` LIKE \`$live_table\`` for all 25 (DDL only, no derivation), then compute the stamp for the planned swap (`swap_seq` = live + 1, §2) and record it in the job state; the stage meta emitter writes it as the `stamp` row;
     - step 3, one table per tick in swap-set order: `checksum($table)` → store `planned` and `crc_xor`, append the table to `prepare.checksummed`. `checksum()` streams, so memory is O(one table's accumulators). A tick that finishes a table with budget left starts the next table only if the previous table took less than half the budget.
   - **load:** for the first table whose `done < planned`: `done = COUNT(*)` of the stage table; more than planned fails with `stage_overflow`. Insert `emit($table, done, …)` in prepared batches (≤500 rows and ≤900 KB each, each statement atomic) until the budget is spent.
     - The master mirror is emitted row by row through the bridge, so it is never held whole.
     - Projections are computed per table by streaming mirror rows into that table's accumulators only.
   - **validate:** V1–V13, one table per step where a check is per table, resumable across ticks (`tables[*].validated`).
   - **diff:** report, automatic bounds, provenance classification and simulation match (§4.6.6). It reads live tables through SQL and the bundle's `simulation.json`; it derives no table.
   - **swap**, then **finalize**.
6. Save state at every step change. If not finished, reschedule `aat_ledger_tick` at `time() + 5` (the builder's pattern, `builder:644-649`).

**Memory gates.** Because no tick derives more than one table, peak memory is bounded by the model plus one table's accumulators. Two gates enforce this:
- **CI:** `tests/ledger-memory-runtime.php` runs `checksum($table)` and a full `emit()` for each of the 25 tables, each in a child `php -d memory_limit=256M` process with the plugin loaded under the reporting-integrity stubs. Each child must peak at or below **192 MiB** (75 % of WordPress's default `WP_MAX_MEMORY_LIMIT` of 256M) and exit 0.
- **Local, under a real WordPress bootstrap:** `tests/tools/ledger-memory-gate.php` needs a pinned WordPress 6.8.x core at `$WP_CORE_DIR`, the local MariaDB and `memory_limit = WP_MAX_MEMORY_LIMIT = 256M`. It runs every tick of a full dry run and a full swap, each in its own PHP process. It asserts every tick peaks ≤ 192 MiB and takes ≤ 25 s, that no prepare tick checksums more than one table whose own time exceeds half the budget, and writes `docs/design/ledger-2.8/memory-gate.json` with the per-tick numbers.
- If either gate fails, the fix is to split the offending table's emitter further, never to raise the ceiling.

#### 4.6.5 Validation (against `_lstg`)
- **V1:** exact row counts for all 25 tables, equal to `tables[*].planned` and to `manifest.expected`.
- **V2:** the `docs/database/integrity.sql` checks, with every expected number taken from `manifest.expected`:
  - every ceremony × category group has a winner;
  - every Acting nomination has exactly 1 credit;
  - no company identity on an Acting nomination;
  - no unreferenced entity or title;
  - duplicates only as accepted (4507);
  - unofficial, winners, ceremonies, categories and citation-only rows equal the manifest.
- **V3:** the manifest's sentinel assertions:
  - Hersholt nm0380965 and Morgan nm0604960 names; nm0916990 "Paul Francis Webster";
  - Ethan Coen; McGill; tt2175842 quotes;
  - slot identities for rows 8165, 965, 526, 1814 (slot 2 → nm0916990) and 2564 (slot 1 → nm0772834);
  - title entity tt0019553;
  - ceremony 1 label `1927/28` and ceremony 6 label `1932/33`;
  - row 12067's note begins with `NOTE: NOTE:` (decision 7);
  - row 10475 is citation-only with its citation verbatim;
  - every addition present with `is_added = 1`.
  - These checks are one function, `AAT_Ledger_Importer::sentinels($tables)`, returning `[{id, ok}]` with stable ids (e.g. `name:nm0380965`, `slots:965`, `slots:8165`, `slots:1814:2`, `label:ceremony:6`). V3 runs it on the stage tables; F1 runs it on the live tables right after the swap, and the heartbeat re-runs it daily. The live results are stored in the meta row `sentinels` and published as `/status` `ingest.live.sentinels` (§4.8). They are the data-layer truth the R2 failure classes rest on (§12 R2).
- **V4:** `hash_equals` of the stage master's census against the PHP census of the corrected rows.
- **V5:** normalizer divergence and idempotence (checked while deriving, §4.4.5).
- **V6:** the Control Desk projection drift SQL run against stage names, through `get_projection_drift_counts($tables, 'ledger')` (§4.9 item 8). It covers count drift, FIND_IN_SET drift, facts missing or stale, stats against the **ledger** expected set, and orphans. All must be 0.
- **V7:** `SHOW COLUMNS` and `SHOW INDEX` are identical for each (live, stage) pair.
- **V8:** for every category, `sanitize_title(canonical)` equals the pure slug.
- **V9:** `derived_sha256` equals the manifest.
- **V10:** per-table content checksum over the table's **declared column list** (`AAT_Ledger_Tables::checksum_columns()`, §4.3; database-generated timestamps and auto-increment ids excluded). `BIT_XOR(CRC32(CONCAT_WS(0x1f, cols…)))` in SQL must equal the stored PHP `crc_xor` over the same columns. This catches silent truncation, charset or collation mangling and quote damage, and cannot fail on a `DEFAULT CURRENT_TIMESTAMP` value.
- **V11:** positional safety holds (§4.4.6). It is recorded in derivation, and re-checked against the stage master rows in SQL order.
- **V12:** the stage `link_overrides` meta row decodes, has `manifest.expected.link_overrides` entries, and each entry's slot count equals its row's credit or title slot count.
- **V13 (`simulation_baseline_mismatch`):** the manifest's recorded baseline is the live dataset: `baseline.kind = legacy` requires live meta without a `bundle_id` (state `legacy`, or `rolled_back` to the pre-ledger data); `baseline.kind = bundle` requires live meta `bundle_id = baseline.bundle_id`. A mismatch is a gating failure (terminal until the bundle changes). It blocks swap mode, and a dry run reports `simulation_match: false` with this code. **A `reassert` job does not run V13**, nor the simulation match, the change bounds or the slug preview: it restores the bundle that is already live and accepted, whose manifest necessarily records the baseline of its *own* first swap (`legacy` for B1), so V13 could never hold for it (fourth critique). It is bounded by its own scope check instead (§4.6.8).

#### 4.6.6 Diff, provenance, automatic bounds and simulation match (decision 4)
**Provenance (critic: media-recovered links).** Production's live projections contain links the local simulation cannot model: the legacy rebuild recovers nominee IDs for ID-less rows from the production media library (`main:1196-1227`, `:1350-1356`). The diff therefore classifies what it compares, in SQL over the live tables:
- an **unsourced link** is a live `aat_award_nominees` row whose master row (`awards.id = source_award_id`) has `TRIM(COALESCE(nominee_ids, '')) = ''`;
- a **media-touched entity** has at least one unsourced link;
- a **media-dependent fact** is a live facts row whose master row has empty `nominee_ids` and a non-empty `primary_entity_id`.

**The diff compares stage with live:**
- master rows changed, added and removed (by `source_row` through the id map);
- entities added and removed;
- labels changed (list, plus up to 500 samples in the public report);
- sourced nominee links added and removed (unsourced links excluded from both sides);
- unsourced links removed, each as (nomination_id, entity_id, label);
- `film_entity_id` and `primary_entity_id` changes;
- the id map (preserved, new, new_upstream, retired);
- the **slug preview**: the §4.12 planner run read-only over current `wp_posts` and `lunara_studio` terms, giving renames, swap_cases, cycles, suffixed, kept_editorial, term renames and `conflict_risk`;
- the **stage sentinels** (V3 results).

**Then three checks:**
- **Bounds:** `blocked` (code `blocked`) on any `change_bounds` breach, `ids_new_upstream > 0`, a retired live id without a matching `retire` entry (`ids_retired_unexplained`, §4.5), or `stage.nominations < live` without `allow_shrink`. The media metrics are bounded here: unsourced links removed ≤ `max_unsourced_links_removed`; media-touched entities among labels changed, entities added and entities removed ≤ `max_media_entities`; media-touched label changes ≤ `max_media_labels_changed`; media-dependent primary changes ≤ `max_media_primary_entity_changes`. These bounds come from `manifest.idless` (§4.1), true upper bounds on what media recovery can have produced from the legacy data.
- **Simulation match, per metric**, with `tol(n) = max(simulation_tolerance.abs, ceil(simulation_tolerance.rel × n))`:
  - **entity-keyed metrics** (labels_changed, entities_added, entities_removed): remove media-touched entities from both production's list P and the simulation's list S (from `simulation.json`) to get P′ and S′. `mismatch = |P′ − S′|`, plus, for labels, the entries in both whose old label differs. Match when `mismatch ≤ tol(|S′|)`. The items of `S′ − P′` are reported, not counted (below: `already_applied`). The removed media-touched items are reported as `media_explained` per metric;
  - **row-keyed metrics** with lists (master_rows_changed, film_entity_changes, title_labels_changed): `mismatch = |P − S|`, match when `≤ tol(|S|)`; `S − P` is reported as `already_applied`;
  - primary_entity_changes: media-dependent facts removed from P, then as row-keyed;
  - count-only metrics (sourced_links_added, sourced_links_removed, ids_*): `|prod − sim| ≤ tol(sim)`.
  - **Simulation-only items are explained by construction** (third critique: remediation). A key in `S − P` is one the simulation changes but production does not, i.e. stage already equals live for it: production already holds the ledger's value (an earlier production edit, or a lost flag the ledger does not touch). The swap will not change it, so it cannot surprise anyone; it is reported per metric as `already_applied` and does not count as mismatch. Only production-only items (`P − S`), old-label disagreements and count-metric gaps can block. A production edit that the agent turns into an overlay correction therefore stops counting on the next dry run without any accepted-drift entry.
  - **Accepted drift** (third critique): before the mismatch is computed, each `accepted-drift.json` item `{metric, key, side, prod_sha1}` whose `prod_sha1` still equals production's current value for that key (the master-row sha1 of §4.5 pass 0 for row keys; sha1 of the live `aat_entities` row's `entity_id|entity_type|label` for entity keys) removes that key from the side it names. Items whose `prod_sha1` no longer matches are ignored and listed as `accepted_drift_stale`, so an accepted item can never hide a later production edit. The removed items are reported per metric as `drift_explained`.
  - V13 must hold. The result is `last_result.simulation_match` plus a per-metric table with `sim`, `prod`, `mismatch`, `tol`, `media_explained` and `drift_explained`.
- **Mode:**
  - `dry_run` → `validated_dry_run`, drop the stage, keep the report;
  - `reassert` → never reaches this step: after validation it goes to its scope check and swap (§4.6.8);
  - `swap` → proceeds only if `simulation_match` is true and nothing is blocked; otherwise status `blocked`, code `simulation_mismatch` (or `simulation_baseline_mismatch`). The same gate therefore holds even if R2 were merged early or carried a regenerated bundle.

**The difference lists** (third critique: the agent could not see which rows differ). For every metric whose `mismatch > 0`, the report stores the actual items of `P′ − S′` (production only) and `S′ − P′` (simulation only), each as `{key, prod, ledger, legacy, differing_fields}`:
- `key` is the `source_row` for row metrics and the `imdb_id` for entity metrics;
- `prod`, `ledger` and `legacy` are the production live value, the stage (ledger) value and the value the local legacy simulation has, for the fields the metric is about (the 14 master fields for master rows; `label` for label changes; `entity_id` for film and primary changes; the entity row for entity adds and removes);
- `differing_fields` names the master fields in which `prod` and `legacy` differ.
- Bound: at most 500 items per metric and side are stored; beyond that the list is truncated with `truncated: true` and the true totals. They are served by `/status/report?section=metric:<name>&page=<n>` (§4.8). All values are public dataset fields; nothing else is included.

**Remediation the agent runs alone** (third critique; no owner step; unit U29). When a dry run ends with `simulation_match: false`, or `blocked` because of `ids_new_upstream`, the agent:
1. reads `/status/report?section=summary`, then every `metric:<name>` section with `mismatch > 0` and the `id_map` section;
2. classifies every item by comparing `prod` (P), `legacy` (C, the CSV's legacy parse) and `ledger` (L):
   - `already_applied`: P = L. Production already holds the ledger's value. The diff already explains these (simulation-only items), so they need no entry;
   - `production_lost_flag`: only `winner` differs, and C = L = winner while P is not (the documented lost flags, `builder:826-836`). Accept;
   - `production_mojibake`: P's differing text contains U+FFFD or a `Ã`-style double-encoding and L is the clean form. Accept;
   - `production_edit`: P ≠ C and P ≠ L, i.e. an edit made in production through a legacy correction lane. The agent checks P against the Academy's own record for that row (the same evidence standard as `corrections.json`). If P is right, it adds an **overlay correction** (before = C, after = P, with `reason`, `evidence` and `verification`), after which L = P and the item disappears. If P is wrong or cannot be verified, it records the item as `superseded` with the evidence that supports L;
   - `id_map` rows: an unmatched upstream row whose best candidate differs only in fields that a `production_edit` explains is **pinned** (`id_pins {source_row, live_id, prod_sha1, reason, evidence}`); an unmatched row with no plausible candidate is a row production lost (e.g. in the 2026-09-19 partial upload, `main:16463-16464`); the agent lists it in `restored {source_row, reason, evidence}`. A restored row gets a new id, is excluded from `ids_new_upstream`, and raises the generated `max_ids_new` by exactly one.
   - `production_only` (fourth critique): an **unmatched live id**, i.e. a live master row that no bundle row maps to (a duplicate from a delta import, a hand-added row). The agent checks it against the Academy's own record. If it is a real Academy record that the dataset lacks, it becomes an addition in `docs/database/additions.json` with evidence and verification; pass 1 then matches it by fingerprint and preserves its id. Otherwise the agent adds a `retire {live_id, prod_sha1, class: "production_only", reason, evidence}` entry, which raises the generated `max_ids_retired` by exactly one; the id retires at the swap and the builder retires its `ledger_entry` post within that bound (§4.11 f). An unmatched live id with neither is `ids_retired_unexplained` and keeps the swap blocked.
3. appends every accepted, superseded, pinned, restored and retired item to `docs/database/accepted-drift.json` with its `class`, `reason`, `evidence` and the observed `prod_sha1` (and every new addition to `docs/database/additions.json`);
4. regenerates the bundle with `--baseline=legacy` (or the recorded bundle baseline), runs every gate, and ships it as the next patch in mode `dry_run` (R1b, §12);
5. repeats from step 1 on the new dry run. **At most three dry runs in total**; if the third still does not match, the agent reports the remaining items to the owner and stops the train. A report, not an approval: the train resumes when a later bundle matches.
- The accepted-drift file is authored data, not generated: the manifest records only its hash and counts. Every class and the three-run limit are asserted by `tests/ledger-simulation-match-runtime.php` on fixture lists.

**The local simulation** (`tests/tools/ledger-dry-run.php`, called by the bundle builder) models "live" from the **recorded baseline** (`manifest.simulation.baseline`, set by `--baseline`, §4.1):
- **`legacy`:** the legacy derivation of the legacy parse of `data/oscars.csv`, with no media recovery. That is what production's master was last imported from, so production edits made through the legacy correction lanes show up as a sourced mismatch. That is the intended stop signal. The baseline stays `legacy` for every bundle generated while `/status` reports no live `bundle_id`, including bundles regenerated after R1 because new overlay entries landed.
- **`bundle`:** once `/status` reports a swapped bundle, its `bundle_id`, the merge commit that shipped it, and its committed derivation summary `docs/database/baselines/<bundle_id>.json.gz`. The simulation diffs the new derivation against that summary. No git history and no `origin/main` is read, so CI's shallow checkout reproduces it.

#### 4.6.7 Swap and finalize
**Swap.**
1. `SET SESSION lock_wait_timeout = 5`.
2. **Keep the pre-ledger snapshot** (`_l0`). Whenever the dataset being displaced is pre-ledger data (live meta has no `bundle_id`: the first swap, and a fix-forward from `rolled_back`) and `_l0` tables do not exist, the RENAME targets for the 9 legacy tables use `_l0` instead of `_lprv`; `_lprv` still receives the 16 ledger tables. A later rollback of that bundle restores the 9 from `_l0` (§4.6.9). Revision 3 applied this only to the first swap, so a fix-forward from `rolled_back` would have pushed the only pre-ledger copy into `_lprv`.
3. Drop the 25 `_lprv` tables (never `_l0`).
3a. For a restore, write the precomputed stamp and `swap_seq` into the restore source's meta (`x_lprv` meta, or the `_lprv` meta table that accompanies an `_l0` restore) before the RENAME, so the restored tables carry their stamp when they go live.
4. One `RENAME TABLE` statement with all renames. The restore direction uses `x → x_ltmp`, `x_lprv → x`, `x_ltmp → x_lprv`.
5. Reset `lock_wait_timeout`.
- A lock-wait timeout is `swap_retry`, retried up to 5 times. Any other error fails with `swap_failed`, and live is untouched.
- Status is persisted as `swapped` immediately after the swap.

**Finalize (idempotent).**
- **F1:** live counts equal the plan, meta `bundle_id` matches and meta `stamp` equals the precomputed stamp. Then `sentinels(live)` runs (§4.6.5) and its results are written to the live meta row `sentinels`. A count, id or stamp mismatch swaps back through `_lbad` and fails with `post_swap_verify`; a failed sentinel on a forward swap does the same (it passed on the stage, so a live failure means the RENAME itself went wrong).
- **F2:** write the autoloaded `aat_ledger_live` = {dataset_version, bundle_id, **stamp (the value already in live meta)**, swap_seq, swapped_at, derived_sha256, corrected_sha256, projection_sha256, consumer_sha256, master_signature, counts, sentinels}.
  - **Guard autoload:** when the new state is `live`, `wp_set_option_autoload('aat_ledger_legacy_link_guard', false)`; when a restore makes it `rolled_back`, `…, true)` (WordPress ≥ 6.4, `function_exists`-guarded; §1). The guard then costs nothing on public requests while it is off.
  - Set `aat_ledger_ever_live` = 1 (never deleted by code).
  - **Pin:** a forward swap of a bundle whose `bundle_id` differs from `aat_ledger_rollback_pin.bundle_id` deletes the pin; a `mode: rollback` restore sets it (§4.6.9).
  - Call `AAT_Ledger::refresh()`.
- **F3:** update the dataset rows (live and previous); then copy `aat_ledger_award_keys_lstg` into `aat_ledger_award_keys` with the idempotent upsert of §4.5 (new keys inserted, `last_dataset_version` refreshed, `retired_in` set on persisted keys whose id no bundle row carries), and only then drop `aat_ledger_award_keys_lstg`. A restore and a re-assert copy nothing. Re-running F3 changes nothing (the MariaDB gate asserts it).
- **F4:** `ledger_bridge_after_swap_invalidate()`:
  - deletes the exact singleton keys `aat_records_total_v1/v2`, `aat_total_stats_v2`, `aat_awards_meta_v1`, `aat_max_ceremony_v1`, `aat_latest_year_label_v1`, `aat_hub_page_stats_v2`, `aat_category_first_ceremony_v1`, `aat_reviewed_award_post_ids_v1` and `aat_category_slug_map_v1`;
  - then calls `clear_oscars_read_api_caches()` (`main:15979`).
- **F5 (hooks):**
  - `do_action('aat_ledger_swapped', $stamp, $consumer_changed, $restored_kind)` always, with `$restored_kind` ∈ `forward | ledger | pre_ledger | reassert`. The theme listens to it from 3.2.91 (§8.1); its listener treats every value alike.
  - `do_action('aat_after_data_import', 'ledger', <manifest.expected.nominations>)` fires **only when `consumer_sha256` changed** since the previous live dataset, and **never** when the swap restored the pre-ledger data (`pre_ledger`). A release that changes only builder inputs still resyncs; no-op swaps never trigger the builder's destructive `start_run` (`builder:587-598`).
  - A rollback to a previous **ledger** bundle (`ledger`) fires `'ledger_rollback'` when `consumer_sha256` differs: its names are ledger names, so the builder's retitle and slug stage stay consistent.
  - A rollback to the **pre-ledger** data does not resync the graph (critic: rollback retitles). `/talent/` and `/film/` keep the last ledger titles and migrated slugs until the fix-forward swap; the builder refuses to start in the `rolled_back` state (§4.11 j). The owner report says so.
  - `hook_pending` is tracked, so the hook fires at least once.
- **F6 (repeated singleton delete):** schedule `aat_ledger_post_swap_sweep` at `time() + 300`, repeating the F4 exact deletes. The next heartbeat repeats them once more. A request that read pre-swap tables before the RENAME and wrote its transient after F4 is therefore overwritten within 5 minutes, not 12 hours. `last_result.sweeps` records both runs.

#### 4.6.8 Failures, heartbeat re-assert
- **Retryable** (backoff 15 min, then 1 h, then 6 h; at most 3 per queue key): `db_error`, `lock_timeout`, `swap_retry` after 5 attempts, `no_progress`.
- **Terminal until the bundle changes:**
  - bundle and codec: `bundle_hash_mismatch`, `codec_violation`, `correction_invalid`, `overlay_before_mismatch`, `addition_invalid`, `addition_cell_type`, `addition_year_mismatch`, `addition_duplicates_upstream`, `correction_targets_addition`, `needs_review_stale`, `review_resolution_unapplied`, `corrected_sha_mismatch`, `fold_unmapped_char`, `privacy_violation`, `accepted_drift_invalid`;
  - derivation: `derived_sha_mismatch`, `deriver_revision_mismatch`, `length_overflow`, `normalizer_divergence`, `normalizer_not_idempotent`, `positional_pairing_unsafe`;
  - validation: `count_mismatch`, `integrity_failed`, `sentinel_failed`, `census_mismatch`, `schema_mismatch`, `slug_mismatch`, `checksum_mismatch`, `link_overrides_invalid`, `stage_overflow`;
  - gating: `blocked` (including `ids_new_upstream` and `ids_retired_unexplained`), `simulation_mismatch`, `simulation_baseline_mismatch`, `id_pin_stale`;
  - re-assert (terminal for that day's queue key only; the next daily check queues a new one): `reassert_id_missing`, `reassert_derivation_changed`. `reassert_not_needed` is an outcome, not a failure.
- **Re-assert (drift, same bundle; its own job mode).** Fourth critique: revision 4 re-derived and swapped the same bundle through the swap gate, where V13 fails for the first bundle (its manifest's baseline is `legacy` while live meta holds B1), so drift was never repaired and `drift_recurring` stayed set.
  - **Trigger.** The daily heartbeat check (§4.6.1 item 4), in state `live` only, when the deployed manifest's `bundle_id` equals live meta's (otherwise the version check's normal import owns the change and the heartbeat records `reassert_skipped_bundle_changed`). `request_reassert()` queues mode `reassert` with queue key `{bundle_id}:reassert:{UTC date}`, so it runs at most once a day and never collides with a manifest import.
  - **Prepare and load** as for a swap, except that step 1 takes every id from the persistent `aat_ledger_award_keys` (`reassert_id_missing` otherwise) and allocates nothing.
  - **Validate:** V1–V12 and the stage sentinels (V3), with V9 also requiring `derived_sha256` = live meta's. **Skipped:** V13, the simulation match, the change bounds and the slug preview (the dataset was accepted when it first swapped).
  - **Scope check, instead of the bounds.** (a) Every stage table's `crc_xor` must equal the V10 value recorded in live meta `validation` at the accepted swap; otherwise the re-derivation is not the accepted dataset (e.g. deriver code changed without a `DERIVER_REVISION` bump) and the job stops with `reassert_derivation_changed`, reported, with nothing swapped. (b) The tables whose live checksum differs from the stage are recomputed now; none → `reassert_not_needed`, stage dropped. (c) For each differing table the job counts the drifted rows in SQL (live primary keys missing from, extra to, or differing from the stage over the declared checksum columns) and stores `reassert {at, drifted_tables, drifted_rows: {table: n}, samples ≤ 50 keys per table}` in the report. The swap then touches exactly the drift: every table it replaces is either identical to live or one of the drifted tables.
  - **Swap:** live → `_ldft` (dropping any older `_ldft`) and stage → live. **`_lprv` is not touched**, so a rollback still returns to the previous dataset rather than the drifted state.
  - **Finalize:** F1 (live sentinels), F2 (a new stamp, so caches built on drifted data rotate), no F3 copy, F4–F6 with `restored_kind = reassert`. `aat_after_data_import` fires only when a drifted table is a graph-builder input (the master, `aat_award_facts`, `aat_award_nominees`, `aat_entities`, `aat_ceremonies`, `aat_categories`), so a builder run that read drifted data is redone.
  - Re-asserts run only in `live`; they pause in `frozen` and `rolled_back`. `tests/ledger-importer-contract.php` pins that the `reassert` branch never calls the V13 or simulation-match functions and always calls the scope check; `tests/ledger-freeze-runtime.php` drives a re-assert of a bundle whose manifest baseline is `legacy` against a live meta holding that bundle and asserts it swaps.

#### 4.6.9 Rollback without shell or admin, and the automatic fix-forward
**Rollback.**
- `mode: rollback` in a new release restores the previous dataset through the 3-way RENAME, but only when the live `bundle_id` equals the manifest's and the restore source passes V1/V4.
  - When the previous dataset is the pre-ledger data, the 9 legacy tables come from `_l0` and the 16 ledger tables from `_lprv`. For the 9, the renames are `x → x_ltmp`, `x_l0 → x`, `x_ltmp → x_lprv`. The pre-ledger state goes live, and the displaced ledger tables are kept as `_lprv`, so a later restore can re-apply them.
  - When the previous dataset is an earlier ledger bundle, all 25 come from `_lprv`.
  - The stamp and `swap_seq` were written into the restore source's meta before the RENAME (§4.6.7 step 3a). F2 writes the option from the restored meta.
  - It sets the **pin** `aat_ledger_rollback_pin` (autoloaded) = `{bundle_id: <the rolled-back bundle>, at, plugin_version}`.
- `wp aat ledger rollback` does the same from the CLI.
- After a rollback to the pre-ledger state, `AAT_Ledger::state()` is `rolled_back` (§4.6.10): the legacy writers stay refused, the legacy rebuild stays deferred, the builder refuses to start, the legacy link guard applies again (§5.6), and F5 does not resync the graph.

**What the pin blocks, and what it does not** (third critique: revision 3's pin and freeze blocked the fix-forward forever).
- The pin blocks exactly one thing: importing the bundle whose `bundle_id` it holds. `request_import()` returns `refused_pinned` for it (§4.6.1), in every state.
- A release whose bundle has a different `bundle_id` imports normally, in `rolled_back` as in `live`. A fix-forward always has a different `bundle_id`, because it changes the overlay, the accepted-drift file, a reference file or the deriver (a deriver change bumps `DERIVER_REVISION`, which is part of `bundle_id`, §4.1).
- The pin clears when a bundle with a different `bundle_id` swaps forward (F2). Nothing else clears it.
- **Same bundle, different cause.** If a rollback was caused by code outside the bundle (e.g. an importer bug) and the data is unchanged, the fix-forward release is generated with `--reswap-of=<pinned id>`, which writes `manifest.reswap_of`; `request_import()` accepts the pinned bundle once for that manifest. `reswap_of` is excluded from `bundle_id`, so nothing else about the bundle changes.
- **The automatic fix-forward, step by step:** the fix-forward release deploys (merge = deploy) → the version check sees a new `AAT_VERSION`, reads the manifest, computes a queue key that differs from the last processed one, and `request_import('upgrade')` returns `queued`, because the state is `rolled_back` (accepted) and the `bundle_id` is not the pinned one → the ticks run in cron → V13 accepts `baseline.kind = legacy` against a `rolled_back` live meta with no `bundle_id` → the simulation match runs against the recorded baseline → the swap displaces the pre-ledger tables into `_l0` again (§4.6.7 step 2) → F2 clears the pin → state `live`. No CLI or admin action is involved anywhere. The MariaDB gate runs exactly this sequence (§11, step (i)).

**What a rollback republishes** (stated in every rollback report to the owner): the pre-ledger `/oscars/` names, which are the known-wrong first-label-wins labels (e.g. nm0604960 labelled "Jean Hersholt"), with misaligned and guarded pairs as plain text rather than wrong links. `/talent/` and `/film/` keep the ledger names and slugs until the fix-forward swap. Because a rollback now happens only on a data-layer failure (§12 R2), the fix-forward always carries a data fix.
- The report always records when `_lprv` was last overwritten and by which bundle. `_l0` is dropped only by `wp aat ledger drop-l0`.
- **Never undo R2 or later by reverting plugin code below 2.8.0.** The 2.7.92 code would run `rebuild_reporting_tables()` on the version change (`main:882`) over the corrected master. That re-pairs by index and re-labels first-label-wins, which brings the defects back, and it re-enables the legacy writers. The undo is always a manifest rollback or a fix-forward.

#### 4.6.10 Kill switch: freeze, not revert
The switch is the `AAT_LEDGER_DISABLED` constant or the `aat_ledger_enabled` filter returning false.

`AAT_Ledger::state()` is resolved in this order:
1. `get_option` is not defined (the reporting-integrity and verifier stubs) → `legacy`. Every predicate returns its `legacy` value and `stamp()` returns '' without calling any WordPress function (critic: stub fatal).
2. no `aat_ledger_ever_live` → `legacy`, enabled or disabled;
3. `aat_ledger_live` has an empty `bundle_id` (pre-ledger restored) → `rolled_back`, enabled or disabled;
4. disabled → `frozen`;
5. otherwise → `live`.

The truth table (third critique: import permission is separate from the freeze):

| State | `is_live()` | `is_frozen()` | `master_is_ledger()` | `refuses_legacy_writes()` (writers refused, legacy rebuild deferred) | legacy link guard | `request_import()` | builder `start_run()` | heartbeat re-assert |
|---|---|---|---|---|---|---|---|---|
| `legacy` | false | false | false | false | on | accepted | allowed | n/a |
| `live` | true | false | true | true | off | accepted, except the pinned bundle | allowed | every 24 h on drift (mode `reassert`: no V13, scope-checked, §4.6.8) |
| `frozen` | false | true | true | true | off | `deferred_frozen` (re-checked when unfrozen) | allowed (reads the frozen ledger master) | paused |
| `rolled_back` | false | false | false | true | on | accepted, except the pinned bundle; `deferred_frozen` while the switch is on | refused (`ledger_rolled_back`) | paused |

- `legacy_write_refusal()` and the first-statement guard of `rebuild_reporting_tables()` both test `refuses_legacy_writes()`, which covers every rebuild call site in §1, including the builder heartbeat's `resync_from_master()`.
- `queue_reporting_rebuild($trigger)` calls `request_import($trigger)` in `live` and `rolled_back`, records the trigger and does nothing else in `frozen`, and follows §4.9 item 2 in `legacy`.

**Frozen behaviour:**
- The live master and projections stay exactly as last swapped. Imports are deferred, not discarded: lifting the switch re-runs the version check (§4.6.1).
- Only the public read enhancements fall back:
  - the API and the explorer return 503 `ledger_unavailable` with `ingest.state = frozen`;
  - `get_entity_rows` drops its ledger UNION;
  - positional renderers show mismatched rows as **plain text** (§5.4), never index-paired;
  - the title context uses its non-ledger branch.
- `canonicalize_name_entity_id_for_label()` stays off, because the master is still ledger-derived.
- The run-now button refuses in `frozen` (§4.6.11).
- `tests/ledger-freeze-runtime.php` drives the whole table (U04).

In `legacy` state (before R2), disabling changes nothing but the switch flag. The legacy rebuild then runs from cron (`aat_legacy_projection_rebuild`, taking the ledger lock), never inline.

#### 4.6.11 WP-Cron evidence and the stop-and-ask path
- `/status` publishes `cron {heartbeat_last_run, heartbeat_runs_24h, heartbeat_max_gap_seconds, tick_last_run, ticks_total, job_age_seconds, daily: [{date, heartbeat_runs}…7]}`. It contains timestamps and counts only.
- **R1 acceptance:** within 3 hours of the R1 deploy,
  - `heartbeat_runs_24h ≥ 2` with `heartbeat_max_gap_seconds ≤ 4200` (70 minutes);
  - and the dry run reaches `validated_dry_run`, with `tick_last_run` advancing between two `/status` reads at least 10 minutes apart while it runs.
- **Stop-and-ask path** (if R1 does not show that evidence within 3 hours):
  1. The train stops before R2.
  2. The agent sends Dalton the `/status` JSON and one question: which of these may unblock cron?
     - (a) confirm on WordPress.com that WP-Cron runs for lunarafilm.com (how Atomic runs cron was not verified);
     - (b) run `wp aat ledger import --now` over SSH;
     - (c) open Control Desk → System Status → **Ledger dataset** and press **Run ledger job now**, which runs one tick per click (and keeps clicking itself until done, while the tab is open).
  3. The agent resumes only on his answer. That button is admin-only (`manage_options` + nonce `aat_ledger_run`), calls the same tick function as cron, and refuses in the frozen state.

### 4.7 Stamp and cache contract
- `AAT_Ledger::stamp()` returns '' in `legacy` and the `aat_ledger_live` stamp in every other state. It reads the option once per request and is guarded by `function_exists('get_option')`.
- `AAT_Ledger::live_meta_stamp()` returns the `stamp` row of the live `aat_ledger_meta` table (one primary-key SELECT, static per request), or '' when the state is not `live` or the table does not exist. The stamp is written into the stage meta at prepare, so this value always describes the tables the same request reads (§2). The API token and the explorer's `data-ledger-token` use it; plugin transients use `stamp()`. `tests/ledger-stamp-contract.php` asserts that the importer writes `stamp` into the stage meta emitter before the RENAME and that F2 writes the identical value into the option.
- `Academy_Awards_Table::get_dataset_stamp()` is public (from R1) and returns `AAT_Ledger::stamp()`, or '' when the class is absent.
- `public function dataset_cache_key($key)` is public, because the category-names harness extracts public methods only. It has a docblock and sits after `ajax_clear_data()`. It returns `$key` unchanged when `AAT_Ledger` is absent or the stamp is ''; otherwise `$key . '__' . $stamp`.
- It is applied on the line **after** each key literal, so pinned literals survive: `$cache_key = 'aat_ceremony_rollup_v3_' . $ceremony;` followed by `$cache_key = $this->dataset_cache_key($cache_key);`.
- **Stamped (16 per-key families):**
  - `aat_entity_rows_v2_`, `aat_entity_label_`, `aat_title_award_context_v1_`, `aat_title_context_v2_` (bumped from v1, §4.10);
  - `aat_person_context_v1_`, `aat_name_entity_link_by_label_`, `aat_name_id_alias_v1_`;
  - `aat_ceremony_title_highlights_v2_`, `aat_category_title_highlights_v2_`, `aat_ceremony_summary_v1_`, `aat_category_summary_v1_`, `aat_ceremony_rollup_v3_`;
  - `aat_category_decade_ledger_v2_`, `aat_category_latest_winner_v3_` (bumped, §4.10), `aat_ballot_category_groups_v2_`, `aat_ceremony_year_v1_`.
  - Existing `LIKE` sweeps still match, because the stamp is a suffix.
- **Not stamped (singletons with exact-key deletes):** `aat_hub_page_stats_v2`, `aat_latest_year_label_v1`, `aat_category_slug_map_v1`, `aat_category_first_ceremony_v1`, `aat_reviewed_award_post_ids_v1`. They are deleted at F4 and F6, and by their existing non-dataset invalidation sites (review saves). Stamping them would silently break those deletes.
- `clear_oscars_read_api_caches()` (`main:15979`) gains `delete_transient('aat_hub_page_stats_v2');`. This fixes the defect where every hub-stats delete targets the retired `_v1` key. The line is additive inside the invalidator.
- **Link overrides** are read through `AAT_Ledger::link_overrides()`:
  - a request-static cache;
  - then `wp_cache_get('link_overrides:' . stamp, 'aat_ledger')`;
  - then one PK SELECT on `aat_ledger_meta`.
  - The transient API is never used, and nothing is deleted: a new stamp is a new key.
- **The legacy link guard** is read from its option once per request (§5.6); the option is autoloaded only while the guard is on (`legacy`, `rolled_back`).
- The payload shapes of the ballot, rollup and summary families are unchanged, so no key bump is needed for them (AGENTS.md:90-94).
- **Theme caches** use the same stamp from 3.2.91 through `lunara_oscars_dataset_cache_key()`, and the theme's `aat_ledger_swapped` listener invalidates the unstamped theme caches and schedules the portal warm right away, so the stamped person index is rebuilt within a minute rather than at the next daily warm (§8.1 item 11).

### 4.8 Status surface (public, read-only, no secrets)
- **`AAT_Ledger::status()`** produces the `ingest` object:
  - `state`, `live {dataset_version, bundle_id, stamp, swapped_at, counts, sentinels [{id, ok}], sentinels_checked_at, drift_recurring}`, `bundled {dataset_version, bundle_id, mode, baseline, matches_live}`, `pin {bundle_id, at} | null`, `job {phase, updated_at, ticks} | null`, `version_check {version, outcome}`;
  - `last_result {status, code, at, simulation_match, dry_run_number, summary {labels_changed, sourced_links_added, sourced_links_removed, unsourced_links_removed, entities_added, entities_removed, master_rows_changed, ids_preserved, ids_new, ids_new_upstream, ids_retired, slug_renames, slug_swap_cases, slug_conflict_risk, term_renames, media_entities, media_labels_changed, drift_explained, accepted_drift_stale}, sentinels [{id, ok}], sweeps}`;
  - `convergence {edge_cache_seconds, graph_resync {running, stage, slug_stage, refused}}`;
  - `cron {…}` (§4.6.11).
  - It never contains raw database errors, table or option names, absolute paths or anything from `*_api_key`.
  - `state` ∈ `not_installed | queued | importing | validated_dry_run | blocked | failed | current | frozen | rolled_back`.
  - `bundle_id` values are content hashes, not secrets; the agent needs `live.bundle_id` to generate the next bundle's baseline (§4.1).
- **R1 ships `GET /wp-json/lunara-ledger/v1/status`:**
  - `data {ready, api {namespace, revision}, software {plugin_version}, dataset {version, source {file: "data/oscars.csv", sha256, rows}, corrected {sha256, rows}, corrections {entries, cells, additions, by_reason}, needs_review {items, unresolved}, attribution, license}, ingest}` (every number copied from the manifest);
  - `meta {api}`, `links {self, license}`.
  - Paths are repository-relative only. `plugin_version` is published (decision 8).
  - `attribution` = "Awards data: Academy of Motion Picture Arts and Sciences Awards Database, via DLu/oscar_data; corrections audited by Lunara Film." (decision 10).
  - `license` = `{spdx: "BSD-2-Clause", copyright: "Copyright (c) 2022, David V. Lu!!", upstream: "https://github.com/DLu/oscar_data", url: <rest url of /license>}`.
- **R1 also ships `GET /wp-json/lunara-ledger/v1/status/report`:** the last report's public lists, in sections. **Storage** (fourth critique: every uncached request decoded the whole stored report): the diff phase writes each (section, page) as one row of `aat_ledger_report_pages` (`body` = that page's JSON, at most 500 items), deleting the previous job's rows first, in the same tick that writes the summary; `aat_ledger_datasets.report` keeps only the summary. A request reads exactly one row by primary key, and a missing page returns an empty `items` list with the section's true total. Two strict parameters only: `section` (default `summary`; one of `summary`, `id_map`, `slugs`, `media`, `metric:<name>` for each §4.6.6 metric) and `page` (1–200, 500 items per page); any other key or value gives 400 `ledger_unknown_param`.
  - `summary`: the simulation comparison table (§4.6.6, with `media_explained` and `drift_explained`), the counts of every other section, the link-override count, the legacy-guard `pairs`, `label_pairs`, `never_link` and `collateral` counts, and the dry-run number (1–3, §4.6.6);
  - `metric:<name>`: the production-only and simulation-only items with `prod`, `ledger`, `legacy` and `differing_fields` (§4.6.6);
  - `id_map`: unmatched upstream rows with their best candidate and differing fields, unmatched live ids, pass-3 matches, stale pins;
  - `media`: unsourced links (≤2000), entity adds and removes, each marked sourced or media-touched;
  - `slugs`: the slug preview (renames, every swap case and cycle, `suffixed`, `kept_editorial`, `conflict_risk` and the studio-term renames, §4.12).
- **R1 also ships `GET /wp-json/lunara-ledger/v1/license`:** `{data {attribution, upstream {name: "DLu/oscar_data", url, spdx, copyright, text}}}`, where `text` is `data/LICENSE-oscar_data.txt` verbatim. This satisfies BSD-2 clause 2 for the data redistributed by the API.
- **Cache class for all three:** `public, max-age=15, s-maxage=60`. R3 extends `/status` additively (§6).
- **Meta marker.** `wp_head`, on entity and hub routes (and on the explorer from R4) prints `<meta name="aat-dataset" content="version=…; stamp=…; state=…">` from a separate callback. `fix_virtual_page_status()` is untouched. Every R2 HTML probe reads it first (§12 R2).
- **Control Desk** gets a "Ledger dataset" row: pass when current; warn when queued, importing, dry run or `drift_recurring`; fail when failed or blocked; info when frozen or rolled back (with the pinned bundle). It also carries the admin-only **Run ledger job now** button (§4.6.11).

### 4.9 Integration edits in `academy-awards-table.php`

1. **After `:58`:** `require_once` of `includes/class-aat-ledger.php`, `includes/class-aat-ledger-slug-redirects.php` and `includes/class-aat-ledger-api.php`, then `AAT_Ledger::init(); AAT_Ledger_Slug_Redirects::init(); AAT_Ledger_Api::init();`. R3 and R4 add their files to this same block.
2. **`maybe_upgrade_schema()`:**
   - It gains **no** ledger DDL (§4.3: the job's prepare step creates the ledger tables in cron).
   - `:882` becomes `$this->queue_reporting_rebuild('upgrade');`, and so does `:759` in `activate()` (trigger `activate`).
   - `queue_reporting_rebuild($trigger)` depends on `AAT_Ledger::state()`:
     - `live` or `rolled_back` → `AAT_Ledger::request_import($trigger)` (which refuses only the pinned bundle, §4.6.1);
     - `frozen` → no-op, with the trigger recorded in status;
     - `legacy` and enabled → no legacy rebuild (the legacy derivation code is byte-identical in 2.8.0, and skipping avoids racing the dry-run diff);
     - `legacy` and disabled → schedule `aat_legacy_projection_rebuild` (cron), which takes the ledger lock and calls `rebuild_reporting_tables()`.
3. **`ensure_projection_data_available()`:** in place of the inline rebuild at `:1683`, call `request_import('projection_empty')` when live, or schedule the legacy cron rebuild in `legacy` state. Inline rebuilds remain only under `wp_doing_cron()` or WP-CLI.
4. **`rebuild_reporting_tables()` (`:1064`), first statement:** `if (class_exists('AAT_Ledger') && AAT_Ledger::refuses_legacy_writes()) { return AAT_Ledger::defer_reporting_rebuild(__FUNCTION__); }` (true in `live`, `frozen` and `rolled_back`). The rest of the body stays byte-identical. This one guard covers all eleven call sites (§1).
5. **`get_bundled_award_group_census()` (`:17310`):** when live, iterate `AAT_Ledger::source()->corrected_rows_by_header()` (upstream plus additions, masked as in §4.4.4) through the existing `$this->build_import_db_row($source_row)` accumulation. Otherwise the existing loop runs. No new `AAT_BUNDLED_CSV_PATH` mention. Under the test stubs `state()` is `legacy` without calling `get_option` (§4.6.10 rule 1), so the pinned upstream census (12,137 / 3,515) runs.
6. **`ajax_import_bundled_data()` (`:16935`):** after the nonce and capability checks, `request_import('admin')` and return `status()` when the state is not `legacy`. The staging body below stays as the `legacy` path.
7. **Legacy writers** call `AAT_Ledger::legacy_write_refusal($context)` after their capability check and before their first write; it refuses whenever `refuses_legacy_writes()` is true, i.e. in `live`, `frozen` and `rolled_back` (decision 3):
   - `ajax_import_data` (`:16469`), `ajax_import_ceremony_delta` (`:16766`), `ajax_clear_data` (`:18058`);
   - `apply_person_credit_source_correction_from_request` (`:12116`), `apply_person_credit_full_row_source_correction_from_request` (`:12221`), `apply_omdb_verified_bad_id_correction_from_request` (`:8465`);
   - the builder's `ajax_backfill_apply` (`builder:941`) and `ajax_name_repair_step` (`builder:1041`).
   - The message: "The Oscars dataset is managed by the ledger (dataset X). Corrections go through docs/database/corrections.json or additions.json; this tool is read-only."
   - The master-mutating `repair_*_credit_rows` helpers (`:4546-5274`) are reachable only from the two refused importers (`:16572-16575`, `:16895-16898`); a test pins that.
   - `ajax_repair_schema` (`:16905`) keeps its dbDelta work; its rebuild is deferred by item 4.
8. **`get_lunara_integrity_summary()` (`:17409`):**
   - The projection drift SQL (`:17516-17600`) moves verbatim into `private function get_projection_drift_counts(array $tables, $variant = 'legacy')`. It sits immediately after `get_lunara_integrity_summary` and before `ajax_clear_data`, so every marker pinned at `tests/reporting-integrity-contract.php:185-215` stays inside the sliced region.
   - **The variant is explicit** (critic: V6 formula). `'legacy'` keeps the `UNION ALL` expected-stats subquery (`main:17580-17591`) byte-identical. `'ledger'` replaces only that subquery by a DISTINCT set: `SELECT DISTINCT x.nomination_id, x.entity_id, f.ceremony, f.winner FROM ( SELECT nomination_id, imdb_id AS entity_id FROM {ledger_nomination_titles} WHERE imdb_id IS NOT NULL UNION SELECT source_award_id, entity_id FROM {nominees} WHERE entity_id != '' ) x INNER JOIN {facts} f ON f.source_award_id = x.nomination_id`, grouped as before under the same `projection_rows` alias. `$tables` carries the ledger titles table name, so V6 passes stage names.
   - V6 always passes `'ledger'`. The Control Desk passes `AAT_Ledger::master_is_ledger() ? 'ledger' : 'legacy'`, so `frozen` reports no false drift and `rolled_back` uses the legacy formula for the restored legacy tables.
   - The Ledger dataset row is added.
   - `ledger_bridge_projection_drift($tables, $variant)` exposes the helper to V6.
9. **Bridge (public, docblocked, after `ajax_clear_data()`):**
   - `ledger_bridge_import_row`, `ledger_bridge_row_fingerprint`, `ledger_bridge_group_key`, `ledger_bridge_finalize_census`, `ledger_bridge_database_census`, `ledger_bridge_entity_ids`, `ledger_bridge_name_key`, `ledger_bridge_entity_type`, `ledger_bridge_normalize_row`, `ledger_bridge_visible_credit_labels` (wraps `split_visible_person_credit_labels`, `main:944-955`), `ledger_bridge_after_swap_invalidate`, `ledger_bridge_projection_drift`;
   - plus `get_dataset_stamp()`, `dataset_cache_key()`, `resolve_credit_links()`, `credit_link_key()`, `credit_pair_is_guarded()` and `credit_id_is_never_link()` (§5).
10. **`canonicalize_name_entity_id_for_label()` (`main:7937`), first statement after the `$id`/`$label` trims:** `if (class_exists('AAT_Ledger') && AAT_Ledger::master_is_ledger()) { return $id; }` (§5.2 rule 6). The rest of the body stays byte-identical, so legacy and `rolled_back` behave as today.
11. **`tools/verify-live-dataset.php`:** the default expectation comes from the corrected rows (upstream plus additions) via `AAT_Ledger_Source`, with counts from the manifest; `--legacy-parse` gives the old behaviour. The pinned markers at `tests/public-query-path-contract.php:106-109` stay.
12. **`aat_search_entities()` (`main:18101`, the entity feed the theme's live search reads):** inside its result loop, after the pinned `get_entity_url( $row['entity_id'] )` line (`tests/live-search-entity-feed-contract.php:29`), a row is skipped when `$plugin->credit_pair_is_guarded($row['entity_id'], $row['label'])` is true. In `legacy` and `rolled_back` that drops never-link IDs and first-label-wins labels (e.g. "Radio Corporation of America" → nm0429444) from search; otherwise it is a no-op.
13. **`get_name_entity_link_by_label()` (`main:7814`):** a step-1 result is dropped when `credit_pair_is_guarded($row['entity_id'], $row['label'])` or `credit_pair_is_guarded($row['entity_id'], $label)` is true (the stored first label and the looked-up text), and step 2 applies the §5.3 count guard and the same check. Only the admin classifiers (`main:10424`, `:10707`) call it after R1, because the public hub closures stop guessing links from labels (§5.2).
14. **`build_entity_url_from_id()` (`main:7802`), the one place every plugin entity URL is built** (fourth critique: poster grids, the portal, cached payloads and the search feed bypassed the template closures, §1): right after its trim, `if ($id !== '' && $this->credit_id_is_never_link($id)) { return ''; }`. `get_entity_url()` (`main:2502`) only delegates, so it inherits the rule, and so do the rollup and latest-winner payloads (`main:2610`, `:2622`, `:2643`, `:3030`), `get_name_entity_link_by_label()` (`:7878`, `:7920`) and `aat_search_entities()` (whose existing empty-URL `continue` then skips the row). The rule is inert in `live` and `frozen` (`credit_id_is_never_link()` returns false while `master_is_ledger()`), so the API serializer, which receives this method as a callable, is unaffected. `get_title_visual_package()` (`main:9427`) returns `array()` for a never-link tt right after its regex check, so no card shows a wrong title's poster.
15. **`ajax_get_awards_datatable()` (`main:16223`), after the SELECT** (whose pinned `'id, ' . $this->get_awards_row_fields_sql()` stays): every row gets `credit_items` and `title_items` (`resolve_credit_links($row, 'credits')['items']` and `(…, 'titles')['items']`). In `legacy` and `rolled_back` only, each `film_id` and `nominee_ids` token that is a never-link ID, or that sits at its position in an aligned row as a guarded (ID, label) pair, is replaced by `?` before the row is returned. The replacement preserves the token count, and `buildEntityUrl('?')` returns '' (`assets/js/academy-awards-table.js:453-462`), so even an edge-cached copy of the previous script renders those items as plain text.

### 4.10 Read-side correctness fixes (R1)
Each fix is marked active on deploy, or inert until live.

- **(active) `external_lookup_year($label)`:** one private helper that returns the 4-digit label for single-year labels and '' for split labels.
  - It replaces the digit-strips at `:4280, 4365, 8410, 8540, 8551, 8769, 9090`, used for external TMDb/OMDb matching.
  - `:8480` (admin form input) and `:8521` (OMDb response) stay: they compare the admin form's year with the dataset's, both digit-stripped, on a refused legacy writer.
  - **Split labels get both years** (fourth critique: a yearless search let a remake's poster attach). A companion `external_lookup_years($label)` returns `['2025']` for a single-year label and `[start, end]` for a split one (`'1932/33'` → `['1932', '1933']`; the end year is the first two digits of the start plus the two after the slash). The TMDb fallback search (`main:9183-9213`) runs once per year in that list (at most two requests, admin-triggered only) and accepts a candidate only when its `release_date` year is in the list: an exact title match first, then the first result whose year is in the list. **It never accepts `results[0]` without a year match** (`:9209-9211` today), and with no year at all it accepts nothing. The OMDb audit (`:8410`, compared at `:8420`) sets `year_match` when the candidate year is in the same list. `tests/ledger-read-fixes-runtime.php` pins it with a stubbed `wp_remote_get`: for a ceremony-5 title, a same-title candidate from 1979 is rejected and one from 1932 accepted, and a list whose only exact match has the wrong year returns no movie.
  - No Wikidata value is ever used (decision 2).
- **(active) `get_title_context_for_imdb_id()` (`:9022`):**
  - key `aat_title_context_v2_` (stamped); the exact delete at `:8643` deletes v1 and v2;
  - `year` = the verbatim year label of the title's first ceremony;
  - `release_year` = `external_lookup_year(year)`;
  - `title` = the ledger display title when live; otherwise the Film token at the tt's position when the Film and FilmId counts are equal; otherwise the entity label. It never returns the pipe-joined `film`.
  - `get_title_visual_package()` (`:9427-9460`) and the TMDb search (`:9183-9194`) use `release_year` and omit the year when it is empty. This ends the `192728` output.
- **(live) `get_entity_rows('title', $tt)` (`:5685`):** join `(facts.film_entity_id = %s) UNION (ledger nomination_titles.imdb_id = %s)` so second-position titles list their rows. It is guarded by `class_exists('AAT_Ledger') && AAT_Ledger::is_live()`, stays SQL-only and keeps both projection-table references pinned at `tests/public-query-path-contract.php:90-92`.
- **(active) `get_category_latest_winner()` (`:2993`):**
  - key `aat_category_latest_winner_v3_` (stamped; a payload shape change, so the version is bumped per AGENTS.md:90-94);
  - the SELECT (`:3010`) adds `class` and `citation`; the payload adds `citation`;
  - it keeps the existing single-row fields and adds `winners_in_ceremony` and `co_winners[]` (every other winner row of the latest ceremony, each with `citation`), selected by ceremony rather than `LIMIT 1`;
  - every winner row (the primary and each co-winner) passes through `normalize_awards_row()` (idempotent on the stored master, V5), keeps `film_id` as its first tt for the existing poster and title consumers, and adds `film_ids` (the full normalized list). The name links on the card come from `$aat_enrich_winner_entry_links` → `$aat_resolve_entry_name_link`, which resolves through `resolve_credit_links()` on the row's `ceremony`, `canonical_category`, `nominees` and `nominee_ids` (§5.3), so the link key matches the one the deriver computed;
  - `templates/hub-page.php:2494-2633` renders "Latest winners" with every name when `winners_in_ceremony > 1`, and never the word "tie";
  - **phones** (third critique: long SciTech lists; fourth critique: the anchor did not exist): below 600 px the card lists the first 3 winners, then "and N more", an **in-page** link to that ceremony's history card on the same category hub, `#aat-category-ceremony-{ceremony}`. Every category-history card gains that id: `<article class="aat-category-ceremony-row aat-ledger-card…" id="aat-category-ceremony-{N}">` (`templates/hub-page.php:2823`; the class substring pinned at `tests/inner-page-visual-rhythm-contract.php:35` is unchanged), and the card renders every winner of its ceremony (`:2835-2839`), SciTech and Special included. The ceremony ballot's `#ceremony-category-…` anchor is not used, because the ballot excludes those classes (`main:7711-7712`). Above 600 px the card lists all. The rows are server-rendered in both cases; a CSS rule hides items 4+ and shows the "and N more" link below 600 px, so no JS is involved. `tests/ledger-read-fixes-runtime.php` asserts that the link's fragment names an id present in the same rendered hub;
  - **its CSS goes into `assets/css/academy-awards-table.css`**, which has no byte budget; `assets/css/hub-polish.css` (6,976 of its 7,000-byte budget, `tests/ceremony-payload-hygiene-contract.php:56`) is not touched;
  - the raw `esc_html(' ' . $latest_winner['detail'])` marker stays **absent**, as `tests/multi-film-label-contract.php:72-78` asserts; the card keeps printing the detail through `$aat_film_display()` (`hub-page.php:2640`), including for co-winners (fourth critique: revision 4 said the marker "stays").
- **(active) citation-only rows** (critic: blank winner card). A row is citation-only when trimmed Film, Name and Nominees are all empty and Citation is not.
  - `$aat_winner_primary` (`templates/hub-page.php:111-143`) gains, as its **last** fallback before `return $film;`, `if ($film === '' && $name === '' && $nominees === '' && trim((string) ($entry['citation'] ?? '')) !== '') { return trim((string) $entry['citation']); }`. Every existing branch and the two pinned markers (`tests/credit-structure-contract.php:48-49`) are unchanged.
  - That one change labels the citation-only row on every consumer of the closure: category history cards (`:2863-2872`), winner cards via `$aat_enrich_winner_entry_links` (`:303-318`), the briefing, feature and major-nominee cards (`:1283-1285`, `:1843-1844`, `:1911-1912`), the winner circle (`:2204-2205`), and the latest-winner card, which now carries `citation` (above).
  - The citation is printed verbatim and whole through the existing `esc_html` paths (the longest upstream citation is 701 characters); no markup, class or section key changes.
  - `$aat_enrich_winner_entry_links` (`:303-318`) leaves `primary_url` empty when the primary label is the citation, so no label lookup runs on citation text.
  - `$aat_winner_secondary` is unchanged, so row 10475 reads: title = its citation, meta = "Special Photographic". Both closures read rows that already carry `citation` (`get_awards_row_fields_sql()`, `main:3196-3198`, used by `get_category_decade_ledger()`, `main:2857-2859`).
  - `tests/ledger-read-fixes-runtime.php` renders the category-history card for row 10475 and for an appended citation-only fixture and asserts the citation text is present.
- **(active) positional renderers and the legacy link guard:** §5.

### 4.11 Graph builder fixes (`includes/class-aat-entity-graph-builder.php`)
These do not depend on the ledger being live unless marked. The builder only runs after an import hook or its own heartbeat, so they take effect at R2's resync.

- **(a) Film year, verbatim, with ownership** (decisions 2, 12):
  - The movie `release_year` = the `year_label` of the film's first ceremony: from `aat_ceremonies.year_label` joined through `award_facts.film_entity_id` in any state; when live, from ledger `nomination_titles`/`titles.first_year_label`, which also covers second-position titles.
  - It is written verbatim (`'1932/33'`), never cast to int. `MIN(c.sort_year)`, the `max(1888, (int) …)` cast (`builder:309`) and the misleading comment (`:305-308`) are removed.
  - **Ownership rule** (critic: stale builder years). The builder overwrites an existing `release_year` only when the builder owns it:
    - the post has `_aat_year_label` and the current `release_year` equals it (written by this builder version or later); or
    - the post has no `_aat_year_label` (written, if at all, by the pre-2.8 builder) and the current value is empty, or equals the **legacy formula's** result for that post. The legacy formula is `max(1888, (int) MIN(c.sort_year))` over `award_facts.film_entity_id`, computed from the `_l0` tables on the first ledger resync (they hold the exact pre-ledger projections the old builder read) and from the live legacy tables in `legacy` state.
    - Any other value was entered by an editor and is kept; the builder records it in state `editorial_years_kept {count, samples ≤ 50}`.
  - Every write also sets `_aat_year_label` to the same string, so every later run can tell ownership without `_l0`.
- **(b) ACF field type:** `add_filter('acf/load_field/key=field_lunara_movie_release_year', …)` and `add_filter('acf/load_field/key=field_lunara_ledger_year', …)` set `type` to `text` (maxlength 7), with `acf/validate_value` accepting '' or `^\d{4}(/\d{2})?$`.
  - Without this, Core's `number` inputs (`class-lunara-entities.php:160-167`, `:385-392`) cannot display `1932/33`, and saving a movie in wp-admin would blank it.
  - The filters sit in `AAT_Entity_Graph_Builder::init()` (add_filter only). They change no stored value.
- **(c) `ledger_entry.ceremony_year`** = the verbatim `year_label` from `award_facts.year_label`, not `(int) sort_year` (`builder:448`, `:488-490`). The theme sorts these rows by `ceremony_number` (`inc/entity-surfaces.php:43-45`, `:71-73`) and prints the label unflattened from 3.2.91 (§8.1).
- **(d) Clear stale meta** (decision 12): when a row's resolved movie or person post is 0, `delete_post_meta($post_id, 'movie')` / `'person'` and their `_movie` / `_person` ACF reference keys, instead of leaving the old value (`builder:480-485`). Example: source rows 7961 and 8103 move from an nm primary to none.
- **(e) Dated category label and title refresh:** the category field and the post title use `format_category_display($canonical, (int) $row['ceremony'])` (`builder:446-450`, `:486`). For an **existing** `ledger_entry` post (`builder:452-455`), the builder compares the computed title with `get_post_field('post_title', $post_id)` and calls `wp_update_post(array('ID' => $post_id, 'post_title' => $title))` only when they differ, so corrected credit text and dated labels reach existing posts too (third critique). `ledger_entry` is not public (`public => false`), so there is no slug to migrate.
- **(f) Retire orphans** (post-finalize step):
  - movie and person posts whose `_lunara_entity_id` is in the report's `entities_removed` and is referenced by no current row go to **draft**;
  - `ledger_entry` posts whose `_aat_source_award_id` is no longer a `source_award_id` go to **trash** via `wp_trash_post()`, because the theme counts `post_status != 'trash'` (`inc/entity-surfaces.php:41`, `:69`), so a draft would still be counted;
  - both are reversible, and the counts are bounded by `max_entities_removed` and `max_ids_retired` (the number of `retire` entries, 0 at launch, §4.6.6). Retiring more than the bound skips the step and records `retire_bound_exceeded` in builder state.
- **(g) Refusals** per §4.9 item 7.
- **(h) Resync gating** by `consumer_sha256` (§4.6.7 F5).
- **(i) Slug journal** (critic: the planner cannot see old titles). In `step_entities`, **immediately before** `wp_update_post(array('ID' => $post_id, 'post_title' => $label))` (`builder:326`), and only when `AAT_Ledger::master_is_ledger()`, the builder appends `{post_type, post_id, entity_id, old_title, old_name, new_title}` to the option `aat_entity_graph_slug_journal` (not autoloaded; keyed by post_id, so a resumed batch overwrites rather than duplicates; cleared by `start_run`). `old_title` is `get_post_field('post_title', $post_id)` and `old_name` is `get_post_field('post_name', $post_id)`, both read before the update.
- **(j) Stage order and refusal.** `run_step()` gains the `slugs` stage **after `studios`**, so the planner sees the journal of both retitled posts and renamed studio terms (§4.12): movies → people → studios → slugs → ledger → verify. `step_entities`' advance (`builder:284`) is unchanged; `step_studios`' two advances (`builder:369-370`, `:383-384`) go to `'slugs'` instead of `'ledger'`, and the new `step_slugs` advances to `'ledger'`. `start_run()` refuses when `AAT_Ledger::state() === 'rolled_back'`, recording `refused: 'ledger_rolled_back'` in builder state (surfaced as `convergence.graph_resync.refused`), for every trigger: admin start, admin resync, `auto_resync` and the builder heartbeat. It deletes nothing before refusing.
- **(k) Relationships replaced per movie, never emptied** (third critique: 35–80 minutes of missing directors and cast on every data change). `start_run()` no longer deletes `directors` and `principal_cast` (`builder:587-598`). Instead, `step_ledger`, for each movie post its batch touches for the first time in this run (tracked in builder state as a sorted list of post ids), computes that movie's full builder-owned set from the live facts in one query (every Directing or Acting fact whose `film_entity_id` is the movie's ID and whose `primary_entity_id` is an nm with a person post, classified by the existing class rule at `builder:497-508`) and writes it with one `set_field` per field, replacing the old value. A movie whose set is unchanged is not written. The `verify` stage then clears `directors` and `principal_cast` on movie posts that no Directing or Acting fact references any more (bounded like retirement, `max_entities_removed`). The relationships therefore go from the old set to the new set in one write per movie, with no empty window.
- **(l) Studio terms by entity ID** (third critique). `step_studios` looks the term up by `_lunara_entity_id` meta first (`get_terms(array('taxonomy' => 'lunara_studio', 'meta_key' => '_lunara_entity_id', 'meta_value' => $id, 'hide_empty' => false, 'number' => 2))`); only when no term carries the ID does it fall back to `term_exists($label)`, and it then sets `_lunara_entity_id` only on a term that carries no other ID. A found term whose name differs from the label is renamed **in place** (`wp_update_term($term_id, 'lunara_studio', array('name' => $label))`, slug untouched) and journalled for the slug stage like a post (`{kind: 'term', term_id, entity_id, old_name, old_slug, new_name}`, only when `master_is_ledger()`). Two terms carrying the same ID are reported as `studio_duplicate_terms` in builder state and left alone.
- **(m) Builder revision.** `AAT_Entity_Graph_Builder::REVISION` (integer, starting at 1 in 2.8.0) is recorded as `completed_revision` in builder state when a run reaches `verify`. The builder's daily heartbeat (`builder:803-820`) and the ledger's version check (§4.6.1) call `maybe_resync_for_revision()`, which starts a run (`start_run()`, cron-scheduled, never inline) when `completed_revision !== REVISION` and `AAT_Ledger::state()` is `live`. A builder-code fix-forward (R2 class B) therefore reaches `/talent/` and `/film/` by itself.
- `backfill_candidates` and `backfill_key` stay byte-identical (`tests/winner-backfill-identity-contract.php:15-20`).

### 4.12 Slug migration, shipped with R2 (decision 6)
**Why.** `step_entities` retitles posts without touching `post_name`. After R2, `/talent/jean-hersholt/` would keep holding nm0604960 (now titled Ralph Morgan). A mislabelled public URL is a mislabel.

**Planner** (`includes/class-aat-ledger-slugs.php`, `AAT_Ledger_Slug_Planner`, pure). Input, per **namespace**: the post types `person` and `movie`, and the taxonomy `lunara_studio` (third critique: company relabels were not migrated):
- the **journal** entries of this run (§4.11 i and l): (object id, entity_id, old title or name, old slug, new title or name) for exactly the posts retitled and the terms renamed in this run;
- the set of slugs held by every other object of that namespace (out of scope), read at stage start. For post types that is every post of the type **in any status** (publish, draft, pending, private, future, trash), which is the set `wp_unique_post_slug()` checks for a non-hierarchical type, plus the reserved flat slugs it refuses (`wp_unique_post_slug_is_bad_flat_slug`, e.g. `feed`); for the taxonomy it is every `lunara_studio` term.

In the dry-run preview (§4.6.6) no retitle happens, so the importer builds the same input read-only: for every post with `_lunara_entity_id` whose current `post_title` differs from the stage label, and every `lunara_studio` term whose `_lunara_entity_id` company's stage label differs from its name, old = current, new = stage label. The preview also reports **`conflict_risk`**: the number of planned targets that the out-of-scope set did not cover but that core would still refuse (a post of the type created after the read, or an unexpected reserved slug); it must be 0 for R2 to merge (§12 R2).

Output: a plan of `{post_id, from, to, record_old_slug, chain_id, order}` and counts `{renames, swap_cases, cycles, suffixed, kept_editorial}`.
- **In scope:** journal entries whose `old_name` equals `sanitize_title(old_title)` or `sanitize_title(old_title) . '-' . N`. Any other slug was set editorially and is kept (`kept_editorial`).
- **Target:** `sanitize_title(new_title)`, made unique against the **final** state, i.e. against out-of-scope slugs and targets already assigned in the plan. `-2`, `-3`… are appended deterministically by entity_id order when needed (`suffixed`).
- **Chains and cycles.** The planner builds the directed graph "post A's target is post B's current slug". A **chain** (e.g. nm0604960 `jean-hersholt` → `ralph-morgan`, then nm0380965 `the-motion-picture-relief-fund` → `jean-hersholt`) is ordered so every post moves away before another takes its slug; it needs no temporary slug. A **cycle** (A takes B's slug and B takes A's) is the only case that uses a temporary slug. A rename that neither frees nor takes a contested slug is a chain of length 1.
- **`record_old_slug`** = true unless the old slug becomes some other post's final slug. In a swap, the old slug now belongs to someone else, so no `_wp_old_slug` may point away from it.

**Execution** (the builder's `slugs` stage, after `studios`, only when `AAT_Ledger::master_is_ledger()`; critic: phase-1 404 window):
1. Chains and cycles are processed **whole, one or more per tick**; the budget is checked only between chains, so no chain is ever left half-done between ticks. Progress is keyed by `chain_id` in builder state, and the stage is resumable.
2. **Chain member:** `wp_update_post(array('ID' => $id, 'post_name' => $to))`. Core records `_wp_old_slug = $from` itself (`wp_check_for_changed_slugs` on `post_updated` for published posts), so the old URL redirects immediately through `wp_old_slug_redirect()`, and Jetpack sync and other `save_post` listeners see the change. When `record_old_slug` is false, the member's `_wp_old_slug = $from` row is deleted right after its successor takes the slug, in the same chain pass.
3. **Cycle:** inside one tick, the first member moves to `aat-slug-tmp-{post_id}` via `$wpdb->update($wpdb->posts, …)` (no hooks, no old-slug row), the others move in order through `wp_update_post`, then the first member moves to its final slug through `wp_update_post`, and `delete_post_meta($id, '_wp_old_slug', 'aat-slug-tmp-' . $id)` removes the temp slug core recorded.
4. **Clean-up** (after the last chain): any `_wp_old_slug` row on any post of the type whose value equals a slug now owned by a post is deleted, so core's redirect never competes with a live post.
5. `clean_post_cache()` for each renamed post.
6. After each `wp_update_post`, the executor re-reads `post_name`. If core made it unique (e.g. appended `-2` because an unexpected post holds the target), the stage stops, records `slug_conflict {post_id, wanted, got}` in builder state and `/status`, and the builder continues with `ledger`; the remaining chains are left untouched.
7. **Studio terms** move in their own chains: `wp_update_term($term_id, 'lunara_studio', array('slug' => $to))`, then `add_term_meta($term_id, '_aat_old_slug', $from)` when `record_old_slug` is true, and the same re-read and `slug_conflict` rule. WordPress has no old-slug redirect for terms (§1), so the plugin adds one: `AAT_Ledger_Slug_Redirects` (its own small file, `includes/class-aat-ledger-slug-redirects.php`, required in the `:58` block and loaded on every request, because it must answer public 404s; its `init()` is `add_action` only) hooks `template_redirect` at priority 5 and, **only** on a 404 whose main query asked for a `lunara_studio` term by slug, looks up the term with `_aat_old_slug` equal to that slug (one `get_terms` meta query) and sends a 301 to `get_term_link()`. The clean-up in step 4 applies to `_aat_old_slug` too.
8. **Bound for terms:** term renames count against `max_term_slug_changes` separately.

Old URLs then 301 to the new ones through WordPress's native `wp_old_slug_redirect()` (decision 6). The only window in which an old URL can 404 or point wrongly is inside a single chain pass, which takes well under a second.

**Bound.** When the plan's post renames exceed `manifest.change_bounds.max_slug_changes` (or its term renames exceed `max_term_slug_changes`), the stage renames nothing, records `slug_bound_exceeded` in builder state (surfaced in `/status` `convergence.graph_resync.slug_stage`), and the builder continues. Because the R2 pre-merge gate requires the R1 preview to be within both bounds with `conflict_risk` 0 (§12 R2), a breach at R2 can only come from content edited between the dry run and the swap; its fix is a fix-forward bundle whose regenerated bounds cover the new preview, never a hand-raised bound.

**Preview.** R1's dry run publishes the planner's read-only output in `/status/report?section=slugs` (§4.8): renames, swap cases, cycles, suffixed, kept_editorial, term renames and `conflict_risk`. The owner can read it after the fact; no approval is needed. R2's live check 9 is driven by it (§12 R2).

### 4.13 Guarantees on public paths (tested)
- No bundle parsing on public requests, except one manifest and one `legacy-link-guard.json` read on the first request after a version change, after the kill switch is lifted, or after the rollback pin changes (§4.6.1).
- No inline rebuilds, and no dbDelta for the ledger tables (§4.3).
- No public template calls `get_name_entity_link_by_label()` (§5.2).
- Ledger pipeline classes are never referenced from `get_hub_page_stats`, `get_ceremony_summary`, `ajax_get_awards_datatable`, `get_entity_rows`, `resolve_credit_links`, `credit_pair_is_guarded`, `credit_id_is_never_link`, `build_entity_url_from_id`, `get_title_visual_package` or the templates. Those use only `AAT_Ledger::is_live()`, `is_frozen()`, `master_is_ledger()`, `refuses_legacy_writes()`, `stamp()`, `live_meta_stamp()`, `table()`, `link_overrides()` and `legacy_link_guard()`.
- Genuine 404s keep `nocache_headers()`; valid routes stay cacheable.

### 4.14 Public-repository privacy (U00; critic: QIDs and birth years in a public repo)
The plugin repository is public (§1). Decision 2 ("store no Wikidata QIDs or birth years publicly") therefore covers the repository, not only lunarafilm.com.

1. **Extract names once.** `tests/tools/extract-reference-names.php` (CLI guard) reads the `ledger_entities` INSERTs (`imdb_id`, `kind`, `name`) and the `ledger_titles` INSERTs (`imdb_id`, `title`) of the current `docs/database/data.sql.gz` (PR #39's rebuild) and writes `data/ledger/entities.tsv` and `data/ledger/titles.tsv` (§4.1 headers). It discards every other column. It runs before step 2 and is then kept only for reproducibility. It prints the INSERT counts it read, and its `--verify` mode compares them with the rows written; no count is a literal (fourth critique).
2. **Rewrite `docs/database/data.sql.gz`** by `tests/tools/strip-reference-columns.php`: every `INSERT INTO ledger_titles VALUES (id, title, year, qid)` becomes `(id, title)` and every `INSERT INTO ledger_entities VALUES (id, kind, name, birth, qid)` becomes `(id, kind, name)`, and the `evidence` value of every `INSERT INTO ledger_corrections` line is redacted (item 6). No other statement changes. The output is gzipped deterministically (`gzencode(…, 9)`, no timestamp). This is a one-time rewrite of the audit's dump so that U00's commit is clean on its own; from U03 on the bundle builder regenerates the file from the deriver (§4.1), so the public SQL always equals the ledger.
3. **Rewrite `docs/database/schema.sql`**: remove `release_year` and `wikidata_qid` from `ledger_titles` (`:67-68`) and `birth_year` and `wikidata_qid` from `ledger_entities` (`:78-79`), leaving the remaining definitions byte-identical, including PR #39's `verification VARCHAR(255)` (`:153`).
4. **Rewrite `docs/database/tools/build.py`** so it no longer takes a Wikidata input or emits those columns (`:4`, `:14-18`, `:142-184`), and say so in `docs/database/README.md`, together with the fact that `data.sql.gz` is now produced by the bundle builder.
5. **Strip the design drafts before committing `docs/design/ledger-2.8/`**: remove `wikidata_qid`, `wikidata_release_year`, `wikidata_birth_year` and their `required`/`oneOf` references from `schemas/entity.schema.json`; remove the five Wikidata fields from `reference_serializer.py`; remove the Wikidata line from `proto/sim.php` (committed as `proto/sim.php.txt`). **The committed plan is this revision**, which quotes no QID, no Wikidata-derived year and no life span; the critiques and earlier revisions are not committed (third critique: they quote such values).
6. **Evidence redaction** (third critique; scope narrowed by the fourth critique). One function, `redact_evidence(string): string`, implemented once in `tests/tools/lib/redact-evidence.php` (U00; used by the bundle builder and the U00 tools; not deployed). Its pattern list is repeated as `AAT_Ledger_Source::PRIVACY_PATTERNS` (U01) for the importer's codec rule 14, and `tests/ledger-bundle-contract.php` asserts the two lists are identical. It applies, in this order:
   1. every URL matching `https?://(www\.)?wikidata\.org/[^\s)\],;'"|]+` becomes the word `Wikidata`;
   2. every remaining token matching `\bQ\d{2,}\b` is removed, together with one preceding space;
   3. every parenthesized year span `\(\s*(b\.\s*|born\s+|c\.\s*)?(1[6-9]\d\d|20\d\d)\s*[-–]\s*((1[6-9]\d\d|20\d\d)\s*)?\)`, every bare span `(?<![:\d])\b(1[6-9]\d\d|20\d\d)\s*[-–]\s*(1[6-9]\d\d|20\d\d)\b` (the look-behind leaves `file:line` ranges such as `main:1668-1690` alone) and every `\b(born|b\.|died|d\.)\s+(in\s+)?(1[6-9]\d\d|20\d\d)\b` becomes `[years omitted]`;
   - Nothing else changes: revision 4's fourth step (collapse every run of spaces) is dropped, because step 2 already removes its one preceding space and a global collapse would rewrite indentation in Markdown and double spaces in the Academy's own text.
   - It is idempotent (`redact(redact(x)) === redact(x)`) and leaves eligibility labels such as `1932/33`, single years and ceremony numbers alone.
   - **It applies only to evidence-bearing values**, named per file in one table, `REDACT_KEYS`, shared by the builder, U00's tools, the codec and the privacy contract:
     - `corrections.json`: `evidence`, `verification`;
     - `additions.json`: `evidence`, `verification` (never `row`);
     - `needs-review.json`: `question`, `tried`, `resolution.evidence`, `resolution.verification` (never `label` or `rows`);
     - `accepted-drift.json`: `reason` and `evidence` of every item, pin, restored and retire entry (never `key`, `prod_sha1` or any value field);
     - `tools/adjudications.json`: `note`;
     - `tools/editor_decisions.json`: `evidence`, `verification` (never `slots`, `cells` or `additions`);
     - the `evidence` and `verification` values of the `INSERT INTO ledger_corrections` lines of `data.sql.gz` (step 2 once, then the builder's dump), and `AUDIT-REPORT.md` (once, by U00).
   - It **never** applies to `before`, `after`, appended `row` cells, labels or any other dataset value. Those are the Academy's text: `data/oscars.csv:11337` (source_row 11336) carries a founder's life span in its Note, and a verified correction or addition that quotes such a cell must ship byte for byte, or its before-check fails (`overlay_before_mismatch`) and its after-value silently alters the record (fourth critique).
   - **Audit review-item labels.** The audit numbered its review items with the letter Q and three digits, which the Q-number rule cannot tell from a QID. U00 renames them, in the JSON records only, to the letter R with the same digits: the keys of `tools/adjudications.json` and the `items` values of `tools/editor_decisions.json`. The kept Python scripts (exempt, provenance only, not re-runnable) keep the old prefix, and the README says so.
   - Measured on PR #39's files (§1): 214 `evidence` strings of `corrections.json` (21 Wikidata URLs, 123 further Q-number tokens, 26 distinct QIDs, the rest year spans), 2 `note` values of `adjudications.json`, the `evidence` of `editor_decisions.json` (6 QIDs, 2 spans), 3 lines of `AUDIT-REPORT.md`, and **0 dataset values**.
   - The deployed `data/ledger/*.json` files are byte copies of the redacted `docs/` files, so the bundle hash covers the redacted copy, and `/wp-json/lunara-ledger/v1/corrections` serves only redacted evidence.
   - What stays: the word "Wikidata" as the name of a source that was consulted, and every non-Wikidata URL (the Academy's database, IMDb, Wikipedia).
7. **`docs/database/oscars-corrected.xlsx`** repeats the evidence on its Corrections sheet. U00 regenerates it once with `python3 docs/database/tools/make_workbook.py docs/database/oscars-corrected.tsv docs/database/corrections.json docs/database/needs-review.json docs/database/oscars-corrected.xlsx docs/database/additions.json` from the redacted files, and from then on the bundle builder regenerates it whenever the overlay changes, with `--check` verifying its sheet dimensions against the manifest (§4.1; fourth critique: stale workbook). If openpyxl is not available locally, U00 deletes the xlsx instead, the README says the TSV is the canonical corrected sheet, and the builder's workbook step is skipped because the file is absent.
8. **CI contract** `tests/ledger-privacy-contract.php` over the tracked files (`git ls-files` when git is available, else a walk of the tree excluding `.git`, `node_modules` and `vendor`; `*.gz` files are scanned after `gzdecode`; `*.xlsx` files are scanned by reading every `xl/**/*.xml` part through `ZipArchive`, and the test fails when `CI` is set and `ZipArchive` is missing; images and fonts are skipped and listed):
   - no file contains `wikidata_qid`, `birth_year`, `wikidata_birth_year` or `wikidata_release_year`, except the tests that name them as forbidden (`tests/ledger-privacy-contract.php`, `tests/ledger-ddl-contract.php`, `tests/ledger-api-contract.php`, `tests/ledger-json-schema-contract.php`, `tests/ledger-serializer-runtime.php`) and `docs/design/ledger-2.8/*.md` (this plan, which names the columns it removes). READMEs and changelogs describe the removal in prose ("Wikidata QIDs", "birth years"), never with the column names;
   - **no Q-number anywhere in data or prose**: no file outside `docs/database/tools/*.py`, `tests/tools/lib/redact-evidence.php` and the privacy test itself matches `wikidata\.org/`, `\bWikidata\s+Q\d+` or `\bQ\d{2,}\b`. This covers `*.json` (the audit's `tools/*.json` included, after the label rename of item 6), `*.tsv`, `*.sql`, `*.sql.gz`, `*.md` (the plan included), `*.txt` and the xlsx parts. The evidence-key exemption of revision 3 is gone. The Python audit tools keep two kinds of Q-token that describe no film, person or company: the Academy's own award-class items in the SPARQL of `wd_awards.py:3,29` and an audit item label in `assemble_corrections.py:131`. The upstream CSV and the corrected TSV contain no such token today (checked this session);
   - **no year span in evidence-bearing text**: no `REDACT_KEYS` value in `docs/database/` or `data/ledger/`, no `*.md` under `docs/database/` or `docs/design/ledger-2.8/`, and no `evidence` or `verification` value of a `ledger_corrections` line of `data.sql.gz` matches the step-3 patterns (equivalently: `redact_evidence()` is the identity on every one of them). Dataset values are out of scope, so source_row 11336's Note passes wherever it appears (in `oscars-corrected.tsv`, the `ledger_nominations` line of the dump, a fixture, or a correction's `before`);
   - no `INSERT INTO ledger_entities` row has more than 3 values and no `INSERT INTO ledger_titles` row more than 2.
9. **Git history.** Commit `d7a3bda` on `main` (merged by `021db1f`) still holds the old `data.sql.gz`, `schema.sql`, the unredacted evidence and the xlsx, and forks or clones may too. Removing them from history needs a history rewrite and a force-push to `main`. That is Dalton's call; the R1 owner report tells him (§14.2). No agent rewrites history.

## 5. Public credit links (critic blockers 1 of all three rounds)

### 5.1 The invariant
Every link from a credited name or title to `/oscars/name|company|title/{id}/`, on every surface, must equal the link the ledger's positional slot gives. No surface may show a known-wrong or under-review pair as a link in any state, and no surface may replace a credited name with another label unless that credit names exactly one nominee. Five mechanisms secure it:
1. **Proof for aligned rows (V11).** Before any swap, the deriver proves the following for every master row whose `|`-split value list and ID list have equal counts: pairing by position yields exactly the single linked identity of each ledger slot. A violation refuses the import. Every count-guarded consumer (§5.3) is then correct by construction **as long as nothing re-routes or relabels the paired ID afterwards**.
2. **Slots for misaligned rows.** Rows in the link-override set (§4.4.6; measured over PR #39's overlay: 242 rows with NomineeIds whose counts differ, of which the members are those with at least one normalized ID, plus the masked row 5671; the manifest carries the generated count) are drawn from the ledger slots while live and rendered as plain text otherwise. Rows with no ID at all are plain text in every state.
3. **No re-routing, no cross-nominee relabel, no guessing.** `canonicalize_name_entity_id_for_label()` returns its input while the master is ledger-derived. A single ID may relabel a credit with the ID's display name only when the credit names at most one nominee and the pair is not guarded (third critique: the title-page line relabelled "Denham Studio Sound Department, A. W. Watkins, Sound Director" as "Denham"). Public pages never turn a label into a link through a lookup, in any state (third critique: "Radio Corporation of America" → Watson Jones).
4. **Legacy link guard** (§5.6) in `legacy` and `rolled_back`, where the master is the unmasked, uncorrected legacy data: known-wrong and flagged (ID, label) pairs, first-label-wins (ID, label) pairs that are not credited aliases, and **every** link to an ID the ledger links nowhere render as plain text. It is applied to index items, before any relabel, **inside `build_entity_url_from_id()`** (so every template, cached payload, the portal and the search feed inherit the never-link rule), in `get_title_visual_package()`, in the DataTables payload and in the search feed (fourth critique: the rule sat only in the two template closures).
5. **One resolver, and an exhaustive consumer list.** Every renderer that turns a master row into credit links calls `resolve_credit_links()` (§5.2). §5.3 lists every consumer of master `nominee_ids`/`film_id`; a contract test pins that the public templates contain no other pairing or lookup.

### 5.2 One resolver in the main class
The resolver is `public function resolve_credit_links($row, $list = 'credits', $value_list = null)`. It is docblocked and sits after `ajax_clear_data()`.
- `$list` ∈ {`credits`, `titles`}. `$value_list` defaults to `$row['nominees']` (credits) or `$row['film']` (titles).
- It returns `{mode, items}` with `mode` ∈ `index | slots | plain` and `items` an ordered list of `{label, links: [{imdb_id, url, name}]}`.

**Algorithm:**
1. `L` = `explode('|', $value_list)`, trimmed and non-empty. `I` = `explode('|', $row['nominee_ids'] or $row['film_id'])`, trimmed and non-empty.
2. If `$value_list === $row[values field]` and `count(L) === count(I) > 0` → `mode index`: `L[i]` links to `I[i]`, except that an item for which `credit_pair_is_guarded(I[i], L[i])` is true, or whose URL is empty, gets no link (the guard can only be true in `legacy` and `rolled_back`). V11 proves the rest match the slot.
3. Else, if `count(I) > 0` or the row was masked, and `AAT_Ledger::is_live()`:
   - look up `AAT_Ledger::link_overrides()[credit_link_key($row, $list)]`;
   - found and not ambiguous → `mode slots`: one item per slot with `label = as_credited` and `links` = its linked identities (0, 1 or several, e.g. Roderick Jaynes → Ethan Coen, Joel Coen);
   - otherwise → `mode plain`.
4. Else → `mode plain`: `L` with no links. That covers every state for rows with no ID (e.g. source_row 3470, "West Germany"), and `legacy`, `frozen` and `rolled_back` for misaligned rows. The index is never used.
5. `credit_link_key($row, $list)` is the §4.4.6 key over `ceremony`, `canonical_category`, the values field and the IDs field. A row missing any of those fields gets `mode plain` whenever step 2 does not apply.
6. **No label re-routing.** No caller may pass an ID obtained from `resolve_credit_links` or from a count-guarded index pairing through `canonicalize_name_entity_id_for_label()` or any label lookup while `AAT_Ledger::master_is_ledger()`. The function itself returns `$id` unchanged in that case (§4.9 item 10), so existing callers (`templates/entity-page.php:87-88`, `main:7916`) are safe without edits. In `legacy` and `rolled_back` it keeps its current behaviour.
7. **No label lookups on public pages.** The hub closures no longer call `get_name_entity_link_by_label()` (they did at `hub-page.php:397-404` and `:468-475`, and replaced the credited text with the stored label). Its remaining callers are the admin classifiers (`main:10424`, `:10707`), and it applies the guard itself (§4.9 item 13). The consequence in `legacy`: names on ID-less rows (mostly SciTech and Special) lose their label-guessed links in R1; they are plain in `live` too (§5.4), so R1 simply shows early what R2 shows.

**Companion methods** (public, docblocked, after `ajax_clear_data()`):
- `credit_pair_is_guarded($imdb_id, $label)` and `credit_id_is_never_link($imdb_id)` (§5.6);
- `credit_items_html(array $resolved, $link_class, $text_class, $separator_html)`: the shared markup for resolver items: an item with one link is `<a class="$link_class" href="…">label</a>`; an item with several links is `<span class="$text_class">label</span> (<a …>name</a>, <a …>name</a>)`, the anchor texts being the linked identities' display names; an item with none is `<span class="$text_class">label</span>`; items are joined by `$separator_html`. Every closure below uses it, so the markup is tested once.

### 5.3 Every consumer of master `nominee_ids`/`film_id` and why it is safe

| Consumer | Pairs by index, relabels or re-routes? | Change (R1) | Why safe after |
|---|---|---|---|
| `main:7802-7809` `build_entity_url_from_id()`, and through it `main:2502` `get_entity_url()` | Builds every plugin entity URL: the two template closures, the direct template calls (`hub-page.php:815`, `:858`, `:1769`, `:2100`, `:3055`; `table-display.php:253`, `:363`, `:393`), the rollup and latest-winner payload URLs (`main:2610`, `:2622`, `:2643`, `:3030`), the search feed (`main:18135`) and the admin tools | Returns '' when `credit_id_is_never_link($id)` (§4.9 item 14). `get_title_visual_package()` (`main:9427`) returns `array()` for a never-link tt | A never-link ID is never an anchor, and never lends its poster, in `legacy` or `rolled_back`, whichever path built the URL (fourth critique; live: `/oscars/ceremony/55/`, tt0169446) |
| `templates/hub-page.php:166-169` `$aat_build_entity_url`; `templates/entity-page.php:54-57` `$build_entity_url` | — | Keep their own `credit_id_is_never_link` check (`method_exists`-guarded, so stub harnesses are unaffected). **Every template entity URL goes through them:** the five direct `get_entity_url(` calls of `hub-page.php` become `$aat_build_entity_url(…)`, and `table-display.php` gains the same closure for its three; a contract pins that `hub-page.php`, `entity-page.php` and `table-display.php` call `get_entity_url(` or `build_entity_url_from_id(` only inside those closures (the admin templates `poster-admin.php`, `tracker-admin.php` and `tracker-v2.php` are exempt). **Payload URLs are never trusted when an ID is present:** `$aat_enrich_winner_entry_links` falls back to `$entry['film_url']` only when `film_id` is empty (today whenever the closure returns '', `hub-page.php:313-315`), and the best-picture cards (`:1769`) build the URL from `$fid` whenever it is set | Defence in depth on top of the method rule, and correct during the hour a pre-R1 rollup transient can survive (`main:2728`) |
| `templates/hub-page.php:444-485` `$aat_render_pipe_links` (ballot title line `:2021`, credit line `:2026`) | Pairs by index unguarded; label lookup and relabel for ID-less values (`:466-475`) | **New signature** `function($value_list, $id_list = '', $class = 'aat-hub-inline-link', $row = null, $list = 'credits')`. With `$row`, it renders `credit_items_html(resolve_credit_links($row, $list, $value_list), …)`; department-credit labels (`$aat_is_department_credit_label`) are never linked, as today. Both call sites pass `$ballot_row` and `'titles'`/`'credits'` (the fallback of `:2022-2025` to Name is kept: when `nominees` is empty the resolver's plain mode prints the Name). Without `$row` (no caller after R1, asserted) it prints plain spans. The label lookup is removed; the comma/"and" split for a single ID-less value (`:451-459`) stays as a display rule for plain items | §5.2 |
| `templates/hub-page.php:351-422` `$aat_build_person_link_items` (winner circle `:2839`, nominee people `:2910`) | Pairs by index unguarded; label lookup and relabel (`:397-404`) | Builds its items from `resolve_credit_links($entry)`: one person item per link **whose ID passes the existing person filter `^(nm\d{7,9}|lnm-[a-z0-9-]+)$` (`:393-395`)**, so companies (row 526's United Artists, co0026841) and the title-primary fill-in tt never become person chips (fourth critique); labelled with the item's credited label for a single-link item and with the identity's display name for a multi-link item; items without a qualifying link are skipped, as today; department credits are skipped, as today; the label lookup is removed | §5.2 |
| `templates/hub-page.php:249-301` `$aat_resolve_entry_name_link` (via `$aat_enrich_winner_entry_links` `:317`: every winner card and the latest-winner card `:2365`) | The Name branch links the whole Name whenever there is one ID, whatever the label count (`:290-295`); the no-Name branch (`:265-270`) already requires one label and one ID | Uses `resolve_credit_links($entry)`: the explicit-name match links the one item whose label matches the Name (existing comparable-name rule), only when exactly one does; both single-ID branches apply only when `count(nominee_parts) <= 1` and the resolver's single item carries a link (so the guard and never-link have already applied). The enrich closure sets `person_url` (and so the latest-winner person chip, `:2683-2684`) only when that link's ID passes the same person filter; a company link still serves as the primary or secondary link of the credit it labels | Guarded; no company shown as a person chip |
| `templates/hub-page.php:621-642` `$aat_get_person_visual` | No (first nm with a portrait) | None. Masked needs-review IDs are absent from the master; in `legacy` a never-link ID gets no URL from `$aat_build_entity_url` | Picks a genuinely credited person |
| `templates/entity-page.php:209-260` `$resolve_title_nominee_display` (title-page nominee line, call site `:1108-1110`) | **Relabels and pairs one label with one ID whatever the nominee count** (`:215`, `:248-253`; third critique, live defects in §1) | Rewritten to return resolver items: (1) `$r = $aat->resolve_credit_links($row)`; (2) mode `slots` or `plain` → the items as given (the credited slot labels; the Name is not used, because it would re-pair); (3) mode `index` → the existing explicit-name match when several nominees (one matching item, else all items); when **at most one** nominee, the label becomes the ID's display name only if the item carries a link and neither `credit_pair_is_guarded($id, $credited)` nor `credit_pair_is_guarded($id, $display)` is true; otherwise the credited label stays. The call site renders `credit_items_html()`. Revision 3 marked this row "Guarded / None / V11"; that was wrong | §5.2, legacy guard |
| `templates/entity-page.php:75-104` `$render_linked_pipe` (name-page film lines `:1115`, and any other caller) | Guarded by count, **then re-routed** by `canonicalize_name_entity_id_for_label()` (`:87-88`) | Re-routing is off while `master_is_ledger()` (§4.9 item 10); in `legacy`/`rolled_back` an item for which `credit_pair_is_guarded()` is true renders as `aat-entity-text`; its URLs come from `$build_entity_url` (never-link applies) | V11, no re-routing, legacy guard. Rows 1814 and 2564 keep nm0916990 and nm0772834 |
| `main:7814-7925` `get_name_entity_link_by_label()` | Step 1 on first-label-wins `sort_label` (`:7856-7880`); step 2 unguarded (`:7897-7913`), then `canonicalize` (`:7916`) | Step 1 drops a result that the guard rejects for either its stored label or the looked-up text; step 2 pairs only when `count(labels) === count(ids)` and skips guarded pairs. While live, step 2 is replaced by `aat_ledger_aliases` (`folded` = fold(label), exactly one linked person identity). No public caller after R1 | Admin only |
| `main:7937-7985` `canonicalize_name_entity_id_for_label()` | Re-routes by label | Returns `$id` unchanged while `master_is_ledger()` | §5.2 rule 6 |
| `main:18101` `aat_search_entities()` (the theme's live-search entity feed) | Lists `aat_entities` labels | Skips a row when `credit_pair_is_guarded($row['entity_id'], $row['label'])` (§4.9 item 12) | No guarded label or never-link ID in search |
| `main:10424`, `main:10707` (person-credit and company-credit classifiers) | Label lookups | None: admin-only audit tools that build no public link | Not public |
| `main:15272-15360` `ajax_tracker_search_entities` (admin only, `verify_admin_ajax_request`) | Guarded, plus an `ids[0]` fallback | The fallback only when `count(ids) === 1` | Guarded |
| `main:3736-3775` `build_nominee_name_index()` | Guarded | None; used only by refused legacy writers (`:4557-5295`) | Refused while `refuses_legacy_writes()` |
| `main:5853-5868` `map_pipe_value_to_id()` | Guarded | None | V11 |
| `main:5795-5806` row-contains-entity check; `main:17300` census key; `main:17516-17548` drift SQL; `builder:882` backfill key; `class-aat-source-validator.php` shape counts | No (sets and counts) | None | Not positional |
| `main:1340` legacy rebuild pairing | Yes | Never runs while `refuses_legacy_writes()` (§4.9 item 4) | Not executed |
| `main:2993-3036` `get_category_latest_winner()` payload | Carries the row's credit fields | Rows normalized; links resolved by the enrich closure above (§4.10) | §5.2 |
| `assets/js/academy-awards-table.js:524-551` (DataTables, until E3) | Guarded | Aligned rows render `row.credit_items` when present (below) | V11, legacy guard |
| `assets/js/academy-awards-table.js:471-485`, `:558` (misaligned rows and the Name-only branch) | **Suggests pairing** with "Lunara 1…N" pills | `ajax_get_awards_datatable()` adds, per row, `credit_items` = `resolve_credit_links($row)['items']` (the DataTables SELECT already carries `id`, pinned at `tests/public-query-path-contract.php:97-98`) and, only while `master_is_ledger()`, `entity_names {id: display name}` for the row's IDs (one `IN` query per page on `aat_entities`). `renderNomineeCell` renders `credit_items` first (links only where the item has links; a multi-link item reads "Roderick Jaynes (Ethan Coen, Joel Coen)"). `renderProfilePills` labels each pill with `entity_names[id]` and renders nothing when `entity_names` is absent, so no numbered pill appears in any state. The six pinned markers (`tests/credit-structure-contract.php:37-44`) stay in the fallback path | No positional suggestion |
| `assets/js/academy-awards-table.js:617-643` `renderFilmCell` (film column) | Pairs `film` with `film_id` by index and links each (fourth critique: row 6429's tt0169446 in `legacy`) | The payload adds `title_items` = `resolve_credit_links($row, 'titles')['items']`, and `renderFilmCell` renders it first, exactly as `renderNomineeCell` renders `credit_items`. In `legacy` and `rolled_back` the payload's never-link and guarded tokens are `?` (§4.9 item 15), so the index fallback and an edge-cached older script render them as plain text | Guarded in every state and for every script version |
| theme `inc/frontend.php:2572-2582` `$map_pipe_values` (search, `:2616`) | Guarded | R1T: each (id, label) pair is dropped when `lunara_oscars_pair_is_guarded()` is true | V11, legacy guard |
| theme `inc/frontend.php:2211-2231` and `:2596-2610` (search title matches) | Build `/oscars/title/{film_id}/` from a single `film_id` themselves | R1T: a title match is skipped when `lunara_oscars_pair_is_guarded($film_id, $film)` is true, which covers never-link titles (fourth critique: the theme built the URL without the plugin's method) | Guarded |
| theme `inc/oscars-portal.php:609-624` person index (count branch) | Guarded | R1T: guarded pairs are skipped | V11, legacy guard |
| theme `inc/oscars-portal.php:625-631` person index (single-ID Name branch) | Unguarded | R1T: requires `count($names) <= 1` and an unguarded pair | Guarded |
| theme `inc/oscars-data.php:169-188` `lunara_resolve_oscars_winner_person_id` (portal winner circle) | Single-ID branch unguarded | R1T: the single-ID branch requires `count($nominee_names) <= 1`; a guarded result returns '' | Guarded |
| theme `functions.php:12706-12725` `lunara_oscar_nominee_id_for_label` (Oscar Picks ledger URLs) | Yes, unguarded, first-ID fallback | R1T: pair only when counts are equal; fall back to the first ID only when there is exactly one ID and at most one name; a guarded result returns '' | Guarded |

The theme's `lunara_oscars_pair_is_guarded($id, $label)` delegates to `credit_pair_is_guarded()`, which also answers true for a never-link ID whatever the label, so every theme row above inherits the never-link rule without a second helper.

### 5.4 Behaviour by state

| State | Aligned rows | Misaligned rows | ID-less rows | Single-ID relabel | Re-routing by label | Label-lookup links |
|---|---|---|---|---|---|---|
| `legacy` (R1 until R2) | index, minus guarded pairs and never-link IDs | **plain** (today these are wrong or guessed links) | plain | only for ≤1 nominee and an unguarded pair | as today | none on public pages |
| `live` | index | ledger slots | plain | only for ≤1 nominee | off | none on public pages |
| `frozen` | index | plain | plain | only for ≤1 nominee | off | none on public pages |
| `rolled_back` | index, minus guarded pairs and never-link IDs | plain | plain | only for ≤1 nominee and an unguarded pair | as today | none on public pages |

### 5.5 Tests and probes
- **`tests/ledger-credit-links-runtime.php`** (CI) extracts `resolve_credit_links`, `credit_link_key`, `credit_pair_is_guarded`, `credit_id_is_never_link`, `credit_items_html` and `canonicalize_name_entity_id_for_label` through the public-method harness. It uses the stored master rows, link-override entries, legacy-parse rows and guard lists exported by the deriver (`tests/fixtures/ledger/credit-links.json`) with a stub `AAT_Ledger` per state and a stub `$wpdb` whose `aat_entities` holds the credit-mode names (live, frozen) or the legacy simulation's labels (legacy, rolled_back). It asserts:
  - **source_row 965** live: The Motion Picture Relief Fund → no link; Jean Hersholt → nm0380965; Ralph Morgan → nm0604960; Ralph Block → nm0088759; Conrad Nagel → nm0619261. Legacy and frozen: all plain.
  - **source_row 276** live: Fox Film Corporation → no link; FRED JACKMAN → nm0413164; WARNER BROS. PICTURES INC. → no link; SIDNEY SANDERS → no link. Legacy: all plain.
  - **source_row 8165** live: one item "Roderick Jaynes" with links [nm0001053 Ethan Coen, nm0001054 Joel Coen]. Legacy: plain.
  - **source_row 9026** live: Charlie Kaufman → nm0442109; Donald Kaufman → no link. Legacy and frozen: both plain.
  - **source_row 938** live: Denham → no link; A. W. Watkins → nm0914249. Legacy and frozen: both plain.
  - **source_row 1575** (settled by PR #39: co0141760 → co0003606, wrong_id): live and frozen: United States Army Pictorial Service → co0003606; legacy and rolled_back (legacy-parse row, co0141760): plain, through its `pairs` entry.
  - **source_row 5671** (the one open needs-review pair, nm0239470, credited `RICHARD DUBOIS`): no link in all four states; `credit_id_is_never_link('nm0239470')` and `('nm0230800')` are true in legacy and rolled_back; while live, `/oscars/name/nm0239470/` returns 404 (no linked credit).
  - **source_row 6429** (FilmId corrected from tt0169446 to tt0084185): in legacy and rolled_back `build_entity_url_from_id('tt0169446')` and `get_entity_url('tt0169446')` return '' and `get_title_visual_package('tt0169446')` returns `array()`; in live and frozen they return the normal URL and package.
  - **source_row 2111**: live and frozen: Samuel Goldwyn Productions → co0189536. Legacy (legacy-parse row, co0058013): plain; `credit_id_is_never_link('co0058013')` is true in legacy and rolled_back and false in live and frozen.
  - **source_row 3470**: mode `plain` with the single item "West Germany" in all four states (not a link-override member).
  - **source_row 526** (aligned): United Artists → co0026841, Thomas T. Moulton → nm0609771 in `live` and `frozen`. In `legacy`, on the legacy-parse row, 'Thomas T. Moulton' paired with its before-ID nm0481264 renders plain (guarded).
  - **source_row 1814** and **2564** in `live` and `frozen`, through `$render_linked_pipe` and through the title-page closure: 'Paul Webster' → `/oscars/name/nm0916990/` although nm0916990's display name is 'Paul Francis Webster' and nm0916986 is displayed 'Paul Webster'; 'Harry Revel' → nm0720779; 'Arthur Schmidt' → nm0772834 although nm0772831 is displayed 'Arthur Schmidt'.
  - `canonicalize_name_entity_id_for_label('nm0916990', 'Paul Webster')` returns 'nm0916990' while `master_is_ledger()`, and runs its existing lookup otherwise (stub call recorded).
  - `credit_pair_is_guarded('nm0429444', 'Radio Corporation Of America')` and `('nm0914249', 'Denham')` are true in legacy (label pairs) and false in live.
  - **An ambiguous-key fixture:** plain.
- **`tests/ledger-render-closures-runtime.php`** (CI, new; third critique: the hub closures were never exercised before production). It extracts, by variable name with the brace-matching slicer, the closures `$aat_clean_nominee_label`, `$aat_normalize_comparable_name`, `$aat_is_department_credit_label`, `$aat_build_entity_url`, `$aat_resolve_entry_name_link`, `$aat_enrich_winner_entry_links` (with the closures it `use`s), `$aat_build_person_link_items`, `$aat_render_hub_text_link` and `$aat_render_pipe_links` from `templates/hub-page.php`, and `$normalize_comparable_name`, `$build_entity_url`, `$render_linked_pipe` and `$resolve_title_nominee_display` plus the title-page nominee line (`:1108-1110`) from `templates/entity-page.php`. It evaluates them against the same harnessed main-class methods and stubs as above (`build_entity_url_from_id` and `get_title_visual_package` extracted too, so the method-level never-link rule is exercised), and for **rows 965, 276, 8165, 9026 and 938** (plus 1575, 2111, 3470, 1814, 2564, 5671 and 526) in the **live, legacy and frozen** states renders: (a) the ballot credit line exactly as the `:2026` call site builds it, (b) the ballot title line (`:2021`), (c) the winner-circle people items (`:2839`), (d) the latest-winner name link and `person_url` through the enrich closure, and (e) the title-page nominee line; and for **row 6429** in legacy, (f) the enrich closure over a payload entry that carries a pre-built `film_url` for tt0169446 (as a pre-R1 rollup transient would) returns an empty `film_url`. It asserts the complete set of (anchor text → href) per case, that no output contains an anchor whose href ID is not in the ledger slot identities of that row (live, frozen) or not in its unguarded index pairs (legacy), and that row 526's winner-circle items hold no co0026841 person chip in any state. The expected anchors for these rows are hand-pinned in the test (identity sentinels, not counts).
- **`tests/ledger-credit-links-contract.php`** (CI) is a source check:
  - the three hub closures and `$resolve_title_nominee_display` call `resolve_credit_links(`, and the title-page line and the three hub closures render through `credit_items_html(`;
  - none contains `$ids[$index]` or `isset($ids[$index])`;
  - `$aat_render_pipe_links`' parameter list ends with `$row = null, $list = 'credits'`, and both ballot call sites pass `$ballot_row`;
  - `templates/` contains no `get_name_entity_link_by_label(` and no reference to pipeline classes;
  - `$aat_build_entity_url` and `$build_entity_url` call `credit_id_is_never_link(`;
  - `$resolve_title_nominee_display` calls `get_entity_display_name(` only inside a branch guarded by `count($nominee_parts) <= 1` and `credit_pair_is_guarded(`;
  - `get_name_entity_link_by_label` calls `credit_pair_is_guarded(` in step 1 and step 2, and step 2 contains the count guard;
  - the first statement of `canonicalize_name_entity_id_for_label` after its trims is the `master_is_ledger()` early return;
  - `templates/entity-page.php`'s `$render_linked_pipe` calls `credit_pair_is_guarded(`;
  - `aat_search_entities` calls `credit_pair_is_guarded(`;
  - `assets/js/academy-awards-table.js` has no `'Lunara ' + (idx + 1)`, reads `credit_items`, `title_items` and `entity_names`, and `renderFilmCell` renders `title_items` before its index fallback;
  - `build_entity_url_from_id`'s first statement after the trim calls `credit_id_is_never_link(`, and `get_title_visual_package` calls it after its regex check;
  - `templates/hub-page.php`, `templates/entity-page.php` and `templates/table-display.php` contain `get_entity_url(` and `build_entity_url_from_id(` only inside the bodies of `$aat_build_entity_url` and `$build_entity_url` (the admin templates are exempt), and `table-display.php` defines `$aat_build_entity_url`;
  - `$aat_enrich_winner_entry_links` reads `$entry['film_url']` only inside a branch guarded by `$film_id === ''`, and the best-picture loop reads it only when `$fid === ''`;
  - `$aat_build_person_link_items` and the `person_url` assignment of `$aat_enrich_winner_entry_links` test the person-ID pattern;
  - `ajax_get_awards_datatable` adds `credit_items` and `title_items` after the pinned SELECT and calls the token-masking helper only behind `! AAT_Ledger::master_is_ledger()`.
- **Deriver side** (`ledger-projection-runtime`): V11 holds; a mutation fixture (`?|nm1,nm2` with two labels) raises `positional_pairing_unsafe`; the three guard lists equal their recomputation from the legacy parse and the local legacy simulation; `pairs` contains `[nm0481264, fold('Thomas T. Moulton')]` (row 526's wrong_id before-pair, `co0026841|nm0481264` → `co0026841|nm0609771`), `[co0141760, fold('United States Army Pictorial Service')]` (row 1575) and, for every unresolved needs-review item of the bundle, its (ID, folded slot label) pairs; `label_pairs` contains `[nm0429444, fold('Radio Corporation of America')]` and `[nm0914249, fold('Denham')]`; `never_link` contains co0058013, nm0239470 and tt0169446 and no ID with `linked_credits > 0` (or, for a tt, that a ledger title slot carries); link-override membership follows §4.4.6 (3470 and 1575 absent; 938, 9026, 2112 and 4746 present as misaligned; 5671 present through masking).
- **Live probe** `tests/tools/verify-credit-links-live.sh <state>` (anonymous GETs; Python anchor parser). **Its cases are generated** by the bundle builder into `tests/fixtures/ledger/live-probe-cases.json` (third critique: revision 3's probe checked only anchors whose text was the credited label, so it passed on every defect in §1):
  - **pages** (generated, fourth critique: revision 4 never visited ceremony pages 29, 55 or 67): the fixed list `/oscars/`, `/oscars/ceremony/12/`, `/oscars/ceremony/6/`, `/oscars/ceremony/69/?ledger=full`, `/oscars/category/special-award/`, `/oscars/category/scientific-or-technical-award-class-iii/`, `/oscars/title/tt0037076/` (Minstrel Man), `/oscars/title/tt0043014/` (Sunset Blvd.), `/oscars/title/tt0145781/` (row 1575), `/oscars/title/tt0036868/` (row 2111), `/oscars/title/tt0031385/` (row 938), `/oscars/title/tt0268126/` (row 9026), `/oscars/title/tt0027532/` (row 526), `/oscars/title/tt0050746/` (row 3470); then, over the rows that carry a never-link ID in the legacy parse, the **ceremony hub and category hub of every row whose never-link ID is a title** (today ceremonies 29, 55 and 67 and their categories), plus a deterministic greedy cover (ordered by pages covering most uncovered IDs, ties by URL) so that every never-link ID **and every before-ID of an ID correction** (the `pairs` IDs: wrong_id and retired_id alike) occurs in the scope of at least one ceremony hub, one category hub and, when any of its rows has a title, one title page (40 IDs with PR #39's overlay: 25 ceremony hubs, 13 category hubs and 23 title pages, computed this session); then the first title page of every row with an unresolved needs-review pair. This replaces revision 4's "first title page of every wrong_id row, at most 40", which PR #39's 269 ID-correction rows (211 distinct first title pages) would have truncated. Deduplicated. The builder refuses with `probe_cases_over_cap` when the list exceeds 150 pages, rather than truncating it silently; the probe paces itself at one GET per second;
  - per page, the generated file holds its **scope** (the rows it renders: a title's rows, a ceremony's rows, a category's rows), the **allowed IDs** (the linked ledger identities of those rows) and, per allowed ID, the **allowed texts** (comparable-name keys, computed with the templates' `$aat_normalize_comparable_name` rule after the `$aat_clean_nominee_label` prefixes: the display name, every linked credited alias, and in `legacy`/`rolled_back` also the legacy-parse credited labels of that ID's unguarded aligned pairs);
  - **P1** (every state): no anchor points to a `never_link` ID, whatever its text;
  - **P2** (every state): no anchor's (ID, folded text) is in `pairs` or `label_pairs`;
  - **P3** (every state): every `/oscars/name|company/` anchor points to an allowed ID of the page;
  - **P4** (every state): every such anchor's text key is an allowed text of its ID; a multi-link item's anchors carry display names, which are allowed;
  - **P5** (every state): every `/oscars/title/` anchor inside an element with class `aat-hub-film-grid`, `aat-ceremony-ballot-rows` or `aat-category-ceremony-row` (`templates/hub-page.php:1759`, `:2094`, `:3049` and `table-display.php:387`; `hub-page.php:2017`; `:2823`) points to a title-slot ID of a scope row, and no such card's poster `src` belongs to a never-link title;
  - **`live`, additionally:** at least one anchor "Jean Hersholt" → `/oscars/name/nm0380965/`; the ceremony-69 full ballot links both nm0001053 and nm0001054 next to "Roderick Jaynes"; `/oscars/title/tt0031385/` links "A. W. Watkins" → nm0914249 and prints "Denham" as text; `/oscars/title/tt0268126/` prints "Donald Kaufman" as text; `/oscars/title/tt0050746/`'s ceremony ballot line and the International Feature hub keep the text "West Germany";
  - every page's `aat-dataset` meta stamp is printed next to its result, so a stale edge copy is identifiable (§12 R2);
  - it exits 0 or 1. R1 runs it with `legacy`, R2 with `live`. Against fixture HTML saved from live pages it must exit 1 on each of the five live defects (tt0145781, tt0036868, tt0031385 and the Class III hub of §1, and `/oscars/ceremony/55/` with its tt0169446 highlight, P1), and 0 on fixture HTML rendered by the R1 code (U08 acceptance).

### 5.6 Legacy link guard (critic: flagged and wrong-ID pairs linked before the swap; third critique: bypasses)
- **Data.** `data/ledger/legacy-link-guard.json` (§4.4.10): `{schema: "lunara-ledger-legacy-guard/2", fold_policy, pairs: [[imdb_id, folded_label]…], label_pairs: [[imdb_id, folded_label]…], never_link: [imdb_id…]}`.
- **Load.** The version check copies it into the option `aat_ledger_legacy_link_guard` as one map: `'p:' . imdb_id . "\x1f" . folded` for both pair lists, and `'n:' . imdb_id` for never-link IDs (§4.6.1). The option is autoloaded only while the guard is on (`legacy`, `rolled_back`); F2 turns autoload off on a forward swap and back on on a restore to the pre-ledger data (§4.6.7). `AAT_Ledger::legacy_link_guard()` returns that map once per request (static cache), or an empty map when `get_option` is undefined or `master_is_ledger()` is true (it then does not read the option at all). Measured scale with PR #39's overlay: 33 never-link IDs, the wrong_id/retired_id and needs-review pairs and the first-label-wins label pairs, a few hundred short keys in total (the manifest carries the counts).
- **Check.**
  - `public function credit_id_is_never_link($imdb_id)` returns false when `AAT_Ledger` is absent or `master_is_ledger()` is true; otherwise `isset(guard['n:' . strtolower(trim($imdb_id))])`.
  - `public function credit_pair_is_guarded($imdb_id, $label)` returns false when `AAT_Ledger` is absent or `master_is_ledger()` is true; otherwise `credit_id_is_never_link($imdb_id) || isset(guard['p:' . strtolower(trim($imdb_id)) . "\x1f" . AAT_Ledger_Text::fold($label)])`.
  - Both are main-class methods, docblocked, after `ajax_clear_data()`, and on the public-method harness list (`tests/category-names-runtime.php`).
- **Where it applies.** `resolve_credit_links` (index items); **`build_entity_url_from_id()` (never-link), and therefore `get_entity_url()`, every template, every cached payload URL, the portal and the search feed**; `get_title_visual_package()` (never-link titles get no poster); the DataTables payload (never-link and guarded tokens become `?`, §4.9 item 15); the two entity-URL closures (never-link, defence in depth); `$render_linked_pipe`, the title-page relabel (both the credited and the display label), the single-ID branch of `$aat_resolve_entry_name_link`, `get_name_entity_link_by_label` steps 1 and 2, `aat_search_entities`; in the theme (R1T), `lunara_oscars_pair_is_guarded($id, $label)` in `inc/oscars-family.php` calls the reader's `credit_pair_is_guarded` when `method_exists`, else returns false, and is consulted by the theme consumers in §5.3, the two search title matches included.
- **Cost.** A few hundred keys in one option, autoloaded only while the guard is on, plus one array lookup per URL built. The collateral count (correct links suppressed because they share a key) is published in the manifest and the R1 report.
- **Residual window.** Rows a production admin edited through the legacy correction lanes since the last bundled import are not in the guard; those edits show up in the dry run's difference lists (§4.6.6), and the remediation loop turns them into corrections or accepted drift. The R1 owner report states that the legacy state lasts until R2.

---

## 6. Read API `lunara-ledger/v1` (R3; `/status`, `/status/report`, `/license` from R1)

### 6.1 Boundaries
**Reads:**
- only `aat_ledger_*` through `AAT_Ledger::table()`;
- for `embed=reviews|media` only: `wp_posts`/`wp_postmeta` (review post type and meta key from `get_review_post_type()` / `get_review_imdb_meta_key()`, `main:15676-15681`) and `aat_posters`.
- **Every embed query requires `p.post_status = 'publish' AND p.post_password = ''`** (first critique). The existing public join pins publish at `tests/oscars-read-api-contract.php:74`.

**Never:**
- writes, imports, rebuilds or remote HTTP;
- reads the CSV or the current user;
- emits Wikidata-derived years, QIDs or birth years (decision 2).

**When the state is not `live`:** `/status` returns `ready: false` with `ingest.state`, and data routes return 503 `ledger_unavailable` with `Retry-After: 300` and `no-store`. Because no data response carries `stale-if-error` (§6.5), an edge that cached an unversioned 200 before a freeze or a class-D rollback serves it for at most `s-maxage` + `stale-while-revalidate` = 600 s, then the 503 (fourth critique: `stale-if-error=86400` let the edge serve rolled-back data for a day).

### 6.2 Routes
Every route: `'methods' => 'GET'`, `'permission_callback' => '__return_true'`. Path IDs are lower-cased.

| Path | Returns | Query params |
|---|---|---|
| `/status` | ready, api, software {plugin_version}, dataset {version, token, stamp, swapped_at, source {file: "data/oscars.csv", sha256, rows}, corrected {sha256, rows}, corrections {entries, cells, additions, by_reason}, needs_review {items, unresolved}, attribution, license}, counts, checks[], ingest (§4.8), cron, abuse, explorer {redirect_health} (from R6) | none |
| `/status/report` | the last import report (public lists) | none |
| `/license` | attribution plus the DLu/oscar_data BSD-2 notice verbatim | none |
| `/schema`, `/schema/{name}` | the schema index and allowlisted documents | none |
| `/ceremonies`, `/ceremonies/{n}` | all ceremonies; detail with previous/next and categories[]; `embed=nominations` | embed, fields, v |
| `/categories`, `/categories/{slug}` | all categories with names_as_given, aliases and stats; detail with ceremonies[]; `embed=latest` = every row of the latest ceremony | class, embed, fields, v |
| `/nominations` | a filtered list | FILTERS, sort, limit, offset, cursor, fields, embed, v |
| `/nominations/{id}` | one record | fields, embed, v |
| `/nominations/by/{title\|person\|company\|category\|ceremony\|class\|decade}` | server-side groups | FILTERS, sort, limit, offset, v |
| `/facets` | disjunctive counts for class, category, ceremony, decade, winner, official | FILTERS, v |
| `/titles/{tt}`, `/people/{nm}`, `/companies/{co}` | an entity with nominations (≤200, then `meta.truncated`); 404 when `linked_credits = 0` | fields, embed, v |
| `/search` | typeahead over display names, linked aliases and reference names | q (required), kind, limit, v |
| `/corrections` | the corrections ledger, cell corrections and appended rows (`scope`); `evidence` and `verification` are the redacted strings of the bundle (§4.14 item 6) | nomination, reason, scope, limit, offset, v |

### 6.3 Parameters (`AAT_Ledger_Query::parse()`, pure)
- **FILTERS:**
  - ceremony (`N` or `N-M`), year (≤10 labels), year_from, year_to, decade (`^(19|20)\d0s$`), class (≤8);
  - **decade membership** is `floor(film_year_start / 10) × 10` of the ceremony (§4.3 `aat_ledger_ceremonies`), the same rule the hubs use (`intval` of the year label, `main:2914-2935`; §1): the 1920s are ceremonies 1–3 and the 1930s 4–12. One generated fixture, `tests/fixtures/ledger/decades.json`, is asserted by the query runtime, the facets runtime and a hub test (third critique: revision 3's U15 put ceremony 3 in the 1930s);
  - category (≤10 slugs; `production-design` → `art-direction` and `sound` → `sound-mixing`, as at `main:5639-5647`), winner, official;
  - ID filters:
    - `tt`, `nm`, `co`: comma lists ≤10, OR within a param, AND across params;
    - **`imdb`**: a comma list ≤4 of any tt/nm/co, AND. Each ID is its own semi-join over title slots or **linked** identities (`review_flag = ''`). This is the explorer's pivot filter.
    - ID pattern: `^(tt|nm|co)\d{7,10}$`, the same as the codec.
- **q:** 2–80 characters, ≤4 terms each ≥2 characters, AND. Each term is folded with `AAT_Ledger_Text::fold()` (§4.4.8) and matched against `nomination_text`.
- **sort** (on `/nominations`), from a fixed `SORTS` map:
  - `-ceremony` (default), `ceremony`, `category,-ceremony` (the alias `category` canonicalizes to it), `category,ceremony`, `id`, `-id`;
  - on `/by`: `-wins`, `-nominations` or `name` for entity dimensions; `-ceremony` or `ceremony` for time; `order` for category and class.
- **Paging:** limit 1–200 (search 1–20); offset 0–20000; `cursor` only for `sort=id|-id`, bound to the token, with 409 `ledger_cursor_expired` when stale.
- **fields:**
  - nominations: id, source_line, added, ceremony, year_label, category, winner, official, credit_line, detail, note, citation, corrected, group, imdb_ids, titles, credits, corrections;
  - entity routes: imdb_id, kind, name, name_source, aliases, stats, first_ceremony, first_year_label, imdb_url, url, nominations (plus review and poster with their embeds).
- **embed:** titles, credits, corrections, links, entities, reviews, media, none (plus `nominations` and `latest` on details).
- **v** (16 hex): excluded from the canonical key; selects the immutable policy only when it equals the current token.
- **Strictness:**
  - unknown keys (including `_fields`, `_embed`, `_envelope`, `_jsonp`, `_method`) → 400 `ledger_unknown_param`;
  - array values → 400; a raw query string over 1,024 bytes → 400;
  - JSONP disabled for the namespace.
- **Canonical query:** keys sorted, values normalized. Empty intersections return an empty result with no SQL.

### 6.4 Records and schemas
**Envelope:** `{data, meta {api, token, dataset_version, total?, returned?, limit?, offset?, sort?, next_cursor?, dimension?, query?, fields?, embed?, truncated?}, links {self, next?, prev?, schema?, license}, included?}`, plus `Link` headers.

**Nomination:**
- `{id, source_line (integer|null; null for appended rows), added, ceremony, year_label, category {id, slug, canonical, as_given, label (dated, format_category_display(canonical, ceremony)), label_modern, class}, winner, official, credit_line, detail, note, citation, corrected, group {nominations, winners}, imdb_ids {titles, people, companies}, titles[], credits[]}`.
- `imdb_ids` lists linked IDs only.
- Title slot: `{ordinal, imdb_id|null, as_credited, title|null, detail, matches_canonical}`.
- Credit slot: `{ordinal, as_credited, matches_canonical, identities[{imdb_id, kind, name, review_flag}]}`.
- **`review_flag`** is `null` or `"needs_review"` (decision 5). The schema documents that a consumer must not link an identity whose `review_flag` is non-null. The explorer renders such a credit as plain text.
- `matches_canonical`: true or false for a titled slot with an ID, or a credit slot with exactly one linked identity, using `fold(as_credited) == fold(canonical)`; null otherwise.
- Identities are never filled from a neighbouring slot. There is no tie flag.

**Entity:** `{imdb_id, kind, name, name_source, aliases[], stats {nominations, wins, official_nominations, first_ceremony, last_ceremony, categories}, first_ceremony?, first_year_label? (titles), imdb_url, url, nominations[], review?, poster?}`.
- There is **no `external` block** and no release year, Wikidata QID or birth year (decision 2).
- `imdb_url` is built from the validated ID.

**Schemas:**
- 20 Draft 2020-12 documents under `schemas/lunara-ledger/v1/` (shipped).
- `$id` = `https://lunarafilm.com/wp-json/lunara-ledger/v1/schema/{name}`, with relative `$ref`s. Every object sets `additionalProperties: false`.
- They are copied from the validated drafts, with these edits:
  - `source_line` = source_row + 1 or null (the "always id + 1" text is removed);
  - `added`, `matches_canonical` and `review_flag` added;
  - `status-response` gains `ingest`, `cron`, `abuse`, `software`, `attribution`, `license` and `dataset.stamp`, and loses `last_import`;
  - `license-response` added;
  - the entity schema drops `external`, `release_year` and `wikidata_*`, and gains `first_ceremony`, `first_year_label` and `imdb_url`;
  - ID patterns widened to `\d{7,10}`; entity `fields` added.
- Fixtures are regenerated from the **deriver** (`tests/tools/ledger-dry-run.php --export-fixtures`), never from `data.sql.gz`.

### 6.5 Caching and HTTP (first critique, blocker 3)
**Token.** `token = substr(sha256(live_meta_stamp|AAT_VERSION|AAT_Ledger::API_REVISION|home_url('/')|base_slug), 0, 16)`, with `live_meta_stamp` read from the live `aat_ledger_meta` row in the same request that reads the data (§2, §4.7). A response built from post-swap tables therefore always carries the post-swap token, and a `v=` equal to the old token can no longer select the immutable policy for new data (third critique: stamp written after the RENAME).
- Every plugin release changes `AAT_VERSION`, so every release rotates the token, the `v=` URLs, the ETags and the object-cache keys. A serializer, query or label fix can never be stuck behind a 30-day immutable edge entry.
- The cost is a cold edge after each release, which warming mitigates.

**Contract** (`tests/ledger-token-contract.php`):
1. It evaluates `AAT_Ledger_Service::token()` with two different `AAT_VERSION` stub values and the same stamp, and asserts the tokens differ. It then does the same for a stamp change and an `API_REVISION` change.
2. A source check: `token()`'s body contains `AAT_VERSION` and `AAT_Ledger::API_REVISION`.
3. `tests/fixtures/ledger/api-source-hash.json` holds `{plugin_version, sha256, parts[]}`. The hash covers everything that shapes an API response (second critique: collaborators outside the five files):
   - the files `includes/class-aat-ledger-{api,query,store,serializer,service}.php` and `includes/class-aat-ledger.php` (fold, table names, stamp, `API_REVISION`);
   - the bodies of these main-class methods, extracted by name with the brace-matching slicer the category-names harness uses: `format_category_display` (`main:5614`), `ordinal` (`main:5529`), `build_entity_url_from_id` (`main:7802`), `infer_entity_type_from_id` (`main:1777`), `get_entity_base_slug` (`main:1722`), `get_entity_base_url` (`main:1731`), `get_ceremony_url` (`main:1994`), `get_category_url` (`main:2000`), `get_review_post_type` (`main:15676`), `get_review_imdb_meta_key` (`main:15680`) and `get_poster_img_html_for_title` (`main:14705`);
   - every `schemas/lunara-ledger/v1/*.schema.json` file.
   A missing method name fails the test (so a rename cannot silently drop coverage). **The fixture must describe the current version exactly** (third critique: a stale fixture disarmed the check): the test fails when `fixture.plugin_version !== AAT_VERSION` ("regenerate the API source hash for this version") and when the current hash differs from `fixture.sha256` ("API sources changed without a version bump"). Every plugin release regenerates the fixture with `php tests/tools/api-source-hash.php --write` in the same commit as the version bump, whether or not the hashed sources changed; from R3 on, a later API edit merged without a bump therefore always fails CI. Before R3 the fixture does not exist and the test is skipped with a printed reason (the API routes of R1 and R2 are `/status`, `/status/report` and `/license` only, all `max-age=15, s-maxage=60`).

| Case | Cache-Control |
|---|---|
| Data 200, unversioned | `public, max-age=60, s-maxage=300, stale-while-revalidate=300` (no `stale-if-error`, which RFC 5861 lets an edge use to serve the last 200 instead of the 503 of a non-live state) |
| Data 200 with `v` = token (not reviews or media embeds) | `public, max-age=604800, s-maxage=2592000, immutable` |
| `/status`, `/status/report`, `/license` | `public, max-age=15, s-maxage=60` |
| Schema | `public, max-age=3600, s-maxage=3600` |
| 400 / 404 | `public, max-age=60, s-maxage=300` |
| 409 / 429 / 503 | `no-store` |
| A `wordpress_logged_in_*` cookie is present | `private, max-age=60` |

- **Object cache:** group `aat_ledger`, key `{token}:{route_id}:{md5(canonical)}`, TTL 1 day or 7 days for hot keys. Without a persistent object cache, only hot keys are stored, as transients `aat_ledger_{token}_{name}`. Nothing is ever deleted.
- **Warming:** the API listens to `aat_ledger_swapped` and to the first request after a version change (`aat_ledger_bundle_checked` update). It schedules `aat_ledger_warm` for the new token. The loader has no API dependency.
- **ETag:** `"{token}-{md5_16(route_id?canonical|extra)}"`, computed before any SQL. `extra` = `wp_cache_get_last_changed('posts')` only for reviews or media embeds.
- **Conditional requests:** If-None-Match takes precedence (weak compare, lists, `*`); If-Modified-Since applies only without it and without `extra`. A match gives a 304 with no body via `rest_pre_serve_request` at priority 20.
- **Last-Modified:** `max(swapped_at, aat_ledger_code_seen_at)` (§4.6.1). There is no hand-edited release timestamp.
- **CORS (namespace only; decision 11):**
  - `rest_pre_serve_request` at priority 9 flags the request;
  - the `http_origin` filter returns '', so core's credentialed echo (`rest-api.php:776-793`) is skipped;
  - priority 11 sends `Access-Control-Allow-Origin: *`, `Allow-Methods: GET, HEAD, OPTIONS` and `Max-Age: 86400`;
  - **request headers:** a `rest_allowed_cors_headers` filter adds `If-None-Match` and `If-Modified-Since` to core's list (§1: core allows only Authorization, X-WP-Nonce and three Content-* headers) for requests whose route starts with `/lunara-ledger/v1` (the `$request` argument exists since WordPress 6.3; without it the two headers are added unconditionally, which is harmless), so a browser app's conditional revalidation passes its preflight (fourth critique);
  - ETag, Last-Modified, Link and X-Ledger-Token are exposed through the `rest_exposed_cors_headers` filter, for the namespace only;
  - `Allow-Credentials` is never sent.
- **JSON output:** `rest_json_encode_options` adds unescaped slashes and unicode for the namespace.

### 6.6 Rate and abuse limits: log-only (decision 9)
- Counters run only for requests that will reach SQL: after validation, the 304 check and the object-cache lookup. They require a persistent object cache and skip private or reserved IPs.
- Key: a salted sha1 of `REMOTE_ADDR` per minute. No raw IP is stored.
- Buckets: `db` counts to 60 per minute and covers `/search`; `q` counts to 20 per minute and covers only `/nominations?q=`. The explorer HTML route (§7.1) also counts in `db` when it will run SQL.
- **Mode `log` (the only mode in this train):** over-limit requests are served normally. The minute's `would_throttle` count and three distribution figures are rolled up hourly by the heartbeat into `/status.abuse {mode: "log", window_hours: 24, requests_counted, would_throttle, distinct_clients_per_minute_p50, top_client_share, private_addr_share, daily: [{date, requests_counted, would_throttle, distinct_clients_per_minute_p50, top_client_share, private_addr_share}…7]}`. The seven UTC-day buckets are kept in a non-autoloaded option and rotate at midnight UTC, so U28's seven-day precondition is read from `/status` alone (second critique: 7-day evidence).
  - `top_client_share` near 1.0 while `distinct_clients_per_minute_p50` is 1 means `REMOTE_ADDR` is an edge address. Enforcing would then throttle everyone at once.
- The 429 path (`Retry-After`, `no-store`) exists behind `apply_filters('aat_ledger_rate_mode', 'log')`, which is only ever `log` in this train. Enforcement is U28, a later release gated on that evidence.

### 6.7 Security
- **One SQL call site:** `AAT_Ledger_Store::select()`, always through `$wpdb->prepare()` when args exist, with a runtime assert of no `%` otherwise.
  - No `->query(`, no `SELECT *`. ORDER BY only from `SORTS`.
  - Table names only from `AAT_Ledger::table()` plus the allowlisted post and poster tables.
- **Forbidden tokens** in the five API read files (`class-aat-ledger-{api,query,store,serializer,service}.php`):
  - `get_tmdb_api_key`, `get_omdb_api_key`, `get_omdb_poster_api_url`, `get_omdb_data_for_imdb_id`, `build_omdb_integrity_audit`, `get_tmdb_data_for_imdb_id`, `get_tmdb_person_data_for_imdb_id`, `get_tmdb_person_imdb_id`, `resolve_screenplay_nominee_ids_from_tmdb`, `get_title_visual_package`, `get_person_visual_package`, `get_lunara_integrity_summary`;
  - `api_key`, `apikey`, `poster_api_url`, `AAT_TMDB_API_KEY`, `AAT_OMDB_API_KEY`, `aat_tmdb_api_key`, `aat_omdb_api_key`;
  - `wp_remote_`, `download_url`, `AAT_BUNDLED_CSV_PATH`, `SplFileObject`, `fgetcsv`;
  - `rebuild_reporting_tables`, `ensure_projection_data_available`;
  - `update_option`, `delete_option`, `setcookie`, `wp_create_nonce`, `check_ajax_referer`, `current_user_can`, `is_user_logged_in`, `nocache_headers`;
  - `wikidata`, `birth_year`, `release_year`;
  - the legacy master and `aat_award_*` table names.
  - The importer, source and `class-aat-ledger.php` files are not scanned, because they legitimately write options and parse the CSV.
- **Status payload rule:** a runtime check on a failed-state fixture asserts no value matches `#^/|[A-Za-z]:\\\\|wp-content|wp_[a-z]|aat_ledger_|_api_key#` or contains database error text.
- Closed schemas plus CI validation of fixtures: a new key, such as an injected `api_key`, fails the build.
- Admin AJAX endpoints and builder `guard()` endpoints are untouched, and the three nopriv reads (`main:626-641`) stay.

### 6.8 PHP structure
- `includes/class-aat-ledger-api.php`: `AAT_Ledger_Api` (NS, route registration, 304 serving, limiter in log mode, warm) and `AAT_Ledger_Http` (pure: cache_control, etag, not_modified). It uses `AAT_Ledger::API_REVISION` and `AAT_Ledger::DERIVER_REVISION` and defines no revision constant of its own.
- `class-aat-ledger-query.php` (pure).
- `class-aat-ledger-store.php` (the only SQL).
- `class-aat-ledger-serializer.php` (pure; the collaborators `format_category_display`, `build_entity_url_from_id`, `get_ceremony_url`, `get_category_url` and `ordinal` are injected as callables; their bodies are covered by the API-source hash, §6.5).
- `class-aat-ledger-service.php` (facade: `instance()`, `token()`, `ready()`, `execute()`, `status()`, `license()`, `schema()`, `nominations()`, `nomination()`, `nominations_by()`, `facets()`, `ceremonies()`, `ceremony()`, `categories()`, `category()`, `entity()`, `search($q, $params)`, `corrections()`, `warm()`).
- Main-class edit: `public function ledger()` goes between `get_instance()` (`main:67-72`) and the `get_table_name()` docblock (`main:74`), outside every pinned slice.

### 6.9 Query plans
The design's EXPLAIN table (MariaDB 10.11: 0.2–30 ms warm) holds for this DDL. The index column sets match `schema.sql`'s: (ceremony, category, winner), (category, ceremony) and (winner, ceremony) on nominations; (imdb_id, nomination_id) on title slots and identities. It is re-verified locally against the tables the U04 gate builds (`tools/verify-ledger-api.php --explain`).

### 6.10 Standalone app contract (LUNARA-HUB; decision 11)
- **Both call paths are supported.** Browsers may call the API directly (CORS `*`, no credentials). **Recommended** in `docs/api/LEDGER-API.md`: the Express server fetches `/wp-json/lunara-ledger/v1/…` server-side. That request carries no Origin header, so it hits the edge cache and keeps browser traffic off the origin.
- The app revalidates with If-None-Match (allowed in CORS preflights, §6.5) and polls `/status` every 5 minutes; a token change means a dataset or code change.
- **Discard rule** (fourth critique): when `/status` answers `ready: false`, or its `dataset.token` differs from the token a cached response carries (`meta.token`, `X-Ledger-Token` or its `v=`), the app discards every cached `v=` response and every cached unversioned response of the old token, and serves no cached data while `ready` is false. `docs/api/LEDGER-API.md` states the rule, and that `v=` responses are immutable for 30 days at the edge, so an app that keeps using an old `v=` URL keeps getting the old data. The explorer already adopts a new `meta.token` (§7.4).
- **Full sync:** `sort=id&limit=200` following `next_cursor` at ≤1 request per second; a 409 means restart.
- TypeScript types are generated from `/schema/*`. No API key exists or is needed.
- The docs reproduce the attribution line and the BSD-2 notice.

---

## 7. Explorer "Oscar Ledger Explorer" (R4 = E1; R6 = E2; R7 = E3; decision 13)

### 7.1 Route lifecycle (`includes/class-aat-ledger-explorer.php`; `AAT_Ledger_Explorer::init()` = add_action/add_filter only)
- **Rewrite:** `^{base}/explore/?$` → `index.php?aat_explorer=1`, at `'top'`, on `init` priority 9. It is **always registered**. The main class's version-gated flush at `init` priority 10 (`main:1693-1705`) persists it; a version bump is mandatory.
- **Query var:** `aat_explorer`.
- **Main query:** `posts_pre_query` returns `array()` for the main explorer query.
- **Status and redirects:**
  - `pre_handle_404` returns true for the route.
  - `template_redirect` at priority 0 sets `is_404 = false`, `is_page = true` and status 200, and **never** calls nocache. This mirrors `main:3048-3055`.
  - The route does its own trailing-slash 301 and param-canonicalization 302; unknown params are carried through. `redirect_canonical` returns false on the route.
  - Service not ready (state not `live`): a 503 notice with `nocache_headers()`, `Retry-After: 300` and a link to `/oscars/ceremonies/`.
- **Template:** `template_include` → `templates/explorer-page.php`. **Title:** `pre_get_document_title` at priority 20, "Oscar Ledger Explorer".
- **Body class:** `aat-ledger-explorer-page`, **not** `aat-shell-page`.
- **Robots:**
  - `wp_robots` gives noindex,follow on filtered states and on `pg > 1`, with a self-canonical in canonical order.
  - **robots.txt** (first critique): the `robots_txt` filter (priority 20; none exists today, §1) appends, for the entity base slug `{b}` (default `oscars`):
    ```
    Disallow: /{b}/explore/*pg=
    Disallow: /{b}/explore/*imdb=*%2C
    Disallow: /{b}/explore/*imdb=*,
    ```
    Multi-ID URLs are written with `%2C` (both `rawurlencode` and `URLSearchParams` encode commas); the literal-comma line covers hand-typed URLs.
- **Cost bound:** the HTML route counts in the log-only `db` bucket (§6.6) whenever it will run SQL.
- **Bloat dequeue:** the guard at `main:6172` is extended with `&& !$this->is_explorer_request()`.
- **Main-class additions after `ajax_clear_data()`:**
  - `public function is_explorer_request()` = `(string) get_query_var('aat_explorer') === '1'`;
  - `public function get_explorer_url($filters = array())`.

### 7.2 URL grammar
Canonical order, defaults omitted.

| Param | Values and rules |
|---|---|
| `ceremony` | 1..max; setting it clears `decade` |
| `decade` | present in facets; setting it clears `ceremony` |
| `class` | class codes; dropped when `category` is set |
| `category` | canonical slug; aliases are canonicalized |
| `winner` | `1` |
| `imdb` | ≤4 tt/nm/co, `\d{7,10}`, lower-cased, sorted by kind then number, AND |
| `group` | ceremony (default), category, title, person, company |
| `sort` | per-group allowlist |
| `pg` | ≥2 (WordPress reserves `page`) |
| `q` | ≤80 characters; it only resolves entities; an ID in q moves into `imdb` |

- None of these names collides with a core public query var or a site CPT or taxonomy query var. The list (`movie`, `person`, `review`, `journal`, `lunara_studio`, `lunara_director`, `lunara_review_year`, `oscar_pick_category`, `oscar_fact_category`, `journal_type`, `journal_section`, `journal_topic`) is pinned in a test.
- `normalize_state()` is pure, idempotent and mirrored by the JS.
- Transition rules:
  - a filter change resets pg;
  - ceremony and decade are exclusive;
  - a category implies its class;
  - a group change resets sort;
  - a 5th ID is refused with a notice;
  - Clear keeps group and sort.

### 7.3 Page anatomy and render rules
- **Hero:** full-bleed and typographic. An atmospheric gradient (gold radial glow over ground-to-navy-deep) and grain are drawn in CSS: an inline SVG `feTurbulence` data URI at 7 %, percent-encoded with no braces, plus a perforation hairline. **No image.** The H1 is the LCP element.
- **Hero stats** ("N nominations · M wins · K ceremonies") come from the live status counts, never from literals.
- **Rail:** a GET form containing:
  - the typeahead (an ARIA 1.2 combobox);
  - a hidden `imdb`;
  - the group-by segments;
  - a `<details class="lle-filter-panel" open>` filter panel, **server-rendered open** (first critique), so without JS it is open at every width. Its first child after `<summary>` is one inline script, `<script data-jetpack-boost="ignore">(function(d){if(d&&d.tagName==='DETAILS'&&window.matchMedia&&matchMedia('(max-width: 1023.98px)').matches){d.open=false;}})(document.currentScript&&document.currentScript.parentNode);</script>` (≤ 240 bytes, no data, the only executable inline script inside `#ledger-explorer`; valid flow content inside `<details>`). **`data-jetpack-boost="ignore"` keeps Jetpack Boost's deferred-JavaScript feature (enabled on the site, §1) from moving it to the end of `<body>`**; moved, its `parentNode` would be `<body>` and the panel would stay open on phones with no layout shift to reveal it (third critique). The `tagName` check makes a moved copy inert rather than wrong. It runs when the parser has read only the summary, before any field inside the panel exists and before anything after the panel is parsed, so there is nothing that could have been painted and then moved: on phones the panel is closed from the first frame (second critique: CLS). At ≥ 1024 px it stays open. The footer JS never toggles `open`, and a user's toggle is never overridden. No `Content-Security-Policy` header is sent today (§1); if one is added later, it must allow this script by hash.
- **Main column:** notices, chips, results, pagination, then the debrief (`.lunara-debrief-block`).
  - The results section carries `aria-busy="true"` while a fetch is in flight and `"false"` after.
  - `<p id="lle-status" role="status" aria-live="polite">` announces "1–25 of N nominations · M wins" after every update (first critique).
- **Rows:**
  - the dated category kicker, plus the as-given name when its fold differs;
  - Won or Nominated;
  - "Not an official nomination" and "Corrected" badges; "Added to the record" for appended rows;
  - a line per title slot and the credits line; a citation-only row (no title slots, no credit slots) leads with its citation verbatim instead, as the hub cards do (§4.10);
  - a `<details>` record plaque: ceremony, film year verbatim, category, class, result with the field count when winners > 1, status, credited, films, detail, note, citation, `#id · source line N` (or "appended record" when `source_line` is null) and corrections.
- **Credit and title modes** come from the API's `matches_canonical` and `review_flag`, so **no client-side fold** is needed:
  - `plain`: no linked identity, never a link. That includes any identity with `review_flag` set, whose plaque line reads "identity under review" and shows no ID link;
  - `same`: the canonical name, linked;
  - `alias`: the canonical name + "credited as X";
  - `group`: "Roderick Jaynes — Ethan Coen and Joel Coen".
- **Wording:**
  - year labels read "Films of 1932/33";
  - category headers read "Field: N nominations, M winners";
  - the copy never says "tie", "critics" or "box office";
  - class display labels (O3): Films (Title), Craft (Production), Scientific & Technical (SciTech), Honorary & Special (Special); Acting, Directing, Writing and Music are unchanged. URL values stay the dataset codes.
- **Links:**
  - **Pivot links, chip links, expand links and pagination links** add or change explorer state. All of them carry `rel="nofollow"` (first critique).
  - Links to `/oscars/title|name|company/` do not carry nofollow. They render when `AAT_Ledger::is_live()`, the same predicate under which those pages read corrected data.
- **Category headings** are title-cased by a PHP-only caser. The JS reads that text from the server DOM, so there is one implementation.
- **Posters:** only on film group cards and the film debrief. Local attachments only (`get_poster_img_html_for_title`, `main:14705`; the API `embed=media` returns the `medium` size with optional srcset). 64×96 on phones and 96×144 at ≥600 px, 2:3, lazy, with width and height. No TMDb or OMDb text or images anywhere.
- **Portraits** are off in v1 (decision 13).
- **Debrief (single entity):** canonical name, kind, IMDb ID (an external link built from the validated ID), "Also credited as", nominations, wins, official, first and last ceremony, categories. **No Wikidata row.**
- **Attribution:** a footer line on the explorer with the exact decision-10 text and a link to `/wp-json/lunara-ledger/v1/license`.

### 7.4 Data consumption
- The adapter (`includes/class-aat-ledger-explorer-data.php`) is the only file that knows service names. It calls `AAT_Ledger_Service::instance()` directly, with no filter.
- `available()` means the class exists and `ready()` is true.

| Explorer state | API call |
|---|---|
| group=ceremony | `/nominations?sort=-ceremony\|ceremony` |
| group=category | `/nominations?sort=category,-ceremony\|category,ceremony` |
| group=title, person, company | `/nominations/by/{group}?sort=-wins\|-nominations\|name` |
| pg | `offset=(pg-1)*25&limit=25` |
| list embeds | `embed=titles,credits,corrections` (+`media` on `by/title`) |
| facets | `/facets?{filters}` |
| typeahead | `/search?q=&limit=8`, debounced 250 ms, ≥2 characters |
| entity debrief | `/{titles\|people\|companies}/{id}?fields=imdb_id,kind,name,aliases,stats,first_year_label` |
| expand | `/nominations?{filters}&imdb=…&limit=50` |

- Every call carries `v={token}`.
- **Request budget:**
  - first load: 0 API calls;
  - filter change: ≤2 in parallel (+1 per new entity);
  - group, sort or page change: 1;
  - typeahead: ≤1 per debounce;
  - record details: 0.
- **Client rules:**
  - `fetch(url, {credentials:'omit'})` with no custom headers;
  - one AbortController per channel and a navigation sequence number;
  - an LRU of 60 URLs and a 12 s timeout;
  - on a `meta.token` change, adopt the new token, drop cached facets and announce the update through `#lle-status`.

### 7.5 JavaScript (`assets/js/ledger-explorer.js`)
- UMD, ES2019, no dependencies and no build step. `node --check` passes because there is no import or export.
- Modules: `state` (normalize, apply, toQuery, toApi, facetsKey), `view` (nominationRecord, groupRecord), `api`, `dom`.
- Boot runs only when `#ledger-explorer` exists and `fetch`, `URLSearchParams`, `history.pushState` and `<template>` are available.
- Boot JSON: `<script type="application/json" id="lle-boot">`, encoded with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`, ≤6 KB. All copy comes from PHP `__()` strings via the `aat_ledger_explorer_strings` filter.
- Rendering clones `<template>` skeletons that the PHP partials render once, filling `data-slot` hooks with `textContent` and `setAttribute`. Cloned pivot links keep `rel="nofollow"`.
- **Skeleton version** (third critique: cached HTML with new JS). `AAT_Ledger_Explorer_View::SKELETON_VERSION` (an integer, bumped whenever a partial's tag, class or `data-slot` set changes) is printed into `lle-boot` as `skeleton`. The JS carries its own `SKELETON_VERSION` constant. When they differ (an edge-cached page from an earlier release served with the new file under the old `?ver=`), boot stops before binding anything, so the server-rendered no-JS page stays fully working, and it logs one `console.info`. `tests/ledger-explorer-contract.php` asserts the two constants are equal in the tree and that `row.php` output and the `lle-tpl-row` skeleton match (render test 19).
- **Navigation:**
  - pushState plus synchronous control updates;
  - dim, not clear, after 150 ms, with `aria-busy`;
  - the results container keeps a min-height;
  - focus moves to the results heading on pagination;
  - `popstate` restores state; no `unload` listeners.
- **Error handling:** keep the old results, then show Retry and a plain link.

### 7.6 CSS (`assets/css/ledger-explorer.css`) and design mapping
- **Tokens:** only canonical `--lunara-*` tokens, with theme.json fallbacks (ground `#0a1520`, gold `#c9a961`, gold-light `#e0c481`, text `#fafbfc`, muted `#a8a8b8`). Never `--lunara-surface-radius`, `--lunara-shell-max` or `*-font-stack`.
- **Contrast** (measured by the explorer design): text 17.76, muted 7.85, gold 8.18, control border 3.68, focus 9.31.
- **Type:** Tiempos Text for body and labels and Tiempos Headline for H1–H4, both preloaded sitewide. H1 is `clamp(2.25rem, 1.55rem + 3.2vw, 4.75rem)`. Labels are ≥.75rem; inputs are 16 px.
- **Layout:**
  - the shell is `min(1320px, 100% - 32px)` (16 px gutters), `100% - 64px` from 1024 px;
  - ≥600: the 5 segments in one row;
  - ≥1024: a 300 px sticky rail plus results;
  - a container query at 720 px for the row grid;
  - no `100vw`.
- **Targets:** inputs and typeahead options 48 px; segments, chips, summaries, pagination and expand 44 px.
- **Motion and modes:** a `:focus-visible` ring; reduced-motion removes transitions; a forced-colors mapping; print hides controls.
- **Reused theme components:** `.lunara-debrief-*` (`style.css:740-910`), `.lunara-search-chip` (`:17965`), `.lunara-button`, `.lunara-button-ghost`.
- **Avoided classes:** `.aat-stat-number`, `.lunara-reveal`, the view-transition card classes, `aat-shell-*`.
- **Assets:**
  - the style handle `aat-ledger-explorer-styles` is render-blocking and not concatenated (`css_do_concat` and `jetpack_boost_async_style` return false);
  - the script `aat-ledger-explorer` loads in the footer;
  - both use filemtime versions;
  - the extended dequeue guard (§7.1) removes the portal bloat handles on the explorer.
- **Jetpack Boost critical CSS** (fourth critique: a release step must not depend on the owner's wp-admin "regenerate" click, theme `docs/SESSION-LOG.md:203`, `:227`):
  - the explorer owns its first paint the way the theme's ledger routes do (theme `inc/frontend.php:1271-1281`): `AAT_Ledger_Explorer` prints `<style id="aat-ledger-explorer-critical-css">` at `wp_head` priority 7, a small seed that fixes the shell width, the rail and results grid, the hero block height and the filter panel's closed height at ≤ 1023.98 px, copied from `ledger-explorer.css` (a contract asserts every seed declaration also exists in the stylesheet with the same value), and the stylesheet itself stays synchronous and unconcatenated (above);
  - whatever Boost's stored `<style id="jetpack-boost-critical-css">` block (theme `tests/journal-archive-payload-gate-runtime.js:62`) holds for the URL group the explorer falls into is stale by construction for a new route. The browser gate therefore **replays** one: the production block captured from `/oscars/ceremonies/` in the same run (or, offline, a committed fixture of equal-specificity `!important` rules from that block), injected before the plugin stylesheet, and asserts CLS ≤ 0.05 and that the seed-only and seed-plus-stale-block first paints differ by at most 1 px in the rail, results and hero boxes (the theme's own replay method, theme `docs/CHANGELOG.md:1655-1665`);
  - no release step regenerates Boost's critical CSS. If the live gate fails on CLS at R4, the step is **report and stop**, with the block's presence and size recorded, never "regenerate".

### 7.7 Budgets
| Metric | Budget |
|---|---|
| JS | ≤36,864 B raw |
| CSS | ≤28,672 B raw |
| Explorer HTML | ≤60 KB for 25 rows |
| Whole document | ≤175 KB |
| Boot JSON | ≤6 KB |
| API bytes per filter change | ≤10 KB gzip |
| **LCP** | ≤ **the measured baseline** of `/oscars/ceremonies/` under identical lab settings (Slow 4G, 4× CPU, 390 px), measured in the same browser-gate run, and ≤2.5 s absolute. The critic measured a render-blocking Jetpack concat bundle of 115,490 bytes gzip on that route; the baseline absorbs it, and the explorer must not be slower than an existing plugin route |
| CLS | ≤0.05 at 390 px and at 1280 px, JS on and off |
| INP | ≤200 ms |
| Third-party requests | 0 |

The CI contract enforces the byte ceilings. The browser gate (`tests/ledger-explorer-browser.cjs`) enforces the rest. It measures the baseline route and the explorer in the same run, prints both, and fails when the explorer's median LCP over 5 runs exceeds the baseline's median.

### 7.8 Security
- GET-only and anonymous.
- No nonces, cookies, user calls or `$wpdb` in explorer files. The only outbound HTTP is `daily_redirect_health()`'s three loopback `wp_remote_head()` calls to `home_url()` paths (R6); the explorer contract allows `wp_remote_` in that method only. Only the explorer class reads `$_GET`.
- No key-bearing symbols. Escaping on every partial.
- No `innerHTML`, `insertAdjacentHTML`, `eval`, `new Function`, `document.write`, localStorage or sessionStorage.
- Links are built only from IDs that pass the ID regex, and never for `review_flag` identities. External links carry `rel="noopener"`.

### 7.9 E2 and E3
**E2 (R6):**
- `template_redirect` at priority 1 sends a **302** to the explorer for `view=table`: portal → `explore/`; ceremony hub N → `?ceremony=N`; category hub → `?category=slug`.
- In-plugin links are repointed: `templates/table-display.php:249`, `templates/hub-page.php:1721, 2306-2307, 3138-3139` (href only; section keys and ob counts unchanged).
- SRI is added to the remaining `datatables-*` tags through `script_loader_tag` and `style_loader_tag`.
- **Redirect health, recorded daily by the site itself** (second critique: nothing ran the 7-day checks). `AAT_Ledger_Explorer::daily_redirect_health()`, hooked to `aat_ledger_daily` (§4.6.1), issues three loopback `wp_remote_head()` requests with `redirection => 0` and a 10 s timeout to `home_url('/oscars/?view=table')`, `home_url('/oscars/ceremony/98/?view=table')` and `home_url('/oscars/category/best-picture/?view=table')`, and stores `{date, ok, results: [{path, status, location}]}` in seven UTC-day buckets published at `/status.explorer.redirect_health`. `ok` is true when all three return the expected status (302 until U27, 301 after) and Location.
- **The 302 becomes a 301 after 7 clean days** (U27, the next version bump; no owner gate). A day is clean when its `redirect_health` bucket is `ok` and its `cron.daily` bucket shows at least 20 heartbeat runs. Both come from `/status`, so the precondition needs no agent to have been present on those days.
- **Who comes back.** When R6 is verified live, the agent schedules a one-shot routine for 8 days later (the session's scheduled-routine tool, e.g. a one-shot message with `delay_minutes` 11520, or a Routine with `run_once_at`) whose message is: "Evaluate U27 and U28 preconditions from /wp-json/lunara-ledger/v1/status; run U27 if seven consecutive clean days are recorded; run U28 only if its abuse precondition holds; otherwise report to the owner." It records the routine's id in SESSION-LOG. If no routine tool exists in that session, the SESSION-LOG entry's "whose move it is next" line names the date to resume.

**E3 (R7)** runs only if the read-only census finds no embeds, or every hit is converted. The census pages through `/wp-json/wp/v2/pages` and `/posts?search=` for the table shortcode and block names. Then:
- the `enqueue_scripts` table branch (`main:6021-6079`) enqueues the explorer handles;
- `render_shortcode` renders `templates/explorer/embedded.php`;
- `assets/js/academy-awards-table.js` and the DataTables CSS are deleted, with each selector grepped first;
- `tests/frontend-asset-routing-runtime.php` and the markers at `tests/credit-structure-contract.php:37-44` are rewritten in the same commit;
- the nopriv AJAX endpoints stay (the verifier and `tests/public-query-path-contract.php:78-88` use them).

---

## 8. Theme integration

### 8.1 R1T: theme 3.2.91 (before the swap)
1. **`inc/oscars-family.php`** (stays `$wpdb`-free) gains three helpers, each `function_exists`-guarded and degrading safely without the plugin:
   - `lunara_oscars_dataset_stamp()`: `lunara_oscars_reader()` + `method_exists('get_dataset_stamp')` → the stamp, else '';
   - `lunara_oscars_dataset_cache_key( $key )`: `$key . '__' . $stamp` when the stamp is non-empty, else `$key` unchanged;
   - `lunara_oscars_pair_is_guarded( $imdb_id, $label )`: the reader's `credit_pair_is_guarded()` when `method_exists`, else false.
2. **`functions.php:12706-12725` `lunara_oscar_nominee_id_for_label()`** (the only definition; live code):
   - pair `names[i]` with `ids[i]` only when `count(names) === count(ids)` (both trimmed and non-empty);
   - fall back to `lunara_oscar_first_id_from_list($ids_raw)` only when there is exactly one ID and at most one name;
   - return '' when no pair qualifies, or when the chosen (ID, label) pair is guarded.
3. **`inc/oscars-data.php:169-188` `lunara_resolve_oscars_winner_person_id()`:** the single-ID branch additionally requires `count($nominee_names) <= 1`; a guarded result returns ''.
4. **`inc/oscars-portal.php:609-631` `lunara_oscars_person_index_absorb()`:** the count branch skips guarded pairs; the single-ID Name branch (`:625`) additionally requires `count($names) <= 1`.
5. **`inc/frontend.php:2572-2582` `$map_pipe_values`:** drops each (id, label) pair for which `lunara_oscars_pair_is_guarded()` is true. **The two search title matches** (`inc/frontend.php:2211-2231` and `:2596-2610`), which build `/oscars/title/{film_id}/` themselves, skip a row for which `lunara_oscars_pair_is_guarded( $film_id, $film )` is true, so a never-link title (e.g. tt0169446, row 6429) is not offered (fourth critique).
6. **`inc/entity-surfaces.php:235` award history year** (second critique): `$year = trim( (string) ( $row['year'] ?? '' ) );` and the cell (`:244`) prints `esc_html( $year )` when `preg_match( '/^\d{4}(\/\d{2})?$/', $year )`, else `&mdash;`. The builder writes `ceremony_year` verbatim from R2 (§4.11 c), and today's integer values keep printing as before.
7. **`inc/entity-surfaces.php:444-446`:** `datePublished` only when `trim((string) $year)` matches `^\d{4}$`, emitted as that string. A split label such as `1932/33` emits no `datePublished`: it is not an ISO date, and flattening it would publish a year the Academy never gave.
8. **`inc/debrief-resolver.php:271-273` `normalize_year()`:** return the trimmed value verbatim when it matches `^\d{4}/\d{2}$`; otherwise the existing first-4-digit behaviour.
9. **Stamped theme caches** (second critique: stale theme transients). The five long-lived Oscars keys take `lunara_oscars_dataset_cache_key()` on the line after their literal, in their **live** `inc/` definitions only (the `functions.php` copies are dead, §1):
   - `inc/oscars-portal.php:642` `lunara_oscars_person_index_v1` (12 h);
   - `inc/oscars-data.php:862` `lunara_oscars_rotating_showcase_v4_{day}_{limit}` (1 day);
   - `inc/oscars-data.php:1219` `lunara_home_ledger_story_cards_v2` (6 h);
   - `inc/oscars-data.php:1342` `lunara_home_oscar_spotlight_v1` (12 h);
   - `inc/oscars-data.php:1701` `lunara_home_deep_cuts_v1` (1 day).
   Every existing delete of those keys keeps its line and gains a second `delete_transient( lunara_oscars_dataset_cache_key( '…' ) )` line for the stamped key: `inc/queries.php:385-400` (review saves) and `:422-445` (data import), `inc/oscars-data.php:483-505` (including the showcase loop), `inc/oscars-portal.php:915`. Review saves therefore still invalidate. The 15-minute keys (`lunara_home_oscars_snapshot_v7`, `lunara_home_database_spotlight_v1`) stay as they are. Without the plugin the stamp is '', every key is unchanged, and `tests/site-studio-oscars-winner-cases.php:28`'s seeded key still hits.
10. **`inc/live-search.php:177`:** the key gains `'|' . lunara_oscars_dataset_stamp()`, which versions the Ledger group on the dataset (AGENTS.md:90). No test pins the key.
11. **`lunara_oscars_on_ledger_swapped( $stamp = '', $consumer_changed = false, $restored_kind = '' )`** in `inc/oscars-data.php` (function_exists-guarded), hooked with `add_action( 'aat_ledger_swapped', 'lunara_oscars_on_ledger_swapped', 10, 3 )` next to the existing `aat_after_data_import` hook (`inc/oscars-data.php:509`). It does four things (third critique: stamp changes degraded portraits, and a pre-ledger restore missed invalidation):
   - calls `lunara_invalidate_oscars_data_caches( 'ledger_swap', 0 )` (`inc/queries.php:420-445`);
   - calls `lunara_flush_oscars_home_transients()` (`inc/oscars-data.php:483-509`);
   - calls `lunara_oscars_board_art_invalidate()` (`inc/oscars-portal.php:728`) when it exists;
   - schedules `lunara_oscars_portal_warm_visuals_now` at `time() + 30` unless it is already scheduled (the pattern of `inc/oscars-portal.php:941-942`), so the stamped person index (`lunara_oscars_person_index_v1__{stamp}`) is rebuilt by the warmer about a minute after the swap instead of rendering `[]` until the next daily warm. The warmer's delete at `:915` deletes both the plain and the stamped key (item 9).
   - A rollback to the pre-ledger data fires only `aat_ledger_swapped` (§4.6.7 F5), so this listener is what invalidates the theme on a rollback. This is the normal data-change invalidation path, not a cache clear as a fix.
12. **Tests:**
   - new `tests/oscars-positional-link-runtime.php` covers items 2–5 with rows 965, 276, 8165, 526 and 938 and a stub reader whose guard contains `(nm0481264, 'Thomas T. Moulton')`, the label pair `(nm0914249, 'Denham')` and the never-link IDs co0058013 and tt0169446 (for which `lunara_oscars_pair_is_guarded(<id>, <any label>)` is true); a search over a stub row `{film: 'Just Another Missing Kid', film_id: 'tt0169446'}` yields no title match from either search path;
   - new `tests/film-year-label-runtime.php` covers items 6–8: `lunara_entity_render_award_history()` with award rows whose `year` is `'1932/33'`, `'2025'`, `'1932'` and `''` prints `1932/33`, `2025`, `1932` and an em dash; the JSON-LD builder emits `datePublished '2025'` for `release_year '2025'` and none for `'1932/33'`; `normalize_year('1932/33')` is `'1932/33'`;
   - new `tests/oscars-dataset-cache-runtime.php` covers items 1, 9–11: with a stub reader stamp `abc123def456` the five keys end in `__abc123def456`, each delete site deletes both forms, and without a reader every key is unchanged; firing `aat_ledger_swapped` through a stub `do_action` calls the three invalidators once each and schedules exactly one `lunara_oscars_portal_warm_visuals_now` event (a second fire schedules none);
   - the existing `tests/fixtures/debrief-resolver-harness.php` cases are unchanged.
13. **Docs:** CHANGELOG and SESSION-LOG, written without the ratchet literal; style.css 3.2.91.

### 8.2 R5: theme 3.2.92 (after the explorer is live)
1. **`inc/oscars-family.php`** (stays `$wpdb`-free):
   - `lunara_is_oscars_explorer_route()`, via `lunara_oscars_reader()` + `method_exists('is_explorer_request')`;
   - `lunara_oscars_explorer_url($filters)`, delegating to `get_explorer_url()` and falling back to `home_url('/oscars/?view=table#oscars-research')`;
   - `lunara_is_oscars_route_family()` becomes portal ∨ ledger ∨ explorer (body class only);
   - `lunara_is_oscars_ledger_route()` is unchanged, so the ledger overlay and label marker stay off the explorer.
2. **`inc/frontend.php:4908` `lunara_is_oscars_dossier_surface()`:** returns false first when `function_exists('lunara_is_oscars_explorer_route') && lunara_is_oscars_explorer_route()`. This removes the 7.5 KB priority-1002 emitter from the explorer.
3. **Links:**
   - `inc/site-studio-footer-navigation.php:27`: the 'Full Ledger' URL → `lunara_oscars_explorer_url()` (label unchanged; pinned at `tests/site-studio-footer-navigation-runtime.php:17`);
   - `inc/control-desk.php:13041, 13188, 13464`: the same;
   - `functions.php:8700` is a dead guarded copy and is left alone;
   - portal Quick Start and `page-oscars.php:190-194` are unchanged (pinned at `tests/site-studio-oscars-runtime.php:83`; E2's redirect covers them).
4. **Tests:** new `tests/oscars-explorer-boundary-runtime.php`. It checks delegation and fallback, route detection, the family class, dossier exclusion and the unchanged ledger detector.
5. **Docs:** CHANGELOG, SESSION-LOG and an `OSCARS-PORTAL-ARCHITECTURE.md` row, written **without** the ratchet literal; style.css 3.2.92.
6. `lunara_oscars_ledger()` (the API design's theme facade) stays deferred; no theme code would call it.

---

## 9. File list by release

**R1 (plugin 2.8.0):**
- **Privacy (U00):** add `data/ledger/{entities.tsv, titles.tsv}` (extracted), `tests/tools/{extract-reference-names.php, strip-reference-columns.php, lib/redact-evidence.php}`, `tests/ledger-privacy-contract.php`; rewrite `docs/database/{data.sql.gz, schema.sql, tools/build.py, README.md, AUDIT-REPORT.md, corrections.json, needs-review.json, tools/adjudications.json, tools/editor_decisions.json}` (the last five redacted in their evidence-bearing values only; the two `tools/*.json` also get the audit-label rename of §4.14 item 6), and regenerate (or delete) `docs/database/oscars-corrected.xlsx`.
- **Data:** add `data/ledger/{manifest.json, corrections.json, additions.json, needs-review.json, accepted-drift.json, name-overrides.tsv, simulation.json, legacy-link-guard.json}` and `data/LICENSE-oscar_data.txt`. Add `docs/database/additions.json` and `docs/database/accepted-drift.json` if absent.
- **Includes:** add `includes/{class-aat-ledger.php, class-aat-ledger-tables.php, class-aat-ledger-source.php, class-aat-ledger-deriver.php, class-aat-ledger-importer.php, class-aat-ledger-slugs.php (planner), class-aat-ledger-slug-redirects.php (always loaded), class-aat-ledger-api.php (status, report, license)}`.
- **Modify:** `academy-awards-table.php` (including the never-link rule in `build_entity_url_from_id()` and `get_title_visual_package()`, the DataTables `credit_items`/`title_items` and token masking, the two-year external lookups), `includes/class-aat-entity-graph-builder.php`, `templates/hub-page.php` (latest winners with the in-page "and N more" anchor; `id="aat-category-ceremony-{N}"` on history cards; citation-only primary label; closures delegate to `resolve_credit_links`; person filter kept on resolver links; no label lookups; never-link URL closure used by every call site; no payload `film_url` when an ID is present), `templates/entity-page.php` (title-page nominee line through the resolver; `$render_linked_pipe` guard; never-link URL closure), `templates/table-display.php` (a `$aat_build_entity_url` closure for its three URL sites), `assets/css/academy-awards-table.css` (latest-winners card rules), `assets/js/academy-awards-table.js` (`credit_items`, `title_items`, name-labelled pills) and `tools/verify-live-dataset.php`.
- **Dev tools** (`tests/tools/`): `build-ledger-bundle.php`, `lib/redact-evidence.php`, `lib/sql-dump.php` (the `data.sql.gz` emitter, §4.1), `corrected_tsv_reference.py`, `ledger-dry-run.php`, `classify-drift.php` (the remediation classifier, §4.6.6), `ledger-import-mariadb.php`, `ledger-memory-gate.php`, `api-source-hash.php`, `verify-credit-links-live.sh` (with a `--fixture-dir` mode), `verify-ledger-r1-live.sh`.
- **New tests:**
  - `tests/{ledger-privacy-contract, ledger-bundle-contract, ledger-ddl-contract, ledger-display-name-runtime, ledger-projection-runtime, ledger-memory-runtime}.php`;
  - `tests/{ledger-importer-contract, ledger-freeze-runtime, ledger-public-path-contract, ledger-legacy-write-guard-contract, ledger-simulation-match-runtime}.php`;
  - `tests/{ledger-stamp-contract, ledger-read-fixes-runtime, ledger-credit-links-runtime, ledger-render-closures-runtime, ledger-credit-links-contract, ledger-datatables-credit-runtime}.php`;
  - `tests/{ledger-builder-contract, ledger-slug-planner-runtime, ledger-http-runtime, ledger-api-contract, tools-cli-guard-contract}.php`;
  - `tests/fixtures/ledger/{credit-links.json, simulation-cases.json, live-probe-cases.json, decades.json}`, `tests/fixtures/live-html/` (saved pages of the four §1 defects and of `/oscars/ceremony/55/`, for the probe's self-test), `tests/ledger-datatables-credit.cjs`.
- **Modified tests:** `tests/{entity-category-labels-runtime, category-names-runtime}.php` (the resolver, guard and URL methods added to their extracted lists; U06 and U08) and the 13 version-pinned tests.
- **Docs:**
  - add `docs/database/{display-name-changes.tsv, slug-collisions.tsv, link-overrides.tsv}`, `docs/database/baselines/<bundle_id>.json.gz`, and regenerate `docs/database/{oscars-corrected.tsv, data.sql.gz, oscars-corrected.xlsx}` and `integrity.sql`'s expected-count literals (all from the bundle builder, §4.1);
  - add `docs/design/ledger-2.8/`: the stripped schema drafts, `reference_serializer.py`, `samples.py`, `api_derived.sql`, this plan, and the prototypes renamed `*.php.txt` so CI's `php -l` skips them;
  - modify `docs/database/README.md`, `README.md`, `readme.txt`;
  - theme repo: `docs/CHANGELOG.md`, `docs/SESSION-LOG.md`.

**R1b (plugin 2.8.1, only if the dry run does not match; U29):** `docs/database/accepted-drift.json` and/or `docs/database/corrections.json` entries, the regenerated `data/ledger/` and `docs/database/` artifacts, the version markers and the theme docs.

**R1T (theme 3.2.91):** `inc/oscars-family.php`, `functions.php`, `inc/oscars-data.php`, `inc/oscars-portal.php`, `inc/frontend.php`, `inc/entity-surfaces.php`, `inc/debrief-resolver.php`, `inc/queries.php`, `inc/live-search.php`, `tests/oscars-positional-link-runtime.php`, `tests/film-year-label-runtime.php`, `tests/oscars-dataset-cache-runtime.php`, `style.css`, `docs/{CHANGELOG, SESSION-LOG}.md`.

**R2 (2.8.1):** `data/ledger/manifest.json` (mode swap; the bounds are generated), regenerated bundle files when the overlay changed since R1, `tests/tools/verify-ledger-r2-live.sh` (runs the R2 checks with the stamp discipline and prints each check's class and result), the version markers and the theme docs.

**R3 (2.8.2):**
- add `includes/{class-aat-ledger-query.php, class-aat-ledger-store.php, class-aat-ledger-serializer.php, class-aat-ledger-service.php}` and `schemas/lunara-ledger/v1/*.schema.json` (20);
- modify `includes/class-aat-ledger-api.php` and `academy-awards-table.php` (require block, `ledger()`);
- add `tests/{ledger-query-runtime, ledger-store-contract, ledger-serializer-runtime, ledger-json-schema-contract, ledger-token-contract}.php`, `tests/fixtures/ledger/{rows.json, json-schema-subset.php, api-source-hash.json}`;
- extend `tests/{ledger-api-contract, ledger-http-runtime}.php`;
- add `tools/verify-ledger-api.php` (CLI guard) and `docs/api/LEDGER-API.md`;
- version markers and theme docs.

**R4 (2.8.3):**
- add `includes/{class-aat-ledger-explorer.php, class-aat-ledger-explorer-data.php, class-aat-ledger-explorer-view.php}`, `templates/explorer-page.php`, `templates/explorer/{hero, controls, suggestions, chips, results, group-ceremony, group-category, row, credit, title, entity-card, pagination, debrief, notices, attribution, js-templates}.php`, `assets/js/ledger-explorer.js` and `assets/css/ledger-explorer.css` (plus the explorer's own first-paint seed, printed by the explorer class, and `tests/fixtures/ledger/boost-critical-replay.css`, the offline stand-in for Boost's stale block, §7.6);
- add `tests/{ledger-explorer-state-runtime, ledger-explorer-route-runtime, ledger-explorer-render-runtime, ledger-explorer-contract, ledger-explorer-js-runtime}.php`, `tests/ledger-explorer-js.cjs`, `tests/ledger-explorer-browser.cjs`, `tests/fixtures/{explorer-url-cases.json, explorer-render-cases.json, explorer-dict.json}` and `tests/tools/{render-explorer-fixture.php, verify-explorer-live.sh}`;
- modify `tests/frontend-asset-routing-runtime.php` (add a row) and `academy-awards-table.php` (require block, two methods, dequeue guard);
- add `docs/EXPLORER.md`; regenerate `tests/fixtures/ledger/api-source-hash.json` for 2.8.3; version markers and theme docs.

**Every plugin release from R3 on** regenerates `tests/fixtures/ledger/api-source-hash.json` with its version bump (§6.5).

**R5 (theme 3.2.92):** `inc/oscars-family.php`, `inc/frontend.php`, `inc/site-studio-footer-navigation.php`, `inc/control-desk.php`, `tests/oscars-explorer-boundary-runtime.php`, `style.css`, `docs/{CHANGELOG, SESSION-LOG, OSCARS-PORTAL-ARCHITECTURE}.md`.

**R6 (2.8.4):** `includes/class-aat-ledger-explorer.php` (redirects, SRI, `daily_redirect_health`), `includes/class-aat-ledger-api.php` (`explorer.redirect_health` in `/status`), `schemas/lunara-ledger/v1/status-response.schema.json`, `tests/fixtures/ledger/api-source-hash.json`, `templates/table-display.php`, `templates/hub-page.php`, `tests/ledger-explorer-route-runtime.php`, version markers.

**R7 (2.8.5):** `academy-awards-table.php` (enqueue branch, render_shortcode) and `templates/explorer/embedded.php`; delete `assets/js/academy-awards-table.js`; modify `assets/css/academy-awards-table.css`, `tests/frontend-asset-routing-runtime.php`, `tests/credit-structure-contract.php` and `tests/ledger-credit-links-contract.php`; remove `tests/ledger-datatables-credit.cjs` and `tests/ledger-datatables-credit-runtime.php`; version markers.

**Later (2.8.6+):** U27 (the 301 switch), U28 (limiter enforcement, conditional).

---

## 10. Pinned contracts and how each stays green

| Test (plugin unless noted) | Touched by | How it stays green |
|---|---|---|
| `reporting-integrity-contract.php:30,80-90` (CSV sha, 12,138 file lines, 3,515 winners, ceremony 98 144/44, ceremony 4 fixture); `winner-backfill-identity-contract.php:60` (12,137 rows of `data/oscars.csv`) | none | The CSV is unchanged; these pin the **upstream** file, which is not a ledger count, and they are the only count literals the plan keeps. The ledger's own counts (12,138 nominations and 3,516 winners with PR #39's overlay) come from the manifest |
| `reporting-integrity-contract.php:94-156` (rebuild slice) | U05 | The guard has no TRUNCATE, `$wpdb->insert` or `get_results`; the body below it is byte-identical |
| `reporting-integrity-contract.php:105-114, 185-215` (census and integrity markers) | U05 | The census markers are kept verbatim. The drift helper sits inside the `get_lunara_integrity_summary…ajax_clear_data` slice. Every pinned string is kept; the `'legacy'` variant is byte-identical |
| `reporting-integrity-contract.php:217-335` (full-plugin load under stubs; runtime census 12,137 / 3,515) | U04, U11 | Load-time code calls only add_action and add_filter. `state()` returns `legacy` without calling `get_option`, which the stubs do not define (§4.6.10 rule 1), so the legacy census runs |
| `schema-dbdelta-contract.php:19-45` | U02, U04 | `$ledger_*_table` names; stage DDL `CREATE TABLE \`$stage_table\` LIKE \`$live_table\``; no other `CREATE TABLE ` text in includes; `maybe_upgrade_schema()` is unchanged (no ledger call, so its gate block at `:19-45`'s regex still holds); count ≥ 39 |
| `atomic-bundled-import-contract.php:15-47` | U05 | The delegation contains no marker string; the legacy order holds |
| `import-shrink-guard-contract.php:37-51` | U05 | The refusal sits before `parse_full_import_file(` and contains no CREATE TABLE, START TRANSACTION or DELETE FROM |
| `person-credit-review-queue-contract.php:150-165`, `person-credit-full-row-resolver-contract.php:~164` | U05 | Additive refusal; the markers remain |
| `public-query-path-contract.php:30-125` | U05, U06, U07, U08 | No new `AAT_BUNDLED_CSV_PATH` in the main file; the `get_entity_rows` edit is SQL-only; `'id, ' . $this->get_awards_row_fields_sql()` untouched (`:97-98`), and `credit_items` is added after the SELECT; verifier markers kept; new methods near sliced ones carry docblocks |
| `oscars-read-api-contract.php:44-120` | U06, U07, version | Key literals and `set_transient(… 12 * HOUR_IN_SECONDS)` strings stay on their own lines; the invalidator only gains a line; no new grid-delete sites; `ledger()` outside the slices; 4 version literals bumped |
| `category-names-runtime.php:27, 47` | U06, U08 | `dataset_cache_key`, `resolve_credit_links` and `credit_pair_is_guarded` are public and added to the extracted list; they return legacy behaviour without `AAT_Ledger`; the rollup literal line stays |
| `entity-category-labels-runtime.php:54-90` | U06, U07, **U08** | `dataset_cache_key` is added to the list (U06). **U08** (fourth critique: the harness renders the real `templates/entity-page.php` against an eval'd stub class, `:57-70`, that has no `get_entity_url` or resolver method, so the title-page nominee line would throw "Call to undefined method"): `get_entity_url`, `resolve_credit_links`, `credit_link_key`, `credit_pair_is_guarded`, `credit_id_is_never_link` and `credit_items_html` are added to the extracted list at `:54`. They run without `AAT_Ledger` (every ledger branch is `class_exists`-guarded, so the resolver returns `index` or `plain` and the guard returns false), `get_entity_url` delegates to the stub `build_entity_url_from_id` at `:64`, and `get_entity_display_name` stays stubbed. The ledger branch of `get_entity_rows` is guarded by `class_exists('AAT_Ledger')`; the warm key `aat_entity_rows_v2_ . md5` still hits |
| `credit-structure-contract.php:37-49` | U07, U08, U26 | The six JS markers stay in `renderNomineeCell`'s fallback path; the two `$aat_winner_primary` markers stay (the citation fallback is appended after them); rewritten against `ledger-explorer.js` in E3 |
| `sql-performance-contract.php:28-54`, `related-review-studio-controls.php:28-35` | every release | The 5 version markers are bumped together; no legacy DDL change |
| 13 version-pinned tests | every plugin release | The literal is replaced per file; the `schema-dbdelta-contract.php:16` comment is untouched |
| `multi-film-label-contract.php:42-49, 72-78` | U07, U08 | Rebuild and rollup label calls untouched; the raw `esc_html(' ' . $latest_winner['detail'])` marker stays **absent** (the test asserts absence; the card prints the detail through `$aat_film_display`, `hub-page.php:2640`); closures keep their signatures |
| `winner-backfill-identity-contract.php:15-101` | U09 | `backfill_candidates` and `backfill_key` untouched; the builder file still loads standalone (ledger calls guarded, only inside methods; ACF filters are add_filter only) |
| `live-search-entity-feed-contract.php:16-32` | U08 | `aat_search_entities` gains one guarded `continue` after the pinned URL line; labels are corrected upstream |
| `virtual-page-cache-eligibility-contract.php:23-40` | U11, U20 | `fix_virtual_page_status` untouched; the meta marker is a separate `wp_head` callback; explorer nocache only in the 503 branch |
| `deployignore-contract.php` | U01, U17, U22 | `data/`, `includes/`, `templates/`, `assets/` and `schemas/` ship; dev tools live in `tests/tools/`; `tools-cli-guard-contract.php` asserts every `tools/*.php` exits outside CLI |
| `frontend-asset-routing-runtime.php:65-118` | U20 (row), U26 (rewrite) | E1 adds one row and never edits `enqueue_scripts`; E3 rewrites the matrix in the same commit |
| `ceremony-payload-hygiene-contract.php:34-35` | U20 | The guard is only extended; filter name and list unchanged |
| `ceremony-payload-hygiene-contract.php:56` (`hub-polish.css` < 7,000 B; 6,976 B today) | U07 | Not touched: the latest-winners rules go into `academy-awards-table.css`, which has no byte budget (§4.10) |
| `inner-page-visual-rhythm-contract.php:30-31` (`get_name_entity_link_by_label` exists; `$aat_build_person_link_items` present) and `:35` (`aat-category-ceremony-row aat-ledger-card`) | U07, U08 | The method stays (admin callers) and the closure keeps its name; the history card's new `id` attribute follows the class attribute, so the pinned substring is unchanged |
| `live-search-entity-feed-contract.php:29` (`get_entity_url( $row['entity_id'] )` in `aat_search_entities`) | U08 | The line is kept; the guard skip is a separate line after it |
| `route-section-composer-contract.php:47-139`, `landing-section-composer-contract.php:27-66`, `inner-page-visual-rhythm-contract.php:103-144` | U07, U08, U25 | Only additive closure-body or href changes; section keys, ob counts and pinned strings untouched; run in those PRs |
| CI lint (`php -l`, `node --check` on `*.js`, CSS brace balance) | U01, U22 | Prototypes committed as `*.php.txt`; UMD JS; the grain data URI is percent-encoded with no braces; no braces in CSS comments |
| theme `oscars-read-path-ratchet.ps1` | U13, U24 and every theme docs entry | No `$wpdb` in `inc/oscars-family.php`; no ratchet literal in any code or doc change; stays at 22 |
| theme `fixtures/oscars-ledger-overlay-harness.php:157-176` | U24 | The new check is `function_exists`-guarded, so all 11 cases are unchanged |
| theme `fixtures/debrief-resolver-harness.php` | U13 | 4-digit years only; `normalize_year` output unchanged for them |
| theme `site-studio-oscars-winner-cases.php:28` (seeded showcase key) | U13 | Without the plugin the stamp is '', so `lunara_oscars_dataset_cache_key()` returns the seeded key unchanged |
| theme `oscars-portal-studio-runtime.php:265-267`, `oscars-portal-studio-contract.ps1:58-59` | U13, U24 | Function names kept; the family is only extended |
| theme `site-studio-footer-navigation-runtime.php:17`, `site-studio-oscars-runtime.php:83` | U24 | Labels and portal Quick Start unchanged |

---

## 11. Test plan

**CI (plugin, `.github/workflows/lint.yml`, PHP 8.2).** Every `tests/*.php` in §9, plus `php -l`, `node --check` and the CSS brace check. CI is the PHP 8.2 gate for the derivation: `ledger-bundle-contract`, `ledger-display-name-runtime` and `ledger-projection-runtime` re-derive the whole bundle under 8.2 and must reproduce `corrected_sha256` and `derived_sha256` bit for bit. `fold()` uses no mbstring or locale function, so it cannot drift between 8.2 and 8.4. Local runs are on PHP 8.4.19. A mismatch between the two is a CI failure, never a local override. When a docker daemon is available, the same three tests are also run locally with `docker run --rm -v "$PWD":/w -w /w php:8.2-cli php tests/<name>.php`. CI needs no git history: `--check` reads the recorded baseline's committed summary (§4.1).

Key assertions (every count read from `data/ledger/manifest.json` or a generated bundle file, never a literal):
- **ledger-privacy-contract:** §4.14 item 8 (no Q-number in any data or prose file, the plan included; no year span in any evidence-bearing value, Markdown file or dump evidence; column names only where listed), plus: `redact_evidence()` is idempotent and maps a fixture of each pattern class to the expected output, and leaves `1932/33`, `1927/28`, single years, ceremony URLs and runs of spaces alone; **scope cases** (fourth critique): a fixture correction whose `before` and `after` both carry source_row 11336's Note verbatim, and a fixture addition whose Citation holds a parenthesized year range, pass the contract and come out of the builder's step 0 byte for byte, while the same text placed in their `evidence` is redacted; `REDACT_KEYS` is identical in the builder, U00's tools, the codec and the contract; the audit's review-item labels in `tools/*.json` begin with R, not Q; `data.sql.gz` decompresses, its `ledger_entities` rows have exactly 3 values and its `ledger_titles` rows exactly 2; `schema.sql` has no `wikidata_qid`, `birth_year` or `release_year` column; every `imdb_id` in `entities.tsv` and `titles.tsv` appears in `data.sql.gz`, or was added by the builder with an empty reference name.
- **ledger-bundle-contract:**
  - hashes and byte copies; codec invariants (rules 1–13);
  - the shipped formats (§4.1): `corrections.json` and `additions.json` are arrays of the stated shapes; every authored JSON file round-trips through `AAT_Ledger_Json::encode_shipped()` byte for byte;
  - corrections apply in file order with 0 mismatches, **each cell corrected more than once passing a before-check per entry**, and the manifest's `count`, `cells` and `multi_corrected_cells` equal a recount; addition rules, with implied `source_row`s and the `addition_cell()` table (`97` → `97`, `true` → `True`, `null` → empty);
  - corrected sha = manifest = `hash_file(oscars-corrected.tsv)` = the Python reference;
  - distinct keys = `expected.nominations`;
  - reference coverage both ways, with exact TSV headers and no `^Q\d+$` cell;
  - numeric titles round-trip;
  - `build-ledger-bundle.php --check` exit 0 (it uses the manifest's recorded baseline and needs no git history);
  - `simulation.json` and `legacy-link-guard.json` hashes equal the manifest; the recorded baseline's summary file exists when `baseline.kind = bundle`, and `docs/database/baselines/` holds only it and this bundle's own summary;
  - needs-review consistency;
  - `build-ledger-bundle.php --check` fails with `evidence_not_redacted` on a temporary copy whose `corrections.json` evidence has a Wikidata URL appended, and the codec refuses the same copy with `privacy_violation`;
  - `accepted-drift.json` validity (codec rule 15) and its manifest counts; `tests/fixtures/ledger/live-probe-cases.json` and `decades.json` are reproduced byte for byte;
  - licence file content;
  - **the public SQL and workbook** (fourth critique: stale docs artifacts): `gzdecode(data.sql.gz)` equals the dump the builder emits from the deriver; its `ledger_dataset` row carries `manifest.corrected_sha256`, `manifest.source.sha256` and the manifest's nomination and winner counts; its `ledger_corrections` line count equals `manifest.expected.corrections`; no flagged identity appears in it; `integrity.sql`'s expected-count literals equal `manifest.expected`; the workbook's `Corrections` and `full_data` dimensions match the manifest (`workbook_stale` otherwise); every `*_sha256` of a gzipped artifact hashes the decompressed bytes;
  - the mutations (below) run in-test on temporary copies;
  - no `*.php` under `docs/design/`.
- **ledger-ddl-contract:**
  - 19 named statements (12 relational, 4 read, 3 persistent: datasets, award_keys, report_pages);
  - `AAT_Ledger_Tables::ensure(` is called only from the importer's prepare step 0 and the CLI, and `maybe_upgrade_schema()` and `activate()` contain no ledger DDL call;
  - every `checksum_columns()` list omits exactly the columns whose DDL has `DEFAULT CURRENT_TIMESTAMP` or `AUTO_INCREMENT`, **except the master's `id`, the single declared exception in `AAT_Ledger_Tables::CHECKSUM_EXCEPTIONS`**, and lists every other column;
  - one definition per line;
  - `swap_set()` = the 25 tables;
  - no FK, CHECK, ENUM, VIEW or IF NOT EXISTS;
  - no `wikidata|birth_year|qid|release_year` column;
  - name lengths ≤64 for all six suffixes;
  - gate placement.
- **ledger-display-name-runtime:** the pinned names (including nm0916990 Paul Francis Webster); override precedence; rule 3: a fixture entity credited only in shared slots takes its first shared credit (`shared_credit`), not its reference name, and the reference name is used only for a fixture entity with no credit text (`reference`); the Jaynes alias with `shared_credit=1`; flagged slots cast no vote; the change list equals `display-name-changes.tsv` with its count equal to `simulation.labels_changed`; `fold()` maps each of the 39 dataset characters as specified, is idempotent, and gives one search row each for nm0005838, nm0306223, nm0380057 and nm3234869; `FOLD_MAP` keys are exactly the U+00C0–U+017F letters plus the six punctuation marks.
- **ledger-projection-runtime** (plugin required under the reporting-integrity stubs):
  - every `expected` count;
  - row 276 nm0413164 ordinal 2 label `FRED JACKMAN`; row 965 nm0380965 ordinal 2 and no row for slot 1; row 8165 two ordinal-1 rows; 4507 one MGM row;
  - nominee sets = normalized master `nominee_ids`; no flagged pair in master or nominees;
  - `first_year_label` of a ceremony-6 title = `1932/33`;
  - `derived_sha256` equal to the manifest, with two runs identical;
  - the replayed baseline derivation's diff = `simulation.json`;
  - V11 holds, plus its mutation;
  - the link-override entries for 965, 276, 8165 and 9026 equal the pinned slot lists;
  - the three guard lists equal the recomputation from the legacy parse and the local legacy simulation; `pairs` includes row 526's before-pair, `label_pairs` includes (nm0429444, Radio Corporation of America) and (nm0914249, Denham), `never_link` includes co0058013 and no ID with `linked_credits > 0`;
  - link-override membership: source_row 3470 is not a member, 938 and 9026 are, and a masked fixture row with one slot is;
  - normalizer idempotence for every row;
  - `manifest.idless` equals a recount over the legacy parse.
- **ledger-memory-runtime:** per-table emit and checksum in a child process with `memory_limit=256M`, each ≤192 MiB.
- **ledger-importer-contract** (source contract):
  - GET_LOCK plus the lease fallback; AAT_VERSION restart; `lock_wait_timeout` before RENAME; the RENAME built once from `swap_set()`; no `TRUNCATE TABLE`;
  - prepare stores `planned`/`crc_xor` for one table per step and records `prepare.checksummed`;
  - V1–V13 and bounds before RENAME; `simulation_mismatch` and `simulation_baseline_mismatch` block swap mode;
  - the stamp is computed in prepare and emitted into the stage meta; F1 compares it; F2 writes the same value into the option;
  - **every mode** writes allocations only to `aat_ledger_award_keys_lstg`; the only statement that writes `aat_ledger_award_keys` is F3's upsert, which follows the RENAME in source order, and `aat_ledger_award_keys_lstg` is dropped only after it (fourth critique); pins run before pass 1 and fail with `id_pin_stale` on a changed row; pass 3 uses `tolerant_key()`; the matching passes run for every bundle key the persistent table lacks, not only on the first import;
  - the `reassert` branch never calls the V13 or simulation-match functions, always calls the scope check, and takes ids only from the persistent table;
  - `_lbad` swap-back; the `_l0` rule for every displacement of pre-ledger data; the re-assert uses `_ldft` and never names `_lprv`; a forward swap of a different bundle deletes the rollback pin;
  - `update_option(OPTION_LIVE` before `do_action('aat_ledger_swapped'`; `aat_after_data_import` guarded by `consumer_changed` and never fired for a `pre_ledger` restore; F6 sweep scheduled;
  - dry_run never reaches the swap; rollback mode requires a live bundle match;
  - retryable and terminal code sets split.
- **ledger-simulation-match-runtime** (pure; the diff classifier with fixture production lists): with 7 media-touched entities appearing only in production's `entities_removed` and 3 media-touched label changes, `simulation_match` is true and `media_explained` reports 7 and 3; with 7 **sourced** extra removals it is false, and the `metric:entities_removed` section lists exactly those 7 with `prod`, `ledger`, `legacy` and `differing_fields`; after adding those 7 to a fixture `accepted-drift.json` with the right `prod_sha1`, the match is true with `drift_explained` 7; with one `prod_sha1` changed, that item is reported `accepted_drift_stale` and the match is false again; media counts above `max_media_entities` give `blocked`; a baseline of `legacy` against a fixture live meta with a `bundle_id` gives `simulation_baseline_mismatch`; the remediation classifier (U29's `tests/tools/classify-drift.php`) returns `already_applied`, `production_lost_flag`, `production_mojibake`, `production_edit` and `production_only` for one fixture item of each kind; a pin resolves a fixture `ids_new_upstream` row; a `restored` entry raises `max_ids_new` by one; a fixture live row that no bundle row maps to gives `blocked` with `ids_retired_unexplained`, and a matching `retire` entry raises `max_ids_retired` by one and clears it (fourth critique).
- **ledger-freeze-runtime:** the whole truth table of §4.6.10 driven through stubs: `is_live`/`is_frozen`/`master_is_ledger`/`refuses_legacy_writes`, `legacy_write_refusal` returning a refusal in `live`, `frozen` and `rolled_back`, `rebuild_reporting_tables` deferring in the same three, **`request_import` returning `deferred_frozen` in `frozen`, `queued` in `rolled_back` for a bundle other than the pinned one, `refused_pinned` for the pinned one, and `queued` for the pinned one when `manifest.reswap_of` names it**; the version check recording `deferred_frozen` and re-running once the switch is lifted; `start_run` refusing only in `rolled_back`; `resolve_credit_links` returning `plain` for misaligned rows outside `live`; `canonicalize_name_entity_id_for_label` returning its input in `live` and `frozen`; `credit_pair_is_guarded`, `credit_id_is_never_link` and the never-link rule of `build_entity_url_from_id` active only in `legacy` and `rolled_back`; **`request_reassert()` queuing mode `reassert` in `live` only (paused in `frozen` and `rolled_back`), and a re-assert of a bundle whose manifest baseline is `legacy` against a live meta holding that bundle passing validation without `simulation_baseline_mismatch`** (fourth critique); and, **in a process where `get_option` is not defined**, `state()` is `legacy`, `stamp()` is '', `legacy_link_guard()` is empty, and no fatal error occurs.
- **ledger-public-path-contract:**
  - pinned public methods and templates never mention the pipeline classes;
  - `init()` bodies contain only add_action and add_filter;
  - load-time requires are limited to `class-aat-ledger.php`, `class-aat-ledger-slug-redirects.php`, the API files and the explorer files;
  - no direct `$this->rebuild_reporting_tables()` in `ensure_projection_data_available` or after the upgrade gate.
- **ledger-legacy-write-guard-contract:** `legacy_write_refusal(` precedes the first write in all 8 entry points; `repair_*_credit_rows(` is called only from the two refused importers; the refusal predicate includes `is_frozen()`.
- **ledger-stamp-contract:**
  - each of the 16 families is followed by a `dataset_cache_key(` line; the 5 singletons are not;
  - `dataset_cache_key` passes the input through unchanged without `AAT_Ledger` or with an empty stamp;
  - `aat_title_context_v2_` and `aat_category_latest_winner_v3_` exist;
  - the invalidator deletes `aat_hub_page_stats_v2`;
  - F4 and F6 delete the same exact list;
  - `get_projection_drift_counts` takes a `$variant` argument, V6 passes `'ledger'`, and the Control Desk call passes the `master_is_ledger()` ternary.
- **ledger-read-fixes-runtime** (eval harness):
  - `external_lookup_year('1927/28')` = '' and `('2025')` = '2025';
  - the title context `year` is verbatim, with no `192728` anywhere;
  - a 2-winner fixture returns `winners_in_ceremony` 2 and a co_winner;
  - the hub template renders both names and never "tie";
  - the category-history card for source row 10475 contains "To all those who built and operated film laboratories, for over a century of service to the motion picture industry." as its title and "Special Photographic" as its meta; an appended citation-only fixture renders its citation; a row with a Film still leads with the Film;
  - the latest-winner payload carries `citation`;
  - the 8-winner fixture's "and N more" link targets `#aat-category-ceremony-{N}`, and the same rendered hub contains an element with that id holding all 8 names (fourth critique: the old target never existed for SciTech);
  - the external lookups: `external_lookup_years('1932/33')` is `['1932', '1933']`; with a stubbed `wp_remote_get`, the TMDb fallback rejects a same-title candidate of another year, accepts one of either split year, and returns no movie when no candidate year matches (never `results[0]`); the OMDb audit's `year_match` is true for a candidate of either split year.
- **ledger-credit-links-runtime / ledger-render-closures-runtime / ledger-credit-links-contract:** §5.5.
- **ledger-datatables-credit-runtime:** runs `node tests/ledger-datatables-credit.cjs`, which loads `assets/js/academy-awards-table.js` in a minimal DOM-free harness and asserts: for row 965 with `credit_items`, 'Jean Hersholt' links nm0380965 and 'The Motion Picture Relief Fund' is plain; without `credit_items` and without `entity_names`, the misaligned row renders the names with **no** pill; with `entity_names`, a pill reads the display name; for row 6429's legacy payload (`title_items` plain, `film_id` masked to `?`) the film cell has no anchor, and so does the index fallback when `title_items` is absent (the pre-R1 script's path); no output contains "Lunara 1". A PHP half asserts that `ajax_get_awards_datatable` masks exactly the never-link and guarded tokens in legacy, keeps the token count, and masks nothing in live. It fails when `CI` is set and node is missing.
- **ledger-builder-contract:**
  - no `MIN(c.sort_year)` and no `(int)` cast on the year label in the builder;
  - `_aat_year_label` written with every year write;
  - the ownership rule (runtime fixture): a post with `release_year '1932'` and no `_aat_year_label`, whose legacy-formula value is 1932, becomes `'1932/33'`; a post with `'1933'` and no `_aat_year_label` is kept and counted in `editorial_years_kept`; a post whose `release_year` equals its `_aat_year_label` is overwritten;
  - the ACF `load_field` filters for both field keys;
  - `delete_post_meta` for movie/person when 0;
  - the dated category label call;
  - retirement uses draft for movie/person and `wp_trash_post` for ledger_entry, never `wp_delete_post`, both behind their bounds;
  - the slug journal write precedes `wp_update_post(… 'post_title' …)` and records `old_title` and `old_name` from `get_post_field`;
  - `start_run` refuses in `rolled_back`, and contains no `DELETE` of `directors`/`principal_cast`;
  - relationships (runtime fixture): a movie with two Directing facts ends with exactly those two directors after one `step_ledger` batch, a movie whose director changed ends with only the new one, and at no step between `start_run` and the end of `step_ledger` is its `directors` meta empty; a movie that lost its last Directing fact is cleared in `verify`, within `max_entities_removed`;
  - an existing `ledger_entry` post whose computed title differs gets `wp_update_post` with the new `post_title`; one whose title is unchanged gets no update;
  - studio terms (runtime fixture): a company relabelled from A to B keeps its term (found by `_lunara_entity_id`), which is renamed in place and journalled; a company whose new name equals another company's old term name does not re-point that term; two terms with one ID are reported as `studio_duplicate_terms`;
  - stage order movies, people, studios, slugs, ledger, verify;
  - refusals present;
  - `backfill_*` byte-identical.
- **ledger-slug-planner-runtime:**
  - **stages in real order** against a stub post store (critic: old titles): `movies`, then `people` (which retitles nm0604960's post at `jean-hersholt` from "Jean Hersholt" to "Ralph Morgan" and nm0380965's post at `the-motion-picture-relief-fund` to "Jean Hersholt", writing the journal), then `slugs`, ends with `jean-hersholt` on nm0380965's post and `ralph-morgan` on nm0604960's, `_wp_old_slug = the-motion-picture-relief-fund` on nm0380965's post and no `_wp_old_slug = jean-hersholt` on any post;
  - the Hersholt case is planned as one chain with no temporary slug; a two-post cycle uses a temporary slug inside one tick and leaves no `aat-slug-tmp-` value anywhere;
  - a tick budget that expires after the first chain resumes at the second chain, and no chain is split across ticks;
  - an editorial slug is kept; a collision with an out-of-scope post gets `-2`;
  - a plan above `max_slug_changes` renames nothing;
  - a stub `wp_update_post` that appends `-2` stops the stage with `slug_conflict`;
  - studio terms: a term whose slug moves from A to B gets `_aat_old_slug = A`; `AAT_Ledger_Slug_Redirects` answers a 404 for `/studio/A/` with a 301 to the term link, and does nothing on a 200 or for any other taxonomy; term renames above `max_term_slug_changes` rename nothing;
  - the out-of-scope set includes drafts and trashed posts, so a target held by a trashed post is suffixed in the plan rather than conflicting at execution; the preview's `conflict_risk` is 0 for that fixture and 1 when a post is added after the read;
  - the planner is deterministic.
- **ledger-http-runtime:**
  - the Cache-Control matrix; **no data-route Cache-Control contains `stale-if-error`** (fourth critique); the ETag format; the `not_modified` truth table; the CORS header set, including `If-None-Match` and `If-Modified-Since` in the namespace's `rest_allowed_cors_headers` output and not in another namespace's, and ETag, Last-Modified, Link and X-Ledger-Token in `rest_exposed_cors_headers`;
  - `/status/report` reads exactly one `aat_ledger_report_pages` row per request (a stub `$wpdb` records one PK SELECT and no `report` column read);
  - the rate key has no raw IP; the rate mode is `log` and never yields 429;
  - `abuse.daily` keeps 7 buckets and rotates at UTC midnight;
  - `Last-Modified` = max(swapped_at, code_seen_at).
- **ledger-api-contract:**
  - GET-only with `__return_true`; the forbidden tokens in the five read files; the single SQL site; no `SELECT *`; SORTS-only ORDER BY;
  - no Allow-Credentials or `nocache_headers(`; `ledger()` placed before `get_table_name()`; `schemas/` not deployignored;
  - embed SQL contains `post_status = 'publish'` and `post_password = ''`;
  - the status failed-state fixture passes the path/secret regex; `ingest.live.bundle_id` is present.
- **ledger-token-contract:** §6.5, including: it fails when `fixture.plugin_version` differs from `AAT_VERSION`, and when the hash differs with the version equal; and `token()` reads `live_meta_stamp()`, not the option stamp.
- **ledger-query-runtime:** every parameter rule in §6.3, including `imdb` AND over linked identities, the compound sort canonicalization, the 409 cursor and 400 for unknown or array params; `q` terms folded by `AAT_Ledger_Text::fold()`; decade membership equal to `tests/fixtures/ledger/decades.json` (1920s = ceremonies 1–3), which `tests/ledger-read-fixes-runtime.php` also asserts against the hub's `get_category_decade_ledger()` bucketing.
- **ledger-serializer-runtime:**
  - fixtures exported from the deriver: 8165 both Coens; row 2 aligned details; 526 corrected with nm0609771; 12067's group equal to its fixture group stats; row 36 winner and unofficial; row 133 decoded quotes; ART DIRECTION dated at 84/85; row 1588 a null slot; 4507 deduplicated; the last upstream row serialized without IDs; row 10475 with empty titles and credits and its citation;
  - an addition with `added: true` and `source_line: null`;
  - a flagged identity with `review_flag: "needs_review"`, absent from `imdb_ids`;
  - `matches_canonical` cases;
  - a record ≤4 KB and a 200-row page ≤350 KB.
- **ledger-json-schema-contract:** 20 files parse with 2020-12 and the right `$id`; refs resolve; objects are closed; fixtures and envelopes validate; an injected `api_key` fails; an entity with an `external` key fails; no schema names a `wikidata_*` or `birth_year` property.
- **ledger-explorer-state-runtime:** ≥40 URL fixture cases; idempotence; the collision list.
- **ledger-explorer-route-runtime:** the rewrite; 200 without nocache; 503 with nocache and Retry-After; `redirect_canonical` false; 301/302 mappings; wp_robots; the `robots_txt` filter output contains the three Disallow lines for the base slug; from R6, `daily_redirect_health()` with a stubbed `wp_remote_head` records `ok` only for three matching 302s.
- **ledger-explorer-render-runtime:**
  - the visible-text fixtures: Jaynes group; the Jannings two films with no `|`; Moulton Corrected; 12067 "Field: N nominations, M winners" and never "tie"; McGill quotes; Mohr Won + unofficial; "Films of 1932/33"; Bono credited-as Paul Hewson; row 10475 led by its citation;
  - a flagged identity rendered as text with no `<a>`;
  - every pivot, chip, expand and pagination `<a>` has `rel="nofollow"`;
  - the filter `<details class="lle-filter-panel" open>` has the `open` attribute, and its first element after `<summary>` is the §7.3 inline collapse script, byte for byte, **including `data-jetpack-boost="ignore"`**, the only executable inline script inside `#ledger-explorer`; `#lle-status` has `role="status"` and `aria-live="polite"`;
  - `lle-boot` carries `skeleton` equal to `AAT_Ledger_Explorer_View::SKELETON_VERSION`;
  - the attribution line is exact;
  - legacy links only when live;
  - row/template structural parity.
- **ledger-explorer-contract:** security greps (`wp_remote_` only inside `daily_redirect_health`); `credentials:'omit'`; byte ceilings; the 44/48 px rules; reduced-motion and forced-colors blocks; handle names; HEX flags; the `'top'` rewrite; the body class is not `aat-shell-page`; the footer JS never reads or writes the panel's `open`; the JS `SKELETON_VERSION` equals the PHP constant, and boot returns before binding when `lle-boot.skeleton` differs (node harness).
- **ledger-explorer-js-runtime:** runs `node tests/ledger-explorer-js.cjs` over the same URL and render fixtures. It fails when `CI` is set and node is missing.
- **tools-cli-guard-contract:** every `tools/*.php` begins with a `PHP_SAPI !== 'cli'` exit or an `ABSPATH` check.

**CI (theme).** The theme has no CI workflow. Run locally and record:
- `php tests/oscars-positional-link-runtime.php`, `php tests/film-year-label-runtime.php`, `php tests/oscars-dataset-cache-runtime.php` (R1T) and `php tests/oscars-explorer-boundary-runtime.php` (R5);
- `pwsh tests/oscars-read-path-ratchet.ps1`, `php tests/oscars-portal-studio-runtime.php`, `pwsh tests/oscars-ledger-overlay-contract.ps1` (the overlay harness), `pwsh tests/debrief-canonical-film-resolver.ps1` (R1T), `php tests/site-studio-footer-navigation-runtime.php`, `pwsh tests/oscars-portal-studio-contract.ps1` and `php tests/site-studio-oscars-runtime.php` (which runs `tests/site-studio-oscars-winner-cases.php`).

**Local gates** (each must run and exit 0 before its unit is done):
- **`tests/tools/ledger-import-mariadb.php`:** real SQL against the local MariaDB with WordPress core's `wpdb` from a pinned WordPress 6.8.x tree at `$WP_CORE_DIR`. It:
  - checks DDL idempotence (a second `ensure()` issues no ALTER), and that the first public-style request after a version bump (a plain `init` run with no cron) creates no ledger table;
  - loads the legacy parse into a master and runs the legacy rebuild to create "live", then plants **media-recovered** nominee rows (10 fixture links on ID-less rows, 3 of them the only link of their entity) to model production;
  - runs the job in dry_run and asserts `simulation_match` true with `media_explained` counts equal to the planted ones; then swap;
  - **production drift and remediation:** on a second fixture "live", clears 5 winner flags, corrupts 3 names with U+FFFD and edits one row's `nominee_ids` through the legacy person-credit lane; the dry run reports `simulation_match: false`; the `metric:master_rows_changed` section lists exactly those 9 rows with their `differing_fields`; running `tests/tools/classify-drift.php` over the report yields 5 `production_lost_flag`, 3 `production_mojibake` and 1 `production_edit`; after the regenerated bundle carries 8 accepted items and one overlay correction, the next dry run matches; a 10th row deleted from the fixture live master is resolved by a `restored` entry, and a row whose `film_id` an admin changed is matched by pass 3 or by a pin;
  - **stamp consistency:** between the RENAME and F2 (a breakpoint in the gate), `live_meta_stamp()` already returns the new stamp while the option still holds the old one;
  - kills a load mid-way, and a prepare mid-way, and resumes;
  - runs two ticks at once (exactly one gets the lock);
  - forces a RENAME lock-wait;
  - performs rollback and restore, asserting `aat_after_data_import` did not fire for the pre-ledger restore;
  - **(i) rollback then automatic fix-forward** (third critique, blocker 1): swap bundle B1; ship a `mode: rollback` manifest for B1 (state `rolled_back`, pin = B1); then install a fix-forward bundle B2 (a changed overlay entry, regenerated with `--baseline=legacy`) under a new `AAT_VERSION` stub, and drive **only** the `init` version check and cron ticks (`do_action('aat_ledger_tick')`), no CLI and no admin call. It must reach `current` for B2, with the version check's outcome `queued`, `_l0` again holding the pre-ledger snapshot, the pin deleted and `aat_after_data_import` fired once. It also asserts that re-installing B1's manifest under yet another version returns `refused_pinned` before B2 swaps, and that with the kill switch on in `rolled_back` the outcome is `deferred_frozen` and the job runs as soon as the switch is lifted;
  - runs two consecutive daily heartbeat checks on an untouched live dataset and asserts no drift either time (generated columns excluded); then updates one live row and asserts drift and a **re-assert of B1, whose manifest baseline is `legacy`, reaching `current` in mode `reassert` without `simulation_baseline_mismatch`** (fourth critique), with `_lprv` checksums unchanged, `_ldft` created, a new stamp, and `reassert.drifted_tables`/`drifted_rows` equal to the edit; after a second forced edit within 26 hours, `drift_recurring` with a second re-assert; a re-assert against a stage whose checksums differ from the accepted V10 values stops with `reassert_derivation_changed` and swaps nothing;
  - **(k) blocked swap, then a pin, then a match** (fourth critique): with the fixture live master edited through a legacy lane after the dry run, a swap-mode job blocks with `ids_new_upstream > 0` and leaves `aat_ledger_award_keys` row-for-row unchanged (empty on a first import); the regenerated bundle with the evidenced pin reaches `current`, the pinned row keeps its live id, and F3 run twice leaves the persistent table identical;
  - **(l) the public SQL loads**: `schema.sql` then `data.sql.gz` into a fresh strict-mode database, then `integrity.sql`; every "should be empty" query returns nothing and the count query equals `manifest.expected` (the README's own procedure);
  - runs a second swap (`_l0` kept) with a bundle whose recorded baseline is the first bundle;
  - enables freeze and bumps the version (master and projection checksums unchanged; no legacy rebuild ran; Control Desk drift counts 0 with the `ledger` variant);
  - asserts live content equals the plan (V10), Control Desk drift counts 0 and a new stamp on every swap.
  - Production is likely MySQL 8 and unverified; this gate is MariaDB only.
- **`tests/tools/ledger-memory-gate.php`:** §4.6.4.
- **`tools/verify-ledger-api.php --explain`:** against that database.
- **PHP vs Python parity:** the PHP serializer over every nomination against `reference_serializer.py` adapted to deriver names.
- **Browser gate** (`tests/ledger-explorer-browser.cjs`, Playwright):
  - widths 360/390/414/768/1280, JS on and off;
  - targets, overflow, focus order, one h1;
  - the JS-on first DOM equals the JS-off DOM, ignoring only the filter panel's `open` attribute below 1024 px;
  - the panel is closed at 390 px and open at 1280 px in the first painted frame, and never changes afterwards without user input; in `--mode=live` the same assertion runs against the production page, and the served HTML still has the collapse script as the first child after `<summary>` with `data-jetpack-boost="ignore"` (i.e. Boost did not move it);
  - CLS ≤ 0.05 at 390 and 1280 with JS on and off, **also with a stale Boost critical block replayed** before the plugin stylesheet (the live `/oscars/ceremonies/` block in live mode, `tests/fixtures/ledger/boost-critical-replay.css` offline), and the seed-only and seed-plus-block first paints within 1 px in the rail, results and hero boxes (§7.6); LCP/INP against the measured baseline (§7.7);
  - request counts, reduced motion, keyboard-only typeahead;
  - `aria-busy` toggling and one `#lle-status` announcement per update.
- **Mutation checks** (inside CI tests where marked, otherwise run once and reported in the PR):
  - flip one overlay before-value → `overlay_before_mismatch` (CI);
  - inject a backslash into a copied CSV cell → `codec_violation` (CI);
  - an addition element carrying an extra key (e.g. `source_row`) or missing `verification` → `addition_invalid`; a `Ceremony` given as `97.0` or a `Winner` given as `"yes"` → `addition_cell_type` (CI);
  - a correction chain whose second `before` differs from its first `after` → `overlay_before_mismatch`; a correction whose `nomination_id` is a string → `correction_invalid` (CI);
  - a duplicate-upstream addition → `addition_duplicates_upstream` (CI);
  - an unmapped character (e.g. `ș`, U+0219, outside the vendored map) in an addition → `fold_unmapped_char` (CI);
  - a QID cell in a copied `entities.tsv` → the privacy contract fails (CI);
  - a Wikidata URL or a parenthesized life span appended to a copied `corrections.json` evidence → the privacy contract fails and `--check` reports `evidence_not_redacted` (CI);
  - the saved live HTML of the four §1 defects and of `/oscars/ceremony/55/` → `verify-credit-links-live.sh` exits 1 on each (CI, through `tests/ledger-credit-links-contract.php`, which runs the script's parser in fixture mode);
  - truncate one stage cell in the MariaDB gate → `checksum_mismatch`;
  - break one pinned display name → the display-name runtime fails (CI).

**Live checks.** Per release, in §12. They are anonymous, sequential GETs with no cache-busting headers and at most one `/status` poll per minute. Every probe result goes into the session-log "Verified live state" table.

---

## 12. Release sequence

Every release follows the same pattern:
1. Branch `claude/<topic>-<version>` from current `main`.
2. Back up every file with `cp` before a mutating edit.
3. Implement and run the gates: every `tests/*.php` in a per-test loop that reports each failure (CI's `set -e` hides later ones), `php -l`, `node --check`, and the unit's local gates.
4. Open a draft PR, merge after CI and the gates pass. **Merge = deploy** (decision 14). Probe the version with the §3 probe (the WordPress.com plugin list first, `/status` as confirmation); **if it is not live within 10 minutes of the merge, report it to the owner and stop the train.**
5. Verify live.
6. Append a session-log entry in the theme repo (no ratchet literal), merge it, and rebuild the rollback hatch (tree `c55bf394…`).

**The first session-log entry of this train (R1) records decision 14:**
- Dalton's instruction to open, merge and ship (already quoted in the top entry);
- automatic deployment confirmed ON for the theme and Oscars plugin connections, per the lead's report of Dalton's confirmation, and proven for the plugin by R1 going live within 10 minutes. It supersedes the top entry's "unconfirmed, treat as manual" line through a correction pointer inside that entry, not an edit of it;
- that AGENTS.md:72, :81 and :167 still say otherwise and are Dalton's to change. No agent edits AGENTS.md.

**Probe discipline for every HTML check from R2 on.** Each probed page's `<meta name="aat-dataset">` stamp is compared with `/status` `ingest.live.stamp` before the check is judged:
- **stale** (the page's stamp is the previous one, or the page has no meta because it is a cached pre-R1 copy): the edge is still serving an old copy. Re-probe every 5 minutes for up to 60 minutes. Still stale at 60 minutes → report "edge cache not converging" to the owner and stop the train. Never a rollback: the data layer is right, as `/status` shows.
- **current** stamp and the check fails → the check's failure class decides (below).
- Theme pages (`/talent/`, `/film/`) carry no ledger meta; they are judged only after the builder has converged (class B).

### R1, plugin 2.8.0 (U00–U12)
Generate `data/ledger` in mode `dry_run` with `--baseline=legacy`, after PR #39 (the reconciled overlay: 372 corrections, 1 addition, 1 open needs-review item) and any later overlay PR have merged to `main`; the PR lists the `main` commit it was generated from. Live:
1. The version probe of §3: within 10 minutes the WordPress.com plugin list shows 2.8.0, then `/wp-json/lunara-ledger/v1/status` answers 200 with `software.plugin_version` `2.8.0` within 3 further minutes.
2. Within 3 hours:
   - `ingest.state` = `validated_dry_run`;
   - `last_result.simulation_match` = true, and not blocked (the per-metric table shows `media_explained` and `drift_explained` separately); every entry of `last_result.sentinels` (V3 on the stage) is ok;
   - cron evidence per §4.6.11. Without it → the stop-and-ask path.
   - If the dry run does not match or is blocked by `ids_new_upstream`: the remediation loop (§4.6.6, U29, release R1b). This is not a stop; the owner is told the result.
3. `/status/report` sections show the unsourced-link count, the media-touched counts, the slug preview (renames, swap cases, cycles, term renames, `conflict_risk`), the link-override count and the legacy-guard `pairs`, `label_pairs` and `never_link` counts.
4. `/oscars/name/nm0380965/` still shows the pre-swap title.
5. The `aat-dataset` meta tag reads `state=validated_dry_run`.
6. The Live Action Short category hub shows both 98th-ceremony winners.
7. `/oscars/category/scientific-and-technical-award-academy-award-of-merit/` shows row 10475's citation ("To all those who built and operated film laboratories, for over a century of service to the motion picture industry.") on its 86th-ceremony card (the citation fix is active in R1, §4.10).
8. No earlier than 65 minutes after the deploy (a pre-R1 ceremony rollup transient lives one hour, `main:2728`, and the edge adds its TTL), `bash tests/tools/verify-credit-links-live.sh legacy` exits 0 over the generated cases (§5.5: rules P1–P5). In particular: `/oscars/title/tt0145781/` has no anchor to co0141760; `/oscars/title/tt0036868/` has no anchor to co0058013; `/oscars/title/tt0031385/` has no "Denham" anchor; the Class III hub has no anchor to nm0429444; the Dodsworth page has no 'Thomas T. Moulton' → nm0481264 anchor; **`/oscars/ceremony/55/`, `/29/` and `/67/` have no anchor to tt0169446, tt0046593 or tt0094416 and show no poster of them**; no probed page links nm0239470; the Minstrel Man and Sunset Blvd. pages link nm0916990 and nm0772834.
9. `/wp-json/lunara-ledger/v1/license` returns the BSD-2 text.
10. At least 15 minutes after the deploy (the theme's search cache lives 10 minutes, theme `inc/live-search.php:265`), `/wp-json/lunara/v1/search?q=radio%20corporation` returns no result linking `/oscars/name/nm0429444/` (the search-feed guard, §4.9 item 12).
11. **Report to the owner** (a message plus the session log; no approval needed):
    - the dry-run summary, the simulation comparison table (sourced, media-explained and drift-explained), `display-name-changes.tsv`, the slug preview and `slug-collisions.tsv`;
    - the legacy window: until R2, known-wrong, under-review and first-label-wins pairs and links to IDs the ledger links nowhere render as plain text rather than as wrong links, with the guard's collateral count; label-guessed links on ID-less SciTech and Special rows are gone (as they will be after R2);
    - **the public repository**: `data.sql.gz`, `schema.sql`, the evidence strings, the xlsx and the design drafts no longer carry Wikidata QIDs, Wikidata years or life spans, but git history (commit `d7a3bda`, merged by `021db1f`) and any fork or clone still do; removing them from history needs a history rewrite and a force-push, which is his call.
`bash tests/tools/verify-ledger-r1-live.sh 2.8.0` automates checks 1 and 3–10.

### R1b, plugin 2.8.1, only if R1's dry run does not match (U29)
The remediation loop of §4.6.6, run by the agent alone: read the difference lists, classify, add evidenced overlay corrections or accepted-drift entries and pins, regenerate with `--baseline=legacy`, gate, merge as the next patch in mode `dry_run`, and re-check R1's checks 1, 2, 3 and 8. At most three dry runs in total (R1 plus two R1b releases); if the third does not match, report the remaining items to the owner and stop the train. Every later release number shifts by one per R1b.

### R1T, theme 3.2.91 (U13)
It may ship any time after R1 is merged, and must be live before R2 merges. Live:
1. `bash tests/tools/lunara-canary-verify.sh 3.2.91` exits 0.
2. `/film/cavalcade/` JSON-LD `datePublished` is still `1932` and its award history still prints `1932` (the stored meta is still the flattened start year until R2's resync), which proves nothing else moved.

### R2, plugin 2.8.1 (U14): automatic gate (decision 4)
**Pre-merge gate**, read from `/status` and `/status/report`; the release is not merged, and the owner is told why, unless all hold:
- `ingest.state = validated_dry_run` for the latest dry-run bundle;
- `simulation_match = true`, `blocked = false`, and every stage sentinel ok;
- the slug preview is within its bounds: post renames ≤ `max_slug_changes`, term renames ≤ `max_term_slug_changes`, and `conflict_risk` = 0 (third critique: the bound was first tested after the swap);
- cron evidence ok;
- R1T live (`lunara-build` 3.2.91).

Then set manifest `mode: swap`. If overlay entries landed after the last dry run, regenerate the bundle with `--baseline=legacy` (`/status` still reports no live `bundle_id`); the swap-mode job runs the same validation and simulation gate on the new bundle before any RENAME, so an unmatched bundle can only block, never swap (§4.6.6). The bounds are regenerated, never hand-raised. Bump the version, gate, merge.

**Probe discipline** (above) applies to every HTML check.

**Live checks and their failure classes** (third critique: classify by data-layer truth first).

| # | Check | Class |
|---|---|---|
| 1 | The §3 version probe shows `2.8.1` (plugin list within 10 minutes, then `/status`) | — (not classed: report and stop) |
| 2 | `/status`: `ingest.state` `current`, a 12-hex stamp, `swap_seq` 1; `ingest.live.counts.nominations` and `.winners` equal `manifest.expected`; **every `ingest.live.sentinels` entry is ok** (the server's own SQL checks of names and slot identities on the live ledger tables, including rows 965, 8165 and 1814, §4.6.5) | **D** |
| 3 | On stamp-current pages: `<title>` is Jean Hersholt on `/oscars/name/nm0380965/`, Ethan Coen on `/oscars/name/nm0001053/`, Paul Francis Webster on `/oscars/name/nm0916990/` and Barney "Chick" McGill on `/oscars/name/nm0569222/`; `/oscars/title/tt2175842/` shows literal quotes; `/oscars/title/tt0019553/` lists the 1927/28 Jannings row | **R** |
| 4 | The first five `entities_removed` IDs of the report (sourced ones first), and nm0239470 (the flagged-only ID), return 404 on their `/oscars/{title\|name\|company}/` route | **R** |
| 5 | For every appended row, its category hub (`get_category_url(canonical)`, e.g. `/oscars/category/scientific-and-technical-award-academy-award-of-merit/` for the captioning award) contains its Nominees text, or its Citation when Nominees is empty (R1 check 7 proved the citation rendering on row 10475) | **R** |
| 6 | `bash tests/tools/verify-credit-links-live.sh live` exits 0 on stamp-current pages (P1–P5 over the generated cases, including Minstrel Man → nm0916990, Sunset Blvd. → nm0772834, Goodbye, Mr. Chips → A. W. Watkins only, For God and Country → co0003606, ceremony 55's highlight → tt0084185) | **R** |
| 7 | Within 15 more minutes, `/wp-json/lunara/v1/search?q=hersholt` links nm0380965 | **B** (theme search, stamped key) |
| 8 | After the builder resync (`convergence.graph_resync.running` false; poll `/status` every 5 minutes for up to 3 hours): for each swap case in `/status/report?section=slugs` (expected to include `jean-hersholt`), `/talent/{slug}/` carries sameAs of the new holder (nm0380965), and the former holder's new slug carries its own ID (nm0604960 at `/talent/ralph-morgan/`) | **B** |
| 9 | **Driven by the report** (third critique: revision 3 hard-coded one URL): for every chain member with `record_old_slug` true in the executed plan (up to 20, swap cases first), `/talent/{from}/` or `/film/{from}/` returns 301 to its `to` slug; for up to 5 studio-term renames, `/studio/{from}/` returns 301 to the term's new link | **B** |
| 10 | `/film/cavalcade/` award history shows `1932/33` in every row and its JSON-LD has no `datePublished` | **B** |
| 11 | `convergence.graph_resync.slug_stage` reports none of `slug_bound_exceeded`, `slug_conflict`, `retire_bound_exceeded` or `studio_duplicate_terms`, and `refused` is empty | **B** |

**How a failure is classified.** Check 2 is the data-layer truth: `/status` state and counts, and the live sentinels the server computes in SQL against the swapped ledger tables at F1 and daily. The HTML checks 3–6 are judged **only after check 2 passes**:
- **Class D (data layer wrong): check 2 fails on a current state.** Ship the next patch with manifest `mode: rollback` (never a code revert), then verify `rolled_back`, a new stamp, the pre-swap `/oscars/` titles, `verify-credit-links-live.sh legacy` exit 0, and, no earlier than 600 s after the rollback, a 503 `ledger_unavailable` from `/wp-json/lunara-ledger/v1/nominations?limit=1` (the edge may serve its last 200 for at most `s-maxage` + `stale-while-revalidate`, §6.1). **Report to the owner** that the rollback republished the pre-ledger `/oscars/` names (known wrong, first-label-wins), that misaligned and guarded pairs show as plain text, and that `/talent/` and `/film/` keep the ledger names and migrated slugs because the builder does not resync on a pre-ledger restore (§4.6.7 F5). Then ship the fix-forward bundle as its own release through the same automatic gate: it imports by itself, because the pin blocks only the rolled-back bundle (§4.6.9).
- **Class R (render fault on a correct data layer): check 2 passes and any of 3–6 fails on a stamp-current page.** The data is right and a template or read path is wrong, so a rollback would only republish known-wrong names. Re-probe once after 10 minutes; still failing → ship a **code** fix-forward (the next patch, no manifest change, so no re-import), and report to the owner. `tests/ledger-render-closures-runtime.php` and the fixture-mode probe make this class unlikely: every hub closure and the title line have been rendered in the live state before the merge (§5.5).
- **Class B (builder, slug and theme):** never a dataset rollback, because a rollback cannot repair them (the builder refuses to run in `rolled_back`, and a rollback would leave the migrated slugs with pre-ledger titles if it did run). Re-probe every 10 minutes for up to 60 minutes. Still failing → ship a fix-forward patch for the builder, slug stage or theme code. A builder fix bumps `AAT_Entity_Graph_Builder::REVISION`; the builder's daily heartbeat and the ledger's version check start a resync by themselves when the last completed run's revision differs and the state is `live` (§4.11 m), so no admin action is needed. Report to the owner.

`bash tests/tools/verify-ledger-r2-live.sh 2.8.1` runs checks 2–11 with the probe discipline and prints each check's class and result. Every outcome, including a clean pass, is reported to the owner in the session log.

### R3, plugin 2.8.2 (U15–U19)
Implement the API and copy and adapt the 20 stripped schema drafts from `docs/design/ledger-2.8/`. Gates plus local EXPLAIN and PHP-vs-Python parity. Live:
1. `/status` `ready:true` with a 16-hex token that differs from any token observed under 2.8.1.
2. MISS then HIT on `/nominations?ceremony=98&limit=5`.
3. The Origin-header probe (HIT expected; a BYPASS is recorded).
4. 304 on If-None-Match; 400 for `?page_size=1`; HEAD returns headers only.
5. Spot checks:
   - nm0380965 is "Jean Hersholt";
   - both Coen identities on `source_row` 8165;
   - `/titles/tt0019553` exists;
   - `/ceremonies/6` has `year_label` `1932/33`;
   - `/people/nm0239470` returns 404 (the flagged-only ID of the one open needs-review item; in general every ID whose only credits are flagged, read from the report);
   - no `external`, `wikidata` or `birth_year` key anywhere.
6. `php tools/verify-ledger-api.php https://lunarafilm.com` (a full cursor sync at ≤1 request per second, compared with the corrected TSV) exits 0.
7. `/status.abuse.mode` = `log`, and `abuse.daily` has a bucket for today.

### R4, plugin 2.8.3 (U20–U23)
Re-probe the preconditions: `pages?slug=explore` returns [], and `/oscars/explore/` returns 404. Live:
1. `tests/tools/verify-explorer-live.sh 2.8.3` passes.
2. `/robots.txt` contains the three Disallow lines. If the host serves a static robots.txt that ignores the filter, record it; noindex and nofollow still apply. (Today's file is WordPress's virtual one, §1.)
3. The live-mode browser gate at 390 px passes against the baseline measured in the same run, including CLS ≤ 0.05 **with and without the replayed stale Boost critical block** (§7.6) and **the filter panel closed in the first painted frame at 390 px** (and open at 1280 px), with the served collapse script still in place after `<summary>` carrying `data-jetpack-boost="ignore"` (third critique: Boost's deferral). The session log records whether the served page carries a `jetpack-boost-critical-css` block and its size. A CLS failure is **report and stop**; no step regenerates Boost's critical CSS (fourth critique).

### R5, theme 3.2.92 (U24)
Only after R4 is verified live. Local gates. Live:
1. `bash tests/tools/lunara-canary-verify.sh 3.2.92` exits 0.
2. The explorer's body class is `lunara-oscars-family`, with no dossier-studio CSS.
3. The footer Full Ledger link goes to `/oscars/explore/`.

Rebuild the hatch.

### R6, plugin 2.8.4 (U25)
E2 cutover. Live: 302 Locations for the portal, ceremony 98 and best-picture category variants; the hubs and portal are otherwise unchanged; canary clean; after the next `aat_ledger_daily`, `/status.explorer.redirect_health` has an `ok` bucket. Schedule the 8-day routine (§7.9) and record its id.

### R7, plugin 2.8.5 (U26)
Only if the census is clean or every hit is converted. Live: no `cdn.datatables.net` on `/oscars/`, the hubs or a shortcode page.

### Later
- U27 (302 → 301) when `/status` shows seven consecutive clean days (§7.9).
- U28 (limiter enforcement), only when `/status.abuse.daily` shows `distinct_clients_per_minute_p50 > 1` and `top_client_share < 0.5` on each of 7 consecutive days. Otherwise the limiter stays in log mode, and this is reported to the owner.
- New overlay entries (corrections, additions, needs-review resolutions) ship as a regenerated bundle with a new `dataset_version`, generated with `--baseline=<the live bundle_id from /status>`. They re-import automatically on deploy and are verified the R2 way: the automatic simulation gate applies to every swap, and the failure classes are the same.
- The theme live search may switch to the ledger search endpoint (alias matches such as "jaynes") in a later theme release.

---

## 13. Risks

1. **WP-Cron cadence on Atomic is unverified.** R1 must show heartbeat and tick evidence before R2 (§4.6.11). The stop-and-ask path offers three unblocks, one of them a single admin button. Until then, ticks run only when cron spawns.
2. **GET_LOCK behind WordPress.com's database layer is unverified.** The lease fallback covers it, and resuming from `COUNT(*)` makes a double tick harmless.
3. **Metadata-lock stalls on RENAME.** Bounded by `lock_wait_timeout` = 5 and up to 5 retries.
4. **The builder resync takes 35–80 minutes after a swap.** Relationships are now replaced per movie without an empty window (§4.11 k), and existing `ledger_entry` titles are refreshed (§4.11 e); during the run, some movies show the old relationships and some the new ones. Gating on `consumer_sha256` limits resyncs to real changes.
5. **Slug migration changes public URLs** (bounded by `max_slug_changes`; old URLs 301 through `_wp_old_slug`). The edge serves the old 200 for up to its TTL (~5 minutes), then the 301. External links to a swap-case slug land on the new holder, the correct person for the label. Chains move whole inside one tick, so there is no multi-tick 404 window. The owner reads `slug-collisions.tsv` after the fact.
6. **Display-name changes** (count `simulation.labels_changed`) are applied without a pre-approval gate (decision 1). Every change replaces a first-label-wins label with the Academy's most-used credit, and the list is published. `name-overrides.tsv` is the lever for later exceptions (e.g. Common / Lonnie Lynn, 49th Parallel / The Invaders). Credit-mode names make label re-routing unsafe, which is why it is off while the master is ledger-derived (§5.2 rule 6).
7. **Media-recovered links disappear from person pages.** Bounded by `manifest.idless` and listed in the report, so they can be converted into verified corrections.
8. **An upstream refresh that shifts rows makes before-checks refuse.** This is fail-safe; a rebase tool keyed on `nomination_key` is not built yet.
9. **`derived_sha256` must agree across PHP 8.2 (CI), 8.4 (local) and production.** CI re-derives under 8.2; production V9 refuses on a mismatch (terminal, visible in `/status`). Folding no longer depends on mbstring or `remove_accents`; the remaining mbstring use (display-name case rules) touches only the dataset's 39 non-ASCII characters, all in Latin-1 and Latin Extended-A, whose case mappings did not change between those versions.
10. **Staleness after a swap.** Edge caches serve pre-swap HTML for about 5 minutes and unversioned REST for up to `s-maxage` + `stale-while-revalidate` = 10 minutes (the R2 probe discipline detects stale copies by their stamp; no `stale-if-error`, so a rollback or freeze reaches the edge within those 10 minutes, §6.1). Plugin transients rotate with the stamp; singletons are re-deleted at +5 minutes (F6). The theme's long-lived Oscars transients (6 h–1 day) and its live-search key rotate with the stamp from 3.2.91, which R2 requires, so no theme cache outlives the swap by more than its edge TTL. The two 15-minute theme keys can hold pre-swap data for up to 15 minutes (critic correction: revision 2 claimed 5–10 minutes overall).
11. **REST with an Origin header may BYPASS the edge.** Server-side use is recommended; browser use is supported (decision 11).
12. **The edge or Batcache may ignore `s-maxage` or `immutable`.** Checked on a live HIT in R3.
13. **`REMOTE_ADDR` on Atomic may be an edge address.** The limiter is log-only, and enforcement (U28) depends on the published daily distribution.
14. **A persistent object cache is unverified.** Without one, only hot API keys persist, and link overrides cost one PK query per rendering request.
15. **Explorer filter URLs multiply cache keys.** Mitigated by nofollow, robots.txt Disallow, noindex and canonical order. The host serves WordPress's virtual robots.txt today; R4 re-checks.
16. **The browser, MariaDB and memory gates are local.** A unit is not done until they run.
17. **Every API token rotates on every plugin release** (the first-critique blocker 3 fix). The first explorer and API requests after a release are cold at the edge; `aat_ledger_warm` mitigates.
18. **Editors cannot store a split year label in Core's number fields.** The Oscars plugin's ACF `load_field` filter turns those two fields into validated text fields (§4.11 b). If Core later renames the field keys, the filter stops applying. The builder still writes correct meta; only the admin input would regress.
19. **Dry-run stage tables temporarily add about 150k rows to production.** They are dropped at the end of the dry run and swept after 24 hours.
20. **Overlay changes change every count** (PR #39 alone moved the corrections from 157 to 372 cells, added one row and settled seven of eight needs-review items). Nothing is hard-coded outside the manifest and the generated bundle files; the only count literals left pin the upstream CSV (§10); the builder's `--check` and CI keep the bundle, the docs artifacts (TSV, SQL dump, `integrity.sql`, workbook) and the tests consistent.
21. **Git history keeps the removed Wikidata columns and the unredacted evidence.** Only a history rewrite, Dalton's call, removes them (§4.14 item 9).
22. **The legacy link guard suppresses some correct links** while the master is not ledger-derived (its published `collateral` count). This is deliberate: a missing link is recoverable, a wrong one is a mislabel.
23. **A rollback to the pre-ledger data leaves `/talent/` and `/film/` on ledger names while `/oscars/` shows pre-ledger names** until the fix-forward swap. It is reported to the owner with every rollback.
24. **The daily redirect-health loopback may be answered by the edge rather than origin.** That is what visitors see too, so it is the right thing to measure; a loopback failure (timeout, DNS) records the day as not clean and only delays U27.
25. **The remediation loop may not converge.** Production may have drifted in ways that neither an overlay correction nor accepted drift explains well (e.g. a bulk edit). The loop stops after three dry runs and reports the remaining items (§4.6.6); nothing swaps meanwhile, and the site stays on the legacy data with the legacy guard.
26. **Accepted drift could hide a real problem.** Every item needs a class, a reason and evidence, is pinned to the production row's sha1 (a later edit re-surfaces it) and is published in the repository; the classes that accept without an overlay change are limited to values the ledger already fixes (`already_applied`, `production_lost_flag`, `production_mojibake`) or explicitly superseded values.
27. **ID-less SciTech and Special names lose their label-guessed links in R1** (§5.2 rule 7). Some of those guesses were right. This is the fail-safe choice (a lost link, never a wrong one), and it is also the post-swap behaviour.
28. **Studio-term redirects are plugin code, not core.** WordPress has no old-slug redirect for terms; `AAT_Ledger_Slug_Redirects` answers only genuine `lunara_studio` 404s. If Core later changes the taxonomy's rewrite slug, the redirects still resolve by term, not by path.
29. **A rollback now needs a data-layer failure** (§12 R2). A render fault on a correct data layer is fixed in code while the corrected data stays live; the probe's fixture mode and the render-closures runtime test make such faults unlikely to reach production.
30. **The never-link rule now acts in `build_entity_url_from_id()`**, so in `legacy` and `rolled_back` it also removes links from admin tools (tracker, poster admin) for the 33 never-link IDs, and no poster is shown for the four never-link titles. Both are fail-safe (a lost link or image, never a wrong one), both end at the swap, and the method rule is inert while `master_is_ledger()`.
31. **A re-assert trusts the recorded V10 checksums.** If live meta's `validation` row is itself damaged, the scope check refuses (`reassert_derivation_changed`) rather than restoring; the drift stays flagged (`drift_recurring`) and is reported, and a fix-forward bundle (a new `bundle_id`) repairs it through the normal gate.
32. **The public SQL dump now carries the ledger's display names** (credit mode) instead of the audit's Wikidata-labelled canonical names, and omits the one flagged identity. That makes it agree with the site and the API; the audit's earlier dumps stay in git history (§14.3 item 9).

---

## 14. Decisions, owner reports and known divergences

### 14.1 Decisions applied (from the lead, under Dalton's delegation)

| # | Decision | Where applied |
|---|---|---|
| 1 | Display names by `credit_mode/1`; no pre-approval gate; `display-name-changes.tsv` published for reading after the fact; `name-overrides.tsv` empty at launch | §4.1, §4.4.2, §12 R1 report |
| 2 | No Wikidata years anywhere; verbatim year labels (never `1932`); eligibility-year behaviour kept; no QIDs or birth years publicly, including the public repository; no `external` API block; `release-year-changes.tsv` and `wikidata_bounded` deleted | §4.1 (name source), §4.3 (DDL), §4.4.3, §4.10, §4.11 a–c, §4.14, §6.4, §6.7, §8.1 items 6–8 |
| 3 | Legacy write tools refused while the ledger is live (and while frozen or rolled back); drift re-asserted every 24 hours | §4.6.1, §4.6.8, §4.9 item 7 |
| 4 | The first swap's bounds are automatic; R2 proceeds when the R1 production dry run matches the simulation within the bounds; the result is reported, not approved | §4.1 (generated bounds, `idless`), §4.6.6 (provenance, V13), §12 R2 |
| 5 | Needs-review resolutions ship in the overlay; unresolved identities are served unlinked in every state, with `review_flag` in the API and schema | §4.1, §4.4.4, §5 (legacy guard §5.6), §6.4, §7.3 |
| 6 | The slug migration ships with R2: collision-aware, two-phase only for cycles, native `_wp_old_slug`, bounded | §4.11 i–j, §4.12, §12 R2 |
| 7 | `NOTE: NOTE:` stays verbatim | §4.4.1, V3 |
| 8 | Plugin version at `/status` | §4.8 |
| 9 | Rate limiter log-only; enforcement later, after REMOTE_ADDR evidence | §6.6, U28 |
| 10 | The attribution line on `/status` and the explorer; licence checked and stated | §1, §4.8, §7.3 |
| 11 | Server-side and browser calls both supported (CORS `*`); server-side recommended | §6.5, §6.10 |
| 12 | Core `ledger_entry` REST exposure out of scope, listed as a divergence; builder fixes apply (clear stale meta, verbatim year label) | §4.11 c–e (stale meta cleared, verbatim year label, titles refreshed on existing `ledger_entry` posts), §14.3 |
| 13 | Explorer: `/oscars/explore/`, "Oscar Ledger Explorer", typographic CSS hero (text LCP), O3 labels, nofollow pivots and robots.txt rules, 302→301 after 7 clean days, E3 on a clean census, portraits off | §7 |
| 14 | Merge = deploy for theme and plugin; the "ask Dalton to deploy" steps are removed; if a version is not live within 10 minutes, report and stop; recorded in SESSION-LOG; AGENTS.md untouched | §3, §12; §4.6.9 (a rollback's fix-forward also needs no admin or shell) |
| 15 | Overlay supports appended rows (`additions.json`, `source_row` implied from `source.rows + 1`, with evidence and verification), read exactly as shipped; every count comes from the manifest; `corrected_sha256` covers upstream plus additions | §4.1, §4.2, §4.5, §10, §11 |

**Licence (decision 10).** DLu/oscar_data is distributed under the **BSD 2-Clause License, "Copyright (c) 2022, David V. Lu!!"** (re-fetched again in this session from `https://raw.githubusercontent.com/DLu/oscar_data/main/LICENSE`; the GitHub API for that repository answers 403 to this session). The plugin already redistributes that data as `data/oscars.csv`, and the API redistributes it further. Clause 2 is met by three things together:
- shipping the notice verbatim as `data/LICENSE-oscar_data.txt`;
- serving it at `/wp-json/lunara-ledger/v1/license`;
- linking it from `/status` and the explorer.

The attribution line credits the Academy as the underlying source. The licence covers DLu's compilation; it grants nothing over the Academy's own text.

**Provenance of decision 14.** The instruction to merge and ship is Dalton's own, quoted in the theme SESSION-LOG top entry. The plugin connection's auto-deploy setting reaches this plan through the lead's report; the 10-minute rule makes R1 its proof, and a failed proof stops the train with nothing else changed.

### 14.2 Reported to the owner (no approval needed)
- **R1:** the dry-run summary; the simulation comparison (sourced, media-explained and drift-explained); `display-name-changes.tsv`; `slug-collisions.tsv`; the slug preview (including studio terms and `conflict_risk`); the unsourced-link count; the cron evidence; the legacy-guard window, its three list counts and its collateral count; the removal of label-guessed links on ID-less rows; **the public-repository note**: the Wikidata columns, Wikidata links, Q-numbers and life spans are gone from the tracked files (evidence now names Wikidata as a source without linking it), git history (`d7a3bda`) and forks still hold them, and purging needs a history rewrite and force-push (his call). Also: `docs/database/data.sql.gz`, `integrity.sql`'s counts and the workbook are now regenerated from the ledger by the bundle builder (display names, linked identities only), and the never-link rule of the legacy window also removes the four wrong-ID title cards' links and posters (e.g. `/oscars/ceremony/55/`).
- **R1b (if any):** each remediation round's classified items (counts per class, the overlay corrections added with their evidence, the accepted-drift entries, the pins and restored rows) and the new match result.
- **R2:** the swap result, the live sentinels, each check's class (D, R or B) and outcome, the builder convergence and the slug-stage result. On a rollback: that the pre-ledger `/oscars/` names were republished and `/talent/` and `/film/` kept the ledger names, and the version and result of the automatic fix-forward. On a render fault: the code fix-forward and the data that stayed live.
- **Any stop:** a version not live within 10 minutes, cron evidence missing, a third dry run that still does not match, `simulation_baseline_mismatch`, a terminal failure, an edge cache not converging within 60 minutes, or a slug or retire bound exceeded. `drift_recurring` is reported but is not a stop: the re-assert keeps running every 24 hours (decision 3).

### 14.3 Known divergences (documented, not fixed in this train)
1. **Lunara Core `ledger_entry` REST exposure** (decision 12). `wp/v2/ledger_entry` stays anonymously exposed (`public => false`, `show_in_rest => true`, Core `class-lunara-entities.php:94-109`). Its field "Ceremony Year" holds the film eligibility year label, verbatim from R2, under a label that says "Ceremony Year".
2. **`/talent/` and `/film/` award counts differ from `/oscars/` and the API.**
   - The theme counts `ledger_entry` posts through the `person` meta (`inc/entity-surfaces.php:28-79`), and the builder links a person only for an nm `primary_entity_id` (`builder:482-485`). Example: Christopher Nolan shows 5 nominations / 1 win on `/talent/` against 8 / 2 on `/oscars/name/nm0634240/`.
   - The builder fixes remove the stale-meta and flattened-year parts of this divergence, and R1T prints the labels unflattened, but the primary-only linking stays.
   - The ledger (`/oscars/`, the API, the explorer) is authoritative.
3. **Lunara Core** extracts a 4-digit year from `release_year` for its own comparisons (`class-lunara-debrief-migration.php:640`). Display paths print the label verbatim.
4. **Movie archive order** casts `1932/33` to 1932 for sorting (`meta_value_num`). Display is verbatim.
5. **The admin auto-link helper** (theme `inc/control-desk.php:7723-7727`) declines to auto-link a review whose year is `1933` to a movie whose label is `1932/33`; it fails safe (no link, a manual link remains possible) and affects only films of ceremonies 1–6.
6. **Git history** of the public plugin repository keeps the pre-U00 `data.sql.gz`, `schema.sql`, the unredacted evidence and the old xlsx (§4.14 item 9).
7. **Reference names are Wikidata labels for people** (`entities.tsv`, §4.1). They are stored publicly as a name source for tie-breaking, casing and search only; no display name uses one while an Academy credit exists (§4.4.2 rule 3). They are names, not QIDs or years, so decision 2 does not exclude them; the README says where they came from.
8. **Studio-term old slugs redirect through plugin code** (`AAT_Ledger_Slug_Redirects`, §4.12), because WordPress has no native mechanism for terms; posts use the native `_wp_old_slug`.
9. **`docs/database/data.sql.gz` is the ledger's dump, not the audit's** from U03 on (§4.1): display names rather than Wikidata labels, linked identities only (nm0239470 on row 5671 is absent), and `ledger_dataset.source_sha256` = the CSV's hash. `integrity.sql` keeps its checks, with its expected counts generated from the manifest. The audit's own dumps (PR #38 and PR #39) remain in git history.

---

## 15. Conflicts resolved

1. **Table set and DDL** (API vs data layer). The data layer's dbDelta-safe DDL wins: no FK, CHECK, ENUM or VIEW; varchar(16) IDs; `aat_ledger_meta` plus the persistent datasets and award_keys tables. The API relies only on index column sets, which are identical.
2. **The API's four read tables** join the swap set (21 → 25) and are built by the one deriver in R1, so R3 needs no re-import.
3. **Data source.** The deriver is the only source. `oscars-corrected.tsv` is regenerated by the builder and verified by an independent Python implementation.
4. **Dataset marker.** One option (`aat_ledger_live`) and one stamp. The API token adds `AAT_VERSION` and `API_REVISION` (blocker 3).
5. **Nomination identity.** The live master id is preserved through `aat_ledger_award_keys`, because the builder keys `ledger_entry` posts on it (`builder:428-472`). `source_line` = source_row + 1 for upstream rows, null for additions.
6. **Status payload.** One endpoint; the data-layer status is its `ingest` object; a minimal `/status`, `/status/report` and `/license` ship in R1.
7. **Release numbering.** Final: 2.8.0 dry run, theme 3.2.91, 2.8.1 swap, 2.8.2 API, 2.8.3 explorer, theme 3.2.92, 2.8.4 E2, 2.8.5 E3. Fix-forwards shift later rows.
8. **Version-pinned test count.** 13; the 14th hit is the comment at `tests/schema-dbdelta-contract.php:16`.
9. **Name collisions.** `tests/ledger-ddl-contract.php` and `tests/ledger-json-schema-contract.php`; `AAT_Ledger_Schema` renamed `AAT_Ledger_Tables`.
10. **Forbidden-symbol scope.** The scan covers only the five API read files.
11. **Entity filter parameter.** `tt`/`nm`/`co` for OR lists; `imdb` for AND (≤4), over linked identities only.
12. **Sort tokens.** Compound category sorts added; `category` canonicalizes to `category,-ceremony`.
13. **Service access.** The explorer adapter calls `AAT_Ledger_Service::instance()` directly, with no filter.
14. **Text folding.** One server-side, self-contained fold (`lunara-fold/1`, no `remove_accents`); the API emits `matches_canonical`; the JS has no fold.
15. **Rate-limit buckets.** `/search` in `db` (60), `q` only for `/nominations?q=` (20); debounce 250 ms. Log-only in this train.
16. **Legacy page links from the explorer** use the predicate `AAT_Ledger::is_live()`.
17. **Theme facade** `lunara_oscars_ledger()` is deferred.
18. **Bootstrap JSON** is plugin-rendered `lle-boot` with the HEX flags.
19. **Include placement.** One require block after `main:58`.
20. **Explorer route** is always registered and serves 503 when not ready.
21. **Cache stamping scope.** 16 per-key families; 5 singletons exact-deleted (F4, F6).
22. **`dataset_cache_key` visibility** is public.
23. **Release year.** *Superseded by decision 2*: no Wikidata years; verbatim labels (§4.4.3).
24. **Upgrade-time legacy rebuild.** None when enabled; cron-only in `legacy`-disabled mode; never when live, frozen or rolled back.
25. **`aat_after_data_import` on every swap.** Gated by `consumer_sha256` (previously `projection_sha256`, which missed builder-only inputs), and never fired for a restore of the pre-ledger data.
26. **Rollback reachability.** Manifest `mode: rollback`; the CLI remains.
27. **Developer tools placement.** `tests/tools/`; `tools/verify-ledger-api.php` has the CLI guard.
28. **Load-time work.** `init()` methods are add_action/add_filter only.
29. **Silent truncation.** The length refusal plus V10 checksums.
30. **Duplicate drift SQL.** Extracted once into `get_projection_drift_counts()`.
31. **Search index and fixture names** come from the deriver, never from `data.sql.gz`; reference names come only from `data/ledger/entities.tsv` and `titles.tsv`.
32. **IMDb ID digit width** is `\d{7,10}` everywhere.
33. **Latest winner LIMIT 1** is replaced by `winners_in_ceremony` and `co_winners`.
34. **Year digit-stripping** goes through one `external_lookup_year()`.
35. **Ephemeral design artifacts** are committed under `docs/design/ledger-2.8/`, with the PHP prototypes as `.php.txt`.
36. **Plugin auto-deploy.** *Superseded by decision 14*: merge = deploy, with the 10-minute probe and stop rule.
37. **Simulation baseline.** Recorded in the manifest (`legacy` or a live `bundle_id` with a committed summary), never `origin/main`; production checks it (V13).
38. **Simulation comparison.** Set-based on sourced items; media-touched items are classified and bounded from `manifest.idless`, not compared.
39. **R2 failure policy.** Data-integrity failures roll back; cache, builder, slug and theme failures re-probe and fix forward.
40. **Slug execution.** Chains in dependency order with core's own `_wp_old_slug`; a temporary slug only inside a cycle; whole chains per tick.
41. **Filter panel.** Server-rendered open (first critique), collapsed below 1024 px by an inline script placed inside the panel right after its summary, so it runs before any panel content is parsed (second critique), replacing revision 2's collapse-at-boot from the footer script.
42. **Seven-day evidence.** Daily buckets in `/status` (`cron.daily`, `abuse.daily`, `explorer.redirect_health`) plus a scheduled one-shot routine, instead of daily agent runs.
43. **Stamp in non-live states.** `stamp()` returns the recorded stamp in `frozen` and `rolled_back` too (revision 2 returned '' unless live), so a rollback rotates caches.
44. **Rollback pin.** The pin blocks one `bundle_id`, not a state; it clears when a different bundle swaps forward (revision 3's pin plus "rolled_back behaves as frozen" blocked every later import). `reswap_of` is the only way to re-swap a pinned bundle.
45. **Import permission versus freeze.** `refuses_legacy_writes()` (live, frozen, rolled_back) and `request_import()` acceptance (legacy, live, rolled_back; deferred only by the kill switch) are separate predicates. The version check records a refusal as a refusal and re-runs when its cause changes.
46. **Title-page nominee line.** It goes through `resolve_credit_links()` like the hub renderers, and relabels only single-nominee credits (revision 3 listed it as safe).
47. **Label lookups on public pages.** Removed in every state; `get_name_entity_link_by_label()` stays for the admin classifiers and applies the guard itself.
48. **Legacy guard contents.** Three lists (`pairs`, `label_pairs`, `never_link`); never-link is enforced in the entity-URL closures, so it covers every anchor in the two templates.
49. **Evidence and the privacy decision.** Evidence is redacted in the repository and the bundle (Wikidata named as a source, never linked; no Q-numbers; no year spans). Revision 3's evidence carve-out is withdrawn.
50. **When the stamp goes live.** Computed in prepare and written into the stage meta, so it goes live with the `RENAME`; the API token reads it from the live meta row.
51. **Where ledger DDL runs.** In the job's prepare step (cron) and the CLI only; never in `maybe_upgrade_schema()` or `activate()`.
52. **R2 failure classes.** D (data layer: `/status` and the server's live sentinels) rolls back; R (render fault on a correct data layer) and B (builder, slug, theme) fix forward in code.
53. **Link-override membership.** `count(I) > 0` with unequal counts, or masked rows with slots; ID-less rows are plain text in every state.
54. **Decade membership.** `floor(film_year_start / 10) × 10`, the hub's existing rule; one generated fixture for API and hub tests.
55. **Studio terms.** Found by `_lunara_entity_id`, renamed in place, slug-migrated by the same planner, redirected by plugin code; the slugs stage runs after studios.
56. **Movie relationships.** Replaced per movie inside `step_ledger`; `start_run` no longer deletes them.
57. **ID mapping and drift.** Pins, then fingerprint, then fingerprint without nominee fields, then the builder's `backfill_key`; unmatched rows and every differing item are published; accepted drift and restored rows are authored, evidenced and sha1-pinned inputs; dry-run allocations stay in a stage table.
58. **API source-hash fixture.** Must name the current `AAT_VERSION` and match the current hash; regenerated with every release.
59. **Display-name rule 3.** The Academy's first shared credit outranks the reference name, which is a Wikidata label for people.
60. **Builder revision.** A builder-code fix triggers its own resync through `AAT_Entity_Graph_Builder::REVISION`, so class-B fix-forwards need no admin action.
61. **Where the never-link rule lives.** In `build_entity_url_from_id()` (and `get_title_visual_package()`), the one builder of every plugin entity URL, not only in the two template closures; payload URLs are never trusted when an ID is present; the DataTables payload masks never-link and guarded tokens (fourth critique, major 1).
62. **Redaction scope.** Only evidence-bearing values, by an explicit per-file key list; dataset values never (major 2).
63. **Id allocation.** Every mode writes the id map to `aat_ledger_award_keys_lstg`; only F3 copies it into the persistent table after a RENAME (major 3).
64. **Drift re-assert.** Its own job mode, `reassert`: V1–V12 and sentinels, no V13, no simulation match, no change bounds, bounded by a scope check against the accepted V10 checksums and the recorded drift (major 4).
65. **API staleness on non-live states.** No `stale-if-error` on data routes; the edge bound is `s-maxage` + `stale-while-revalidate`; clients discard cached responses on `ready: false` or a token change (major 5).
66. **Stub harnesses of the entity template** extract the resolver, guard and URL methods rather than stubbing them (major 6).
67. **Overlay formats.** Read exactly as shipped by PR #39: corrections applied in file order with a before-check per entry (multi-correction cells allowed), additions as `{row, reason, evidence, verification}` with an implied `source_row` and typed cells; JSON rewritten only in the shipped layout.
68. **Public SQL and workbook.** Regenerated by the bundle builder and checked by `--check` (decompressed bytes for the SQL, sheet dimensions for the workbook), with `integrity.sql`'s counts generated.
69. **Version probe.** The WordPress.com plugin list (IsOnWP) first; `/status` only as confirmation.

---

## 16. Changes from the previous revision

### 16.1 Third critique (of revision 3) → resolutions

| # | Critic issue | Severity | Resolution in this revision | Units |
|---|---|---|---|---|
| 1 | After a rollback to the pre-ledger data, the fix-forward can never import: `rolled_back` "behaves as frozen", `request_import()` is a no-op there and under the pin, the version check marks the version checked anyway, and the U04 acceptance locked it in | blocker | Import permission is split from the freeze (§4.6.10 truth table): `refuses_legacy_writes()` keeps writers, the legacy rebuild and (separately) the builder refused in `rolled_back`, while `request_import()` accepts any bundle whose `bundle_id` differs from the pinned one (§4.6.1). The pin holds only the rolled-back `bundle_id` and clears when a different bundle swaps forward (F2); `reswap_of` covers a rollback caused outside the bundle (§4.6.9). The version check records `deferred_frozen`/`refused_pinned` as refusals and re-runs when the cause changes. The `_l0` rule now covers every displacement of pre-ledger data, so the fix-forward puts the pre-ledger snapshot back into `_l0` (§4.6.7). MariaDB gate step (i): swap B1, roll back, install fix-forward B2 and reach `current` through the version check and cron ticks alone. U04's freeze runtime now asserts `queued` in `rolled_back` and `refused_pinned` for the pin | U04, U05, U14 |
| 2 | The legacy link guard is bypassed on title pages (single-ID relabel) and by label lookups (first-label-wins `sort_label`), and the R1 probe could not see it; live defects on tt0145781, tt0036868, tt0031385 and the Class III hub | blocker | Verified all four live (§1). (a) The title-page nominee line goes through `resolve_credit_links()` in every state and relabels only when the credit names at most one nominee and neither the credited nor the display label is guarded (§5.3). (b) The guard gains `never_link` (every ID the ledger links nowhere: wrong_id before-IDs that no longer occur and flagged-only IDs; 27 today) and `label_pairs` (every first-label-wins label of the local legacy simulation that is not a credited alias, ~100 today) (§4.4.10, §5.6). Never-link is enforced inside both templates' entity-URL closures; the guard is applied before any relabel, to `get_name_entity_link_by_label()` results and to the search feed; and public pages stop label lookups altogether (§5.2 rule 7). (c) The live probe is generated (`live-probe-cases.json`) and fails on any anchor to a never-link ID, a guarded (ID, text), an ID outside the page's scope or a text that is not a known name of the ID, whatever the anchor text (P1–P4, §5.5); it visits the four defect pages and every flagged or corrected row's title page, and must exit 1 on saved copies of today's defects. (d) §5.3 is corrected; runtime cases cover rows 938, 1575, 2111 and 9026 (and 3470) through the real entity-page closures | U03, U08, U12, U13, U14 |
| 3 | A simulation mismatch or a blocked ID mapping stops the train with nothing the agent can act on | major | Mapping pass 3 on the builder's `backfill_key` formula (`builder:922-929`), preceded by evidenced, sha1-pinned `id_pins` and followed by `restored` rows (§4.5). The report publishes, per metric and side, the differing items with `prod`, `ledger`, `legacy` and `differing_fields`, and the unmatched mapping rows with their best candidate (§4.6.6, §4.8 sections). The agent's remediation loop is defined: classify (`already_applied`, `production_lost_flag`, `production_mojibake`, `production_edit`), convert production edits into evidenced overlay corrections or record them in `docs/database/accepted-drift.json`, regenerate, and repeat the dry run, at most three times (U29, R1b). Dry-run allocations go to a stage table so pins can change them | U03, U04, U11, U29 |
| 4 | R2 checks that can fail because of rendering code trigger a dataset rollback; the hub closures are first exercised live in production; `$aat_render_pipe_links` receives no row | major | R2 classifies by data-layer truth first (§12 R2): check 2 now includes the server's live sentinels (V3 re-run in SQL on the swapped tables at F1 and daily, published in `/status`); only a check-2 failure is class D (rollback). HTML failures on a correct data layer are class R and fixed forward in code. `$aat_render_pipe_links` gains `$row = null, $list = 'credits'`, and both ballot call sites pass `$ballot_row` (§5.3). New CI test `tests/ledger-render-closures-runtime.php` extracts the three hub closures, the enrich closure and the entity-page title line and renders rows 965, 276, 8165, 9026 and 938 (plus 1575, 2111, 3470, 1814, 2564) in live, legacy and frozen, asserting the anchors (§5.5) | U04, U08, U11, U14 |
| 5 | Wikidata QIDs and life-span years are published through correction evidence, the deployed bundle, `/corrections` and the design documents | major | Evidence redaction (§4.14 item 6): Wikidata URLs become the word "Wikidata", remaining Q-numbers and year spans are removed, in `docs/database/*.json`, `adjudications.json`, `AUDIT-REPORT.md`, `data.sql.gz`'s `ledger_corrections` evidence and the regenerated xlsx; the bundle copies the redacted files, so the bundle hash covers the redacted copy and `/corrections` serves only redacted text. The builder redacts in place and `--check` fails on unredacted input; codec rule 14 refuses a bundle that contains the patterns. The privacy contract drops the evidence-key exemption and scans every data and prose file, the plan included, for Q-numbers and year spans. This plan quotes no QID, Wikidata year or life span | U00, U01, U17 |
| 6 | Jetpack Boost's deferred JavaScript moves the phone-collapse inline script to the end of `<body>` | minor | Verified (theme `docs/PERFORMANCE-PLUGIN-AUDIT-2026-09-17.md:58`). The script carries `data-jetpack-boost="ignore"` and a `tagName` check; the render contract asserts the attribute byte for byte; U23 adds a live 390 px first-paint check and a check that the served script is still in place (§7.3, §11, §12 R4) | U21, U22, U23 |
| 7 | `entities.tsv` reference names are Wikidata labels, and rule 3 preferred them over the Academy's shared credit | minor | Verified (`build.py:163-171`). Rule 3 now takes the first Academy shared credit (`shared_credit`); the reference name is used only with no Academy credit at all (§4.4.2). The README and §4.1 say reference names are Wikidata labels for people, used only for ties, casing and search; §14.3 item 7 lists it | U00, U01, U03 |
| 8 | The API source-hash contract stops working once the fixture's `plugin_version` falls behind | minor | The fixture must name the current `AAT_VERSION` and match the current hash; every release regenerates it with the version bump (§6.5). U19, U23, U25, U27 and U28 regenerate it unconditionally | U18, U19, U23, U25, U27, U28 |
| 9 | The stamp is written after the RENAME, so post-swap responses can carry the old token | minor | The stamp is computed in prepare and written into the stage meta, so it goes live in the RENAME; `live_meta_stamp()` feeds the API token and the explorer's `data-ledger-token` (§2, §4.7, §6.5); F1 checks it; the MariaDB gate asserts it between the RENAME and F2 | U04, U11, U18 |
| 10 | V10 and the daily drift hash name no column list; `drift_recurring` is ambiguous for a daily check | minor | Verified the generated columns (§1). `checksum_columns()` per table excludes DB-generated timestamps and auto-increment IDs (§4.3); V10 and drift use it. `drift_recurring` = drift found at the first daily check after a re-assert (within 26 hours); the re-assert still runs every 24 hours (decision 3). The MariaDB gate asserts no drift on two consecutive heartbeat days of an untouched dataset (§4.6.1, §11) | U02, U04 |
| 11 | The slug bound is first tested after the swap; company relabels are not migrated; check 9 is hard-coded; `step_studios` re-points terms by label | minor | The R2 pre-merge gate requires the preview within `max_slug_changes` and `max_term_slug_changes` and `conflict_risk` 0 (§12 R2). Check 9 is driven by the executed plan. `step_studios` finds terms by `_lunara_entity_id`, renames in place and journals; the planner covers `lunara_studio` terms, with `_aat_old_slug` and a plugin 404→301 handler, because terms have no native old-slug redirect (§4.11 l, §4.12). The slugs stage runs after studios | U09, U10, U14 |
| 12 | Link-override membership is unclear for ID-less rows; the ballot's "Credit: West Germany" would disappear | minor | Verified (345 rows; `main:3654-3656`). Membership = `count(I) > 0` with unequal counts, or masked rows with slots; ID-less rows render their text plain in every state (§4.4.6, §5.2). Runtime and probe cases for source_row 3470 | U03, U08 |
| 13 | The API and the hubs disagree on decade membership | minor | Decade = `floor(film_year_start / 10) × 10`, the hub's rule (§1, §6.3); one generated fixture `decades.json` (1920s = ceremonies 1–3) is asserted by the query, facets and hub tests | U01, U15, U07 |
| 14 | `hub-polish.css` has 24 bytes of headroom; the multi-winner card needs CSS; long SciTech lists on phones | minor | New rules go into `academy-awards-table.css` (no budget); `hub-polish.css` is untouched. Below 600 px the card shows the first 3 winners and "and N more" linking to the ceremony hub's category anchor, in CSS over server-rendered rows (§4.10) | U07 |
| 15 | The builder empties movie relationships on every data change and never refreshes existing `ledger_entry` titles | minor | Verified (`builder:587-598`, `:452-455`). `start_run` no longer deletes relationships; `step_ledger` replaces each touched movie's set in one write; `verify` clears movies that lost them (§4.11 k). Existing `ledger_entry` titles are refreshed when they differ (§4.11 e) | U09 |
| 16 | The first request after each deploy runs 18 dbDelta passes | minor | Ledger DDL moves to the job's prepare step 0 (cron) and the CLI; `maybe_upgrade_schema()` and `activate()` gain none (§4.3, §4.9 item 2) | U02, U04 |
| 17 | Edge-cached explorer HTML can pair with new JavaScript | minor | `lle-boot` carries `skeleton`; the JS stays inert on a mismatch and leaves the no-JS page working (§7.5) | U21, U22 |
| 18 | The stamped person index renders `[]` until the daily warm; a pre-ledger restore misses theme invalidation | minor | Verified (theme `inc/oscars-portal.php:636-651`, `:910-926`, `:945`; `inc/queries.php:445`). The theme's `aat_ledger_swapped` listener calls `lunara_invalidate_oscars_data_caches`, `lunara_flush_oscars_home_transients` and `lunara_oscars_board_art_invalidate` and schedules `lunara_oscars_portal_warm_visuals_now` (§8.1 item 11) | U13 |

### 16.2 First critique → where it is resolved now

| Critic issue | Severity | Resolution (updated in this revision) | Units |
|---|---|---|---|
| Hub pages link names to the wrong people after the swap | blocker | §5 in full: one resolver used by the ballot, latest-winner and winner-circle renderers **and the title-page line**; link overrides from ledger slots; V11 for aligned rows; plain text outside `live`; no label re-routing, cross-nominee relabel or label guessing; every consumer listed (§5.3). Runtime tests on rows 276, 965 and 8165 (plus 9026, 938, 1575, 2111, 3470, 526, 1814, 2564) through the resolver and through the real template closures; live probes of `/oscars/ceremony/12/` and `/6/` and the generated page list | U08, U13 |
| `wikidata_bounded/1` publishes Wikidata errors | blocker | Decision 2: no Wikidata years, QIDs or birth years anywhere, including the repository and the evidence (§4.14) | U00, U01, U03, U07, U09, U17 |
| The API token lacks a code version under 30-day immutable caching | blocker | Token includes `AAT_VERSION`; source-hash contract with an exact-version fixture; token stamp read from the live meta (§6.5) | U18 |
| The kill switch brings the defects back | major | Freeze semantics (§4.6.10), now with import permission separated so a rollback's fix-forward still imports | U04, U05 |
| Needs-review IDs published as confident links | major | Decision 5: masked and flagged when the master is ledger-derived; the guard (now also never-link) in the other states; unlinked in the API and explorer | U01, U03, U08, U13, U17, U21 |
| R2 proceeds by default on editorial changes | major | Decision 4: an automatic gate (sourced `simulation_match`, bounds, V13, stage sentinels, slug-preview bounds, cron evidence, R1T live), enforced inside swap mode too, with an agent-run remediation loop when it does not match | U04, U14, U29 |
| Relabelled person posts keep misleading slugs | major | Decision 6: journal-driven, collision-aware slug migration in R2, now also for studio terms, with the bound checked before the merge | U09, U10, U14 |
| `/talent/` and `/film/` disagree with `/oscars/` | major | Decision 12: builder fixes (stale meta, verbatim years, refreshed titles, relationships without an empty window) plus documented divergences | U09, U13 |
| Per-tick memory never measured for the full derivation | major | One table per tick in every phase; CI per-table memory test under 256M; local tick-by-tick gate under a real WordPress bootstrap, ≤ 192 MiB (§4.6.4) | U03, U04 |
| No evidence WP-Cron runs on Atomic | major | R1 cron evidence with daily buckets and the written stop-and-ask path (§4.6.11) | U04, U11, U12 |
| Explorer crawl trap | minor | nofollow on pivot, chip, expand and pagination links; robots.txt Disallow for multi-ID and paged states; limiter counting | U20, U21 |
| `ids_new`/`ids_retired` unbounded | minor | Bounds (`max_ids_new` = additions + restored rows; `max_ids_retired` = 0); orphan retirement within bounds | U01, U04, U09 |
| Pipeline-class constant on public paths | minor | Revision constants in `class-aat-ledger.php` | U04, U11 |
| DDL one line per table | minor | One definition per line, asserted; dbDelta idempotence gate | U02 |
| Unpublished content and server paths could leak | minor | Publish-only embeds; repository-relative paths; regex check | U11, U16, U18 |
| Builder resync skipped when only builder inputs change | minor | `builder_inputs_sha256` and `consumer_sha256`; plus the builder revision for code fixes | U03, U04, U09 |
| Mobile and accessibility gaps; LCP budget | minor | Server-rendered open panel collapsed on phones by a Boost-proof pre-parse script; `aria-live`; `aria-busy`; LCP against a measured baseline | U21, U22, U23 |
| Rate limiter may throttle everyone | minor | Log-only with daily evidence; enforcement conditional (U28) | U18, U28 |
| Singleton caches stale; `_lprv` overwritten | minor | F6 repeated deletes; `_l0` kept for every pre-ledger displacement; `_ldft` re-asserts | U04 |
| CI PHP 8.2 vs local 8.4 | minor | CI re-derives under 8.2; the fold is version-independent; prototypes as `.php.txt` | U01, U03 |

### 16.3 Second critique (of revision 2) → status now
All twenty resolutions of revision 3 stand, with these refinements:
- #1 (QIDs in the repository): extended to evidence text, the xlsx and the plan itself (§4.14 items 6–8).
- #2 (canonicalize re-routing): unchanged; the title-page line is now also covered (§5.3).
- #3 (R2 failure policy): refined from D/B into D/R/B, with the data-layer sentinels deciding D (§12 R2).
- #4 (slug planner input): unchanged; the journal also records studio terms (§4.11 l).
- #5 (citation-only rows): unchanged; the latest-winner rows are now normalized (§4.10).
- #6 (theme year flattening), #7 (fold), #9 (baseline), #10 (drift variants), #14 (builder year ownership), #15 (slug chains), #17 (per-table prepare), #18 (7-day evidence), #19 (stub-safe state), #20 (DataTables pills): unchanged.
- #8 (media provenance): unchanged, plus accepted drift and difference lists (§4.6.6).
- #11 (theme transients): the swap listener now also invalidates and warms (§8.1 item 11).
- #12 (phone collapse CLS): the script now survives Jetpack Boost (§7.3).
- #13 (legacy-state wrong links): the guard now also holds label pairs and never-link IDs and applies before relabels (§5.6).
- #16 (source-hash coverage): the fixture must also name the current version (§6.5).

### 16.4 Other changes
- **New unit U29** (conditional remediation dry run, R1b), using `tests/tools/classify-drift.php` (shipped with U04).
- **New bundle file** `accepted-drift.json` (authored, redacted); new generated fixtures `live-probe-cases.json` and `decades.json`; `legacy-link-guard.json` schema `/2`; manifest keys `accepted_drift`, `expected.legacy_guard.{label_pairs, never_link}`, `change_bounds.max_term_slug_changes`, optional `reswap_of`.
- **New codes** `privacy_violation`, `accepted_drift_invalid`, `id_pin_stale`, `sentinel_failed`, `evidence_not_redacted` (builder); request outcomes `queued`, `already_queued`, `deferred_frozen`, `refused_pinned`.
- **`DERIVER_REVISION` 4** (display-name rule 3 and link-override membership changed), so every bundle generated by this revision has a new `bundle_id`.
- **New predicates and methods** `refuses_legacy_writes()`, `live_meta_stamp()`, `credit_id_is_never_link()`, `credit_items_html()`, `AAT_Ledger_Importer::sentinels()`, `tolerant_key()`, `AAT_Ledger_Tables::ensure()`/`checksum_columns()`, `AAT_Ledger_Slug_Redirects`, `AAT_Entity_Graph_Builder::REVISION`.
- **New tests** `ledger-render-closures-runtime`; probe fixture mode over saved live HTML; MariaDB gate steps for drift remediation, stamp consistency, rollback plus automatic fix-forward, and two-day drift stability.
- **`/status/report`** is sectioned and paged (`section`, `page`).
- **Work units** are in `work_units-v4.json` (U00–U29).

---

## 17. Changes from v4

Revision 4 had no blocker. The fourth critique found six majors and ten minors; PR #39 then changed the dataset. Every patch below was checked against the code or the data files this session, and the citations are in the sections named.

### 17.1 Fourth critique → patches

| # | Critic issue | Severity | Patch in this revision (sections) | Verified against | Units |
|---|---|---|---|---|---|
| 1 | The never-link rule sat only in the two URL closures; poster grids, the portal, cached payloads and the DataTables film cell bypassed it; `/oscars/ceremony/55/` links "Just Another Missing Kid" to tt0169446, and the probe never visited that page | major | The rule moves into `build_entity_url_from_id()`, the single builder of every plugin entity URL, so `get_entity_url()`, every template call, the rollup and latest-winner payload URLs, the search feed and the portal inherit it; `get_title_visual_package()` gives a never-link title no poster (§4.9 item 14, §5.3, §5.6). Templates reach entity URLs only through the two closures (`table-display.php` gains one), a pinned contract; the enrich closure and the best-picture cards no longer fall back to a payload `film_url` when an ID is present (§5.3). DataTables gets `title_items`, and in `legacy`/`rolled_back` its payload masks never-link and guarded tokens as `?`, which even an edge-cached older script renders as text (§4.9 item 15). The theme's two search title matches consult the guard (§8.1 item 5). The probe list is generated to cover every never-link ID and every correction before-ID on a ceremony hub, a category hub and a title page (about 75 pages; revision 4's 40-page cap would have truncated PR #39's 269 ID-correction rows), includes ceremonies 29, 55 and 67 and `/oscars/`, gains rule P5 for title cards and posters, and must exit 1 on a saved `/oscars/ceremony/55/` (§5.5). R1's probe waits 65 minutes for the one-hour rollup transient (§12 R1) | `main:2502-2504`, `:7802-7809`, `:2610`, `:2622`, `:2643`, `:2728`, `:3030`, `:9427`, `:18135`; `hub-page.php:313-315`, `:815`, `:858`, `:1769`, `:2100`, `:3055`; `table-display.php:253`, `:363`, `:393`; `academy-awards-table.js:453-462`, `:617-643`; theme `inc/frontend.php:2211-2231`, `:2596-2610`; rows 6429, 3349, 7876 in the replica (§1) | U03 (probe cases), U08, U12, U13, U14 |
| 2 | The evidence redactor and the year-span contract applied to every JSON string, so they could rewrite the Academy's text or refuse verified corrections (source_row 11336's Note) | major | Redaction, codec rule 14 and the year-span contract apply only to the evidence-bearing keys of one shared table, `REDACT_KEYS` (evidence, verification, question, tried, resolution evidence, drift reason and evidence, adjudication note); never to `before`, `after`, appended cells, labels or drift values. Revision 4's global space-collapse step is dropped (it would also have rewritten Markdown indentation). Fixture cases: a correction carrying row 11336's Note in `before` and `after`, and an addition Citation with a year range, pass byte for byte (§4.1, §4.2 rule 14, §4.14 items 6 and 8, §11) | `data/oscars.csv:11337`; the §4.14 patterns over PR #39's files change 0 dataset values (§1) | U00, U01 |
| 3 | In swap mode prepare wrote allocations into the persistent `award_keys` before the gates, so a blocked swap left permanent allocations that a later pin could not move | major | Every mode writes the id map to `aat_ledger_award_keys_lstg`; only F3, after the RENAME, copies it into the persistent table with an idempotent upsert; the matching passes run for every key the persistent table lacks; the `_lstg` sweep spares a job in status `swapped`. Prepare step 1 owns the `_lstg` id table, and step 2 no longer drops it. MariaDB gate step (k): a blocked swap, then a pin, then a match, with the persistent table unchanged by the blocked run and F3 idempotent (§4.3, §4.5, §4.6.4, §4.6.7 F3, §11) | plan-internal (the v4 text at §4.5 and §4.6.4) | U04 (U29's pins rely on it) |
| 4 | The daily drift re-assert ran through the swap gate, where V13 fails for the first bundle (baseline `legacy` against a live B1), so drift was never repaired | major | Re-assert is its own job mode `reassert`: ids only from the persistent table; V1–V12 and the stage sentinels; no V13, simulation match, change bounds or slug preview; bounded by a scope check (the stage must equal the accepted V10 checksums; the swap touches only the drifted tables; drifted-row counts are recorded). Pinned in `ledger-importer-contract` and `ledger-freeze-runtime`; MariaDB gate (g) re-asserts B1 itself (§4.6.1, §4.6.5 V13, §4.6.8, §11) | plan-internal | U04 |
| 5 | `stale-if-error=86400` on unversioned data responses let the edge serve rolled-back data for a day against the 503 of `frozen`/`rolled_back`; the app contract never said to discard cached `v=` responses | major | Dropped from data routes; the staleness bound after a freeze or rollback is `s-maxage` + `stale-while-revalidate` = 600 s, asserted by `ledger-http-runtime` and checked in R2's class-D verification; the app contract and `docs/api/LEDGER-API.md` gain the discard rule on `ready: false` or a token change (§6.1, §6.5, §6.10, §12 R2) | theme `docs/SESSION-LOG.md:42` (the edge caches `/wp-json/` GETs) | U18, U19, U14 |
| 6 | `tests/entity-category-labels-runtime.php` renders the real entity template against a stub class without the resolver methods, so U08 would turn it red | major | U08 adds `get_entity_url`, `resolve_credit_links`, `credit_link_key`, `credit_pair_is_guarded`, `credit_id_is_never_link` and `credit_items_html` to the harness's extracted list, all runnable without `AAT_Ledger`, and lists the test in its acceptance (§10) | `tests/entity-category-labels-runtime.php:54`, `:57-70`, `:64`, `:83`; `templates/entity-page.php:209-260`, `:1107-1110` | U08 |
| 7 | The phone "and N more" link pointed at a ceremony-ballot anchor that never exists for SciTech or Special | minor | The link targets the same hub's history card, `#aat-category-ceremony-{N}`, an id added to every category-history card (the pinned class substring is unchanged); the read-fixes runtime asserts the target exists in the rendered hub (§4.10) | `main:7711-7712`; `hub-page.php:1993`, `:2823`, `:2835-2839`; `tests/inner-page-visual-rhythm-contract.php:35` | U07 |
| 8 | Resolver links would put companies and the fill-in title into person chips | minor | The existing person-ID filter stays on resolver links in `$aat_build_person_link_items` and on `person_url` in the enrich closure; row 526 is pinned (§5.3, §5.5) | `hub-page.php:393-395`, `:335-341`, `:2683-2684` | U08 |
| 9 | With split labels the TMDb fallback searched without a year and accepted `results[0]`; the OMDb audit lost its year guard | minor | `external_lookup_years()` gives both years of a split label; the TMDb fallback searches with each and accepts only a candidate of one of them, never `results[0]` unmatched; the OMDb audit uses the same list (§4.10, §11) | `main:9183-9213`, `:9209-9211`, `:8410`, `:8420` | U07 |
| 10 | The new routes could depend on a Jetpack Boost critical-CSS regeneration, a manual wp-admin click | minor | The explorer prints its own synchronous first-paint seed; the browser gate replays a stale Boost block (`jetpack-boost-critical-css`) and still requires CLS ≤ 0.05 and ≤ 1 px geometry change; a live CLS failure is report and stop, never "regenerate" (§7.6, §11, §12 R4) | theme `docs/SESSION-LOG.md:203`, `:227`; theme `tests/journal-archive-payload-gate-runtime.js:62`; theme `inc/frontend.php:1271-1281`; theme `docs/CHANGELOG.md:1655-1665` | U21, U22, U23 |
| 11 | The primary version probe went through the edge-cached `/status` route | minor | The WordPress.com plugin list (IsOnWP diagnose) is the primary probe for every plugin release; `/status` confirms afterwards, once a minute for up to 3 minutes (§3, §12) | theme `docs/SESSION-LOG.md:39`, `:42` | U12, U14, U19, U23, U25–U29 |
| 12 | CORS sent no `Allow-Headers` for `If-None-Match`/`If-Modified-Since` | minor | `rest_allowed_cors_headers` adds both for the namespace; exposure moves to `rest_exposed_cors_headers`; asserted in `ledger-http-runtime` (§6.5, §6.10) | WordPress 6.8 `class-wp-rest-server.php:395-434` | U11, U18 |
| 13 | The remediation loop had no class for production-only master rows (`max_ids_retired` fixed at 0) | minor | New class `production_only`: a real Academy record becomes an addition (pass 1 then preserves its id); otherwise an evidenced, sha1-pinned `retire` entry raises the generated `max_ids_retired` by one; an unexplained one blocks with `ids_retired_unexplained` (§4.1, §4.2 rule 15, §4.5, §4.6.6) | plan-internal | U01, U04, U29 |
| 14 | Three spec contradictions: (a) the raw latest-winner detail marker "stays" although the test asserts it absent; (b) R2's Class column held pasted section references; (c) the master id was both checksummed and excluded as AUTO_INCREMENT | minor | (a) The marker stays absent; the card prints the detail through `$aat_film_display` (§4.10, §10). (b) Classes restored: 1 not classed (report and stop), 2 D, 3–6 R, 7–11 B (§12 R2). (c) The master `id` is the single declared exception in `checksum_columns()` and the DDL contract (§4.3, §11) | `tests/multi-film-label-contract.php:72-78`; `hub-page.php:2640`; `main:805` | U02, U07 (U14 already carried the right classes) |
| 15 | `data.sql.gz`, `schema.sql` and the workbook were frozen at an old baseline; U00 hard-coded INSERT counts | minor | The bundle builder regenerates `data.sql.gz` from the deriver (schema.sql layout, display names, linked identities only), rewrites `integrity.sql`'s expected counts from the manifest, regenerates the workbook and checks its sheet dimensions; `--check` compares gzipped artifacts after `gzdecode`; the MariaDB gate loads the dump with `schema.sql` and runs `integrity.sql`. U00 counts are computed at run time (§4.1, §4.14, §11) | `integrity.sql:7-11`; the workbook's `A1:I374`/`A1:N12139`; `data.sql.gz`'s `ledger_dataset` row (§1) | U00, U01, U03, U04 |
| 16 | `/status/report` decoded the whole stored report on every uncached request; the guard option stayed autoloaded after R2 | minor | New persistent table `aat_ledger_report_pages` (one row per section and page, one PK read per request); the guard option is autoloaded only while the guard is on, switched with `wp_set_option_autoload()` at F2 (§4.3, §4.6.1, §4.6.7, §4.8, §5.6) | WordPress 6.8 `option.php:537-548` | U02, U04, U11 |

### 17.2 Data update (PR #39) → patches

| Item | Patch (sections) | Units |
|---|---|---|
| `corrections.json` has 372 entries (wrong_id 235, encoding 84, retired_id 38, credit_text 10, missing_id 5); a cell may be corrected more than once, in file order, each with its before-check | Shipped corrections format with multi-correction chains (§4.1); codec rules 4 and 16 (§4.2); manifest `count`, `cells`, `multi_corrected_cells`; reason is free text; `pairs` covers retired_id before-IDs; the probe covers every ID correction; U01's "no cell corrected twice" is replaced by a per-entry before-check assertion. The replica applies all 372 with 0 mismatches (§1) | U01, U03 |
| `additions.json` holds one appended row as `{row, reason, evidence, verification}`, `row` with the 14 source columns as typed JSON values | Shipped additions format: array, implied `source_row`, `addition_cell()` typing table, `addition_invalid` and `addition_cell_type` replacing `addition_row_gap`; the Python reference uses the same table and reproduces `3ecfa6fe…9d7f` (§1, §4.1, §4.2 rule 5, §4.5) | U01 |
| `needs-review.json` holds one item (rows [5671], nm0239470, Richard Dubois), served unlinked | Examples and sentinels move from the settled items (1575, 2112, 4746) to 5671; nm0239470 is never-link in legacy, absent from the master, the projections, search and the SQL dump, 404 when live; 1575 now links co0003606 when live (§1, §4.4.6, §5.5, §12 R1, R2 and R3) | U03, U08, U12, U14, U19 |
| Expected counts 12,138 nominations and 3,516 winners, matching the Academy | Nothing pins them: every count comes from the manifest; the only literals left pin the upstream CSV (12,137 rows, 3,515 winners; §0 item 1, §1, §10). U00's "8,469 and 5,263" and "57 strings…" literals are replaced by run-time counts; U01's and U03's counts come from the manifest | U00, U01, U03, U05 |
| `schema.sql` widened `verification` to 255 | U00's column strip keeps `:153` byte-identical; the ledger DDL already has `varchar(255)`; the longest value is 137 characters (§1, §4.3, §4.14 item 3) | U00 |
| `data.sql.gz` rebuilt (still with Wikidata columns and the flagged identity) | U00 strips and redacts it once; from U03 on the builder regenerates it from the ledger (§4.1, §4.14 items 1–2, §14.3 item 9) | U00, U03 |
| The generator and the codec read the files exactly as shipped | The shipped JSON layout (one-space indent, no trailing newline) is reproduced by `AAT_Ledger_Json::encode_shipped()`, verified byte for byte on all five files with PHP 8.4; the builder writes only through it; the bundle contract asserts the round trip (§4.1) | U01 |
| New audit file `tools/editor_decisions.json` (real QIDs and year spans in `evidence`; review-item labels shaped like Q-numbers) | Added to step 0's inputs and to `REDACT_KEYS`; the audit labels in both `tools/*.json` files are renamed from Q to R; the privacy contract no longer needs a JSON exemption (§1, §4.14 items 6 and 8) | U00, U01 |

### 17.3 Other corrections found while verifying

- `tests/reporting-integrity-contract.php` pins the CSV hash at `:30`, not `:31` (§1, §10); `winner-backfill-identity-contract.php:60` is listed as an upstream pin.
- `build.py`'s Wikidata lines moved in PR #39 (`:4`, `:14-18`, `:142-184`), and `schema.sql`'s columns are at `:67-68` and `:78-79` (§1, §4.14).
- Revision 4's prepare step 2 dropped "any `_lstg`", which would have dropped the id table step 1 had just written; step 2 now drops only the 25 swap-set stage tables (§4.6.4).
- `--check` compared gzipped artifacts byte for byte, which zlib differences between CI and local PHP can break; gzipped artifacts are compared after `gzdecode`, and `summary_sha256` hashes the decompressed JSON (§4.1).
- `make_workbook.py` takes `additions.json` as its fifth argument; U00's command and the builder pass it (§4.1, §4.14 item 7).
- `AAT_Ledger::DERIVER_REVISION` goes from 4 to 5 (the shipped overlay formats, the per-entry corrections rows and the title list of `pairs`), so every bundle generated by this revision has a new `bundle_id` (§2).
- The positional-hazard and never-link measurements of §1 are re-measured over PR #39's overlay: 242 differing rows (239 with a `?` slot), 0 equal-count hazards, 33 never-link IDs including 4 titles.

### 17.4 Work units

`work_units-v5.json` keeps U00–U29 and their dependencies; the titles, file lists and acceptance criteria of U00, U01, U02, U03, U04, U05, U07, U08, U11, U12, U13, U14, U17, U18, U19, U21, U22, U23, U25, U26, U27, U28 and U29 are patched as mapped above. No unit is added, and no dependency changes. Every overlay-derived number in them is read from the manifest or recounted; the literals left are the upstream-CSV pins (U05) and the synthetic redaction cases of U00.
