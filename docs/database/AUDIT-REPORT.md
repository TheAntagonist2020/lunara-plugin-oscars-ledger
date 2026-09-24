# Oscars dataset audit — 2026-09-24

The Academy Awards dataset behind lunarafilm.com has 12,137 nominations, from the
1st ceremony (1927/28) to the 98th (2025). This audit checked every row, every IMDb ID
and every winner flag against sources independent of the data. The corrected dataset
is `oscars-corrected.xlsx` / `oscars-corrected.tsv`. `corrections.json` lists each of
the 157 changed cells with its before value, after value, evidence and confirmation.
Eight questions no source could settle are in `needs-review.json` and on the workbook's
**Needs review** sheet. They were left unchanged.

## What the source is

- **Dalton's workbook is the same data the site imports.** `oscars.csv.xlsx`, sheet
  `full_data`, holds the same values as the plugin's `data/oscars.csv` and as the
  current upstream `DLu/oscar_data` `oscars.csv`. That project scrapes the Academy's
  official awards database and adds IMDb IDs. The 98 `Ceremony_N` sheets equal the
  full sheet's subsets exactly. The two files differ only in how quote marks are
  encoded.
- **Production matches the plugin's normalized copy row for row.** The comparison
  (`tools/verify-live-dataset.php`) found 12,137 rows, 3,515 winners and 0 differing
  groups. The live site therefore carries the defects below, including the ones the
  importer adds.

## How it was checked

| Check | Method | Result |
| --- | --- | --- |
| Structure | Every row: film/ID and nominee/ID slot counts, ID formats, duplicates, category and ceremony coherence | Every slot aligned except 1 film with no ID. 13,738 distinct IDs, 0 malformed |
| Identity (Wikidata) | Wikidata's name, dates and type for all 13,738 IDs (P345) | 13,540 found. 13,664 of 14,127 (ID, credited name) pairs match |
| Identity (IMDb) | IMDb's own name, title and known-for summary for all 13,738 IDs | Used to catch same-name records and to re-confirm every new ID |
| Winners | 5,256 Wikidata "award received" statements | All agree. 4 statements disagree, and in every case Wikidata is wrong: *Young Americans* (revoked in 1969) and *Ford v Ferrari*'s Sound Editing win filed as Sound |
| Major categories | Picture, Director, 4 acting categories | All 573 winners confirmed |
| Ties | Multiple winners in single-winner categories | 5 groups, all documented ties or special awards |
| Namesakes | Wikidata occupations for 8,240 people, compared with their category's craft | No wrong-person IDs among the craft categories |
| Research | Each of 485 flagged items researched by an agent with sources | 5 research workflows, 15 research agents |
| Verification | Each proposed ID change checked by two independent skeptics (a source lens and a ceremony-context lens) | Applied only when both confirmed |

Credits that differ from a person's usual name are **not** errors, and were kept as the
Academy credits them:
- pseudonyms: Roderick Jaynes (Joel and Ethan Coen), P.H. Vazak (Robert Towne);
- birth names of performers known by a stage name: Paul Hewson (Bono), Lonnie Lynn (Common);
- US release titles: *The Invaders* for *49th Parallel*;
- the Academy's own spellings and capitals.

The schema keeps both the canonical name and the name as credited.

## What was corrected (157 cells in 138 rows)

**Encoding (84 cells).** The source writes every double quote as `\"`: 194 times in 84
cells, and it contains no other backslashes. Every `\"` became `"`. Examples:
`Barney "Chick" McGill`, `Ahmir "Questlove" Thompson`, the Citation *"Mickey Mouse."*

**Credit text (6 cells).**
- Four nominee slots kept wording from the credit line:
  - "Screenplay - Edward Berger" (95th);
  - "Screenplay - Justine Triet" (96th);
  - "In collaboration with Thomas Bidegain" (97th);
  - "in collaboration with Jean-Claude Carrière" (45th).
- "Metro- Goldwyn-Mayer British" had a stray space, fixed in both its nominee slot and
  its credit line.

**IMDb IDs (67 cells).** Each fix is backed by evidence and two confirmations:

