# Profile Image Batch Importer

contract: Add a private WP-CLI batch importer for Dalton-supplied Oscars profile images.

input: Staged zip source is `G:\lunara-backups\oscars-profile-images.zip` locally before server upload.

input: The zip contains `4,158` `.jpg` files named by IMDb person ID under `oscars-profile-images/nmXXXXXXX.jpg`.

input: `tmdb_profile_results.csv` contains `8,403` rows with headers `NomineeId`, `ExpectedName`, `TMDBName`, `TMDBPersonId`, `ProfilePath`, `ImageFile`, `Status`.

input: `profiles_missing.csv` contains `4,245` rows with headers `NomineeId`, `ExpectedName`, `Status`.

invariant: Every image imported must have an `nm` filename that appears in `tmdb_profile_results.csv`.

invariant: No image in `profiles_missing.csv` is imported.

invariant: The importer does not use Cinemagoer, IMDb scraping, OMDb, TMDb network calls, or external image hunting.

invariant: The importer reads Dalton-supplied image files only.

contract: Dry-run mode reports counts for staged images, CSV matches, missing CSV conflicts, unknown IDs, already-imported attachments, and importable rows without changing WordPress.

contract: Import mode runs in bounded batches with `--limit` and `--offset`.

contract: Import mode creates WordPress media attachments and writes:
- `_aat_person_imdb_id`
- `_aat_person_portrait_source = manual-batch-upload`
- `_aat_person_portrait_verified = 1`
- `_aat_person_portrait_batch = <batch label>`

contract: Import mode updates attachment alt text to `<ExpectedName> portrait`.

contract: Import mode clears `aat_person_profile_attachment_v2_<NomineeId>` after each successful import.

failure: If the image file is missing, unreadable, malformed, not a JPEG, or the ID is not in the approved CSV, skip the row and report it.

failure: If an attachment already exists for the person ID and manual batch source, skip unless an explicit future replace flag exists.

public surface: No public URL changes.

public surface: No CSV file paths, batch paths, private notes, or metadata keys appear in public HTML.

visual: Existing person pages should resolve the imported verified local portrait without additional manual assignment.

test: A source contract proves the CLI command exists, supports dry-run/import/limit/offset/source/csv options, blocks external fetching, writes required metadata, and clears transients.

test: A dry-run against the staged dataset reports `4,158` importable zip images and `0` zip/missing conflicts before import.

deferred: Bulk replacement of already-imported portraits.

deferred: Browser-based bulk upload.

deferred: Automatic image sourcing or matching.

