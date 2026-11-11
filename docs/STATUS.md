# STATUS — CWeb Form Protection with Turnstile for Elementor Forms (handoff / reprise après compaction)

> Dernière mise à jour : 2026-11-11 (rendez-vous de fin octobre : compatibilité 7.1.4 / 7.2 bêta, v1.2.3 publiée). Le plugin vit dans
> `cweb-form-protection-turnstile-elementor/` ; les tests dans `tests/` (racine repo,
> hors plugin) ; la doc + assets dans `docs/`.

> **MISE À JOUR 2026-11-11 (v1.2.3 — cache d'Elementor, mode par formulaire)** —
> Rendez-vous de fin de mois. Vérifié à l'exécution sur **WordPress 7.1.4 (PHP 8.3)
> et 7.2 bêta 5 (PHP 8.5)**, Elementor 4.4.0, Elementor Pro 4.2.1, WooCommerce
> 11.3.0 : scénario navigateur de 28 étapes (`tasks/todo.md`), toutes réussies
> après correctif. Lecture du diff du cœur 7.1.4 → 7.2 bêta 5 : aucun crochet
> utilisé par l'extension ne change. **Quatre vrais défauts trouvés et corrigés** :
> (1) le **champ Turnstile par formulaire** perdait son widget dès la deuxième
> visite (page servie par le cache d'éléments d'Elementor) : le cache réenfile
> `cwebts`/`cwebts-api`, mais l'extension ne les enregistrait qu'au rendu ; ils
> sont maintenant enregistrés sur `wp_enqueue_scripts`. Mesuré aussi avec
> Elementor 4.2.2 : le défaut existait déjà en août, la validation de la 1.2.2
> n'avait regardé que la première visite. (2) Des clés saisies ou changées après
> la mise en cache laissaient le formulaire sans widget jusqu'à 24 h : la purge de
> la 1.2.2 couvre maintenant les clés et l'apparence. (3) Trouvé par la relecture
> Codex puis reproduit : un **formulaire protégé dans un modèle réutilisé**
> (`[elementor-template]`), mis en cache sur une page qui chargeait déjà les scripts,
> restait vide sur une page où il est seul. Les formulaires protégés sont
> maintenant exclus du cache d'éléments (filtre `elementor/element/is_dynamic_content`,
> `Plugin::keep_turnstile_forms_dynamic()`). (4) Après une mise à jour, l'extension vide
> une fois ce cache (option `cwebts_version`), pour que les pages déjà en cache
> profitent des correctifs sans attendre 24 h. **Plugin Check 2.1+** signalait
> 3 « OffloadedContent » (règle élargie le 16 août) : `phpcs:ignore` justifiés,
> 2.2.0 → « No errors found ». Tests : 101 → 129, PHP 7.4 / 8.3 / 8.5.
> « Tested up to » laissé à 7.1 (la 7.2 sort le 8 décembre).

> **MISE À JOUR 2026-08-13 (v1.2.1 — compatibilité WordPress 7.1)** — Le courriel
> « WordPress 7.1 is scheduled for release on August 19 » demandait de passer
> `Tested up to` à 7.1. Aucun des 7 changements annoncés (éditeur en iframe,
> traitement d'images côté navigateur, `@wordpress/components`, barre d'outils
> persistante, API d'icônes SVG, jQuery UI 1.14.2, API Abilities) ne touche ce
> plugin — détail dans le CHANGELOG. Un vrai défaut a par contre été trouvé et
> corrigé : `Settings::defaults()` appelait `__()`, atteint dès `plugins_loaded`
> par le constructeur de chaque intégration, ce que WordPress 6.7+ signale comme
> « translation triggered too early ». Le repli traduit vit maintenant dans
> `Settings::default_error_message()`, appelé au moment d'afficher ou de valider.
>
> **Vérifié à l'exécution sur WordPress 7.1-RC3** (wp-env/Docker, Elementor 4.2.2,
> clés de test Cloudflare) : 1 avis par page avant correctif → 0 après, journal PHP
> vide, widget rendu sur la connexion et les commentaires, vérification serveur
> réelle chez Cloudflare (bloqué sans jeton, accepté avec), aucune balise du
> plugin dans l'éditeur d'articles iframé, **Plugin Check : « No errors found »**.
> `.pot` régénéré avec `wp i18n make-pot` (16 chaînes qui manquaient). Tests :
> 92 → 95.
>
> **MISE À JOUR 2026-08-13 (v1.2.2 — cache Elementor)** — La couverture demandée
> ensuite (WooCommerce, Elementor **Pro** 4.2.1 avec l'archive fournie par
> Alexandre, site en français) a validé les 4 formulaires WooCommerce (commande
> réellement passée), le champ Turnstile par formulaire et le message par défaut
> traduit — et a fait sortir un vrai défaut, corrigé dans la foulée : le cache
> d'éléments d'Elementor 4.x neutralisait l'injection du mode « tous les
> formulaires » (voir « Limites connues »). Tests : 95 → 101.

