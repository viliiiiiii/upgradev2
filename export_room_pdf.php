<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_once __DIR__ . '/includes/export_tokens.php';

set_time_limit(120);

$roomId = (int)($_GET['room_id'] ?? 0);
$ttlDays = max(1, min(365, (int)($_GET['ttl'] ?? 30)));
$qrSize  = max(140, min(280, (int)($_GET['qr'] ?? 200)));

if ($roomId <= 0) {
    http_response_code(400);
    exit('Room is required.');
}

$pdo = get_pdo();
$roomStmt = $pdo->prepare(
    'SELECT r.id, r.room_number, r.label, r.building_id, b.name AS building_name
     FROM rooms r
     JOIN buildings b ON b.id = r.building_id
     WHERE r.id = ?
     LIMIT 1'
);
$roomStmt->execute([$roomId]);
$roomRow = $roomStmt->fetch(PDO::FETCH_ASSOC);

if (!$roomRow) {
    http_response_code(404);
    exit('Room not found.');
}

$roomNumber   = trim((string)($roomRow['room_number'] ?? ''));
$roomLabel    = trim((string)($roomRow['label'] ?? ''));
$buildingName = trim((string)($roomRow['building_name'] ?? ''));
if ($buildingName === '') {
    $buildingName = 'Building #' . (int)($roomRow['building_id'] ?? 0);
}

$roomHeading = $roomNumber !== '' ? '#' . $roomNumber : 'Room ' . (string)$roomId;
if ($roomLabel !== '') {
    $roomHeading .= ' · ' . $roomLabel;
}

$tasks    = room_tasks($roomId);
$grouped  = group_tasks_by_status($tasks);
$statusCounts   = ['open' => 0, 'in_progress' => 0, 'done' => 0];
$priorityCounts = [];
$latestTimestamp = null;

foreach ($tasks as $task) {
    $status = (string)($task['status'] ?? 'open');
    if (!isset($statusCounts[$status])) {
        $statusCounts[$status] = 0;
    }
    $statusCounts[$status]++;

    $priority = (string)($task['priority'] ?? '');
    if (!isset($priorityCounts[$priority])) {
        $priorityCounts[$priority] = 0;
    }
    $priorityCounts[$priority]++;

    $ts = $task['updated_at'] ?: ($task['created_at'] ?? null);
    if ($ts && ($latestTimestamp === null || $ts > $latestTimestamp)) {
        $latestTimestamp = $ts;
    }
}

$statusOrder   = ['open', 'in_progress', 'done'];
$priorityOrder = ['high', 'mid/high', 'mid', 'low/mid', 'low', ''];

