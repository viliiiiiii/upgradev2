<?php
require_once __DIR__ . '/helpers.php';
require_login();

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/includes/export_tokens.php';

set_time_limit(120);

/* ============================
   Settings (via GET)
   ============================ */
$ttlDays = max(1, min(365, (int)($_GET['ttl'] ?? 30)));
$qrSize  = max(120, min(300, (int)($_GET['qr'] ?? 160))); // a bit larger for a “hero” centered QR

/* ============================
   selection vs. filters
   ============================ */
/* ============================
   Utilities
   ============================ */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$selectedIds = [];
if (!empty($_REQUEST['selected'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', (string)$_REQUEST['selected'])));
}

if ($selectedIds) {
    $tasks   = fetch_tasks_by_ids($selectedIds);
    $filters = [];
    $summary = 'Selected tasks: ' . implode(', ', $selectedIds);
} else {
    $filters = get_filter_values();
    $tasks   = export_tasks($filters); // table only
    $summary = filter_summary($filters);
}

/* ============================
   Group tasks by room
   ============================ */
$groups = []; // room_id => [ 'meta' => [...], 'tasks' => [...] ]
foreach ($tasks as $t) {
    $rid = (int)($t['room_id'] ?? 0);
    if (!isset($groups[$rid])) {
        $groups[$rid] = [
            'meta' => [
                'room_id'       => $rid,
                'room_number'   => $t['room_number'] ?? '',
                'room_label'    => $t['room_label'] ?? '',
                'building_name' => $t['building_name'] ?? '',
            ],
            'tasks' => [],
        ];
    }
    $groups[$rid]['tasks'][] = $t;
}

/* ============================
   Build per-room public link & QR
   ============================ */
$pdo = get_pdo();
ensure_public_room_token_tables($pdo);

$base       = base_url_for_pdf();
$publicPath = '/public_room_photos.php'; // viewer to accept ?t=

$roomIds = array_keys($groups);
$existing = $roomIds ? fetch_valid_room_tokens($pdo, $roomIds) : [];

$roomUrl = [];  // room_id -> public URL
$roomQr  = [];  // room_id -> data URI

foreach ($roomIds as $rid) {
    if ($rid <= 0) continue; // no room -> skip QR
    $tokRow = $existing[$rid] ?? insert_room_token($pdo, $rid, $ttlDays);
    $token  = is_string($tokRow['token']) ? $tokRow['token'] : (string)$tokRow['token'];
    $url    = $base . $publicPath . '?t=' . rawurlencode($token);
    $qr     = qr_data_uri($url, $qrSize);
    $roomUrl[$rid] = $url;
    if ($qr) $roomQr[$rid] = $qr;
}

/* ============================
   HTML (futuristic light; Dompdf-safe)
   ============================ */
ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Tasks by Room (Centered QR • Futuristic Light)</title>
<style>
  @page { margin: 60px 28px 55px 28px; }

  thead { display: table-header-group; }
  tfoot { display: table-row-group; }
  tr    { page-break-inside: avoid; }

  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 11px; color: #101623; line-height: 1.28;
    background: #ffffff;
  }

  /* Header / Footer */
  .pdf-header {
    position: fixed; top: -48px; left: 0; right: 0; height: 48px;
    padding: 6px 0 8px 0;
    border-bottom: 1px solid #e5e7eb;
  }
  .brand {
    font-weight: 800; letter-spacing: .02em;
    font-size: 14px; color: #4f46e5;
  }
  .meta-line { font-size: 10px; color: #667085; }

  .pdf-footer {
    position: fixed; bottom: -42px; left: 0; right: 0; height: 42px;
    border-top: 1px solid #e5e7eb;
    font-size: 10px; color: #7c8aa0; padding-top: 6px;
  }

  /* Summary */
  .summary {
    margin: 0 0 12px 0;
    padding: 8px 10px;
    border: 1px solid #e8ecf4;
    border-radius: 10px;
    background: #f7faff;
    box-shadow: inset 0 0 0 1px #eef2ff;
    font-size: 10px;
  }

  /* Room Block (card) */
  .room-block {
    page-break-inside: avoid;
    margin: 10px 0 16px 0;
    padding: 12px;
    border: 1px solid #e8ecf4;
    border-radius: 12px;
    background: #ffffff;
    box-shadow:
      0 0 0 1px #eef2ff inset,
      0 3px 10px rgba(79,70,229,0.06);
  }

  /* Room Header as a 3-col table for Dompdf compatibility */
  table.room-header {
    width: 100%;
    border-collapse: collapse;
    margin: 0 0 10px 0;
  }
  table.room-header td {
    vertical-align: middle;
    padding: 2px 6px;
  }
  .room-meta {
    font-size: 12px;
    color: #0f172a;
  }
  .meta-row { margin: 2px 0; }
  .lbl {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 10px;
    color: #3730a3;
    background: #eef2ff;
    border: 1px solid #e0e7ff;
    margin-right: 6px;
  }
  .val {
    font-weight: 700;
    color: #111827;
  }

  /* Center QR with a “halo” ring */
  .qr-cell { text-align: center; }
  .qr-halo {
    display: inline-block;
    width: <?php echo (int)($qrSize + 24); ?>px;
    height: <?php echo (int)($qrSize + 24); ?>px;
    border-radius: 999px;
    background: #f5f7ff;
    box-shadow:
      0 0 0 4px #eef2ff inset,
      0 8px 18px rgba(79,70,229,0.08);
    padding: 12px;
  }
  .qr-img {
    width: <?php echo (int)$qrSize; ?>px;
    height: <?php echo (int)$qrSize; ?>px;
    display: block;
    margin: 0 auto;
  }
  .qr-url {
    margin-top: 6px;
    font-size: 9px;
    color: #1f2a44;
    word-break: break-all;
  }
  .qr-url a {
    color: #3b82f6;
    text-decoration: none;
    border-bottom: 1px solid #93c5fd;
  }

  /* Tasks Table (per room) */
  table.tasks {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    border: 1px solid #e8ecf4;
  }
  table.tasks thead th {
    background: #f5f7ff;
    color: #334155;
    border-bottom: 1px solid #e5e7eb;
    padding: 7px 6px;
    font-weight: 800;
    font-size: 10px;
    letter-spacing: .04em;
    text-transform: uppercase;
  }
  table.tasks tbody td {
    padding: 6px 6px;
    border-bottom: 1px solid #eef2f7;
    color: #111827;
    overflow: hidden;
    word-wrap: break-word;
  }
  table.tasks tbody tr:nth-child(even) td {
    background: #fbfdff;
  }

  /* Column widths */
  .col-id       { width: 42px; }
  .col-title    { width: 280px; }
  .col-priority { width: 90px; }
  .col-status   { width: 100px; }
  .col-assigned { width: 140px; }
  .col-due      { width: 100px; }
  .col-created  { width: 120px; }
  .col-updated  { width: 120px; }
</style>
</head>
<body>

<div class="pdf-header">
  <div class="brand">Tasks by Room</div>
  <div class="meta-line">
    Generated: <?php echo h(date('Y-m-d H:i')); ?> •
    Total tasks: <?php echo (int)count($tasks); ?> •
    Layout: Landscape • QR TTL: <?php echo (int)$ttlDays; ?>d
  </div>
</div>

<div class="pdf-footer">
  <span>www.your-domain.tld</span>
</div>

<div class="summary">
  <strong>Filters:</strong> <?php echo h($summary); ?>
</div>

<?php if (!$groups): ?>
  <p style="color:#667085;">No tasks found for the selected filters.</p>
<?php else: ?>
  <?php foreach ($groups as $rid => $g): ?>
    <?php
      $meta      = $g['meta'];
      $list      = $g['tasks'];
      $roomStr   = trim(($meta['room_number'] ?? '') . ((!empty($meta['room_label'])) ? ' - ' . $meta['room_label'] : ''));
      $building  = $meta['building_name'] ?? '';
      $hasQr     = $rid > 0 && !empty($roomQr[$rid]);
      $qrLink    = $rid > 0 && !empty($roomUrl[$rid]) ? $roomUrl[$rid] : '';
    ?>
    <section class="room-block">
      <table class="room-header">
        <tr>
          <td style="width:33%;">
            <div class="room-meta">
              <div class="meta-row"><span class="lbl">Building</span> <span class="val"><?php echo h($building ?: '—'); ?></span></div>
              <div class="meta-row"><span class="lbl">Room</span> <span class="val"><?php echo h($roomStr ?: '—'); ?></span></div>
            </div>
          </td>
          <td class="qr-cell" style="width:34%;">
            <?php if ($hasQr): ?>
              <div class="qr-halo">
                <img class="qr-img" src="<?php echo $roomQr[$rid]; ?>" alt="Room QR">
              </div>
              <?php if ($qrLink): ?>
                <div class="qr-url">
                  <a href="<?php echo h($qrLink); ?>"><?php echo h($qrLink); ?></a>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div style="font-size:10px; color:#7c8aa0;">No QR for this room</div>
            <?php endif; ?>
          </td>
          <td style="width:33%;"></td>
        </tr>
      </table>

      <table class="tasks">
        <thead>
          <tr>
            <th class="col-id">ID</th>
            <th class="col-title">Title</th>
            <th class="col-priority">Priority</th>
            <th class="col-status">Status</th>
            <th class="col-assigned">Assigned To</th>
            <th class="col-due">Due</th>
            <th class="col-created">Created</th>
            <th class="col-updated">Updated</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $task): ?>
            <?php
              $created = !empty($task['created_at']) ? substr((string)$task['created_at'], 0, 16) : '';
              $updated = !empty($task['updated_at']) ? substr((string)$task['updated_at'], 0, 16) : '';
              $due     = !empty($task['due_date'])    ? (string)$task['due_date'] : '—';
            ?>
            <tr>
              <td class="col-id">#<?php echo (int)$task['id']; ?></td>
              <td class="col-title"><?php echo h($task['title'] ?? ''); ?></td>
              <td class="col-priority"><?php echo h(priority_label($task['priority'] ?? '')); ?></td>
              <td class="col-status"><?php echo h(status_label($task['status'] ?? '')); ?></td>
              <td class="col-assigned"><?php echo h($task['assigned_to'] ?? ''); ?></td>
              <td class="col-due"><?php echo h($due); ?></td>
              <td class="col-created"><?php echo h($created); ?></td>
              <td class="col-updated"><?php echo h($updated); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

/* ---------- Dompdf options ---------- */
$options = new Options();
$options->set('isRemoteEnabled', true);       // enables remote/data URIs if needed
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

/* A4 Landscape */
$dompdf->setPaper('A4', 'landscape');

$dompdf->render();

/* Page numbers via canvas */
$canvas = $dompdf->get_canvas();
$w = $canvas->get_width();
$h = $canvas->get_height();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
$size = 9;
$canvas->page_text($w - 130, $h - 30, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, $size, [0.42, 0.45, 0.50]);

// Stream inline
$dompdf->stream('tasks-by-room-qr-futuristic.pdf', ['Attachment' => false]);