> **MISE À JOUR 2026-06-08 (réponse à la pré-review automatique WP.org)** — La
> soumission a été *pended* (review `AUTOPREREVIEW`, voir le `.eml` dans `docs/`).
> Deux points : (1) **nom/slug** — « Turnstile » menait le titre, position qui
> suggère une affiliation. Renommé **`CWeb Turnstile for Elementor Forms` →
> `CWeb Form Protection with Turnstile for Elementor Forms`** (slug
> `cweb-turnstile-for-elementor-forms` → **`cweb-form-protection-turnstile-elementor`**),
> structure conforme : terme distinctif possédé (« CWeb Form Protection ») en tête,
> marques tierces après « with »/« for ». Label de menu admin → « CWeb Form
> Protection ». Banner WP.org refait dans le même sens. Namespace `CWebTS` et slug
> d'admin `cwebts` conservés (abréviations internes, hors périmètre trademark).
> (2) **`Requires Plugins: elementor`** ajouté au header (décision : plugin centré
> Elementor ; protection WP native = bonus). **52/52 tests OK**, zip
> `cweb-form-protection-turnstile-elementor-1.0.0.zip` reconstruit. Reste : répondre
> au courriel **en demandant la réservation du nouveau slug**, ré-upload sur « Add
> your plugin », renommer le repo GitHub `collectifweb/cweb-form-protection-turnstile-elementor`.

> **MISE À JOUR 2026-06-06 (mise aux normes WordPress.org)** — Le plugin a été
> **renommé `captcha-field-for-turnstile` → `CWeb Form Protection with Turnstile for Elementor Forms`**
> (slug `cweb-form-protection-turnstile-elementor`, namespace `CWebTS`, préfixe
> `cwebts_`/`CWEBTS_`/`.cwebts-` ; `cf-turnstile` gardé). Repo harmonisé sur le
> sibling `cweb-product-finder-for-gravity-forms` : `README.md`/`LICENSE`/
> `CHANGELOG.md` à la racine, `docs/{ARCHITECTURE.md,screenshots/,wp-org-assets/}`,
> `tests/` sorti du dossier plugin. **Plugin Check** (rapport
> `docs/cweb-turnstile-...-184143.md`) **corrigé** : tests hors-build, `.distignore`
> supprimé, `load_plugin_textdomain` retiré, version sur `wp_register_script`,
> `phpcs:ignore` nonce ciblés, vars uninstall préfixées. `Contributors:
> alexandreminem`. `php -l` OK, **52/52 tests**, zip `cweb-form-protection-turnstile-elementor-1.0.0.zip` prêt.
> Repo GitHub à renommer en `collectifweb/cweb-form-protection-turnstile-elementor`.
> Reste : screenshots réels, SVN, soumission.
> Les sections ci-dessous gardent l'historique de conception (slugs d'origine).

## Où en est-on

**Plugin v1.2.3 en ligne sur WordPress.org depuis le 2026-11-11 (13 h 42, heure de
Toronto)**, sur le « oui » d'Alexandre : commit `a0541c6`, étiquette et release GitHub
`v1.2.3` (avec le zip), SVN wp.org rév. 3796883 (trunk + tags/1.2.3). Vérifié après
coup : l'API WordPress.org annonce la 1.2.3, et le zip public est identique, fichier
pour fichier, au dossier de l'extension dans le dépôt. Le développement de fonctionnalités est en pause ; ce qui
bouge relève de la maintenance (compatibilité, correctifs).

Pipeline suivi à chaque version : plan → **confront-codex (consensus)** →
développement → **re-revue Codex du code** → déploiement (git, release GitHub,
SVN wp.org).

État qualité (2026-11-11, v1.2.3) : `php -l` propre ; **129/129 tests** sous PHP 7.4,
8.3 et 8.5 (`php tests/run-tests.php`, à la racine du dépôt, hors dossier plugin) ;
**Plugin Check 2.2.0 : « No errors found »** sur WP 7.1.4 et 7.2 bêta 5 ; scénario
navigateur de 28 étapes réussi sur les deux (`tests/e2e/`). Historique de la 1.2.2 ci-dessous.
Vérifié à l'exécution le 2026-08-13 sur **WordPress 7.1-RC3** avec Elementor Pro
4.2.1 et WooCommerce 11.0.1, site en français : champ Turnstile par formulaire,
4 formulaires WooCommerce (dont une commande réellement passée), message par
défaut bien traduit, aucun avis PHP. Seul écart trouvé — le cache d'éléments
d'Elementor 4.x face au mode « tous les formulaires » — **corrigé en 1.2.2** et
revérifié sur le même environnement (voir « Limites connues »).

