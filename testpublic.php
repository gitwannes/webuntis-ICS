<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$server = 'ap.webuntis.com';
$classId = 4014;       // 1EM3
$date = '2026-08-24';  // Week of the screenshot

// 1. Fetch Weekly Data Endpoint
$dataUrl = sprintf(
    'https://%s/WebUntis/api/public/timetable/weekly/data?elementType=1&elementId=%d&date=%s&formatId=2',
    $server, $classId, $date
);

// 2. Fetch Page Config Endpoint (where elements are sometimes cached)
$configUrl = sprintf('https://%s/WebUntis/api/public/timetable/weekly/pageconfig?type=1', $server);

$ctx = stream_context_create(['http' => ['timeout' => 10]]);
$dataResponse = @file_get_contents($dataUrl, false, $ctx);
$configResponse = @file_get_contents($configUrl, false, $ctx);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<body style="font-family: monospace; background: #f4f4f4; padding: 20px;">
    <h2>WebUntis Public Data Diagnostic</h2>
    <p>Press <strong>Ctrl+F</strong> and search for "Dieltiens" or "Digitaal".</p>
    
    <h3>1. Weekly Data Payload (api/public/timetable/weekly/data)</h3>
    <pre style="background: #fff; padding: 15px; border: 1px solid #ccc;">
<?= htmlspecialchars(json_encode(json_decode($dataResponse), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>
    </pre>

    <h3>2. Page Config Payload (api/public/timetable/weekly/pageconfig)</h3>
    <pre style="background: #fff; padding: 15px; border: 1px solid #ccc;">
<?= htmlspecialchars(json_encode(json_decode($configResponse), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>
    </pre>
</body>
</html>