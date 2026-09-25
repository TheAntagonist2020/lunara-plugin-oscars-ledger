# Oscars dataset audit — 2026-09-24/25

The Academy Awards dataset behind lunarafilm.com has **12,138 nominations** from the
1st ceremony (1927/28) to the 98th (2025). This audit checked every row, every IMDb ID
and every winner flag against sources independent of the data, and then reconciled
every row with the **Academy Awards Database itself**. The corrected dataset is
`oscars-corrected.xlsx` / `oscars-corrected.tsv`.

- `corrections.json` lists each of the **372 changed cells**, in 339 rows, with its before
  value, after value, evidence and confirmation.
- `additions.json` lists the **one award the source was missing**.
- One question no source could settle is in `needs-review.json` and on the workbook's
  **Needs review** sheet. It is left unchanged in the data, and the site shows that
  credit without a link until it is settled.

**Dataset version:** `2026.09.25-1`.

## What the source is

- **Dalton's workbook is the same data the site imports.** `oscars.csv.xlsx`, sheet
  `full_data`, holds the same values as the plugin's `data/oscars.csv` and as the
  current upstream `DLu/oscar_data` `oscars.csv`. That project scrapes the Academy's
  official awards database and adds IMDb IDs.
- **The 98 `Ceremony_N` sheets** equal the full sheet's subsets exactly.
- **The two files differ only in how quote marks are encoded.**
- **Production matches the plugin's normalized copy row for row.** The comparison
  (`tools/verify-live-dataset.php`) found 12,137 rows, 3,515 winners and 0 differing
  groups. The live site therefore carries the defects below, including the ones the
  importer adds.

## How it was checked

| Check | Method | Result |
| --- | --- | --- |
| **The Academy's own records** | Every ceremony 1–98 read from the Academy Awards Database (awardsdatabase.oscars.org) and every row compared field by field: winner, category, films, credit line, character or song, Sci-Tech area, citation, notes | 12,135 of 12,137 rows identical. 3 real discrepancies (below). The other differences are explained under "Differences that are not errors" |
| Structure | Every row: film/ID and nominee/ID slot counts, ID formats, duplicates, category and ceremony coherence | Every slot aligned except 1 film with no ID. 13,738 distinct IDs, 0 malformed |
| Identity (Wikidata) | Wikidata's name, dates and type for all 13,738 IDs (P345) | 13,540 found. 13,664 of 14,127 (ID, credited name) pairs match |
| Identity (IMDb) | IMDb's own name, title and known-for summary for all 13,738 IDs | Used to catch same-name records and to re-confirm every new ID |
| Winners | 5,256 Wikidata "award received" statements, then the Academy database | All agree. 4 Wikidata statements disagree, and in every case Wikidata is wrong |
| Major categories | Picture, Director, 4 acting categories | All 573 winners confirmed |
| Ties | Multiple winners in single-winner categories | 5 groups, all documented ties or special awards |
| Namesakes | Wikidata occupations for 8,240 people, compared with their category's craft | No wrong-person IDs among the craft categories |
| **Company eras** | All 73 company IDs (663 credit slots): IMDb's name, active years and the films' own company credits, compared with the ceremonies where each ID is used | 8 IDs named a different company or era than the one credited; 7 corrected (187 slots), 1 confirmed correct |
| Research | Each flagged item researched by an agent with sources | 9 research workflows |
| Verification | Every change checked by two independent skeptics (a source lens and a ceremony-context lens) | Applied only when both confirmed |

Credits that differ from a person's usual name are **not** errors, and were kept as the
Academy credits them:
- **Pseudonyms:** Roderick Jaynes (Joel and Ethan Coen), P.H. Vazak (Robert Towne).
- **Birth names of performers known by a stage name:** Paul Hewson (Bono), Lonnie Lynn (Common).
- **US release titles:** *The Invaders* for *49th Parallel*.
- **The Academy's own spellings and capitals.**

The schema keeps both the canonical name and the name as credited.

