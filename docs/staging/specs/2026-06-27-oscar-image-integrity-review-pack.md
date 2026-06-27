# Oscar Image Integrity Review Pack

contract: `Academy Awards > Image Integrity` includes a private `Fix First Review Pack` that surfaces the first 25 high-priority private integrity rows before the full table.

data: review pack rows are derived from the existing normalized image-integrity rows after triage hydration and sorting, filtered to `Wrong Match` and `Needs Review`, capped at 25, and kept read-only.

invariant: no public route, schema, media import/delete, external API call, or Oscar result-row mutation changes.

test: `php tests/image-integrity-console-contract.php` confirms the version, review-pack data contract, template hooks, CSS hooks, and documentation.

deferred: saving review states, importing images, and batch mutation remain outside this pass.

## Working notes

The purpose is operational speed: Dalton can open one private screen and immediately see the first cleanup batch without paging through the full console.
