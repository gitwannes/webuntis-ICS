<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

/**
 * driesap.php
 *
 * Fetches the class timetable from WebUntis's public API.
 * Configured via driesap_config.json.
 */

$CONFIG_FILE = __DIR__ . '/driesap_config.json';

// ---------------------------------------------------------------------------
// Configuration Management
// ---------------------------------------------------------------------------

function loadConfig(string $file): array
{
    if (!file_exists($file)) {
        throw new RuntimeException("Configuration file not found: $file");
    }
    $json = file_get_contents($file);
    $config = json_decode($json, true);
    if (!is_array($config)) {
        throw new RuntimeException("Invalid JSON in configuration file.");
    }
    return $config;
}

function saveConfig(string $file, array $config): void
{
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($file, $json) === false) {
        throw new RuntimeException("Failed to write to configuration file.");
    }
}

function fetchClassesFromApi(string $server): array
{
    $url = sprintf('https://%s/WebUntis/api/public/timetable/weekly/pageconfig?type=1', $server);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    
    if ($body === false) {
        throw new RuntimeException("Failed to fetch class list from WebUntis.");
    }
    
    $data = json_decode($body, true);
    $classes = [];
    
    if (isset($data['data']['elements']) && is_array($data['data']['elements'])) {
        foreach ($data['data']['elements'] as $el) {
            $name = $el['name'] ?? 'Unknown';
            $longName = $el['longName'] ?? '';
            $classes[$el['id']] = $longName ? "$name ($longName)" : $name;
        }
    }
    
    asort($classes);
    return $classes;
}

$config = loadConfig($CONFIG_FILE);

const ELEMENT_TYPE_TEACHER = 2;
const ELEMENT_TYPE_SUBJECT = 3;
const ELEMENT_TYPE_ROOM    = 4;

const KNOWN_CANCEL_STATES = ['CANCEL'];
const KNOWN_NORMAL_STATES = [
    'STANDARD', 'SUBSTITUTION', 'ROOMSUBSTITUTION', 'SHIFT', 'ADDITIONAL', 'EXAM',
];

// ---------------------------------------------------------------------------
// Date Handling Helpers
// ---------------------------------------------------------------------------

function mondaysCovering(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $mondays = [];
    $dow = (int)$start->format('N');
    $current = $start->modify('-' . ($dow - 1) . ' days');
    while ($current <= $end) {
        $mondays[] = $current;
        $current = $current->modify('+7 days');
    }
    return $mondays;
}

function formatTime(int $hhmm): string
{
    $hh = intdiv($hhmm, 100);
    $mm = $hhmm % 100;
    return sprintf('%02d:%02d', $hh, $mm);
}

function formatDate(int $yyyymmdd): string
{
    $s = (string)$yyyymmdd;
    return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
}

// ---------------------------------------------------------------------------
// Merging Logic
// ---------------------------------------------------------------------------

function processAndMergeEvents(array $rawLessons): array
{
    $groupedByTime = [];
    foreach ($rawLessons as $lesson) {
        $subject = implode(', ', $lesson['subject']) ?: 'Les';
        $key = $lesson['date'] . '|' . $lesson['start_time'] . '|' . $lesson['end_time'] . '|' . $subject;
        
        if (!isset($groupedByTime[$key])) {
            $groupedByTime[$key] = [
                'date'       => $lesson['date'],
                'start_time' => $lesson['start_time'],
                'end_time'   => $lesson['end_time'],
                'subject'    => $subject,
                'details'    => [], 
                'cell_state' => $lesson['cell_state']
            ];
        }

        $teacher = implode(', ', $lesson['teacher']);
        $room = implode(', ', $lesson['room']);
        $detailParts = [];
        if ($teacher) $detailParts[] = "Lkr: $teacher";
        if ($room) $detailParts[] = "Lok: $room";
        if ($lesson['subst_text']) $detailParts[] = $lesson['subst_text'];
        
        $detailLine = implode(' | ', $detailParts);
        if ($detailLine && !in_array($detailLine, $groupedByTime[$key]['details'])) {
            $groupedByTime[$key]['details'][] = $detailLine;
        }
    }

    $parallelMerged = array_values($groupedByTime);

    usort($parallelMerged, function ($a, $b) {
        return [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']];
    });

    $consecutiveMerged = [];
    foreach ($parallelMerged as $current) {
        if (empty($consecutiveMerged)) {
            $consecutiveMerged[] = $current;
            continue;
        }

        $lastIdx = count($consecutiveMerged) - 1;
        $last = $consecutiveMerged[$lastIdx];

        $isConsecutive = $current['date'] === $last['date']
            && $current['start_time'] === $last['end_time']
            && $current['subject'] === $last['subject']
            && $current['details'] === $last['details']
            && $current['cell_state'] === $last['cell_state'];

        if ($isConsecutive) {
            $consecutiveMerged[$lastIdx]['end_time'] = $current['end_time'];
        } else {
            $consecutiveMerged[] = $current;
        }
    }

    return $consecutiveMerged;
}

// ---------------------------------------------------------------------------
// Pipeline & ICS logic
// ---------------------------------------------------------------------------

function fetchWeek(string $server, int $classId, DateTimeImmutable $mondayDate): array
{
    $url = sprintf(
        'https://%s/WebUntis/api/public/timetable/weekly/data?%s',
        $server,
        http_build_query([
            'elementType' => 1,
            'elementId'   => $classId,
            'date'        => $mondayDate->format('Y-m-d'),
            'formatId'    => 2,
        ])
    );
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Failed to fetch week of ' . $mondayDate->format('Y-m-d'));
    }
    return json_decode($body, true) ?? [];
}

