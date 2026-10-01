<?php

namespace Quellabs\Recommender;

/** A recommendation with a score whose meaning is defined by its strategy. */
readonly class RecommendationResult {
    /** @param int $itemId Recommended product ID
     * @param float $score Strategy-specific score
     * @param string $strategy Either item_links or top_rated
     * @param array<int, int> $contributingItemIds Rated items contributing to the score
     */
    public function __construct(
        public int $itemId,
        public float $score,
        public string $strategy,
        public array $contributingItemIds,
    ) {}
}
