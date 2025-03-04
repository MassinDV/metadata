<?php

// Function to fetch JSON data from API
function fetchJsonData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Function to get category name from URL
function getCategoryFromUrl($url) {
    $path = parse_url($url, PHP_URL_PATH);
    $segments = explode('/', $path);
    return urldecode($segments[3]);
}

// Function to capitalize slug (e.g., "australias-open" to "Australias Open")
function capitalizeSlug($slug) {
    return str_replace('-', ' ', ucwords(strtolower($slug)));
}

// Function to extract StreamID from streamUrl (e.g., "2168982-8h534X8441YD7B1" from URL)
function extractStreamId($streamUrl) {
    if (preg_match('/bloomberg\/(.+?)\.smil/', $streamUrl, $match)) {
        return $match[1];
    }
    return '';
}

// Function to process "show" data with unique episodes
function processShowData($data, $category) {
    $shows = [];
    $episodesByShow = [];
    $processedIds = [];

    if (isset($data['data']['content']) && is_array($data['data']['content'])) {
        foreach ($data['data']['content'] as $item) {
            if (!in_array($item['id'], $processedIds)) {
                if ($item['type'] === 'show') {
                    $shows[$item['id']] = $item;
                    $processedIds[] = $item['id'];
                } elseif ($item['type'] === 'episode') {
                    $episodesByShow[$category][] = $item;
                    $processedIds[] = $item['id'];
                }
            }
        }
    }

    $result = [];
    foreach ($shows as $showId => $show) {
        $episodeCounter = 1;
        $streamUrl = '';
        if (isset($show['video']['sources']['HLS'])) {
            foreach ($show['video']['sources']['HLS'] as $hls) {
                if ($hls['Name'] === 'High') {
                    $streamUrl = $hls['Link'];
                    break;
                }
            }
        }

        $showData = [
            'Name' => $show['title'],
            'Category' => $category,
            'Slug' => capitalizeSlug($show['slug'] ?? ''),
            'Episodes' => []
        ];

        $showData['Episodes'][] = [
            'CUID' => $show['id'],
            'Session' => 'S01',
            'Episode' => sprintf('E%02d', $episodeCounter++),
            'imageUrl' => $show['image']['2-3']['x-large'] ?? '',
            'streamId' => extractStreamId($streamUrl)
        ];

        if (isset($episodesByShow[$category])) {
            foreach ($episodesByShow[$category] as $episode) {
                $episodeStreamUrl = '';
                if (isset($episode['video']['sources']['HLS'])) {
                    foreach ($episode['video']['sources']['HLS'] as $hls) {
                        if ($hls['Name'] === 'High') {
                            $episodeStreamUrl = $hls['Link'];
                            break;
                        }
                    }
                }

                $showData['Episodes'][] = [
                    'CUID' => $episode['id'],
                    'Session' => 'S01',
                    'Episode' => sprintf('E%02d', $episodeCounter++),
                    'imageUrl' => $episode['image']['2-3']['x-large'] ?? $show['image']['2-3']['x-large'],
                    'streamId' => extractStreamId($episodeStreamUrl)
                ];
            }
        }

        $result[] = $showData;
    }

    return $result;
}

// Function to process "movie" data
function processMovieData($data, $category) {
    $movies = [];
    $processedIds = [];

    if (isset($data['data']['content']) && is_array($data['data']['content'])) {
        foreach ($data['data']['content'] as $item) {
            if ($item['type'] === 'movie' && !in_array($item['id'], $processedIds)) {
                $streamUrl = '';
                if (isset($item['video']['sources']['HLS'])) {
                    foreach ($item['video']['sources']['HLS'] as $hls) {
                        if ($hls['Name'] === 'High') {
                            $streamUrl = $hls['Link'];
                            break;
                        }
                    }
                }

                $movies[] = [
                    'CUID' => $item['id'],
                    'Title' => $item['title'],
                    'Category' => $category,
                    'Slug' => capitalizeSlug($item['slug'] ?? ''),
                    'VerticalImage' => $item['image']['2-3']['x-large'] ?? '',
                    'PosterImage' => $item['image']['16-9']['x-large'] ?? '',
                    'StreamID' => extractStreamId($streamUrl)
                ];
                $processedIds[] = $item['id'];
            }
        }
    }

    return $movies;
}

// Function to merge and export data to JSON
function appendToJson($newData, $filename, $uniqueKey) {
    $existingData = [];
    if (file_exists($filename)) {
        $existingData = json_decode(file_get_contents($filename), true);
        if (!is_array($existingData)) {
            $existingData = [];
        }
    }

    $mergedData = $existingData;
    foreach ($newData as $item) {
        $key = $uniqueKey === 'CUID' ? $item[$uniqueKey] : ($item['Name'] . '|' . $item['Category']);
        if ($uniqueKey === 'CUID') {
            $mergedData[$key] = $item;
        } else {
            $found = false;
            foreach ($mergedData as &$existingItem) {
                if ($existingItem['Name'] === $item['Name'] && $existingItem['Category'] === $item['Category']) {
                    foreach ($item['Episodes'] as $episode) {
                        $existingItem['Episodes'][] = $episode;
                    }
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $mergedData[] = $item;
            }
            unset($existingItem);
        }
    }

    if ($uniqueKey !== 'CUID') {
        foreach ($mergedData as &$show) {
            $uniqueEpisodes = [];
            foreach ($show['Episodes'] as $episode) {
                $uniqueEpisodes[$episode['CUID']] = $episode;
            }
            $show['Episodes'] = array_values($uniqueEpisodes);
        }
        unset($show);
    }

    $jsonData = json_encode($uniqueKey === 'CUID' ? array_values($mergedData) : $mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($filename, $jsonData);
    echo "Data appended to $filename successfully.\n";
}

// API URLs
$urls = [
    "https://api-now.asharq.com/api/categories/اقتصاد/?limit=500",
    "https://api-now.asharq.com/api/categories/بيئة-ومناخ/?limit=500",
    "https://api-now.asharq.com/api/categories/تكنولوجيا/?limit=500",
    "https://api-now.asharq.com/api/categories/ثقافة/?limit=500",
    "https://api-now.asharq.com/api/categories/رياضة/?limit=500",
    "https://api-now.asharq.com/api/categories/صحة/?limit=500",
    "https://api-now.asharq.com/api/categories/علوم/?limit=500",
    "https://api-now.asharq.com/api/categories/مجتمع/?limit=500",
    "https://api-now.asharq.com/api/categories/مقابلات/?limit=500",
    "https://api-now.asharq.com/api/categories/منوعات/?limit=500"
];

// Process each URL and collect all rows
$allShowData = [];
$allMovieData = [];
foreach ($urls as $url) {
    $category = getCategoryFromUrl($url);
    $data = fetchJsonData($url);
    
    if ($data && isset($data['status']) && $data['status'] === true) {
        $showData = processShowData($data, $category);
        $movieData = processMovieData($data, $category);
        
        $allShowData = array_merge($allShowData, $showData);
        $allMovieData = array_merge($allMovieData, $movieData);
    } else {
        echo "Failed to fetch or process data from $url\n";
    }
}

// Append to JSON files
if (!empty($allShowData)) {
    appendToJson($allShowData, 'shows_with_episodes_output.json', 'Name');
} else {
    echo "No 'show' or 'episode' data to append.\n";
}

if (!empty($allMovieData)) {
    appendToJson($allMovieData, 'movies_output.json', 'CUID');
} else {
    echo "No 'movie' data to append.\n";
}

?>