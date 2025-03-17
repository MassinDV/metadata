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
    // Decode HTML entities (e.g., L&#x27;usine → L'usine)
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Convert to title case (e.g., SALAH ET FATI → Salah Et Fati)
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
        $existingData = json_decode($jsonContent, true) ?? [];
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
        return;
    }

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

        // Determine the starting episode number based on existing episodes
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
                echo "Duplicate or invalid CUID for series: $seriesName\n";
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

            // Increment the episode number
            $episodeNumber++;

            // Mark this CUID as processed
            $existingEpisodesMap[$cuid] = true;
        }

        // Append new episodes to existing data
        if (!empty($episodes)) {
            $found = false;
            foreach ($existingData as &$series) {
                if ($series['Name'] === $seriesName) {
                    $series['Episodes'] = array_merge($series['Episodes'], $episodes);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $existingData[] = [
                    'Name' => $seriesName,
                    'Category' => $categoryName,
                    'Episodes' => $episodes,
                ];
            }
        }
    }

    // Save updated data to JSON file
    file_put_contents($jsonFileName, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Data saved to $jsonFileName\n";
}

// Define categories and URLs
$categories = [
    'Ramadan' => 'https://forja.ma/category/okzcntibiugptsesdsuzbtydjuudvoirezaifofo?lang=fr',
];

// Process each category
foreach ($categories as $categoryName => $categoryUrl) {
    processCategory($categoryUrl, $categoryName);
}
?>
