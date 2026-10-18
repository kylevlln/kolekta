/* Kolekta — resident dashboard. */

const KO = window.KOLEKTA || {};
const WASTE_TYPES = KO.wasteTypes || [];
const USER = KO.user || {};

const TYPE_LABEL = {};
const TYPE_CLASS = {
  biodegradable: 'bio',
  non_biodegradable: 'non-bio',
  recyclable: 'recyc',
  mixed: 'mixed',
};
const WEEK_SHORT = {
  biodegradable: 'Bio',
  recyclable: 'Rec',
  non_biodegradable: 'Non-bio',
  mixed: 'Mixed',
};
WASTE_TYPES.forEach((w) => { TYPE_LABEL[w.code] = w.name; });

const STATUS_BADGE = {
  pending: 'b-pending',
  investigating: 'b-investigating',
  resolved: 'b-resolved',
  reopened: 'b-reopened',
  archived: 'b-archived',
};

let lastDispatchId = null;
let notifData = [];
let openReportId = null;
let notifArchivedMode = false;


document.addEventListener('DOMContentLoaded', init);

async function init() {
  initNav();
  initBell();

  await Promise.all([
    loadSchedule(),
    loadDispatch(),
    loadAnnouncements(),
    loadMyReports(),
    loadProfile(),
    loadAlerts(),
  ]);

  await loadNotifCount();
  initReportForm();
  initProfileForms();
  initModals();
}

function initModals() {
  document.querySelectorAll('.modal-backdrop').forEach((m) => {
    m.addEventListener('click', (e) => {
      if (e.target === m) closeModal(m.id);
    });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach((m) => closeModal(m.id));
    }
  });
}

