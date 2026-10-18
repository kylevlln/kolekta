/* Kolekta — authentication pages. */

document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('loginForm');
  if (loginForm) initLogin(loginForm);

  const registerForm = document.getElementById('registerForm');
  if (registerForm) initRegister(registerForm);

  const forgotForm = document.getElementById('forgotForm');
  if (forgotForm) initForgot(forgotForm);

  const resetForm = document.getElementById('resetForm');
  if (resetForm) initReset(resetForm);
});

/* Only allow local relative redirect targets (from login.php?next=...). */
function safeNext() {
  try {
    const n = new URLSearchParams(location.search).get('next');
    if (n && /^[\w./&-]{1,120}$/.test(n) && !n.startsWith('/') && n.indexOf('://') === -1 && n.indexOf('..') === -1) return n;
  } catch (e) { /* ignore */ }
  return null;
}

/* ------------------------------------------------------------- email validation */

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const EMAIL_CHARS_RE = /^[A-Za-z0-9.!#$%&'*+\/=?^_`{|}~@-]+$/;

/* ==== EMAIL VALIDATION ==== */
/* mode 'signin': values without "@" are usernames (neutral, no message) and the
   "valid characters" message is not used. mode 'signup': full validation.
   Returns { ok, msg }, or null when neutral. */
function checkEmail(value, mode) {
  if (value.trim() === '') return { ok: false, msg: 'Email is required.' };
  if (/\s/.test(value)) return { ok: false, msg: 'Email cannot contain spaces.' };
  if (mode === 'signin' && value.indexOf('@') === -1) return null;
  if (mode === 'signup' && !EMAIL_CHARS_RE.test(value)) return { ok: false, msg: 'Email can only contain valid characters.' };
  if (!EMAIL_RE.test(value)) return { ok: false, msg: 'Please enter a valid email address.' };
  return { ok: true, msg: 'Valid email address.' };
}

/* Paint feedback under the email/identifier input (single .field-error line).
   showValid=false keeps Sign In simple: green border only, no success message. */
function paintEmail(input, res, showValid) {
  const wrap = input.closest('.field');
  const msg = wrap ? wrap.querySelector('.field-error') : null;
  if (wrap) wrap.classList.remove('has-error');

  if (!res) {
    if (msg) { msg.textContent = ''; msg.classList.remove('ok'); }
    input.classList.remove('ok', 'err');
    return;
  }
  if (res.ok) {
    if (msg) {
      msg.textContent = showValid ? '✓ ' + res.msg : '';
      msg.classList.toggle('ok', showValid);
    }
    input.classList.add('ok');
    input.classList.remove('err');
  } else {
    if (msg) { msg.textContent = '✕ ' + res.msg; msg.classList.remove('ok'); }
    input.classList.add('err');
    input.classList.remove('ok');
  }
}

/* Drop a field's error/valid state so stale messages never linger while typing. */
function clearFieldFeedback(el) {
  const wrap = el.closest('.field');
  if (wrap) wrap.classList.remove('has-error');
  const msg = wrap ? wrap.querySelector('.field-error') : null;
  if (msg) { msg.textContent = ''; msg.classList.remove('ok'); }
  el.classList.remove('ok', 'err');
}

/* ---------------------------------------------------------------- login */
/* Sign In keeps validation simple: errors appear on submit only,
   no password checklist, no live requirements. */
/* ==== LOGIN SUBMIT ==== */
function initLogin(form) {
  const btn = form.querySelector('button[type="submit"]');
  const identifier = document.getElementById('loginId');
  const password = document.getElementById('loginPass');

  identifier.addEventListener('input', () => clearFieldFeedback(identifier));
  password.addEventListener('input', () => clearFieldFeedback(password));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const idValue = identifier.value.trim();
    const passValue = password.value;

    const emailCheck = checkEmail(identifier.value, 'signin');
    if (emailCheck && !emailCheck.ok) {
      paintEmail(identifier, emailCheck, false);
      identifier.focus();
      return;
    }
    paintEmail(identifier, emailCheck, false); /* null (username) = neutral */

    if (!passValue) {
      addFieldError(password, 'Password is required.');
      password.focus();
      return;
    }

    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="border-top-color:var(--evergreen)"></span> Signing in…';

    try {
      const data = await API.post('api/auth.php?action=login', { identifier: idValue, password: passValue });
      toast(`Welcome back, ${data.name.split(' ')[0]}.`);
      const target = safeNext() || data.redirect;
      document.body.classList.add('auth-leaving');
      setTimeout(() => { window.location.href = target; }, 220);
    } catch (err) {
      if (err.status === 419) {
        window.location.reload();
        return;
      }
      if (err.status === 401) {
        addFieldError(password, 'Incorrect password.');
        password.focus();
      } else {
        toast(err.message, true);
        markError('loginId', err.message);
      }
      btn.disabled = false;
      btn.innerHTML = original;
    }
  });
}

