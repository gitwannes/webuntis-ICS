<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

/**
 * driesap.php
 *
 * 1. Fetches the class timetable from WebUntis's public API.
 * 2. Parses webuntisdata.json to map short names (e.g., "DISI") to their full names.
 * 3. Merges consecutive and parallel events.
 * 4. Outputs a cleanly formatted ICS calendar file.
 * 5. Logs all actions (including remote calendar syncs via ?feed=1) to driesap.log.
 */

// ---------------------------------------------------------------------------
// File Paths & Constants
// ---------------------------------------------------------------------------

$CONFIG_FILE = __DIR__ . '/driesap_config.json';
$STATE_FILE = __DIR__ . '/driesap_state.json';
$EXTERNAL_DATA_FILE = __DIR__ . '/webuntisdata.json';
$LOG_FILE = __DIR__ . '/driesap.log';

const ELEMENT_TYPE_TEACHER = 2;
const ELEMENT_TYPE_SUBJECT = 3;
const ELEMENT_TYPE_ROOM    = 4;

const KNOWN_CANCEL_STATES = ['CANCEL'];
const KNOWN_NORMAL_STATES = ['STANDARD', 'SUBSTITUTION', 'ROOMSUBSTITUTION', 'SHIFT', 'ADDITIONAL', 'EXAM'];

// ---------------------------------------------------------------------------
// Core Utilities & Logging
// ---------------------------------------------------------------------------

/**
 * Appends an action to the log file using the standard format:
 * YYYY-MM-DD HH:MM:SS.v | ACTION | DETAILS
 */
function writeAppLog(string $action, string $details = ''): void
{
    global $LOG_FILE;
    $timestamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
    $logLine = $timestamp . ' | ' . $action;
    if ($details !== '') {
        $logLine .= ' | ' . $details;
    }
    // LOCK_EX prevents file corruption if web and cron hit it simultaneously
    file_put_contents($LOG_FILE, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
}

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

/**
 * Loads dynamic application state (like cached classes and last_generated timestamp)
 * from a separate JSON file to avoid race conditions with static configuration.
 */
function loadState(string $file): array
{
    if (!file_exists($file)) {
        return ['classes' => [], 'last_generated' => 'Never'];
    }
    $json = file_get_contents($file);
    $state = json_decode($json, true);
    if (!is_array($state)) {
        return ['classes' => [], 'last_generated' => 'Never'];
    }
    return $state;
}

/**
 * Saves the dynamic application state to the JSON file.
 */
function saveState(string $file, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($file, $json) === false) {
        writeAppLog('Warning', "Failed to write to state file: $file");
    }
}

/**
 * Loads the manually saved webuntisdata.json and builds a flat dictionary 
 * mapping shortNames (e.g., 'DISI') to their long/display names.
 */
function loadExternalDataMap(string $file): array
{
    $mapping = [];
    if (!file_exists($file)) {
        return $mapping; // Fail gracefully if file hasn't been uploaded yet
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        writeAppLog('Warning', 'webuntisdata.json contains invalid JSON.');
        return $mapping;
    }

    // 1. Extract Teachers (Nested inside 'teacher' object, prioritize displayName for full name)
    if (!empty($data['teachers'])) {
        foreach ($data['teachers'] as $tNode) {
            $t = $tNode['teacher'] ?? [];
            $short = trim($t['shortName'] ?? '');
            $display = trim($t['displayName'] ?? $t['longName'] ?? '');
            
            if ($short !== '' && $display !== '' && $short !== $display) {
                $mapping[$short] = $display;
            }
        }
    }

    // 2. Extract Departments, Rooms, Subjects, Classes (Flat structures)
    $standardTypes = ['departments', 'rooms', 'subjects', 'classes'];
    foreach ($standardTypes as $type) {
        if (!empty($data[$type])) {
            foreach ($data[$type] as $item) {
                $short = trim($item['shortName'] ?? '');
                $long = trim($item['longName'] ?? $item['displayName'] ?? '');
                
                if ($short !== '' && $long !== '' && $short !== $long) {
                    $mapping[$short] = $long;
                }
            }
        }
    }

    return $mapping;
}