function gotoSection(hash) {
  const id = (hash || '#overview').replace('#', '');
  const sections = document.querySelectorAll('main > section[id]');
  let found = false;
  sections.forEach((s) => {
    const match = s.id === id;
    s.style.display = match ? '' : 'none';
    if (match) found = true;
  });
  if (!found) {
    sections.forEach((s) => { s.style.display = s.id === 'overview' ? '' : 'none'; });
  }
  document.querySelectorAll('.sb-nav a').forEach((a) => {
    a.classList.toggle('active', a.getAttribute('href') === '#' + id);
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function initNav() {
  document.querySelectorAll('.sb-nav a').forEach((a) => {
    a.addEventListener('click', (e) => {
      e.preventDefault();
      location.hash = a.getAttribute('href');
      gotoSection(location.hash);
    });
  });
  document.querySelectorAll('[data-goto]').forEach((b) => {
    b.addEventListener('click', () => {
      const h = '#' + b.getAttribute('data-goto');
      location.hash = h;
      gotoSection(h);
    });
  });
  window.addEventListener('hashchange', () => gotoSection(location.hash));
  gotoSection(location.hash || '#overview');
}

function initBell() {
  const panel = document.getElementById('notifPanel');
  document.addEventListener('click', (e) => {
    if (panel.classList.contains('open') && !panel.contains(e.target) && e.target.id !== 'bell') {
      panel.classList.remove('open');
    }
  });
}

/* ==== WASTE TYPE PICKER ==== */
function initWastePick() {
  const pick = document.getElementById('wastePick');
  if (!pick || !WASTE_TYPES.length) return;
  pick.innerHTML = WASTE_TYPES.map((w) => `
    <label class="pick-opt">
      <input type="radio" name="waste" value="${escapeHtml(w.code)}">
      <span class="lbl">${escapeHtml(w.name)}</span>
    </label>`).join('');
  const first = pick.querySelector('input');
  if (first) first.checked = true;
}

/* -------------------------------------------------------- schedule */
/* ==== WEEKLY COLLECTION SCHEDULE ==== */
let weekOffset = 0;

async function loadSchedule() {
  try {
    const data = await API.get('api/schedule.php?week_offset=' + weekOffset);
    renderWeek(data.grid);
    renderNext(data.next);
    renderScheduleDays(data.grid);
    const zoneName = (data.zone && data.zone.name) || '';
    document.getElementById('weekZone').textContent = zoneName;
    document.getElementById('scheduleZone').textContent = zoneName;
    if (document.getElementById('weekLabel')) {
      document.getElementById('weekLabel').textContent = data.week_label || '';
    }
  } catch (err) {
    toast(err.message, true);
  }
}

function shiftWeek(delta, reset) {
  if (reset) weekOffset = 0;
  else weekOffset += delta;
  weekOffset = Math.max(-52, Math.min(52, weekOffset));
  loadSchedule();
}

function renderWeek(grid) {
  const el = document.getElementById('week');
  if (!el) return;
  el.innerHTML = '';

  const now = new Date();
  const todayNum = ((now.getDay() + 6) % 7) + 1; // Mon = 1

  grid.forEach((day) => {
    const div = document.createElement('div');
    div.className = 'day' + (day.weekday === todayNum ? ' today' : '') + (day.waste_types.length === 0 ? ' empty' : '');

    const name = document.createElement('span');
    name.className = 'd-name';
    name.textContent = day.short;

    const dateEl = document.createElement('span');
    dateEl.className = 'd-date';
    if (day.date) {
      dateEl.textContent = String(parseInt(day.date.split('-')[2], 10)).padStart(2, '0');
    } else {
      // Fallback for older responses.
      const date = new Date(now);
      const diff = ((day.weekday - todayNum) + 7) % 7;
      date.setDate(now.getDate() + diff);
      dateEl.textContent = String(date.getDate()).padStart(2, '0');
    }

    const types = document.createElement('div');
    types.className = 'd-types';
    day.waste_types.forEach((t) => {
      const code = t && t.code ? t.code : t;
      const full = TYPE_LABEL[code] || (t && t.label) || code;
      const pill = document.createElement('span');
      pill.className = 'type-dot ' + (TYPE_CLASS[code] || 'mixed');
      pill.textContent = WEEK_SHORT[code] || full.split(' ')[0];
      pill.title = full;
      types.appendChild(pill);
    });

    div.append(name, dateEl, types);
    el.appendChild(div);
  });
}

function renderNext(next) {
  document.getElementById('nextIn').textContent = next
    ? (next.in_days === 0 ? 'Today' : next.in_days === 1 ? 'Tomorrow' : 'In ' + next.in_days + ' days')
    : 'No schedule';

  document.getElementById('nextDay').textContent = next ? next.weekday : '—';
  document.getElementById('nextDate').textContent = next ? fmtDate(next.date) : '';
  const types = document.getElementById('nextTypes');
  types.innerHTML = '';
  if (next) {
    next.waste_types.forEach((t) => {
      const code = t && t.code ? t.code : t;
      const pill = document.createElement('span');
      pill.className = 'type-dot ' + (TYPE_CLASS[code] || 'mixed');
      pill.textContent = TYPE_LABEL[code] || (t && t.label) || code;
      types.appendChild(pill);
    });
  }
}

function renderScheduleDays(grid) {
  const el = document.getElementById('scheduleDays');
  if (!el) return;

  const now = new Date();
  const todayNum = ((now.getDay() + 6) % 7) + 1;

  el.innerHTML = grid.map((day) => {
    const isToday = day.weekday === todayNum;
    const rows = day.waste_types.length ? day.waste_types.map((t) => `
      <div class="sched-day-row">
        <span class="type-dot ${TYPE_CLASS[t.code] || 'mixed'}">${escapeHtml(t.label)}</span>
        <span class="mono">${t.window_start ? escapeHtml(t.window_start) + '–' + escapeHtml(t.window_end) : 'Flexible'}</span>
      </div>`).join('')
      : '<div class="sched-day-row muted">No collection — rest day</div>';

    return `
      <div class="sched-day ${isToday ? 'today' : ''}">
        <div class="sd-head">
          <b>${escapeHtml(day.label)}</b>
          ${isToday ? '<span class="badge b-active">Today</span>' : ''}
        </div>
        ${rows}
      </div>`;
  }).join('');
}

/* -------------------------------------------------------- dispatch */
/* ==== DISPATCH ALERT BANNER ==== */
async function loadDispatch() {
  try {
    const data = await API.get('api/dispatch.php?action=latest');
    const latest = data.latest;
    renderAlertLatest(data.latest);

    if (!latest) return;
    const then = new Date(latest.dispatched_at.replace(' ', 'T'));
    const hoursAgo = (Date.now() - then.getTime()) / 36e5;
    if (hoursAgo > 48) return;
    if (sessionStorage.getItem('kolekta_banner_hidden') === String(latest.id)) return;

    const banner = document.getElementById('dispatchBanner');
    const zoneName = USER.zone_name || 'your purok';
    document.getElementById('bannerTitle').textContent = 'Collection underway — ' + zoneName;
    document.getElementById('bannerBody').textContent =
      `The team is collecting ${latest.waste_label.toLowerCase()} in ${zoneName}. ` +
      `Dispatched ${fmtTime(latest.dispatched_at)}.`;
    banner.style.display = 'flex';
    lastDispatchId = latest.id;
  } catch (err) { /* banner is optional */ }
}

function hideBanner() {
  document.getElementById('dispatchBanner').style.display = 'none';
  if (lastDispatchId !== null) {
    sessionStorage.setItem('kolekta_banner_hidden', String(lastDispatchId));
  }
}

function renderAlertLatest(latest) {
  const el = document.getElementById('alertLatest');
  if (!el) return;
  if (!latest) {
    el.innerHTML = '<div class="empty">No active dispatch alerts for your purok right now.</div>';
    return;
  }
  const zoneName = USER.zone_name || 'your purok';
  el.innerHTML = `
    <div class="dispatch-live">
      <div class="dl-ico">${icon('truck')}</div>
      <div class="dl-body">
        <div class="ri-top">
          <b>Collection underway — ${escapeHtml(zoneName)}</b>
          <span class="badge b-active">Active</span>
        </div>
        <div class="ri-meta">
          Collecting <b>${escapeHtml(latest.waste_label.toLowerCase())}</b> · dispatched ${escapeHtml(fmtTime(latest.dispatched_at))} by ${escapeHtml(latest.triggered_by_name || 'the barangay')}
        </div>
        ${latest.message ? `<div class="ri-note">${escapeHtml(latest.message)}</div>` : ''}
      </div>
    </div>`;
}

async function loadAlerts() {
  try {
    const items = await API.get('api/notifications.php?action=list' + (notifArchivedMode ? '&archived=1' : ''));
    notifData = items;
    renderAlertHistory(items);
    renderNotifListFull();
  } catch (err) { /* ignore */ }
}

function renderAlertHistory(items) {
  const el = document.getElementById('alertHistory');
  if (!el) return;
  const dispatchNotifs = items.filter((n) => n.kind === 'dispatch');
  if (!dispatchNotifs.length) {
    el.innerHTML = '<div class="empty">No past truck alerts recorded.</div>';
    return;
  }
  el.innerHTML = dispatchNotifs.map((n) => `
    <div class="notif-item ${n.is_read ? '' : 'unread'} kind-dispatch">
      <div class="n-ico">${icon('truck')}</div>
      <div class="n-body">
        <b>${escapeHtml(n.title)}</b>
        <p>${escapeHtml(n.body)}</p>
        <div class="n-time">${escapeHtml(fmtTime(n.created_at))}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          ${notifArchivedMode ? `<button class="btn btn--ghost btn--sm" type="button" onclick="restoreNotif(${n.id})">Restore</button>` : ''}
          <button class="btn btn--ghost btn--sm" type="button" onclick="deleteNotif(${n.id})">Delete</button>
        </div>
      </div>
    </div>`).join('');
}

/* --------------------------------------------------- announcements */
/* ==== BARANGAY NOTICES ==== */
async function loadAnnouncements() {
  const el = document.getElementById('announcements');
  try {
    const items = await API.get('api/announcements.php?action=list');
    if (!items.length) {
      el.innerHTML = '<div class="empty">No current announcements.</div>';
      return;
    }
    el.innerHTML = items.map((a) => `
      <div class="ann-item ${a.kind === 'cancellation' ? 'kind-cancel' : ''}">
        <div class="a-ico">${icon(a.kind === 'cancellation' ? 'x' : 'megaphone')}</div>
        <div class="ann-body">
          <b>${escapeHtml(a.title)}</b>
          <p>${escapeHtml(a.body)}</p>
          <div class="ann-meta">${fmtTime(a.created_at)} · ${escapeHtml(a.zone_name || 'All zones')}</div>
        </div>
      </div>`).join('');
  } catch (err) {
    el.innerHTML = '<div class="empty">Notices are unavailable right now.</div>';
  }
}

/* ---------------------------------------------------- report form */
/* ==== REPORT MISSED PICKUP FORM ==== */
function initReportForm() {
  const form = document.getElementById('reportForm');
  const photo = document.getElementById('reportPhoto');
  if (!form || !photo) return;

  /* ==== PHOTO UPLOAD ==== */
  photo.addEventListener('change', () => {
    const zone = document.getElementById('uploadZone');
    if (photo.files && photo.files[0]) {
      const f = photo.files[0];
      zone.classList.add('has-file');
      document.getElementById('uploadMain').textContent = f.name;
      document.getElementById('uploadSub').textContent =
        Math.round(f.size / 1024) + ' KB · ready to attach';
      if (f.size > 5 * 1024 * 1024) {
        toast('Photo exceeds the 5 MB limit.', true);
        photo.value = '';
        zone.classList.remove('has-file');
        document.getElementById('uploadMain').textContent = 'Attach a photo of the uncollected waste';
        document.getElementById('uploadSub').textContent = 'JPEG, PNG, or WebP';
      }
    }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const address = document.getElementById('reportAddress').value.trim();
    if (!address) return addFieldError(document.getElementById('reportAddress'), 'Enter the address of the missed pickup.');

    const submitBtn = form.querySelector('button[type="submit"]');
    const original = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner"></span> Submitting…';

    const wasteType = (document.getElementById('reportWaste') || {}).value || 'mixed';
    const fd = new FormData();
    fd.append('waste_type', wasteType);
    fd.append('address', address);
    fd.append('notes', document.getElementById('reportNotes').value.trim());
    if (photo.files[0]) fd.append('photo', photo.files[0]);

    try {
      await API.call('api/reports.php?action=create', { method: 'POST', body: fd });
      toast('Report submitted. The barangay has been notified.');
      form.reset();
      document.getElementById('uploadZone').classList.remove('has-file');
      document.getElementById('uploadMain').textContent = 'Attach a photo of the uncollected waste';
      document.getElementById('uploadSub').textContent = 'JPEG, PNG, or WebP';
      const reportWaste = document.getElementById('reportWaste');
      if (reportWaste) reportWaste.selectedIndex = 0;
      await loadMyReports();
      await loadNotifCount();
    } catch (err) {
      toast(err.message, true);
      submitBtn.disabled = false;
      submitBtn.innerHTML = original;
    }
  });
}

/* ------------------------------------------------------ my reports */
/* ==== MY REPORTS LIST ==== */
async function loadMyReports() {
  const el = document.getElementById('myReports');
  try {
    const rows = await API.get('api/reports.php?action=my');
    const count = document.getElementById('myReportsCount');
    if (count) count.textContent = rows.length
      ? rows.length + ' filed report' + (rows.length === 1 ? '' : 's')
      : '';
    if (!rows.length) {
      el.innerHTML = '<div class="empty">No reports filed yet.</div>';
      return;
    }
    el.innerHTML = rows.map((r) => `
      <div class="report-item report-item--mine${r.status === 'reopened' ? ' report-item--reopened' : ''}">
        <div class="ph">${r.photo_path
          ? `<img src="${escapeHtml(r.photo_path)}" alt="Report photo">`
          : icon('image')}
        </div>
        <div class="ri-body">
          <div class="ri-top">
            <b>${escapeHtml(r.address)}</b>
            <span class="badge ${STATUS_BADGE[r.status] || ''}">${escapeHtml(r.status_label || r.status)}</span>
          </div>
          <div class="ri-meta">${escapeHtml(r.waste_label)}${r.zone_name ? ' · ' + escapeHtml(r.zone_name) : ''}</div>
          ${r.resolution_note && r.status !== 'reopened' ? `<div class="ri-note" style="color:var(--fern)">${escapeHtml(r.resolution_note)}</div>` : ''}
          ${r.status === 'reopened' ? '<div class="ri-note" style="color:#775C23">Follow-up sent — the barangay has been notified again.</div>' : ''}
          ${r.secondary_dispatch ? '<div class="ri-time"><span class="badge b-active">Secondary collection arranged</span></div>' : ''}
          <div class="ri-time">Filed ${escapeHtml(fmtTime(r.created_at))}${r.status === 'resolved' && r.resolved_at ? ` · resolved ${escapeHtml(fmtTime(r.resolved_at))}` : ''}</div>
        </div>
        <div class="ri-actions">
          <button class="btn btn--ghost btn--sm" data-view-report="${r.id}">${r.status === 'resolved' ? 'View & follow up' : 'View details'}</button>
        </div>
      </div>`).join('');

    el.querySelectorAll('[data-view-report]').forEach((b) => {
      b.addEventListener('click', () => openReportDetail(parseInt(b.getAttribute('data-view-report'), 10)));
    });
  } catch (err) {
    el.innerHTML = '<div class="empty">Could not load your reports.</div>';
  }
}

/* ------------------------------------------------- report detail + follow-up */
function openModal(id) {
  document.querySelectorAll('.modal-backdrop.open').forEach((m) => m.classList.remove('open'));
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}

/* ==== REPORT DETAIL MODAL ==== */
async function openReportDetail(id) {
  openReportId = id;
  try {
    const data = await API.get('api/reports.php?action=detail&id=' + id);
    const r = data.report;
    document.getElementById('reportDetailTitle').textContent = r.waste_label + ' — ' + r.address;
    const sub = document.getElementById('reportDetailSub');
    sub.innerHTML = `Filed ${escapeHtml(fmtTime(r.created_at))}${r.zone_name ? ' · ' + escapeHtml(r.zone_name) : ''}`;
    document.getElementById('reportDetailBadge').innerHTML = `
      <span class="badge ${STATUS_BADGE[r.status] || ''}">${escapeHtml(r.status_label || r.status)}</span>`;
    const photo = document.getElementById('reportDetailPhoto');
    photo.innerHTML = '';
    if (r.photo_path) {
      photo.innerHTML = `<img src="${escapeHtml(r.photo_path)}" alt="Report photo">`;
    }
    document.getElementById('reportThread').innerHTML = renderReportThread(data.events, r);
    const box = document.getElementById('followupBox');
    if (r.reopen_allowed) {
      box.style.display = '';
      document.getElementById('followupMsg').value = '';
      document.getElementById('followupMsg').closest('.field').classList.remove('has-error');
      box.querySelector('.field-error').textContent = '';
    } else {
      box.style.display = 'none';
    }
    openModal('reportModal');
  } catch (err) {
    toast(err.message, true);
  }
}

function statusPill(status, label) {
  return `<span class="badge ${STATUS_BADGE[status] || ''}">${escapeHtml(label || status)}</span>`;
}

function renderReportThread(events, report) {
  if (!events || !events.length) return '<div class="empty">No history recorded for this report yet.</div>';

  return events.map((e) => {
    const mine = e.actor_role === 'resident';
    const who = mine ? 'You' : (e.actor_name || 'Barangay');
    const label = e.label || 'Update';
    const msg = e.message
      ? `<p class="t-msg">${escapeHtml(e.message)}</p>`
      : `<p class="t-msg" style="color:var(--muted)">${e.kind === 'created' ? 'No additional details provided.' : 'No note added.'}</p>`;
    const fromPill = e.from_status
      ? statusPill(e.from_status, STATUS_LABEL[e.from_status] || e.from_status) + '<span class="arrow">' + icon('arrowRight') + '</span>'
      : '';
    const statusLine = e.to_status
      ? `<div class="t-status">${fromPill}${statusPill(e.to_status, STATUS_LABEL[e.to_status] || e.to_status)}</div>`
      : '';

    return `
      <div class="thread-item ${mine ? 't-resident' : 't-admin'}${e.kind === 'followup' ? ' t-followup' : ''}">
        <span class="t-ico ${mine ? 't-ico-resident' : 't-ico-admin'}">${mine ? 'R' : 'A'}</span>
        <div class="t-body">
          <div class="t-head">
            <b>${escapeHtml(who)} — ${escapeHtml(label)}</b>
            <span class="t-time">${escapeHtml(fmtTime(e.created_at))}</span>
          </div>
          ${statusLine}
          ${msg}
        </div>
      </div>`;
  }).join('');
}

const STATUS_LABEL = {
  pending: 'Pending',
  investigating: 'Investigating',
  resolved: 'Resolved',
  reopened: 'Reopened',
  archived: 'Archived',
};

document.getElementById('followupBtn').addEventListener('click', submitReportFollowUp);

/* ==== REPORT FOLLOW-UP (reopen) ==== */
async function submitReportFollowUp() {
  const msg = document.getElementById('followupMsg');
  const btn = document.getElementById('followupBtn');
  const message = msg.value.trim();
  if (!message) {
    const wrap = msg.closest('.field');
    wrap.classList.add('has-error');
    wrap.querySelector('.field-error').textContent = 'Tell the barangay what is still pending.';
    return;
  }
  const id = openReportId;
  if (!id) return;

  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Reopening…';

  try {
    await API.post('api/reports.php?action=followup', { id, message });
    toast('Report reopened. The barangay has been notified.');
    closeModal('reportModal');
    openReportId = null;
    await Promise.all([loadMyReports(), loadNotifCount()]);
  } catch (err) {
    toast(err.message, true);
    btn.disabled = false;
    btn.innerHTML = original;
  }
}

/* ------------------------------------------------------ profile */
/* ==== PROFILE FORM ==== */
async function loadProfile() {
  try {
    const p = await API.get('api/profile.php?action=me');
    document.getElementById('pfName').value = p.name || '';
    document.getElementById('pfEmail').value = p.email || '';
    document.getElementById('pfUser').value = p.username || '';
    document.getElementById('pfPhone').value = p.phone || '';
    document.getElementById('pfHouse').value = p.household || '';
    if (p.zone_id) document.getElementById('pfZone').value = p.zone_id;
  } catch (err) {
    toast('Could not load your profile.', true);
  }
}

function initProfileForms() {
  /* Paint or clear ONE field, leaving every other field untouched. */
  function showError(el, message) {
    const wrap = el.closest('.field');
    if (wrap) wrap.classList.add('has-error');
    const msg = wrap ? wrap.querySelector('.field-error') : null;
    if (msg) { msg.textContent = message; msg.classList.remove('ok'); }
    el.classList.add('err');
    el.classList.remove('ok');
  }
  function clearError(el) {
    const wrap = el.closest('.field');
    if (wrap) wrap.classList.remove('has-error');
    const msg = wrap ? wrap.querySelector('.field-error') : null;
    if (msg) { msg.textContent = ''; msg.classList.remove('ok'); }
    el.classList.remove('err', 'ok');
  }
  /* Wipe every field's styling before a fresh validation pass. */
  function clearAllErrors(form) {
    form.querySelectorAll('.field').forEach((f) => {
      f.classList.remove('has-error');
      const m = f.querySelector('.field-error');
      if (m) { m.textContent = ''; m.classList.remove('ok'); }
      const inp = f.querySelector('.input, .select');
      if (inp) inp.classList.remove('err', 'ok');
    });
  }

  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  /* Rule for a single profile field: '' means valid, otherwise the message. */
  function profileFieldState(id, value) {
    const v = String(value).trim();
    switch (id) {
      case 'pfName':
        if (v.length < 3) return 'Enter your full name.';
        if (v.length > 90) return 'Keep your name to 90 characters or fewer.';
        return '';
      case 'pfEmail':
        if (v === '') return 'Email is required.';
        if (!EMAIL_RE.test(v)) return 'Please enter a valid email address.';
        return '';
      case 'pfUser':
        if (v === '') return 'Username is required.';
        if (v.length > 60) return 'Keep your username to 60 characters or fewer.';
        return '';
      case 'pfPhone':
        if (v === '') return 'Mobile number is required.';
        if (!/^[0-9+\-(). ]{7,24}$/.test(v)) return 'Enter a valid mobile number.';
        return '';
      case 'pfHouse':
        if (v === '') return 'Household / street address is required.';
        if (v.length > 120) return 'Keep the household name to 120 characters or fewer.';
        return '';
      case 'pfZone':
        return v === '' ? 'Choose your purok.' : '';
      default:
        return '';
    }
  }

  /* ==== PROFILE FORM ==== */
  const profileForm = document.getElementById('profileForm');
  if (profileForm) {
    let submitAttempted = false;

    function repaintField(el, force) {
      const wrap = el.closest('.field');
      const hadError = !!(wrap && wrap.classList.contains('has-error'));
      const msg = profileFieldState(el.id, el.value);
      if (msg) {
        if (force || hadError) showError(el, msg);
      } else {
        clearError(el);
      }
    }

    ['pfName', 'pfEmail', 'pfUser', 'pfPhone', 'pfHouse', 'pfZone'].forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', () => repaintField(el, submitAttempted));
      el.addEventListener('change', () => repaintField(el, true));
      el.addEventListener('blur', () => repaintField(el, true));
    });

    profileForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      submitAttempted = true;

      const nameEl = document.getElementById('pfName');
      const emailEl = document.getElementById('pfEmail');
      const userEl = document.getElementById('pfUser');
      const phoneEl = document.getElementById('pfPhone');
      const houseEl = document.getElementById('pfHouse');
      const zoneEl = document.getElementById('pfZone');

      /* Reset once, then paint EVERY problem so they appear together. */
      clearAllErrors(profileForm);
      const invalid = [];
      const check = (id, el) => {
        const msg = profileFieldState(id, el.value);
        if (msg) { showError(el, msg); invalid.push(el); }
      };
      check('pfName', nameEl);
      check('pfEmail', emailEl);
      check('pfUser', userEl);
      check('pfPhone', phoneEl);
      check('pfHouse', houseEl);
      check('pfZone', zoneEl);

      if (invalid.length) {
        invalid[0].focus();
        return;
      }

      const btn = profileForm.querySelector('button[type="submit"]');
      const original = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Saving…';
      try {
        const res = await API.post('api/profile.php?action=update', {
          name: nameEl.value.trim(),
          email: emailEl.value.trim(),
          username: userEl.value.trim(),
          phone: phoneEl.value.trim(),
          household: houseEl.value.trim(),
          zone_id: zoneEl.value,
        });
        toast(res.message || 'Profile updated.');
        if (res.zone_changed) {
          setTimeout(() => window.location.reload(), 1200);
        }
      } catch (err) {
        const m = err.message || 'Could not save your profile.';
        /* surface API messages on the fields where they apply */
        if (/email/i.test(m)) addFieldError(emailEl, m);
        else if (/username/i.test(m)) addFieldError(userEl, m);
        else if (/purok|zone/i.test(m)) addFieldError(zoneEl, m);
        else if (/household|address/i.test(m)) addFieldError(houseEl, m);
        else if (/phone|mobile/i.test(m)) addFieldError(phoneEl, m);
        else if (/name/i.test(m)) addFieldError(nameEl, m);
        else toast(m, true);
      } finally {
        btn.disabled = false;
        btn.innerHTML = original;
      }
    });
  }

  /* ==== CHANGE PASSWORD FORM ==== */
  const passwordForm = document.getElementById('passwordForm');
  if (passwordForm) {
    const pwCurrent = document.getElementById('pwCurrent');
    const pwNew = document.getElementById('pwNew');
    const pwConfirm = document.getElementById('pwConfirm');
    const pwRules = document.getElementById('pwNewRules');
    const pwStrength = document.getElementById('pwNewStrength');
    let pwTouched = false;

    function updatePwRules() {
      if (!pwNew) return false;
      if (pwTouched && pwRules) pwRules.classList.add('show');
      const field = pwNew.closest('.field');
      const feedback = field ? field.querySelector('.field-error') : null;
      const allOk = renderPasswordRules(pwNew, pwRules, feedback);
      if (pwStrength) {
        const s = PASS_RULES.filter((r) => r.test(pwNew.value)).length;
        const i = pwStrength.querySelector('i');
        i.style.width = (s / PASS_RULES.length * 100) + '%';
        if (s === PASS_RULES.length) i.className = 's3';
        else if (s >= 2) i.className = 's2';
        else i.className = '';
      }
      if (pwConfirm && pwConfirm.value) matchConfirm(pwNew, pwConfirm);
      return allOk;
    }

    pwNew.addEventListener('input', () => { pwTouched = true; updatePwRules(); });
    pwNew.addEventListener('blur', () => { if (pwNew.value) { pwTouched = true; updatePwRules(); } });
    pwCurrent.addEventListener('input', () => clearError(pwCurrent));
    pwConfirm.addEventListener('input', () => { clearError(pwConfirm); if (pwNew.value) matchConfirm(pwNew, pwConfirm); });

    passwordForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      pwTouched = true;

      clearAllErrors(passwordForm);
      const invalid = [];
      if (pwCurrent.value === '') {
        showError(pwCurrent, 'Enter your current password.');
        invalid.push(pwCurrent);
      }

      if (!updatePwRules()) {
        showError(pwNew, 'Password must be 8+ characters with letters and numbers, no spaces or special characters.');
        invalid.push(pwNew);
      }
      if (pwNew.value !== pwConfirm.value) {
        showError(pwConfirm, 'New passwords do not match.');
        invalid.push(pwConfirm);
      }

      if (invalid.length) {
        invalid[0].focus();
        return;
      }

      const btn = passwordForm.querySelector('button[type="submit"]');
      const original = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Updating…';
      try {
        const res = await API.post('api/profile.php?action=password', {
          current_password: pwCurrent.value,
          new_password: pwNew.value,
          new_password_confirm: pwConfirm.value,
        });
        toast(res.message || 'Password updated.');
        passwordForm.reset();
        pwTouched = false;
        if (pwRules) pwRules.classList.remove('show');
        if (pwStrength) {
          const i = pwStrength.querySelector('i');
          i.style.width = '0';
          i.className = '';
        }
        clearAllErrors(passwordForm);
      } catch (err) {
        const m = err.message || 'Could not update your password.';
        if (/current/i.test(m)) addFieldError(pwCurrent, m);
        else if (/match/i.test(m)) addFieldError(pwConfirm, m);
        else if (/space|letter|number|special|symbol|8|72|character/i.test(m)) {
          if (pwRules) pwRules.classList.add('show');
          addFieldError(pwNew, m);
        } else toast(m, true);
      } finally {
        btn.disabled = false;
        btn.innerHTML = original;
      }
    });
  }
}

