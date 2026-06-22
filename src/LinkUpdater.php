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
	 */
	class LinkUpdater {
		
		public function __construct(
			private readonly Connection           $connection,
			private readonly RecommendationConfig $config,
		) {}
		
		/**
		 * Update the co-occurrence count in vogoo_links when a rating crosses
		 * the like/dislike threshold.
		 *
		 * @param int   $memberId  The member whose rating changed
		 * @param int   $productId The product being rated
		 * @param int   $category  The category
		 * @param float $rating    The new rating (-1.0 = being deleted)
		 * @param float $previous  The previous rating (-1.0 = did not exist)
		 */
		public function updateLinks(int $memberId, int $productId, int $category, float $rating, float $previous): void {
			$threshold = $this->config->getThresholdRating();
			$crossedUp = $rating >= $threshold && $previous < $threshold;
			$crossedDown = $rating < $threshold && $previous >= $threshold;
			
			if (!$crossedUp && !$crossedDown) {
				return;
			}
			
			if ($crossedUp) {
				// Increment co-occurrence counts for all products this member already likes
				$this->connection->execute(
					'UPDATE vogoo_links vl
				 INNER JOIN vogoo_ratings vr ON vr.member_id = :member_id
				     AND vr.category = :category
				     AND vr.rating >= :threshold
				     AND vr.product_id <> :product_id
				 SET vl.cnt = vl.cnt + 1
				 WHERE (
				     (vl.item_id1 = vr.product_id AND vl.item_id2 = :product_id2)
				     OR
				     (vl.item_id2 = vr.product_id AND vl.item_id1 = :product_id3)
				 )
				 AND vl.category = :category2',
					[
						'member_id'  => $memberId,
						'category'   => $category,
						'threshold'  => $threshold,
						'product_id' => $productId,
						'product_id2'=> $productId,
						'product_id3'=> $productId,
						'category2'  => $category,
					],
				);
				
				// Insert link rows that do not yet exist
				$this->connection->execute(
					'INSERT INTO vogoo_links (item_id1, item_id2, category, cnt, diff_slope)
				 SELECT :product_id, vr.product_id, :category, 1, 0.0
				 FROM vogoo_ratings vr
				 WHERE vr.member_id = :member_id
				   AND vr.category = :category2
				   AND vr.rating >= :threshold
				   AND vr.product_id <> :product_id2
				   AND NOT EXISTS (
				       SELECT 1 FROM vogoo_links vl
				       WHERE vl.category = :category3
				         AND vl.item_id1 = :product_id3
				         AND vl.item_id2 = vr.product_id
				   )
				 UNION
				 SELECT vr.product_id, :product_id4, :category4, 1, 0.0
				 FROM vogoo_ratings vr
				 WHERE vr.member_id = :member_id2
				   AND vr.category = :category5
				   AND vr.rating >= :threshold2
				   AND vr.product_id <> :product_id5
				   AND NOT EXISTS (
				       SELECT 1 FROM vogoo_links vl
				       WHERE vl.category = :category6
				         AND vl.item_id2 = :product_id6
				         AND vl.item_id1 = vr.product_id
				   )',
					[
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
						'product_id6' => $productId,
					],
				);
				
				return;
			}
			
			// Crossed down: decrement counts and prune zero-count rows
			$this->connection->execute(
				'UPDATE vogoo_links vl
			 INNER JOIN vogoo_ratings vr ON vr.member_id = :member_id
			     AND vr.category = :category
			     AND vr.rating >= :threshold
			     AND vr.product_id <> :product_id
			 SET vl.cnt = vl.cnt - 1
			 WHERE (
			     (vl.item_id1 = vr.product_id AND vl.item_id2 = :product_id2)
			     OR
			     (vl.item_id2 = vr.product_id AND vl.item_id1 = :product_id3)
			 )
			 AND vl.category = :category2',
				[
					'member_id'  => $memberId,
					'category'   => $category,
					'threshold'  => $threshold,
					'product_id' => $productId,
					'product_id2'=> $productId,
					'product_id3'=> $productId,
					'category2'  => $category,
				],
			);
			
			$this->connection->execute('DELETE FROM vogoo_links WHERE cnt = 0');
		}
		
		/**
		 * Update the slope one diff_slope and cnt columns in vogoo_links when a
		 * rating is added, changed, or removed.
		 *
		 * @param int   $memberId  The member whose rating changed
		 * @param int   $productId The product being rated
		 * @param int   $category  The category
		 * @param float $rating    The new rating (-1.0 = being deleted)
		 * @param float $previous  The previous rating (-1.0 = did not exist)
		 */
		public function updateSlope(int $memberId, int $productId, int $category, float $rating, float $previous): void {
			// Both absent: nothing to do
			if ($rating < 0.0 && $previous < 0.0) {
				return;
			}
			
			if ($previous < 0.0) {
				// New rating: add slope entries
				$this->addSlope($memberId, $productId, $category, $rating);
				return;
			}
			
			if ($rating < 0.0) {
				// Deleted rating: remove slope entries
				$this->removeSlope($memberId, $productId, $category, $previous);
				return;
			}
			
			// Changed rating: adjust diff_slope by the delta
			$this->adjustSlope($memberId, $productId, $category, $rating, $previous);
		}
		
		// -------------------------------------------------------------------------
		
		private function addSlope(int $memberId, int $productId, int $category, float $rating): void {
			// Update existing rows where product_id is item_id1
			$this->connection->execute(
				'UPDATE vogoo_links vl
			 INNER JOIN vogoo_ratings vr ON vr.member_id = :member_id
			     AND vr.category = :category
			     AND vr.rating >= 0.0
			     AND vr.product_id <> :product_id
			 SET vl.cnt = vl.cnt + 1,
			     vl.diff_slope = vl.diff_slope + vr.rating - :rating
			 WHERE vl.item_id2 = vr.product_id
			   AND vl.item_id1 = :product_id2
			   AND vl.category = :category2',
				[
					'member_id'  => $memberId,
					'category'   => $category,
					'product_id' => $productId,
					'rating'     => $rating,
					'product_id2'=> $productId,
					'category2'  => $category,
				],
			);
			
			// Update existing rows where product_id is item_id2
			$this->connection->execute(
				'UPDATE vogoo_links vl
			 INNER JOIN vogoo_ratings vr ON vr.member_id = :member_id
			     AND vr.category = :category
			     AND vr.rating >= 0.0
			     AND vr.product_id <> :product_id
			 SET vl.cnt = vl.cnt + 1,
			     vl.diff_slope = vl.diff_slope - vr.rating + :rating
			 WHERE vl.item_id1 = vr.product_id
			   AND vl.item_id2 = :product_id2
			   AND vl.category = :category2',
				[
					'member_id'  => $memberId,
					'category'   => $category,
					'product_id' => $productId,
					'rating'     => $rating,
					'product_id2'=> $productId,
					'category2'  => $category,
				],
			);
			
			// Insert missing link rows
			$this->connection->execute(
				'INSERT INTO vogoo_links (item_id1, item_id2, category, cnt, diff_slope)
			 SELECT :product_id, vr.product_id, :category, 1, vr.rating - :rating
			 FROM vogoo_ratings vr
			 WHERE vr.member_id = :member_id
			   AND vr.category = :category2
			   AND vr.rating >= 0.0
			   AND vr.product_id <> :product_id2
			   AND NOT EXISTS (
			       SELECT 1 FROM vogoo_links vl
			       WHERE vl.category = :category3
			         AND vl.item_id1 = :product_id3
			         AND vl.item_id2 = vr.product_id
			   )
			 UNION
			 SELECT vr.product_id, :product_id4, :category4, 1, :rating2 - vr.rating
			 FROM vogoo_ratings vr
			 WHERE vr.member_id = :member_id2
			   AND vr.category = :category5
			   AND vr.rating >= 0.0
			   AND vr.product_id <> :product_id5
			   AND NOT EXISTS (
			       SELECT 1 FROM vogoo_links vl
			       WHERE vl.category = :category6
			         AND vl.item_id2 = :product_id6
			         AND vl.item_id1 = vr.product_id
			   )',
				[
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
					'product_id6' => $productId,
				],
			);
		}
		
		private function removeSlope(int $memberId, int $productId, int $category, float $previous): void {
			$this->connection->execute(
				'UPDATE vogoo_links vl
			 INNER JOIN vogoo_ratings vr ON vr.member_id = :member_id
			     AND vr.category = :category
			     AND vr.rating >= 0.0
			     AND vr.product_id <> :product_id
			 SET vl.cnt = vl.cnt - 1,
			     vl.diff_slope = vl.diff_slope - vr.rating + :previous
			 WHERE vl.item_id2 = vr.product_id
			   AND vl.item_id1 = :product_id2
			   AND vl.category = :category2',
				[
					'member_id'  => $memberId,
					'category'   => $category,
					'product_id' => $productId,
					'previous'   => $previous,
					'product_id2'=> $productId,
					'category2'  => $category,
				],
			);
			
			$this->connection->execute(
				'UPDATE vogoo_links vl
			 INNER JOIN vogoo_ratings vr ON vr.member_id = :member_id
			     AND vr.category = :category
			     AND vr.rating >= 0.0
			     AND vr.product_id <> :product_id
			 SET vl.cnt = vl.cnt - 1,
			     vl.diff_slope = vl.diff_slope + vr.rating - :previous
			 WHERE vl.item_id1 = vr.product_id
			   AND vl.item_id2 = :product_id2
			   AND vl.category = :category2',
				[
					'member_id'  => $memberId,
					'category'   => $category,
					'product_id' => $productId,
					'previous'   => $previous,
					'product_id2'=> $productId,
					'category2'  => $category,
				],
			);
			
			$this->connection->execute('DELETE FROM vogoo_links WHERE cnt = 0');
		}
		
		private function adjustSlope(int $memberId, int $productId, int $category, float $rating, float $previous): void {
			$diff = $rating - $previous;
			
			$this->connection->execute(
				'UPDATE vogoo_links vl
			 INNER JOIN vogoo_ratings vr ON vr.member_id = :member_id
			     AND vr.category = :category
			     AND vr.rating >= 0.0
			     AND vr.product_id <> :product_id
			 SET vl.diff_slope = vl.diff_slope - :diff
			 WHERE vl.item_id2 = vr.product_id
			   AND vl.item_id1 = :product_id2
			   AND vl.category = :category2',
				[
					'member_id'  => $memberId,
					'category'   => $category,
					'product_id' => $productId,
					'diff'       => $diff,
					'product_id2'=> $productId,
					'category2'  => $category,
				],
			);
			
			$this->connection->execute(
				'UPDATE vogoo_links vl
			 INNER JOIN vogoo_ratings vr ON vr.member_id = :member_id
			     AND vr.category = :category
			     AND vr.rating >= 0.0
			     AND vr.product_id <> :product_id
			 SET vl.diff_slope = vl.diff_slope + :diff
			 WHERE vl.item_id1 = vr.product_id
			   AND vl.item_id2 = :product_id2
			   AND vl.category = :category2',
				[
					'member_id'  => $memberId,
					'category'   => $category,
					'product_id' => $productId,
					'diff'       => $diff,
					'product_id2'=> $productId,
					'category2'  => $category,
				],
			);
		}
	}