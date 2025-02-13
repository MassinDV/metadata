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

// Function to check if an episode already exists in the data
function isEpisodeExists($episodes, $cuid) {
    foreach ($episodes as $episode) {
        if ($episode['CUID'] === $cuid) {
            return true;
        }
    }
    return false;
}

// Function to process a category and save data to a JSON file
function processCategory($categoryUrl, $categoryName) {
    $urls = scrapeUrlsFromCategory($categoryUrl);
    if (empty($urls)) {
        echo "No series found for category: $categoryName\n";
        return;
    }

    $jsonFileName = "$categoryName.json";
    $existingData = [];
    // Check if JSON file exists and load existing data
    if (file_exists($jsonFileName)) {
        $jsonContent = file_get_contents($jsonFileName);
        $existingData = json_decode($jsonContent, true) ?? [];
    }

    foreach ($urls as $url) {
        $html = file_get_html($url);
        if (!$html) {
            echo "Failed to load content from: $url\n";
            continue;
        }

        $seriesName = $html->find('meta[data-hid="title"]', 0)->content ?? 'UnknownSeries';
        // Format the series name
        $seriesName = formatSeriesName($seriesName);

        $episodes = [];
        $episodeNumber = 1;

        foreach ($html->find('div.episode-container') as $episodeContainer) {
            $imageUrl = $episodeContainer->find('img', 0)->src ?? '';
            $cuid = extractIdFromImageUrl($imageUrl);

            if ($cuid) {
                $proxyUrl = "https://api.forja.ma/pages/proxy/content/$cuid/stream_url?lang=fr";
                $redirectUrl = getRedirectUrl($proxyUrl);
                $redirectId = extractIdFromRedirectUrl($redirectUrl);

                if ($redirectId) {
                    // Removed the streamUrl assignment and added streamId
                    $streamId = "$redirectId";

                    // Check if the episode already exists in the existing data
                    $isDuplicate = false;
                    foreach ($existingData as $series) {
                        if ($series['Name'] === $seriesName && isEpisodeExists($series['Episodes'], $cuid)) {
                            $isDuplicate = true;
                            break;
                        }
                    }

                    if (!$isDuplicate) {
                        $episodes[] = [
                            'CUID' => $cuid,
                            'Session' => "S01",
                            'Episode' => sprintf("E%02d", $episodeNumber), // Formats as E01, E02, etc.
                            'imageUrl' => $imageUrl,
                            'streamId' => $streamId // Added streamId here
                        ];
                        $episodeNumber++;
                    }
                }
            }
        }

        if (!empty($episodes)) {
            // Check if the series already exists in the existing data
            $seriesIndex = -1;
            foreach ($existingData as $index => $series) {
                if ($series['Name'] === $seriesName) {
                    $seriesIndex = $index;
                    break;
                }
            }

            if ($seriesIndex === -1) {
                // Add new series
                $existingData[] = [
                    'Name' => $seriesName,
                    'Category' => $categoryName,
                    'Episodes' => $episodes
                ];
            } else {
                // Append new episodes to existing series
                $existingData[$seriesIndex]['Episodes'] = array_merge($existingData[$seriesIndex]['Episodes'], $episodes);
            }
        }
    }

    // Save updated data to JSON file
    file_put_contents($jsonFileName, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Data saved to $jsonFileName\n";
}

// Define categories and URLs
$categories = [
    'Drama' => 'https://forja.ma/category/series?g=serie-drame&contentType=playlist&lang=fr',
    'Action' => 'https://forja.ma/category/series?g=action-serie&contentType=playlist&lang=fr',
    'History' => 'https://forja.ma/category/series?g=histoire-serie&contentType=playlist&lang=fr',
    'Docs' => 'https://forja.ma/category/fnqxzgjwehxiksgbfujypbplresdjsiegtngcqwm?lang=fr',
    'Kids' => 'https://forja.ma/category/uvrqdsllaeoaxfporlrbsqehcyffsoufneihoyow?lang=fr',
    'Comedy' => 'https://forja.ma/category/series?g=comedie-serie&contentType=playlist&lang=fr',
];

// Process each category
foreach ($categories as $categoryName => $categoryUrl) {
    processCategory($categoryUrl, $categoryName);
}
?>
