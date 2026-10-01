# Independent recommender measures

The `vogoo_links.cnt` column is replaced by `liked_count` and `slope_count`.
`diff_slope` now sums the directed rating difference only for genuine rating
pairs. Existing `cnt` values mix the two algorithms and cannot be reused.
`getLinkedItems()` now requires a positive liked count; Slope One uses only
the slope count. The rebuild command computes both measures regardless of
incremental settings and clears stale categories.

Before upgrading, back up both `vogoo_ratings` and `vogoo_links` together.
Pause rating writes, run
[`migrations/2026-10-independent-pair-counts.sql`](migrations/2026-10-independent-pair-counts.sql),
then run `sculpt recommender:rebuild-links`. Verify recommendations before
resuming writes. The migration leaves `vogoo_ratings` intact and clears derived
rows before changing the schema. Do not use `recommender:init-db --force` for
an upgrade; it drops ratings.

For rollback, stop rating writes, restore both backed-up tables, reinstall
the previous package version, and resume writes. Restoring only `vogoo_links`
would put derived counts out of sync with ratings written after the backup.

The SQL rebuild replaces each category in a transaction after staging its
results. On a local MySQL test database, 150 members with 12 ratings each
(19,800 directed contributions) took 0.038 seconds with the set-based
aggregation versus 3.542 seconds with per-pair upserts. These measurements
include query overhead and are a small-catalog comparison, not a production
capacity estimate.

For user recommendations, the grouped path used three queries with 20
neighbours in the integration fixture. A local timing run returned 10 items
from 10 neighbours in 2.31 ms and 200 items from 200 neighbours in 5.46 ms.
These fixture measurements exclude network latency and application work.