/* -------------------------------------------------------------- register */
/* Sign Up uses live validation, but each field only starts showing feedback
   after the user has typed into it or attempted to submit. */
/* ==== REGISTER SUBMIT ==== */
function initRegister(form) {
  const btn = form.querySelector('button[type="submit"]');
  const pass = document.getElementById('regPass');
  const confirm = document.getElementById('regConfirm');
  const passField = pass ? pass.closest('.field') : null;
  const passRules = document.getElementById('regPassRules');
  const email = document.getElementById('regEmail');

  let emailTouched = false;
  let passTouched = false;
  let submitAttempted = false;

  /* Rule for a single register field: '' means valid, otherwise the message. */
  function registerFieldState(id, value) {
    const v = String(value).trim();
    switch (id) {
      case 'regName':
        if (v.length < 3) return 'Enter your full name.';
        if (v.length > 90) return 'Keep your name to 90 characters or fewer.';
        return '';
      case 'regUser':
        if (v === '') return 'Username is required.';
        if (v.length > 60) return 'Keep your username to 60 characters or fewer.';
        return '';
      case 'regPhone':
        if (v === '') return 'Mobile number is required.';
        if (!/^[0-9+\-(). ]{7,24}$/.test(v)) return 'Enter a valid mobile number.';
        return '';
      case 'regHouse':
        if (v === '') return 'Household / street address is required.';
        if (v.length > 120) return 'Keep the household name to 120 characters or fewer.';
        return '';
      case 'regZone':
        return v === '' ? 'Choose your purok.' : '';
      default:
        return '';
    }
  }

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

  /* Re-check one field from its current value. force=true reveals an error even
     before the first submit; otherwise an error only updates/clears once shown. */
  function repaintField(el, force) {
    const wrap = el.closest('.field');
    const hadError = !!(wrap && wrap.classList.contains('has-error'));
    const msg = registerFieldState(el.id, el.value);
    if (msg) {
      if (force || hadError) showError(el, msg);
    } else {
      clearError(el);
    }
  }

  function updateEmail() {
    if (!email || !emailTouched) return;
    paintEmail(email, checkEmail(email.value, 'signup'), true);
  }

  if (email) {
    email.addEventListener('input', () => { emailTouched = true; updateEmail(); });
    email.addEventListener('blur', () => { if (email.value) { emailTouched = true; updateEmail(); } });
  }

  function updatePassword() {
    if (!pass) return false;
    if (passTouched && passRules) passRules.classList.add('show');
    const allOk = renderPasswordRules(pass, passRules, passField ? passField.querySelector('.field-error') : null);
    if (btn) btn.disabled = !allOk;

    const strength = document.getElementById('passStrength');
    if (strength) {
      const s = PASS_RULES.filter((r) => r.test(pass.value)).length;
      const i = strength.querySelector('i');
      i.style.width = (s / PASS_RULES.length * 100) + '%';
      if (s === PASS_RULES.length) i.className = 's3';
      else if (s >= 2) i.className = 's2';
      else i.className = '';
    }
    if (confirm && confirm.value) matchConfirm(pass, confirm);
    return allOk;
  }

  if (pass) pass.addEventListener('input', () => { passTouched = true; updatePassword(); });
  if (pass) pass.addEventListener('blur', () => { if (pass.value) { passTouched = true; updatePassword(); } });
  confirm && confirm.addEventListener('input', () => { clearError(confirm); matchConfirm(pass, confirm); });

  /* Live validation for the required fields: errors clear/refresh the instant
     the value changes, so a fixed field never stays red. */
  ['regName', 'regUser', 'regPhone', 'regHouse', 'regZone'].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', () => repaintField(el, submitAttempted));
    el.addEventListener('change', () => repaintField(el, true));
    el.addEventListener('blur', () => repaintField(el, true));
  });

  const consentBox = document.getElementById('regConsent');
  const privacyModal = document.getElementById('privacyModal');
  const privacyFrame = document.getElementById('privacyModalFrame');
  const privacyBody = document.getElementById('privacyModalBody');
  const privacyAgreeBtn = document.getElementById('privacyAgreeBtn');
  const policyScrolledInput = document.getElementById('regPolicyScrolled');
  let policyScrolledState = false;

  function setPolicyScrolled(on) {
    policyScrolledState = on;
    if (policyScrolledInput) policyScrolledInput.value = on ? '1' : '0';
    const err = document.getElementById('regPolicyError');
    if (err && on) err.textContent = '';
    if (privacyAgreeBtn) privacyAgreeBtn.disabled = !on;
  }

  function syncPrivacyScroll() {
    if (!privacyFrame) return;
    try {
      const doc = privacyFrame.contentWindow && privacyFrame.contentWindow.document;
      if (!doc) return;
      const check = () => {
        const root = doc.scrollingElement || doc.documentElement || doc.body;
        if (!root) return;
        if (root.scrollTop + root.clientHeight >= root.scrollHeight - 6) setPolicyScrolled(true);
      };
      const alreadyBound = doc.__kolektaPrivacyBound === true;
      if (!alreadyBound) {
        doc.__kolektaPrivacyBound = true;
        doc.addEventListener('scroll', check);
      }
      setTimeout(check, 160);
      setTimeout(check, 500);
    } catch (e) { /* Same origin only; ignore if unavailable */ }
  }

  if (privacyFrame) {
    privacyFrame.addEventListener('load', syncPrivacyScroll);
    window.addEventListener('load', syncPrivacyScroll);
    setTimeout(syncPrivacyScroll, 300);
  }

  function openPrivacyModal() {
    if (privacyModal) {
      privacyModal.classList.add('open');
      syncPrivacyScroll();
    }
  }

  function closePrivacyModal() {
    if (privacyModal) privacyModal.classList.remove('open');
  }

  document.querySelectorAll('[data-privacy-modal]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      openPrivacyModal();
    });
  });

  window.openPrivacyModal = openPrivacyModal;
  window.closePrivacyModal = closePrivacyModal;

  if (privacyAgreeBtn) {
    privacyAgreeBtn.addEventListener('click', () => {
      if (!policyScrolledState) {
        toast('Please read to the end of the Privacy Policy first.', true);
        return;
      }
      setPolicyScrolled(true);
      if (consentBox) {
        suppressConsentChange = true;
        consentBox.checked = true;
        suppressConsentChange = false;
        clearError(consentBox);
      }
      closePrivacyModal();
    });
  }

  let suppressConsentChange = false;
  if (consentBox) {
    consentBox.addEventListener('change', () => {
      if (suppressConsentChange) return;
      if (consentBox.checked) {
        consentBox.checked = false;
        openPrivacyModal();
      } else {
        clearFieldFeedback(consentBox);
      }
    });
  }

  // click backdrop close
  if (privacyModal) {
    privacyModal.addEventListener('click', (event) => {
      if (event.target === privacyModal) closePrivacyModal();
    });
  }

  /* Wipe every field's styling before a fresh validation pass. Also removes the
     stale .err/.ok classes that unhideErrors() leaves behind on inputs. */
  function clearAllFieldErrors() {
    form.querySelectorAll('.field').forEach((f) => {
      f.classList.remove('has-error');
      const m = f.querySelector('.field-error');
      if (m) { m.textContent = ''; m.classList.remove('ok'); }
      const inp = f.querySelector('.input, .select');
      if (inp) inp.classList.remove('err', 'ok');
    });
  }

  if (btn && pass) btn.disabled = true;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    submitAttempted = true;

    const nameEl = document.getElementById('regName');
    const usernameEl = document.getElementById('regUser');
    const phoneEl = document.getElementById('regPhone');
    const houseEl = document.getElementById('regHouse');
    const zoneEl = document.getElementById('regZone');
    const consent = document.getElementById('regConsent');

    const name = nameEl.value.trim();
    const emailVal = email.value;
    const username = usernameEl.value.trim();
    const phone = phoneEl.value.trim();
    const household = houseEl.value.trim();
    const zone = zoneEl.value;
    const password = pass.value;
    const conf = confirm.value;

    /* Reset once, then paint EVERY problem so they appear together. */
    clearAllFieldErrors();
    const invalid = [];

    const checkField = (id, el) => {
      const msg = registerFieldState(id, el.value);
      if (msg) { showError(el, msg); invalid.push(el); }
    };
    checkField('regName', nameEl);
    checkField('regUser', usernameEl);
    checkField('regPhone', phoneEl);
    checkField('regHouse', houseEl);
    checkField('regZone', zoneEl);

    /* Email keeps its dedicated painter (and its "valid" tick). */
    emailTouched = true;
    updateEmail();
    if (!checkEmail(emailVal, 'signup').ok) invalid.push(email);

    /* Password checklist + confirmation. */
    passTouched = true;
    if (!updatePassword()) {
      invalid.push(pass);
    } else if (password !== conf) {
      showError(confirm, 'Passwords do not match.');
      invalid.push(confirm);
    }

    if (!consent || !consent.checked) {
      showError(consent, 'Please accept the Privacy Policy and Terms of Service to continue.');
      invalid.push(consent);
    }

    if (!policyScrolledState) {
      openPrivacyModal();
      invalid.push(consent || form);
    }

    /* Any error blocks the request — no account is created. */
    if (invalid.length) {
      invalid[0].focus();
      return;
    }

    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="border-top-color:var(--evergreen)"></span> Creating account…';

    try {
       const data = await API.post('api/auth.php?action=register', {
         name, email: emailVal, username, phone, household, zone_id: zone, password,
         accept: consent.checked ? 1 : 0,
         policy_scrolled: policyScrolledState ? 1 : 0,
       });
      toast('Account created successfully.');
      document.body.classList.add('auth-leaving');
      setTimeout(() => { window.location.href = data.redirect || 'login.php?registered=1'; }, 220);
    } catch (err) {
      if (err.status === 419) { window.location.reload(); return; }
      const msg = err.message || 'Registration failed.';
      if (/email/i.test(msg)) markError('regEmail', msg);
      else if (/username/i.test(msg)) markError('regUser', msg);
      else if (/mobile|phone/i.test(msg)) markError('regPhone', msg);
      else if (/household|address/i.test(msg)) markError('regHouse', msg);
      else if (/zone/i.test(msg)) markError('regZone', msg);
      else if (/password/i.test(msg)) markError('regPass', msg);
      else toast(msg, true);
      btn.disabled = false;
      btn.innerHTML = original;
    }
  });
}

