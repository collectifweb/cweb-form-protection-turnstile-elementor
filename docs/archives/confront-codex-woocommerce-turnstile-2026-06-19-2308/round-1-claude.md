# Round 1 — Plan : protéger les formulaires WooCommerce avec Turnstile (v1.2.0)

## 1. Contexte

Plugin WordPress **CWeb Form Protection with Turnstile for Elementor Forms**
(namespace `CWebTS`, slug `cweb-form-protection-turnstile-elementor`). Il ajoute un widget
Cloudflare Turnstile à des formulaires et vérifie le jeton côté serveur via l'endpoint
`siteverify`.

### Architecture existante (vérifiée en lisant le code)

- **Autoload PSR-4-like** : `CWebTS\Sub\Some_Class` → `includes/sub/class-some-class.php`
  (`cweb-form-protection-turnstile-elementor.php`).
- **`CWebTS\Plugin`** (`includes/class-plugin.php`) construit le graphe de services
  (`Settings`, `Verifier`, `Widget_Renderer`) et instancie les intégrations dans
  `init()` via `register_native_integrations()` (login, register, lost-password, comments).
- **`Abstract_Integration`** (`includes/integrations/class-abstract-integration.php`) :
  classe de base. Le constructeur appelle `register()` **uniquement si** `is_enabled()`
  (= `1 === (int) get(toggle())` **ET** `is_configured()`). Fournit, réutilisables :
  - `render_widget()` → `renderer->enqueue()` puis `renderer->render(['action' => action()])`
  - `get_token()` → lit/sanitise `$_POST['cf-turnstile-response']`
  - `passes()` → `verifier->verify(get_token(), null, action())`
  - `is_post()`, `is_enabled()`