function namesForType(array $periodElements, array $lookup, int $type): array
{
    $names = [];
    foreach ($periodElements as $pe) {
        if (($pe['type'] ?? null) !== $type) {
            continue;
        }
        $key = $type . ':' . $pe['id'];
        if (isset($lookup[$key])) {
            $el = $lookup[$key];
            
            $longName = trim((string)($el['longName'] ?? ''));
            $shortName = trim((string)($el['name'] ?? ''));
            
            if ($longName !== '') {
                $names[] = $longName;
            } elseif ($shortName !== '') {
                $names[] = $shortName;
            } else {
                $names[] = 'id:' . $pe['id'];
            }
        }
    }
    return $names;
}

function icsEscape(string $text): string
{
    return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
}

function foldLine(string $line): string
{
    $result = '';
    $bytes = strlen($line);
    $chunkSize = 75;
    $offset = 0;
    $first = true;
    while ($offset < $bytes) {
        $len = $first ? $chunkSize : $chunkSize - 1;
        $chunk = substr($line, $offset, $len);
        $result .= ($first ? '' : "\r\n ") . $chunk;
        $offset += $len;
        $first = false;
    }
    return $result;
}

function runSync(array &$config, string $configFile): array
{
    $logs = [];
    $allPeriods = [];
    $elementsLookup = [];
    $lessons = [];

    try {
        $tz = new DateTimeZone($config['timezone']);
        $today = new DateTimeImmutable('today', $tz);
        
        $mBefore = (int)$config['months_before'];
        $mAfter = (int)$config['months_after'];
        $rangeStart = $today->modify("first day of -$mBefore month");
        $rangeEnd   = $today->modify("last day of +$mAfter month");

        foreach (mondaysCovering($rangeStart, $rangeEnd) as $monday) {
            $data = fetchWeek($config['server'], (int)$config['class_id'], $monday);
            $resultData = $data['data']['result']['data'] ?? null;
            
            if (!is_array($resultData)) continue;

            $periods = $resultData['elementPeriods'][(string)$config['class_id']] ?? [];
            $allPeriods = array_merge($allPeriods, $periods);

            foreach ($resultData['elements'] ?? [] as $el) {
                $elementsLookup[$el['type'] . ':' . $el['id']] = $el;
            }
        }

        $rangeStartStr = $rangeStart->format('Y-m-d');
        $rangeEndStr = $rangeEnd->format('Y-m-d');
        
        $cancelledCount = 0;
        foreach ($allPeriods as $p) {
            $cellState = $p['cellState'] ?? null;
            if (in_array($cellState, KNOWN_CANCEL_STATES, true)) {
                $cancelledCount++;
                continue;
            }

            $lessonDate = formatDate((int)$p['date']);
            if ($lessonDate < $rangeStartStr || $lessonDate > $rangeEndStr) continue;

            $periodElements = $p['elements'] ?? [];
            $lessons[] = [
                'date'        => $lessonDate,
                'start_time'  => formatTime((int)$p['startTime']),
                'end_time'    => formatTime((int)$p['endTime']),
                'subject'     => namesForType($periodElements, $elementsLookup, ELEMENT_TYPE_SUBJECT),
                'teacher'     => namesForType($periodElements, $elementsLookup, ELEMENT_TYPE_TEACHER),
                'room'        => namesForType($periodElements, $elementsLookup, ELEMENT_TYPE_ROOM),
                'cell_state'  => $cellState,
                'subst_text'  => $p['substText'] ?? null,
                'period_text' => $p['periodText'] ?? null,
            ];
        }

        $events = processAndMergeEvents($lessons);
        $logs[] = sprintf("Parsed %d lessons, excluded %d cancelled.", count($lessons), $cancelledCount);
        $logs[] = sprintf("Merged %d raw periods into %d calendar events across distinct groups.", count($lessons), count($events));

        $utc = new DateTimeZone('UTC');
        $nowUtc = (new DateTimeImmutable('now', $utc))->format('Ymd\THis\Z');

        // Update last generated time in config
        $config['last_generated'] = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        saveConfig($configFile, $config);

        $ics = [];
        $ics[] = 'BEGIN:VCALENDAR';
        $ics[] = 'VERSION:2.0';
        $ics[] = 'PRODID:-//hofmans.be//WebUntis Sync//NL';
        $ics[] = 'CALSCALE:GREGORIAN';
        $ics[] = 'METHOD:PUBLISH';
        $ics[] = foldLine('X-WR-CALNAME:' . icsEscape($config['calendar_name']));
        $ics[] = 'X-WR-TIMEZONE:' . $tz->getName();
        $ics[] = 'X-LAST-GENERATED:' . $nowUtc; // Add custom header for last generation time

        foreach ($events as $event) {
            $startLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i', $event['date'] . ' ' . $event['start_time'], $tz);
            $endLocal   = DateTimeImmutable::createFromFormat('Y-m-d H:i', $event['date'] . ' ' . $event['end_time'], $tz);
            $startUtc = $startLocal->setTimezone($utc)->format('Ymd\THis\Z');
            $endUtc   = $endLocal->setTimezone($utc)->format('Ymd\THis\Z');

            $uidSource = $event['date'] . $event['start_time'] . $event['end_time'] . $event['subject'];
            $uid = md5($uidSource) . '@hofmans.be';

            $descriptionParts = [];
            $allTeachers = [];
            
            foreach ($event['details'] as $idx => $detailLine) {
                $descriptionParts[] = "- " . $detailLine;
                if (preg_match('/Lkr:\s([^|]+)/', $detailLine, $matches)) {
                    $allTeachers[] = trim($matches[1]);
                }
            }
            if ($event['cell_state'] !== 'STANDARD') {
                $descriptionParts[] = "State: {$event['cell_state']}";
            }
            
            $description = implode('\\n', array_map('icsEscape', $descriptionParts));
            $teacherTitle = !empty($allTeachers) ? ' - ' . implode(', ', array_unique($allTeachers)) : '';

            $ics[] = 'BEGIN:VEVENT';
            $ics[] = foldLine('UID:' . $uid);
            $ics[] = foldLine('DTSTAMP:' . $nowUtc);
            $ics[] = foldLine('DTSTART:' . $startUtc);
            $ics[] = foldLine('DTEND:' . $endUtc);
            $ics[] = foldLine('SUMMARY:' . icsEscape($event['subject'] . $teacherTitle));
            if ($description !== '') {
                $ics[] = foldLine('DESCRIPTION:' . $description);
            }
            $ics[] = 'END:VEVENT';
        }
        $ics[] = 'END:VCALENDAR';
        
        $icsContent = implode("\r\n", $ics) . "\r\n";
        
        $tmpPath = __DIR__ . '/' . $config['output_path'] . '.tmp';
        $finalPath = __DIR__ . '/' . $config['output_path'];
        
        file_put_contents($tmpPath, $icsContent);
        rename($tmpPath, $finalPath);

        $logs[] = "Wrote ICS to " . $finalPath;

        return [
            'success' => true,
            'status'  => 'OK',
            'events'  => $events,
            'logs'    => $logs,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'status'  => 'NOT OK',
            'error'   => $e->getMessage(),
            'events'  => [],
            'logs'    => [],
        ];
    }
}

