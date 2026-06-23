<?php
	
	namespace Quellabs\Recommender;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	
	/**
	 * User-based collaborative filtering: member similarity scoring and
	 * neighbour-based recommendations.
	 *
	 * The similarity algorithm is the original Vogoo weighted mean-squared-error
	 * approach, which penalises pairs with few common ratings relative to the
	 * member's total ratings.
	 *
	 * All methods throw on database failure. Wrap calls in try/catch if you need
	 * to handle errors.
	 */
	readonly class UserSimilarity {
		
		private Connection $connection;
		private RecommendationConfig $config;
		private RecommendationEngine $engine;
		
		/**
		 * UserSimilarity constructor
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 * @param RecommendationEngine $engine The recommendation engine used for rating lookups
		 */
		public function __construct(Connection $connection, RecommendationConfig $config, RecommendationEngine $engine) {
			$this->engine = $engine;
			$this->config = $config;
			$this->connection = $connection;
		}
		
		/**
		 * Return a similarity score in [0, 100] between two members.
		 * 0 means no overlap or completely different taste; 100 means identical.
		 *
		 * @param int $memberId1 The first member ID
		 * @param int $memberId2 The second member, compared against the first
		 * @param int|null $category Defaults to configured default
		 */
		public function memberSimilarity(int $memberId1, int $memberId2, ?int $category = null): int {
			$cat = $this->config->resolveCategory($category);
			
			$nrRatings1 = $this->engine->memberNumRatings($memberId1, true, false, $cat);
			
			if ($nrRatings1 === 0) {
				return 0;
			}
			
			$row = $this->connection->execute('
				SELECT
					COUNT(r2.`product_id`) AS c2,
					SUM((r2.`rating` - r1.`rating`) * (r2.`rating` - r1.`rating`)) AS s
				FROM `vogoo_ratings` r1
				INNER JOIN `vogoo_ratings` r2 ON r2.`member_id` = :member_id2 AND
				                                 r2.`product_id` = r1.`product_id` AND
				                                 r2.`category` = r1.`category` AND
				                                 r2.`rating` >= 0.0
				WHERE r1.`member_id` = :member_id1 AND
				      r1.`category` = :category AND
				      r1.`rating` >= 0.0
			', [
				'member_id1' => $memberId1,
				'member_id2' => $memberId2,
				'category'   => $cat
			])->fetchAssoc();
			
			$nrCommonRatings = (int)$row['c2'];
			
			if ($nrCommonRatings === 0) {
				return 0;
			}
			
			return $this->scoreSimilarity($nrCommonRatings, (float)$row['s'], $nrRatings1);
		}
		
		/**
		 * Return neighbours of a member as [['member_id' => int, 'similarity' => int], ...]
		 * sorted by similarity descending, filtered by a minimum similarity threshold.
		 *
		 * This performs a similarity calculation for every candidate neighbour —
		 * for large member sets you should pre-compute and cache neighbour lists.
		 *
		 * @param int $memberId The member ID
		 * @param int $minSimilarity Minimum score to include (0–100)
		 * @param int $limit Maximum number of neighbours (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{member_id: int, similarity: int}>
		 */
		public function getNeighbours(int $memberId, int $minSimilarity = 1, int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$minSimilarity = max(0, min(100, $minSimilarity));
			$limit = max(0, $limit);
			
			// Fetch all members who have rated at least one product in common
			$rows = $this->connection->execute('
				SELECT DISTINCT r2.`member_id`
				FROM `vogoo_ratings` r1
				INNER JOIN `vogoo_ratings` r2 ON r2.`product_id` = r1.`product_id` AND
				                                 r2.`category` = r1.`category` AND
				                                 r2.`member_id` <> :member_id
				WHERE r1.`member_id` = :member_id2 AND
				      r1.`category` = :category AND
				      r1.`rating` >= 0.0 AND
				      r2.`rating` >= 0.0
			', [
				'member_id'  => $memberId,
				'member_id2' => $memberId,
				'category'   => $cat
			])->fetchAll('assoc');
			
			$neighbours = [];
			
			foreach ($rows as $row) {
				$otherId = (int)$row['member_id'];
				$similarity = $this->memberSimilarity($memberId, $otherId, $cat);
				
				if ($similarity < $minSimilarity) {
					continue;
				}
				
				$neighbours[] = ['member_id' => $otherId, 'similarity' => $similarity];
			}
			
			usort($neighbours, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
			return $limit > 0 ? array_slice($neighbours, 0, $limit) : $neighbours;
		}
		
		/**
		 * Return recommended items for a member based on what similar members
		 * have liked, weighted by similarity score.
		 * Only returns items the member has not already rated.
		 * @param int $memberId The member ID
		 * @param int $minSimilarity Minimum neighbour similarity to consider
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int> List of product IDs ordered by score
		 */
		public function memberGetRecommendedItems(int $memberId, int $minSimilarity = 1, array $filter = [], int $limit = 0, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$minSimilarity = max(0, min(100, $minSimilarity));
			$limit = max(0, $limit);
			
			$neighbours = $this->getNeighbours($memberId, $minSimilarity, 0, $cat);
			
			if (empty($neighbours)) {
				return [];
			}
			
			$scores = $this->computeNeighbourScores($memberId, $neighbours, $filter, $cat);
			
			if (empty($scores)) {
				return [];
			}
			
			arsort($scores);
			
			$result = array_keys($scores);
			return $limit > 0 ? array_slice($result, 0, $limit) : $result;
		}

		/**
		 * Convert the raw sum of squared rating differences into a 0–100 similarity
		 * score, applying the Vogoo confidence penalty when the number of common
		 * ratings is small relative to the member's total ratings.
		 * @param int $nrCommonRatings Number of products both members rated
		 * @param float $sumSquaredDiff Sum of squared rating differences over those products
		 * @param int $nrRatings1 Total genuine ratings of the first member
		 * @return int Similarity score in [0, 100]
		 */
		private function scoreSimilarity(int $nrCommonRatings, float $sumSquaredDiff, int $nrRatings1): int {
			$cost = $this->config->getCost();
			$spread = $sumSquaredDiff * $cost * $cost * 20.0;
			
			$tempFactor = $spread / $nrCommonRatings;
			
			if ($tempFactor > 100) {
				return 0;
			}
			
			$thresholdNr = $this->config->getThresholdNrCommonRatings();
			$thresholdMult = $this->config->getThresholdMult();
			
			// Enough common ratings: return direct score
			if ($nrCommonRatings > $thresholdNr || ($nrCommonRatings * $thresholdMult) >= $nrRatings1) {
				return 100 - (int)$tempFactor;
			}
			
			// Fewer common ratings: apply a confidence penalty
			if ($nrRatings1 < ($thresholdNr * $thresholdMult)) {
				$tempFactor2 = ($nrCommonRatings * $thresholdMult) / $nrRatings1;
			} else {
				$tempFactor2 = ($nrCommonRatings * $thresholdMult) / ($thresholdNr * $thresholdMult);
			}
			
			$tempFactor2 *= $tempFactor2;
			
			return (int)((100.0 - $tempFactor) * (0.1 + 0.9 * $tempFactor2));
		}
		
		/**
		 * Compute similarity-weighted recommendation scores for every product liked by
		 * the member's neighbours that the member has not rated. Each candidate's score
		 * is the similarity-weighted average of the neighbours' ratings for it. Runs one
		 * query per neighbour.
		 * @param int $memberId The member receiving recommendations
		 * @param array<int, array{member_id: int, similarity: int}> $neighbours
		 * @param array<int> $filter When non-empty, only score product IDs in this set
		 * @param int $category Already-resolved category
		 * @return array<int, float> Map of candidate product_id => weighted score
		 */
		private function computeNeighbourScores(int $memberId, array $neighbours, array $filter, int $category): array {
			$threshold = $this->config->getThresholdRating();
			$scores = [];
			$weights = [];
			
			foreach ($neighbours as ['member_id' => $neighbourId, 'similarity' => $similarity]) {
				$rows = $this->connection->execute('
					SELECT
						`product_id`,
						`rating`
					FROM `vogoo_ratings`
					WHERE `member_id` = :member_id AND
					      `category` = :category AND
					      `rating` >= :threshold AND
					      NOT EXISTS (
					      	SELECT 1 FROM `vogoo_ratings` vr
					      	WHERE vr.`member_id` = :target_member_id AND
					      	      vr.`category` = :category2 AND
					      	      vr.`product_id` = `vogoo_ratings`.`product_id`
					      )
				', [
					'member_id'        => $neighbourId,
					'category'         => $category,
					'threshold'        => $threshold,
					'target_member_id' => $memberId,
					'category2'        => $category
				])->fetchAll('assoc');
				
				foreach ($rows as $row) {
					$id = (int)$row['product_id'];
					
					if (!empty($filter) && !in_array($id, $filter, true)) {
						continue;
					}
					
					$scores[$id] = ($scores[$id] ?? 0.0) + $similarity * (float)$row['rating'];
					$weights[$id] = ($weights[$id] ?? 0) + $similarity;
				}
			}
			
			// Normalise by total weight
			foreach ($scores as $id => $score) {
				$scores[$id] = $score / $weights[$id];
			}
			
			return $scores;
		}
	}