// ---------------------------------------------------------------------------
// WebUntis API Fetching
// ---------------------------------------------------------------------------

/**
 * Safely fetches data from a URL using cURL instead of file_get_contents,
 * providing accurate error messages on failure without using the @ suppressor.
 */
function safeApiRequest(string $url, int $timeout = 10): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("Failed to initialize cURL.");
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WebUntis-ICS-Sync/1.0');
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Network request failed: " . $error);
    }
    if ($httpCode >= 400) {
        throw new RuntimeException("WebUntis API returned HTTP error $httpCode for $url");
    }
    
    return $response;
}

function fetchClassesFromApi(string $server): array
{
    $url = sprintf('https://%s/WebUntis/api/public/timetable/weekly/pageconfig?type=1', $server);
    $body = safeApiRequest($url, 10);
    
    $data = json_decode($body, true);
    $classes = [];
    
    if (isset($data['data']['elements']) && is_array($data['data']['elements'])) {
        foreach ($data['data']['elements'] as $el) {
            $name = trim($el['name'] ?? 'Unknown');
            $longName = trim($el['longName'] ?? '');
            
            // Deduplication: Only append longName if it differs from the short name
            if ($longName !== '' && $longName !== $name) {
                $classes[$el['id']] = "$name ($longName)";
            } else {
                $classes[$el['id']] = $name;
            }
        }
    }
    
    asort($classes);
    return $classes;
}

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
    try {
        $body = safeApiRequest($url, 15);
    } catch (RuntimeException $e) {
        throw new RuntimeException('Failed to fetch week of ' . $mondayDate->format('Y-m-d') . ': ' . $e->getMessage());
    }
    return json_decode($body, true) ?? [];
}

// ---------------------------------------------------------------------------
// Date & Parsing Helpers
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

/**
 * Resolves the name of an element (Teacher, Subject, Room).
 * Priority: 1. webuntisdata.json mapping | 2. API LongName | 3. API ShortName
 */
function namesForType(array $periodElements, array $lookup, int $type, array $externalMapping): array
{
    $names = [];
    foreach ($periodElements as $pe) {
        if (($pe['type'] ?? null) !== $type) continue;
        
        $key = $type . ':' . $pe['id'];
        if (isset($lookup[$key])) {
            $el = $lookup[$key];
            
            $shortName = trim((string)($el['name'] ?? ''));
            $longName = trim((string)($el['longName'] ?? ''));
            
            // 1. External Dictionary lookup (webuntisdata.json overrides everything)
            if ($shortName !== '' && isset($externalMapping[$shortName])) {
                $names[] = $externalMapping[$shortName];
            } 
            // 2. Public API longName fallback (only if it differs from the shortname)
            elseif ($longName !== '' && $longName !== $shortName) {
                $names[] = $longName;
            } 
            // 3. Absolute fallback to shortname
            elseif ($shortName !== '') {
                $names[] = $shortName;
            } else {
                $names[] = 'id:' . $pe['id'];
            }
        }
    }
    return $names;
}

// ---------------------------------------------------------------------------
// ICS & Event Merging Logic
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
        
        // Strip out the room capacity (e.g., "(180)") from the raw room string
        $rawRoom = implode(', ', $lesson['room']);
        $room = trim(preg_replace('/\s*\(\d+\)\s*/', ' ', $rawRoom));
        
        $detailParts = [];
        
        // Build the cleaned description string
        if ($teacher) {
            $detailParts[] = $teacher; // Teacher name directly, without "Lkr: "
        }
        if ($room) {
            $detailParts[] = "Lok: $room"; // Cleaned room name
        }
        if (!empty($lesson['subst_text'])) {
            $detailParts[] = trim($lesson['subst_text']);
        }
        // Note: $lesson['lesson_text'] (containing "DUUR: ...") is intentionally omitted here
        
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

