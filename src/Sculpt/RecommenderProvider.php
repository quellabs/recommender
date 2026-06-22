<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Sculpt\Application;
	use Quellabs\Sculpt\ServiceProvider;
	
	/**
	 * Registers quellabs/recommender commands with the Sculpt CLI.
	 *
	 * Sculpt discovers this provider automatically via the "discover" section
	 * in composer.json. Config is loaded from the file(s) listed there and
	 * injected by the framework via setConfig() before register() is called.
	 *
	 * Minimal config/recommender.php example:
	 *
	 *   return [
	 *       'driver'   => 'mysql',
	 *       'host'     => 'localhost',
	 *       'database' => 'mydb',
	 *       'username' => 'root',
	 *       'password' => '',
	 *   ];
	 */
	class RecommenderProvider extends ServiceProvider {
		
		/**
		 * Cached Connection singleton
		 */
		private ?Connection $connection = null;
		
		/**
		 * Cached RecommendationConfig singleton
		 */
		private ?RecommendationConfig $recommendationConfig = null;
		
		/**
		 * Returns the default configuration values.
		 * @return array<string, mixed>
		 */
		public static function getDefaults(): array {
			return [
				'driver'                      => 'mysql',
				'host'                        => 'localhost',
				'database'                    => '',
				'username'                    => '',
				'password'                    => '',
				'port'                        => 3306,
				'encoding'                    => 'utf8mb4',
				
				// RecommendationConfig defaults
				'category'                    => 1,
				'threshold_nr_common_ratings' => 30,
				'threshold_mult'              => 2,
				'threshold_rating'            => 0.66,
				'cost'                        => 5.0,
				'not_interested'              => -1.0,
				'direct_links'                => false,
				'direct_slope'                => true,
			];
		}
		
		/**
		 * Register all recommender commands with the Sculpt application.
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				PublishConfigCommand::class,
				InitCommand::class,
				RebuildLinksCommand::class,
			]);
		}
		
		/**
		 * Return a configured CakePHP Connection instance (singleton).
		 */
		public function getConnection(): Connection {
			if ($this->connection !== null) {
				return $this->connection;
			}
			
			$this->connection = new Connection([
				'driver'   => $this->resolveDriver($this->getConfigValueAsString('driver', 'mysql')),
				'host'     => $this->getConfigValueAsString('host', 'localhost'),
				'username' => $this->getConfigValueAsString('username', ''),
				'password' => $this->getConfigValueAsString('password', ''),
				'database' => $this->getConfigValueAsString('database', ''),
				'port'     => $this->getConfigValueAsInt('port', 3306),
				'encoding' => $this->getConfigValueAsString('encoding', 'utf8mb4'),
			]);
			
			return $this->connection;
		}
		
		/**
		 * Return a RecommendationConfig instance built from the loaded config (singleton).
		 */
		public function getRecommendationConfig(): RecommendationConfig {
			if ($this->recommendationConfig !== null) {
				return $this->recommendationConfig;
			}
			
			$this->recommendationConfig = new RecommendationConfig(
				category: $this->getConfigValueAsInt('category', 1),
				thresholdNrCommonRatings: $this->getConfigValueAsInt('threshold_nr_common_ratings', 30),
				thresholdMult: $this->getConfigValueAsInt('threshold_mult', 2),
				thresholdRating: $this->getConfigValueAsFloat('threshold_rating', 0.66),
				cost: $this->getConfigValueAsFloat('cost', 5.0),
				notInterested: $this->getConfigValueAsFloat('not_interested', -1.0),
				directLinks: (bool)$this->getConfigValue('direct_links', false),
				directSlope: (bool)$this->getConfigValue('direct_slope', true),
			);
			
			return $this->recommendationConfig;
		}
		
		// -------------------------------------------------------------------------
		
		/**
		 * Retrieve a float value from config, falling back to the provided default.
		 */
		private function getConfigValueAsFloat(string $key, float $default): float {
			$value = $this->getConfigValue($key);
			return is_numeric($value) ? (float)$value : $default;
		}
		
		/**
		 * Resolve a short driver name to a fully qualified CakePHP driver class.
		 */
		private function resolveDriver(string $driver): string {
			$driverMap = [
				'mysql'     => \Cake\Database\Driver\Mysql::class,
				'postgres'  => \Cake\Database\Driver\Postgres::class,
				'sqlite'    => \Cake\Database\Driver\Sqlite::class,
				'sqlserver' => \Cake\Database\Driver\Sqlserver::class,
			];
			
			return $driverMap[$driver] ?? $driver;
		}
	}