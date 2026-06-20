# Plan : protéger les formulaires WooCommerce avec Turnstile (v1.2.0)

> Plan validé par confrontation croisée Claude × Codex (consensus bilatéral, 2 rounds).
> Historique : `docs/archives/confront-codex-woocommerce-turnstile-2026-06-19-2308/`.

## Contexte

Le plugin **CWeb Form Protection with Turnstile for Elementor Forms** (namespace `CWebTS`)
ajoute un widget Cloudflare Turnstile à des formulaires et vérifie le jeton côté serveur.
Il protège déjà les formulaires WordPress natifs (login, inscription, mot de passe oublié,
commentaires) et les formulaires Elementor Pro.

Côté WooCommerce, **seul le mot de passe oublié** est couvert (correctif v1.0.1, hook
`woocommerce_lostpassword_form` greffé dans `WP_Lost_Password`). Tout le reste est sans
protection — notamment le **checkout**, cible n°1 des bots. À noter : activer le toggle
« login » existant ne protège PAS le login WooCommerce, car `WP_Login::authenticate` ne
s'active que sur les champs `log`/`pwd` de `wp-login.php`, alors que WooCommerce poste
`username`/`password`.

**Objectif** : protéger 4 formulaires WooCommerce standards — **checkout classique
(shortcode)**, **connexion WooCommerce**, **inscription**, **modification du compte** —
chacun via **sa propre case** dans les réglages, en réutilisant toute la mécanique existante.

## Approche

**4 nouvelles classes, une par formulaire**, dans `includes/integrations/`, étendant
`CWebTS\Integrations\Abstract_Integration` (comme `WP_Login`, `WP_Register`, etc.). Aucune
logique de vérification nouvelle : on réutilise `render_widget()`, `passes()`, `get_token()`,
`is_enabled()`. On branche les bons hooks WooCommerce et on adapte le mécanisme d'erreur
propre à chaque formulaire. Les classes restent **inertes si WooCommerce est absent** (leurs
hooks ne se déclenchent jamais) — pas de garde `function_exists('WC')`.

**Décision clé sur le message d'erreur** : côté WooCommerce, on ajoute le **message brut**
`$this->settings->get_error_message()` **sans** le préfixe `<strong>Error:</strong>` (que les
intégrations WP ajoutent). WooCommerce préfixe lui-même « Error: » au login et à l'inscription
→ rajouter le nôtre créerait un double préfixe. Pour edit-account et checkout, le message brut
est aussi cohérent avec le style de notices natif de WooCommerce.

### Tableau des 4 intégrations

| Classe (fichier) | Toggle | `action()` | Hook de RENDU (dans le form) | Hook de VALIDATION | Mécanisme d'erreur |
|---|---|---|---|---|---|
| `WC_Checkout` (`class-wc-checkout.php`) | `protect_wc_checkout` | `wc_checkout` | `woocommerce_review_order_before_submit` | `woocommerce_checkout_process` (action, 0 arg) | `wc_add_notice($msg,'error')` — msg brut |
| `WC_Login` (`class-wc-login.php`) | `protect_wc_login` | `wc_login` | `woocommerce_login_form` | `woocommerce_process_login_errors` (filtre, 3 args) | retourne `WP_Error` — msg brut |
| `WC_Register` (`class-wc-register.php`) | `protect_wc_register` | `wc_register` | `woocommerce_register_form` | `woocommerce_process_registration_errors` (filtre, 4 args) | retourne `WP_Error` — msg brut |
| `WC_Account` (`class-wc-account.php`) | `protect_wc_account` | `wc_account` | `woocommerce_edit_account_form` | `woocommerce_save_account_details_errors` (action, 2 args) | mute `WP_Error` — msg brut |

### Détails / justifications de chaque hook

- **Checkout — rendu sur `woocommerce_review_order_before_submit`** (juste au-dessus du bouton
  « Commander », dans `#payment`). **Révision post-test terrain** : le plan initial avait retenu
  `woocommerce_checkout_before_order_review` (hors fragment AJAX) pour la stabilité du jeton,
  mais le test sur un vrai site a montré que ce hook place le widget **tout en haut du
  récapitulatif « Votre commande », au-dessus des produits** — visuellement inacceptable et
  loin du bouton. On revient donc au placement conventionnel, près du bouton. `#payment` est
  rechargé par `WC_AJAX::update_order_review()` à chaque recalcul, donc le widget y est re-rendu
  par le helper front : **re-rendu immédiat sur l'événement `updated_checkout`** (ajouté dans
  `assets/js/turnstile.js`) qui ferme la fenêtre de jeton vide, avec le `MutationObserver`
  existant en filet. C'est la mitigation JS que Codex avait posée comme condition de ce
  placement. Le champ caché `cf-turnstile-response` reste sérialisé avec le POST checkout.