foreach ($grouped as &$group) {
    usort($group, static function (array $a, array $b): int {
        $dueA = $a['due_date'] ?? null;
        $dueB = $b['due_date'] ?? null;
        if ($dueA && $dueB) {
            $cmp = strcmp($dueA, $dueB);
            if ($cmp !== 0) {
                return $cmp;
            }
        } elseif ($dueA && !$dueB) {
            return -1;
        } elseif (!$dueA && $dueB) {
            return 1;
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });
}
unset($group);

$taskIds = array_column($tasks, 'id');
$photoRows = $taskIds ? fetch_photos_for_tasks($taskIds) : [];
$baseUrl   = base_url_for_pdf();
$photoUrls = [];
$photoCount = 0;

foreach ($photoRows as $taskId => $rows) {
    $photoCount += count($rows);
    $urls = [];
    foreach ($rows as $row) {
        $urls[] = $baseUrl . photo_public_url($row, 1200);
        if (count($urls) >= 6) {
            break;
        }
    }
    if ($urls) {
        $photoUrls[(int)$taskId] = $urls;
    }
}

ensure_public_room_token_tables($pdo);
$tokenRow = fetch_valid_room_tokens($pdo, [$roomId]);
$tokenRow = $tokenRow[$roomId] ?? insert_room_token($pdo, $roomId, $ttlDays);
$token    = is_string($tokenRow['token']) ? $tokenRow['token'] : (string)$tokenRow['token'];
$roomLink = $baseUrl . '/public_room_photos.php?t=' . rawurlencode($token);
$roomQr   = qr_data_uri($roomLink, $qrSize);

$formatDate = static function (?string $value, string $format = 'M j, Y'): string {
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Exception $e) {
        return (string)$value;
    }
};
$formatDateTime = static function (?string $value) use ($formatDate): string {
    return $formatDate($value, 'M j, Y H:i');
};
$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$generatedAt  = $formatDateTime((new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
$latestUpdate = $latestTimestamp ? $formatDateTime($latestTimestamp) : '—';
$taskCount    = count($tasks);
$activeCount  = ($statusCounts['open'] ?? 0) + ($statusCounts['in_progress'] ?? 0);
$doneCount    = $statusCounts['done'] ?? 0;

ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Room export - <?php echo $h($roomHeading); ?></title>
<style>
  @page { size: A4; margin: 16mm 14mm 18mm 14mm; }
  body {
    font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
    font-size: 11px;
    color: #0f172a;
    background: #eef2ff;
    margin: 0;
  }
  .wrapper {
    display: flex;
    flex-direction: column;
    gap: 22px;
  }
  .panel {
    border-radius: 22px;
    border: 1px solid rgba(148,163,184,.2);
    background: linear-gradient(155deg, rgba(255,255,255,.96), rgba(225,234,255,.94));
    box-shadow: 0 26px 60px rgba(15,23,42,.18);
    padding: 28px 30px;
  }
  .room-overview {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    align-items: flex-start;
    justify-content: space-between;
  }
  .room-overview__copy {
    flex: 1 1 280px;
  }
  .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 10px;
    letter-spacing: .12em;
    text-transform: uppercase;
    background: rgba(59,130,246,.12);
    color: #1e3a8a;
    font-weight: 700;
  }
  .room-title {
    margin: 16px 0 6px 0;
    font-size: 28px;
    letter-spacing: .1em;
    text-transform: uppercase;
    font-weight: 800;
    color: #0b1d4d;
  }
  .room-subtitle {
    font-size: 13px;
    color: #334155;
    letter-spacing: .18em;
    text-transform: uppercase;
  }
  .meta-grid {
    margin-top: 20px;
    display: grid;
    gap: 12px 18px;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    font-size: 10px;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #475569;
  }
  .meta-grid strong {
    display: block;
    margin-top: 4px;
    font-size: 15px;
    color: #0f172a;
    letter-spacing: 0;
    text-transform: none;
  }
  .qr-block {
    flex: 0 0 auto;
    min-width: <?php echo (int)($qrSize + 60); ?>px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    text-align: center;
  }
  .qr-halo {
    padding: 16px;
    border-radius: 18px;
    background: linear-gradient(160deg, rgba(15,23,42,.08), rgba(148,163,184,.12));
    box-shadow: 0 16px 32px rgba(15,23,42,.15);
  }
  .qr-halo img {
    display: block;
    width: <?php echo (int)$qrSize; ?>px;
    height: <?php echo (int)$qrSize; ?>px;
  }
  .qr-caption {
    font-size: 9px;
    color: #475569;
    letter-spacing: .12em;
    text-transform: uppercase;
  }
  .qr-link {
    font-size: 9px;
    color: #64748b;
    word-break: break-all;
  }
  .insight-grid {
    margin-top: 24px;
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  }
  .insight-card {
    padding: 12px 14px;
    border-radius: 16px;
    border: 1px solid rgba(148,163,184,.18);
    background: #f8fbff;
    box-shadow: 0 10px 24px rgba(15,23,42,.08);
  }
  .insight-card small {
    display: block;
    font-size: 10px;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #64748b;
  }
  .insight-card strong {
    display: block;
    margin-top: 6px;
    font-size: 15px;
    color: #0f172a;
  }
  .insight-card span {
    display: block;
    margin-top: 2px;
    font-size: 10px;
    color: #475569;
    letter-spacing: .06em;
    text-transform: uppercase;
  }
  .section {
    border-radius: 20px;
    border: 1px solid rgba(148,163,184,.18);
    background: rgba(255,255,255,.94);
    box-shadow: 0 18px 42px rgba(15,23,42,.12);
    padding: 22px 26px;
  }
  .group + .group {
    margin-top: 18px;
  }
  .group-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    border-bottom: 1px solid rgba(148,163,184,.25);
    padding-bottom: 10px;
  }
  .group-title {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #0b1d4d;
  }
  .group-count {
    font-size: 11px;
    color: #475569;
    letter-spacing: .1em;
    text-transform: uppercase;
  }
  .task-card {
    margin-top: 14px;
    padding: 16px 18px;
    border-radius: 16px;
    border: 1px solid rgba(148,163,184,.2);
    background: linear-gradient(165deg, rgba(248,250,255,.98), rgba(232,240,255,.92));
    box-shadow: 0 16px 34px rgba(15,23,42,.1);
    page-break-inside: avoid;
  }
  .task-card header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .task-title {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: .02em;
  }
  .tag-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .tag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
  }
  .tag.status-open { background: rgba(59,130,246,.16); color: #1e3a8a; }
  .tag.status-in_progress { background: rgba(245,158,11,.18); color: #92400e; }
  .tag.status-done { background: rgba(16,185,129,.18); color: #065f46; }
  .tag.priority-high { background: rgba(239,68,68,.16); color: #b91c1c; }
  .tag.priority-midhigh { background: rgba(245,158,11,.16); color: #92400e; }
  .tag.priority-mid { background: rgba(234,179,8,.16); color: #b45309; }
  .tag.priority-lowmid { background: rgba(34,197,94,.16); color: #047857; }
  .tag.priority-low { background: rgba(45,212,191,.16); color: #0f766e; }
  .tag.priority-none { background: rgba(148,163,184,.22); color: #334155; }
  .task-meta {
    margin-top: 12px;
    display: grid;
    gap: 10px 16px;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    font-size: 10px;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #64748b;
  }
  .task-meta strong {
    display: block;
    margin-top: 4px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
    color: #0f172a;
  }
  .task-desc {
    margin-top: 12px;
    font-size: 11px;
    line-height: 1.5;
    color: #0f172a;
  }
  .photo-grid {
    margin-top: 14px;
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  }
  .photo-grid img {
    display: block;
    width: 100%;
    height: 120px;
    object-fit: cover;
    border-radius: 12px;
    box-shadow: 0 12px 24px rgba(15,23,42,.18);
  }
  .empty-state {
    margin: 18px 0 6px;
    padding: 18px;
    border-radius: 16px;
    border: 1px dashed rgba(148,163,184,.45);
    background: rgba(241,245,255,.88);
    color: #475569;
    text-align: center;
    font-size: 11px;
  }
</style>
</head>
<body>
  <div class="wrapper">
    <section class="panel">
      <div class="room-overview">
        <div class="room-overview__copy">
          <div class="badge">Room Export</div>
          <h1 class="room-title"><?php echo $h($roomHeading); ?></h1>
          <div class="room-subtitle"><?php echo $h($buildingName); ?></div>
          <div class="meta-grid">
            <div>Total tasks<strong><?php echo $h($taskCount); ?></strong></div>
            <div>Active tasks<strong><?php echo $h($activeCount); ?></strong></div>
            <div>Completed<strong><?php echo $h($doneCount); ?></strong></div>
            <div>Linked photos<strong><?php echo $h($photoCount); ?></strong></div>
            <div>Last update<strong><?php echo $h($latestUpdate); ?></strong></div>
            <div>Generated<strong><?php echo $h($generatedAt); ?></strong></div>
            <div>QR access<strong><?php echo $h($ttlDays); ?> days</strong></div>
          </div>
        </div>
        <div class="qr-block">
          <?php if ($roomQr): ?>
            <div class="qr-halo">
              <img src="<?php echo $h($roomQr); ?>" alt="QR code for room">
            </div>
            <div class="qr-caption">Scan for live updates</div>
            <div class="qr-link"><?php echo $h($roomLink); ?></div>
          <?php else: ?>
            <div class="qr-caption">QR code unavailable</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="insight-grid">
        <?php foreach ($statusOrder as $statusKey):
          $count = $statusCounts[$statusKey] ?? 0;
          if ($count <= 0) continue;
        ?>
          <div class="insight-card">
            <small>Status</small>
            <strong><?php echo $h(status_label($statusKey)); ?></strong>
            <span><?php echo $h($count); ?> task<?php echo $count === 1 ? '' : 's'; ?></span>
          </div>
        <?php endforeach; ?>
        <?php foreach ($priorityOrder as $priorityKey):
          $count = $priorityCounts[$priorityKey] ?? 0;
          if ($count <= 0) continue;
        ?>
          <div class="insight-card">
            <small>Priority</small>
            <strong><?php echo $h(priority_label($priorityKey)); ?></strong>
            <span><?php echo $h($count); ?> task<?php echo $count === 1 ? '' : 's'; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section">
      <?php if ($taskCount === 0): ?>
        <div class="empty-state">No tasks are linked to this room yet. Use the QR code to capture the first punch list items on site.</div>
      <?php else: ?>
        <?php foreach ($statusOrder as $statusKey):
          $tasksInGroup = $grouped[$statusKey] ?? [];
          if (!$tasksInGroup) continue;
        ?>
          <div class="group">
            <div class="group-header">
              <div class="group-title"><?php echo $h(status_label($statusKey)); ?></div>
              <div class="group-count"><?php echo $h(count($tasksInGroup)); ?> task<?php echo count($tasksInGroup) === 1 ? '' : 's'; ?></div>
            </div>
            <?php foreach ($tasksInGroup as $task):
              $priorityValue = (string)($task['priority'] ?? '');
              $priorityClass = priority_class($priorityValue);
              $statusClass   = 'status-' . preg_replace('/[^a-z0-9_]+/', '_', strtolower((string)($task['status'] ?? 'open')));
              $assigned      = trim((string)($task['assigned_to'] ?? ''));
              $dueDate       = $formatDate($task['due_date'] ?? null);
              $createdAt     = $formatDateTime($task['created_at'] ?? null);
              $updatedAt     = $formatDateTime($task['updated_at'] ?? null);
              $photosForTask = $photoUrls[(int)$task['id']] ?? [];
              $desc          = trim((string)($task['description'] ?? ''));
            ?>
              <article class="task-card">
                <header>
                  <div class="task-title">#<?php echo $h($task['id']); ?> · <?php echo $h($task['title'] ?? ''); ?></div>
                  <div class="tag-row">
                    <span class="tag <?php echo $h($statusClass); ?>"><?php echo $h(status_label((string)($task['status'] ?? 'open'))); ?></span>
                    <span class="tag <?php echo $h($priorityClass); ?>"><?php echo $h(priority_label($priorityValue)); ?></span>
                    <?php if (!empty($task['due_date'])): ?>
                      <span class="tag" style="background: rgba(37,99,235,.14); color: #1d4ed8;">Due <?php echo $h($formatDate($task['due_date'] ?? null)); ?></span>
                    <?php endif; ?>
                  </div>
                </header>
                <div class="task-meta">
                  <div>Assigned<strong><?php echo $assigned !== '' ? $h($assigned) : '—'; ?></strong></div>
                  <div>Created<strong><?php echo $h($createdAt); ?></strong></div>
                  <div>Updated<strong><?php echo $h($updatedAt); ?></strong></div>
                  <div>Due<strong><?php echo $h($dueDate); ?></strong></div>
                </div>
                <?php if ($desc !== ''): ?>
                  <div class="task-desc"><?php echo nl2br($h($desc)); ?></div>
                <?php endif; ?>
                <?php if ($photosForTask): ?>
                  <div class="photo-grid">
                    <?php foreach ($photosForTask as $photoUrl): ?>
                      <img src="<?php echo $h($photoUrl); ?>" alt="Task photo">
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
<?php
$html = ob_get_clean();

$pdfFile  = tempnam(sys_get_temp_dir(), 'room_pdf_') . '.pdf';
$htmlFile = tempnam(sys_get_temp_dir(), 'room_html_') . '.html';
file_put_contents($htmlFile, $html);

$wkhtml = '/usr/local/bin/wkhtmltopdf';
if (!is_executable($wkhtml)) { $wkhtml = '/usr/bin/wkhtmltopdf'; }
if (!is_executable($wkhtml)) { $wkhtml = 'wkhtmltopdf'; }

$cookieArg = '--cookie "PHPSESSID" ' . escapeshellarg(session_id());
$cmd = sprintf(
    '%s --quiet --encoding utf-8 --print-media-type '
    . '--margin-top 16mm --margin-right 14mm --margin-bottom 18mm --margin-left 14mm '
    . '--page-size A4 --orientation Portrait '
    . '--footer-right "Page [page] of [toPage]" --footer-font-size 9 '
    . '%s %s %s 2>&1',
    escapeshellarg($wkhtml),
    $cookieArg,
    escapeshellarg($htmlFile),
    escapeshellarg($pdfFile)
);

$out = [];
$ret = 0;
exec($cmd, $out, $ret);
@unlink($htmlFile);

if ($ret !== 0 || !file_exists($pdfFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "wkhtmltopdf failed (code $ret)\nCommand:\n$cmd\n\nOutput:\n" . implode("\n", $out);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="room-' . $roomId . '-export.pdf"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);
@unlink($pdfFile);
