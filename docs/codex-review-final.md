# Revue finale - Captcha Field for Turnstile

## BLOQUANT

Aucun bloquant identifie.

## IMPORTANT

1. Import `cfturnstile_failover` / `cfturnstile_failsafe_type`: mapping incomplet quand le type source est absent ou vide.

Dans le plugin source, le select "Failsafe Type" traite une valeur vide comme `allow` (`! $failsafe_type || $failsafe_type == 'allow'`). Ici, l'import ne considere comme `allow` que `pass`, `allow`, `open`, `available`; donc une installation Simple Cloudflare Turnstile avec `cfturnstile_failover = 1` et `cfturnstile_failsafe_type` absent/vide sera importee en `failure_mode = block`.

Impact: pas de fail-open ni de fuite de securite; c'est meme plus strict. Mais c'est une perte de semantique d'import pour les installs anciennes/incompletes qui utilisaient le defaut "allow". Corriger en traitant `''` comme `allow` quand `cfturnstile_failover` est truthy.

Reference locale: `captcha-field-for-turnstile/includes/class-settings.php:341`.
Source verifiee: `https://github.com/ElliotSowersby/simple-cloudflare-turnstile/blob/master/inc/admin/admin-options.php` et `https://github.com/ElliotSowersby/simple-cloudflare-turnstile/blob/master/inc/admin/register-settings.php`.

## MINEUR

1. `turnstile.js`: correctif robuste pour le cas nominal, mais correlation multi-formulaires encore best-effort.

Le code stocke bien le `widgetId` retourne par `render()` et reset via cet id, avec fallback element; `error-callback` est branche; le reset AJAX Elementor est limite aux reponses `admin-ajax.php` contenant `response.success`, et seulement sur `false`. C'est une nette correction des findings precedents.

Reserve: le suivi FIFO peut encore reset le mauvais formulaire si deux formulaires Elementor sont soumis simultanement et que les reponses AJAX arrivent dans l'ordre inverse. Risque faible, non bloquant, deja annonce dans le commentaire du code comme "best-effort".

Reference locale: `captcha-field-for-turnstile/assets/js/turnstile.js:87`.

2. Import `language`: fidelite partielle des valeurs du plugin source.

Les noms d'options sont corrects (`cfturnstile_language`), mais Simple Cloudflare Turnstile stocke beaucoup de codes regionaux (`en-us`, `fr-fr`, `de-de`, etc.). La liste locale n'accepte que des codes plus courts pour plusieurs langues (`en`, `fr`, `de`, etc.), donc `sanitize()` retombera a `auto` pour plusieurs valeurs importees. C'est robuste, mais pas une copie fidele.

Reference locale: `captcha-field-for-turnstile/includes/class-settings.php:63`.
Source verifiee: `https://github.com/ElliotSowersby/simple-cloudflare-turnstile/blob/master/inc/admin/admin-options.php`.

## Verdict global

Partie A: findings precedents correctement corriges.

- `Verifier`: correct. Un JSON contenant la cle `success` est honore quel que soit le statut HTTP; `success:false` rejette meme en `failure_mode=allow`; `WP_Error`, body vide, JSON invalide ou JSON sans `success` restent des erreurs transport soumises a `failure_mode`.
- `turnstile.js`: correct pour le flux attendu. Le reset par `widgetId`, le filtre `admin-ajax.php`, le reset seulement sur `response.success === false`, le stale-clear et `error-callback` corrigent les regressions signalees. Reste seulement la limite FIFO mentionnee en mineur.
- Elementor field: garde statique OK pour eviter les doublons dans une requete PHP WordPress normale.
- Packaging/readme: OK. `.distignore` exclut `tests/` et `README.md`; description header raccourcie; licence harmonisee `GPLv2 or later`; `Tested up to: 7.0`; disclosure Cloudflare explicite pour token, secret key et IP.

Partie B: import globalement sain.

- Securite OK: `admin-post.php`, capability `manage_options`, nonce `check_admin_referer`, sortie redirigee via `wp_safe_redirect`.
- Pas d'alteration de l'autre plugin: l'import lit les options `cfturnstile_*` et ecrit seulement `turnstile_forms_settings`.
- Secret: pas affiche dans l'UI d'import; il reste dans le stockage serveur et l'input secret du plugin reste write-only.
- Sanitization/robustesse: les valeurs passent par `sanitize()`; les valeurs absentes sont sautees; les valeurs invalides retombent aux defaults.
- Mapping des vrais noms d'options source verifie: `cfturnstile_key`, `cfturnstile_secret`, `cfturnstile_theme`, `cfturnstile_size`, `cfturnstile_appearance`, `cfturnstile_language`, `cfturnstile_error_message`, `cfturnstile_login`, `cfturnstile_register`, `cfturnstile_reset`, `cfturnstile_comment`, `cfturnstile_failover`, `cfturnstile_failsafe_type`.

Verification locale:

- `php captcha-field-for-turnstile/tests/run-tests.php`: 49 run, 49 passed, 0 failed.
- `php -l` sur tous les fichiers PHP du plugin: aucune erreur de syntaxe.
