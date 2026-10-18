<?php
/**
 * Kolekta — resident dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/page.php';

$user = page_guard('resident');

$zones = db()->query(
    'SELECT id, name, code FROM zones WHERE is_active = 1 ORDER BY id'
)->fetchAll();

$wasteTypes = db()->query(
    'SELECT id, code, name, is_active, sort_order FROM waste_types WHERE is_active = 1 ORDER BY sort_order, id'
)->fetchAll();

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$nameParts = explode(' ', trim($user['name']));
$firstName = $nameParts[0] ?? '';
if ($firstName === '') {
    $firstName = 'neighbor';
}
$initials = mb_strtoupper(mb_substr($nameParts[0] ?? '', 0, 1));
if (count($nameParts) > 1) {
    $lastWord = (string)end($nameParts);
    $initials .= mb_strtoupper(mb_substr($lastWord, 0, 1));
}
if ($initials === '') {
    $initials = '?';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="view-transition" content="same-origin">
<title>Overview — Kolekta</title>
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

    <nav class="sb-nav" id="userNav">
      <a href="#overview" class="sb-link active"><?= icon('home') ?><span class="sb-label">Overview</span></a>
      <a href="#schedule" class="sb-link"><?= icon('calendar') ?><span class="sb-label">My schedule</span></a>
      <a href="#alerts" class="sb-link"><?= icon('truck') ?><span class="sb-label">Truck alerts</span></a>
      <a href="#notices" class="sb-link"><?= icon('megaphone') ?><span class="sb-label">Barangay notices</span></a>
      <a href="#reports" class="sb-link"><?= icon('flag') ?><span class="sb-label">My reports</span></a>
      <a href="#notifications" class="sb-link"><?= icon('bell') ?><span class="sb-label">Notifications</span><span class="sb-count" id="navNotif">0</span></a>
      <a href="#profile" class="sb-link"><?= icon('users') ?><span class="sb-label">My profile</span></a>
    </nav>

    <div class="sb-foot">
      <div class="sb-user">
        <span class="sb-avatar"><?= esc($initials) ?></span>
        <span class="sb-user-text">
          <b><?= esc($user['name']) ?></b>
          <span id="sideZone"><?= esc($user['zone_name'] ?? 'No purok assigned') ?></span>
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
        <h1 id="greeting"><?= esc($greeting) ?>, <?= esc($firstName) ?>.</h1>
        <p id="todayLine">Your collection schedule and dispatches for today.</p>
      </div>
      <div class="main-head-actions">
        <span class="zone-chip" id="zoneChip"><i></i><?= esc($user['zone_name'] ?? 'No zone') ?></span>
        <!-- ==== NOTIFICATIONS BELL ==== -->
        <button class="bell" id="bell" title="Notifications" onclick="toggleNotifs()"><?= icon('bell') ?><span class="dot"></span></button>
      </div>
    </div>

    <!-- ==== DISPATCH ALERT BANNER ==== -->
    <div id="dispatchBanner" class="alert" style="display:none">
      <div class="a-ico"><?= icon('truck') ?></div>
      <div class="a-body"><b id="bannerTitle"></b><span id="bannerBody"></span></div>
      <button class="a-close" onclick="hideBanner()"><?= icon('x') ?></button>
    </div>

    <!-- ============================ OVERVIEW ============================ -->
    <section id="overview">
      <div class="grid grid--hero" style="margin-bottom:26px">
        <section class="panel next-card">
          <div class="nc-inner">
            <div class="panel-head">
              <h2><?= icon('calendar') ?> Next collection</h2>
              <span class="right" id="nextIn"></span>
            </div>
            <div class="next-day" id="nextDay">—</div>
            <div class="next-date" id="nextDate"></div>
            <div class="next-types" id="nextTypes"></div>
            <div class="next-hint">
              Bring waste out within the collection window. Do not set bags out the
              night before — this prevents <b>litter, dogs, and scavenging</b>.
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('clock') ?> This week</h2>
            <span class="right" id="weekZone"></span>
          </div>
          <div class="week" id="week"></div>
          <div class="next-hint" style="margin-top:20px;font-size:12.5px;color:var(--muted);border-top:1px solid var(--hairline-2);padding-top:14px">
            Follow the barangay ordinance: <b style="color:var(--ink)">no segregation, no collection</b>.
          </div>
        </section>
      </div>

      <div class="quick-actions">
        <button type="button" class="btn btn--primary" data-goto="reports"><?= icon('flag') ?> Report missed pickup</button>
        <button type="button" class="btn btn--ghost" data-goto="schedule"><?= icon('calendar') ?> View my schedule</button>
        <button type="button" class="btn btn--ghost" data-goto="profile"><?= icon('users') ?> Manage my profile</button>
      </div>
    </section>

    <!-- ============================ SCHEDULE ============================ -->
    <section id="schedule" class="mt-3">
      <p class="section-title">My collection schedule</p>

      <div class="grid grid--2">
        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('calendar') ?> Weekly collection</h2>
            <span class="right" id="scheduleZone"></span>
          </div>
          <div class="mb-2" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <button class="btn btn--ghost btn--sm" type="button" onclick="shiftWeek(-1)">‹ Previous week</button>
            <span class="mono" id="weekLabel" style="font-size:12px;color:var(--muted)">This week</span>
            <button class="btn btn--ghost btn--sm" type="button" onclick="shiftWeek(1)">Next week ›</button>
            <button class="btn btn--ghost btn--sm" type="button" onclick="shiftWeek(0, true)">This week</button>
          </div>
          <div id="scheduleDays"></div>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('clock') ?> Reminders</h2>
          </div>
          <ul class="rule-list">
            <li><span class="r-num">1</span><div><b>Segregate at source.</b> Separate biodegradable, non-biodegradable, and recyclable waste before collection day.</div></li>
            <li><span class="r-num">2</span><div><b>Use the correct container.</b> Biodegradable in green bags, recyclables in clear bags, non-biodegradable in black bags.</div></li>
            <li><span class="r-num">3</span><div><b>Bring out within the window.</b> Only set waste out on the scheduled day, within the collection window.</div></li>
            <li><span class="r-num">4</span><div><b>No segregation, no collection.</b> Unsegregated waste may be left behind by the collection team.</div></li>
          </ul>
          <div class="next-hint" style="font-size:12.5px;color:var(--muted);border-top:1px solid var(--hairline-2);padding-top:14px;margin-top:6px">
            Need to change puroks? Update it in <a href="#profile" data-goto="profile">My profile</a> and your schedule updates automatically.
          </div>
        </section>
      </div>
    </section>

    <!-- ============================ ALERTS ============================ -->
    <section id="alerts" class="mt-3">
      <p class="section-title">Truck alerts</p>

      <section class="panel mb-2">
        <div class="panel-head">
          <h2><?= icon('truck') ?> Latest dispatch</h2>
        </div>
        <div id="alertLatest"></div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('bell') ?> Dispatch notifications</h2>
        </div>
        <div id="alertHistory"></div>
      </section>
    </section>

    <!-- ============================ NOTICES ============================ -->
    <section id="notices" class="mt-3">
      <p class="section-title">Barangay notices</p>
      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('megaphone') ?> Latest notices</h2>
          <span class="right">For <?= esc($user['zone_name'] ?? 'your purok') ?> and the whole barangay</span>
        </div>
        <div id="announcements"></div>
      </section>
    </section>

    <!-- ============================ REPORTS ============================ -->
    <section id="reports" class="mt-3">
      <p class="section-title">My missed-pickup reports</p>

      <div class="grid grid--2" style="margin-bottom:26px">
        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('flag') ?> Report a missed pickup</h2>
            <span class="right">Three steps</span>
          </div>
          <!-- ==== REPORT MISSED PICKUP FORM ==== -->
          <form id="reportForm" novalidate>
            <div class="field">
              <label for="reportWaste">What was not collected?</label>
              <select class="select" id="reportWaste">
                <?php foreach ($wasteTypes as $w): ?>
                  <?php if (empty($w['is_active'])) continue; ?>
                  <option value="<?= esc($w['code']) ?>"><?= esc($w['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field-error"></p>
            </div>

            <div class="field">
              <label for="reportAddress">Address</label>
              <input class="input" type="text" id="reportAddress" maxlength="160" value="<?= esc($user['household'] ?? '') ?>" placeholder="Street / household">
              <p class="field-error"></p>
            </div>

            <div class="field">
              <label for="reportPhoto">Photo <span class="opt">optional, max 5 MB</span></label>
              <!-- ==== PHOTO UPLOAD (missed pickup) ==== -->
              <label class="upload" id="uploadZone">
                <input type="file" id="reportPhoto" accept="image/jpeg,image/png,image/webp">
                <div class="u-ico"><?= icon('camera') ?></div>
                <div class="u-main" id="uploadMain">Attach a photo of the uncollected waste</div>
                <div class="u-sub" id="uploadSub">JPEG, PNG, or WebP</div>
              </label>
            </div>

            <div class="field">
              <label for="reportNotes">Notes <span class="opt">optional</span></label>
              <textarea class="input" id="reportNotes" maxlength="500" rows="2" placeholder="Short description of the missed pickup"></textarea>
            </div>

            <button type="submit" class="btn btn--primary" style="width:100%">Submit report</button>
          </form>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('fileText') ?> Report history</h2>
            <span class="right" id="myReportsCount"></span>
          </div>
          <div id="myReports"></div>
        </section>
      </div>
    </section>

    <!-- ============================ NOTIFICATIONS ============================ -->
    <section id="notifications" class="mt-3">
      <p class="section-title">Notifications</p>
      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('bell') ?> All notifications</h2>
          <div style="display:flex;gap:8px">
            <button type="button" class="btn btn--ghost btn--sm" onclick="toggleArchivedMode()">Show archived</button>
            <button type="button" class="btn btn--ghost btn--sm" onclick="markAllRead()">Mark all read</button>
            <button type="button" class="btn btn--ghost btn--sm" onclick="deleteAllNotifications()">Delete all</button>
          </div>
        </div>
        <div id="notifListFull"></div>
      </section>
    </section>

    <!-- ============================ PROFILE ============================ -->
    <section id="profile" class="mt-3">
      <p class="section-title">My profile</p>

      <div class="grid grid--2">
        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('users') ?> Account details</h2>
          </div>
          <!-- ==== PROFILE FORM ==== -->
          <form id="profileForm" novalidate>
            <div class="field">
              <label for="pfName">Full name</label>
              <input class="input" type="text" id="pfName" maxlength="90">
              <p class="field-error"></p>
            </div>
            <div class="form-row">
              <div class="field">
                <label for="pfEmail">Email</label>
                <input class="input" type="email" id="pfEmail" maxlength="160">
                <p class="field-error"></p>
              </div>
              <div class="field">
                <label for="pfUser">Username</label>
                <input class="input" type="text" id="pfUser" maxlength="60">
                <p class="field-error"></p>
              </div>
            </div>
            <div class="form-row">
              <div class="field">
                <label for="pfZone">Purok / zone</label>
                <select class="select" id="pfZone">
                  <option value="">Select your purok…</option>
                  <?php foreach ($zones as $z): ?>
                    <option value="<?= (int)$z['id'] ?>"><?= esc($z['name']) ?> — <?= esc($z['code']) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="field-error"></p>
              </div>
              <div class="field">
                <label for="pfPhone">Mobile</label>
                <input class="input" type="tel" id="pfPhone" maxlength="24">
                <p class="field-error"></p>
              </div>
            </div>
            <div class="field">
              <label for="pfHouse">Household / street address</label>
              <input class="input" type="text" id="pfHouse" maxlength="120">
              <p class="field-error"></p>
            </div>
            <button type="submit" class="btn btn--primary" style="width:100%">Save changes</button>
          </form>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2><?= icon('lock') ?> Change password</h2>
          </div>
          <!-- ==== CHANGE PASSWORD FORM ==== -->
          <form id="passwordForm" novalidate>
            <div class="field">
              <label for="pwCurrent">Current password</label>
              <input class="input" type="password" id="pwCurrent" maxlength="72" autocomplete="current-password">
              <p class="field-error"></p>
            </div>
            <div class="field">
              <label for="pwNew">New password</label>
              <input class="input" type="password" id="pwNew" maxlength="72" autocomplete="new-password">
              <div class="pass-strength" id="pwNewStrength"><i></i></div>
              <ul class="pass-rules" id="pwNewRules">
                <li data-rule="len">At least 8 characters</li>
                <li data-rule="letter">Contains a letter</li>
                <li data-rule="num">Contains a number</li>
                <li data-rule="space">No spaces</li>
                <li data-rule="sym">No special characters</li>
              </ul>
              <p class="field-error"></p>
            </div>
            <div class="field">
              <label for="pwConfirm">Confirm new password</label>
              <input class="input" type="password" id="pwConfirm" maxlength="72" autocomplete="new-password">
              <p class="field-error"></p>
            </div>
            <button type="submit" class="btn btn--primary" style="width:100%">Update password</button>
          </form>
        </section>
      </div>
    </section>

  </main>
  </div>
</div>

<!-- ==== NOTIFICATIONS DRAWER ==== -->
<div class="notif" id="notifPanel">
  <div class="notif-head">
    <b>Notifications</b>
    <button onclick="markAllRead()">Mark all read</button>
  </div>
  <div id="notifList"></div>
</div>

<!-- ==== REPORT DETAIL + FOLLOW-UP MODAL ==== -->
<div class="modal-backdrop" id="reportModal">
  <div class="modal modal--wide">
    <div class="rd-head">
      <div>
        <h3 id="reportDetailTitle">Report details</h3>
        <p class="m-sub" id="reportDetailSub"></p>
      </div>
      <span id="reportDetailBadge"></span>
    </div>
    <div id="reportDetailPhoto"></div>
    <div id="reportThread" class="thread"></div>
    <div id="followupBox" class="reopen-box" style="display:none">
      <div class="rb-head">
        <b>Still not collected?</b>
        <span>Reopen this report instead of filing a new one — the barangay will see the whole history.</span>
      </div>
      <div class="field">
        <label for="followupMsg">Your follow-up message</label>
        <textarea class="input" id="followupMsg" maxlength="1000" rows="3" placeholder="e.g. Hindi pa rin po kami napuntahan hanggang ngayon."></textarea>
        <p class="field-error"></p>
      </div>
      <button type="button" class="btn btn--primary" id="followupBtn" style="width:100%">Not yet collected — reopen report</button>
    </div>
    <div class="m-actions">
      <button type="button" class="btn btn--ghost" onclick="closeModal('reportModal')">Close</button>
    </div>
  </div>
</div>

<div class="toast"><span class="t-dot"></span><span class="t-text"></span></div>

<script>
window.KOLEKTA = {
  user: <?= json_encode($user, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  zones: <?= json_encode($zones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  wasteTypes: <?= json_encode($wasteTypes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
<script src="assets/js/app.js"></script>
<script src="assets/js/sidebar.js"></script>
<script src="assets/js/user.js"></script>
</body>
</html>