<?php
	
	namespace Quellabs\Recommender;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	
	/**
	 * Catalogue-level statistics about the ratings store.
	 *
	 * All methods throw on database failure. Wrap calls in try/catch if you need
	 * to handle errors.
	 */
	class Statistics {
		
		public function __construct(
			private readonly Connection $connection,
			private readonly RecommendationConfig $config,
		) {
		}
		
		/**
		 * Return the number of distinct members who have given at least one rating.
		 *
		 * @param int|null $category Defaults to configured default
		 */
		public function numMembers(?int $category = null): int {
			$cat = $this->config->resolveCategory($category);
			$row = $this->connection->execute(
				'SELECT COUNT(DISTINCT member_id) AS cnter FROM vogoo_ratings WHERE category = :category',
				['category' => $cat],
			)->fetchAssoc();
			return (int)$row['cnter'];
		}
		
		/**
		 * Return all distinct member IDs that have given at least one rating.
		 *
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int>
		 */
		public function members(?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			$rows = $this->connection->execute(
				'SELECT DISTINCT member_id FROM vogoo_ratings WHERE category = :category',
				['category' => $cat],
			)->fetchAll('assoc');
			return array_map('intval', array_column($rows, 'member_id'));
		}
		
		/**
		 * Return the number of distinct products that have received at least one rating.
		 *
		 * @param int|null $category Defaults to configured default
		 */
		public function numProducts(?int $category = null): int {
			$cat = $this->config->resolveCategory($category);
			$row = $this->connection->execute(
				'SELECT COUNT(DISTINCT product_id) AS cnter FROM vogoo_ratings WHERE category = :category AND rating >= 0.0',
				['category' => $cat],
			)->fetchAssoc();
			return (int)$row['cnter'];
		}
		
		/**
		 * Return the total number of genuine ratings stored.
		 *
		 * @param int|null $category Defaults to configured default
		 */
		public function numRatings(?int $category = null): int {
			$cat = $this->config->resolveCategory($category);
			$row = $this->connection->execute(
				'SELECT COUNT(*) AS cnter FROM vogoo_ratings WHERE category = :category AND rating >= 0.0',
				['category' => $cat],
			)->fetchAssoc();
			return (int)$row['cnter'];
		}
		
		/**
		 * Return the most-rated products as [['product_id' => int, 'num_ratings' => int], ...]
		 * ordered by rating count descending.
		 *
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{product_id: int, num_ratings: int}>
		 */
		public function mostRatedProducts(int $limit = 10, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$sql = 'SELECT product_id, COUNT(*) AS num_ratings
		        FROM vogoo_ratings
		        WHERE category = :category
		          AND rating >= 0.0
		        GROUP BY product_id
		        ORDER BY num_ratings DESC';
			
			$params = ['category' => $cat];
			
			if ($limit > 0) {
				$sql .= ' LIMIT ' . $limit;
			}
			
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			
			return array_map(
				fn($row) => ['product_id' => (int)$row['product_id'], 'num_ratings' => (int)$row['num_ratings']],
				$rows
			);
		}
		
		/**
		 * Return the highest-rated products as [['product_id' => int, 'avg_rating' => float], ...]
		 * ordered by average rating descending. Products with fewer than $minRatings
		 * ratings are excluded.
		 *
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int $minRatings Minimum number of ratings to qualify
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{product_id: int, avg_rating: float}>
		 */
		public function topRatedProducts(int $limit = 10, int $minRatings = 1, ?int $category = null): array {
			$cat = $this->config->resolveCategory($category);
			
			$sql = 'SELECT product_id, AVG(rating) AS avg_rating
		        FROM vogoo_ratings
		        WHERE category = :category
		          AND rating >= 0.0
		        GROUP BY product_id
		        HAVING COUNT(*) >= :min_ratings
		        ORDER BY avg_rating DESC';
			
			$params = ['category' => $cat, 'min_ratings' => $minRatings];
			
			if ($limit > 0) {
				$sql .= ' LIMIT ' . $limit;
			}
			
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			
			return array_map(
				fn($row) => ['product_id' => (int)$row['product_id'], 'avg_rating' => (float)$row['avg_rating']],
				$rows
			);
		}
		
		/**
		 * Return the number of item pairs in the vogoo_links table.
		 * Useful for monitoring link table growth.
		 *
		 * @param int|null $category Defaults to configured default
		 */
		public function numLinks(?int $category = null): int {
			$cat = $this->config->resolveCategory($category);
			$row = $this->connection->execute(
				'SELECT COUNT(*) AS cnter FROM vogoo_links WHERE category = :category',
				['category' => $cat],
			)->fetchAssoc();
			return (int)$row['cnter'];
		}
	}