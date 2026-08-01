<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

/**
 * driesap.php
 *
 * Fetches the class timetable for entity 4014 ("1EM3") from WebUntis's public
 * (no-login-needed) weekly timetable REST endpoint.
 *
 * Can be run via CLI (cron) or accessed directly in a web browser.
 */

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$SERVER       = 'ap.webuntis.com';
$CLASS_ID     = 4014;
$OUTPUT_PATH  = __DIR__ . '/driesap.ics';
$TIMEZONE_STR = 'Europe/Brussels';
$CALNAME      = 'Dries - Lessenrooster';

const ELEMENT_TYPE_TEACHER = 2;
const ELEMENT_TYPE_SUBJECT = 3;
const ELEMENT_TYPE_ROOM    = 4;

const KNOWN_CANCEL_STATES = ['CANCEL'];
const KNOWN_NORMAL_STATES = [
    'STANDARD', 'SUBSTITUTION', 'ROOMSUBSTITUTION', 'SHIFT', 'ADDITIONAL', 'EXAM',
];

// ---------------------------------------------------------------------------
// Date range: 1st of previous month -> last day of (current month + 2)
// ---------------------------------------------------------------------------

$tz = new DateTimeZone($TIMEZONE_STR);
$today = new DateTimeImmutable('today', $tz);

$rangeStart = $today->modify('first day of -1 month');
$rangeEnd   = $today->modify('last day of +2 month');

/**
 * @return DateTimeImmutable[] One entry per Monday of every ISO week overlapping [$start, $end]
 */
function mondaysCovering(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $mondays = [];
    $dow = (int)$start->format('N'); // 1 = Monday ... 7 = Sunday
    $current = $start->modify('-' . ($dow - 1) . ' days');
    while ($current <= $end) {
        $mondays[] = $current;
        $current = $current->modify('+7 days');
    }
    return $mondays;
}

// ---------------------------------------------------------------------------
// Fetching & Parsing Helpers
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

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON for week of ' . $mondayDate->format('Y-m-d'));
    }
    return $json;
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
            $names[] = $el['longName'] ?? $el['name'] ?? ('id:' . $pe['id']);
        } else {
            $names[] = 'unknown id:' . $pe['id'];
        }
    }
    return $names;
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
 * Group lessons by exact group signature (Subject, Teacher, Room, Status),
 * then merge consecutive hours within each distinct group independently.
 */