## What was corrected (372 cells in 339 rows, and 1 added row)

Row numbers below are spreadsheet rows, where the header is row 1. The nomination
numbers in `corrections.json` are one lower.

| Kind | Cells | What it is |
| --- | --- | --- |
| Encoding | 84 | The source writes every double quote as `\"` |
| Credit text | 10 | Wording copied into a nominee slot, stray spaces, one misspelled name |
| Wrong IMDb ID | 235 | The ID belongs to a different person, company or era |
| Retired IMDb ID | 38 | Right organisation, but IMDb retired the ID (Columbia) |
| Missing link | 5 | A proven ID for a credit the source left unlinked |
| Missing award | 1 row | An award the source does not have |

### Encoding (84 cells)
The source writes every double quote as `\"`: 194 times in 84 cells, and it contains no
other backslashes. Every `\"` became `"`. Examples: `Barney "Chick" McGill`,
`Ahmir "Questlove" Thompson`, and the Citation *"Mickey Mouse."*

### Credit text (10 cells)

**Nominee slots that kept wording from the credit line:**
- "Screenplay - Edward Berger" (95th)
- "Screenplay - Justine Triet" (96th)
- "In collaboration with Thomas Bidegain" (97th)
- "in collaboration with Jean-Claude Carrière" (45th)

**Stray spaces.** Each is fixed in both its nominee slot and its credit line:
- "Metro- Goldwyn-Mayer British" (38th)
- "Todd- AO Sound Department" (34th, *West Side Story*). The Academy's record and the
  dataset's six other Todd-AO credits all read "Todd-AO".

**"Bei Shimen" becomes "Bei Shimeng"** (97th, the DJI Ronin 2 award), in the citation and
the nominee slot. These sources give Shimeng:
- the Academy's database, its press release and its 2025 Sci-Tech ceremony page;
- DJI's announcement;
- the Television Academy's 2024 Engineering Emmys;
- a DJI patent.

The Academy's first release was reprinted with "Shimen" and later corrected.

### Missing award (1 row)
The Academy Awards Database lists a **97th-ceremony Academy Award of Merit** "To all those
who have developed and supported captioning technology, whether open or closed, for
film." The source data does not have it. The Academy's press release of 27 January 2025
and its 2025 Sci-Tech ceremony page confirm it. It was an Oscar statuette, accepted by
Marlee Matlin on 29 April 2025.

The award was added as nomination 12,138 (spreadsheet row 12,139). It was appended after the last row so that
every existing nomination number stays the same. With it, ceremony 97 has 139 records,
the same as the Academy.

### IMDb IDs (278 slots)
Each fix is backed by evidence and two confirmations.

**People**

| Row(s) | Credit | Was | Now | Why |
| --- | --- | --- | --- | --- |
| 527 | Thomas T. Moulton, *Dodsworth* (9th) | nm0481264 | nm0609771 | The old ID is Oscar Lagerstrom's. Moulton has nm0609771 on his 20 other rows |
| 817 | Art Smith, *Spawn of the North* (11th) | nm0807356 | nm1071079 | The old ID is an MGM unit manager who [years omitted]. The Academy's honoree, Paramount's miniatures man, [years omitted] |
| 193, 408, 519 | Hal Roach, Producer | co0075561 | nm0730018 | The studio's ID sat on the person |
| 7382 | Eugene Corr, *Waldo Salt* | nm0944310 | nm0180644 | The old ID is a stub named "Eugene X" |
| 8769 | Al Mayer Sr. / Al Mayer Jr. | son's ID on the father | nm4869190 / nm2353419 | Father and son now each carry their own ID |
| 12081 | Jeong Hoon Seo, "Golden" | nm13927769 | nm15612471 | The old ID was a different Korean voice actor |
| 8364 | Daniel Langlois (Softimage) | nm0486570 | nm9834494 | The old ID was a Québec production manager |
| 8375 | Jim Frazier (Frazier lens) | nm2149370 | nm1192634 | The old ID was a US documentary cinematographer |
| 9040 | Kim Davidson (SideFX) | nm3181275 | nm3487551 | The old ID was an actress |
| 9687 | David Inglish (Bonner Medal) | nm0408890 | nm0408889 | The old ID was the director of *Mulligan Men* |