class IcsBuilder 
{
    private array $lines = [];
    
    public function __construct(string $calendarName, string $timezone, string $lastGeneratedUtc) 
    {
        $this->addLine('BEGIN:VCALENDAR');
        $this->addLine('VERSION:2.0');
        $this->addLine('PRODID:-//hofmans.be//WebUntis Sync//NL');
        $this->addLine('CALSCALE:GREGORIAN');
        $this->addLine('METHOD:PUBLISH');
        $this->addProperty('X-WR-CALNAME', $calendarName);
        $this->addProperty('X-WR-TIMEZONE', $timezone);
        $this->addProperty('X-LAST-GENERATED', $lastGeneratedUtc);
    }
    
    public function addEvent(string $uid, string $dtStamp, string $dtStart, string $dtEnd, string $summary, string $description): void 
    {
        $this->addLine('BEGIN:VEVENT');
        $this->addProperty('UID', $uid);
        $this->addProperty('DTSTAMP', $dtStamp);
        $this->addProperty('DTSTART', $dtStart);
        $this->addProperty('DTEND', $dtEnd);
        $this->addProperty('SUMMARY', $summary);
        if ($description !== '') {
            $this->addProperty('DESCRIPTION', $description);
        }
        $this->addLine('END:VEVENT');
    }
    
    public function build(): string 
    {
        $this->addLine('END:VCALENDAR');
        return implode("\r\n", $this->lines) . "\r\n";
    }
    
    private function addProperty(string $key, string $value): void 
    {
        $escaped = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $value);
        $line = $key . ':' . $escaped;
        $this->lines[] = $this->fold($line);
    }
    
    private function addLine(string $line): void 
    {
        $this->lines[] = $this->fold($line);
    }
    
    private function fold(string $line): string 
    {
        $result = '';
        while (strlen($line) > 75) {
            $chunk = mb_strcut($line, 0, 75, 'UTF-8');
            $result .= $chunk . "\r\n ";
            $line = substr($line, strlen($chunk));
        }
        $result .= $line;
        return $result;
    }
}

