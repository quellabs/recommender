<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;

/** Maintains independent liked and Slope One pair measures during rating writes. */
readonly class LinkUpdater {
    /** @param Connection $connection Database connection
     * @param RecommendationConfig $config Recommendation settings
     */
    public function __construct(private Connection $connection, private RecommendationConfig $config) {}

    /** Update liked-pair counts when a rating crosses the like threshold.
     * @param int $memberId Member being updated
     * @param int $productId Product being updated
     * @param int $category Rating category
     * @param float $rating New rating, or -1.0 for deletion
     * @param float $previous Previous rating, or -1.0 if absent
     * @return void
     */
    public function updateLinks(int $memberId, int $productId, int $category, float $rating, float $previous): void {
        $threshold = $this->config->getThresholdRating();
        $delta = (int)($rating >= $threshold) - (int)($previous >= $threshold);
        if ($delta === 0) {
            return;
        }
        $this->connection->transactional(function () use ($memberId, $productId, $category, $threshold, $delta): void {
            foreach ($this->otherRatings($memberId, $productId, $category, $threshold) as $row) {
                $other = $row['product_id'];
                $this->changePair($productId, $other, $category, $delta, 0, 0.0);
                $this->changePair($other, $productId, $category, $delta, 0, 0.0);
            }
            $this->prune($category);
        });
    }

    /** Update Slope One counts and differential for a rating change.
     * @param int $memberId Member being updated
     * @param int $productId Product being updated
     * @param int $category Rating category
     * @param float $rating New rating, or -1.0 for deletion
     * @param float $previous Previous rating, or -1.0 if absent
     * @return void
     */
    public function updateSlope(int $memberId, int $productId, int $category, float $rating, float $previous): void {
        $countDelta = (int)($rating >= 0.0) - (int)($previous >= 0.0);
        if ($countDelta === 0 && ($rating < 0.0 || $rating === $previous)) {
            return;
        }
        $this->connection->transactional(function () use ($memberId, $productId, $category, $rating, $previous, $countDelta): void {
            foreach ($this->otherRatings($memberId, $productId, $category, 0.0) as $row) {
                $other = $row['product_id'];
                $otherRating = $row['rating'];
                $oldDiff = $previous >= 0.0 ? $otherRating - $previous : 0.0;
                $newDiff = $rating >= 0.0 ? $otherRating - $rating : 0.0;
                $this->changePair($productId, $other, $category, 0, $countDelta, $newDiff - $oldDiff);
                $this->changePair($other, $productId, $category, 0, $countDelta, $oldDiff - $newDiff);
            }
            $this->prune($category);
        });
    }

    /** Read the member's other genuine ratings in this category.
     * @param int $memberId Member ID
     * @param int $productId Product to exclude
     * @param int $category Category
     * @param float $minimum Minimum rating
     * @return array<int, array{product_id: int, rating: float}>
     */
    private function otherRatings(int $memberId, int $productId, int $category, float $minimum): array {
        $rows = $this->connection->execute('SELECT product_id, rating FROM vogoo_ratings
            WHERE member_id = :member AND category = :category AND product_id <> :product AND rating >= :minimum',
            ['member' => $memberId, 'category' => $category, 'product' => $productId, 'minimum' => $minimum]
        )->fetchAll('assoc');
        $ratings = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['product_id'] ?? null) || !is_numeric($row['rating'] ?? null)) {
                throw new \UnexpectedValueException('Invalid rating row returned by the database.');
            }
            $ratings[] = ['product_id' => (int)$row['product_id'], 'rating' => (float)$row['rating']];
        }
        return $ratings;
    }

    /** Apply one directed pair's independent deltas.
     * @param int $first First item
     * @param int $second Second item
     * @param int $category Category
     * @param int $likedDelta Liked count delta
     * @param int $slopeDelta Slope count delta
     * @param float $diffDelta Differential sum delta
     * @return void
     */
    private function changePair(int $first, int $second, int $category, int $likedDelta, int $slopeDelta, float $diffDelta): void {
        if ($likedDelta < 0 || $slopeDelta < 0) {
            $this->connection->execute('UPDATE vogoo_links SET liked_count = liked_count + :liked,
                slope_count = slope_count + :slope, diff_slope = diff_slope + :diff
                WHERE item_id1 = :first AND item_id2 = :second AND category = :category',
                ['first' => $first, 'second' => $second, 'category' => $category,
                 'liked' => $likedDelta, 'slope' => $slopeDelta, 'diff' => $diffDelta]);
            return;
        }
        $this->connection->execute('INSERT INTO vogoo_links
            (item_id1, item_id2, category, liked_count, slope_count, diff_slope)
            VALUES (:first, :second, :category, :liked, :slope, :diff)
            ON DUPLICATE KEY UPDATE liked_count = liked_count + VALUES(liked_count),
                slope_count = slope_count + VALUES(slope_count), diff_slope = diff_slope + VALUES(diff_slope)',
            ['first' => $first, 'second' => $second, 'category' => $category,
             'liked' => $likedDelta, 'slope' => $slopeDelta, 'diff' => $diffDelta]
        );
    }

    /** Remove rows with neither measure populated.
     * @param int $category Category to prune
     * @return void
     */
    private function prune(int $category): void {
        $this->connection->execute('DELETE FROM vogoo_links WHERE category = :category AND liked_count = 0 AND slope_count = 0',
            ['category' => $category]);
    }
}