## Objectif & différenciateur

Plugin WordPress open-source (cible WordPress.org) ajoutant **Cloudflare Turnstile**.
Différenciateur vs `simple-cloudflare-turnstile` (Elliot Sowersby) : **champ
Turnstile par formulaire dans Elementor Pro** (comme reCAPTCHA v2 natif) → on
choisit quels formulaires sont protégés. + intégrations WordPress natives.
Bonus demandé : **import des clés/réglages depuis le plugin d'Elliot** (pas de
régénération de clés Cloudflare).

## Carte des fichiers (plugin)

```
captcha-field-for-turnstile/
├── captcha-field-for-turnstile.php   Bootstrap + autoloader (namespace TurnstileForms)
├── uninstall.php                     Supprime les 2 options (mono + multisite)
├── .distignore                       Exclut tests/, README.md du build WP.org
├── readme.txt / README.md / LICENSE  Packaging (GPLv2 or later)
├── includes/
│   ├── class-plugin.php              Câble les hooks, instancie les services
│   ├── class-settings.php           Réglages, page admin, secret write-only, IMPORT Elliot
│   ├── class-verifier.php           siteverify, matrice 4 cas, cache par requête
│   ├── class-widget-renderer.php    HTML widget + enqueue conditionnel (defer)
│   ├── elementor/class-turnstile-field.php   Champ par formulaire (render + validation)
│   └── integrations/                WP login / register / lost-password / comments (+ base)
├── assets/js/turnstile.js           Rendu explicite + reset AJAX (échec) + expired-callback
├── assets/css/admin.css
├── languages/captcha-field-for-turnstile.pot  (54 chaînes)
└── tests/                           bootstrap.php (stubs WP) + run-tests.php (52) + MANUAL-PROTOCOL.md
```

## Décisions verrouillées (consensus Codex + ajouts)

- **Périmètre v1** : champ Elementor + WP natifs (login/register/lostpw/comments)
  + page réglages + **import Elliot**. **Hors v1** : auto-all Elementor,
  WooCommerce, réglages Turnstile par champ (`update_controls`).
- **Verifier — matrice 4 cas** : (1) clés absentes → pas de widget, pas de blocage,
  notice admin ; (2) token vide/>2048 → rejet local ; (3) `success:false` → rejet
  (même en `allow`) ; (4) WP_Error/body vide/JSON sans `success` → `failure_mode`
  (**block** par défaut). Verdict JSON honoré quel que soit le statut HTTP. Timeout
  5 s (`turnstile_forms_timeout`). Cache statique par hash de token (anti
  `timeout-or-duplicate`).
- **Token Elementor** : lu dans `$_POST['cf-turnstile-response']` (pas de
  `data-response-field-name`). Validation une fois par requête (garde statique).
- **JS** : rendu explicite (`render=explicit&onload=turnstileFormsOnload`),
  reset par `widgetId` après soumission Elementor AJAX **en échec**
  (`response.success === false`, filtré `admin-ajax.php`), `expired/timeout/error-callback`.
  Multi-formulaires : suivi FIFO **best-effort** (limite connue, non bloquante).
- **remoteip** : `REMOTE_ADDR` strict (jamais XFF), validé, filtrable
  (`turnstile_forms_remoteip`).
- **action/hostname** : `data-action` posé par contexte ; vérif serveur
  **OFF par défaut** (`turnstile_forms_verify_action` / `_verify_hostname` +
  `_hostname_allowlist`).
- **Secret** : write-only (jamais affiché ni envoyé au navigateur ; vide = conserver ;
  case « remove » pour effacer).