/* ---------------------------------------------------------- forgot password */
/* request a reset link; always lands on the "check your inbox" page */
/* ==== FORGOT PASSWORD SUBMIT ==== */
function initForgot(form) {
  const btn = form.querySelector('button[type="submit"]');
  const email = document.getElementById('fpEmail');

  email.addEventListener('input', () => clearFieldFeedback(email));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const res = checkEmail(email.value, 'signup');
    if (!res.ok) {
      paintEmail(email, res, true);
      email.focus();
      return;
    }

    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="border-top-color:var(--evergreen)"></span> Sending…';

    try {
      const data = await API.post('api/auth.php?action=forgot', { email: email.value.trim() });
      toast('Reset link sent.');
      setTimeout(() => { window.location.href = data.redirect || 'forgot-sent.php'; }, 500);
    } catch (err) {
      if (err.status === 419) { window.location.reload(); return; }
      markError('fpEmail', err.message || 'Could not send the reset link.');
      btn.disabled = false;
      btn.innerHTML = original;
    }
  });
}

/* ----------------------------------------------------------- password reset */
/* exchanged for a new password once the emailed token is verified */
/* ==== RESET PASSWORD SUBMIT ==== */
function initReset(form) {
  const btn = form.querySelector('button[type="submit"]');
  const pass = document.getElementById('rpPass');
  const confirm = document.getElementById('rpConfirm');
  const passField = pass ? pass.closest('.field') : null;
  const passRules = document.getElementById('rpPassRules');
  const token = document.getElementById('rpToken');
  const email = document.getElementById('rpEmail');

  let passTouched = false;

  function updatePassword() {
    if (!pass) return false;
    if (passTouched && passRules) passRules.classList.add('show');
    const allOk = renderPasswordRules(pass, passRules, passField ? passField.querySelector('.field-error') : null);
    if (btn) btn.disabled = !allOk;

    const strength = document.getElementById('rpPassStrength');
    if (strength) {
      const s = PASS_RULES.filter((r) => r.test(pass.value)).length;
      const i = strength.querySelector('i');
      i.style.width = (s / PASS_RULES.length * 100) + '%';
      if (s === PASS_RULES.length) i.className = 's3';
      else if (s >= 2) i.className = 's2';
      else i.className = '';
    }
    if (confirm && confirm.value) matchConfirm(pass, confirm);
    return allOk;
  }

  if (pass) pass.addEventListener('input', () => { passTouched = true; updatePassword(); });
  if (pass) pass.addEventListener('blur', () => { if (pass.value) { passTouched = true; updatePassword(); } });
  confirm && confirm.addEventListener('input', () => matchConfirm(pass, confirm));

  if (btn && pass) btn.disabled = true;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    passTouched = true;
    if (!pass || !updatePassword()) {
      if (pass) pass.focus();
      return;
    }
    if (confirm && pass.value !== confirm.value) {
      addFieldError(confirm, 'Passwords do not match.');
      confirm.focus();
      return;
    }

    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="border-top-color:var(--evergreen)"></span> Updating…';

    try {
      const data = await API.post('api/auth.php?action=reset', {
        token: token.value, email: email.value, password: pass.value,
      });
      toast('Password updated.');
      setTimeout(() => { window.location.href = data.redirect || 'login.php?reset=1'; }, 600);
    } catch (err) {
      if (err.status === 419) { window.location.reload(); return; }
      markError('rpPass', err.message || 'Could not reset the password.');
      if (passRules) passRules.classList.add('show');
      btn.disabled = false;
      btn.innerHTML = original;
    }
  });
}

/* ==== SHOW PASSWORD BUTTON ==== */
function toggleShow(inputId, btn) {
  const input = document.getElementById(inputId);
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  const on = btn.querySelector('.pw-eye');
  const off = btn.querySelector('.pw-eye-off');
  if (on) on.hidden = show;
  if (off) off.hidden = !show;
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}