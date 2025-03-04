<?php

// Function to fetch JSON data from a given URL
function fetchJsonData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get HTTP status code
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "Error: API request failed with status code $httpCode.\n";
        return null;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error: Failed to decode JSON response.\n";
        return null;
    }

    return $data;
}

// Initialize XML document
$doc = new DOMDocument('1.0', 'UTF-8');
$root = $doc->createElement('tv');
$root->setAttribute('generator-info-name', 'Roya TV EPG Generator');
$doc->appendChild($root);

// Array to store channel IDs
$channels = [];

// Loop through pages 1 to 6
for ($page = 1; $page <= 6; $page++) {
    $url = "https://backend.roya.tv/api/v01/channels/schedule-pagination?page=$page&day_number=0&device_size=Size02Q40&device_type=1";
    $data = fetchJsonData($url);

    // Debugging: Print raw JSON response
    echo "Page $page Response:\n";
    echo json_encode($data, JSON_PRETTY_PRINT);
    echo "\n";

    // Check if data exists and process it
    if (isset($data['data']) && !empty($data['data'])) {
        foreach ($data['data'] as $dayData) {
            if (isset($dayData['channel']) && !empty($dayData['channel'])) {
                foreach ($dayData['channel'] as $channel) {
                    // Extract channel-level fields
                    $title = isset($channel['title']) ? $channel['title'] : '';
                    $channelId = 'channel_' . (isset($channel['id']) ? $channel['id'] : uniqid());

                    // Add channel definition if not already added
                    if (!in_array($channelId, $channels)) {
                        $channelNode = $doc->createElement('channel');
                        $channelNode->setAttribute('id', $channelId);
                        $displayName = $doc->createElement('display-name', $title);
                        $channelNode->appendChild($displayName);
                        $root->appendChild($channelNode);
                        $channels[] = $channelId;
                    }

                    // Process programs
                    if (isset($channel['programs']) && !empty($channel['programs'])) {
                        foreach ($channel['programs'] as $program) {
                            $startTime = isset($program['start_time']) ? parseTime($program['start_time'], $dayData['date']) : '';
                            $endTime = isset($program['end_time']) ? parseTime($program['end_time'], $dayData['date']) : '';
                            $programName = isset($program['name']) ? $program['name'] : '';
                            $programDescription = isset($program['description']) ? $program['description'] : '';

                            // Skip if start or end time is missing
                            if (empty($startTime) || empty($endTime)) {
                                continue;
                            }

                            // Create programme node
                            $programmeNode = $doc->createElement('programme');
                            $programmeNode->setAttribute('start', $startTime);
                            $programmeNode->setAttribute('stop', $endTime);
                            $programmeNode->setAttribute('channel', $channelId);

                            // Add title and description
                            $titleNode = $doc->createElement('title', $programName);
                            $titleNode->setAttribute('lang', 'ar');
                            $programmeNode->appendChild($titleNode);

                            $descNode = $doc->createElement('desc', $programDescription);
                            $descNode->setAttribute('lang', 'ar');
                            $programmeNode->appendChild($descNode);

                            $root->appendChild($programmeNode);
                        }
                    }
                }
            } else {
                echo "No channels found on page $page.\n";
            }
        }
    } else {
        echo "No data found on page $page.\n";
    }
}

// Save XML to file
$doc->formatOutput = true;
$doc->save('epg.xml');

echo "EPG file has been created successfully.\n";

// Helper function to parse time
function parseTime($time, $date) {
    $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', "$date $time");
    if (!$dateTime) {
        return '';
    }
    return $dateTime->format('YmdHis') . ' +0000'; // Format: YYYYMMDDHHMMSS +0000
}
?>