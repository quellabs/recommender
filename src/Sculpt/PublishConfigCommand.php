<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Publishes the recommender configuration file to config/recommender.php
	 * in the project root.
	 *
	 * Usage:
	 *   sculpt recommender:init
	 */
	class PublishConfigCommand extends CommandBase {
		
		public function getSignature(): string {
			return 'recommender:init';
		}
		
		public function getDescription(): string {
			return 'Publish the recommender configuration file to config/recommender.php';
		}
		
		public function execute(ConfigurationManager $config): int {
			$source = realpath(__DIR__ . '/../../config/recommender.php');
			
			if ($source === false) {
				$this->output->error('Could not locate the recommender config stub file.');
				return 1;
			}
			$target = ComposerUtils::getProjectRoot() . '/config/recommender.php';
			
			// Skip if the config file was already published
			if (file_exists($target)) {
				$this->output->success('Config file already exists, skipping');
				return 0;
			}
			
			$result = copy($source, $target);
			
			if ($result) {
				$this->output->success('Published config/recommender.php');
			} else {
				$this->output->error("Failed to copy config file to {$target}");
			}
			
			return $result ? 0 : 1;
		}
	}