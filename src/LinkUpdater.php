<?php
	
	namespace Quellabs\Recommender;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	
	/**
	 * Maintains the vogoo_links table incrementally as ratings are added, changed,
	 * or removed. Replaces the set_direct_links() and set_direct_slope() functions
	 * from the original directitems.php.
	 *
	 * Only active when RecommendationConfig::isDirectLinks() or isDirectSlope()
	 * is true. When both are false this class is never called.
	 *
	 * The $rating and $previous arguments use -1.0 as a sentinel meaning
	 * "no rating exists" (i.e. the rating is being created or deleted).
	 *
	 * All public methods wrap their multi-statement mutations in a transaction to
	 * prevent vogoo_links from becoming inconsistent if a statement fails midway.
	 */
	readonly class LinkUpdater {
		
		private Connection $connection;
		private RecommendationConfig $config;
		
		/**
		 * LinkUpdater constructor
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 */
		public function __construct(Connection $connection, RecommendationConfig $config) {
			$this->config = $config;
			$this->connection = $connection;
		}
		
		/**
		 * Update the co-occurrence count in vogoo_links when a rating crosses
		 * the like/dislike threshold.
		 * @param int $memberId The member whose rating changed
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $rating The new rating (-1.0 = being deleted)
		 * @param float $previous The previous rating (-1.0 = did not exist)
		 * @throws \Exception
		 */
		public function updateLinks(int $memberId, int $productId, int $category, float $rating, float $previous): void {
			$threshold = $this->config->getThresholdRating();
			$crossedUp = $rating >= $threshold && $previous < $threshold;
			$crossedDown = $rating < $threshold && $previous >= $threshold;
			
			if (!$crossedUp && !$crossedDown) {
				return;
			}
			
			$this->connection->transactional(function () use ($memberId, $productId, $category, $threshold, $crossedUp): void {
				if ($crossedUp) {
					$this->incrementLinks($memberId, $productId, $category, $threshold);
				} else {
					$this->decrementLinks($memberId, $productId, $category, $threshold);
				}
			});
		}
		
		/**
		 * Update the slope one diff_slope and cnt columns in vogoo_links when a
		 * rating is added, changed, or removed.
		 * @param int $memberId The member whose rating changed
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $rating The new rating (-1.0 = being deleted)
		 * @param float $previous The previous rating (-1.0 = did not exist)
		 * @throws \Exception
		 */
		public function updateSlope(int $memberId, int $productId, int $category, float $rating, float $previous): void {
			// Both absent: nothing to do
			if ($rating < 0.0 && $previous < 0.0) {
				return;
			}
			
			$this->connection->transactional(function () use ($memberId, $productId, $category, $rating, $previous): void {
				if ($previous < 0.0) {
					// New rating: add slope entries
					$this->addSlope($memberId, $productId, $category, $rating);
				} elseif ($rating < 0.0) {
					// Deleted rating: remove slope entries
					$this->removeSlope($memberId, $productId, $category, $previous);
				} else {
					// Changed rating: adjust diff_slope by the delta
					$this->adjustSlope($memberId, $productId, $category, $rating, $previous);
				}
			});
		}
		
		// -------------------------------------------------------------------------
		
		/**
		 * Increment co-occurrence counts and insert missing link rows when a rating
		 * crosses the like threshold upward.
		 * @param int $memberId The member whose rating changed
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $threshold The minimum rating to count as "liked"
		 * @return void
		 */
		private function incrementLinks(int $memberId, int $productId, int $category, float $threshold): void {
			$this->incrementExistingLinks($memberId, $productId, $category, $threshold);
			$this->insertMissingLinks($memberId, $productId, $category, $threshold);
		}
		
		/**
		 * Increment the co-occurrence count of every existing link row that pairs
		 * the rated product with another product this member already likes.
		 * @param int $memberId The member whose rating changed
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $threshold The minimum rating to count as "liked"
		 * @return void
		 */
		private function incrementExistingLinks(int $memberId, int $productId, int $category, float $threshold): void {
			// Increment co-occurrence counts for all products this member already likes
			$this->connection->execute('
					UPDATE `vogoo_links` vl
					INNER JOIN `vogoo_ratings` vr ON vr.`member_id` = :member_id AND
					                                 vr.`category` = :category AND
					                                 vr.`rating` >= :threshold AND
					                                 vr.`product_id` <> :product_id
					SET vl.`cnt` = vl.`cnt` + 1
				 WHERE (
						(vl.`item_id1` = vr.`product_id` AND vl.`item_id2` = :product_id2)
				     OR
						(vl.`item_id2` = vr.`product_id` AND vl.`item_id1` = :product_id3)
					) AND vl.`category` = :category2
				', [
				'member_id'   => $memberId,
				'category'    => $category,
				'threshold'   => $threshold,
				'product_id'  => $productId,
				'product_id2' => $productId,
				'product_id3' => $productId,
				'category2'   => $category
			]);
		}
		
		/**
		 * Insert link rows for liked pairs that do not yet exist, in both
		 * (item_id1, item_id2) orderings.
		 * @param int $memberId The member whose rating changed
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $threshold The minimum rating to count as "liked"
		 * @return void
		 */
		private function insertMissingLinks(int $memberId, int $productId, int $category, float $threshold): void {
			// Insert link rows that do not yet exist
			$this->connection->execute('
					INSERT INTO `vogoo_links` (`item_id1`, `item_id2`, `category`, `cnt`, `diff_slope`)
					SELECT :product_id, vr.`product_id`, :category, 1, 0.0
					FROM `vogoo_ratings` vr
					WHERE vr.`member_id` = :member_id AND
					      vr.`category` = :category2 AND
					      vr.`rating` >= :threshold AND
					      vr.`product_id` <> :product_id2 AND
					      NOT EXISTS (
					      	SELECT 1 FROM `vogoo_links` vl
					      	WHERE vl.`category` = :category3 AND
					      	      vl.`item_id1` = :product_id3 AND
					      	      vl.`item_id2` = vr.`product_id`
				   )
				 UNION
					SELECT vr.`product_id`, :product_id4, :category4, 1, 0.0
					FROM `vogoo_ratings` vr
					WHERE vr.`member_id` = :member_id2 AND
					      vr.`category` = :category5 AND
					      vr.`rating` >= :threshold2 AND
					      vr.`product_id` <> :product_id5 AND
					      NOT EXISTS (
					      	SELECT 1 FROM `vogoo_links` vl
					      	WHERE vl.`category` = :category6 AND
					      	      vl.`item_id2` = :product_id6 AND
					      	      vl.`item_id1` = vr.`product_id`
					      )
				', [
				'product_id'  => $productId,
				'category'    => $category,
				'member_id'   => $memberId,
				'category2'   => $category,
				'threshold'   => $threshold,
				'product_id2' => $productId,
				'category3'   => $category,
				'product_id3' => $productId,
				'product_id4' => $productId,
				'category4'   => $category,
				'member_id2'  => $memberId,
				'category5'   => $category,
				'threshold2'  => $threshold,
				'product_id5' => $productId,
				'category6'   => $category,
				'product_id6' => $productId
			]);
		}
		
		/**
		 * Decrement co-occurrence counts and prune zero-count rows when a rating
		 * crosses the like threshold downward.
		 * @param int $memberId The member whose rating changed
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $threshold The minimum rating to count as "liked"
		 * @return void
		 */
		private function decrementLinks(int $memberId, int $productId, int $category, float $threshold): void {
			$this->connection->execute('
				UPDATE `vogoo_links` vl
				INNER JOIN `vogoo_ratings` vr ON vr.`member_id` = :member_id AND
				                                 vr.`category` = :category AND
				                                 vr.`rating` >= :threshold AND
				                                 vr.`product_id` <> :product_id
				SET vl.`cnt` = vl.`cnt` - 1
			 WHERE (
					(vl.`item_id1` = vr.`product_id` AND vl.`item_id2` = :product_id2)
			     OR
					(vl.`item_id2` = vr.`product_id` AND vl.`item_id1` = :product_id3)
				) AND vl.`category` = :category2
			', [
				'member_id'   => $memberId,
				'category'    => $category,
				'threshold'   => $threshold,
				'product_id'  => $productId,
				'product_id2' => $productId,
				'product_id3' => $productId,
				'category2'   => $category
			]);
			
			$this->connection->execute('DELETE FROM `vogoo_links` WHERE `cnt` = 0');
		}
		
		/**
		 * Add slope one entries for a new rating — updates existing link rows and
		 * inserts any pairs that do not yet exist.
		 * @param int $memberId The member whose rating was added
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $rating The new rating value
		 * @return void
		 */
		private function addSlope(int $memberId, int $productId, int $category, float $rating): void {
			$this->addSlopeAsItem1($memberId, $productId, $category, $rating);
			$this->addSlopeAsItem2($memberId, $productId, $category, $rating);
			$this->insertMissingSlopePairs($memberId, $productId, $category, $rating);
		}
		
		/**
		 * Update existing link rows where the rated product is item_id1.
		 * @param int $memberId The member whose rating was added
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $rating The new rating value
		 * @return void
		 */
		private function addSlopeAsItem1(int $memberId, int $productId, int $category, float $rating): void {
			// Update existing rows where product_id is item_id1
			$this->connection->execute('
				UPDATE `vogoo_links` vl
				INNER JOIN `vogoo_ratings` vr ON vr.`member_id` = :member_id AND
				                                 vr.`category` = :category AND
				                                 vr.`rating` >= 0.0 AND
				                                 vr.`product_id` <> :product_id
				SET vl.`cnt` = vl.`cnt` + 1,
				    vl.`diff_slope` = vl.`diff_slope` + vr.`rating` - :rating
				WHERE vl.`item_id2` = vr.`product_id` AND
				      vl.`item_id1` = :product_id2 AND
				      vl.`category` = :category2
			', [
				'member_id'   => $memberId,
				'category'    => $category,
				'product_id'  => $productId,
				'rating'      => $rating,
				'product_id2' => $productId,
				'category2'   => $category
			]);
		}
		
		/**
		 * Update existing link rows where the rated product is item_id2.
		 * @param int $memberId The member whose rating was added
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $rating The new rating value
		 * @return void
		 */
		private function addSlopeAsItem2(int $memberId, int $productId, int $category, float $rating): void {
			// Update existing rows where product_id is item_id2
			$this->connection->execute('
				UPDATE `vogoo_links` vl
				INNER JOIN `vogoo_ratings` vr ON vr.`member_id` = :member_id AND
				                                 vr.`category` = :category AND
				                                 vr.`rating` >= 0.0 AND
				                                 vr.`product_id` <> :product_id
				SET vl.`cnt` = vl.`cnt` + 1,
				    vl.`diff_slope` = vl.`diff_slope` - vr.`rating` + :rating
				WHERE vl.`item_id1` = vr.`product_id` AND
				      vl.`item_id2` = :product_id2 AND
				      vl.`category` = :category2
			', [
				'member_id'   => $memberId,
				'category'    => $category,
				'product_id'  => $productId,
				'rating'      => $rating,
				'product_id2' => $productId,
				'category2'   => $category
			]);
		}
		
		/**
		 * Insert link rows for pairs that do not yet exist, in both orderings.
		 * @param int $memberId The member whose rating was added
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $rating The new rating value
		 * @return void
		 */
		private function insertMissingSlopePairs(int $memberId, int $productId, int $category, float $rating): void {
			// Insert missing link rows
			$this->connection->execute('
				INSERT INTO `vogoo_links` (`item_id1`, `item_id2`, `category`, `cnt`, `diff_slope`)
				SELECT :product_id, vr.`product_id`, :category, 1, vr.`rating` - :rating
				FROM `vogoo_ratings` vr
				WHERE vr.`member_id` = :member_id AND
				      vr.`category` = :category2 AND
				      vr.`rating` >= 0.0 AND
				      vr.`product_id` <> :product_id2 AND
				      NOT EXISTS (
				      	SELECT 1 FROM `vogoo_links` vl
				      	WHERE vl.`category` = :category3 AND
				      	      vl.`item_id1` = :product_id3 AND
				      	      vl.`item_id2` = vr.`product_id`
			   )
			 UNION
				SELECT vr.`product_id`, :product_id4, :category4, 1, :rating2 - vr.`rating`
				FROM `vogoo_ratings` vr
				WHERE vr.`member_id` = :member_id2 AND
				      vr.`category` = :category5 AND
				      vr.`rating` >= 0.0 AND
				      vr.`product_id` <> :product_id5 AND
				      NOT EXISTS (
				      	SELECT 1 FROM `vogoo_links` vl
				      	WHERE vl.`category` = :category6 AND
				      	      vl.`item_id2` = :product_id6 AND
				      	      vl.`item_id1` = vr.`product_id`
				      )
			', [
				'product_id'  => $productId,
				'category'    => $category,
				'rating'      => $rating,
				'member_id'   => $memberId,
				'category2'   => $category,
				'product_id2' => $productId,
				'category3'   => $category,
				'product_id3' => $productId,
				'product_id4' => $productId,
				'category4'   => $category,
				'rating2'     => $rating,
				'member_id2'  => $memberId,
				'category5'   => $category,
				'product_id5' => $productId,
				'category6'   => $category,
				'product_id6' => $productId
			]);
		}
		
		/**
		 * Remove slope one entries for a deleted rating — decrements cnt and
		 * diff_slope, then prunes zero-count rows.
		 *
		 * @param int $memberId The member whose rating was deleted
		 * @param int $productId The product that was rated
		 * @param int $category The category
		 * @param float $previous The rating value that was deleted
		 * @return void
		 */
		private function removeSlope(int $memberId, int $productId, int $category, float $previous): void {
			$this->connection->execute('
				UPDATE `vogoo_links` vl
				INNER JOIN `vogoo_ratings` vr ON vr.`member_id` = :member_id AND
				                                 vr.`category` = :category AND
				                                 vr.`rating` >= 0.0 AND
				                                 vr.`product_id` <> :product_id
				SET vl.`cnt` = vl.`cnt` - 1,
				    vl.`diff_slope` = vl.`diff_slope` - vr.`rating` + :previous
				WHERE vl.`item_id2` = vr.`product_id` AND
				      vl.`item_id1` = :product_id2 AND
				      vl.`category` = :category2
			', [
				'member_id'   => $memberId,
				'category'    => $category,
				'product_id'  => $productId,
				'previous'    => $previous,
				'product_id2' => $productId,
				'category2'   => $category
			]);
			
			$this->connection->execute('
				UPDATE `vogoo_links` vl
				INNER JOIN `vogoo_ratings` vr ON vr.`member_id` = :member_id AND
				                                 vr.`category` = :category AND
				                                 vr.`rating` >= 0.0 AND
				                                 vr.`product_id` <> :product_id
				SET vl.`cnt` = vl.`cnt` - 1,
				    vl.`diff_slope` = vl.`diff_slope` + vr.`rating` - :previous
				WHERE vl.`item_id1` = vr.`product_id` AND
				      vl.`item_id2` = :product_id2 AND
				      vl.`category` = :category2
			', [
				'member_id'   => $memberId,
				'category'    => $category,
				'product_id'  => $productId,
				'previous'    => $previous,
				'product_id2' => $productId,
				'category2'   => $category
			]);
			
			$this->connection->execute('DELETE FROM `vogoo_links` WHERE `cnt` = 0');
		}
		
		/**
		 * Adjust slope one diff_slope values when an existing rating changes —
		 * applies the delta without touching cnt.
		 *
		 * @param int $memberId The member whose rating changed
		 * @param int $productId The product being rated
		 * @param int $category The category
		 * @param float $rating The new rating value
		 * @param float $previous The previous rating value
		 * @return void
		 */
		private function adjustSlope(int $memberId, int $productId, int $category, float $rating, float $previous): void {
			$diff = $rating - $previous;
			
			$this->connection->execute('
				UPDATE `vogoo_links` vl
				INNER JOIN `vogoo_ratings` vr ON vr.`member_id` = :member_id AND
				                                 vr.`category` = :category AND
				                                 vr.`rating` >= 0.0 AND
				                                 vr.`product_id` <> :product_id
				SET vl.`diff_slope` = vl.`diff_slope` - :diff
				WHERE vl.`item_id2` = vr.`product_id` AND
				      vl.`item_id1` = :product_id2 AND
				      vl.`category` = :category2
			', [
				'member_id'   => $memberId,
				'category'    => $category,
				'product_id'  => $productId,
				'diff'        => $diff,
				'product_id2' => $productId,
				'category2'   => $category
			]);
			
			$this->connection->execute('
				UPDATE `vogoo_links` vl
				INNER JOIN `vogoo_ratings` vr ON vr.`member_id` = :member_id AND
				                                 vr.`category` = :category AND
				                                 vr.`rating` >= 0.0 AND
				                                 vr.`product_id` <> :product_id
				SET vl.`diff_slope` = vl.`diff_slope` + :diff
				WHERE vl.`item_id1` = vr.`product_id` AND
				      vl.`item_id2` = :product_id2 AND
				      vl.`category` = :category2
			', [
				'member_id'   => $memberId,
				'category'    => $category,
				'product_id'  => $productId,
				'diff'        => $diff,
				'product_id2' => $productId,
				'category2'   => $category
			]);
		}
	}