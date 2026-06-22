<?php
	
	namespace Quellabs\Recommender\Config;
	
	class RecommendationConfig {
		
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
		
		public function getCategory(): int {
			return $this->category;
		}
		
		public function getThresholdNrCommonRatings(): int {
			return $this->thresholdNrCommonRatings;
		}
		
		public function getThresholdMult(): int {
			return $this->thresholdMult;
		}
		
		public function getThresholdRating(): float {
			return $this->thresholdRating;
		}
		
		public function getCost(): float {
			return $this->cost;
		}
		
		public function getNotInterested(): float {
			return $this->notInterested;
		}
		
		public function isDirectLinks(): bool {
			return $this->directLinks;
		}
		
		public function isDirectSlope(): bool {
			return $this->directSlope;
		}
		
		/**
		 * Resolve an optional category override against the configured default.
		 * All public methods accept ?int $category = null and call this internally.
		 */
		public function resolveCategory(?int $category): int {
			return $category ?? $this->category;
		}
	}