- **Intégrations natives existantes**, modèle de référence :
  - `WP_Login` : `toggle()='protect_login'`, rend sur `login_form`, valide sur le filtre
    `authenticate` (priorité 30). **Garde importante** : `authenticate()` retourne tôt si
    `XMLRPC_REQUEST`/`REST_REQUEST`, si non-POST, ou si `empty($_POST['log']) &&
    empty($_POST['pwd'])`. → WooCommerce poste `username`/`password`, donc cette garde
    **désactive** la validation pour le login WC (pas de blocage, mais pas de protection
    non plus).
  - `WP_Register` : rend sur `register_form`, valide sur le filtre `registration_errors`
    (`$errors->add('cwebts_failed', ...)`).
  - `WP_Lost_Password` : rend sur `lostpassword_form` **et** `woocommerce_lostpassword_form`
    (seul point WC couvert aujourd'hui, ajouté en v1.0.1) ; valide sur `lostpassword_post`.
  - `WP_Comments` : rend sur `comment_form_after_fields` / `comment_form_logged_in_after`,
    valide sur `preprocess_comment` (avec bypass admin AJAX). Couvre aussi les **avis
    produits WooCommerce** (ce sont des commentaires).
- **`Elementor_All_Forms`** : intégration optionnelle « protéger tous les forms Elementor »,
  injecte le widget côté serveur dans le HTML du form (`elementor/widget/render_content`)
  et reste **inerte si Elementor Pro absent**. Modèle pour « rester inerte sans la dépendance ».
- **`Widget_Renderer`** : `get_html()` produit `<div class="cf-turnstile cwebts-widget"
  data-sitekey=… data-theme=… …></div>` ; `enqueue()` charge `assets/js/turnstile.js`
  (helper) + l'API Cloudflare en `render=explicit&onload=cwebtsOnload`, en `defer`.
- **`assets/js/turnstile.js`** : rend tous les `.cf-turnstile:not([data-tf-rendered])`,
  plus un **`MutationObserver`** sur `document.body` (debounce 200 ms) qui re-rend les
  widgets injectés après coup (popups, AJAX). Couvre donc nativement les fragments
  rechargés en AJAX.
- **`Settings`** (`includes/class-settings.php`) : option unique `cwebts_settings`.
  `defaults()` liste les toggles (`protect_login`, `protect_register`, `protect_lostpassword`,
  `protect_comments`, `protect_elementor_all_forms`, …). `sanitize()` force chaque toggle à
  0/1 dans une boucle. Champs admin via `add_settings_field` + callbacks `field_*`.
- **Tests** : `tests/run-tests.php` (runner maison, 73 tests) + `tests/bootstrap.php`
  (stubs WP). Pas de PHPUnit. Lancement : `php tests/run-tests.php`.

### État WooCommerce actuel

Seul le **mot de passe oublié** WC est protégé (via `WP_Lost_Password`). Manquent :
**checkout, connexion « Mon compte », inscription « Mon compte », modifier le compte**
(+ avis produits, mais ceux-là passent déjà par `protect_comments`).

### Objectif

Protéger les 4 formulaires WooCommerce standards — **paiement, connexion, inscription,
modifier le compte** — chacun avec **sa propre case** dans les réglages (choix utilisateur
explicite), en réutilisant toute la mécanique existante. Pas de mot de passe oublié (déjà
fait), pas d'avis produits (déjà faits via les commentaires).

## 2. Approche proposée

**4 nouvelles classes, une par formulaire**, sur le moule `Abstract_Integration` —
exactement comme `WP_Login`/`WP_Register`. Aucune logique de vérification nouvelle : on
réutilise `render_widget()`, `passes()`, `get_token()`, `is_enabled()`. On ne fait que
brancher les bons hooks WooCommerce et adapter le **mécanisme de remontée d'erreur** propre
à chaque formulaire.

| Classe (fichier `includes/integrations/`) | Toggle | `action()` | Hook RENDU | Hook VALIDATION | Erreur |
|---|---|---|---|---|---|
| `WC_Checkout` (`class-wc-checkout.php`) | `protect_wc_checkout` | `wc_checkout` | `woocommerce_review_order_before_submit` | `woocommerce_checkout_process` (action) | `wc_add_notice($msg,'error')` |
| `WC_Login` (`class-wc-login.php`) | `protect_wc_login` | `wc_login` | `woocommerce_login_form` | `woocommerce_process_login_errors` (filtre) | retourne `WP_Error` |
| `WC_Register` (`class-wc-register.php`) | `protect_wc_register` | `wc_register` | `woocommerce_register_form` | `woocommerce_register_post` (action, `$errors` réf.) | `$errors->add(...)` |
| `WC_Account` (`class-wc-account.php`) | `protect_wc_account` | `wc_account` | `woocommerce_edit_account_form` | `woocommerce_save_account_details_errors` (action, `$errors` réf.) | `$errors->add(...)` |

Toutes restent **inertes si WooCommerce est absent** : leurs hooks ne se déclenchent jamais.
Pas de garde `function_exists('WC')` (comme `Elementor_All_Forms` sans Elementor Pro).

### Détails de validation par formulaire

- **Checkout** : `woocommerce_checkout_process` est une action sans argument déclenchée
  pendant `WC_Checkout::process_checkout()`. On y appelle `wc_add_notice($msg,'error')` si
  `! passes()`. WooCommerce agrège les notices et avorte la commande (en AJAX, les notices
  sont renvoyées comme fragment et réaffichées). Le jeton voyage dans le POST car le JS de
  checkout sérialise tout `form.checkout` (le champ caché `cf-turnstile-response` est inclus).
- **Login** : filtre `woocommerce_process_login_errors($validation_error, $username,
  $password)` déclenché dans `WC_Form_Handler::process_login()` **après** vérification du
  nonce `woocommerce-login-nonce`, **avant** `wp_signon()`. On retourne un `WP_Error` (avec
  notre message) si `! passes()`. Scope : uniquement le POST de login WC. Pas de conflit
  avec `WP_Login::authenticate` (qui s'auto-désactive faute de `log`/`pwd`).
- **Register** : action `woocommerce_register_post($username, $email, $validation_errors)`,
  où `$validation_errors` est un `WP_Error` passé par référence. On y ajoute l'erreur.
- **Edit account** : action `woocommerce_save_account_details_errors($errors, $user)`,
  `$errors` est un `WP_Error` par référence. On y ajoute l'erreur.

## 3. Étapes d'implémentation

1. **Créer les 4 classes** dans `includes/integrations/`, calquées sur `WP_Register`/`WP_Login`.
   Chacune : `toggle()`, `action()`, `register()` (add render hook + add validation hook),
   et une méthode de validation adaptée au mécanisme d'erreur du formulaire.
2. **Câbler dans `class-plugin.php`** : nouvelle méthode `register_woocommerce_integrations()`
   appelée depuis `init()`, instanciant les 4 classes (comme `register_native_integrations()`).
3. **Réglages (`class-settings.php`)** :
   - `defaults()` : `protect_wc_checkout/login/register/account => 0`.
   - boucle `sanitize()` : ajouter les 4 clés.
   - nouvelle **section « WooCommerce »** (`add_settings_section`) + 4 champs
     (`add_settings_field` + callbacks `field_protect_wc_*`), avec description « Requiert
     WooCommerce ». Modèle : `field_protect_elementor_all_forms`.
4. **Tests** (`tests/run-tests.php` + stub `wc_add_notice` dans `tests/bootstrap.php`) :
   - `WC_Login::validate` → renvoie `WP_Error` portant le message quand le jeton échoue ;
     entrée inchangée quand il passe.
   - `WC_Register::validate` / `WC_Account::validate` → ajoutent `cwebts_failed` au `WP_Error`
     quand échec ; rien quand succès.
   - `WC_Checkout::validate` → appelle `wc_add_notice($msg,'error')` quand échec ; pas d'appel
     quand succès.
   - Gating : chaque classe ne s'active que si son toggle = 1 et clés configurées.
   Modèle : tests `WP_Comments` (set `$_POST['cf-turnstile-response']` + réponse `siteverify`
   simulée).
5. **`.pot`** : régénérer pour les nouvelles chaînes (libellés/descriptions de champs).
   Les messages d'erreur réutilisent `get_error_message()` + la chaîne « Error: » existantes.
6. **Doc + version** : bump `1.1.1 → 1.2.0` (entête plugin + `CWEBTS_VERSION`),
   `CHANGELOG.md`, `readme.txt` (changelog + features + Stable tag). MAJ mémoire après déploiement.

## 4. Points sensibles (zones d'incertitude — à challenger)

1. **Placement du widget au checkout.** `woocommerce_review_order_before_submit` est dans
   le bloc `#payment` que WooCommerce recharge en AJAX (`update_order_review`) à chaque
   changement de livraison/paiement/pays. Le widget y est détruit puis recréé →
   re-rendu par le `MutationObserver`. En mode « managed » (défaut, souvent invisible) c'est
   transparent, MAIS :
   - **Risque de course** : si `update_order_review` se déclenche juste au moment du clic
     « Commander », le widget peut être en cours de re-render → jeton momentanément vide →
     `woocommerce_checkout_process` rejette à tort.
   - **Alternative** : `woocommerce_after_order_notes` (dans la colonne détails client, hors
     zone rechargée) → jeton stable, mais widget plus loin du bouton (UX moins évidente, et
     champ caché malgré tout sérialisé donc fonctionnel).
   - Recommandation actuelle : garder `woocommerce_review_order_before_submit` (standard, ce
     que font les plugins Turnstile connus). **À trancher avec Codex.**
2. **Login WC : double-vérif ?** On valide via `woocommerce_process_login_errors`. Mais
   `wp_signon()` (appelé par WC après) déclenche `authenticate`, où `WP_Login` est branché
   si `protect_login` est activé. Sa garde `empty(log)&&empty(pwd)` devrait l'auto-désactiver
   sur le POST WC (champs `username`/`password`). À confirmer qu'il n'y a vraiment aucun cas
   où les deux se déclenchent et produisent une double erreur / un blocage involontaire.
3. **Token vide vs challenge non résolu.** `passes()` → `verify()` retourne `false` pour un
   jeton vide (rejet sans appel réseau). Donc un formulaire soumis sans widget résolu est
   bloqué. OK voulu. Mais sur les formulaires **non-AJAX** (login/register/account), si le
   widget n'a pas eu le temps de se rendre (JS lent), l'utilisateur pourrait être bloqué.
   Risque faible (rendu explicite + onload), mais à noter.
4. **Mot de passe oublié laissé dans `WP_Lost_Password`.** Rétro-compatibilité : un user qui
   a déjà activé `protect_lostpassword` garde la couverture WC. Mais ça crée une UX « éclatée »
   (un réglage à part). On documente. Pas de migration. **À challenger : faut-il regrouper ?**
5. **Double widget possible ?** Page « Mon compte » : login + register côte à côte → 2 widgets
   indépendants (correct, Cloudflare gère plusieurs widgets). Checkout : un seul widget par
   fragment. À confirmer qu'aucun cumul anormal n'apparaît (ex. plusieurs hooks tirant le même
   rendu).
6. **`woocommerce_checkout_process` et les paiements AJAX/blocks.** Le nouveau **Checkout
   Block** (Gutenberg/React, `wc/checkout`) **n'émet pas** `woocommerce_checkout_process` ni
   `woocommerce_review_order_before_submit` — ces hooks sont propres au **checkout “shortcode”
   classique**. Le plan ne couvre donc PAS le Checkout Block. **Point important à expliciter** :
   accepte-t-on de ne couvrir que le checkout classique en v1.2.0 ? (couvrir les Blocks
   demande l'API `StoreApi`/`IntegrationInterface`, hors scope raisonnable ici.)

## 5. Alternatives écartées

- **Classe WooCommerce unique avec un seul toggle** (façon `Elementor_All_Forms`) : écartée
  car l'utilisateur veut une case par formulaire, et `Abstract_Integration` est conçu autour
  d'« un toggle = une classe ». 4 petites classes collent mieux au pattern.
- **Étendre `WP_Login`/`WP_Register` en y greffant les hooks WC** (comme `WP_Lost_Password`
  l'a fait) : écartée car les formulaires WC ont des hooks de validation et des mécanismes
  d'erreur **différents** (filtre vs action, `WP_Error` vs `wc_add_notice`), et un toggle
  distinct est voulu. Greffer alourdirait des classes au scope clair.
- **Injection serveur façon `Elementor_All_Forms`** (parser le HTML pour insérer le widget) :
  inutile ici — WooCommerce expose des hooks d'action **à l'intérieur** de chaque form, donc
  un simple `echo` du widget suffit. Pas besoin de manipuler le HTML.
- **Couvrir le Checkout Block en v1.2.0** : écartée pour limiter le scope (API différente).
  À documenter comme limite connue.

## Questions explicites pour Codex

1. Checkout : `woocommerce_review_order_before_submit` (près du bouton, zone AJAX) vs
   `woocommerce_after_order_notes` (stable) — lequel, et pourquoi ?
2. Confirmes-tu qu'il n'y a pas de double-validation problématique login WC (`authenticate`
   vs `woocommerce_process_login_errors`) ? Vois-tu un meilleur point de validation pour le
   login WC ?
3. Les hooks de validation register/account (`woocommerce_register_post`,
   `woocommerce_save_account_details_errors`) sont-ils les bons, avec les bonnes signatures ?
4. L'approche « 4 classes » est-elle la bonne granularité, ou y a-t-il mieux ?
5. Manque-t-il un formulaire WC « standard » important que je n'ai pas listé (ex. *add payment
   method*) ? Le scope « 4 forms + pas de Checkout Block » est-il acceptable pour une v1.2.0 ?