**Titles**

| Row(s) | Credit | Was | Now | Why |
| --- | --- | --- | --- | --- |
| 3350 | *Man in Space* | tt0046593 | tt0049473 | The old ID was the whole Disney anthology series |
| 6430 | *Just Another Missing Kid* | tt0169446 | tt0084185 | The old ID was the CBC series *The Fifth Estate* |
| 7877 | *D-Day Remembered* | tt0094416 | tt0109519 | The old ID was the PBS series *American Experience* |
| 544 | *The March of Time* | tt3034436 | — | The old ID was one 1935 issue. The award honoured the series, which has no IMDb title |

**Studios**

| Row(s) | Credit | Was | Now | Why |
| --- | --- | --- | --- | --- |
| 51 rows, 1935–68 | 20th Century-Fox | co0028775 | co0000756 | The old ID is the pre-merger Fox Film Corporation [years omitted], which has no titles after 1935. IMDb credits co0000756 on every one of these films. The 8 "Fox" credits before the merger keep co0028775 |
| 82 rows, 1927–66 | Warner Bros. | co0080422 | co0002663 | The old ID is "Warner Bros. Entertainment", IMDb's modern rights holder, on only 2 of the 70 films. co0002663 is the studio IMDb credits on all of them |
| 3 rows, 1967–68 | Warner Bros. (Warner Bros.-Seven Arts) | co0080422 | — | The merged company is already linked on the Seven Arts slot (below) |
| 38 rows, 1932/33–68 | Columbia | co0050868 | co0014351 | Same studio. IMDb retired the old ID, which now redirects to co0014351 and has no titles |
| 9 rows, 1937–64 | Walt Disney Studios | co0008970 | co0098836 | The old ID is Walt Disney Pictures, incorporated in 1983. co0098836 is Walt Disney Productions, the studio of those years |
| 24 rows, 1937–67 | Samuel Goldwyn Studio departments | co0058013 | co0064215 | co0058013 is Samuel Goldwyn Films, founded in 2000 |
| 8 rows, 1931–47 | Samuel Goldwyn Productions (Best Picture) | co0058013 | co0189536 | Wikidata and IMDb both tie co0189536 to Samuel Goldwyn Productions [years omitted] |
| 146 | Samuel Goldwyn–United Artists sound department (4th) | co0058013 | — | No single proven ID for that studio |
| 4747, 4861, 4862 | Seven Arts (Warner Bros.-Seven Arts) | co0638213 | co0076018 | The old ID is an unrelated Canadian company. co0076018 is the merged Warner Bros.-Seven Arts (1967–69), IMDb's production company on all three films |
| 1322, 1480, 1676, 1851, 2022 | "the RCA Manufacturing Company", "RCA Sound" | co0097233 | — | The old ID is the RCA Records label, with no film-sound credits and nothing before 1954. No IMDb record represents RCA's Hollywood recording department. The Academy files the four "RCA Sound" rows and the 1955 *Not as a Stranger* credit (already unlinked) as one nominee, so all five now match |
| 2113, 2229, 2344, 2346 | J. Arthur Rank (the Rank banners) | co0040435 | — | The old ID is Rank's German distributor, not on *Hamlet* at all. No Rank company record is credited as producer or presenter on these films |
| 1388, 1584, 1766 | United States Navy | co0077018 | co0047766 | The Academy credits the Navy. The old ID is the parent Navy Department |
| 1576, 1577, 1590 | United States Army Pictorial Service | co0141760 | co0003606 | The old ID is the whole Army. The Pictorial Service has its own record, and the Academy keeps the two apart |
| 1403, 2087 | Artkino | co0154885 | co0047444 | The old ID is a one-title Soviet record. co0047444 is Artkino Pictures, the New York distributor |
| 317 | Jesse L. Lasky (production company), 1934 | co0057677 | — | The old ID is the Lasky Feature Play Company, dissolved in 1916 |
| 3394 | Westrex Sound Services Inc. | co0001880 | — | The old ID is the Westrex recording-system trademark, not the services company |
| 1389, 2948 | US Army Signal Corps | co0141760 | co0041860 | Upstream had folded all Army units into one record |
| 1411, 1767, 1934, 2322 | US Army Air Forces | co0141760 | co0046209 | Same |
| 1574 | War Department Special Service Division | co0023398 | co0038466 | The same unit already carries co0038466 on row 1405 |
| 2848, 2852 | London Films (Korda) | co0053636 | co0103018 | The old ID was a German namesake |
| 264 | Educational Pictures | co0015504 | co0050385 | The old ID was "Pictorial Films" (one 1952 title) |
| 978 | Bausch & Lomb / National Carbon | swapped | fixed | National Carbon's ID sat in Bausch & Lomb's slot |

