<?php
/**
 * Kolekta — barangay administration console.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/page.php';

$user = page_guard('admin');

/* All zones + waste types for scope selectors. */
$zones = db()->query(
    'SELECT id, name, code, description, is_active FROM zones ORDER BY id'
)->fetchAll();

$wasteTypes = db()->query(
    'SELECT id, code, name, is_active, sort_order FROM waste_types ORDER BY sort_order, id'
)->fetchAll();

/* Weekly schedule matrix: zone_id -> weekday -> [waste_types] */
$matrix = [];
$rows = db()->query(
    'SELECT s.zone_id, s.weekday, s.waste_type, s.is_active
       FROM schedules s
      ORDER BY s.zone_id, s.weekday'
)->fetchAll();

foreach ($zones as $z) {
    $matrix[$z['id']] = [];
    for ($d = 1; $d <= 7; $d++) {
        $matrix[$z['id']][$d] = [];
    }
}
foreach ($rows as $r) {
    if (isset($matrix[$r['zone_id']])) {
        $matrix[$r['zone_id']][(int)$r['weekday']][] = [
            'waste_type' => $r['waste_type'],
            'is_active'  => (int)$r['is_active'] === 1,
        ];
    }
}

$adminNameParts = explode(' ', trim($user['name']));
$adminInitials = mb_strtoupper(mb_substr($adminNameParts[0] ?? '', 0, 1));
if (count($adminNameParts) > 1) {
    $adminInitials .= mb_strtoupper(mb_substr((string)end($adminNameParts), 0, 1));
}
if ($adminInitials === '') {
    $adminInitials = '?';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="view-transition" content="same-origin">
<title>Administration Console — Kolekta</title>
<?= csrf_meta() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,480;0,9..144,560;1,9..144,400;1,9..144,480&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%231E3B2C'/%3E%3Cpath d='M10 20c3 3 9 3 12 0s3-8 0-11' stroke='%23F5F0E4' stroke-width='2.4' fill='none' stroke-linecap='round'/%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/css/kolekta.css?v=3">
<link rel="stylesheet" href="assets/css/sidebar.css">
</head>
<body>

<div class="sb-layout">

  <!-- ==== MOBILE TRIGGER (<=860px) ==== -->
  <button class="sb-burger" type="button" aria-controls="sbPanel" aria-expanded="false" aria-label="Open navigation">
    <svg class="sb-burger-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>
    <svg class="sb-burger-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>
  </button>

  <!-- ==== DRAWER SCRIM (<=860px) ==== -->
  <div class="sb-scrim"></div>

  <aside class="sb" id="sbPanel" aria-label="Primary">
    <div class="sb-head">
      <button class="sb-collapse" type="button" aria-controls="sbPanel" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 6l-6 6 6 6"/></svg>
      </button>
      <span class="wordmark sb-brand">
        <span class="wordmark--mark"><?= brand_mark() ?></span>
        <span class="wordmark--word">Kolekta</span>
      </span>
    </div>

    <nav class="sb-nav" id="adminNav">
      <a href="#overview" class="sb-link active"><?= icon('home') ?><span class="sb-label">Dashboard</span></a>
      <span class="sb-section">Management</span>
      <a href="#schedules" class="sb-link"><?= icon('calendar') ?><span class="sb-label">Collection schedules</span></a>
      <a href="#dispatch" class="sb-link"><?= icon('send') ?><span class="sb-label">Dispatch alerts</span></a>
      <a href="#notices" class="sb-link"><?= icon('megaphone') ?><span class="sb-label">Notices</span></a>
      <a href="#reports" class="sb-link"><?= icon('inbox') ?><span class="sb-label">Missed-pickup reports</span><span class="sb-count" id="navPending">0</span></a>
      <a href="#puroks" class="sb-link"><?= icon('mapPin') ?><span class="sb-label">Puroks</span></a>
      <a href="#wastetypes" class="sb-link"><?= icon('recycle') ?><span class="sb-label">Waste types</span></a>
    </nav>

    <div class="sb-foot">
      <div class="sb-user">
        <span class="sb-avatar admin-av"><?= esc($adminInitials) ?></span>
        <span class="sb-user-text">
          <b><?= esc($user['name']) ?></b>
          <span>Administration</span>
        </span>
      </div>
      <a class="sb-mini" href="legal/privacy.php"><?= icon('lock') ?><span class="sb-label">Privacy &amp; terms</span></a>
      <button class="sb-mini" type="button" onclick="signOut()"><?= icon('logout') ?><span class="sb-label">Sign out</span></button>
    </div>
  </aside>

  <div class="sb-main">
  <main class="main">

    <div class="main-head">
      <div>
        <h1>Administration console</h1>
        <p>Collection schedules, dispatch alerts, notices and missed-pickup triage.</p>
      </div>
      <div class="main-head-actions">
        <span class="zone-chip"><i></i><?= BARANGAY_NAME ?></span>
      </div>
    </div>

    <!-- ============================ OVERVIEW ============================ -->
    <section id="overview">
      <p class="section-title">Dashboard</p>

      <div class="quick-actions mb-3">
        <button type="button" class="btn btn--primary" data-goto="schedules" data-opens="schedule"><?= icon('plus') ?> Add schedule</button>
        <button type="button" class="btn btn--ghost" data-goto="notices"><?= icon('plus') ?> Create notice</button>
        <button type="button" class="btn btn--ghost" data-goto="dispatch"><?= icon('send') ?> Dispatch alert</button>
        <button type="button" class="btn btn--ghost" data-goto="reports"><?= icon('inbox') ?> Review reports</button>
      </div>

      <!-- ==== DASHBOARD STATS ==== -->
      <div class="stats mb-3" id="statsRow">
        <div class="stat"><span class="s-lab">Registered residents</span><span class="s-val">—</span></div>
        <div class="stat"><span class="s-lab">Active residents</span><span class="s-val">—</span></div>
        <div class="stat"><span class="s-lab">Today&rsquo;s schedules</span><span class="s-val">—</span></div>
        <div class="stat"><span class="s-lab">Active dispatch alerts</span><span class="s-val">—</span></div>
      </div>

      <div class="stats mb-3" id="statsRow2">
        <div class="stat"><span class="s-lab">Pending reports</span><span class="s-val">—</span></div>
        <div class="stat"><span class="s-lab">Investigating</span><span class="s-val">—</span></div>
        <div class="stat"><span class="s-lab">Resolved</span><span class="s-val">—</span></div>
        <div class="stat"><span class="s-lab">Active puroks</span><span class="s-val">—</span></div>
      </div>

      <div class="grid grid--3">
        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('users') ?> Residents per purok</h2>
          </div>
          <div id="purokStats"></div>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('truck') ?> Recent dispatch activity</h2>
            <span class="right">Latest first</span>
          </div>
          <div id="activityLog"></div>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('megaphone') ?> Recent notices</h2>
          </div>
          <div id="adminAnnPreview"></div>
        </section>
      </div>
    </section>

    <!-- ============================ SCHEDULES ============================ -->
    <section id="schedules" class="mt-3">
      <p class="section-title">Collection schedules</p>

      <section class="panel mb-2">
        <div class="panel-head">
          <h2><?= icon('calendar') ?> Weekly calendar (per purok)</h2>
          <span class="right">Fixed repeating schedule</span>
        </div>
        <div class="table-wrap">
          <!-- ==== WEEKLY SCHEDULE MATRIX ==== -->
          <table class="table" id="schedMatrix"></table>
        </div>
      </section>

      <!-- ==== WEEK PREVIEW (by purok) ==== -->
      <section class="panel mb-2">
        <div class="panel-head">
          <h2><?= icon('calendar') ?> Week preview (by purok)</h2>
          <span class="right" id="previewWeek"></span>
        </div>
        <div class="filter-bar mb-2">
          <label>Purok
            <select class="select select--sm" id="previewZone"></select>
          </label>
          <button type="button" class="btn btn--ghost btn--sm" onclick="previewShift(-1)">‹ Prev week</button>
          <button type="button" class="btn btn--ghost btn--sm" onclick="previewShift(0)">This week</button>
          <button type="button" class="btn btn--ghost btn--sm" onclick="previewShift(1)">Next week ›</button>
        </div>
        <div id="previewDays"></div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('fileText') ?> Manage schedules</h2>
          <button type="button" class="btn btn--primary btn--sm" id="addScheduleBtn" data-opens="schedule"><?= icon('plus') ?> Add schedule</button>
        </div>

        <div class="filter-bar mb-2">
          <label>Purok
            <select class="select select--sm" id="schedFilterZone"><option value="">All puroks</option></select>
          </label>
          <label>Waste type
            <select class="select select--sm" id="schedFilterWaste"><option value="">All waste types</option></select>
          </label>
          <label>Day
            <select class="select select--sm" id="schedFilterDay"><option value="">All days</option></select>
          </label>
          <label>Status
            <select class="select select--sm" id="schedFilterStatus">
              <option value="">Active &amp; inactive</option>
              <option value="active">Active only</option>
              <option value="inactive">Inactive only</option>
            </select>
          </label>
        </div>

        <div id="schedList"></div>
      </section>
    </section>

    <!-- ============================ DISPATCH ============================ -->
    <section id="dispatch" class="mt-3">
      <p class="section-title">Dispatch alerts</p>

      <section class="panel mb-2">
        <div class="panel-head">
          <h2><?= icon('send') ?> Trigger a truck dispatch</h2>
          <span class="right">One tap alerts the whole purok</span>
        </div>
        <div class="dispatch-toolbar mb-2">
          <span class="inline-label">Collecting:</span>
          <div class="seg" id="wasteSeg">
            <button type="button" data-waste="biodegradable">Biodegradable</button>
            <button type="button" data-waste="non_biodegradable">Non-biodegradable</button>
            <button type="button" data-waste="recyclable">Recyclable</button>
            <button type="button" data-waste="" class="on">Sorted (all)</button>
          </div>
        </div>
        <!-- ==== DISPATCH TRIGGER ZONE GRID ==== -->
        <div class="zonegrid" id="zoneGrid"></div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('fileText') ?> Alert history</h2>
        </div>
        <div class="filter-bar mb-2">
          <label>Purok
            <select class="select select--sm" id="dispatchFilterZone"><option value="">All puroks</option></select>
          </label>
          <label>Status
            <select class="select select--sm" id="dispatchFilterStatus">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="cancelled">Cancelled</option>
              <option value="archived">Archived</option>
            </select>
          </label>
        </div>
        <div id="dispatchList"></div>
      </section>
    </section>

    <!-- ============================ NOTICES ============================ -->
    <section id="notices" class="mt-3">
      <p class="section-title">Notices</p>

      <div class="grid grid--2 mb-2">
        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('megaphone') ?> NOTICES.</h2>
          </div>
          <!-- ==== POST A NOTICE FORM ==== -->
          <form id="annForm" novalidate>
            <div class="field">
              <label for="annScope">Reach</label>
              <select class="select" id="annScope">
                <option value="all">All puroks</option>
                <?php foreach ($zones as $z): ?>
                  <option value="<?= (int)$z['id'] ?>"><?= esc($z['name']) ?><?= $z['is_active'] ? '' : ' (inactive)' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Type</label>
              <div class="pick" style="grid-template-columns:1fr 1fr">
                <label class="pick-opt">
                  <input type="radio" name="annKind" value="notice" checked>
                  <span class="lbl">Notice</span>
                </label>
                <label class="pick-opt">
                  <input type="radio" name="annKind" value="cancellation">
                  <span class="lbl" style="color:var(--rust)">Cancellation</span>
                </label>
              </div>
            </div>
            <div class="form-row">
              <div class="field">
                <label for="annTitle">Title</label>
                <input class="input" type="text" id="annTitle" maxlength="160" placeholder="e.g. No collection on Friday">
                <p class="field-error"></p>
              </div>
              <div class="field">
                <label for="annExpires">Expires <span class="opt">optional</span></label>
                <input class="input" type="datetime-local" id="annExpires">
              </div>
            </div>
            <div class="field">
              <label for="annBody">Message</label>
              <textarea class="input" id="annBody" maxlength="600" rows="2" placeholder="Brief instruction for residents"></textarea>
              <p class="field-error"></p>
            </div>
            <label class="checkline mb-2">
              <input type="checkbox" id="annPublished" checked>
              <span>Publish immediately</span>
            </label>
            <button type="submit" class="btn btn--primary" style="width:100%">Publish notice</button>
          </form>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('fileText') ?> Manage notices</h2>
          </div>
          <div class="filter-bar mb-2">
            <label>Status
              <select class="select select--sm" id="noticeFilterStatus">
                <option value="">All notices</option>
                <option value="published">Published</option>
                <option value="draft">Unpublished</option>
              </select>
            </label>
          </div>
          <div id="adminAnnList"></div>
        </section>
      </div>
    </section>

    <!-- ============================ REPORTS ============================ -->
    <section id="reports" class="mt-3">
      <p class="section-title">Missed-pickup reports</p>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('inbox') ?> Report triage</h2>
          <span class="right">Pending → Investigating → Reopened → Resolved → Archived</span>
        </div>

        <div class="filter-bar mb-2">
          <label>Status
            <select class="select select--sm" id="reportFilterStatus">
              <option value="" selected>All reports</option>
              <option value="pending">Pending</option>
              <option value="investigating">Investigating</option>
              <option value="reopened">Reopened</option>
              <option value="resolved">Resolved</option>
              <option value="archived">Archived</option>
            </select>
          </label>
          <label>Purok
            <select class="select select--sm" id="reportFilterZone"><option value="">All puroks</option></select>
          </label>
          <label>Waste type
            <select class="select select--sm" id="reportFilterWaste"><option value="">All waste types</option></select>
          </label>
          <label>Date
            <input class="input input--sm" type="date" id="reportFilterDate">
          </label>
        </div>

        <div id="reopenedNotice" class="mb-2"></div>
        <div id="zoneTriageSummary" class="mb-2"></div>
        <!-- ==== MISSED-PICKUP REPORT TRIAGE ==== -->
        <div id="triageList"></div>
      </section>
    </section>

    <!-- ============================ PUROKS ============================ -->
    <section id="puroks" class="mt-3">
      <p class="section-title">Puroks</p>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('mapPin') ?> Manage puroks</h2>
          <button type="button" class="btn btn--primary btn--sm" id="addPurokBtn" data-opens="purok"><?= icon('plus') ?> Add purok</button>
        </div>
        <!-- ==== PUROK MANAGEMENT ==== -->
        <div id="purokList"></div>
      </section>
    </section>

    <!-- ============================ WASTE TYPES ============================ -->
    <section id="wastetypes" class="mt-3">
      <p class="section-title">Waste types</p>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('recycle') ?> Manage waste types</h2>
          <button type="button" class="btn btn--primary btn--sm" id="addWasteBtn" data-opens="waste"><?= icon('plus') ?> Add waste type</button>
        </div>
        <!-- ==== WASTE TYPE MANAGEMENT ==== -->
        <div id="wasteList"></div>
      </section>
    </section>

  </main>
  </div>
</div>

<!-- ==== MODALS ==== -->

<!-- ==== CONFIRM MODAL ==== -->
<div class="modal-backdrop" id="confirmModal">
  <div class="modal">
    <h3 id="confirmTitle">Are you sure?</h3>
    <p class="m-sub" id="confirmMsg"></p>
    <div class="m-actions">
      <button type="button" class="btn btn--ghost" onclick="closeModal('confirmModal')">Cancel</button>
      <button type="button" class="btn btn--danger-soft" id="confirmYes">Yes, continue</button>
    </div>
  </div>
</div>

<!-- ==== SCHEDULE ADD/EDIT MODAL ==== -->
<div class="modal-backdrop" id="scheduleModal">
  <div class="modal">
    <h3 id="scheduleModalTitle">Add schedule</h3>
    <form id="scheduleForm" novalidate>
      <div class="field">
        <label for="schedZone">Purok</label>
        <select class="select" id="schedZone"><option value="">Select purok…</option></select>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="schedDay">Day</label>
          <select class="select" id="schedDay"><option value="">Select…</option></select>
        </div>
        <div class="field">
          <label for="schedWaste">Waste type</label>
          <select class="select" id="schedWaste"><option value="">Select…</option></select>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="schedStart">Window start</label>
          <input class="input" type="time" id="schedStart" step="60">
        </div>
        <div class="field">
          <label for="schedEnd">Window end</label>
          <input class="input" type="time" id="schedEnd" step="60">
        </div>
      </div>
      <label class="checkline mb-2">
        <input type="checkbox" id="schedActive" checked>
        <span>Active (shown to residents)</span>
      </label>
      <div class="m-actions">
        <button type="button" class="btn btn--ghost" onclick="closeModal('scheduleModal')">Cancel</button>
        <button type="submit" class="btn btn--primary" id="scheduleSubmit">Save schedule</button>
      </div>
    </form>
  </div>
</div>

<!-- ==== DISPATCH EDIT MODAL ==== -->
<div class="modal-backdrop" id="dispatchModal">
  <div class="modal">
    <h3>Edit dispatch alert</h3>
    <form id="dispatchForm" novalidate>
      <div class="field">
        <label for="dispatchZone">Purok</label>
        <select class="select" id="dispatchZone"></select>
      </div>
      <div class="field">
        <label for="dispatchWaste">Waste type</label>
        <select class="select" id="dispatchWaste"><option value="">Sorted (all)</option></select>
      </div>
      <div class="field">
        <label for="dispatchMessage">Message <span class="opt">shown to residents</span></label>
        <textarea class="input" id="dispatchMessage" maxlength="300" rows="2"></textarea>
      </div>
      <div class="m-actions">
        <button type="button" class="btn btn--ghost" onclick="closeModal('dispatchModal')">Cancel</button>
        <button type="submit" class="btn btn--primary">Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ==== NOTICE EDIT MODAL ==== -->
<div class="modal-backdrop" id="noticeModal">
  <div class="modal">
    <h3>Edit notice</h3>
    <form id="noticeForm" novalidate>
      <div class="field">
        <label for="editAnnScope">Reach</label>
        <select class="select" id="editAnnScope"></select>
      </div>
      <div class="field">
        <label>Type</label>
        <div class="pick" style="grid-template-columns:1fr 1fr">
          <label class="pick-opt">
            <input type="radio" name="editAnnKind" value="notice">
            <span class="lbl">Notice</span>
          </label>
          <label class="pick-opt">
            <input type="radio" name="editAnnKind" value="cancellation">
            <span class="lbl" style="color:var(--rust)">Cancellation</span>
          </label>
        </div>
      </div>
      <div class="field">
        <label for="editAnnTitle">Title</label>
        <input class="input" type="text" id="editAnnTitle" maxlength="160">
      </div>
      <div class="field">
        <label for="editAnnBody">Message</label>
        <textarea class="input" id="editAnnBody" maxlength="600" rows="2"></textarea>
      </div>
      <div class="form-row" style="align-items:end">
        <div class="field">
          <label for="editAnnExpires">Expires <span class="opt">optional</span></label>
          <input class="input" type="datetime-local" id="editAnnExpires">
        </div>
        <label class="checkline">
          <input type="checkbox" id="editAnnPublished" checked>
          <span>Published</span>
        </label>
      </div>
      <div class="m-actions">
        <button type="button" class="btn btn--ghost" onclick="closeModal('noticeModal')">Cancel</button>
        <button type="submit" class="btn btn--primary">Save notice</button>
      </div>
    </form>
  </div>
</div>

<!-- ==== REPORT STATUS MODAL ==== -->
<div class="modal-backdrop" id="reportModal">
  <div class="modal modal--wide">
    <h3 id="reportModalTitle">Update report</h3>
    <p class="m-sub" id="reportSub"></p>
    <div id="reportReopenedBanner" class="reopen-banner" style="display:none">
      Reopened by the resident — respond and move this report forward.
    </div>
    <div class="field">
      <label for="reportNewStatus">Move to</label>
      <select class="select" id="reportNewStatus"></select>
    </div>
    <label class="checkline" id="reportDispatchRow">
      <input type="checkbox" id="reportDispatch">
      <span>Arrange a secondary collection truck</span>
    </label>
    <div class="field mt-2">
      <label for="reportNote">Your response / note <span class="opt">optional</span></label>
      <textarea class="input" id="reportNote" maxlength="500" rows="2" placeholder="e.g. Second truck routed for 10:00 AM"></textarea>
    </div>
    <div class="field mt-2">
      <label>Report history</label>
      <div id="reportHistory" class="thread thread--compact"></div>
    </div>
    <div class="m-actions">
      <button type="button" class="btn btn--ghost" onclick="closeModal('reportModal')">Cancel</button>
      <button type="button" class="btn btn--primary" id="reportSubmit">Save status</button>
    </div>
  </div>
</div>

<!-- ==== PUROK ADD/EDIT MODAL ==== -->
<div class="modal-backdrop" id="purokModal">
  <div class="modal">
    <h3 id="purokModalTitle">Add purok</h3>
    <form id="purokForm" novalidate>
      <div class="field">
        <label for="purokName">Name</label>
        <input class="input" type="text" id="purokName" maxlength="60" placeholder="e.g. Purok 9">
      </div>
      <div class="field">
        <label for="purokCode">Code <span class="opt">e.g. P9</span></label>
        <input class="input" type="text" id="purokCode" maxlength="12" placeholder="P9">
      </div>
      <div class="field">
        <label for="purokDesc">Description <span class="opt">optional</span></label>
        <input class="input" type="text" id="purokDesc" maxlength="255">
      </div>
      <div class="m-actions">
        <button type="button" class="btn btn--ghost" onclick="closeModal('purokModal')">Cancel</button>
        <button type="submit" class="btn btn--primary" id="purokSubmit">Save purok</button>
      </div>
    </form>
  </div>
</div>

<!-- ==== WASTE TYPE ADD/EDIT MODAL ==== -->
<div class="modal-backdrop" id="wasteModal">
  <div class="modal">
    <h3 id="wasteModalTitle">Add waste type</h3>
    <form id="wasteForm" novalidate>
      <div class="field">
        <label for="wasteCode">Code <span class="opt">cannot be changed later</span></label>
        <input class="input" type="text" id="wasteCode" maxlength="32" placeholder="e.g. residual">
      </div>
      <div class="field">
        <label for="wasteName">Display name</label>
        <input class="input" type="text" id="wasteName" maxlength="60" placeholder="e.g. Residual waste">
      </div>
      <div class="field">
        <label for="wasteSort">Sort order</label>
        <input class="input" type="number" id="wasteSort" min="0" value="0">
      </div>
      <div class="m-actions">
        <button type="button" class="btn btn--ghost" onclick="closeModal('wasteModal')">Cancel</button>
        <button type="submit" class="btn btn--primary" id="wasteSubmit">Save waste type</button>
      </div>
    </form>
  </div>
</div>

<div class="toast"><span class="t-dot"></span><span class="t-text"></span></div>

<script>
window.KOLEKTA = {
  user: <?= json_encode($user, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  zones: <?= json_encode($zones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  wasteTypes: <?= json_encode($wasteTypes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  matrix: <?= json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  weekNames: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
  weekDays: [
    {v: 1, label: 'Monday'}, {v: 2, label: 'Tuesday'}, {v: 3, label: 'Wednesday'},
    {v: 4, label: 'Thursday'}, {v: 5, label: 'Friday'}, {v: 6, label: 'Saturday'}, {v: 7, label: 'Sunday'}
  ]
};
</script>
<script src="assets/js/app.js"></script>
<script src="assets/js/sidebar.js"></script>
<script src="assets/js/admin.js"></script>
</body>
</html>