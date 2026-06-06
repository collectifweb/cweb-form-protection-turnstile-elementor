# Plan : Captcha Field for Turnstile — Elementor & WordPress Forms

> Plan consolidé (consensus Claude + Codex, 2026-06-06). Autonome et exécutable.
> Historique du débat : `docs/archives/confront-codex-turnstile-elementor-2026-06-06-1137/`

## Contexte

Plugin WordPress open-source (destiné à WordPress.org) ajoutant la protection
**Cloudflare Turnstile** aux formulaires. Inspiré de `simple-cloudflare-turnstile`
(Elliot Sowersby), mais avec **un différenciateur clé** : un **champ Turnstile
ajouté formulaire par formulaire** dans le module Forms d'Elementor Pro (comme
l'intégration reCAPTCHA v2 native), permettant de choisir **quels** formulaires
Elementor sont protégés. Le concurrent applique Turnstile globalement ; ici la
granularité est par formulaire, par construction (le champ est présent ou non).

## Périmètre v1.0

**Inclus :**
1. **Champ Turnstile Elementor Pro** (par formulaire) — cœur du projet.
2. **Formulaires WordPress natifs** (toggles individuels, off par défaut) :
   connexion, inscription, mot de passe oublié, commentaires.
3. **Page de réglages** globale (clés + apparence + toggles).
4. **Import depuis « Simple Cloudflare Turnstile »** (Elliot Sowersby) : bouton
   sur la page de réglages (admin-post + nonce + capability) qui recopie les
   options `cfturnstile_*` (clés `cfturnstile_key`/`cfturnstile_secret`, thème,
   taille, apparence, langue, message, toggles `login`/`register`/`reset`/`comment`,
   et `failover`/`failsafe_type` → `failure_mode`). Valeurs passées par
   `sanitize()`. N'altère pas l'autre plugin ; ne régénère aucune clé Cloudflare.
   *(Ajout demandé après le consensus initial — couvert par la re-validation Codex.)*

**Reporté (v1.1+), explicitement hors v1 :**
- Mode « auto-protéger tous les formulaires Elementor » (`elementor_auto_all`) —
  recrée la surface fragile des plugins globaux (injection DOM, popups, rendu
  tardif, cache, double-rendu). Si ajouté : JS explicite + validation globale,
  jamais sur un hook de pré-rendu non confirmé.
