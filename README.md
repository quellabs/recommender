# quellabs/recommender

Collaborative filtering recommendation engine for PHP 8.2+, built on the CakePHP 5 database layer. Implements two
complementary algorithms:

- **Item-based collaborative filtering** — "people who liked A also liked B"
- **Slope One** — lightweight weighted rating prediction for unrated items

Based on the [Vogoo PHP recommendation engine](http://www.vogoo.net/) (2007–2008) by Stéphane Droux, modernised for PHP
8.2+ and the Quellabs ecosystem.

---

## Requirements

- PHP 8.2+
- `cakephp/database` ^5.0
- MySQL (incremental updates use `INSERT ... ON DUPLICATE KEY UPDATE`)

## Installation

```bash
composer require quellabs/recommender
```

## Setup

### 1. Publish the configuration file

```bash
sculpt recommender:init
```

This copies `config/recommender.php` to your project root. Edit it to tune the engine constants. Database credentials
are read from `config/database.php`, which is shared with other Canvas packages.

### 2. Create the database tables

```bash
sculpt recommender:init-db
```

Creates `vogoo_ratings` and `vogoo_links`. Use `--force` to drop and recreate existing tables.
`--force` deletes ratings and is not a migration procedure.

### 3. Populate the link table

If you have existing ratings, rebuild the link table from scratch:

```bash
sculpt recommender:rebuild-links
```

To rebuild a single category only:

```bash
sculpt recommender:rebuild-links --category=2
```

Pause rating writes while rebuilding. The command computes each category in staging storage,
then replaces its rows in one transaction. It rebuilds both measures regardless of the
`direct_links` and `direct_slope` settings, and clears stale rows for empty categories.

### Upgrade an existing installation

Back up both `vogoo_ratings` and `vogoo_links`, pause rating writes, run
[`migrations/2026-10-independent-pair-counts.sql`](migrations/2026-10-independent-pair-counts.sql),
then run `sculpt recommender:rebuild-links` before resuming writes. The old
`cnt` column mixes liked and Slope One contributions, so its values cannot be
assigned to either new count. The migration preserves `vogoo_ratings`; the rebuild
reconstructs every derived row from those ratings. If an upgrade fails, restore
both backed-up tables together, then run the previous package version.

## Database schema

### `vogoo_ratings`

Stores member/product ratings.

| Column       | Type         | Description                           |
|--------------|--------------|---------------------------------------|
| `member_id`  | INT UNSIGNED | Your application's user ID            |
| `product_id` | INT UNSIGNED | Your application's product/item ID    |
| `category`   | INT UNSIGNED | Category grouping (default: 1)        |
| `rating`     | FLOAT        | 0.0–1.0, or -1.0 for "not interested" |
| `ts`         | DATETIME     | Last updated timestamp                |

### `vogoo_links`

Stores pre-computed item co-occurrence counts and Slope One diff values. Populated by the rebuild command or maintained
incrementally.

| Column       | Type         | Description                               |
|--------------|--------------|-------------------------------------------|
| `item_id1`   | INT UNSIGNED | First item in the pair                    |
| `item_id2`   | INT UNSIGNED | Second item in the pair                   |
| `category`   | INT UNSIGNED | Category grouping                         |
| `liked_count` | INT UNSIGNED | Members who liked both items |
| `slope_count` | INT UNSIGNED | Members who genuinely rated both items |
| `diff_slope` | FLOAT | Sum of rating(item_id2) minus rating(item_id1) over slope contributors |

A directed pair row exists while either count is positive. `Statistics::numLinks()`
counts rows of either kind. Each incremental option updates only its own measure.

## Configuration

All options with their defaults:

```php
// config/recommender.php
return [
    // Default category used when no category is passed to engine methods
    'category' => 1,

    // Minimum number of common ratings before similarity is considered reliable
    'threshold_nr_common_ratings' => 30,

    // Multiplier used in the similarity confidence calculation
    'threshold_mult' => 2,

    // Minimum rating for an item to count as "liked" in link calculations
    'threshold_rating' => 0.66,

    // Cost factor used in the member similarity spread calculation
    'cost' => 5.0,

    // Sentinel value stored to mark "not interested" (fixed at -1.0)
    'not_interested' => -1.0,

    // Maintain vogoo_links incrementally on every rating change.
    // When false, run "sculpt recommender:rebuild-links" after bulk imports.
    'direct_links' => false,
    'direct_slope' => true,
];
```

`direct_links` and `direct_slope` are independent. You can enable either or both:

|        | `direct_links`                                    | `direct_slope`                                             |
|--------|---------------------------------------------------|------------------------------------------------------------|
| Powers | `getLinkedItems()`, `memberGetRecommendedItems()` | `getSlopeItems()`, `memberPredict()`, `memberPredictAll()` |
| Counts | Co-occurrence (liked pairs only)                  | All rated pairs                                            |

When both are `false`, `vogoo_links` is read-only at runtime and must be rebuilt manually.
Run a full rebuild when enabling either incremental option on an existing ratings store.
Invalid persisted ratings return `false` from `setRating()`; visitor ratings and
invalid configuration throw `InvalidArgumentException`. Ratings must be finite,
within 0.0–1.0, or exactly -1.0. IDs and categories must be nonnegative.

## Usage

### With Canvas (autowired)

`RecommendationEngine` and `ItemRecommender` are resolved automatically by the Canvas DI container — their
constructors depend only on `Connection` (provided by `quellabs/canvas-database`) and `RecommendationConfig`
(provided by this package), so no manual wiring is required:

```php
use Quellabs\Recommender\ItemRecommender;
use Quellabs\Recommender\RecommendationEngine;

class ProductController
{
    public function __construct(
        private RecommendationEngine $engine,
        private ItemRecommender $recommender,
    ) {}
}
```

### Standalone

```php
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\RecommendationEngine;
use Quellabs\Recommender\ItemRecommender;

$connection = new Connection([
    'driver'   => Mysql::class,
    'host'     => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'mydb',
]);

$config    = new RecommendationConfig();
$engine    = new RecommendationEngine($connection, $config);
$recommender = new ItemRecommender($connection, $config);
```

---

## API reference

### `RecommendationEngine`

Handles rating CRUD. All write methods trigger incremental link/slope updates when `direct_links` or `direct_slope` is
enabled.

```php
// Record ratings
$engine->setRating($memberId, $productId, 0.8);
$engine->automaticRating($memberId, $productId, purchase: true);  // 1.0
$engine->automaticRating($memberId, $productId, purchase: false); // 0.7, or +0.01
$engine->setNotInterested($memberId, $productId);

// Read ratings
$engine->getRating($memberId, $productId);           // ['rating' => 0.8, 'ts' => '...']
$engine->memberRatings($memberId);                   // [['product_id', 'rating', 'ts'], ...]
$engine->memberNumRatings($memberId);
$engine->memberAverageRating($memberId);
$engine->productRatings($productId);
$engine->productNumRatings($productId);
$engine->productAverageRating($productId);

// Delete
$engine->deleteRating($memberId, $productId);
$engine->deleteMember($memberId);
$engine->deleteProduct($productId);
```

### `ItemRecommender`

Item-based CF and Slope One recommendations.

```php
// Item-based CF (requires direct_links or rebuild)
$recommender->getLinkedItems($productId);                          // [productId, ...]
$recommender->memberGetRecommendedItems($memberId);                // [productId, ...]
$recommender->memberGetReasons($memberId, $productId);             // [productId, ...]

// Slope One (requires direct_slope or rebuild)
$recommender->memberPredict($memberId, $productId);                // float|null
$recommender->memberPredictAll($memberId);                         // [['product_id', 'rating'], ...]
$recommender->getSlopeItems($productId);                           // [['product_id', 'diff'], ...]

// Opt-in scores, strategy, and contributing product IDs
$recommender->memberRecommendationsDetailed($memberId);           // RecommendationResult[]
$recommender->visitorRecommendationsDetailed($visitor);           // RecommendationResult[]

// Anonymous visitors (pass a VisitorContext instead of a member ID)
$recommender->visitorGetRecommendedItems($visitor);
$recommender->visitorPredict($visitor, $productId);
$recommender->visitorPredictAll($visitor);
```

All methods accept an optional `$filter` (array of allowed product IDs), `$limit`, and `$category` parameter.
The detailed methods also accept `$minHistory` (default 1) and `$minRatings`
(default 2). Below the genuine-rating history threshold they rank top-rated
products with at least `$minRatings` ratings. Seen and rejected products are
excluded. `item_links` scores sum `liked_count × (source rating − like threshold)`;
`top_rated` scores are average genuine ratings. Scores are meaningful only
within the same strategy. A single-item Slope One prediction estimates even
an already rated item; the all-item methods return only unrated items.

Allowed-item filters are applied before SQL limits. Lists above 500 IDs are
loaded into a temporary indexed table in batches of 500 for bounded query size.

### `UserSimilarity`

User-based CF. Similarity scores range from 0 (no overlap) to 100 (identical taste).

```php
$similarity = $userSimilarity->memberSimilarity($memberId1, $memberId2); // int 0–100
$neighbours = $userSimilarity->getNeighbours($memberId, minSimilarity: 10, limit: 20);
$items      = $userSimilarity->memberGetRecommendedItems($memberId);
```

`getNeighbours()` aggregates candidate overlaps in one query. Recommendation
rating reads are grouped in batches of 500 neighbours.

### `VisitorContext`

Holds in-memory ratings for anonymous visitors. Persist and restore it across requests via session serialization.

```php
$visitor = new VisitorContext($config);
$visitor->setRating($productId, 0.9);
$visitor->setNotInterested($productId);
$visitor->removeRating($productId);
$visitor->getRatings();         // [['product_id', 'rating', 'category'], ...]
$visitor->getRatedProductIds(); // [productId, ...]
```

### `Statistics`

```php
$stats->numMembers();
$stats->numProducts();
$stats->numRatings();
$stats->numLinks();
$stats->mostRatedProducts(limit: 10);  // [['product_id', 'num_ratings'], ...]
$stats->topRatedProducts(limit: 10, minRatings: 5); // [['product_id', 'avg_rating'], ...]
```

## Sculpt CLI commands

| Command                                         | Description                                     |
|-------------------------------------------------|-------------------------------------------------|
| `sculpt recommender:init`                       | Publish `config/recommender.php`                |
| `sculpt recommender:init-db`                    | Create `vogoo_ratings` and `vogoo_links` tables |
| `sculpt recommender:init-db --force`            | Drop and recreate tables                        |
| `sculpt recommender:rebuild-links`              | Rebuild `vogoo_links` from all ratings          |
| `sculpt recommender:rebuild-links --category=N` | Rebuild a single category                       |

## Multi-category support

Every method accepts an optional `?int $category` parameter. When omitted it falls back to the `category` value in
`RecommendationConfig` (default: 1). To work with multiple catalogues, either pass the category explicitly or
instantiate separate `RecommendationConfig` objects per category:

```php
$booksConfig    = new RecommendationConfig(category: 1);
$moviesConfig   = new RecommendationConfig(category: 2);

$booksEngine  = new RecommendationEngine($connection, $booksConfig);
$moviesEngine = new RecommendationEngine($connection, $moviesConfig);
```

## License

MIT
