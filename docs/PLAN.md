# Plan technique — Turnstile for Elementor & WordPress Forms

> Statut : **DRAFT** — à confronter avec Codex avant développement.
> Dernière mise à jour : 2026-06-06

## 1. Objectif & différenciateur

Plugin WordPress open-source (destiné à WordPress.org) ajoutant la protection
**Cloudflare Turnstile** aux formulaires. Inspiré de
`simple-cloudflare-turnstile` (Elliot Sowersby), mais avec **un différenciateur clé** :

- **Contrôle par formulaire dans Elementor** via un **champ « Cloudflare Turnstile »
  à glisser dans le module Forms**, exactement comme l'intégration reCAPTCHA v2
  native d'Elementor.
- L'utilisateur choisit **quels formulaires Elementor** sont protégés (tous, ou
  seulement certains) — fonctionnalité absente du plugin d'Elliot, qui applique
  Turnstile globalement à tous les formulaires Elementor.

## 2. Périmètre (v1.0)

### Intégrations formulaires
1. **Elementor Pro Forms** — champ Turnstile par formulaire (CŒUR DU PROJET).
2. **WordPress natif** (toggles individuels) :
   - Connexion (`wp-login.php`)
   - Inscription
   - Mot de passe oublié
   - Commentaires
3. **WooCommerce** (optionnel, si actif) : login / register / lost password.
   → Candidat à reporter en v1.1 pour réduire la surface v1. À débattre.

### Hors périmètre v1
- reCAPTCHA/hCaptcha (on ne fait QUE Turnstile).
- Autres plugins de formulaires (CF7, WPForms, Gravity) — v2 éventuelle.

## 3. Pré-requis & compatibilité

- WordPress ≥ 5.8, PHP ≥ 7.4 (testé 8.3).
- Elementor **Pro** requis pour le champ formulaire (le module Forms est Pro).
  Détection : si Elementor Pro absent → l'intégration Elementor ne se charge pas,
  les formulaires WP natifs restent fonctionnels.
- Aucune dépendance composer en runtime. Script externe unique : l'API officielle
  Cloudflare `https://challenges.cloudflare.com/turnstile/v0/api.js`.

## 4. Architecture & arborescence

Slug / text-domain : `turnstile-forms` (provisoire, à valider sur WP.org).
Namespace PHP : `TurnstileForms`. Préfixe constantes : `TURNSTILE_FORMS_`.
Option unique (array) : `turnstile_forms_settings`.

```
turnstile-forms/
├── turnstile-forms.php              # En-tête plugin + bootstrap + garde-fous
├── uninstall.php                    # delete_option au désinstall
├── readme.txt                       # WordPress.org
├── README.md                        # GitHub
├── LICENSE                          # GPLv2+
├── includes/
│   ├── class-plugin.php             # Singleton, orchestration, chargement
│   ├── class-settings.php           # Settings API, page admin, getters
│   ├── class-verifier.php           # Wrapper siteverify (wp_remote_post)
│   ├── class-widget-renderer.php    # HTML widget + enqueue api.js (mutualisé)
│   ├── elementor/
│   │   └── class-turnstile-field.php# Field_Base : le champ par formulaire
│   └── integrations/
│       ├── class-wp-login.php
│       ├── class-wp-register.php
│       ├── class-wp-lostpassword.php
│       ├── class-wp-comments.php
│       └── class-woocommerce.php    # (si retenu en v1)
├── assets/
│   ├── css/admin.css
│   └── js/turnstile.js              # rendu explicite + callbacks (optionnel)
└── languages/
    └── turnstile-forms.pot
```

## 5. Réglages (page admin)

Page sous **Réglages → Turnstile** (ou menu top-level). Settings API + nonce.

