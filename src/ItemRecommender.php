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
	 *  - Links (cnt only): item co-occurrence — "people who liked A also liked B"
	 *  - Slope One (cnt + diff_slope): weighted predicted rating for unseen items
	 *
	 * Both strategies require vogoo_links to be pre-populated, either via a
	 * batch rebuild (not included here) or via incremental updates through
	 * LinkUpdater.
	 *
	 * All methods throw on database failure. Wrap calls in try/catch if you need
	 * to handle errors.
	 */
	class ItemRecommender {
		
		public function __construct(
			private readonly Connection           $connection,
			private readonly RecommendationConfig $config,
		) {}
		
		// -------------------------------------------------------------------------
		// Links (co-occurrence)
		// -------------------------------------------------------------------------
		
		/**
		 * Return items that co-occur with the given product, ordered by
		 * co-occurrence count descending.
		 *
		 * @param int        $productId
		 * @param array<int> $filter    When non-empty, only return product IDs in this set
		 * @param int        $limit     Maximum number of results (0 = unlimited)
		 * @param int|null   $category  Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function getLinkedItems(int $productId, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$sql = 'SELECT item_id2, cnt
		        FROM vogoo_links
		        WHERE item_id1 = :product_id
		          AND category = :category
		        ORDER BY cnt DESC';
			
			$params = ['product_id' => $productId, 'category' => $cat];
			
			if ($limit > 0) {
				$sql .= ' LIMIT :limit';
				$params['limit'] = $limit;
			}
			
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			return $this->filterAndExtract($rows, 'item_id2', $filter);
		}
		
		/**
		 * Return recommended items for a member using item-based CF, ordered by
		 * weighted co-occurrence score descending.
		 * Only returns items the member has not already rated.
		 *
		 * @param int        $memberId
		 * @param array<int> $filter   When non-empty, only return product IDs in this set
		 * @param int        $limit    Maximum number of results (0 = unlimited)
		 * @param int|null   $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function memberGetRecommendedItems(int $memberId, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat       = $this->config->resolveCategory($category);
			$threshold = $this->config->getThresholdRating();
			
			$sql = 'SELECT l.item_id2, SUM(l.cnt * (r.rating - :threshold)) AS cnter
		        FROM vogoo_links l
		        INNER JOIN vogoo_ratings r ON r.member_id = :member_id
		            AND l.item_id1 = r.product_id
		            AND r.rating >= 0.0
		            AND l.category = r.category
		            AND r.category = :category
		        WHERE NOT EXISTS (
		            SELECT 1 FROM vogoo_ratings vr
		            WHERE vr.member_id = :member_id2
		              AND vr.category = :category2
		              AND vr.product_id = l.item_id2
		        )
		        GROUP BY l.item_id2
		        HAVING cnter > 0
		        ORDER BY cnter DESC';
			
			$params = [
				'threshold'  => $threshold,
				'member_id'  => $memberId,
				'category'   => $cat,
				'member_id2' => $memberId,
				'category2'  => $cat,
			];
			
			if ($limit > 0) {
				$sql .= ' LIMIT :limit';
				$params['limit'] = $limit;
			}
			
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			return $this->filterAndExtract($rows, 'item_id2', $filter);
		}
		
		/**
		 * Return the products this member has already rated that are linked to the
		 * given product — the "why we recommend this" list.
		 *
		 * @param int      $memberId
		 * @param int      $productId
		 * @param int      $limit     Maximum number of results (0 = unlimited)
		 * @param int|null $category  Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function memberGetReasons(int $memberId, int $productId, int $limit = 0, ?int $category = null): array {
			$cat       = $this->config->resolveCategory($category);
			$threshold = $this->config->getThresholdRating();
			
			$sql = 'SELECT r.product_id
		        FROM vogoo_ratings r
		        INNER JOIN vogoo_links l ON l.item_id1 = :product_id
		            AND r.product_id = l.item_id2
		            AND l.cnt > 0
		            AND l.category = r.category
		        WHERE r.member_id = :member_id
		          AND r.category = :category
		          AND r.rating >= :threshold';
			
			$params = [
				'product_id' => $productId,
				'member_id'  => $memberId,
				'category'   => $cat,
				'threshold'  => $threshold,
			];
			
			if ($limit > 0) {
				$sql .= ' LIMIT :limit';
				$params['limit'] = $limit;
			}
			
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			return array_map('intval', array_column($rows, 'product_id'));
		}
		
		/**
		 * Return recommended items for an anonymous visitor using item-based CF.
		 * Ratings are read from the provided VisitorContext rather than the database.
		 *
		 * @param VisitorContext $visitor
		 * @param array<int>     $filter   When non-empty, only return product IDs in this set
		 * @param int            $limit    Maximum number of results (0 = unlimited)
		 * @param int|null       $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function visitorGetRecommendedItems(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat       = $this->config->resolveCategory($category);
			$threshold = $this->config->getThresholdRating();
			$ratings   = $visitor->getRatings($cat);
			
			if (empty($ratings)) {
				return [];
			}
			
			$ratedIds = array_column($ratings, 'product_id');
			$scores   = [];
			
			foreach ($ratings as $entry) {
				if ($entry['rating'] === $this->config->getNotInterested()) {
					continue;
				}
				
				$rows = $this->connection->execute(
					'SELECT item_id2, cnt
				 FROM vogoo_links
				 WHERE category = :category
				   AND item_id1 = :product_id',
					['category' => $cat, 'product_id' => $entry['product_id']],
				)->fetchAll('assoc');
				
				foreach ($rows as $row) {
					$id = (int)$row['item_id2'];
					
					if ((!empty($filter) && !in_array($id, $filter, true)) || in_array($id, $ratedIds, true)) {
						continue;
					}
					
					if ((int)$row['cnt'] === 0) {
						continue;
					}
					
					$scores[$id] = ($scores[$id] ?? 0.0) + ($entry['rating'] - $threshold) * (int)$row['cnt'];
				}
			}
			
			$scores = array_filter($scores, fn($s) => $s > 0);
			arsort($scores);
			
			$result = array_keys($scores);
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		/**
		 * Return the visitor's already-rated products that are linked to the given
		 * product — the "why we recommend this" list for anonymous visitors.
		 *
		 * @param VisitorContext $visitor
		 * @param int            $productId
		 * @param int            $limit     Maximum number of results (0 = unlimited)
		 * @param int|null       $category  Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function visitorGetReasons(VisitorContext $visitor, int $productId, int $limit = 0, ?int $category = null): array {
			$cat       = $this->config->resolveCategory($category);
			$threshold = $this->config->getThresholdRating();
			$ratings   = $visitor->getRatings($cat);
			
			$likedIds = array_column(
				array_filter($ratings, fn($e) => $e['rating'] >= $threshold),
				'product_id'
			);
			
			if (empty($likedIds)) {
				return [];
			}
			
			$placeholders = implode(',', array_fill(0, count($likedIds), '?'));
			
			$sql = "SELECT item_id2
		        FROM vogoo_links
		        WHERE category = ?
		          AND item_id1 = ?
		          AND item_id2 IN ({$placeholders})
		          AND cnt > 0";
			
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
		 *
		 * @param int        $productId
		 * @param int        $minLinks   Minimum co-occurrence count to include a pair
		 * @param array<int> $filter     When non-empty, only return product IDs in this set
		 * @param int        $limit      Maximum number of results (0 = unlimited)
		 * @param int|null   $category   Defaults to configured default
		 * @return array<int, array{product_id: int, diff: float}>
		 */
		public function getSlopeItems(int $productId, int $minLinks = 1, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$sql = 'SELECT item_id2, (diff_slope / cnt) AS avg_diff
		        FROM vogoo_links
		        WHERE item_id1 = :product_id
		          AND category = :category
		          AND cnt != 0
		          AND cnt >= :min_links
		        ORDER BY avg_diff DESC';
			
			$params = ['product_id' => $productId, 'category' => $cat, 'min_links' => $minLinks];
			
			if ($limit > 0) {
				$sql .= ' LIMIT :limit';
				$params['limit'] = $limit;
			}
			
			$rows   = $this->connection->execute($sql, $params)->fetchAll('assoc');
			$result = [];
			
			foreach ($rows as $row) {
				$id = (int)$row['item_id2'];
				
				if (!empty($filter) && !in_array($id, $filter, true)) {
					continue;
				}
				
				$result[] = ['product_id' => $id, 'diff' => (float)$row['avg_diff']];
			}
			
			return $result;
		}
		
		/**
		 * Predict a member's rating for a single product using slope one.
		 * Returns null when there is insufficient data to make a prediction.
		 *
		 * @param int      $memberId
		 * @param int      $productId
		 * @param int|null $category  Defaults to configured default
		 * @return float|null Predicted rating in [0.0, 1.0], or null
		 */
		public function memberPredict(int $memberId, int $productId, ?int $category = null): ?float {
			$cat = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute(
				'SELECT SUM(l.cnt) AS cnter,
			        SUM(r.rating * l.cnt - l.diff_slope) AS diff
			 FROM vogoo_links l
			 INNER JOIN vogoo_ratings r ON r.member_id = :member_id
			     AND r.product_id = l.item_id2
			     AND r.category = l.category
			 WHERE l.item_id1 = :product_id
			   AND l.category = :category',
				['member_id' => $memberId, 'product_id' => $productId, 'category' => $cat],
			)->fetchAssoc();
			
			if ((int)$row['cnter'] === 0) {
				return null;
			}
			
			return $this->clampRating((float)$row['diff'] / (float)$row['cnter']);
		}
		
		/**
		 * Predict ratings for all unrated items for a member using slope one,
		 * returned as [['product_id' => int, 'rating' => float], ...] sorted
		 * by predicted rating descending.
		 *
		 * @param int        $memberId
		 * @param array<int> $filter   When non-empty, only return product IDs in this set
		 * @param int        $limit    Maximum number of results (0 = unlimited)
		 * @param int|null   $category Defaults to configured default
		 * @return array<int, array{product_id: int, rating: float}>
		 */
		public function memberPredictAll(int $memberId, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$rows = $this->connection->execute(
				'SELECT l.item_id2,
			        SUM(l.cnt) AS cnter,
			        SUM(r.rating * l.cnt + l.diff_slope) AS diff
			 FROM vogoo_links l
			 INNER JOIN vogoo_ratings r ON r.member_id = :member_id
			     AND r.rating >= 0.0
			     AND l.item_id1 = r.product_id
			     AND l.cnt != 0
			     AND r.category = :category
			     AND l.category = r.category
			 WHERE NOT EXISTS (
			     SELECT 1 FROM vogoo_ratings vr
			     WHERE vr.member_id = :member_id2
			       AND vr.category = :category2
			       AND vr.product_id = l.item_id2
			 )
			 GROUP BY l.item_id2',
				[
					'member_id'  => $memberId,
					'category'   => $cat,
					'member_id2' => $memberId,
					'category2'  => $cat,
				],
			)->fetchAll('assoc');
			
			$result = [];
			
			foreach ($rows as $row) {
				$id = (int)$row['item_id2'];
				
				if (!empty($filter) && !in_array($id, $filter, true)) {
					continue;
				}
				
				$result[] = [
					'product_id' => $id,
					'rating'     => $this->clampRating((float)$row['diff'] / (float)$row['cnter']),
				];
			}
			
			usort($result, fn($a, $b) => $b['rating'] <=> $a['rating']);
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		/**
		 * Predict a rating for a single product for an anonymous visitor using
		 * slope one. Returns null when there is insufficient data.
		 *
		 * @param VisitorContext $visitor
		 * @param int            $productId
		 * @param int|null       $category  Defaults to configured default
		 * @return float|null Predicted rating in [0.0, 1.0], or null
		 */
		public function visitorPredict(VisitorContext $visitor, int $productId, ?int $category = null): ?float {
			$cat     = $this->config->resolveCategory($category);
			$ratings = $visitor->getRatings($cat);
			
			$products = [];
			
			foreach ($ratings as $entry) {
				if ($entry['rating'] >= 0.0) {
					$products[$entry['product_id']] = $entry['rating'];
				}
			}
			
			if (empty($products)) {
				return null;
			}
			
			$rows = $this->connection->execute(
				'SELECT item_id2, cnt, diff_slope
			 FROM vogoo_links
			 WHERE item_id1 = :product_id
			   AND category = :category
			   AND cnt > 0',
				['product_id' => $productId, 'category' => $cat],
			)->fetchAll('assoc');
			
			$numerator   = 0.0;
			$denominator = 0;
			
			foreach ($rows as $row) {
				$id = (int)$row['item_id2'];
				
				if (isset($products[$id])) {
					$numerator   += $products[$id] * (int)$row['cnt'] - (float)$row['diff_slope'];
					$denominator += (int)$row['cnt'];
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
		 *
		 * @param VisitorContext $visitor
		 * @param array<int>     $filter   When non-empty, only return product IDs in this set
		 * @param int            $limit    Maximum number of results (0 = unlimited)
		 * @param int|null       $category Defaults to configured default
		 * @return array<int, array{product_id: int, rating: float}>
		 */
		public function visitorPredictAll(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat     = $this->config->resolveCategory($category);
			$ratings = $visitor->getRatings($cat);
			
			$products = [];
			$ratedIds = [];
			
			foreach ($ratings as $entry) {
				if ($entry['rating'] >= 0.0) {
					$products[$entry['product_id']] = $entry['rating'];
					$ratedIds[]                      = $entry['product_id'];
				}
			}
			
			if (empty($products)) {
				return [];
			}
			
			// Accumulate cnter and diff across all rated products
			$accumulated = [];
			
			foreach ($products as $ratedProductId => $ratedRating) {
				$rows = $this->connection->execute(
					'SELECT item_id2, SUM(cnt) AS cnter, SUM(:rating * cnt + diff_slope) AS diff
				 FROM vogoo_links
				 WHERE item_id1 = :product_id
				   AND cnt > 0
				   AND category = :category
				 GROUP BY item_id2',
					['rating' => $ratedRating, 'product_id' => $ratedProductId, 'category' => $cat],
				)->fetchAll('assoc');
				
				foreach ($rows as $row) {
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
			
			$result = [];
			
			foreach ($accumulated as $id => [$cnter, $diff]) {
				if (in_array($id, $ratedIds, true)) {
					continue;
				}
				
				$result[] = [
					'product_id' => $id,
					'rating'     => $this->clampRating($diff / $cnter),
				];
			}
			
			usort($result, fn($a, $b) => $b['rating'] <=> $a['rating']);
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}
		
		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------
		
		/**
		 * Clamp a predicted rating to the valid [0.0, 1.0] range.
		 */
		private function clampRating(float $value): float {
			return max(0.0, min(1.0, $value));
		}
		
		/**
		 * Extract a column from rows, optionally filtering by a whitelist of IDs.
		 *
		 * @param array<int, array<string, mixed>> $rows
		 * @param string                           $column
		 * @param array<int>                       $filter
		 * @return array<int, int>
		 */
		private function filterAndExtract(array $rows, string $column, array $filter): array {
			$result = [];
			
			foreach ($rows as $row) {
				$id = (int)$row[$column];
				
				if (empty($filter) || in_array($id, $filter, true)) {
					$result[] = $id;
				}
			}
			
			return $result;
		}
	}