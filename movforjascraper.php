<?php
// Include the simple_html_dom library
include('simple_html_dom.php');

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

// Function to extract ID from an image URL
function extractIdFromImageUrl($imageUrl) {
    preg_match('/(\d+)_(vertical|poster)_image/', $imageUrl, $matches);
    return $matches[1] ?? null;
}

// Function to extract ID from a redirect URL
function extractIdFromRedirectUrl($redirectUrl) {
    preg_match('/\/(\d+)\//', $redirectUrl, $matches);
    return $matches[1] ?? null;
}

// Function to scrape movie data
function scrapeMovieData($movieUrl, $category) {
    $html = file_get_html($movieUrl);
    if (!$html) {
        echo "Failed to load content from: $movieUrl\n";
        return null;
    }

    // Extract title
    $title = $html->find('meta[data-hid="title"]', 0)->content ?? 'Unknown Title';

    // Extract synopsis
    $synopsis = $html->find('p.editable-content-ar', 0)->plaintext ?? 'No synopsis available';

    // Extract info (Réalisateur, Audio, Distribution)
    $info = [];
    foreach ($html->find('div.info-datas') as $infoData) {
        $key = trim($infoData->plaintext);
        $value = trim($infoData->next_sibling()->find('a, span', 0)->plaintext ?? 'N/A');
        $info[$key] = $value;
    }

    // Extract vertical image URL
    $verticalImage = $html->find('img[alt="Contenu principal vertical image"]', 0)->src ?? '';

    // Extract poster image URL
    $posterImage = $html->find('img[alt="Contenu poster image"]', 0)->src ?? '';

    // Extract CUID from the vertical image URL
    $cuid = extractIdFromImageUrl($verticalImage);

    // Get StreamID using CUID
    $streamID = '';
    if ($cuid) {
        $proxyUrl = "https://api.forja.ma/pages/proxy/content/$cuid/stream_url?lang=fr";
        $redirectUrl = getRedirectUrl($proxyUrl);
        $streamID = extractIdFromRedirectUrl($redirectUrl);
    }

    return [
        'CUID' => $cuid,
        'Title' => $title,
        'Category' => $category,
        'Synopsis' => $synopsis,
        'Info' => $info,
        'VerticalImage' => $verticalImage,
        'PosterImage' => $posterImage,
        'StreamID' => $streamID
    ];
}

// Function to scrape all movies from the category page
function scrapeMoviesFromCategory($categoryUrl, $categoryName) {
    $movies = [];
    $html = file_get_html($categoryUrl);

    if (!$html) {
        echo "Failed to load the HTML content from the URL: $categoryUrl\n";
        return $movies;
    }

    // Find all movie URLs
    foreach ($html->find('a[href^="/content/"]') as $element) {
        $movieUrl = "https://forja.ma" . $element->href . "?play=false&lang=fr";
        $movieData = scrapeMovieData($movieUrl, $categoryName);

        if ($movieData) {
            $movies[] = $movieData;
        }
    }

    return $movies;
}

// Define categories and URLs
$categories = [
    'Movies' => 'https://forja.ma/category/films?lang=fr',
    'Theater' => 'https://forja.ma/category/qwygxsxmgphvbrmncuuvvpmzswzsfbfpwjprvinh?lang=fr'
];

// Scrape movies from all categories
$allMovies = [];
foreach ($categories as $categoryName => $categoryUrl) {
    $movies = scrapeMoviesFromCategory($categoryUrl, $categoryName);
    $allMovies = array_merge($allMovies, $movies);
}

// Save the scraped data to a JSON file
$jsonFileName = 'movies.json';
file_put_contents($jsonFileName, json_encode($allMovies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Data saved to $jsonFileName\n";
?>
