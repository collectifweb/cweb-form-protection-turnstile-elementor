// QA navigateur — usage : node qa.cjs <base-url> <dossier-env-wp-env> <dossier-sortie>
// Variables : PLAYWRIGHT_CORE (chemin du module playwright-core), WP_ENV_BIN (binaire wp-env).
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2];
const ENVDIR = process.argv[3];
const OUT = process.argv[4];
fs.mkdirSync(OUT, { recursive: true });
const ERR = 'Please confirm you are not a robot.';
const WPENV = process.env.WP_ENV_BIN || 'wp-env';
const results = [];
const consoleErrors = [];

function wp(cmd) {
  let out = '';
  try { out = execSync(`${WPENV} run cli -- ${cmd} 2>/dev/null`, { cwd: ENVDIR, encoding: 'utf8' }); }
  catch (e) { out = e.stdout || ''; } // WP-CLI sort en erreur quand il ne trouve rien : on rend une chaîne vide
  return out.split('\n').filter(l => !/^[✔ℹ✖]/.test(l) && !/McpAdapter|Colors\.php|Command failed/.test(l)).join('\n').trim();
}
function rec(name, ok, detail = '') {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}
let failShot = 0;
const openPages = [];
async function step(name, fn) {
  try { const r = await fn(); rec(name, r === undefined ? true : !!r.ok, r && r.detail ? r.detail : ''); }
  catch (e) {
    let notes = '';
    for (const pg of openPages) {
      if (pg.isClosed()) continue;
      failShot++; await pg.screenshot({ path: path.join(OUT, `echec-${failShot}.png`), fullPage: true }).catch(() => {});
      const n = await pg.locator('.woocommerce-error, .woocommerce-message, .woocommerce-info, .wc-block-components-notice-banner, #login_error, .elementor-message').allInnerTexts().catch(() => []);
      if (n.length) notes += ` [${pg.url().replace(BASE, '')}: ${n.join(' | ').replace(/\s+/g, ' ').slice(0, 160)}]`;
    }
    rec(name, false, 'exception: ' + String(e.message).split('\n')[0] + notes);
  }
}
async function token(page, scope = 'body', timeout = 25000) {
  const sel = `${scope} input[name="cf-turnstile-response"]`;
  await page.waitForFunction(s => [...document.querySelectorAll(s)].some(i => i.value && i.value.length > 10), sel, { timeout });
}
function watch(page, label) {
  openPages.push(page);
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(`[${label}] ${m.text()}`); });
  page.on('pageerror', e => consoleErrors.push(`[${label}] pageerror ${e.message}`));
}

