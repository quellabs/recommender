<?php
	
	namespace Quellabs\Recommender;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	
	/**
	 * Item-based collaborative filtering and Slope One recommendations.
	 *
	 * Two recommendation strategies are available per method, controlled by which
	 * vogoo_links columns are populated:
	 *
	 *  - Links (liked_count): item co-occurrence — "people who liked A also liked B"
	 *  - Slope One (slope_count + diff_slope): weighted predicted rating for unseen items
	 *
	 * Both strategies require vogoo_links to be pre-populated, either via a
	 * batch rebuild or via incremental updates through LinkUpdater.
	 *
	 * All methods throw on database failure. Wrap calls in try/catch if you need
	 * to handle errors.
	 *
	 * @phpstan-import-type RatingList from VisitorContext
	 */
	readonly class ItemRecommender {
		
		private Connection $connection;
		private RecommendationConfig $config;
		
		/**
		 * ItemRecommender constructor
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 */
		public function __construct(Connection $connection, RecommendationConfig $config) {
			$this->config = $config;
			$this->connection = $connection;
		}
		
		// -------------------------------------------------------------------------
		// Links (co-occurrence)
		// -------------------------------------------------------------------------
		
		/**
		 * Return items that co-occur with the given product, ordered by
		 * co-occurrence count descending.
		 * @param int $productId The product ID
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function getLinkedItems(int $productId, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$sql = '
				SELECT
					`item_id2`,
					`liked_count`
				FROM `vogoo_links`
				WHERE `item_id1` = :product_id AND
				      `category` = :category AND `liked_count` > 0
	        ';
			$params = ['product_id' => $productId, 'category' => $cat];
			$sql .= $this->allowedSql($filter, '`item_id2`', $params);
			$sql .= ' ORDER BY `liked_count` DESC, `item_id2` ASC';
			
			if ($limit > 0) {
				$sql .= ' LIMIT ' . $limit;
			}
			
			try {
				$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			} finally {
				$this->clearAllowedTable($filter);
			}
			
			$result = $this->filterAndExtract($rows, 'item_id2', $filter);
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		/**
		 * Return recommended items for a member using item-based CF, ordered by
		 * weighted co-occurrence score descending.
		 * Only returns items the member has not already rated.
		 * @param int $memberId The member ID
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function memberGetRecommendedItems(int $memberId, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$limit = max(0, $limit);
			$threshold = $this->config->getThresholdRating();
			
			$sql = '
				SELECT
					l.`item_id2`,
					SUM(l.`liked_count` * (r.`rating` - :threshold)) AS cnter
				FROM `vogoo_links` l
				INNER JOIN `vogoo_ratings` r ON r.`member_id` = :member_id AND
				                               l.`item_id1` = r.`product_id` AND
				                               r.`rating` >= 0.0 AND
				                               l.`category` = r.`category` AND
				                               r.`category` = :category
		        WHERE NOT EXISTS (
					SELECT 1 FROM `vogoo_ratings` vr
					WHERE vr.`member_id` = :member_id2 AND
					      vr.`category` = :category2 AND
					      vr.`product_id` = l.`item_id2`
		        )
			';
			$params = [
				'threshold'  => $threshold,
				'member_id'  => $memberId,
				'category'   => $cat,
				'member_id2' => $memberId,
				'category2'  => $cat
			];
			$sql .= $this->allowedSql($filter, 'l.`item_id2`', $params);
			$sql .= '
				GROUP BY l.`item_id2`
		        HAVING cnter > 0
				ORDER BY cnter DESC, l.`item_id2` ASC
			';
			
			if ($limit > 0) {
				$sql .= ' LIMIT ' . $limit;
			}
			
			try {
				$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			} finally {
				$this->clearAllowedTable($filter);
			}
			$result = $this->filterAndExtract($rows, 'item_id2', $filter);
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		/**
		 * Return the products this member has already rated that are linked to the
		 * given product — the "why we recommend this" list.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function memberGetReasons(int $memberId, int $productId, int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$limit = max(0, $limit);
			$threshold = $this->config->getThresholdRating();
			
			$sql = '
				SELECT r.`product_id`
				FROM `vogoo_ratings` r
				INNER JOIN `vogoo_links` l ON l.`item_id1` = :product_id AND
				                             r.`product_id` = l.`item_id2` AND
				                             l.`liked_count` > 0 AND
				                             l.`category` = r.`category`
				WHERE r.`member_id` = :member_id AND
				      r.`category` = :category AND
				      r.`rating` >= :threshold
			';
			
			$params = [
				'product_id' => $productId,
				'member_id'  => $memberId,
				'category'   => $cat,
				'threshold'  => $threshold
			];
			
			if ($limit > 0) {
				$sql .= ' LIMIT ' . $limit;
			}
			
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			return array_map('intval', array_column($rows, 'product_id'));
		}
		
		/**
		 * Return recommended items for an anonymous visitor using item-based CF.
		 * Ratings are read from the provided VisitorContext rather than the database.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function visitorGetRecommendedItems(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$ratings = $visitor->getRatings($cat);
			
			if (empty($ratings)) {
				return [];
			}
			
			$scores = $this->scoreVisitorCandidates($ratings, $filter, $cat);
			$scores = array_filter($scores, fn($s) => $s > 0);
			uksort($scores, fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
			
			$result = array_keys($scores);
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		/**
		 * Return the visitor's already-rated products that are linked to the given
		 * product — the "why we recommend this" list for anonymous visitors.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param int $productId The product ID
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function visitorGetReasons(VisitorContext $visitor, int $productId, int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$threshold = $this->config->getThresholdRating();
			$ratings = $visitor->getRatings($cat);
			
			$likedIds = array_column(
				array_filter($ratings, fn($e) => $e['rating'] >= $threshold),
				'product_id'
			);
			
			if (empty($likedIds)) {
				return [];
			}
			
			$placeholders = implode(',', array_fill(0, count($likedIds), '?'));
			
			$sql = "
				SELECT `item_id2`
				FROM `vogoo_links`
				WHERE `category` = ? AND
				      `item_id1` = ? AND
				      `item_id2` IN ({$placeholders}) AND
				      `liked_count` > 0
			";
			
			if ($limit > 0) {
				$sql .= ' LIMIT ' . $limit;
			}
			
			$rows = $this->connection->execute($sql, array_merge([$cat, $productId], $likedIds))->fetchAll('assoc');
			return array_map('intval', array_column($rows, 'item_id2'));
		}
		
		// -------------------------------------------------------------------------
		// Slope One
		// -------------------------------------------------------------------------
		
		/**
		 * Return items sorted by their average slope one diff relative to the given
		 * product, ordered best-match first.
		 * @param int $productId The product ID
		 * @param int $minLinks Minimum co-occurrence count to include a pair
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{product_id: int, diff: float}>
		 */
		public function getSlopeItems(int $productId, int $minLinks = 1, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$minLinks = max(1, $minLinks);
			$limit = max(0, $limit);
			
			$sql = '
				SELECT
					`item_id2`,
					(`diff_slope` / `slope_count`) AS avg_diff
				FROM `vogoo_links`
				WHERE `item_id1` = :product_id AND
				      `category` = :category AND
				      `slope_count` >= :min_links
			';
			
			$params = [
				'product_id' => $productId,
				'category'   => $cat,
				'min_links'  => $minLinks
			];
			$sql .= $this->allowedSql($filter, '`item_id2`', $params);
			$sql .= ' ORDER BY avg_diff DESC, `item_id2` ASC';
			
			if ($limit > 0) {
				$sql .= ' LIMIT ' . $limit;
			}
			
			try {
				$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			} finally {
				$this->clearAllowedTable($filter);
			}
			$result = [];
			
			foreach ($rows as $row) {
				if (!is_array($row) || !isset($row['item_id2'], $row['avg_diff']) || !is_scalar($row['item_id2'])) {
					continue;
				}
				
				$id = (int)$row['item_id2'];
				
				if (!empty($filter) && !in_array($id, $filter, true)) {
					continue;
				}
				
				$result[] = ['product_id' => $id, 'diff' => (float)$row['avg_diff']];
			}
			
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		/**
		 * Predict a member's rating for a single product using slope one.
		 * Returns null when there is insufficient data to make a prediction.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return float|null Predicted rating in [0.0, 1.0], or null
		 */
		public function memberPredict(int $memberId, int $productId, ?int $category = null): ?float {
			$cat = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute('
				SELECT
					SUM(l.`slope_count`) AS cnter,
					SUM(r.`rating` * l.`slope_count` - l.`diff_slope`) AS diff
				FROM `vogoo_links` l
				INNER JOIN `vogoo_ratings` r ON r.`member_id` = :member_id AND
				                               r.`product_id` = l.`item_id2` AND
				                               r.`category` = l.`category` AND
				                               r.`rating` >= 0.0
				WHERE l.`item_id1` = :product_id AND
				      l.`category` = :category AND l.`slope_count` > 0
			', [
				'member_id'  => $memberId,
				'product_id' => $productId,
				'category'   => $cat
			])->fetchAssoc();
			
			if ((int)$row['cnter'] === 0) {
				return null;
			}
			
			return $this->clampRating((float)$row['diff'] / (float)$row['cnter']);
		}
		
		/**
		 * Predict ratings for all unrated items for a member using slope one,
		 * returned as [['product_id' => int, 'rating' => float], ...] sorted
		 * by predicted rating descending.
		 * @param int $memberId The member ID
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{product_id: int, rating: float}>
		 */
		public function memberPredictAll(int $memberId, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$limit = max(0, $limit);
			
			$rows = $this->connection->execute('
				SELECT
					l.`item_id2`,
					SUM(l.`slope_count`) AS cnter,
					SUM(r.`rating` * l.`slope_count` + l.`diff_slope`) AS diff
				FROM `vogoo_links` l
				INNER JOIN `vogoo_ratings` r ON r.`member_id` = :member_id AND
				                               r.`rating` >= 0.0 AND
				                               l.`item_id1` = r.`product_id` AND
				                               l.`slope_count` > 0 AND
				                               r.`category` = :category AND
				                               l.`category` = r.`category`
				 WHERE NOT EXISTS (
						SELECT 1 FROM `vogoo_ratings` vr
						WHERE vr.`member_id` = :member_id2 AND
						      vr.`category` = :category2 AND
						      vr.`product_id` = l.`item_id2`
				 )
				GROUP BY l.`item_id2`
			', [
				'member_id'  => $memberId,
				'category'   => $cat,
				'member_id2' => $memberId,
				'category2'  => $cat
			])->fetchAll('assoc');
			
			$result = [];
			
			foreach ($rows as $row) {
				if (!is_array($row) || !isset($row['item_id2'], $row['cnter'], $row['diff']) || !is_scalar($row['item_id2'])) {
					continue;
				}
				
				$id = (int)$row['item_id2'];
				
				if (!empty($filter) && !in_array($id, $filter, true)) {
					continue;
				}
				
				$result[] = [
					'product_id' => $id,
					'rating'     => $this->clampRating((float)$row['diff'] / (float)$row['cnter'])
				];
			}
			
			usort($result, fn($a, $b) => ($b['rating'] <=> $a['rating']) ?: ($a['product_id'] <=> $b['product_id']));
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		/**
		 * Predict a rating for a single product for an anonymous visitor using
		 * slope one. Returns null when there is insufficient data.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return float|null Predicted rating in [0.0, 1.0], or null
		 */
		public function visitorPredict(VisitorContext $visitor, int $productId, ?int $category = null): ?float {
			$cat = $this->config->resolveCategory($category);
			$products = $this->collectGenuineRatings($visitor->getRatings($cat));
			
			if (empty($products)) {
				return null;
			}
			
			$rows = $this->connection->execute('
				SELECT
					`item_id2`,
					`slope_count`,
					`diff_slope`
				FROM `vogoo_links`
				WHERE `item_id1` = :product_id AND
				      `category` = :category AND
				      `slope_count` > 0
			', [
				'product_id' => $productId,
				'category'   => $cat
			])->fetchAll('assoc');
			
			$numerator = 0.0;
			$denominator = 0;
			
			foreach ($rows as $row) {
				if (!is_array($row) || !isset($row['item_id2'], $row['slope_count'], $row['diff_slope']) || !is_scalar($row['item_id2'])) {
					continue;
				}
				
				$id = (int)$row['item_id2'];
				
				if (isset($products[$id])) {
					$numerator += $products[$id] * (int)$row['slope_count'] - (float)$row['diff_slope'];
					$denominator += (int)$row['slope_count'];
				}
			}
			
			if ($denominator === 0) {
				return null;
			}
			
			return $this->clampRating($numerator / $denominator);
		}
		
		/**
		 * Predict ratings for all unrated items for an anonymous visitor using
		 * slope one, returned as [['product_id' => int, 'rating' => float], ...]
		 * sorted by predicted rating descending.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{product_id: int, rating: float}>
		 */
		public function visitorPredictAll(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$products = $this->collectGenuineRatings($visitor->getRatings($cat));
			
			if (empty($products)) {
				return [];
			}
			
			$accumulated = $this->accumulateSlopePredictions($products, $filter, $cat);
			$ratedIds = $visitor->getRatedProductIds($cat);
			$result = [];
			
			foreach ($accumulated as $id => [$cnter, $diff]) {
				if (in_array($id, $ratedIds, true)) {
					continue;
				}
				
				$result[] = [
					'product_id' => $id,
					'rating'     => $this->clampRating($diff / $cnter)
				];
			}
			
			usort($result, fn($a, $b) => ($b['rating'] <=> $a['rating']) ?: ($a['product_id'] <=> $b['product_id']));
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------

		/** Return scored member recommendations, falling back to top rated items for short histories.
		 * item_links scores sum liked_count multiplied by (member rating minus threshold);
		 * top_rated scores are mean genuine ratings.
		 * @param int $memberId Member ID
		 * @param array<int> $filter Allowed product IDs, or empty for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int|null $category Category override
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring
		 * @param int $minRatings Minimum ratings for a fallback item
		 * @return array<int, RecommendationResult>
		 */
		public function memberRecommendationsDetailed(int $memberId, array $filter = [], int $limit = 0, ?int $category = null, int $minHistory = 1, int $minRatings = 2): array {
			$cat = $this->config->resolveCategory($category);
			$history = (int)$this->connection->execute('SELECT COUNT(*) AS total FROM vogoo_ratings WHERE member_id = :member AND category = :category AND rating >= 0.0',
				['member' => $memberId, 'category' => $cat])->fetchAssoc()['total'];
			if ($history < max(1, $minHistory)) {
				$excluded = array_map('intval', array_column($this->connection->execute(
					'SELECT product_id FROM vogoo_ratings WHERE member_id = :member AND category = :category',
					['member' => $memberId, 'category' => $cat])->fetchAll('assoc'), 'product_id'));
				return $this->fallbackResults($excluded, $filter, $limit, $cat, $minRatings);
			}
			$params = ['member' => $memberId, 'category' => $cat, 'threshold' => $this->config->getThresholdRating()];
			$sql = 'SELECT l.item_id2, SUM(l.liked_count * (r.rating - :threshold)) AS score,
				JSON_ARRAYAGG(r.product_id) AS contributors
				FROM vogoo_links l JOIN vogoo_ratings r ON r.product_id = l.item_id1
					AND r.category = l.category AND r.member_id = :member AND r.rating >= 0.0
				WHERE l.category = :category AND l.liked_count > 0
					AND NOT EXISTS (SELECT 1 FROM vogoo_ratings seen WHERE seen.member_id = :member2
						AND seen.category = :category2 AND seen.product_id = l.item_id2)';
			$params['member2'] = $memberId;
			$params['category2'] = $cat;
			$sql .= $this->allowedSql($filter, 'l.item_id2', $params);
			$sql .= ' GROUP BY l.item_id2 HAVING score > 0 ORDER BY score DESC, l.item_id2 ASC';
			try {
				$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			} finally {
				$this->clearAllowedTable($filter);
			}
			$results = [];
			foreach ($rows as $row) {
				$id = (int)$row['item_id2'];
				if ($filter !== [] && !in_array($id, $filter, true)) {
					continue;
				}
				$decoded = json_decode((string)$row['contributors'], true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($decoded)) {
					throw new \UnexpectedValueException('Invalid recommendation contributors returned by the database.');
				}
				$contributors = [];
				foreach ($decoded as $value) {
					if (!is_int($value)) {
						throw new \UnexpectedValueException('Invalid contributing product ID returned by the database.');
					}
					$contributors[] = $value;
				}
				$contributors = array_values(array_unique($contributors));
				sort($contributors);
				$results[] = new RecommendationResult($id, (float)$row['score'], 'item_links', $contributors);
			}
			return $limit > 0 ? array_slice($results, 0, $limit) : $results;
		}

		/** Return scored visitor recommendations with the same cold-start rule.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param array<int> $filter Allowed product IDs, or empty for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int|null $category Category override
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring
		 * @param int $minRatings Minimum ratings for a fallback item
		 * @return array<int, RecommendationResult>
		 */
		public function visitorRecommendationsDetailed(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null, int $minHistory = 1, int $minRatings = 2): array {
			$cat = $this->config->resolveCategory($category);
			$ratings = $visitor->getRatings($cat);
			$history = count(array_filter($ratings, fn($row) => $row['rating'] >= 0.0));
			if ($history < max(1, $minHistory)) {
				return $this->fallbackResults(array_column($ratings, 'product_id'), $filter, $limit, $cat, $minRatings);
			}
			$reasons = [];
			$scores = array_filter($this->scoreVisitorCandidates($ratings, $filter, $cat, $reasons), fn($score) => $score > 0);
			uksort($scores, fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
			$results = [];
			foreach ($scores as $id => $score) {
				$contributors = array_values(array_unique($reasons[$id] ?? []));
				sort($contributors);
				$results[] = new RecommendationResult((int)$id, (float)$score, 'item_links', $contributors);
			}
			return $limit > 0 ? array_slice($results, 0, $limit) : $results;
		}

		/** Rank popular high-rated items while excluding every rated or rejected item.
		 * @param array<int> $excluded Items already seen
		 * @param array<int> $filter Allowed IDs, or empty for all
		 * @param int $limit Maximum results
		 * @param int $category Category
		 * @param int $minRatings Minimum rating count
		 * @return array<int, RecommendationResult>
		 */
		private function fallbackResults(array $excluded, array $filter, int $limit, int $category, int $minRatings): array {
			$stats = new Statistics($this->connection, $this->config);
			$results = [];
			foreach ($stats->topRatedProducts(0, max(1, $minRatings), $category) as $row) {
				$id = $row['product_id'];
				if (in_array($id, $excluded, true) || ($filter !== [] && !in_array($id, $filter, true))) {
					continue;
				}
				$results[] = new RecommendationResult($id, $row['avg_rating'], 'top_rated', []);
				if ($limit > 0 && count($results) >= $limit) {
					break;
				}
			}
			return $results;
		}

		/** Append a parameterized allowlist predicate before SQL limits.
		 * Large lists use a temporary indexed table populated in bounded batches.
		 * @param array<int> $filter Allowed product IDs
		 * @param string $column SQL column selected by the caller
		 * @param array<string, int|float> $params Bound query parameters
		 * @return string SQL predicate
		 */
		private function allowedSql(array $filter, string $column, array &$params): string {
			if ($filter === []) {
				return '';
			}
			foreach ($filter as $id) {
				if (!is_int($id) || $id < 0 || $id > 4294967295) {
					throw new \InvalidArgumentException('Allowed product IDs must fit an unsigned 32-bit integer.');
				}
			}
			if (count($filter) > 500) {
				$this->connection->execute('DROP TEMPORARY TABLE IF EXISTS recommender_allowed_items');
				$this->connection->execute('CREATE TEMPORARY TABLE recommender_allowed_items (id INT UNSIGNED PRIMARY KEY)');
				try {
					foreach (array_chunk(array_values(array_unique($filter)), 500) as $chunk) {
						$values = implode(',', array_fill(0, count($chunk), '(?)'));
						$this->connection->execute('INSERT INTO recommender_allowed_items (id) VALUES ' . $values, $chunk);
					}
				} catch (\Throwable $exception) {
					$this->connection->execute('DROP TEMPORARY TABLE IF EXISTS recommender_allowed_items');
					throw $exception;
				}
				return ' AND ' . $column . ' IN (SELECT id FROM recommender_allowed_items)';
			}
			$names = [];
			foreach (array_values($filter) as $index => $id) {
				$name = 'allowed_' . $index;
				$params[$name] = $id;
				$names[] = ':' . $name;
			}
			return ' AND ' . $column . ' IN (' . implode(',', $names) . ')';
		}

		/** Release the temporary table used for a large allowlist.
		 * @param array<int> $filter Allowed IDs
		 * @return void
		 */
		private function clearAllowedTable(array $filter): void {
			if (count($filter) > 500) {
				$this->connection->execute('DROP TEMPORARY TABLE IF EXISTS recommender_allowed_items');
			}
		}
		
		/**
		 * Clamp a predicted rating to the valid [0.0, 1.0] range.
		 * @param float $value The raw predicted rating to clamp into the valid range
		 * @return float
		 */
		private function clampRating(float $value): float {
			return max(0.0, min(1.0, $value));
		}
		
		/**
		 * Extract a column from rows, optionally filtering by a whitelist of IDs.
		 * @param array<int, mixed> $rows
		 * @param string $column Name of the result column holding the product ID
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @return array<int, int>
		 */
		private function filterAndExtract(array $rows, string $column, array $filter): array {
			$result = [];
			
			foreach ($rows as $row) {
				if (!is_array($row) || !isset($row[$column]) || !is_scalar($row[$column])) {
					continue;
				}
				
				$id = (int)$row[$column];
				
				if (empty($filter) || in_array($id, $filter, true)) {
					$result[] = $id;
				}
			}
			
			return $result;
		}
		
		/**
		 * Build a [product_id => rating] map of genuine ratings (>= 0.0, excluding
		 * "not interested") from a visitor's rating list, for slope one prediction input.
		 * @param RatingList $ratings
		 * @return array<int, float>
		 */
		private function collectGenuineRatings(array $ratings): array {
			$products = [];
			
			foreach ($ratings as $entry) {
				if ($entry['rating'] >= 0.0) {
					$products[$entry['product_id']] = $entry['rating'];
				}
			}
			
			return $products;
		}
		
		/**
		 * Accumulate weighted co-occurrence scores for every candidate item linked to
		 * the visitor's rated products, skipping "not interested" entries, zero-count
		 * links, and items the visitor has already rated.
		 * @param RatingList $ratings
		 * @param array<int> $filter When non-empty, only score product IDs in this set
		 * @param int $category Already-resolved category
		 * @param array<int, array<int, int>>|null $reasons Optional contributing IDs by candidate
		 * @return array<int, float> Map of candidate product_id => raw score
		 */
		private function scoreVisitorCandidates(array $ratings, array $filter, int $category, ?array &$reasons = null): array {
			$threshold = $this->config->getThresholdRating();
			$ratedIds = array_column($ratings, 'product_id');
			$scores = [];
			
			foreach ($ratings as $entry) {
				if ($entry['rating'] === $this->config->getNotInterested()) {
					continue;
				}
				
				$rows = $this->connection->execute('
					SELECT
						`item_id2`,
						`liked_count`
					FROM `vogoo_links`
					WHERE `category` = :category AND
					      `item_id1` = :product_id
				', [
					'category'   => $category,
					'product_id' => $entry['product_id']
				])->fetchAll('assoc');
				
				foreach ($rows as $row) {
					if (!is_array($row) || !isset($row['item_id2'], $row['liked_count']) || !is_scalar($row['item_id2'])) {
						continue;
					}
					
					$id = (int)$row['item_id2'];
					
					if ((!empty($filter) && !in_array($id, $filter, true)) || in_array($id, $ratedIds, true)) {
						continue;
					}
					
					if ((int)$row['liked_count'] === 0) {
						continue;
					}
					
					$scores[$id] = ($scores[$id] ?? 0.0) + ($entry['rating'] - $threshold) * (int)$row['liked_count'];
					if ($reasons !== null) {
						$reasons[$id][] = $entry['product_id'];
					}
				}
			}
			
			return $scores;
		}
		
		/**
		 * Accumulate slope one (cnter, diff) totals per candidate item across all of
		 * the visitor's rated products. Runs one query per rated product.
		 * @param array<int, float> $products Map of rated product_id => rating
		 * @param array<int> $filter When non-empty, only accumulate product IDs in this set
		 * @param int $category Already-resolved category
		 * @return array<int, array{0: float, 1: float}> Map of candidate id => [cnter, diff]
		 */
		private function accumulateSlopePredictions(array $products, array $filter, int $category): array {
			// Accumulate cnter and diff across all rated products
			$accumulated = [];
			
			foreach ($products as $ratedProductId => $ratedRating) {
				$rows = $this->connection->execute('
					SELECT
						`item_id2`,
						SUM(`slope_count`) AS cnter,
						SUM(:rating * `slope_count` + `diff_slope`) AS diff
					FROM `vogoo_links`
					WHERE `item_id1` = :product_id AND
					      `slope_count` > 0 AND
					      `category` = :category
					GROUP BY `item_id2`
				', [
					'rating'     => $ratedRating,
					'product_id' => $ratedProductId,
					'category'   => $category
				])->fetchAll('assoc');
				
				foreach ($rows as $row) {
					if (!is_array($row) || !isset($row['item_id2'], $row['cnter'], $row['diff']) || !is_scalar($row['item_id2'])) {
						continue;
					}
					
					$id = (int)$row['item_id2'];
					
					if (!empty($filter) && !in_array($id, $filter, true)) {
						continue;
					}
					
					if (isset($accumulated[$id])) {
						$accumulated[$id][0] += (float)$row['cnter'];
						$accumulated[$id][1] += (float)$row['diff'];
					} else {
						$accumulated[$id] = [(float)$row['cnter'], (float)$row['diff']];
					}
				}
			}
			
			return $accumulated;
		}
	}