| Réglage | Type | Défaut | Notes |
|---|---|---|---|
| `site_key` | text | '' | Clé publique |
| `secret_key` | password | '' | Clé secrète (jamais exposée côté client) |
| `theme` | select auto/light/dark | auto | `data-theme` |
| `size` | select normal/flexible/compact | flexible | `data-size` |
| `appearance` | select always/execute/interaction-only | always | `data-appearance` |
| `language` | select auto + liste | auto | `data-language` |
| `error_message` | text | « Veuillez confirmer… » | Message d'échec |
| `protect_login` | checkbox | off | WP login |
| `protect_register` | checkbox | off | WP register |
| `protect_lostpassword` | checkbox | off | WP lost pw |
| `protect_comments` | checkbox | off | Commentaires |
| `protect_woocommerce` | checkbox | off | Si WooCommerce actif |
| `elementor_auto_all` | checkbox | off | **Optionnel** : auto-protéger TOUS les forms Elementor |
| `failure_mode` | select block/allow | block | Comportement si l'API Cloudflare est injoignable |

Validation/sanitisation : `sanitize_text_field`, `sanitize_key`, whitelist des
selects. Échappement à l'affichage (`esc_attr`, `esc_html`).

## 6. Intégration Elementor (cœur)

### 6.1 Enregistrement du champ
```php
add_action( 'elementor_pro/forms/fields/register', function ( $registrar ) {
    $registrar->register( new \TurnstileForms\Elementor\Turnstile_Field() );
} );
```
Chargé seulement si `did_action('elementor_pro/init')` / classe
`\ElementorPro\Modules\Forms\Fields\Field_Base` existe.

### 6.2 Classe `Turnstile_Field extends Field_Base`
- `get_type()` → `'turnstile'`
- `get_name()` → « Cloudflare Turnstile »
- `render( $item, $item_index, $form )` :
  - enqueue de l'API Cloudflare,
  - `echo` d'un `<div class="cf-turnstile" data-sitekey=… data-theme=… data-size=…>`,
  - si plusieurs widgets sur une page → rendu explicite via `turnstile.js`.