// ---------------------------------------------------------------------------
// Execution Entrypoint (CLI vs Browser HTML)
// ---------------------------------------------------------------------------

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    $result = runSync($config, $CONFIG_FILE);
    if (!$result['success']) {
        fwrite(STDERR, "ERROR: " . $result['error'] . "\n");
        exit(1);
    }
    echo "Sync complete. Status: OK\n";
    exit(0);
}

$message = '';
$result = null;

if (empty($config['classes'])) {
    try {
        $config['classes'] = fetchClassesFromApi($config['server']);
        saveConfig($CONFIG_FILE, $config);
    } catch (Exception $e) {
        $message = "Could not fetch initial classes: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['refresh_classes'])) {
        try {
            $config['classes'] = fetchClassesFromApi($config['server']);
            saveConfig($CONFIG_FILE, $config);
            $message = "Class list refreshed successfully.";
        } catch (Exception $e) {
            $message = "Error refreshing classes: " . $e->getMessage();
        }
    } elseif (isset($_POST['save_settings'])) {
        $config['class_id'] = (int)$_POST['class_id'];
        $config['months_before'] = (int)$_POST['months_before'];
        $config['months_after'] = (int)$_POST['months_after'];
        $config['calendar_name'] = $_POST['calendar_name'];
        saveConfig($CONFIG_FILE, $config);
        $message = "Settings saved successfully.";
    } elseif (isset($_POST['generate_ics'])) {
        $result = runSync($config, $CONFIG_FILE);
        $message = "ICS Calendar generated successfully.";
    }
}

