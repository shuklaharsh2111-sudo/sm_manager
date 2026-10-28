<?php
/*
|--------------------------------------------------------------------------
| FATHOM HELPERS (server-side key, automatic sync)
|--------------------------------------------------------------------------
| The API key lives only on the server (config/fathom.php or the
| FATHOM_API_KEY environment variable). Nothing about it is shown in the UI.
|
| Finished meetings are pulled from Fathom (recording + AI summary + action
| items) and saved in `sme_meeting_recordings`. A recording is assigned to the
| SME whose scheduled meeting has the same date and subject.
| Tables are created automatically (see fathom_migration.sql).
*/

const FATHOM_API_BASE = 'https://api.fathom.ai/external/v1';
const FATHOM_AUTO_SYNC_SECONDS = 300;

function fathomEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fathomApiKey(): string
{
    $env = getenv('FATHOM_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    $file = __DIR__ . '/../config/fathom.php';
    if (is_file($file)) {
        $cfg = include $file;
        if (is_array($cfg) && !empty($cfg['api_key'])) {
            return trim((string) $cfg['api_key']);
        }
    }
    return '';
}

function fathomEnsureSchema(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `sme_fathom_sync` (
          `id` tinyint(4) NOT NULL,
          `last_synced_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `sme_meeting_recordings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `sme_user_id` int(11) NOT NULL,
          `recording_id` varchar(64) NOT NULL,
          `meeting_id` int(11) DEFAULT NULL,
          `title` varchar(255) NOT NULL DEFAULT '',
          `started_at` datetime DEFAULT NULL,
          `ended_at` datetime DEFAULT NULL,
          `recording_url` varchar(2048) NOT NULL DEFAULT '',
          `share_url` varchar(2048) NOT NULL DEFAULT '',
          `summary` mediumtext DEFAULT NULL,
          `action_items` mediumtext DEFAULT NULL,
          `invitees` mediumtext DEFAULT NULL,
          `synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_user_recording` (`sme_user_id`,`recording_id`),
          KEY `idx_user_started` (`sme_user_id`,`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $ready = true;
    foreach (['sme_fathom_sync', 'sme_meeting_recordings'] as $table) {
        $check = $conn->query("SHOW TABLES LIKE '" . $table . "'");
        if (!($check instanceof mysqli_result) || $check->num_rows === 0) {
            $ready = false;
            error_log('Fathom: table ' . $table . ' is missing. Run fathom_migration.sql.');
        }
        if ($check instanceof mysqli_result) {
            $check->free();
        }
    }
    return $ready;
}

/** GET a Fathom endpoint. Returns [httpStatus, decodedJsonOrNull, errorString]. */
function fathomRequest(string $path, array $query = []): array
{
    $apiKey = fathomApiKey();
    if ($apiKey === '') {
        return [0, null, 'Fathom is not configured on the server.'];
    }
    if (!function_exists('curl_init')) {
        return [0, null, 'The PHP cURL extension is not enabled.'];
    }
    $url = FATHOM_API_BASE . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $apiKey, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) {
        return [0, null, $error !== '' ? $error : 'Network error.'];
    }
    $json = json_decode((string) $body, true);
    return [$status, is_array($json) ? $json : null, ''];
}

function fathomDate(?string $iso): ?string
{
    if (!$iso) {
        return null;
    }
    try {
        $d = new DateTimeImmutable($iso);
        return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function fathomLastSync(mysqli $conn): ?int
{
    $res = $conn->query('SELECT last_synced_at FROM sme_fathom_sync WHERE id = 1');
    $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    return ($row && $row['last_synced_at']) ? (int) strtotime((string) $row['last_synced_at']) : null;
}

/** Sync only if the last run was more than FATHOM_AUTO_SYNC_SECONDS ago. */
function fathomAutoSync(mysqli $conn): void
{
    if (fathomApiKey() === '') {
        return;
    }
    $last = fathomLastSync($conn);
    if ($last === null || (time() - $last) >= FATHOM_AUTO_SYNC_SECONDS) {
        fathomSync($conn, 2);
    }
}

/**
 * Pulls recent recordings (summary + action items) and saves the ones that
 * belong to a scheduled SME meeting. Returns ['ok','message','count'].
 */
function fathomSync(mysqli $conn, int $maxPages = 5): array
{
    if (fathomApiKey() === '') {
        return ['ok' => false, 'message' => 'Fathom is not configured on the server.', 'count' => 0];
    }
    // Stamp first so parallel page loads do not all hit the API.
    $conn->query('INSERT INTO sme_fathom_sync (id, last_synced_at) VALUES (1, NOW()) ON DUPLICATE KEY UPDATE last_synced_at = NOW()');

    $upsert = $conn->prepare(
        'INSERT INTO sme_meeting_recordings
            (sme_user_id, recording_id, meeting_id, title, started_at, ended_at,
             recording_url, share_url, summary, action_items, invitees)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            meeting_id = VALUES(meeting_id), title = VALUES(title),
            started_at = VALUES(started_at), ended_at = VALUES(ended_at),
            recording_url = VALUES(recording_url), share_url = VALUES(share_url),
            summary = VALUES(summary), action_items = VALUES(action_items), invitees = VALUES(invitees)'
    );
    $findMeeting = $conn->prepare(
        'SELECT id, sme_user_id FROM sme_meetings
         WHERE meeting_date = ? AND LOWER(TRIM(subject)) = LOWER(TRIM(?))
         LIMIT 1'
    );
    if (!$upsert || !$findMeeting) {
        return ['ok' => false, 'message' => 'Unable to prepare the sync queries.', 'count' => 0];
    }

    $count = 0;
    $cursor = null;
    for ($page = 0; $page < $maxPages; $page++) {
        $query = ['include_summary' => 'true', 'include_action_items' => 'true'];
        if ($cursor) {
            $query['cursor'] = $cursor;
        }
        [$status, $json, $error] = fathomRequest('/meetings', $query);
        if ($status === 401 || $status === 403) {
            error_log('Fathom: the server API key was rejected.');
            return ['ok' => false, 'message' => 'Fathom rejected the server API key.', 'count' => $count];
        }
        if ($status === 429) {
            return ['ok' => $count > 0, 'message' => 'Fathom rate limit reached. Try again in a minute.', 'count' => $count];
        }
        if ($status !== 200 || $json === null) {
            return ['ok' => $count > 0, 'message' => $error !== '' ? $error : 'Fathom returned HTTP ' . $status . '.', 'count' => $count];
        }

        foreach (($json['items'] ?? $json['meetings'] ?? []) as $item) {
            $recordingId = (string) ($item['recording_id'] ?? '');
            if ($recordingId === '') {
                continue;
            }
            $title = mb_substr((string) ($item['meeting_title'] ?? $item['title'] ?? 'Untitled meeting'), 0, 255);
            $started = fathomDate($item['recording_start_time'] ?? $item['scheduled_start_time'] ?? $item['created_at'] ?? null);
            $ended = fathomDate($item['recording_end_time'] ?? $item['scheduled_end_time'] ?? null);
            if (!$started) {
                continue;
            }

            // Only keep recordings that belong to a scheduled SME meeting.
            $day = substr($started, 0, 10);
            $findMeeting->bind_param('ss', $day, $title);
            $findMeeting->execute();
            $meeting = $findMeeting->get_result()->fetch_assoc();
            if (!$meeting) {
                continue;
            }
            $smeId = (int) $meeting['sme_user_id'];
            $meetingId = (int) $meeting['id'];

            $url = mb_substr((string) ($item['url'] ?? ''), 0, 2048);
            $share = mb_substr((string) ($item['share_url'] ?? ''), 0, 2048);
            $summary = $item['default_summary']['markdown_formatted'] ?? $item['summary'] ?? null;
            $summary = is_string($summary) ? $summary : null;

            $actions = [];
            foreach (($item['action_items'] ?? []) as $a) {
                $actions[] = [
                    'text'      => (string) ($a['description'] ?? ''),
                    'assignee'  => (string) ($a['assignee']['name'] ?? ''),
                    'completed' => !empty($a['completed']),
                ];
            }
            $actionsJson = $actions ? json_encode($actions, JSON_UNESCAPED_UNICODE) : null;

            $invitees = [];
            foreach (($item['calendar_invitees'] ?? []) as $p) {
                $name = (string) ($p['name'] ?? $p['email'] ?? '');
                if ($name !== '') {
                    $invitees[] = $name;
                }
            }
            $inviteesJson = $invitees ? json_encode($invitees, JSON_UNESCAPED_UNICODE) : null;

            $upsert->bind_param(
                'isissssssss',
                $smeId, $recordingId, $meetingId, $title, $started, $ended,
                $url, $share, $summary, $actionsJson, $inviteesJson
            );
            if ($upsert->execute()) {
                $count++;
            }
        }

        $cursor = $json['next_cursor'] ?? null;
        if (!$cursor) {
            break;
        }
    }
    $upsert->close();
    $findMeeting->close();

    return [
        'ok'      => true,
        'message' => $count === 0 ? 'No new recordings for your meetings yet.' : 'Synced ' . $count . ' recording' . ($count === 1 ? '' : 's') . '.',
        'count'   => $count,
    ];
}

/* ---------- Rendering ---------- */

/** Minimal, safe Markdown -> HTML for Fathom summaries (headings, bullets, bold, https links). */
function fathomMarkdown(string $md): string
{
    $lines = preg_split('/\R/u', trim($md)) ?: [];
    $html = '';
    $inList = false;
    $inline = static function (string $text): string {
        $text = fathomEsc($text);
        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        return preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
            static fn($m) => '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
            $text
        );
    };
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (preg_match('/^\s*[-*]\s+(.*)$/u', $line, $m)) {
            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
        if ($line === '') {
            continue;
        }
        if (preg_match('/^#{1,6}\s+(.*)$/u', $line, $m)) {
            $html .= '<h5>' . $inline($m[1]) . '</h5>';
        } else {
            $html .= '<p>' . $inline($line) . '</p>';
        }
    }
    return $html . ($inList ? '</ul>' : '');
}

function fathomSafeUrl(string $url): string
{
    return preg_match('~^https://~i', $url) ? $url : '';
}

/** One recording card (HTML string). $row = a sme_meeting_recordings row (+ meeting_subject, comp_name). */
function fathomRenderCard(array $row): string
{
    $e = 'fathomEsc';
    $started = !empty($row['started_at']) ? strtotime((string) $row['started_at']) : false;
    $ended = !empty($row['ended_at']) ? strtotime((string) $row['ended_at']) : false;
    $when = $started ? date('d M Y, h:i A', $started) : 'Date unknown';
    $duration = ($started && $ended && $ended > $started) ? max(1, (int) round(($ended - $started) / 60)) . ' min' : '';

    $html = '<article class="rec-card" data-recording-id="' . (int) $row['id'] . '">';
    $html .= '<header class="rec-card-head"><div>'
        . '<h3>' . $e($row['title']) . '</h3>'
        . '<p class="rec-meta">' . $e($when) . ($duration !== '' ? ' &middot; ' . $e($duration) : '');
    if (!empty($row['comp_name'])) {
        $html .= ' &middot; ' . $e($row['comp_name']);
    }
    $html .= '</p></div><div class="rec-links">';
    $link = fathomSafeUrl((string) ($row['share_url'] ?: $row['recording_url']));
    if ($link !== '') {
        $html .= '<a class="rec-btn" href="' . $e($link) . '" target="_blank" rel="noopener noreferrer">Watch recording</a>';
    }
    $html .= '</div></header>';

    $invitees = json_decode((string) ($row['invitees'] ?? ''), true);
    if (is_array($invitees) && $invitees) {
        $html .= '<p class="rec-people"><span>Attendees:</span> ' . $e(implode(', ', array_slice($invitees, 0, 12))) . '</p>';
    }

    $html .= '<details class="rec-details" open><summary>Summary</summary><div class="rec-body">';
    $html .= !empty($row['summary'])
        ? fathomMarkdown((string) $row['summary'])
        : '<p class="rec-muted">Fathom is still preparing this summary. Sync again in a few minutes.</p>';
    $html .= '</div></details>';

    $actions = json_decode((string) ($row['action_items'] ?? ''), true);
    if (is_array($actions) && $actions) {
        $html .= '<details class="rec-details"><summary>Action items (' . count($actions) . ')</summary><ul class="rec-actions">';
        foreach ($actions as $a) {
            $html .= '<li class="' . (!empty($a['completed']) ? 'is-done' : '') . '">' . $e($a['text'] ?? '')
                . (!empty($a['assignee']) ? ' <span>' . $e($a['assignee']) . '</span>' : '') . '</li>';
        }
        $html .= '</ul></details>';
    }

    $html .= '<details class="rec-details rec-transcript" data-load-transcript="1"><summary>Transcript</summary>'
        . '<div class="rec-body rec-transcript-body"><p class="rec-muted">Open to load the transcript.</p></div></details>';

    return $html . '</article>';
}
