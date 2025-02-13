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
                    $streamUrl = "https://forja.uplaytv3117.workers.dev/index.m3u8?id=$redirectId";

                    $episodes[] = [
                        'CUID' => $cuid,
                        'Session' => "S01",
                        'Episode' => sprintf("E%02d", $episodeNumber), // Formats as E01, E02, etc.
                        'imageUrl' => $imageUrl,
                        'streamUrl' => $streamUrl
                    ];

                    $episodeNumber++;
                }
            }
        }

        if (!empty($episodes)) {
            $existingData[] = [
                'Name' => $seriesName,
                'Category' => $categoryName,
                'Episodes' => $episodes
            ];
        }
    }

    // Save updated data to JSON file
    file_put_contents($jsonFileName, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Data saved to $jsonFileName\n";
}

// Define categories and URLs
$categories = [
    'Kidsfr' => ''https://forja.ma/category/uvrqdsllaeoaxfporlrbsqehcyffsoufneihoyow?lang=fr',
    'kidsar' => ''https://forja.ma/category/uvrqdsllaeoaxfporlrbsqehcyffsoufneihoyow?lang=ar'
];

// Process each category
foreach ($categories as $categoryName => $categoryUrl) {
    processCategory($categoryUrl, $categoryName);
}
?>
