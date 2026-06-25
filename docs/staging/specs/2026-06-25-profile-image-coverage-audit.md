# Profile Image Coverage Audit

contract: Extend the private manual profile-image workflow with a read-only source/route coverage audit.

interface: `wp aat profile-images coverage --results-csv=/private/tmdb_profile_results.csv --batch=oscars-profile-images-20260625 --sample=25`

input: `tmdb_profile_results.csv` is the approved portrait roster and uses `Status=OK` for importable portrait IDs.

input: The optional `--batch` label scopes imported-media counts to `_aat_person_portrait_batch`.

input: The optional `--sample` value controls how many missing-route/source IDs are printed for manual follow-up.

contract: Coverage mode reports approved IDs, live source people IDs, public entity IDs, imported attachments, route-backed approved IDs, approved IDs absent from `people`, approved IDs absent from `wp_aat_entities`, approved IDs present in `people` but absent from `wp_aat_entities`, imported attachments without routes, and route-backed imported attachments.

contract: Coverage mode prints compact sample rows for missing source people IDs and imported-media/no-route IDs.

invariant: Coverage mode is read-only. It does not import media, write post meta, delete transients, mutate source tables, call TMDb/OMDb/IMDb/Cinemagoer, or repair people/entity rows.

invariant: Public HTML must not expose CSV paths, server import paths, batch labels, attachment metadata keys, or source coverage diagnostics.

failure: If `--results-csv` is missing or unreadable, coverage mode fails before database comparison.

failure: If the live source `people` table is absent, coverage mode reports `people_table_available=0` and treats approved IDs as missing source rows rather than throwing SQL noise.

test: A source contract proves `coverage` mode exists, reports the route/source buckets, reads approved `Status=OK` rows, accepts `--batch` and `--sample`, and contains no mutation or external-fetch behavior inside the coverage path.

deferred: Automatic source repair.

deferred: Public profile route creation for approved IDs absent from source `people`.

deferred: Importing the remaining manual portrait package.

## Working notes

The live 2026-06-25 ramp found `806` approved portrait IDs absent from both `people` and `wp_aat_entities`, plus `30` already-imported attachments without public routes. This audit makes that condition first-class before further broad scaling.
