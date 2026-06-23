<?php
	
	namespace Quellabs\Recommender\Config;
	
	class RecommendationConfig {
		
		/**
		 * Build an immutable recommendation configuration.
		 * @param int $category Default category for all operations
		 * @param int $thresholdNrCommonRatings Minimum number of common ratings required before similarity is considered reliable
		 * @param int $thresholdMult Multiplier used in the similarity confidence calculation
		 * @param float $thresholdRating Minimum rating value for an item to count as "liked" in link/slope calculations
		 * @param float $cost Cost factor used in the similarity spread calculation
		 * @param float $notInterested Sentinel value stored in vogoo_ratings to mark "not interested"
		 * @param bool $directLinks Whether to maintain the item co-occurrence link table incrementally on every rating change
		 * @param bool $directSlope Whether to maintain the slope one diff table incrementally on every rating change
		 */
		public function __construct(
			// Default category for all operations
			private readonly int   $category = 1,
			
			// Minimum number of common ratings required before similarity is considered reliable
			private readonly int   $thresholdNrCommonRatings = 30,
			
			// Multiplier used in the similarity confidence calculation
			private readonly int   $thresholdMult = 2,
			
			// Minimum rating value for an item to count as "liked" in link/slope calculations
			private readonly float $thresholdRating = 0.66,
			
			// Cost factor used in the similarity spread calculation
			private readonly float $cost = 5.0,
			
			// Sentinel value stored in vogoo_ratings to mark "not interested"
			private readonly float $notInterested = -1.0,
			
			// Whether to maintain the item co-occurrence link table incrementally on every rating change
			private readonly bool  $directLinks = false,
			
			// Whether to maintain the slope one diff table incrementally on every rating change
			private readonly bool  $directSlope = true,
		) {}
		
		/**
		 * @return int The configured default category
		 */
		public function getCategory(): int {
			return $this->category;
		}
		
		/**
		 * @return int Minimum common ratings before a similarity is considered reliable
		 */
		public function getThresholdNrCommonRatings(): int {
			return $this->thresholdNrCommonRatings;
		}
		
		/**
		 * @return int Multiplier used in the similarity confidence calculation
		 */
		public function getThresholdMult(): int {
			return $this->thresholdMult;
		}
		
		/**
		 * @return float Minimum rating for an item to count as "liked"
		 */
		public function getThresholdRating(): float {
			return $this->thresholdRating;
		}
		
		/**
		 * @return float Cost factor used in the similarity spread calculation
		 */
		public function getCost(): float {
			return $this->cost;
		}
		
		/**
		 * @return float Sentinel rating value marking "not interested"
		 */
		public function getNotInterested(): float {
			return $this->notInterested;
		}
		
		/**
		 * @return bool Whether the link table is maintained incrementally
		 */
		public function isDirectLinks(): bool {
			return $this->directLinks;
		}
		
		/**
		 * @return bool Whether the slope one table is maintained incrementally
		 */
		public function isDirectSlope(): bool {
			return $this->directSlope;
		}
		
		/**
		 * Resolve an optional category override against the configured default.
		 * All public methods accept ?int $category = null and call this internally.
		 * @param int|null $category Category override, or null to use the configured default
		 * @return int The resolved category
		 */
		public function resolveCategory(?int $category): int {
			return $category ?? $this->category;
		}
	}