function runSync(array &$config, string $configFile, array &$state, string $stateFile): array
{
    global $EXTERNAL_DATA_FILE;
    $logs = [];
    $allPeriods = [];
    $elementsLookup = [];
    $lessons = [];

    try {
        writeAppLog('ICS generated', 'Started sync for class ID: ' . $config['class_id']);
        
        // Load the manual override dictionary
        $externalMapping = loadExternalDataMap($EXTERNAL_DATA_FILE);
        if (!empty($externalMapping)) {
            $logs[] = "Loaded " . count($externalMapping) . " external mappings from webuntisdata.json";
        }
        
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
                'subject'     => namesForType($periodElements, $elementsLookup, ELEMENT_TYPE_SUBJECT, $externalMapping),
                'teacher'     => namesForType($periodElements, $elementsLookup, ELEMENT_TYPE_TEACHER, $externalMapping),
                'room'        => namesForType($periodElements, $elementsLookup, ELEMENT_TYPE_ROOM, $externalMapping),
                'cell_state'  => $cellState,
                'subst_text'  => trim($p['substText'] ?? ''),
                'period_text' => trim($p['periodText'] ?? ''),
                'lesson_text' => trim($p['lessonText'] ?? ''),
            ];
        }

        $events = processAndMergeEvents($lessons);
        $logs[] = sprintf("Parsed %d lessons, excluded %d cancelled.", count($lessons), $cancelledCount);
        $logs[] = sprintf("Merged %d raw periods into %d calendar events.", count($lessons), count($events));

        $utc = new DateTimeZone('UTC');
        $nowUtc = (new DateTimeImmutable('now', $utc))->format('Ymd\THis\Z');

        $state['last_generated'] = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        saveState($stateFile, $state);

        $ics = new IcsBuilder($config['calendar_name'], $tz->getName(), $nowUtc);

        foreach ($events as $event) {
            $startLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i', $event['date'] . ' ' . $event['start_time'], $tz);
            $endLocal   = DateTimeImmutable::createFromFormat('Y-m-d H:i', $event['date'] . ' ' . $event['end_time'], $tz);
            $startUtc = $startLocal->setTimezone($utc)->format('Ymd\THis\Z');
            $endUtc   = $endLocal->setTimezone($utc)->format('Ymd\THis\Z');

            $uidSource = $event['date'] . $event['start_time'] . $event['end_time'] . $event['subject'];
            $uid = md5($uidSource) . '@hofmans.be';

            $descriptionParts = [];
            
            foreach ($event['details'] as $idx => $detailLine) {
                // Lines are cleanly appended without the "- " prefix
                $descriptionParts[] = $detailLine;
            }
            
            // Append cell state if it's an unusual state (e.g. EXAM or SUBSTITUTION)
            if ($event['cell_state'] !== 'STANDARD') {
                $descriptionParts[] = "State: {$event['cell_state']}";
            }
            
            $description = implode("\n", $descriptionParts);

            $ics->addEvent($uid, $nowUtc, $startUtc, $endUtc, $event['subject'], $description);
        }
        
        $icsContent = $ics->build();
        
        $tmpPath = __DIR__ . '/' . $config['output_path'] . '.tmp';
        $finalPath = __DIR__ . '/' . $config['output_path'];
        
        file_put_contents($tmpPath, $icsContent);
        rename($tmpPath, $finalPath);

        $logs[] = "Wrote ICS to " . $finalPath;
        $logMessage = 'Success: ' . count($events) . ' events written.';
        if (count($events) > 0) {
            $first = $events[0];
            $last = $events[count($events) - 1];
            $logMessage .= sprintf(' (First: %s %s, Last: %s %s)', 
                $first['date'], $first['start_time'], 
                $last['date'], $last['start_time']
            );
        }
        writeAppLog('ICS generated', $logMessage);

        return [
            'success' => true,
            'status'  => 'OK',
            'events'  => $events,
            'logs'    => $logs,
        ];
    } catch (Throwable $e) {
        writeAppLog('ICS generated', 'Error: ' . $e->getMessage());
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
// Execution Entrypoint & Routing
// ---------------------------------------------------------------------------

$config = loadConfig($CONFIG_FILE);
$state = loadState($STATE_FILE);
$isCli = (php_sapi_name() === 'cli');

// --- 1. CLI / Cron Execution ---
if ($isCli) {
    $result = runSync($config, $CONFIG_FILE, $state, $STATE_FILE);
    if (!$result['success']) {
        fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), "ERROR: " . $result['error'] . "\n");
        exit(1);
    }
    echo "Sync complete. Status: OK\n";
    exit(0);
}

// --- 2. External Calendar Sync Endpoint (?feed=1) ---
if (isset($_GET['feed'])) {
    $path = __DIR__ . '/' . $config['output_path'];
    if (file_exists($path)) {
        // Capture identity of the bot/app pulling the data
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown User-Agent';
        
        writeAppLog('ICS remote sync', "IP: $ip | Agent: $userAgent");
        
        // Output the calendar file directly to the requesting app
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    } else {
        http_response_code(404);
        exit("Calendar file not generated yet.");
    }
}

// --- 3. Web UI Execution ---
$message = '';
$result = null;

