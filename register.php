<?php
/**
 * Kolekta — resident registration.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/page.php';

page_guest_only();

$zones = db()->query('SELECT id, name, code FROM zones WHERE is_active = 1 ORDER BY id')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="view-transition" content="same-origin">
<title>Register your household — Kolekta</title>
<?= csrf_meta() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,480;0,9..144,560;1,9..144,400;1,9..144,480&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%231E3B2C'/%3E%3Cpath d='M10 20c3 3 9 3 12 0s3-8 0-11' stroke='%23F5F0E4' stroke-width='2.4' fill='none' stroke-linecap='round'/%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/css/kolekta.css?v=3">
</head>
<body class="auth">

<main class="auth-left">
  <div class="auth-card" style="max-width:560px">
    <div class="auth-top">
      <a class="back-btn" href="login.php" aria-label="Back to Sign in" title="Back to Sign in">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      </a>
      <span class="auth-sg"><?= esc(BARANGAY_NAME) ?></span>
    </div>

    <h1 class="auth-title">Create an account</h1>
    <p class="auth-sub">Secure Your Household Waste &amp; Route Dispatch Account</p>
    <p class="auth-sub-note">After creating your account you'll be sent to the Sign In page.</p>

    <form id="registerForm" novalidate autocomplete="on">
      <div class="field">
        <label for="regName">Full name</label>
        <input class="input" type="text" id="regName" maxlength="90" placeholder="e.g. Juan Dela Cruz">
        <p class="field-error"></p>
      </div>

      <div class="form-row">
        <div class="field">
          <label for="regEmail">Email</label>
          <input class="input" type="email" id="regEmail" maxlength="160" placeholder="name@email.com">
          <p class="field-error"></p>
        </div>
        <div class="field">
          <label for="regUser">Username</label>
          <input class="input" type="text" id="regUser" maxlength="60" placeholder="juandelacruz">
          <p class="field-error"></p>
        </div>
      </div>

      <div class="form-row">
        <div class="field">
          <label for="regZone">Purok / zone</label>
          <select class="select" id="regZone">
            <option value="">Select your purok…</option>
            <?php foreach ($zones as $z): ?>
              <option value="<?= (int)$z['id'] ?>"><?= esc($z['name']) ?> — <?= esc($z['code']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field-error"></p>
        </div>
        <div class="field">
          <label for="regPhone">Mobile</label>
          <input class="input" type="tel" id="regPhone" maxlength="24" placeholder="0917 123 4567">
          <p class="field-error"></p>
        </div>
      </div>

      <div class="field">
        <label for="regHouse">Household / street address</label>
        <input class="input" type="text" id="regHouse" maxlength="120" placeholder="e.g. 14 Rizal St, Sitio Proper">
        <p class="field-error"></p>
      </div>

      <div class="form-row">
        <div class="field">
          <label for="regPass">Password</label>
          <div class="input-wrap">
            <input class="input" type="password" id="regPass" maxlength="72">
            <!-- ==== SHOW PASSWORD BUTTON ==== -->
            <button type="button" class="pw-toggle" onclick="toggleShow('regPass', this)" aria-label="Show password">
              <svg class="pw-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="pw-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" hidden><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <!-- ==== PASSWORD STRENGTH + PASSWORD RULES CHECKLIST ==== -->
          <div class="pass-strength" id="passStrength"><i></i></div>
          <ul class="pass-rules" id="regPassRules">
            <li data-rule="len">At least 8 characters</li>
            <li data-rule="letter">Contains a letter</li>
            <li data-rule="num">Contains a number</li>
            <li data-rule="space">No spaces</li>
            <li data-rule="sym">No special characters</li>
          </ul>
          <p class="field-error"></p>
        </div>
        <div class="field">
          <!-- ==== CONFIRM PASSWORD FIELD ==== -->
          <label for="regConfirm">Confirm password</label>
          <input class="input" type="password" id="regConfirm" maxlength="72">
          <p class="field-error"></p>
        </div>
      </div>

      <!-- ==== TERMS + PRIVACY CONSENT CHECKBOX ==== -->
      <div class="field">
        <label class="consent-check" for="regConsent">
          <input type="checkbox" id="regConsent">
          <span>I have read and agree to the
            <a href="legal/terms.php" target="_blank" rel="noopener">Terms of Service</a> and the
            <a href="#" data-privacy-modal>Privacy Policy</a>, including how my household's
            information and photos are processed under the Data Privacy Act (R.A. 10173).</span>
        </label>
        <p class="field-error"></p>
      </div>

      <input type="hidden" id="regPolicyScrolled" name="policy_scrolled" value="0">

      <div class="auth-actions">
        <button type="submit" class="btn btn--primary">Create account</button>
      </div>
    </form>

    <!-- ==== PRIVACY POLICY MODAL ==== -->
    <div class="modal-backdrop" id="privacyModal">
      <div class="modal modal--wide">
        <h3>Privacy Policy</h3>
        <p class="m-sub">Please read to the end before continuing with registration.</p>
        <div class="policy-modal-body" id="privacyModalBody">
          <iframe id="privacyModalFrame" src="legal/privacy.php" title="Privacy Policy" loading="lazy"></iframe>
        </div>
        <div class="m-actions">
          <button type="button" class="btn btn--ghost" onclick="closePrivacyModal()">Close</button>
          <button type="button" class="btn btn--primary" id="privacyAgreeBtn" disabled>Agree &amp; continue</button>
        </div>
      </div>
    </div>

    <div class="auth-or">or</div>

    <p class="auth-foot">
      Already registered?
      <a href="login.php">Sign in</a>
    </p>
  </div>
</main>

<?php include __DIR__ . '/inc/auth_promo.php'; ?>

<?= cookie_notice() ?>
<div class="toast"><span class="t-dot"></span><span class="t-text"></span></div>
<script src="assets/js/app.js"></script>
<script src="assets/js/auth.js?v=3"></script>
<script src="assets/js/legal.js"></script>
</body>
</html>