(async () => {
  const browser = await chromium.launch();
  const anon = await browser.newContext(); const admin = await browser.newContext();
  const pa = await admin.newPage(); watch(pa, 'admin');
  const p = await anon.newPage(); watch(p, 'anon');

  // ---------- Connexion WordPress ----------
  await step('Connexion WP : refusée sans jeton (identifiants valides)', async () => {
    const r = await anon.request.post(`${BASE}/wp-login.php`, { form: { log: 'admin', pwd: 'password', 'wp-submit': 'Log In', testcookie: '1' }, headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' }, maxRedirects: 0 });
    const body = await r.text();
    return { ok: r.status() === 200 && body.includes(ERR), detail: `HTTP ${r.status()}` };
  });
  await anon.clearCookies();
  await step('Connexion WP : widget dans le formulaire + connexion réussie avec jeton', async () => {
    await pa.goto(`${BASE}/wp-login.php`);
    const inForm = await pa.locator('#loginform .cf-turnstile').count();
    await token(pa, '#loginform');
    await pa.fill('#user_login', 'admin'); await pa.fill('#user_pass', 'password');
    await Promise.all([pa.waitForURL(/wp-admin/, { timeout: 30000 }), pa.click('#wp-submit')]);
    return { ok: inForm === 1, detail: `widgets dans #loginform=${inForm}, url=${pa.url()}` };
  });

  // ---------- Page de réglages ----------
  await step('Réglages : la page s\'affiche, clé secrète jamais renvoyée', async () => {
    const r = await pa.goto(`${BASE}/wp-admin/options-general.php?page=cwebts`);
    const html = await pa.content();
    await pa.screenshot({ path: path.join(OUT, 'reglages.png'), fullPage: true });
    return { ok: r.status() === 200 && html.includes('cwebts_settings[site_key]') && !html.includes('1x0000000000000000000000000000000AA'), detail: `HTTP ${r.status()}` };
  });

  // ---------- Inscription WordPress ----------
  await step('Inscription WP : refusée sans jeton', async () => {
    const r = await anon.request.post(`${BASE}/wp-login.php?action=register`, { form: { user_login: 'nojeton', user_email: 'nojeton@example.test', 'wp-submit': 'Register' }, maxRedirects: 0 });
    const body = await r.text();
    const exists = wp('wp user get nojeton --field=ID') !== '';
    return { ok: body.includes(ERR) && !exists, detail: `HTTP ${r.status()}, compte créé=${exists}` };
  });
  await step('Inscription WP : réussie avec jeton', async () => {
    await p.goto(`${BASE}/wp-login.php?action=register`);
    await token(p, '#registerform');
    await p.fill('#user_login', 'qareg' + Date.now() % 100000); await p.fill('#user_email', `qareg${Date.now() % 100000}@example.test`);
    await Promise.all([p.waitForURL(/checkemail=registered/, { timeout: 30000 }), p.click('#wp-submit')]);
    return { ok: true, detail: p.url() };
  });

  // ---------- Mot de passe perdu WordPress ----------
  await step('Mot de passe perdu WP : refusé sans jeton', async () => {
    const r = await anon.request.post(`${BASE}/wp-login.php?action=lostpassword`, { form: { user_login: 'qacustomer', 'wp-submit': 'Get New Password' }, maxRedirects: 0 });
    const body = await r.text();
    return { ok: body.includes(ERR), detail: `HTTP ${r.status()}` };
  });
  await step('Mot de passe perdu WP : accepté avec jeton', async () => {
    await p.goto(`${BASE}/wp-login.php?action=lostpassword`);
    await token(p, '#lostpasswordform');
    await p.fill('#user_login', 'qacustomer');
    await Promise.all([p.waitForURL(/checkemail=confirm/, { timeout: 30000 }), p.click('#wp-submit')]);
    return { ok: true, detail: p.url() };
  });

  // ---------- Commentaires ----------
  await step('Commentaire anonyme : refusé sans jeton (403)', async () => {
    const r = await anon.request.post(`${BASE}/wp-comments-post.php`, { form: { comment: 'Sans jeton', author: 'Bot', email: 'bot@example.test', comment_post_ID: '1', comment_parent: '0' }, maxRedirects: 0 });
    const body = await r.text();
    return { ok: r.status() === 403 && body.includes(ERR), detail: `HTTP ${r.status()}` };
  });
  await step('Commentaire anonyme : widget dans le formulaire + publié avec jeton', async () => {
    await p.goto(`${BASE}/hello-world/`);
    const inForm = await p.locator('#commentform .cf-turnstile').count();
    await token(p, '#commentform');
    const txt = 'Commentaire QA anonyme ' + Date.now();
    await p.fill('#comment', txt); await p.fill('#author', 'QA'); await p.fill('#email', 'qa@example.test');
    await Promise.all([p.waitForURL(/#comment-\d+/, { timeout: 30000 }), p.click('#commentform [type=submit]')]);
    return { ok: inForm === 1, detail: `widgets dans #commentform=${inForm}` };
  });
  await step('Commentaire connecté : widget présent + publié avec jeton', async () => {
    await pa.goto(`${BASE}/hello-world/`);
    const inForm = await pa.locator('#commentform .cf-turnstile').count();
    await token(pa, '#commentform');
    await pa.fill('#comment', 'Commentaire QA admin ' + Date.now());
    await Promise.all([pa.waitForURL(/#comment-\d+/, { timeout: 30000 }), pa.click('#commentform [type=submit]')]);
    return { ok: inForm === 1, detail: `widgets=${inForm}` };
  });
  await step('Réponse depuis l\'administration (replyto-comment) : non bloquée', async () => {
    await pa.goto(`${BASE}/wp-admin/edit-comments.php`);
    const nonce = await pa.locator('#_ajax_nonce-replyto-comment').getAttribute('value');
    const cid = wp('wp comment list --post_id=1 --number=1 --orderby=comment_ID --order=DESC --field=comment_ID');
    const r = await admin.request.post(`${BASE}/wp-admin/admin-ajax.php`, { form: { action: 'replyto-comment', comment_ID: cid, comment_post_ID: '1', content: 'Réponse admin QA', '_ajax_nonce-replyto-comment': nonce } });
    const body = await r.text();
    return { ok: r.status() === 200 && body.includes('<wp_ajax>') && !body.includes(ERR), detail: `HTTP ${r.status()}, ${body.slice(0, 80).replace(/\s+/g, ' ')}` };
  });
  await step('Note de l\'éditeur de blocs (REST, type note) : non bloquée', async () => {
    const nonce = (await (await admin.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
    const r = await admin.request.post(`${BASE}/?rest_route=/wp/v2/comments`, { headers: { 'X-WP-Nonce': nonce }, data: { post: 1, content: 'Note QA', type: 'note' } });
    const body = await r.text();
    return { ok: r.status() === 201 && !body.includes(ERR), detail: `HTTP ${r.status()}, ${body.slice(0, 100)}` };
  });

  // ---------- Elementor Pro : champ par formulaire ----------
  const pid = wp('wp post list --post_type=page --name=qa-elementor --field=ID');
  async function elementorSend(ctx, formId, extra = {}) {
    const form = { action: 'elementor_pro_forms_send_form', post_id: pid, form_id: formId, queried_id: pid, referer_title: 'QA', 'form_fields[name]': 'QA', ...extra };
    const r = await ctx.request.post(`${BASE}/wp-admin/admin-ajax.php`, { form });
    return r.json();
  }
  await step('Elementor : formulaire A (avec champ) refusé sans jeton', async () => {
    const j = await elementorSend(anon, 'qaformA');
    return { ok: j.success === false, detail: JSON.stringify(j).slice(0, 160) };
  });
  await step('Elementor : formulaire B (sans champ) accepté sans jeton', async () => {
    const j = await elementorSend(anon, 'qaformB');
    return { ok: j.success === true, detail: JSON.stringify(j).slice(0, 120) };
  });
  await step('Elementor : widget seulement dans A, envoi navigateur de A réussi', async () => {
    await p.goto(`${BASE}/qa-elementor/`);
    const a = await p.locator('.elementor-element-qaformA form .cf-turnstile').count();
    const b = await p.locator('.elementor-element-qaformB form .cf-turnstile').count();
    await token(p, '.elementor-element-qaformA');
    await p.fill('.elementor-element-qaformA input[name="form_fields[name]"]', 'QA navigateur');
    await p.click('.elementor-element-qaformA button[type=submit]');
    await p.waitForSelector('.elementor-element-qaformA .elementor-message-success', { timeout: 30000 });
    await p.screenshot({ path: path.join(OUT, 'elementor-par-formulaire.png'), fullPage: true });
    return { ok: a === 1 && b === 0, detail: `widgets A=${a}, B=${b}` };
  });
  await step('Elementor : visite suivante servie par le cache d\'Elementor, widget rendu et envoi de A réussi', async () => {
    const cached = wp(`wp post meta get ${pid} _elementor_element_cache`) !== '';
    const c = await browser.newContext(); const q = await c.newPage(); watch(q, 'anon-cache');
    await q.goto(`${BASE}/qa-elementor/`);
    const scripts = await q.locator('script[src*="turnstile/v0/api.js"]').count();
    await token(q, '.elementor-element-qaformA');
    await q.fill('.elementor-element-qaformA input[name="form_fields[name]"]', 'QA cache');
    await q.click('.elementor-element-qaformA button[type=submit]');
    await q.waitForSelector('.elementor-element-qaformA .elementor-message-success', { timeout: 30000 });
    await c.close();
    return { ok: cached && scripts === 1, detail: `cache présent=${cached}, script Cloudflare=${scripts}` };
  });

  await step('Elementor : modèle réutilisé, mis en cache sur une page qui chargeait déjà les scripts, puis affiché seul ailleurs : widget rendu et envoi réussi', async () => {
    const c = await browser.newContext(); const q = await c.newPage(); watch(q, 'anon-modele');
    await q.goto(`${BASE}/qa-template-a/`); // met en cache le modèle alors que les scripts sont déjà en file
    await q.goto(`${BASE}/qa-template-c/`);
    const widgets = await q.locator('.elementor-element-qaformT form .cf-turnstile').count();
    const scripts = await q.locator('script[src*="turnstile/v0/api.js"]').count();
    await token(q, '.elementor-element-qaformT');
    await q.fill('.elementor-element-qaformT input[name="form_fields[name]"]', 'QA modèle');
    await q.click('.elementor-element-qaformT button[type=submit]');
    await q.waitForSelector('.elementor-element-qaformT .elementor-message-success', { timeout: 30000 });
    await c.close();
    return { ok: widgets === 1 && scripts === 1, detail: `widgets=${widgets}, script Cloudflare=${scripts}` };
  });

  // ---------- Elementor Pro : mode « tous les formulaires » (via la page de réglages) ----------
  async function setAllForms(on) {
    await pa.goto(`${BASE}/wp-admin/options-general.php?page=cwebts`);
    const cb = pa.locator('input[name="cwebts_settings[protect_elementor_all_forms]"][type=checkbox]');
    if ((await cb.isChecked()) !== on) await cb.click();
    await Promise.all([pa.waitForURL(/settings-updated=true/, { timeout: 30000 }), pa.click('#submit')]);
  }
  await step('Tous les formulaires : activé depuis les réglages, cache Elementor purgé, widget injecté dans B', async () => {
    await p.goto(`${BASE}/qa-elementor/`); // remplit le cache d'éléments d'Elementor
    const cacheBefore = wp(`wp post meta get ${pid} _elementor_element_cache`) !== '';
    await setAllForms(true);
    const cacheAfter = wp(`wp post meta get ${pid} _elementor_element_cache`) !== '';
    await p.goto(`${BASE}/qa-elementor/`);
    const b = await p.locator('.elementor-element-qaformB form .cf-turnstile').count();
    const a = await p.locator('.elementor-element-qaformA form .cf-turnstile').count();
    return { ok: b === 1 && a === 1 && !cacheAfter, detail: `cache avant=${cacheBefore}, après=${cacheAfter}, widgets A=${a} B=${b}` };
  });
  await step('Tous les formulaires : B refusé sans jeton, accepté avec (navigateur)', async () => {
    const j = await elementorSend(anon, 'qaformB');
    await token(p, '.elementor-element-qaformB');
    await p.fill('.elementor-element-qaformB input[name="form_fields[name]"]', 'QA tous');
    await p.click('.elementor-element-qaformB button[type=submit]');
    await p.waitForSelector('.elementor-element-qaformB .elementor-message-success', { timeout: 30000 });
    return { ok: j.success === false, detail: 'sans jeton: ' + JSON.stringify(j).slice(0, 100) };
  });
  await step('Tous les formulaires : désactivé, B redevient libre', async () => {
    await setAllForms(false);
    await p.goto(`${BASE}/qa-elementor/`);
    const b = await p.locator('.elementor-element-qaformB form .cf-turnstile').count();
    const j = await elementorSend(anon, 'qaformB');
    return { ok: b === 0 && j.success === true, detail: `widgets B=${b}` };
  });

  // ---------- WooCommerce ----------
  const wc = await browser.newContext(); const pw = await wc.newPage(); watch(pw, 'woo');
  await step('Woo connexion : refusée sans jeton', async () => {
    await pw.goto(`${BASE}/my-account/`);
    const nonce = await pw.locator('#woocommerce-login-nonce').getAttribute('value');
    const r = await wc.request.post(`${BASE}/my-account/`, { form: { username: 'qacustomer', password: 'QaCustomer!2026', login: 'Log in', 'woocommerce-login-nonce': nonce, _wp_http_referer: '/my-account/' } });
    const body = await r.text();
    return { ok: body.includes(ERR) && !body.includes('woocommerce-MyAccount-navigation'), detail: `HTTP ${r.status()}` };
  });
  await step('Woo connexion : widget dans le formulaire + connexion réussie avec jeton', async () => {
    await pw.goto(`${BASE}/my-account/`);
    const n = await pw.locator('form.woocommerce-form-login .cf-turnstile').count();
    await token(pw, 'form.woocommerce-form-login');
    await pw.fill('#username', 'qacustomer'); await pw.fill('#password', 'QaCustomer!2026');
    await pw.click('form.woocommerce-form-login button[name=login]');
    await pw.waitForSelector('.woocommerce-MyAccount-navigation', { timeout: 30000 });
    return { ok: n === 1, detail: `widgets=${n}` };
  });
  await step('Woo détails du compte : enregistrés avec jeton', async () => {
    await pw.goto(`${BASE}/my-account/edit-account/`);
    const n = await pw.locator('form.woocommerce-EditAccountForm .cf-turnstile').count();
    await token(pw, 'form.woocommerce-EditAccountForm');
    await pw.fill('#account_first_name', 'Qa'); await pw.fill('#account_last_name', 'Client');
    await pw.fill('#account_display_name', 'qacustomer');
    await pw.click('button[name=save_account_details]');
    await pw.waitForSelector('.woocommerce-message, .wc-block-components-notice-banner.is-success', { timeout: 30000 });
    const msg = await pw.locator('.woocommerce-message, .wc-block-components-notice-banner.is-success').first().innerText();
    return { ok: n === 1 && /changed|modifi/i.test(msg), detail: `widgets=${n}, « ${msg.trim()} »` };
  });
  await step('Woo commande (page shortcode) : widget avant le bouton + commande passée avec jeton', async () => {
    const prod = wp('wp post list --post_type=product --name=qa-product --field=ID');
    await pw.goto(`${BASE}/?add-to-cart=${prod}`);
    await pw.goto(`${BASE}/checkout/`);
    await pw.waitForSelector('#place_order', { timeout: 30000 });
    await token(pw, 'form.checkout');
    const before = await pw.evaluate(() => { const w = document.querySelector('form.checkout .cf-turnstile'); const b = document.querySelector('#place_order'); return !!(w && b && (w.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)); });
    const fill = async (s, v) => { if (await pw.locator(s).count()) await pw.fill(s, v); };
    await fill('#billing_first_name', 'Qa'); await fill('#billing_last_name', 'Client'); await fill('#billing_address_1', '1 rue QA');
    await fill('#billing_city', 'Montreal'); await fill('#billing_postcode', '90210'); await fill('#billing_phone', '5145550000'); await fill('#billing_email', 'qacustomer@example.test');
    if (await pw.locator('select#billing_state').count()) await pw.selectOption('select#billing_state', 'CA');
    await pw.waitForTimeout(1500); await pw.waitForSelector('.blockUI', { state: 'detached', timeout: 30000 }).catch(() => {});
    await token(pw, 'form.checkout');
    await pw.click('#place_order');
    await pw.waitForURL(/order-received/, { timeout: 45000 });
    await pw.screenshot({ path: path.join(OUT, 'commande-recue.png') });
    return { ok: before, detail: `widget avant le bouton=${before}, ${pw.url().replace(BASE, '')}` };
  });
  await step('Woo inscription + mot de passe perdu : widgets présents et envois réussis', async () => {
    const c2 = await browser.newContext(); const q = await c2.newPage(); watch(q, 'woo-reg');
    await q.goto(`${BASE}/my-account/`);
    const n = await q.locator('form.woocommerce-form-register .cf-turnstile').count();
    await token(q, 'form.woocommerce-form-register');
    await q.fill('#reg_email', `woo${Date.now() % 100000}@example.test`); await q.fill('#reg_password', 'WooReg!2026-long');
    await q.press('#reg_password', 'Tab'); await q.waitForTimeout(1000); // l'indicateur de force change de hauteur au blur : laisser la mise en page se poser
    await q.click('form.woocommerce-form-register button[name=register]');
    await q.waitForSelector('.woocommerce-MyAccount-navigation', { timeout: 30000 });
    const c3 = await browser.newContext(); const l = await c3.newPage(); watch(l, 'woo-lost');
    await l.goto(`${BASE}/my-account/lost-password/`);
    const nl = await l.locator('form.lost_reset_password .cf-turnstile').count();
    await token(l, 'form.lost_reset_password');
    await l.fill('#user_login', 'qacustomer');
    await l.click('form.lost_reset_password button[type=submit]');
    await l.waitForSelector('.woocommerce-message, .woocommerce-info, .wc-block-components-notice-banner.is-success, .wc-block-components-notice-banner.is-info', { timeout: 30000 });
    await c2.close(); await c3.close();
    return { ok: n === 1 && nl === 1, detail: `widgets inscription=${n}, mot de passe perdu=${nl}` };
  });

  // ---------- Vérification serveur réelle : clé secrète « toujours refusé » ----------
  wp(`wp option patch update cwebts_settings secret_key 2x0000000000000000000000000000000AA`);
  await step('Clé « toujours refusé » : connexion WP refusée même avec un jeton', async () => {
    const c = await browser.newContext(); const q = await c.newPage();
    await q.goto(`${BASE}/wp-login.php`); await token(q, '#loginform');
    await q.fill('#user_login', 'admin'); await q.fill('#user_pass', 'password');
    await Promise.all([q.waitForLoadState('load'), q.click('#wp-submit')]);
    await q.waitForTimeout(500);
    const body = await q.content(); await c.close();
    return { ok: body.includes(ERR) && !/wp-admin/.test(q.url()), detail: q.url().replace(BASE, '') };
  });
  await step('Clé « toujours refusé » : commande Woo refusée même avec un jeton', async () => {
    const prod = wp('wp post list --post_type=product --name=qa-product --field=ID');
    await pw.goto(`${BASE}/?add-to-cart=${prod}`); await pw.goto(`${BASE}/checkout/`);
    await pw.waitForSelector('#place_order'); await pw.waitForTimeout(1500);
    await pw.waitForSelector('.blockUI', { state: 'detached', timeout: 30000 }).catch(() => {});
    await token(pw, 'form.checkout');
    await pw.click('#place_order');
    await pw.waitForSelector('.woocommerce-error, .woocommerce-NoticeGroup-checkout, .wc-block-components-notice-banner.is-error', { timeout: 30000 });
    const err = await pw.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout, .wc-block-components-notice-banner.is-error').first().innerText();
    return { ok: err.includes(ERR) && !/order-received/.test(pw.url()), detail: `« ${err.trim().slice(0, 80)} »` };
  });
  wp(`wp option patch update cwebts_settings secret_key 1x0000000000000000000000000000000AA`);

  // ---------- Éditeur de blocs : aucune trace du widget ----------
  await step('Éditeur de blocs : aucune balise Turnstile chargée', async () => {
    await pa.goto(`${BASE}/wp-admin/post.php?post=1&action=edit`);
    await pa.waitForTimeout(6000);
    const n = await pa.evaluate(() => { const all = [document, ...[...document.querySelectorAll('iframe')].map(f => { try { return f.contentDocument; } catch (e) { return null; } }).filter(Boolean)]; return all.reduce((s, d) => s + d.querySelectorAll('.cf-turnstile, script[src*="challenges.cloudflare.com"]').length, 0); });
    return { ok: n === 0, detail: `éléments Turnstile=${n}` };
  });

  await browser.close();
  const ours = consoleErrors.filter(e => /turnstile|cwebts|cloudflare/i.test(e));
  fs.writeFileSync(path.join(OUT, 'console.txt'), consoleErrors.join('\n'));
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
  const fail = results.filter(r => !r.ok).length;
  console.log(`\n${results.length} étapes, ${results.length - fail} réussies, ${fail} en échec. Erreurs console : ${consoleErrors.length} (dont liées à Turnstile/extension : ${ours.length})`);
  ours.forEach(e => console.log('  console: ' + e.slice(0, 200)));
})().catch(e => { console.error(e); process.exit(1); });