| Row(s) | Credit | Was | Now | Why |
| --- | --- | --- | --- | --- |
| 527 | Thomas T. Moulton, *Dodsworth* (9th) | nm0481264 | nm0609771 | The old ID is Oscar Lagerstrom's. Moulton has nm0609771 on his 20 other rows |
| 193, 408, 519 | Hal Roach, Producer | co0075561 | nm0730018 | The studio's ID sat on the person |
| 7382 | Eugene Corr, *Waldo Salt* | nm0944310 | nm0180644 | The old ID is a stub named "Eugene X" |
| 3350 | *Man in Space* | tt0046593 | tt0049473 | The old ID was the whole Disney anthology series |
| 6430 | *Just Another Missing Kid* | tt0169446 | tt0084185 | The old ID was the CBC series *The Fifth Estate* |
| 7877 | *D-Day Remembered* | tt0094416 | tt0109519 | The old ID was the PBS series *American Experience* |
| 544 | *The March of Time* | tt3034436 | — | The old ID was one 1935 issue. The award honoured the series, which has no IMDb title |
| 24 rows, 1937–67 | Samuel Goldwyn Studio departments | co0058013 | co0064215 | co0058013 is Samuel Goldwyn Films, founded in 2000 |
| 8 rows, 1931–47 | Samuel Goldwyn Productions (Best Picture) | co0058013 | co0189536 | Wikidata and IMDb both tie co0189536 to Samuel Goldwyn Productions (1923–1959) |
| 146 | Samuel Goldwyn–United Artists sound department (4th) | co0058013 | — | No single proven ID for that studio |
| 1389, 2948 | US Army Signal Corps | co0141760 | co0041860 | Upstream had folded all Army units into one record |
| 1411, 1767, 1934, 2322 | US Army Air Forces | co0141760 | co0046209 | Same |
| 1574 | War Department Special Service Division | co0023398 | co0038466 | The same unit already carries co0038466 on row 1405 |
| 2848, 2852 | London Films (Korda) | co0053636 | co0103018 | The old ID was a German namesake |
| 264 | Educational Pictures | co0015504 | co0050385 | The old ID was "Pictorial Films" (one 1952 title) |
| 978 | Bausch & Lomb / National Carbon | swapped | fixed | National Carbon's ID sat in Bausch & Lomb's slot |
| 8769 | Al Mayer Sr. / Al Mayer Jr. | son's ID on the father | nm4869190 / nm2353419 | Father and son now each carry their own ID |
| 12081 | Jeong Hoon Seo, "Golden" | nm13927769 | nm15612471 | The old ID was a different Korean voice actor |
| 8364 | Daniel Langlois (Softimage) | nm0486570 | nm9834494 | The old ID was a Québec production manager |
| 8375 | Jim Frazier (Frazier lens) | nm2149370 | nm1192634 | The old ID was a US documentary cinematographer |
| 9040 | Kim Davidson (SideFX) | nm3181275 | nm3487551 | The old ID was an actress |
| 9687 | David Inglish (Bonner Medal) | nm0408890 | nm0408889 | The old ID was the director of *Mulligan Men* |

**Unlinked (set to no ID).** In each case the old ID belonged to an unrelated person
who shares the honoree's name, and no correct ID could be proven. An empty link is
better than a wrong one:

| Row | Honoree | Old ID was |
| --- | --- | --- |
| 2503 | Robert Carr | the British politician Lord Carr |
| 3152 | Lloyd Russell | a 2011–12 property master |
| 5672 | John C. Dolan | a present-day key grip |
| 7952 | Mike Davis (Kodak) | a transportation coordinator |
| 8104 and 7962 | John Carter (Todd-AO) | a 1914 actor, and an actor from a 2002 video |
| 6022 | David P. Robinson (Dolby) | a Florida grip |
| 9552 | Chris Edwards (EFILM) | an amateur actor |

Most of these errors started at IMDb. It attaches Scientific and Technical awards to
whichever record has the same name, and the upstream data inherited those links.

## What the site's importer adds (fixed in the plugin, not the data)

These defects appear only after import. The source workbook is correct on each point:

- **Lost placeholders.** `normalize_imdb_entity_ids()` drops `?` placeholders and removes
  duplicate IDs. Every later nominee then slides onto its neighbour's ID. On the live
  site, "Jean Hersholt" links to Ralph Morgan's ID, and one ID is published as a person
  named "The Motion Picture Relief Fund".
- **First label wins.** A pseudonym becomes a person's name: Ethan Coen's page is titled
  "Roderick Jaynes".
- **Quote marks.** PHP's CSV escape character mangles the quote marks in those 84 cells.
- **Second films dropped.** A nomination for two films registers only the first. 15
  titles, such as *The Way of All Flesh*, never get an entry of their own.
- **Early years flattened.** Labels like "1927/28" are rendered as a single year in
  places, e.g. "1932/33" becomes 1932 in the site's structured data.

The schema here (`schema.sql`) rules each of these out by construction. Credit slots
keep their position even without an ID. Canonical names and credited names are
separate fields. A credit can name several people. Titles are an ordered list.
`year_label` is kept verbatim.

## Needs review (unchanged)

These 8 items are listed in `needs-review.json`, with the question and what was tried:

- **Art Smith (row 817):** likely a namesake; `nm1071079` is a candidate.
- **RCA Records ID (rows 1322, 1480, 1676, 1851, 2022):** the rows credit "RCA Manufacturing" and "RCA Sound", but several competing RCA records exist.
- **Navy Department (rows 1388, 1584, 1766):** the reviewers recommend keeping it.
- **Army Pictorial Service (rows 1576, 1577, 1590).**
- **J. Arthur Rank (rows 2113, 2229, 2344, 2346):** the ID is Rank's German distribution arm.
- **Warner Bros.-Seven Arts (rows 4747, 4861, 4862):** the credit is split across two slots.
- **Richard Dubois (row 5672):** likely a namesake, but not proven.

## Known and accepted

Row 4508 (*Doctor Zhivago*, Sound) credits MGM British and MGM, and both link to MGM's
company ID. That follows the dataset's rule of linking a studio department to its
parent studio.
