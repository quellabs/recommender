<?php
	
	namespace Quellabs\Recommender;
	
	use Quellabs\Recommender\Config\RecommendationConfig;
	
	/**
	 * Holds the in-memory rating state for an anonymous visitor (no member_id).
	 * Replaces the $vogoo_session global from the original Vogoo codebase.
	 *
	 * The caller is responsible for persisting and restoring this object across
	 * requests (e.g. via session serialization). The recommender classes receive
	 * it as a method argument rather than reading a global.
	 */
	class VisitorContext {
		
		/** @var array<int, array{product_id: int, rating: float, category: int}> */
		private array $ratings = [];
		private readonly RecommendationConfig $config;
		
		/**
		 * VisitorContext constructor
		 * @param RecommendationConfig $config The recommendation configuration
		 */
		public function __construct(RecommendationConfig $config) {
			$this->config = $config;
		}
		
		/**
		 * Record or update a rating for a product in the given category.
		 * @param int $productId The product ID
		 * @param float $rating Use RecommendationConfig::getNotInterested() for "not interested"
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 */
		public function setRating(int $productId, float $rating, ?int $category = null): void {
			$cat = $this->config->resolveCategory($category);
			
			foreach ($this->ratings as &$entry) {
				if ($entry['product_id'] === $productId && $entry['category'] === $cat) {
					$entry['rating'] = $rating;
					return;
				}
			}
			
			$this->ratings[] = [
				'product_id' => $productId,
				'rating'     => $rating,
				'category'   => $cat
			];
		}
		
		/**
		 * Mark a product as "not interested" for the given category.
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 */
		public function setNotInterested(int $productId, ?int $category = null): void {
			$this->setRating($productId, $this->config->getNotInterested(), $category);
		}
		
		/**
		 * Remove a rating for a product in the given category.
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to the configured default category
		 * @return void
		 */
		public function removeRating(int $productId, ?int $category = null): void {
			$cat = $this->config->resolveCategory($category);
			
			$this->ratings = array_values(
				array_filter(
					$this->ratings,
					fn($e) => !($e['product_id'] === $productId && $e['category'] === $cat)
				)
			);
		}
		
		/**
		 * Return all ratings for the given category.
		 * @param int|null $category Defaults to the configured default category
		 * @return array<int, array{product_id: int, rating: float, category: int}>
		 */
		public function getRatings(?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			return array_values(
				array_filter($this->ratings, fn($e) => $e['category'] === $cat)
			);
		}
		
		/**
		 * Return all rated product IDs for the given category.
		 * @param int|null $category Defaults to the configured default category
		 * @return array<int, int>
		 */
		public function getRatedProductIds(?int $category = null): array {
			return array_column($this->getRatings($category), 'product_id');
		}
		
		/**
		 * Whether the visitor has no ratings in the given category.
		 * @param int|null $category Defaults to the configured default category
		 * @return bool
		 */
		public function isEmpty(?int $category = null): bool {
			return empty($this->getRatings($category));
		}
	}