**Missing links added (5 slots).** Each ID is IMDb's only production-company credit on
the film and is confirmed by Wikidata:
- Two Cities Films (co0103139) on *Henry V*, *Hamlet* and *In Which We Serve*.
- Cineguild (co0113550) on *Great Expectations*.
- Todd-AO (co0016792) on *West Side Story*. The "Todd- AO" typo had defeated the source's
  matching; the other six Todd-AO credits already carry this ID.

**Unlinked people (set to no ID).** In each case the old ID belonged to an unrelated
person who shares the honoree's name, and no correct ID could be proven. An empty link is
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

Most of these errors started at IMDb. It attaches Scientific and Technical awards, and
some honorary awards, to whichever record has the same name. The upstream data inherited
those links.

## Differences from the Academy database that are not errors

| Row | What differs | Why it stays |
| --- | --- | --- |
| 11 | The Academy lists *Sunrise*'s cinematography win as two records, Charles Rosher and Karl Struss. The dataset has one row, "Charles Rosher, Karl Struss" | The Academy's own note: "It is considered a single nomination for the film." |
| 5893, 7205, 8087 | Special awards for *Star Wars*, *Who Framed Roger Rabbit* and *Toy Story* name the film only in the citation. The dataset links the film | The link is correct and useful |
| 11355, 11448 | The two *Borat Subsequent Moviefilm* nominations | Identical. The comparison tool could not read the very long title |

The "came in 2nd" and "came in 3rd" notes of the 6th–8th ceremonies and the doubled
"NOTE: NOTE:" of two 98th-ceremony ties are the Academy's own text.

## What the site's importer adds (fixed in the plugin, not the data)

These defects appear only after import. The source is correct on each point:

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

The schema here (`schema.sql`) rules each of these out by construction:
- Credit slots keep their position even without an ID.
- Canonical names and credited names are separate fields.
- A credit can name several people.
- Titles are an ordered list.
- `year_label` is kept verbatim.

## Needs review (unchanged)

**Richard Dubois (row 5672, 48th Sci-Tech, Akwaklame Company).** The row carries
nm0239470, an actor and producer record: bit parts in 1983–84, a 1990 film role and a
2006 producer credit. The honoree was most likely Richard H. DuBois, a Long Beach chemist
who registered AkwaKlame in 1972. Nothing links the two men except IMDb's own award
attachment, which also mislinked the co-honoree. Nothing proves they are different men
either. The ID is left in the data, flagged, and shown on the site without a link.

**Resolved this round:**
- RCA (both items)
- J. Arthur Rank
- Art Smith
- Navy Department
- Army Pictorial Service
- Warner Bros.-Seven Arts

All are listed in the tables above.

## Known and accepted

Row 4508 (*Doctor Zhivago*, Sound) credits MGM British and MGM, and both link to MGM's
company ID. That follows the dataset's rule of linking a studio department to its
parent studio.
