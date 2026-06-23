<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Cake\Database\Connection;
	
	/**
	 * Creates the vogoo_ratings and vogoo_links tables required by the
	 * recommender engine.
	 *
	 * Usage:
	 *   sculpt recommender:init-db
	 *   sculpt recommender:init-db --force   (drop and recreate existing tables)
	 */
	class InitCommand extends CommandBase {
		
		public function getSignature(): string {
			return 'recommender:init-db';
		}
		
		public function getDescription(): string {
			return 'Create the vogoo_ratings and vogoo_links database tables';
		}
		
		public function getHelp(): string {
			return <<<HELP
<bold>Usage:</bold>
  sculpt recommender:init-db [--force]

<bold>Options:</bold>
  --force   Drop existing tables before creating them. All data will be lost.

<bold>Tables created:</bold>
  vogoo_ratings   Stores member/product ratings (float 0.0-1.0, -1.0 = not interested)
  vogoo_links     Stores item co-occurrence counts and slope one diff values

<bold>Notes:</bold>
  vogoo_links requires a unique key on (item_id1, item_id2, category) for the
  rebuild command's upsert to work correctly.
HELP;
		}
		
		public function execute(ConfigurationManager $config): int {
			/** @var RecommenderProvider $provider */
			$provider   = $this->provider;
			$connection = $provider->getConnection();
			$force      = $config->hasFlag('force');
			
			$tables = ['vogoo_ratings', 'vogoo_links'];
			
			// Check which tables already exist
			$existing = [];
			
			foreach ($tables as $table) {
				$rows = $connection->execute(
					'SELECT COUNT(*) AS cnt
				 FROM information_schema.tables
				 WHERE table_schema = DATABASE()
				   AND table_name = :table',
					['table' => $table],
				)->fetchAssoc();
				
				if ((int)$rows['cnt'] > 0) {
					$existing[] = $table;
				}
			}
			
			// Bail out if tables exist and --force was not given
			if (!empty($existing) && !$force) {
				foreach ($existing as $table) {
					$this->output->warning("Table '{$table}' already exists. Use --force to drop and recreate.");
				}
				
				return 1;
			}
			
			// Drop existing tables when --force is set (reverse order to avoid FK issues)
			if ($force && !empty($existing)) {
				foreach (array_reverse($tables) as $table) {
					$connection->execute("DROP TABLE IF EXISTS `{$table}`");
					$this->output->writeLn("<dim>Dropped table '{$table}'.</dim>");
				}
			}
			
			$this->createRatingsTable($connection);
			$this->createLinksTable($connection);
			
			return 0;
		}
		
		/**
		 * Create the vogoo_ratings table.
		 * @param Connection $connection The CakePHP database connection
		 * @return void
		 */
		private function createRatingsTable(Connection $connection): void {
			// Create vogoo_ratings
			$connection->execute(
				'CREATE TABLE `vogoo_ratings` (
			    `member_id`  INT UNSIGNED  NOT NULL,
			    `product_id` INT UNSIGNED  NOT NULL,
			    `category`   INT UNSIGNED  NOT NULL DEFAULT 1,
			    `rating`     FLOAT         NOT NULL,
			    `ts`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			    PRIMARY KEY (`member_id`, `product_id`, `category`),
			    INDEX `idx_product`  (`product_id`, `category`),
			    INDEX `idx_member`   (`member_id`,  `category`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
			);
			
			$this->output->success("Created table 'vogoo_ratings'.");
		}
		
		/**
		 * Create the vogoo_links table.
		 * @param Connection $connection The CakePHP database connection
		 * @return void
		 */
		private function createLinksTable(Connection $connection): void {
			// Create vogoo_links
			$connection->execute(
				'CREATE TABLE `vogoo_links` (
			    `item_id1`   INT UNSIGNED  NOT NULL,
			    `item_id2`   INT UNSIGNED  NOT NULL,
			    `category`   INT UNSIGNED  NOT NULL DEFAULT 1,
			    `cnt`        INT           NOT NULL DEFAULT 0,
			    `diff_slope` FLOAT         NOT NULL DEFAULT 0.0,
			    PRIMARY KEY (`item_id1`, `item_id2`, `category`),
			    INDEX `idx_item2` (`item_id2`, `category`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
			);
			
			$this->output->success("Created table 'vogoo_links'.");
		}
	}