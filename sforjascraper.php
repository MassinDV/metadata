<?php
// Include the simple_html_dom library
include('simple_html_dom.php');

// Function to scrape series URLs from a category page
function scrapeUrlsFromCategory($categoryUrl) {
    $urls = [];
    $html = file_get_html($categoryUrl);
    if (!$html) {
        echo "Failed to load the HTML content from the URL: $categoryUrl\n";
        return $urls;
    }
    foreach ($html->find('a[href^="/content/"]') as $element) {
        $fullUrl = "https://forja.ma" . $element->href . "?lang=fr";
        $urls[] = $fullUrl;
    }
    return $urls;
}

// Function to get the redirect URL
function getRedirectUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    if (preg_match('/Location: (.*)/i', $response, $matches)) {
        return trim($matches[1]);
    }
    return $url;
}

// Function to extract ID from a redirect URL
function extractIdFromRedirectUrl($redirectUrl) {
    preg_match('/\/(\d+)\//', $redirectUrl, $matches);
    return $matches[1] ?? null;
}

// Function to extract ID from an image URL
function extractIdFromImageUrl($imageUrl) {
    preg_match('/(\d+)_tile_image/', $imageUrl, $matches);
    return $matches[1] ?? null;
}

// Function to decode HTML entities and format series names
function formatSeriesName($name) {
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = ucwords(strtolower($name));
    return $name;
}

// Function to process a category and save data to a JSON file
function processCategory($categoryUrl, $categoryName) {
    $jsonFileName = "$categoryName.json";
    $existingData = [];
    $existingEpisodesMap = [];

    // Load existing data if JSON file exists
    if (file_exists($jsonFileName)) {
        $jsonContent = file_get_contents($jsonFileName);
        if ($jsonContent === false) {
            echo "Error: Could not read $jsonFileName\n";
            return;
        }
        $existingData = json_decode($jsonContent, true);
        if ($existingData === null) {
            echo "Error: Invalid JSON in $jsonFileName, starting fresh\n";
            $existingData = [];
        }
        // Build map of existing episode CUIDs
        foreach ($existingData as $series) {
            foreach ($series['Episodes'] ?? [] as $episode) {
                $existingEpisodesMap[$episode['CUID']] = true;
            }
        }
    }

    // Scrape series URLs from the category page
    $urls = scrapeUrlsFromCategory($categoryUrl);
    if (empty($urls)) {
        echo "No series found for category: $categoryName\n";
        // Save existing data unchanged if no new data is found
        if (!empty($existingData)) {
            file_put_contents($jsonFileName, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        return;
    }

    $newData = []; // Temporary array for new series/episodes

    foreach ($urls as $url) {
        $html = file_get_html($url);
        if (!$html) {
            echo "Failed to load content from: $url\n";
            continue;
        }

        // Extract series name
        $seriesName = $html->find('meta[data-hid="title"]', 0)?->content ?? 'UnknownSeries';
        $seriesName = formatSeriesName($seriesName);

        // Find episode containers and process them
        $episodes = [];
        $episodeNumber = 1;

        // Check if series exists in existing data and get next episode number
        foreach ($existingData as $series) {
            if ($series['Name'] === $seriesName) {
                $episodeNumber = count($series['Episodes']) + 1;
                break;
            }
        }

        foreach ($html->find('div.episode-container') as $episodeContainer) {
            $imageUrl = $episodeContainer->find('img', 0)?->src ?? '';
            if (empty($imageUrl)) {
                echo "Missing image URL for series: $seriesName\n";
                continue;
            }

            $cuid = extractIdFromImageUrl($imageUrl);
            if (!$cuid || isset($existingEpisodesMap[$cuid])) {
                // Skip duplicates or invalid CUIDs
                continue;
            }

            // Fetch stream ID
            $proxyUrl = "https://api.forja.ma/pages/proxy/content/$cuid/stream_url?lang=fr";
            $redirectUrl = getRedirectUrl($proxyUrl);
            $streamId = extractIdFromRedirectUrl($redirectUrl);

            if (!$streamId) {
                echo "Failed to extract stream ID for CUID: $cuid\n";
                continue;
            }

            // Add the episode with a unique episode number
            $episodes[] = [
                'CUID' => $cuid,
                'Session' => 'S01', // Update this logic if multiple seasons exist
                'Episode' => sprintf("E%02d", $episodeNumber),
                'imageUrl' => $imageUrl,
                'streamId' => $streamId,
            ];

            $episodeNumber++;
            $existingEpisodesMap[$cuid] = true; // Mark as processed
        }

        // Store new episodes in temporary array
        if (!empty($episodes)) {
            $newData[$seriesName] = [
                'Name' => $seriesName,
                'Category' => $categoryName,
                'Episodes' => $episodes,
            ];
        }
    }

    // Merge new data with existing data
    foreach ($newData as $seriesName => $newSeries) {
        $found = false;
        foreach ($existingData as &$series) {
            if ($series['Name'] === $seriesName) {
                // Append new episodes to existing series
                $series['Episodes'] = array_merge($series['Episodes'], $newSeries['Episodes']);
                $found = true;
                break;
            }
        }
        if (!$found) {
            // Add new series if it doesn’t exist
            $existingData[] = $newSeries;
        }
    }

    // Save updated data to JSON file
    if (file_put_contents($jsonFileName, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        echo "Error: Failed to write to $jsonFileName\n";
    } else {
        echo "Data saved to $jsonFileName\n";
    }
}

// Define categories and URLs
$categories = [
    'Drama' => 'https://forja.ma/category/series?g=serie-drame&contentType=playlist&lang=fr',
    'Action' => 'https://forja.ma/category/series?g=action-serie&contentType=playlist&lang=fr',
    'History' => 'https://forja.ma/category/series?g=histoire-serie&contentType=playlist&lang=fr',
    'Docs' => 'https://forja.ma/category/fnqxzgjwehxiksgbfujypbplresdjsiegtngcqwm?lang=fr',
    'Kids' => 'https://forja.ma/category/uvrqdsllaeoaxfporlrbsqehcyffsoufneihoyow?lang=fr',
    'Ramadan' => 'https://forja.ma/category/okzcntibiugptsesdsuzbtydjuudvoirezaifofo?lang=fr',
    'Comedy' => 'https://forja.ma/category/series?g=comedie-serie&contentType=playlist&lang=fr',
];

// Process each category
foreach ($categories as $categoryName => $categoryUrl) {
    processCategory($categoryUrl, $categoryName);
}
?>