function mergeGroupedConsecutive(array $lessons): array
{
    if (empty($lessons)) {
        return [];
    }

    $grouped = [];
    foreach ($lessons as $lesson) {
        $subj = $lesson['subject']; sort($subj);
        $teach = $lesson['teacher']; sort($teach);
        $rm    = $lesson['room'];    sort($rm);

        // Unique key representing one specific group/track for a given date
        $key = implode('||', [
            $lesson['date'],
            implode(',', $subj),
            implode(',', $teach),
            implode(',', $rm),
            (string)$lesson['cell_state'],
            (string)$lesson['subst_text'],
            (string)$lesson['period_text']
        ]);

        $grouped[$key][] = $lesson;
    }

    $allMerged = [];

    foreach ($grouped as $trackLessons) {
        // Sort chronologically within this specific group track
        usort($trackLessons, function ($a, $b) {
            return $a['start_time'] <=> $b['start_time'];
        });

        $mergedTrack = [];
        foreach ($trackLessons as $current) {
            if (empty($mergedTrack)) {
                $mergedTrack[] = $current;
                continue;
            }

            $lastIdx = count($mergedTrack) - 1;
            $last = $mergedTrack[$lastIdx];

            // Ignore exact duplicate time slots for the same group signature
            if ($current['start_time'] === $last['start_time'] && $current['end_time'] === $last['end_time']) {
                continue;
            }

            // Merge back-to-back hours within this group track
            if ($current['start_time'] === $last['end_time']) {
                $mergedTrack[$lastIdx]['end_time'] = $current['end_time'];
            } else {
                $mergedTrack[] = $current;
            }
        }

        foreach ($mergedTrack as $mEvent) {
            $allMerged[] = $mEvent;
        }
    }

    // Sort final calendar events by date and start time
    usort($allMerged, function ($a, $b) {
        return [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']];
    });

    return $allMerged;
}

// ---------------------------------------------------------------------------
// Pipeline & ICS logic
// ---------------------------------------------------------------------------

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

function parseEvents(
    array $allPeriods,
    array $elementsLookup,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd,
    array &$logs
): array {
    $lessons = [];
    $cancelledCount = 0;
    $rangeStartStr = $rangeStart->format('Y-m-d');
    $rangeEndStr = $rangeEnd->format('Y-m-d');

    foreach ($allPeriods as $p) {
        $cellState = $p['cellState'] ?? null;

        if (in_array($cellState, KNOWN_CANCEL_STATES, true)) {
            $cancelledCount++;
            continue;
        }

        if (!in_array($cellState, KNOWN_NORMAL_STATES, true)) {
            $logs[] = sprintf(
                "WARNING: unrecognized cellState '%s' on period id=%s date=%s - including it anyway.",
                (string)$cellState,
                (string)($p['id'] ?? '?'),
                (string)($p['date'] ?? '?')
            );
        }

        $lessonDate = formatDate((int)$p['date']);
        if ($lessonDate < $rangeStartStr || $lessonDate > $rangeEndStr) {
            continue;
        }

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

    $logs[] = sprintf("Parsed %d lessons, excluded %d cancelled.", count($lessons), $cancelledCount);

    $events = mergeGroupedConsecutive($lessons);
    $logs[] = sprintf("Merged %d raw periods into %d calendar events across distinct groups.", count($lessons), count($events));

    return $events;
}

function buildIcs(array $events, DateTimeZone $tz, string $calName): string
{
    $utc = new DateTimeZone('UTC');
    $nowUtc = (new DateTimeImmutable('now', $utc))->format('Ymd\THis\Z');

    $ics = [];
    $ics[] = 'BEGIN:VCALENDAR';
    $ics[] = 'VERSION:2.0';
    $ics[] = 'PRODID:-//hofmans.be//WebUntis Sync//NL';
    $ics[] = 'CALSCALE:GREGORIAN';
    $ics[] = 'METHOD:PUBLISH';
    $ics[] = foldLine('X-WR-CALNAME:' . icsEscape($calName));
    $ics[] = 'X-WR-TIMEZONE:' . $tz->getName();

    foreach ($events as $event) {
        $startLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i', $event['date'] . ' ' . $event['start_time'], $tz);
        $endLocal   = DateTimeImmutable::createFromFormat('Y-m-d H:i', $event['date'] . ' ' . $event['end_time'], $tz);
        $startUtc = $startLocal->setTimezone($utc)->format('Ymd\THis\Z');
        $endUtc   = $endLocal->setTimezone($utc)->format('Ymd\THis\Z');

        $subject = implode(', ', $event['subject']) ?: 'Les';
        $teacher = implode(', ', $event['teacher']);
        $room    = implode(', ', $event['room']);

        $descriptionParts = [];
        if ($teacher !== '') {
            $descriptionParts[] = "Leerkracht: $teacher";
        }
        if (!empty($event['subst_text'])) {
            $descriptionParts[] = $event['subst_text'];
        }
        if (!empty($event['period_text'])) {
            $descriptionParts[] = $event['period_text'];
        }
        if ($event['cell_state'] !== 'STANDARD') {
            $descriptionParts[] = "Status: {$event['cell_state']}";
        }
        $description = implode('\\n', array_map('icsEscape', $descriptionParts));

        $uidSource = $event['date'] . $event['start_time'] . $event['end_time'] . $subject . $teacher . $room;
        $uid = md5($uidSource) . '@hofmans.be';

        $ics[] = 'BEGIN:VEVENT';
        $ics[] = foldLine('UID:' . $uid);
        $ics[] = foldLine('DTSTAMP:' . $nowUtc);
        $ics[] = foldLine('DTSTART:' . $startUtc);
        $ics[] = foldLine('DTEND:' . $endUtc);
        $ics[] = foldLine('SUMMARY:' . icsEscape($subject));
        if ($room !== '') {
            $ics[] = foldLine('LOCATION:' . icsEscape($room));
        }
        if ($description !== '') {
            $ics[] = foldLine('DESCRIPTION:' . $description);
        }
        $ics[] = 'END:VEVENT';
    }

    $ics[] = 'END:VCALENDAR';

    return implode("\r\n", $ics) . "\r\n";
}

// ---------------------------------------------------------------------------
// Main Sync Runner
// ---------------------------------------------------------------------------

function runSync(): array
{
    global $SERVER, $CLASS_ID, $OUTPUT_PATH, $CALNAME, $rangeStart, $rangeEnd, $tz;

    $logs = [];
    $allPeriods = [];
    $elementsLookup = [];

    try {
        foreach (mondaysCovering($rangeStart, $rangeEnd) as $monday) {
            try {
                $data = fetchWeek($SERVER, $CLASS_ID, $monday);
            } catch (Throwable $e) {
                $logs[] = 'WARNING: ' . $e->getMessage();
                continue;
            }

            $resultData = $data['data']['result']['data'] ?? null;
            if (!is_array($resultData)) {
                $logs[] = 'WARNING: unexpected response shape for week of ' . $monday->format('Y-m-d');
                continue;
            }

            $periods = $resultData['elementPeriods'][(string)$CLASS_ID] ?? [];
            $allPeriods = array_merge($allPeriods, $periods);

            foreach ($resultData['elements'] ?? [] as $el) {
                $elementsLookup[$el['type'] . ':' . $el['id']] = $el;
            }
        }

        $events = parseEvents($allPeriods, $elementsLookup, $rangeStart, $rangeEnd, $logs);
        $icsContent = buildIcs($events, $tz, $CALNAME);

        $tmpPath = $OUTPUT_PATH . '.tmp';
        if (file_put_contents($tmpPath, $icsContent) === false) {
            throw new RuntimeException("Failed to write temporary file at $tmpPath");
        }
        if (!rename($tmpPath, $OUTPUT_PATH)) {
            throw new RuntimeException("Failed to save output file to $OUTPUT_PATH");
        }

        $logs[] = "Wrote ICS to $OUTPUT_PATH";

        return [
            'success' => true,
            'status'  => 'OK',
            'events'  => $events,
            'logs'    => $logs,
            'file'    => $OUTPUT_PATH,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'status'  => 'NOT OK',
            'error'   => $e->getMessage(),
            'events'  => [],
            'logs'    => $logs,
        ];
    }
}

// ---------------------------------------------------------------------------
// Execution Entrypoint (CLI vs Direct Download vs Browser HTML)
// ---------------------------------------------------------------------------

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    $result = runSync();
    foreach ($result['logs'] as $log) {
        fwrite(STDERR, $log . "\n");
    }
    if (!$result['success']) {
        fwrite(STDERR, "ERROR: " . ($result['error'] ?? 'Unknown error') . "\n");
        exit(1);
    }
} else {
    // Handle Direct Download Request
    if (isset($_GET['download'])) {
        $result = runSync();
        if ($result['success'] && file_exists($OUTPUT_PATH)) {
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($OUTPUT_PATH) . '"');
            header('Content-Length: ' . (string)filesize($OUTPUT_PATH));
            header('Cache-Control: max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($OUTPUT_PATH);
            exit;
        } else {
            http_response_code(500);
            echo "Error generating ICS file: " . htmlspecialchars($result['error'] ?? 'Unknown error');
            exit;
        }
    }

    // Render HTML View
    $result = runSync();
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>WebUntis Calendar Sync</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 2rem; background: #f8f9fa; color: #333; }
            h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
            .badge { display: inline-block; padding: 0.35rem 0.65rem; font-weight: bold; border-radius: 4px; color: #fff; }
            .badge-ok { background-color: #28a745; }
            .badge-not-ok { background-color: #dc3545; }
            .btn { display: inline-block; padding: 0.6rem 1.2rem; background-color: #007bff; color: #fff; text-decoration: none; border-radius: 4px; font-weight: bold; margin-top: 1rem; transition: background-color 0.2s; }
            .btn:hover { background-color: #0056b3; }
            .btn-disabled { background-color: #6c757d; pointer-events: none; opacity: 0.65; }
            .logs { background: #e9ecef; padding: 1rem; border-radius: 4px; font-family: monospace; margin: 1rem 0; }
            table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            th, td { padding: 0.75rem; border: 1px solid #dee2e6; text-align: left; }
            th { background-color: #f1f3f5; }
            tr:nth-child(even) { background-color: #f8f9fa; }
        </style>
    </head>
    <body>
        <h1>WebUntis Timetable Sync</h1>
        
        <p><strong>Status:</strong> 
            <span class="badge <?= $result['success'] ? 'badge-ok' : 'badge-not-ok'; ?>">
                <?= htmlspecialchars($result['status']); ?>
            </span>
        </p>

        <div>
            <a href="?download=1" class="btn <?= $result['success'] ? '' : 'btn-disabled'; ?>">
                📥 Download .ics File
            </a>
        </div>

        <?php if (!$result['success']): ?>
            <p style="color: red; margin-top: 1rem;"><strong>Error:</strong> <?= htmlspecialchars($result['error']); ?></p>
        <?php endif; ?>

        <h3>Logs</h3>
        <div class="logs">
            <?php foreach ($result['logs'] as $log): ?>
                <div><?= htmlspecialchars($log); ?></div>
            <?php endforeach; ?>
        </div>

        <h3>Calendar Items (<?= count($result['events']); ?>)</h3>
        <?php if (!empty($result['events'])): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Subject</th>
                        <th>Teacher</th>
                        <th>Room</th>
                        <th>State</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result['events'] as $event): ?>
                        <tr>
                            <td><?= htmlspecialchars($event['date']); ?></td>
                            <td><?= htmlspecialchars($event['start_time']); ?> - <?= htmlspecialchars($event['end_time']); ?></td>
                            <td><?= htmlspecialchars(implode(', ', $event['subject'])); ?></td>
                            <td><?= htmlspecialchars(implode(', ', $event['teacher'])); ?></td>
                            <td><?= htmlspecialchars(implode(', ', $event['room'])); ?></td>
                            <td><?= htmlspecialchars($event['cell_state']); ?></td>
                            <td><?= htmlspecialchars(trim(($event['subst_text'] ?? '') . ' ' . ($event['period_text'] ?? ''))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No calendar events found.</p>
        <?php endif; ?>
    </body>
    </html>
    <?php
}
