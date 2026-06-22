<?php
	
	return [
		// Database connection
		'driver'   => 'mysql',
		'host'     => 'localhost',
		'database' => '',
		'username' => '',
		'password' => '',
		'port'     => 3306,
		'encoding' => 'utf8mb4',
		
		// Default category used when no category is passed to engine methods
		'category' => 1,
		
		// Minimum number of common ratings before similarity is considered reliable
		'threshold_nr_common_ratings' => 30,
		
		// Multiplier used in the similarity confidence calculation
		'threshold_mult' => 2,
		
		// Minimum rating for an item to count as "liked" in link/slope calculations
		'threshold_rating' => 0.66,
		
		// Cost factor used in the member similarity spread calculation
		'cost' => 5.0,
		
		// Sentinel value stored to mark "not interested" (must be negative)
		'not_interested' => -1.0,
		
		// Maintain the co-occurrence link table incrementally on every rating change.
		// When false, run "sculpt recommender:rebuild-links" after bulk imports.
		'direct_links' => false,
		
		// Maintain the slope one diff table incrementally on every rating change.
		// When false, run "sculpt recommender:rebuild-links" after bulk imports.
		'direct_slope' => true,
	];