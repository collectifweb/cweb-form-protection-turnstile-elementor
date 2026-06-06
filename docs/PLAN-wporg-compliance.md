# Plan — Mise aux normes WordPress.org + harmonisation repo

> Passe de conformité/packaging. Le code métier (déjà validé Codex, 52/52 tests)
> ne change pas dans sa logique — on renomme, on harmonise, on package.
> **Aucune rétro-compat nécessaire** : le plugin n'a jamais été publié (fresh installs).

## Décisions actées

- **Nom public** : `CWeb Turnstile for Elementor Forms`
- **Slug / dossier / text domain** : `cweb-turnstile-for-elementor-forms`
- **Fichier principal** : `cweb-turnstile-for-elementor-forms.php`
- **Repo GitHub** : `collectifweb/CWeb-Turnstile-for-Elementor-Forms` (Title-Case, calqué sur le sibling)
- **Préfixe distinctif** : `CWEBTS_` (constantes) · `cwebts_` (fonctions/hooks/option/query) · `cwebts-` (CSS) · `cwebtsOnload` (JS) · namespace `CWebTS\`
- **Conservé tel quel** (requis Cloudflare) : classe CSS `cf-turnstile`, champ POST `cf-turnstile-response`
- **Contributors** : `alexandreminem, collectifweb` (compte qui soumet en tête)
- **Author / URI** : `Collectif WEB` / `https://collectif-web.ca`

## Table de renommage (identifiants globaux)

| Actuel | Nouveau |
|---|---|
| `TURNSTILE_FORMS_VERSION/FILE/DIR/URL/BASENAME` | `CWEBTS_VERSION/FILE/DIR/URL/BASENAME` |
| fn `turnstile_forms_bootstrap()` | `cwebts_bootstrap()` |
| namespace `TurnstileForms` (+ `\Elementor`, `\Integrations`) | `CWebTS` (+ sous-ns identiques) |
| option `turnstile_forms_settings` | `cwebts_settings` |
| filtres `turnstile_forms_{remoteip,timeout,verify_action,verify_hostname,hostname_allowlist}` | `cwebts_{…}` |
| action admin-post `turnstile_forms_import` | `cwebts_import` |
| code WP_Error `turnstile_forms_failed` | `cwebts_failed` |
| query arg `tf_imported` | `cwebts_imported` |
| settings page slug `turnstile-forms` | `cweb-turnstile` |
| handles scripts `turnstile-forms`, `turnstile-forms-api` | `cwebts`, `cwebts-api` |
| handle style `turnstile-forms-admin` | `cwebts-admin` |
| CSS `turnstile-forms-{settings,widget,import}` | `cwebts-{settings,widget,import}` |
| JS global `window.turnstileFormsOnload` (+ onload arg api.js) | `window.cwebtsOnload` |
| text domain `captcha-field-for-turnstile` (×56) | `cweb-turnstile-for-elementor-forms` |
| `@package TurnstileForms` (docblocks) | `@package CWebTS` |

## Étapes

1. **Renommer le dossier plugin** `captcha-field-for-turnstile/` → `cweb-turnstile-for-elementor-forms/`,
   le fichier principal et le `.pot`.
2. **Appliquer la table de renommage** dans tous les `*.php`, `*.js`, `*.css`
   (sed ciblé, ordre long→court pour éviter les collisions partielles).
3. **Mettre à jour le header** du fichier principal (Name, URI, Author, Author URI,
   Text Domain) + le disclaimer marques (Cloudflare **et** Elementor).
4. **readme.txt** : titre, `Contributors: alexandreminem, collectifweb`, courte
   description, section `== About the name ==` (modèle sibling), conserver la
   divulgation service tiers + trademarks.
5. **LICENSE** : remplacer le GPL complet (338 l.) par la **notice courte** du
   sibling (nom plugin + copyright Collectif WEB 2024-2026 + pointeur GPLv2).
6. **Régénérer le `.pot`** avec le nouveau text domain + header.
7. **Structurer le repo** (calqué sibling) :
   - `cweb-turnstile-for-elementor-forms/` (plugin)
   - `docs/ARCHITECTURE.md` (nouveau, structure du sibling) + plan/status existants
   - `docs/screenshots/` + `docs/wp-org-assets/` (icon.svg + banner.svg ; PNG à exporter)
   - `CHANGELOG.md` (Keep a Changelog, entrée `[1.0.0]`)
   - `README.md` (GitHub, badges, disclaimer), `.gitignore` (modèle sibling), `LICENSE`
8. **Mettre à jour les tests** (option `cwebts_settings`, namespace `CWebTS`,
   filtres `cwebts_*`, stubs bootstrap) → relancer : doit rester **52/52**.
9. **`php -l`** sur tous les fichiers + grep anti-référence orpheline
   (`turnstile_forms|TURNSTILE_FORMS|TurnstileForms|captcha-field-for-turnstile`
   ne doit plus rien matcher hors `cf-turnstile`).
10. **Git** : `git init`, commit initial propre, créer le repo GitHub
    `collectifweb/CWeb-Turnstile-for-Elementor-Forms` (**visibilité à confirmer**),
    push `main`.
11. **Construire le zip de distribution** (exclusions `.distignore`) prêt pour
    l'upload de revue WP.org.
12. **Actualiser** `docs/STATUS.md` + mémoire projet (nouveau nom/slug).

## Hors scope (tâches humaines, signalées)

- Captures d'écran réelles (`screenshot-1/2.png`) — nécessitent un WP + Elementor Pro.
- Export PNG des banner/icon depuis les SVG (si pas d'outil de rasterisation dispo).
- Création réelle de la page WP.org + commit SVN `trunk`/`tags`/`assets` (manuel,
  via le checkout `wp-svn/`, comme pour le sibling).
- Idéalement : un passage du plugin **Plugin Check (PCP)** sur un WP réel avant envoi.

## Points de vigilance

- Ordre des `sed` : remplacer les chaînes longues avant les courtes
  (`turnstile_forms_verify_action` avant `turnstile_forms_`).
- Ne PAS toucher `cf-turnstile` / `cf-turnstile-response`.
- Le namespace `ElementorPro\Modules\Forms\Fields\Field_Base` (import) ne change pas.
- Vérifier que l'autoloader (`CWebTS\` → `includes/`) suit le renommage du namespace.
