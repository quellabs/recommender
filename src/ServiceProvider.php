<?php
	
	namespace Quellabs\Recommender;
	
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;
	
	/**
	 * Registers RecommendationConfig with Canvas's DI container so it can be
	 * autowired into controllers and services alongside the Connection that
	 * CanvasDatabase\ServiceProvider already provides.
	 *
	 * Typical controller usage:
	 *
	 *   public function __construct(
	 *       Connection $connection,
	 *       RecommendationConfig $config,
	 *   ) {
	 *       $this->recommender = new ItemRecommender($connection, $config);
	 *   }
	 *
	 * Config is loaded from the file listed in the "discover" section of
	 * composer.json and injected via setConfig() before createInstance() is called.
	 *
	 * Minimal config/recommender.php example:
	 *
	 *   return [
	 *       'category'      => 1,
	 *       'direct_slope'  => true,
	 *       'direct_links'  => false,
	 *   ];
	 */
	class ServiceProvider implements ServiceProviderInterface {
		
		/** @var array<string, mixed> */
		private array $config = [];
		
		/**
		 * Return the provider metadata (none for this provider).
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [];
		}
		
		/**
		 * Return the raw configuration array currently held by the provider.
		 * @return array<string, mixed>
		 */
		public function getConfig(): array {
			return $this->config;
		}
		
		/**
		 * Store the raw configuration array injected by the DI container.
		 * @param array<string, mixed> $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * This provider handles RecommendationConfig only.
		 * @param string $className Fully-qualified class name being resolved
		 * @param array<string, mixed> $metadata Provider metadata
		 * @return bool True if this provider handles the class
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === RecommendationConfig::class;
		}
		
		/**
		 * Build and return a RecommendationConfig from the loaded config values.
		 * @param string $className Fully-qualified class name being resolved
		 * @param array<string, mixed> $dependencies Resolved constructor dependencies
		 * @param array<string, mixed> $metadata Provider metadata
		 * @param MethodContextInterface|null $methodContext Optional method-call context
		 * @return RecommendationConfig The configured instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): RecommendationConfig {
			return new RecommendationConfig(
				category: $this->getInt('category', 1),
				thresholdNrCommonRatings: $this->getInt('threshold_nr_common_ratings', 30),
				thresholdMult: $this->getInt('threshold_mult', 2),
				thresholdRating: $this->getFloat('threshold_rating', 0.66),
				cost: $this->getFloat('cost', 5.0),
				notInterested: $this->getFloat('not_interested', -1.0),
				directLinks: $this->getBool('direct_links', false),
				directSlope: $this->getBool('direct_slope', true),
			);
		}
		
		/**
		 * Read an integer config value, falling back to a default when missing or non-numeric.
		 * @param string $key Config key
		 * @param int $default Fallback value
		 * @return int
		 */
		private function getInt(string $key, int $default): int {
			$value = $this->config[$key] ?? $default;
			return is_numeric($value) ? (int)$value : $default;
		}
		
		/**
		 * Read a float config value, falling back to a default when missing or non-numeric.
		 * @param string $key Config key
		 * @param float $default Fallback value
		 * @return float
		 */
		private function getFloat(string $key, float $default): float {
			$value = $this->config[$key] ?? $default;
			return is_numeric($value) ? (float)$value : $default;
		}
		
		/**
		 * Read a boolean config value, falling back to a default when missing.
		 * @param string $key Config key
		 * @param bool $default Fallback value
		 * @return bool
		 */
		private function getBool(string $key, bool $default): bool {
			return isset($this->config[$key]) ? (bool)$this->config[$key] : $default;
		}
	}