- WooCommerce (login/register/checkout) — surface trop large, peu différenciant.
- `update_controls` (réglages Turnstile **par champ** dans l'éditeur Elementor) —
  v1 = réglages globaux ; le renderer est néanmoins prêt à recevoir des overrides.
- Autres plugins de formulaires (CF7, WPForms, Gravity) — v2.

## Pré-requis & compatibilité

- WordPress ≥ 5.8 ; PHP ≥ 7.4 (testé 8.3) ; GPLv2+.
- **Elementor Pro requis** pour le champ (module Forms = Pro). Si Pro absent →
  l'intégration Elementor ne se charge pas, les formulaires natifs restent actifs.
  Si Elementor free seul → idem (Forms est Pro). Versions « tested up to » à
  documenter dans le readme.
- Dépendance externe unique : l'API officielle Cloudflare
  `https://challenges.cloudflare.com/turnstile/v0/api.js` (documentée dans le
  readme comme appel à un service tiers). Aucune dépendance Composer runtime.

## Architecture

Pas de singleton lourd. Une classe `Plugin` câble les hooks et instancie des
services testables. État global réduit aux constantes de bootstrap.

```
captcha-field-for-turnstile/
├── captcha-field-for-turnstile.php   # En-tête + bootstrap + garde ABSPATH
├── uninstall.php                     # delete_option
├── readme.txt                        # WordPress.org
├── README.md                         # GitHub
├── LICENSE                           # GPLv2+
├── includes/
│   ├── class-plugin.php              # Câblage hooks, instancie services
│   ├── class-settings.php           # Settings API, page admin, sanitization, secret write-only
│   ├── class-verifier.php           # siteverify, matrice 4 cas, cache par requête
│   ├── class-widget-renderer.php    # HTML widget, enqueue conditionnel, data-action, overrides
│   ├── elementor/
│   │   └── class-turnstile-field.php# Field_Base : champ par formulaire
│   └── integrations/
│       ├── class-wp-login.php
│       ├── class-wp-register.php
│       ├── class-wp-lostpassword.php
│       └── class-wp-comments.php
├── assets/
│   ├── css/admin.css
│   └── js/turnstile.js               # rendu explicite + reset AJAX + expired-callback
├── languages/
│   └── captcha-field-for-turnstile.pot
└── tests/                            # PHPUnit Verifier + sanitization + protocole manuel
```

- Namespace PHP interne : `TurnstileForms` (non public, OK).
- Préfixe constantes : `TURNSTILE_FORMS_`. Option unique (array) :
  `turnstile_forms_settings`. Préfixe hooks/filtres publics : `turnstile_forms_`.

## Réglages (page admin, `manage_options`, Settings API + nonce)

| Réglage | Type | Défaut | Notes |
|---|---|---|---|
| `site_key` | text | '' | Clé publique |
| `secret_key` | password **write-only** | '' | Vide par défaut ; sauvegarde vide = conserver ; bouton effacer/remplacer ; indicateur « clé configurée » sans exposer |
| `theme` | select auto/light/dark | auto | `data-theme` |
| `size` | select normal/flexible/compact | flexible | `data-size` |
| `appearance` | select always/interaction-only | always | `data-appearance` (mode manuel `execution=execute` NON exposé en v1) |
| `language` | select auto + liste | auto | `data-language` |
| `error_message` | text | « Veuillez confirmer que vous n'êtes pas un robot. » | Message d'échec |
| `protect_login` | checkbox | off | |
| `protect_register` | checkbox | off | |
| `protect_lostpassword` | checkbox | off | |
| `protect_comments` | checkbox | off | |
| `failure_mode` | select block/allow | block | S'applique **uniquement** au cas 4 (réseau/timeout) |

Sanitization : `sanitize_text_field`, `sanitize_key`, whitelist stricte des
selects. Échappement systématique à l'affichage.

## Vérification serveur (`class-verifier.php`)

`Verifier::verify( string $token, ?string $remoteip = null, ?string $expected_action = null ): bool`

**Matrice à 4 cas (ordre strict) :**
1. **Clés absentes** → géré en amont (widget non rendu, pas de blocage, notice
   admin). Le Verifier n'est pas appelé.
2. **Token absent / vide / > 2048 caractères** → rejet **local**, aucun appel réseau.
3. **Réponse Cloudflare `success:false`** (`invalid-input-response`,
   `timeout-or-duplicate`, `invalid-input-secret`, …) → **rejet**.
4. **Erreur réseau / timeout / `WP_Error` / JSON invalide** → appliquer
   `failure_mode` (**block** par défaut).

**Détails :**
- `wp_remote_post()` vers `https://challenges.cloudflare.com/turnstile/v0/siteverify`,
  body `secret` + `response` (+ `remoteip` si valide). **Timeout 5 s**, filtrable
  via `turnstile_forms_timeout`.
- **Cache statique par requête** : stocker la **réponse Cloudflare brute** indexée
  par `hash` du token ; ne jamais appeler Siteverify 2× pour le même token dans la
  même requête. Les contrôles locaux (`action`/`hostname`/filtres) sont appliqués
  **après** lecture du cache (le cache ne court-circuite pas un contrôle local).
- **`remoteip`** : `$_SERVER['REMOTE_ADDR']` strict par défaut (jamais `X-Forwarded-For`),
  validé par `filter_var(..., FILTER_VALIDATE_IP)` ; omis si vide/invalide.
  Filtre `turnstile_forms_remoteip` pour désactiver (`''`/`null`) ou fournir une IP
  de chaîne proxy maîtrisée.
- **`action`** : `data-action` défini par contexte (`elementor_form`, `wp_login`,
  `wp_register`, `wp_lostpassword`, `wp_comment`) pour l'observabilité Cloudflare.
  Vérification serveur stricte **désactivée par défaut**, activable via filtre
  `turnstile_forms_verify_action`.
- **`hostname`** : vérification serveur **désactivée par défaut**, activable via
  `turnstile_forms_verify_hostname` ; si activée, comparaison contre une
  **allowlist filtrable** (pas une seule valeur `home_url()`).
- Jamais de log du token. Pas de bypass silencieux si secret invalide → échec +
  notice admin.

## Intégration Elementor (`elementor/class-turnstile-field.php`)

Enregistrement (uniquement quand le module Forms est chargé → pas de fatal) :
```php
add_action( 'elementor_pro/forms/fields/register', function ( $registrar ) {
    require_once __DIR__ . '/elementor/class-turnstile-field.php';
    $registrar->register( new \TurnstileForms\Elementor\Turnstile_Field() );
} );
```

`class Turnstile_Field extends \ElementorPro\Modules\Forms\Fields\Field_Base` :
- `get_type()` → `'turnstile'` ; `get_name()` → « Cloudflare Turnstile ».
- `render( $item, $item_index, $form )` : enqueue conditionnel de l'API + echo du
  `<div class="cf-turnstile" data-sitekey data-theme data-size data-appearance
  data-language data-action="elementor_form">` (via `Widget_Renderer`). Rendu
  **explicite** côté JS pour gérer reset/multi-widgets.
- `validation( $field, $record, $ajax_handler )` : lit
  `$_POST['cf-turnstile-response']` (`wp_unslash` + sanitization + limite 2048),
  appelle `Verifier::verify()` ; si échec → `$ajax_handler->add_error( $field['id'],
  $error_message )`. La validation ne s'exécute que pour les formulaires contenant
  le champ → granularité par formulaire.
- Doublons : si plusieurs champs Turnstile détectés dans un form, valider une
  seule fois (cache par requête) + notice.

## Intégrations WordPress natives (`includes/integrations/`)

Chacune gardée par son toggle, rend le widget (`Widget_Renderer`), lit
`$_POST['cf-turnstile-response']`, appelle `Verifier::verify()`.

| Form | Rendu | Validation | data-action |
|---|---|---|---|
| Login | `login_form` | `authenticate` / `wp_authenticate_user` (`WP_Error`) | `wp_login` |
| Register | `register_form` | `registration_errors` | `wp_register` |
| Lost pw | `lostpassword_form` | `lostpassword_post` | `wp_lostpassword` |
| Comments | `comment_form_after_fields` **+** `comment_form_logged_in_after` | `preprocess_comment` (ignorer pingback/trackback via `comment_type`) | `wp_comment` |

## Assets & JS (`assets/js/turnstile.js`)

- API Cloudflare chargée avec `defer` via filtre `script_loader_tag` ciblé sur le
  handle (compat WP 5.8+ ; `wp_enqueue_script` strategy seule = WP 6.3+).
- Enqueue **conditionnel** : seulement si un widget est rendu sur la page.
- **Rendu explicite** (`turnstile.render`) pour widgets Elementor.
- **Reset/re-render après TOUTE réponse AJAX Elementor non-succès** (pas seulement
  erreur Turnstile) + gestion `expired-callback` → évite `timeout-or-duplicate` à
  la 2ᵉ soumission. Events frontend Elementor exacts à confirmer au codage.
- Formulaires natifs : rendu implicite suffisant (reload pleine page) ; gérer
  `expired-callback`.

## Tests

- **Unitaires `Verifier`** (mock HTTP via filtre `pre_http_request` ou transport
  injectable) : succès, `success:false`, `WP_Error`, JSON invalide, timeout,
  token vide, token > 2048, cache par requête.
- **Sanitization** des réglages (selects whitelistés, secret write-only).
- **Protocole manuel versionné** avec les **clés de test Cloudflare**
  (sitekeys/secrets déterministes) pour Elementor Pro + formulaires natifs.
- Transparence : l'intégration Elementor Pro n'est pas exécutable en CI sans WP +
  Pro ; documenté honnêtement. `php -l` sur tous les fichiers ; viser WPCS.

## Filtres publics

`turnstile_forms_timeout`, `turnstile_forms_remoteip`,
`turnstile_forms_verify_action`, `turnstile_forms_verify_hostname`,
`turnstile_forms_hostname_allowlist`.

## Conformité WordPress.org

- GPLv2+ ; en-têtes complets (`Requires at least`, `Requires PHP`, `Tested up to`).
- Garde `ABSPATH` en tête de chaque fichier. Sorties échappées, entrées
  sanitizées, nonces (Settings API), capacités (`manage_options`).
- `wp_remote_post` (pas de cURL direct). Aucun script tiers bundlé ; secret jamais
  exposé côté client ni loggé. Pas de tracking/phone-home. `uninstall.php` propre.
- i18n complet (`.pot`, `load_plugin_textdomain`). Préfixes uniques partout.
- **Branding** : nom public et slug ne commencent PAS par
  `turnstile`/`cloudflare`/`elementor`/`wordpress`/`wp`. Proposition de travail :
  slug `captcha-field-for-turnstile`, titre « Captcha Field for Turnstile —
  Elementor & WordPress Forms ». Readme : « Not affiliated with Cloudflare or
  Elementor. » **Nom public final à confirmer par le mainteneur avant packaging.**

## Découpage de livraison

1. Squelette + bootstrap + `Settings` (incl. secret write-only) + `Verifier`
   (matrice 4 cas + cache) + `Widget_Renderer`.
2. Champ Elementor (render + validation) — différenciateur.
3. Intégrations natives (login, register, lostpassword, comments).
4. `turnstile.js` (rendu explicite + reset AJAX + expired-callback) + admin.css.
5. Tests `Verifier`/sanitization + protocole manuel.
6. readme.txt / README.md / .pot / LICENSE / uninstall.php.
7. Re-validation Codex + `php -l`.

## Points de vigilance (suivi pendant l'implémentation)

- Ne jamais référencer `Field_Base` hors du callback `…/fields/register`.
- Reset du widget après tout échec AJAX, sinon `timeout-or-duplicate`.
- Cache Verifier = réponse brute par token, contrôles locaux appliqués après.
- Secret write-only : ne jamais renvoyer la valeur dans le HTML du formulaire.
- Commentaires : couvrir le cas utilisateur connecté + exclure pingback/trackback.
- `defer` via `script_loader_tag` pour rester compatible WP 5.8.
- Valider l'IP avant de l'envoyer comme `remoteip`.

## Décisions explicitement écartées

- **`data-response-field-name` → `form_fields[...]`** : écarté en v1 (couplage aux
  internes Elementor inutile tant que le champ caché Cloudflare est sérialisé).
- **`failure_mode=allow` par défaut** : écarté (désactive la protection au pire
  moment) ; `allow` réservé au cas réseau, opt-in.
- **Vérif `action`/`hostname` strictes par défaut** : écarté (risque de faux
  rejets > gain en v1) ; disponibles par filtre.
- **Singleton central lourd** : écarté au profit de services instanciables.
- **Timeout 10 s** : écarté pour 5 s (UX formulaire).