/* ----------------------------------------------------- notifications */
/* ==== NOTIFICATIONS ==== */
async function loadNotifCount() {
  try {
    const data = await API.get('api/notifications.php?action=count');
    document.querySelector('.bell').classList.toggle('has', data.unread > 0);
    document.getElementById('navNotif').textContent = data.unread;
  } catch (e) { /* ignore */ }
}

function toggleNotifs() {
  const panel = document.getElementById('notifPanel');
  const open = panel.classList.toggle('open');
  if (open) loadNotifs();
}

async function loadNotifs() {
  const el = document.getElementById('notifList');
  const items = await API.get('api/notifications.php?action=list' + (notifArchivedMode ? '&archived=1' : ''));
  notifData = items;
  if (!items.length) {
    el.innerHTML = '<div class="empty">No notifications yet.</div>';
    return;
  }
  el.innerHTML = renderNotifItems(items);

  const unreadIds = items.filter((n) => !n.is_read).map((n) => n.id);
  if (unreadIds.length && sessionStorage.getItem('kolekta_notif_seen') !== unreadIds.join(',')) {
    sessionStorage.setItem('kolekta_notif_seen', unreadIds.join(','));
    setTimeout(async () => {
      await API.post('api/notifications.php?action=mark_read', { ids: unreadIds });
      await loadNotifCount();
      const badge = document.getElementById('bell');
      badge.classList.remove('has');
    }, 3500);
  }
}