if (empty($state['classes'])) {
    try {
        $state['classes'] = fetchClassesFromApi($config['server']);
        saveState($STATE_FILE, $state);
        writeAppLog('State changed', 'Auto-fetched initial class list');
    } catch (Exception $e) {
        $message = "Could not fetch initial classes: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['refresh_classes'])) {
        try {
            $state['classes'] = fetchClassesFromApi($config['server']);
            saveState($STATE_FILE, $state);
            writeAppLog('State changed', 'Refreshed class list from WebUntis');
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
        
        writeAppLog('Config changed', json_encode($config));
        
        $message = "Settings saved successfully.";
    } elseif (isset($_POST['generate_ics'])) {
        $result = runSync($config, $CONFIG_FILE, $state, $STATE_FILE);
        $message = "ICS Calendar generated successfully.";
    }
}

if (isset($_GET['download'])) {
    $path = __DIR__ . '/' . $config['output_path'];
    if (file_exists($path)) {
        writeAppLog('ICS downloaded', 'Manual web UI download');
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
    <title>WebUntis Sync Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
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
<body class="bg-slate-100 text-slate-800 min-h-screen p-4 md:p-8 font-sans">

    <div class="max-w-5xl mx-auto space-y-6">
        
        <!-- Header -->
        <header class="flex justify-between items-center bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <div>
                <h1 class="text-2xl font-black text-slate-900 flex items-center gap-3">
                    <i data-lucide="calendar-sync" class="w-8 h-8 text-blue-600"></i> WebUntis Sync Dashboard
                </h1>
                <p class="text-sm text-slate-500 mt-1 font-mono">Offline Mapping Engine</p>
            </div>
            <a href="?download=1" class="hidden md:flex items-center gap-2 px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-lg shadow transition-colors">
                <i data-lucide="download" class="w-4 h-4"></i> Download ICS
            </a>
        </header>

        <?php if ($message): ?>
            <div class="p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 font-bold flex items-center gap-3 shadow-sm">
                <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            
            <!-- Config Card -->
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                <h2 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i data-lucide="settings" class="w-5 h-5 text-slate-400"></i> Configuration
                </h2>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Target Class Group</label>
                        <input type="text" id="classSearch" placeholder="Filter classes..." onkeyup="filterClasses()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded mb-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <select name="class_id" id="classSelect" class="w-full px-3 py-2 border border-slate-200 rounded text-sm focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-700">
                            <?php foreach ($state['classes'] as $id => $name): ?>
                                <option value="<?= $id ?>" <?= $id == $config['class_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="refresh_classes" value="1" class="mt-2 text-xs font-bold text-blue-600 hover:text-blue-800 flex items-center gap-1">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> Force Refresh Class List
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Months Before</label>
                            <input type="number" name="months_before" min="0" max="12" value="<?= htmlspecialchars((string)$config['months_before']) ?>" class="w-full px-3 py-2 border border-slate-200 rounded text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Months After</label>
                            <input type="number" name="months_after" min="0" max="12" value="<?= htmlspecialchars((string)$config['months_after']) ?>" class="w-full px-3 py-2 border border-slate-200 rounded text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Output Calendar Name</label>
                        <input type="text" name="calendar_name" value="<?= htmlspecialchars($config['calendar_name']) ?>" class="w-full px-3 py-2 border border-slate-200 rounded text-sm font-bold text-slate-700 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>

                    <div class="pt-2 border-t border-slate-100">
                        <button type="submit" name="save_settings" value="1" class="w-full py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded shadow transition-colors flex justify-center items-center gap-2">
                            <i data-lucide="save" class="w-4 h-4"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Execution Card -->
            <div class="bg-indigo-50 p-6 rounded-xl shadow-sm border border-indigo-100 flex flex-col justify-between">
                <div>
                    <h2 class="text-lg font-bold text-indigo-900 mb-4 flex items-center gap-2">
                        <i data-lucide="activity" class="w-5 h-5 text-indigo-500"></i> Engine Status
                    </h2>
                    
                    <div class="bg-white p-4 rounded border border-indigo-100 shadow-sm mb-6 flex justify-between items-center">
                        <div>
                            <span class="block text-xs font-bold text-indigo-400 uppercase tracking-wider mb-1">Last Generated</span>
                            <span class="font-mono text-indigo-900 font-bold text-lg">
                                <?= htmlspecialchars($state['last_generated'] ?? 'Never') ?>
                            </span>
                        </div>
                        <?php if (file_exists($EXTERNAL_DATA_FILE)): ?>
                            <div class="text-right">
                                <span class="block text-xs font-bold text-emerald-500 uppercase tracking-wider mb-1">Mapping File</span>
                                <span class="text-emerald-700 font-bold flex items-center justify-end gap-1"><i data-lucide="check" class="w-4 h-4"></i> Active</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-3">
                    <form method="POST">
                        <button type="submit" name="generate_ics" value="1" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-lg rounded shadow-lg transition-transform active:scale-95 flex justify-center items-center gap-2">
                            <i data-lucide="zap" class="w-6 h-6 text-indigo-200"></i> GENERATE ICS NOW
                        </button>
                    </form>
                    <a href="?download=1" class="md:hidden flex justify-center items-center gap-2 w-full py-3 bg-slate-800 text-white font-bold rounded shadow">
                        <i data-lucide="download" class="w-4 h-4"></i> Download ICS
                    </a>
                </div>
            </div>
        </div>

        <?php if ($result !== null): ?>
            <!-- Results Dashboard -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="p-4 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
                    <h2 class="font-bold text-slate-800 flex items-center gap-2">
                        <i data-lucide="file-check-2" class="w-5 h-5 text-slate-400"></i> Generation Results
                    </h2>
                    <span class="px-3 py-1 text-xs font-black uppercase tracking-wider rounded-full <?= $result['success'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>">
                        <?= $result['status'] ?>
                    </span>
                </div>

                <?php if (!$result['success']): ?>
                    <div class="p-6 text-red-600 font-bold font-mono">
                        ERROR: <?= htmlspecialchars($result['error']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($result['logs'])): ?>
                    <div class="bg-slate-800 p-4 font-mono text-xs text-green-400 space-y-1">
                        <?php foreach ($result['logs'] as $log): ?>
                            <div><?= htmlspecialchars($log) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($result['events'])): ?>
                    <div class="overflow-x-auto border-t border-slate-200">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-slate-500 font-bold text-xs uppercase tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="px-6 py-3">Date & Time</th>
                                    <th class="px-6 py-3">Subject</th>
                                    <th class="px-6 py-3">Parsed Details</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($result['events'] as $event): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-6 py-3 font-mono text-slate-600 whitespace-nowrap">
                                            <?= htmlspecialchars($event['date']) ?><br>
                                            <span class="text-xs font-bold text-slate-400"><?= htmlspecialchars($event['start_time']) ?> - <?= htmlspecialchars($event['end_time']) ?></span>
                                        </td>
                                        <td class="px-6 py-3">
                                            <strong class="text-slate-800"><?= htmlspecialchars($event['subject']) ?></strong><br>
                                            <span class="text-[10px] uppercase font-bold text-slate-400"><?= htmlspecialchars($event['cell_state']) ?></span>
                                        </td>
                                        <td class="px-6 py-3 text-xs text-slate-600 font-mono">
                                            <?php foreach ($event['details'] as $line): ?>
                                                <div class="truncate max-w-sm" title="<?= htmlspecialchars($line) ?>">&bull; <?= htmlspecialchars($line) ?></div>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Footer / Logs View -->
        <div class="flex justify-between items-center text-xs font-bold text-slate-400 pb-8 mt-8 border-t border-slate-200 pt-6">
            <p>Hofmans WebUntis Sync Module</p>
            <div class="flex gap-4">
                <a href="?feed=1" target="_blank" class="flex items-center gap-1 hover:text-slate-600 transition-colors" title="Use this link for automated apps like Google Calendar.">
                    <i data-lucide="link" class="w-4 h-4"></i> Proxy Sync URL
                </a>
                <a href="<?= htmlspecialchars(basename($LOG_FILE)) ?>" target="_blank" class="flex items-center gap-1 hover:text-slate-600 transition-colors">
                    <i data-lucide="terminal" class="w-4 h-4"></i> View driesap.log
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", () => lucide.createIcons());
    </script>
</body>
</html>