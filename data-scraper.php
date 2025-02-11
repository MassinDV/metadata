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

// Function to process a category and save data to CSV
function processCategory($categoryUrl, $categoryName) {
    $urls = scrapeUrlsFromCategory($categoryUrl);
    if (empty($urls)) {
        echo "No series found for category: $categoryName\n";
        return;
    }

    $csvFileName = "$categoryName.csv";
    $isNewFile = !file_exists($csvFileName);

    // Open the CSV file in append mode to avoid overwriting
    $csvFile = fopen($csvFileName, 'a');

    if (!$csvFile) {
        echo "Failed to create CSV: $csvFileName\n";
        return;
    }

    // Write headers only if the file is new
    if ($isNewFile) {
        fputcsv($csvFile, ['CUID', 'Name', 'Session', 'Episode', 'imageUrl', 'Category', 'streamUrl']);
    }

    foreach ($urls as $url) {
        $html = file_get_html($url);
        if (!$html) {
            echo "Failed to load content from: $url\n";
            continue;
        }

        $seriesName = $html->find('meta[data-hid="title"]', 0)->content ?? 'UnknownSeries';
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

                    // Save episode data to CSV
                    fputcsv($csvFile, [
                        'CUID' => $cuid,
                        'Name' => $seriesName,
                        'Session' => "S01",
                        'Episode' => sprintf("E%02d", $episodeNumber), // Formats as E01, E02, etc.
                        'imageUrl' => $imageUrl,
                        'Category' => $categoryName,
                        'streamUrl' => $streamUrl
                    ]);

                    $episodeNumber++;
                }
            }
        }
    }

    fclose($csvFile);
    echo "Data saved to $csvFileName\n";
}

// Define categories and URLs
$categories = [
    'Action' => 'https://forja.ma/category/series?g=action-serie&contentType=playlist&lang=fr',
    'History' => 'https://forja.ma/category/series?g=histoire-serie&contentType=playlist&lang=fr'
];

// Process each category
foreach ($categories as $categoryName => $categoryUrl) {
    processCategory($categoryUrl, $categoryName);
}
?>
