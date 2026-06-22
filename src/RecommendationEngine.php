<?php
	
	namespace Quellabs\Recommender;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	
	/**
	 * Core ratings engine. Handles reading and writing member ratings, and
	 * maintains the vogoo_links table via LinkUpdater when incremental updates
	 * are enabled.
	 *
	 * Ratings are normalised floats in [0.0, 1.0]. The special value
	 * RecommendationConfig::getNotInterested() (-1.0) marks explicit disinterest.
	 *
	 * All methods throw on database failure (CakePHP 5 execute() throws rather
	 * than returning false). Wrap calls in try/catch if you need to handle errors.
	 */
	readonly class RecommendationEngine {
		
		private LinkUpdater $linkUpdater;
		
		/**
		 * RecommendationEngine constructor
		 * @param Connection $connection
		 * @param RecommendationConfig $config
		 */
		public function __construct(
			private Connection $connection,
			private RecommendationConfig $config,
		) {
			$this->linkUpdater = new LinkUpdater($connection, $config);
		}
		
		// -------------------------------------------------------------------------
		// Members
		// -------------------------------------------------------------------------
		
		/**
		 * Return the number of ratings a member has given.
		 *
		 * @param int $memberId
		 * @param bool $realRatings When true, count genuine ratings (>= 0.0)
		 * @param bool $notInterested When true, count "not interested" ratings instead
		 * @param int|null $category Defaults to configured default
		 */
		public function memberNumRatings(int $memberId, bool $realRatings = true, bool $notInterested = false, ?int $category = null): int {
			$cat = $this->config->resolveCategory($category);
			
			$sql = '
				SELECT
					COUNT(*) AS number_of_ratings
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `category` = :category
			';
			
			$params = [
				'member_id' => $memberId,
				'category'  => $cat
			];
			
			if ($realRatings) {
				if (!$notInterested) {
					$sql .= ' AND `rating` >= 0.0';
				}
			} else {
				$sql .= ' AND `rating` = :not_interested';
				$params['not_interested'] = $this->config->getNotInterested();
			}
			
			$row = $this->connection->execute($sql, $params)->fetchAssoc();
			return (int)$row['number_of_ratings'];
		}
		
		/**
		 * Return the average rating this member has given.
		 * Returns 0.0 when the member has no ratings.
		 * @param int $memberId
		 * @param int|null $category Defaults to configured default
		 */
		public function memberAverageRating(int $memberId, ?int $category = null): float {
			$cat = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute('
				SELECT
					AVG(`rating`) AS average
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id
				AND `category` = :category
				AND `rating` >= 0.0
			', [
				'member_id' => $memberId,
				'category'  => $cat
			])->fetchAssoc();
			
			return $row['average'] !== null ? (float)$row['average'] : 0.0;
		}
		
		/**
		 * Return all ratings for a member as an array of
		 * ['product_id' => int, 'rating' => float, 'ts' => string].
		 *
		 * @param int $memberId
		 * @param bool $orderByDate Order by timestamp
		 * @param bool $orderByRating Order by rating value
		 * @param bool $ascending Sort direction
		 * @param bool $realRatings Include genuine ratings
		 * @param bool $notInterested Include "not interested" ratings
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{product_id: int, rating: float, ts: string}>
		 */
		public function memberRatings(int $memberId, bool $orderByDate = false, bool $orderByRating = false, bool $ascending = true, bool $realRatings = true, bool $notInterested = false, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$sql = '
				SELECT
					`product_id`,
					`rating`,
					`ts`
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `category` = :category
			';
			
			$params = [
				'member_id' => $memberId,
				'category'  => $cat
			];
			
			if ($realRatings) {
				if (!$notInterested) {
					$sql .= ' AND `rating` >= 0.0';
				}
			} else {
				$sql .= ' AND `rating` = :not_interested';
				$params['not_interested'] = $this->config->getNotInterested();
			}
			
			if ($orderByDate || $orderByRating) {
				$sql .= ' ORDER BY ' . ($orderByDate ? '`ts`' : '`rating`');
				$sql .= $ascending ? ' ASC' : ' DESC';
			}
			
			return $this->connection->execute($sql, $params)->fetchAll('assoc');
		}
		
		/**
		 * Delete all ratings for a member. When incremental link updates are
		 * enabled, each rating is removed via deleteRating() to keep vogoo_links
		 * consistent.
		 *
		 * @param int $memberId
		 * @param int|null $category Defaults to configured default
		 */
		public function deleteMember(int $memberId, ?int $category = null): void {
			$cat = $this->config->resolveCategory($category);
			
			if ($this->config->isDirectLinks() || $this->config->isDirectSlope()) {
				$rows = $this->connection->execute('
					SELECT `product_id`
					FROM `vogoo_ratings`
					WHERE `member_id` = :member_id AND
					      `category` = :category
				', [
					'member_id' => $memberId,
					'category'  => $cat
				])->fetchAll('assoc');
				
				foreach ($rows as $row) {
					$this->deleteRating($memberId, (int)$row['product_id'], $cat);
				}
				
				return;
			}
			
			$this->connection->execute('
				DELETE
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id
			', [
				'member_id' => $memberId
			]);
		}
		
		// -------------------------------------------------------------------------
		// Products
		// -------------------------------------------------------------------------
		
		/**
		 * Return the number of genuine ratings a product has received.
		 *
		 * @param int $productId
		 * @param int|null $category Defaults to configured default
		 */
		public function productNumRatings(int $productId, ?int $category = null): int {
			$cat = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute('
				SELECT
					COUNT(*) AS number_of_ratings
				FROM `vogoo_ratings`
				WHERE `product_id` = :product_id AND
				      `rating` >= 0.0 AND
				      `category` = :category
			', [
				'product_id' => $productId,
				'category'   => $cat
			])->fetchAssoc();
			
			return (int)$row['number_of_ratings'];
		}
		
		/**
		 * Return the average genuine rating for a product. Returns 0.0 when no
		 * ratings exist.
		 *
		 * @param int $productId
		 * @param int|null $category Defaults to configured default
		 */
		public function productAverageRating(int $productId, ?int $category = null): float {
			$cat = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute('
				SELECT
					AVG(`rating`) AS average
				FROM `vogoo_ratings`
				WHERE `product_id` = :product_id AND
				      `category` = :category AND
				      `rating` >= 0.0
			', [
				'product_id' => $productId,
				'category'   => $cat
			])->fetchAssoc();
			
			return $row['average'] !== null ? (float)$row['average'] : 0.0;
		}
		
		/**
		 * Return all ratings for a product as an array of
		 * ['member_id' => int, 'rating' => float, 'ts' => string].
		 *
		 * @param int $productId
		 * @param bool $orderByDate
		 * @param bool $orderByRating
		 * @param bool $ascending
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{member_id: int, rating: float, ts: string}>
		 */
		public function productRatings(int $productId, bool $orderByDate = false, bool $orderByRating = false, bool $ascending = true, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$sql = '
				SELECT
					`member_id`,
					`rating`,
					`ts`
				FROM `vogoo_ratings`
				WHERE `product_id` = :product_id
				AND `rating` >= 0.0
				AND `category` = :category
			';
			
			$params = [
				'product_id' => $productId,
				'category'   => $cat
			];
			
			if ($orderByDate || $orderByRating) {
				$sql .= ' ORDER BY ' . ($orderByDate ? '`ts`' : '`rating`');
				$sql .= $ascending ? ' ASC' : ' DESC';
			}
			
			return $this->connection->execute($sql, $params)->fetchAll('assoc');
		}
		
		/**
		 * Delete all ratings for a product. When incremental link updates are
		 * enabled, each rating is removed via deleteRating() to keep vogoo_links
		 * consistent.
		 *
		 * @param int $productId
		 * @param int|null $category Defaults to configured default
		 */
		public function deleteProduct(int $productId, ?int $category = null): void {
			$cat = $this->config->resolveCategory($category);
			
			if ($this->config->isDirectLinks() || $this->config->isDirectSlope()) {
				$rows = $this->connection->execute('
					SELECT `member_id`
					FROM `vogoo_ratings`
					WHERE `product_id` = :product_id
					AND `category` = :category
				', [
					'product_id' => $productId,
					'category'   => $cat
				])->fetchAll('assoc');
				
				foreach ($rows as $row) {
					$this->deleteRating((int)$row['member_id'], $productId, $cat);
				}
				
				return;
			}
			
			$this->connection->execute('
				DELETE
				FROM `vogoo_ratings`
				WHERE `product_id` = :product_id AND
				      `category` = :category
			', [
				'product_id' => $productId,
				'category'   => $cat
			]);
		}
		
		// -------------------------------------------------------------------------
		// Combined
		// -------------------------------------------------------------------------
		
		/**
		 * Return the rating and timestamp for a specific member/product pair as
		 * ['rating' => float, 'ts' => string], or an empty array when no rating
		 * exists.
		 *
		 * @param int $memberId
		 * @param int $productId
		 * @param bool $notInterested Include "not interested" ratings
		 * @param int|null $category Defaults to configured default
		 * @return array{rating: float, ts: string}|array{}
		 */
		public function getRating(int $memberId, int $productId, bool $notInterested = false, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$sql = '
				SELECT
					`rating`,
					`ts`
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `product_id` = :product_id AND
				      `category` = :category
			';
			
			$params = [
				'member_id'  => $memberId,
				'product_id' => $productId,
				'category'   => $cat
			];
			
			if (!$notInterested) {
				$sql .= ' AND `rating` >= 0.0';
			}
			
			$stmt = $this->connection->execute($sql, $params);
			
			if ($stmt->rowCount() !== 1) {
				return [];
			}
			
			$row = $stmt->fetchAssoc();
			return ['rating' => (float)$row['rating'], 'ts' => $row['ts']];
		}
		
		/**
		 * Set or update a rating for a member/product pair.
		 * Triggers incremental link/slope updates if enabled.
		 *
		 * @param int $memberId
		 * @param int $productId
		 * @param float $rating Must be in [0.0, 1.0] or equal getNotInterested()
		 * @param int|null $category Defaults to configured default
		 */
		public function setRating(int $memberId, int $productId, float $rating, ?int $category = null): bool {
			$cat = $this->config->resolveCategory($category);
			
			if (($rating < 0.0 && $rating !== $this->config->getNotInterested()) || $rating > 1.0) {
				return false;
			}
			
			// Check whether a rating already exists
			$stmt = $this->connection->execute('
				SELECT `rating`
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `product_id` = :product_id AND
				      `category` = :category
			', [
				'member_id'  => $memberId,
				'product_id' => $productId,
				'category'   => $cat
			]);
			
			if ($stmt->rowCount() === 1) {
				$previous = (float)$stmt->fetchAssoc()['rating'];
				
				if ($this->config->isDirectLinks()) {
					$this->linkUpdater->updateLinks($memberId, $productId, $cat, $rating, $previous);
				}
				
				if ($this->config->isDirectSlope()) {
					$this->linkUpdater->updateSlope($memberId, $productId, $cat, $rating, $previous);
				}
				
				return $this->connection->execute('
					UPDATE `vogoo_ratings`
					SET
						`rating` = :rating,
						`ts` = NOW()
					WHERE `member_id` = :member_id AND
					      `product_id` = :product_id AND
					      `category` = :category
				', [
					'rating'     => $rating,
					'member_id'  => $memberId,
					'product_id' => $productId,
					'category'   => $cat
				])->rowCount() === 1;
			}
			
			// No existing rating: insert
			if ($this->config->isDirectLinks()) {
				$this->linkUpdater->updateLinks($memberId, $productId, $cat, $rating, -1.0);
			}
			
			if ($this->config->isDirectSlope()) {
				$this->linkUpdater->updateSlope($memberId, $productId, $cat, $rating, -1.0);
			}
			
			return $this->connection->execute('
				INSERT INTO `vogoo_ratings` (`member_id`, `product_id`, `category`, `rating`, `ts`)
				VALUES (:member_id, :product_id, :category, :rating, NOW())
			', [
				'member_id'  => $memberId,
				'product_id' => $productId,
				'category'   => $cat,
				'rating'     => $rating
			])->rowCount() === 1;
		}
		
		/**
		 * Record an implicit rating from a purchase (1.0) or a click (0.7, or
		 * increment by 0.01 if already rated below 1.0).
		 *
		 * @param int $memberId
		 * @param int $productId
		 * @param bool $purchase True for a purchase, false for a click
		 * @param int|null $category Defaults to configured default
		 */
		public function automaticRating(int $memberId, int $productId, bool $purchase, ?int $category = null): bool {
			$cat = $this->config->resolveCategory($category);
			
			if ($purchase) {
				return $this->setRating($memberId, $productId, 1.0, $cat);
			}
			
			// Click: initialise at 0.7, or nudge existing rating up by 0.01
			$existing = $this->getRating($memberId, $productId, false, $cat);
			
			if (empty($existing)) {
				return $this->setRating($memberId, $productId, 0.7, $cat);
			}
			
			if ($existing['rating'] < 1.0) {
				return $this->setRating($memberId, $productId, $existing['rating'] + 0.01, $cat);
			}
			
			return true;
		}
		
		/**
		 * Mark a product as "not interested" for a member.
		 *
		 * @param int $memberId
		 * @param int $productId
		 * @param int|null $category Defaults to configured default
		 */
		public function setNotInterested(int $memberId, int $productId, ?int $category = null): bool {
			return $this->setRating($memberId, $productId, $this->config->getNotInterested(), $category);
		}
		
		/**
		 * Delete a single member/product rating.
		 * Triggers incremental link/slope cleanup if enabled.
		 *
		 * @param int $memberId
		 * @param int $productId
		 * @param int|null $category Defaults to configured default
		 */
		public function deleteRating(int $memberId, int $productId, ?int $category = null): void {
			$cat = $this->config->resolveCategory($category);
			
			if ($this->config->isDirectLinks() || $this->config->isDirectSlope()) {
				$stmt = $this->connection->execute('
					SELECT `rating`
					FROM `vogoo_ratings`
					WHERE `member_id` = :member_id AND
					      `product_id` = :product_id AND
					      `category` = :category
				', [
					'member_id'  => $memberId,
					'product_id' => $productId,
					'category'   => $cat
				]);
				
				if ($stmt->rowCount() === 1) {
					$previous = (float)$stmt->fetchAssoc()['rating'];
					
					if ($this->config->isDirectLinks()) {
						$this->linkUpdater->updateLinks($memberId, $productId, $cat, -1.0, $previous);
					}
					
					if ($this->config->isDirectSlope()) {
						$this->linkUpdater->updateSlope($memberId, $productId, $cat, -1.0, $previous);
					}
				}
			}
			
			$this->connection->execute('
				DELETE
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `product_id` = :product_id AND
				      `category` = :category
			', [
				'member_id'  => $memberId,
				'product_id' => $productId,
				'category'   => $cat
			]);
		}
	}