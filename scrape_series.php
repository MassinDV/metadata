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

// Function to get existing CUIDs from CSV file
function getExistingCuids($csvFilePath) {
    $existingCuids = [];

    if (file_exists($csvFilePath) && filesize($csvFilePath) > 0) {
        $file = fopen($csvFilePath, 'r');

        // Skip header row
        fgetcsv($file);

        // Read CUIDs from the existing CSV file
        while (($data = fgetcsv($file)) !== false) {
            $existingCuids[] = $data[0]; // CUID is the first column
        }

        fclose($file);
    }

    return $existingCuids;
}

// Function to process a category and append unique data to CSV
function processCategory($categoryUrl, $categoryName) {
    $urls = scrapeUrlsFromCategory($categoryUrl);
    if (empty($urls)) {
        echo "No series found for category: $categoryName\n";
        return;
    }

    // Define the CSV file path
    $csvFilePath = "C:/Users/rifma/Dropbox/Forja/csv2/{$categoryName}.csv";

    // Get existing CUIDs to prevent duplicates
    $existingCuids = getExistingCuids($csvFilePath);

    // Open the CSV file in append mode
    $csvFile = fopen($csvFilePath, 'a');

    if (!$csvFile) {
        echo "Failed to open CSV: $csvFilePath\n";
        return;
    }

    // If the file is new or empty, write the headers
    if (!file_exists($csvFilePath) || filesize($csvFilePath) === 0) {
        fputcsv($csvFile, ['CUID', 'Name', 'Session', 'Episode', 'EpisodeName', 'imageUrl', 'Category', 'streamUrl']);
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

            // Skip if CUID already exists in CSV
            if ($cuid && !in_array($cuid, $existingCuids)) {
                $proxyUrl = "https://api.forja.ma/pages/proxy/content/$cuid/stream_url?lang=fr";
                $redirectUrl = getRedirectUrl($proxyUrl);
                $redirectId = extractIdFromRedirectUrl($redirectUrl);

                if ($redirectId) {
                    $streamUrl = "https://forja.uplaytv3117.workers.dev/index.m3u8?id=$redirectId";

                    // Append only unique episode data to CSV
                    fputcsv($csvFile, [
                        'CUID' => $cuid,
                        'Name' => $seriesName,
                        'Session' => "S01",
                        'Episode' => sprintf("E%02d", $episodeNumber), // Formats as E01, E02, etc.
                        'EpisodeName' => '', // Empty value for EpisodeName
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
    echo "New unique data appended to $csvFilePath\n";
}

// Define categories and URLs
$categories = [
    'Action' => 'https://forja.ma/category/series?g=action-serie&contentType=playlist&lang=fr',
    'Drama' => 'https://forja.ma/category/series?g=serie-drame&contentType=playlist&lang=fr',
    'Comedy' => 'https://forja.ma/category/series?g=comedie-serie&contentType=playlist&lang=fr',
    'Theater' => 'https://forja.ma/category/qwygxsxmgphvbrmncuuvvpmzswzsfbfpwjprvinh&lang=fr',
    'Documentaries' => 'https://forja.ma/category/fnqxzgjwehxiksgbfujypbplresdjsiegtngcqwm?lang=fr',
    'kids' => 'https://forja.ma/category/uvrqdsllaeoaxfporlrbsqehcyffsoufneihoyow?lang=fr',
    'History' => 'https://forja.ma/category/series?g=histoire-serie&contentType=playlist&lang=fr'
];

// Process each category and append unique data
foreach ($categories as $categoryName => $categoryUrl) {
    processCategory($categoryUrl, $categoryName);
}
?>