- `validation( $field, $record, $ajax_handler )` :
  - lit le token dans `$_POST['cf-turnstile-response']` (input caché injecté par
    le widget, sérialisé par l'AJAX Elementor),
  - appelle `Verifier::verify( $token, $remote_ip )`,
  - en cas d'échec : `$ajax_handler->add_error( $field['id'], $error_message )`.
- `update_controls( $widget )` : ajoute des contrôles par champ (theme, size)
  surchargant le global. (Optionnel v1 — fallback sur réglages globaux.)

**Granularité** : la validation ne s'exécute que pour les formulaires contenant
le champ → « certains formulaires » par construction. « Tous » = ajouter le champ
partout, OU activer `elementor_auto_all`.

### 6.3 Mode « tous les formulaires » (`elementor_auto_all`, optionnel)
- À l'activation : on injecte le widget avant le bouton submit de chaque form
  Elementor (hook `elementor_pro/forms/pre_render` ou filtre du HTML) ET on valide
  via `elementor_pro/forms/validation` pour TOUT formulaire.
- **Risque** : placement/style fragile, double-rendu si un champ est déjà présent
  (dédup nécessaire). → Marqué secondaire ; à challenger avec Codex (garder en v1
  ou repousser v1.1 ?).

### 6.4 Détail technique critique — récupération du token
Le widget Turnstile crée `<input type="hidden" name="cf-turnstile-response">`.
L'AJAX Elementor sérialise tous les inputs du `<form>`, donc le token arrive dans
`$_POST['cf-turnstile-response']`. Alternative envisagée : `data-response-field-name`
pour mapper sur `form_fields[<id>]` et lire `$field['value']` — plus « propre »
mais plus fragile (dépend du nom interne Elementor). **Choix par défaut : lire
`$_POST['cf-turnstile-response']`** (approche identique au reCAPTCHA natif).
Multi-formulaires sur une page : OK car chaque submit AJAX ne porte que ses inputs.

## 7. Vérification serveur (`class-verifier.php`)

```php
$response = wp_remote_post(
    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    [
        'timeout' => 10,
        'body'    => [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $remote_ip, // optionnel, derrière proxy → prudence
        ],
    ]
);
```
- Décodage JSON, retour `success` (bool) + `error-codes`.
- **Token vide** → rejet immédiat (pas d'appel réseau).
- **Erreur réseau / WP_Error** → comportement selon `failure_mode`
  (block = rejet sécurisé / allow = laisse passer).
- `remoteip` : récupéré via une fonction prudente (pas de confiance aveugle aux
  en-têtes proxy ; option pour `REMOTE_ADDR` strict). À débattre.
- Pas de log du token. Pas de cache (token usage unique).

## 8. WordPress natif (intégrations)

| Form | Rendu (action) | Validation (hook) |
|---|---|---|
| Login | `login_form` | `wp_authenticate_user` / `authenticate` (filtre) |
| Register | `register_form` | `registration_errors` |
| Lost pw | `lostpassword_form` | `lostpassword_post` |
| Comments | `comment_form_after_fields` | `preprocess_comment` |
| WooCommerce | hooks WC dédiés | hooks WC dédiés |

Chaque intégration : garde par toggle de réglage, rend le widget, lit
`$_POST['cf-turnstile-response']`, appelle `Verifier::verify`. Échec login →
`WP_Error`. Échec commentaire → `wp_die` avec message + retour.

## 9. Assets & rendu

- `api.js` chargé en `defer` async, enqueue conditionnel (seulement si un widget
  est rendu sur la page) pour ne pas alourdir tout le site.
- Rendu implicite par défaut (`class="cf-turnstile"`). Rendu explicite
  (`turnstile.js`) seulement si nécessaire (plusieurs widgets / re-render AJAX).
- Reset du widget après échec de soumission AJAX (Elementor) via callback JS.

## 10. Sécurité & conformité WordPress.org

- GPLv2+ ; en-têtes plugin complets ; `Requires at least`, `Requires PHP`.
- `ABSPATH` guard en tête de chaque fichier PHP.
- Toutes les sorties échappées ; toutes les entrées sanitizées ; nonces via
  Settings API ; capacités (`manage_options`) pour les réglages.
- `wp_remote_post` (pas de cURL direct). Pas de script tiers bundlé.
- Secret jamais envoyé au client ; jamais loggé.
- `uninstall.php` supprime l'option. Désinstallation propre.
- Préfixes uniques partout (pas de collision).
- i18n : `load_plugin_textdomain`, `.pot` fourni, toutes chaînes traduisibles.
- Pas d'« phone home », pas de tracking.
- Marque : « Cloudflare » et « Elementor » sont des marques tierces — mention
  « not affiliated » dans le readme ; nom du plugin à valider vs guidelines WP.org.

## 11. Comportements limites (à trancher avec Codex)

1. **Clés non configurées** → ne PAS bloquer les formulaires (sinon site cassé) ;
   ne pas rendre le widget ; afficher un admin notice. (Défaut proposé.)
2. **API Cloudflare injoignable** → `failure_mode` (block par défaut, sécurisé).
   Débat : défaut block vs allow pour éviter de casser les forms en cas de panne.
3. **Mode auto-all Elementor** : garder en v1 ou repousser ?
4. **WooCommerce** : v1 ou v1.1 ?
5. **`remoteip`** : envoyer ou non par défaut (vie privée / proxies) ?
6. **Champ Turnstile multiple dans un même form** : ignorer les doublons.

## 12. Tests & validation

- Lint PHP (`php -l`) sur tous les fichiers.
- (Si dispo) PHP_CodeSniffer avec `WordPress` standard.
- Scénarios manuels documentés (impossible sans WP installé ici) :
  form Elementor avec/sans champ, login WP, échec token, panne réseau.
- Vérif statique : grep des sorties non échappées, des entrées non sanitizées.

## 13. Découpage de livraison

1. Squelette plugin + bootstrap + réglages + Verifier.
2. Champ Elementor (render + validation) — différenciateur.
3. Intégrations WP natives.
4. Assets JS/CSS + rendu explicite/reset.
5. (Option) mode auto-all + WooCommerce.
6. readme.txt / README.md / .pot / LICENSE.
7. Re-validation Codex + lint.

## 14. Questions ouvertes pour Codex

- Approche token (`$_POST['cf-turnstile-response']` vs `data-response-field-name`)
  est-elle la plus robuste pour l'AJAX Elementor multi-forms ?
- `failure_mode` par défaut : block ou allow ?
- Mode auto-all : architecture d'injection la moins fragile ? v1 ou v1.1 ?
- Faut-il `update_controls` (réglages par champ) en v1 ou seulement global ?
- Périmètre v1 : inclure WooCommerce ?
- Nom/slug compatibles guidelines marques WordPress.org ?
