# Oscars Person Portrait Import Queue

## Decisions

contract: Add a private Oscars admin workflow that turns the dry-run nominee portrait audit into a one-by-one verified portrait import queue.

contract: The queue accepts IMDb `nm` person IDs from Dalton's nominee CSV, pasted IDs, or the plugin person table, but every import action targets exactly one person at a time.

contract: Only verified TMDb person `profile_path` images may be imported as person portraits. Title backdrops, stills, posters, contextual title images, and any non-person image source remain barred from the person portrait chamber.

contract: Imports create Media Library attachments and mark them with plugin-owned metadata linking the attachment to the Oscar person ID and portrait source.

contract: Existing public URLs, Oscar result rows, title data, ceremony data, and category data remain unchanged.

contract: Public person pages continue to render the existing honest states: local portrait, TMDb profile candidate, or no portrait. Local verified portraits remain preferred over external API images.

invariant: The import queue is admin-only, nonce-protected, capability-protected, and never exposes API keys.

invariant: Re-running an import for the same person and same source image must not create duplicate attachments when a matching plugin-owned attachment already exists.

invariant: A person with no TMDb person `profile_path` remains a no-portrait/manual-review case; no fallback image is imported.

test: A focused contract proves the import queue adds admin-only controls, nonce handling, local attachment metadata, duplicate protection, and profile-only source constraints.

test: Existing person visual integrity and nominee portrait dry-run contracts remain green.

deferred: Bulk unchecked imports.

deferred: External face matching or automated identity verification.

deferred: Replacing the recovered movie-poster importer.

## Working Notes

- The recovered `movie-poster-batch-importer` is a useful pattern for dry-run, duplicate checking, and media sideloading, but it is movie-poster-specific and must not be reused directly for person portraits.
- The current emergency guard already prevents contextual title art from rendering as a person portrait. This queue should preserve that guard while giving Dalton a controlled way to build the verified local portrait library.
- The fastest useful v1 is a private queue plus one-row import action, not a full bulk media operations console.