if (isset($_GET['download'])) {
    $path = __DIR__ . '/' . $config['output_path'];
    if (file_exists($path)) {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebUntis Calendar Sync Settings</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 2rem; background: #f4f5f7; color: #333; }
        .card { background: #fff; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; }
        h1, h2, h3 { margin-top: 0; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: bold; margin-bottom: 0.5rem; }
        select, input[type="text"], input[type="number"] { width: 100%; max-width: 400px; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 0.5rem; }
        .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .badge { padding: 0.35rem 0.65rem; border-radius: 4px; font-weight: bold; color: white; }
        .badge-ok { background: #28a745; }
        .badge-not-ok { background: #dc3545; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #dee2e6; padding: 0.75rem; text-align: left; }
        th { background: #f8f9fa; }
        .alert { padding: 1rem; background: #d4edda; color: #155724; border-radius: 4px; margin-bottom: 1rem; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
    </style>
    <script>
        function filterClasses() {
            var input = document.getElementById('classSearch').value.toLowerCase();
            var options = document.getElementById('classSelect').options;
            for (var i = 0; i < options.length; i++) {
                var text = options[i].text.toLowerCase();
                options[i].style.display = text.indexOf(input) > -1 ? '' : 'none';
            }
        }
    </script>
</head>
<body>

    <h1>WebUntis Sync Dashboard</h1>

    <?php if ($message): ?>
        <div class="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Configuration</h2>
        <form method="POST">
            <div class="form-group">
                <label>Class Group</label>
                <input type="text" id="classSearch" placeholder="Search for a class..." onkeyup="filterClasses()">
                <select name="class_id" id="classSelect">
                    <?php foreach ($config['classes'] as $id => $name): ?>
                        <option value="<?= $id ?>" <?= $id == $config['class_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="refresh_classes" value="1" class="btn btn-secondary btn-sm">Refresh Class List</button>
            </div>

            <div class="form-group">
                <label>Months Before (includes current month)</label>
                <input type="number" name="months_before" min="0" max="12" value="<?= htmlspecialchars((string)$config['months_before']) ?>">
            </div>

            <div class="form-group">
                <label>Months After</label>
                <input type="number" name="months_after" min="0" max="12" value="<?= htmlspecialchars((string)$config['months_after']) ?>">
            </div>

            <div class="form-group">
                <label>Calendar Name (Output ICS Name)</label>
                <input type="text" name="calendar_name" value="<?= htmlspecialchars($config['calendar_name']) ?>">
            </div>

            <button type="submit" name="save_settings" value="1" class="btn btn-primary">Save Settings</button>
        </form>
    </div>

    <div class="card">
        <div class="flex-between">
            <div>
                <h2>Manual Sync</h2>
                <p><strong>Last Generated:</strong> <?= htmlspecialchars($config['last_generated'] ?? 'Never') ?></p>
            </div>
            <form method="POST">
                <button type="submit" name="generate_ics" value="1" class="btn btn-success" style="font-size: 1.1rem; padding: 1rem 2rem;">🚀 Generate ICS Now</button>
            </form>
        </div>
        <p style="margin-top: 1rem;"><a href="?download=1" class="btn btn-primary">📥 Download Latest .ics File</a></p>
    </div>

    <?php if ($result !== null): ?>
        <div class="card">
            <h2>Generation Status</h2>
            <p>Status: <span class="badge <?= $result['success'] ? 'badge-ok' : 'badge-not-ok' ?>"><?= $result['status'] ?></span></p>
            <?php if (!$result['success']): ?>
                <p style="color: red;"><?= htmlspecialchars($result['error']) ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($result['logs'])): ?>
        <div class="card">
            <h2>Logs</h2>
            <div style="background: #e9ecef; padding: 1rem; border-radius: 4px; font-family: monospace; font-size: 0.9em;">
                <?php foreach ($result['logs'] as $log): ?>
                    <div><?= htmlspecialchars($log) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($result['events'])): ?>
        <div class="card">
            <h2>Calendar Preview</h2>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Subject</th>
                        <th>Combined Details (Lines)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result['events'] as $event): ?>
                        <tr>
                            <td><?= htmlspecialchars($event['date']) ?></td>
                            <td><?= htmlspecialchars($event['start_time']) ?> - <?= htmlspecialchars($event['end_time']) ?></td>
                            <td><strong><?= htmlspecialchars($event['subject']) ?></strong><br><small><?= htmlspecialchars($event['cell_state']) ?></small></td>
                            <td>
                                <?php foreach ($event['details'] as $line): ?>
                                    <div><?= htmlspecialchars($line) ?></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</body>
</html>