async function notifs() {
  try {
    return await API.get('api/notifications.php?action=list');
  } catch (err) {
    return [];
  }
}

function notifIcon(kind) {
  if (kind === 'dispatch') return icon('truck');
  if (kind === 'report_update') return icon('flag');
  if (kind === 'announcement') return icon('megaphone');
  return icon('bell');
}

function renderNotifItems(items) {
  return items.map((n) => `
    <div class="notif-item ${n.is_read ? '' : 'unread'} kind-${n.kind}">
      <div class="n-ico">${notifIcon(n.kind)}</div>
      <div class="n-body">
        <b>${escapeHtml(n.title)}</b>
        <p>${escapeHtml(n.body)}</p>
        <div class="n-time">${escapeHtml(fmtTime(n.created_at))}</div>
        ${notifArchivedMode ? `<button class="btn btn--ghost btn--sm" type="button" onclick="restoreNotif(${n.id})">Restore</button>` : ''}
      </div>
    </div>`).join('');
}

function renderNotifListFull() {
  const el = document.getElementById('notifListFull');
  if (!el) return;
  if (!notifData.length) {
    el.innerHTML = '<div class="empty">No notifications yet.</div>';
    return;
  }
  el.innerHTML = renderNotifItems(notifData);
}

/* ==== MARK ALL READ ==== */
async function markAllRead() {
  try {
    await API.post('api/notifications.php?action=mark_read', {});
    document.getElementById('bell').classList.remove('has');
    if (document.getElementById('navNotif')) document.getElementById('navNotif').textContent = '0';
    notifData = notifData.map((n) => ({ ...n, is_read: 1 }));
    loadNotifs();
    renderNotifListFull();
    toast('All notifications marked as read.');
  } catch (err) {
    toast(err.message, true);
  }
}