- **Checkout — validation sur `woocommerce_checkout_process`** (action sans argument, appelée
  dans `WC_Checkout::process_checkout()` avant création de commande). `wc_add_notice($msg,
  'error')` si `!passes()` interrompt la commande. Le jeton voyage dans le POST car
  `checkout.js` poste `form.checkout.serialize()`.
- **Login — `woocommerce_login_form` / `woocommerce_process_login_errors`** (filtre,
  `accepted_args=3` : `$validation_error, $username, $password`). Déclenché dans
  `WC_Form_Handler::process_login()` après le nonce `woocommerce-login`, avant `wp_signon()`.
  Scopé au seul POST de login WC. **Pas de double-validation** avec `WP_Login::authenticate`
  (qui s'auto-désactive faute de `log`/`pwd`). Couvre aussi le login « client de retour » du
  checkout (`form-login.php` appelle `woocommerce_login_form()`) — souhaité.
- **Inscription — `woocommerce_register_form` / `woocommerce_process_registration_errors`**
  (filtre, `accepted_args=4` : `$validation_error, $username, $password, $email`). On n'utilise
  PAS `woocommerce_register_post`, qui se déclenche dans `wc_create_new_customer()` — partagé
  avec la création de compte au checkout et des créations programmatiques — et bloquerait des
  flux sans widget. Conséquence assumée : **la création de compte pendant le checkout est
  protégée par `protect_wc_checkout`, pas par `protect_wc_register`.**
- **Modifier le compte — `woocommerce_edit_account_form` / `woocommerce_save_account_details_errors`**
  (action, `accepted_args=2`, transmise via `do_action_ref_array(array(&$errors,&$user))`). On
  mute `$errors` (`->add('cwebts_failed', $msg)`), pas de valeur de retour.

## Étapes d'implémentation

1. **Créer les 4 classes** (`includes/integrations/class-wc-*.php`), calquées sur
   `WP_Register`/`WP_Login`. Chacune : `toggle()`, `action()`, `register()` (add render hook +
   add validation hook avec le bon `accepted_args`), et la méthode `validate()` adaptée.
2. **Câbler dans `class-plugin.php`** : nouvelle méthode `register_woocommerce_integrations()`
   appelée depuis `init()`, instanciant les 4 classes (modèle `register_native_integrations()`).
3. **Réglages (`class-settings.php`)** :
   - `defaults()` : `protect_wc_checkout/login/register/account => 0`.
   - boucle de `sanitize()` (toggles binaires) : ajouter les 4 clés.
   - nouvelle **section « WooCommerce »** (`add_settings_section`) + 4 champs
     (`add_settings_field` + callbacks `field_protect_wc_*`). Modèle :
     `field_protect_elementor_all_forms`. **Libellés précis** :
     - Checkout : *« Checkout classique (shortcode) »* + description :
       *« Protège le formulaire de paiement classique (shortcode). Le bloc Checkout (Gutenberg
       / woocommerce/checkout) n'est pas couvert dans cette version. Requiert WooCommerce. »*
     - Login : *« Connexion WooCommerce »* (couvre aussi le login « client de retour » du checkout).
     - Inscription : *« Inscription WooCommerce »*.
     - Compte : *« Détails du compte WooCommerce »*.
4. **Tests** (`tests/run-tests.php` + `tests/bootstrap.php`) — voir section dédiée ci-dessous.
5. **`.pot`** : régénérer pour les nouvelles chaînes (libellés/descriptions). Les messages
   d'erreur réutilisent `get_error_message()` (chaîne existante).
6. **Doc + version** : bump `1.1.1 → 1.2.0` (entête plugin + constante `CWEBTS_VERSION`),
   `CHANGELOG.md`, `readme.txt` (changelog + section fonctionnalités + « Stable tag », avec la
   limite Checkout Block et les exclusions nommées). MAJ mémoire projet après déploiement.

## Tests

**Modèle existant** : les tests `WP_Comments` instancient l'intégration avec le toggle
**désactivé** (donc `register()` n'est pas appelé → pas de `add_action`), puis appellent
`validate()` directement après avoir posé `$_POST['cf-turnstile-response']` + une réponse
`siteverify` simulée.

**À ajouter dans `tests/bootstrap.php`** :
- stub **`wc_add_notice`** (capture des notices dans un tableau global inspectable).
- **capture de hooks** : stubs `add_action`/`add_filter` enregistrant dans un tableau global
  (actuellement absents) — nécessaire UNIQUEMENT si on teste le gating / l'enregistrement
  effectif des hooks (toggle on). Sans eux, instancier avec toggle on ferait un fatal.
- compléter le stub **`WP_Error`** (`get_error_code()`, `get_error_messages()`) si les
  assertions les utilisent.

**Cas à couvrir dans `tests/run-tests.php`** :
- `WC_Login::validate` → retourne un `WP_Error` portant le message brut quand le jeton échoue ;
  entrée inchangée quand il passe.
- `WC_Register::validate` (filtre 4 args) / `WC_Account::validate` (action 2 args, mute) →
  ajoutent `cwebts_failed` au `WP_Error` quand échec ; rien quand succès.
- `WC_Checkout::validate` → appelle `wc_add_notice($msg,'error')` quand échec ; aucun appel
  quand succès.
- **Séparation des contextes** : avec le filtre `cwebts_verify_action` forcé à `true`, vérifier
  que chaque classe porte la bonne `action()` et qu'un jeton d'un contexte n'est pas accepté
  dans un autre (`wc_register` vs `wc_checkout`).
- **Gating** : chaque classe n'enregistre ses hooks que si son toggle = 1 ET les clés sont
  configurées (nécessite la capture de hooks ci-dessus).
- **Non-régression « création de compte au checkout »** : `protect_wc_register=1` +
  `protect_wc_checkout=0` + `createaccount=1` ne doit PAS être bloqué (le filtre register ne se
  déclenche pas sur ce flux — c'est tout l'intérêt du changement de hook).

## Points de vigilance (pendant et après l'implémentation)

- **Vérification front-end du checkout (manuelle/navigateur)** — indispensable, les tests
  unitaires ne la couvrent pas : changer pays/livraison/paiement → le widget reste rendu ;
  cliquer rapidement après un recalcul ; confirmer `cf-turnstile-response` dans le POST ;
  forcer un échec Turnstile puis resoumettre. Le placement hors fragment réduit fortement le
  risque, mais on le prouve quand même.
- **Page « Mon compte »** : login + inscription côte à côte → 2 widgets indépendants (correct,
  Cloudflare gère plusieurs widgets).
- **Sans WooCommerce** : aucune fonction WC ne doit être appelée au chargement, uniquement dans
  les callbacks de hooks (qui ne se déclenchent pas si WC absent).

## Décisions explicitement écartées

- **`woocommerce_checkout_before_order_review` au checkout** (placement « stable hors fragment
  AJAX ») : retenu en théorie pour éviter le jeton vide, **écarté après test terrain** car il
  place le widget en haut du récapitulatif, loin du bouton. Remplacé par
  `woocommerce_review_order_before_submit` + re-rendu sur `updated_checkout`.
- **`woocommerce_register_post` pour l'inscription** : non scopé (partagé avec checkout +
  créations programmatiques). Remplacé par `woocommerce_process_registration_errors`.
- **Préfixe `<strong>Error:</strong>` côté WooCommerce** : double préfixe. → message brut.
- **Classe WooCommerce unique / un seul toggle** : l'utilisateur veut une case par formulaire,
  et `Abstract_Integration` est conçu « un toggle = une classe ».
- **Pay for order (`order-pay`) et Add payment method** : hors scope v1.2.0 (choix utilisateur
  « les standards »). Nommés comme exclus dans le readme/changelog. Hooks connus pour plus tard :
  rendu `woocommerce_pay_order_before_submit` / `woocommerce_add_payment_method_form_bottom`,
  validation `WC_Form_Handler::pay_action()` / filtre `woocommerce_add_payment_method_form_is_valid`.
- **Checkout Block (`woocommerce/checkout`, Gutenberg)** : hors scope (API Blocks/Store API
  différente). Signalé par libellé explicite + readme/changelog. Détection automatique
  (`has_block` + admin notice) = amélioration optionnelle ultérieure, non bloquante.
- **Retirer `Requires Plugins: elementor`** : décision produit plus large (le plugin est publié
  sur wp.org sous l'identité « …for Elementor Forms »). Hors v1.2.0 ; à remonter à Alexandre si
  WooCommerce devient un axe autonome.

## Déploiement (flux habituel, après re-validation)

1. `php tests/run-tests.php` → tout au vert, compteur augmenté.
2. Vérification front-end manuelle (ci-dessus).
3. Re-validation Codex du diff (selon le flux de travail établi).
4. Commit, push `main`, tag + release GitHub, déploiement SVN wp.org (trunk + tags/1.2.0 +
   « Stable tag »).
5. MAJ mémoire projet.
