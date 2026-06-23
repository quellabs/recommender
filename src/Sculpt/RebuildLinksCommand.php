<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Rebuilds the vogoo_links table from scratch by iterating over all members
	 * in the ratings table and recomputing co-occurrence counts and slope one
	 * diff values.
	 *
	 * Usage:
	 *   sculpt recommender:rebuild-links
	 *   sculpt recommender:rebuild-links --category=2
	 *
	 * When no --category is given, rebuilds all categories found in vogoo_ratings.
	 */
	class RebuildLinksCommand extends CommandBase {
		
		/**
		 * Return the command's invocable signature.
		 * @return string The command signature (its invocable name)
		 */
		public function getSignature(): string {
			return 'recommender:rebuild-links';
		}
		
		/**
		 * Return the one-line command description shown in command listings.
		 * @return string One-line description of the command
		 */
		public function getDescription(): string {
			return 'Rebuild the vogoo_links co-occurrence and slope one table from ratings data';
		}
		
		/**
		 * Return the detailed help text shown for this command.
		 * @return string Detailed help text
		 */
		public function getHelp(): string {
			return <<<HELP
<bold>Usage:</bold>
  sculpt recommender:rebuild-links [--category=<id>]

<bold>Options:</bold>
  --category=<id>   Only rebuild for the given category. Rebuilds all categories if omitted.

<bold>Description:</bold>
  Clears vogoo_links for the target category (or all categories) and
  recomputes it from vogoo_ratings in a single pass per member.

  Run this after bulk-importing ratings, or to recover from an inconsistent
  link table. For ongoing consistency, enable direct_links or direct_slope in
  your recommender config instead.

  Requires a unique key on vogoo_links (item_id1, item_id2, category).
HELP;
		}
		
		/**
		 * Rebuild vogoo_links for one or all categories from ratings data.
		 * @param ConfigurationManager $config The Sculpt configuration manager (flags and arguments)
		 * @return int Exit code: 0 on success
		 */
		public function execute(ConfigurationManager $config): int {
			/** @var RecommenderProvider $provider */
			$provider = $this->provider;
			$connection = $provider->getConnection();
			$recommenderConfig = $provider->getRecommendationConfig();
			
			$categoryArg = $config->getAsIntOrNull('category');
			
			// Resolve which categories to rebuild
			if ($categoryArg !== null) {
				$categories = [$categoryArg];
			} else {
				$rows = $connection->execute('
					SELECT DISTINCT
				        `category`
					FROM `vogoo_ratings`
					ORDER BY `category`
				')->fetchAll('assoc');
				
				$categories = array_map('intval', array_column($rows, 'category'));
				
				if (empty($categories)) {
					$this->output->warning('No ratings found — nothing to rebuild.');
					return 0;
				}
			}
			
			foreach ($categories as $category) {
				$this->rebuildCategory($connection, $recommenderConfig, $category);
			}
			
			return 0;
		}
		
		// -------------------------------------------------------------------------
		
		/**
		 * Rebuild vogoo_links for a single category.
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 * @param int $category Already-resolved category
		 * @return void
		 */
		private function rebuildCategory(Connection $connection, RecommendationConfig $config, int $category): void {
			$threshold = $config->getThresholdRating();
			
			$this->output->writeLn("Rebuilding category <bold>{$category}</bold>...");
			
			// Truncate existing links for this category
			$connection->execute('
				DELETE
				FROM `vogoo_links`
				WHERE `category` = :category
			', [
				'category' => $category
			]);
			
			// Fetch all members who have rated at least one product in this category
			$members = $connection->execute('
				SELECT DISTINCT
			        `member_id`
				FROM `vogoo_ratings`
				WHERE `category` = :category
			', [
				'category' => $category
			])->fetchAll('assoc');
			
			$total = count($members);
			$processed = 0;
			
			foreach ($members as $memberRow) {
				$this->rebuildMemberPairs($connection, $config, (int)$memberRow['member_id'], $category, $threshold);
				
				$processed++;
				
				// Progress every 100 members
				if ($processed % 100 === 0 || $processed === $total) {
					$this->output->writeLn("  {$processed}/{$total} members processed");
				}
			}
			
			$linkCount = $connection->execute('
				SELECT
					COUNT(*) AS cnt
				FROM `vogoo_links`
				WHERE `category` = :category
			', [
				'category' => $category
			])->fetchAssoc()['cnt'];
			
			$this->output->success("Category {$category}: rebuilt {$linkCount} link pairs from {$total} members.");
		}
		
		/**
		 * Recompute and upsert all (item1, item2) link pairs for a single member's
		 * genuine ratings in the given category.
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 * @param int $memberId The member ID
		 * @param int $category Already-resolved category
		 * @param float $threshold Minimum rating to count as "liked" for co-occurrence
		 * @return void
		 */
		private function rebuildMemberPairs(Connection $connection, RecommendationConfig $config, int $memberId, int $category, float $threshold): void {
			// Fetch all genuine ratings for this member in this category
			$ratings = $connection->execute('
				SELECT
					`product_id`,
					`rating`
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `category` = :category AND
				      `rating` >= 0.0
			', [
				'member_id' => $memberId,
				'category'  => $category
			])->fetchAll('assoc');
			
			$count = count($ratings);
			
			// Build all (item1, item2) pairs rated by this member
			for ($i = 0; $i < $count; $i++) {
				for ($j = $i + 1; $j < $count; $j++) {
					$a = $ratings[$i];
					$b = $ratings[$j];
					$ratingA = (float)$a['rating'];
					$ratingB = (float)$b['rating'];
					
					// Co-occurrence: only count pairs where both items are liked
					if ($config->isDirectLinks()) {
						if ($ratingA >= $threshold && $ratingB >= $threshold) {
							$this->upsertLink($connection, (int)$a['product_id'], (int)$b['product_id'], $category, 0.0);
							$this->upsertLink($connection, (int)$b['product_id'], (int)$a['product_id'], $category, 0.0);
						}
					}
					
					// Slope one: count all pairs with genuine ratings
					if ($config->isDirectSlope()) {
						// item_id1=A, item_id2=B: diff = ratingB - ratingA
						$this->upsertLink($connection, (int)$a['product_id'], (int)$b['product_id'], $category, $ratingB - $ratingA);
						
						// item_id1=B, item_id2=A: diff = ratingA - ratingB
						$this->upsertLink($connection, (int)$b['product_id'], (int)$a['product_id'], $category, $ratingA - $ratingB);
					}
				}
			}
		}
		
		/**
		 * Insert a new link row or increment the cnt/diff_slope of an existing one.
		 * Requires a unique key on (item_id1, item_id2, category).
		 * @param Connection $connection The CakePHP database connection
		 * @param int $itemId1 The first item ID (item_id1)
		 * @param int $itemId2 The second item ID (item_id2)
		 * @param int $category Already-resolved category
		 * @param float $diffDelta Amount to add to diff_slope (0.0 for plain co-occurrence links)
		 * @return void
		 */
		private function upsertLink(Connection $connection, int $itemId1, int $itemId2, int $category, float $diffDelta): void {
			$connection->execute('
				INSERT INTO `vogoo_links` (`item_id1`, `item_id2`, `category`, `cnt`, `diff_slope`)
			 	VALUES (:item_id1, :item_id2, :category, :cnt, :diff_slope)
			 	ON DUPLICATE KEY UPDATE
					`cnt` = `cnt` + VALUES(`cnt`),
					`diff_slope` = `diff_slope` + VALUES(`diff_slope`)
			', [
				'item_id1'   => $itemId1,
				'item_id2'   => $itemId2,
				'category'   => $category,
				'cnt'        => 1,
				'diff_slope' => $diffDelta,
			]);
		}
	}