/* ==== DELETE NOTIFICATION ==== */
async function deleteNotif(id) {
  if (!window.confirm('Are you sure you want to delete this notification? This action cannot be undone.')) return;
  try {
    await API.post('api/notifications.php?action=delete', { ids: [Number(id)] });
    notifData = notifData.filter((n) => Number(n.id) !== Number(id));
    if (notifData.length) { /* keep current view */ }
    loadNotifs();
    renderNotifListFull();
    loadNotifCount();
    toast('Notification deleted.');
  } catch (err) {
    toast(err.message, true);
  }
}

async function deleteAllNotifications() {
  if (!window.confirm('Are you sure you want to delete all notifications on this page? This action cannot be undone.')) return;
  try {
    const ids = notifData.map((n) => Number(n.id));
    if (ids.length) await API.post('api/notifications.php?action=delete', { ids });
    else await API.post('api/notifications.php?action=delete', { all: true });
    notifData = [];
    loadNotifs();
    renderNotifListFull();
    loadNotifCount();
    toast('Notifications deleted.');
  } catch (err) {
    toast(err.message, true);
  }
}

/* ----------------------------------------------------------- misc */
/* ==== SIGN OUT ==== */
async function signOut() {
  try {
    await API.post('api/auth.php?action=logout', {});
    window.location.href = 'index.php';
  } catch (err) {
    toast(err.message || 'Could not sign out. Try again.', true);
  }
}

/* ==== NOTIFICATION ARCHIVE TOGGLE ==== */
function toggleArchivedMode() {
  notifArchivedMode = !notifArchivedMode;
  const btn = document.querySelector('#notifications .panel-head .btn');
  if (btn) btn.textContent = notifArchivedMode ? 'Show active' : 'Show archived';
  loadAlerts();
}

async function restoreNotif(id) {
  try {
    await API.post('api/notifications.php?action=restore', { ids: [id] });
    toast('Notification restored.');
    loadAlerts();
    await loadNotifCount();
  } catch (err) {
    toast((err.message || 'Could not restore notification.'), true);
  }
}