- **Import Elliot** : `admin-post` + `manage_options` + nonce ; lit `cfturnstile_*`,
  écrit seulement `turnstile_forms_settings` ; mappe clés/thème/size/appearance/
  language(+normalisation régionale)/error_message/toggles + `failover`/`failsafe_type`
  → `failure_mode` (type vide = allow, sémantique d'Elliot). N'altère pas l'autre plugin.
- **Compat** : WP ≥ 5.8 (defer via `script_loader_tag`), PHP ≥ 7.4. Elementor Pro
  requis pour le champ ; sinon natifs seulement.
- **Nom/slug** : ne commence PAS par turnstile/cloudflare/elementor/wordpress/wp.
  Provisoire : slug `captcha-field-for-turnstile`. Namespace interne `TurnstileForms`.

## Reste à faire (reprise)

> Les six points de juin (nom public, captures, soumission WP.org, tests manuels,
> « Tested up to », chantier v1.1) sont **tous réglés** — le plugin est en ligne
> depuis le 2026-06-10 et en v1.2.1 depuis le 2026-08-13.

1. ~~Rejouer la vérification sur WordPress 7.1 finale~~ : fait le 2026-11-11 sur
   7.1.4 (et 7.2 bêta 5).
2. **Passer « Tested up to » à 7.2** quand la 7.2 sera en RC ou sortie (prévue le
   2026-12-08) : modification du seul readme, sans nouvelle version. Rejouer
   d'abord le scénario sur la RC.
3. (piste) Couvrir le **Checkout Block** de WooCommerce (la page de commande en
   blocs Gutenberg), aujourd'hui hors périmètre — seule la page à l'ancienne
   (shortcode) est protégée.

## Limites connues

- **Cache d'éléments d'Elementor 4.x vs champ par formulaire — CORRIGÉ en 1.2.3.**
  Sur un cache valide, Elementor n'affiche plus les champs : il ressert le HTML et
  réenfile les poignées de scripts mémorisées au premier rendu. Nos poignées
  n'étaient enregistrées qu'au rendu, donc ignorées : widget vide, envois
  refusés, dès la deuxième visite. `Widget_Renderer::register_assets()` les
  enregistre désormais sur `wp_enqueue_scripts` (sans les charger). La purge de
  `_elementor_element_cache` se déclenche aussi quand les clés ou l'apparence
  changent. Tester toujours **deux visites** (voir `tasks/lessons.md`).
- **Formulaire protégé dans un modèle Elementor réutilisé — CORRIGÉ en 1.2.3.**
  Le cache d'un document ne retient que les scripts que ce document ajoute à la
  file : un modèle rendu d'abord sur une page qui les avait déjà en file n'en
  retient aucun, et reste vide ailleurs. Reproduit avec `[elementor-template]` ;
  en-têtes, pieds de page du Theme Builder et fenêtres surgissantes passent par
  la même méthode (non reproduit). Les formulaires qui portent le champ, et tous
  les formulaires en mode « tous les formulaires », sont rendus à chaque visite.
  Limite : un formulaire dont le réglage « Cache Settings » (onglet Avancé) est
  forcé sur « Active » reste en cache et retrouve le défaut.
- **Signalé par la relecture Codex, non traité (antérieur à la 1.2.3)** : en mode
  par formulaire, une page sans widget ne charge aucun script ; un formulaire
  protégé inséré ensuite par AJAX (ou ajouté dans un aperçu Elementor déjà
  ouvert) n'a donc pas de widget. Un cache de pages ou un CDN qui n'écoute pas
  la purge d'Elementor peut servir l'ancien widget après un changement de clé.
  La purge vide aussi les CSS générées par Elementor sur tout le site.
- **Cache d'éléments d'Elementor 4.x vs mode « tous les formulaires » — CORRIGÉ
  en 1.2.2.** Mesuré le 2026-08-13 sur WP 7.1-RC3 + Elementor Pro
  4.2.1 : Elementor 4.x met en cache le HTML rendu de chaque widget dans la
  métadonnée `_elementor_element_cache` (durée 24 h ; ce n'est plus une
  expérimentation, c'est le comportement standard). Quand ce cache existe, le
  filtre `elementor/widget/render_content` **ne se déclenche pas du tout** (sonde :
  0 appel) → le widget n'est pas injecté, alors que la validation
  `elementor_pro/forms/validation`, elle, tourne. Résultat : formulaire sans
  captcha mais soumission refusée, sans recours pour le visiteur. Fenêtre
  d'exposition : jusqu'à 24 h après l'activation de l'option, sur les pages déjà
  rendues. Une fois le cache reconstruit, il contient le widget et tout redevient
  normal. **N'affecte pas le mode par défaut** (champ Turnstile posé dans le
  formulaire : Elementor vide le cache à l'enregistrement de la page).
  *Correctif livré en 1.2.2* : `Settings::init()` écoute sa propre option
  (`update_option_cwebts_settings` et `add_option_cwebts_settings`) ; quand
  `protect_elementor_all_forms` change vraiment, il purge
  `_elementor_element_cache` (`delete_post_meta_by_key()`, fonction du cœur) et
  appelle `files_manager->clear_cache()` si Elementor est là. Vérifié sur le même
  environnement : cache vidé à l'activation, widget présent au rendu suivant,
  vidé aussi à la désactivation, et intact quand on enregistre un autre réglage.
- Reset Turnstile multi-formulaires Elementor simultanés = corrélation FIFO
  best-effort (peut reset le mauvais widget si soumissions concurrentes inversées).
- Le passage en caisse **en blocs** de WooCommerce n'est pas protégé (périmètre
  assumé depuis la 1.2.0).

## Artefacts de validation

- Plan final : `docs/plan-turnstile-elementor.md`
- Débat consensus : `docs/archives/confront-codex-turnstile-elementor-2026-06-06-1137/`
- Revue code Codex : `docs/codex-review-implementation.md`
- Validation finale Codex : `docs/codex-review-final.md`
- Brouillon initial : `docs/PLAN.md`
