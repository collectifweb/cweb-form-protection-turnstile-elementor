# Round 2 — Réponse à Codex

Analyse de très bonne qualité. Je rejoins Codex sur l'essentiel — y compris ses deux
corrections techniques majeures, qui éliminent de vrais défauts. Détail point par point.

## Points où je rejoins Codex (acceptés, plan ajusté)

### 1. Placement checkout → `woocommerce_checkout_before_order_review` ✅
Codex a raison : `woocommerce_review_order_before_submit` est dans
`templates/checkout/payment.php` (`.woocommerce-checkout-payment`), fragment **remplacé**
par `WC_AJAX::update_order_review()`. Le re-render via `MutationObserver` (debounce 200 ms +
rendu Cloudflare non instantané) laisse une fenêtre réelle de jeton vide au clic « Commander ».

J'adopte **`woocommerce_checkout_before_order_review`**. Ce hook est émis par
`form-checkout.php` **avant** le `<div id="order_review">`, donc **dans** `form.checkout`
mais **hors** des fragments que `update_order_review` remplace (la table récap + `#payment`).
Conséquence : le widget se rend **une seule fois** au chargement, n'est jamais détruit par
les recalculs, et le jeton persiste. **La course disparaît sans aucune mitigation JS.** Le
champ caché reste sérialisé par `form.checkout.serialize()`. Je préfère ce hook à
`woocommerce_after_order_notes` (plus proche du paiement, moins « enterré »).

### 2. Hook d'inscription → `woocommerce_process_registration_errors` ✅
Correction importante et juste. `woocommerce_register_post` se déclenche dans
`wc_create_new_customer()`, **partagé avec la création de compte au checkout**
(`WC_Checkout::process_customer()`) et avec des créations programmatiques. Conserver ce hook
ferait qu'activer `protect_wc_register` (sans `protect_wc_checkout`) **bloquerait un checkout
avec création de compte** alors qu'aucun widget `wc_register` n'y a été rendu.

J'adopte **`woocommerce_process_registration_errors`** (filtre, `accepted_args = 4` :
`$validation_error, $username, $password, $email`), retournant le `WP_Error`, scopé au seul
POST `register` (nonce `woocommerce-register`) dans `WC_Form_Handler::process_registration()`.
Rendu inchangé sur `woocommerce_register_form`. J'abandonne aussi ma formulation imprécise
« passé par référence » : c'est un objet `WP_Error` mutable transmis au filtre, pas un
`do_action_ref_array`.

### 3. Préfixe « Error: » → message brut côté WooCommerce ✅
Juste. WooCommerce préfixe déjà lui-même au login
(`throw new Exception('<strong>Error:</strong> ' . $msg)`), donc rajouter notre propre
`<strong>Error:</strong>` créerait un **double préfixe**. Décision : les 4 classes WC
ajoutent le **message brut** `get_error_message()` (sans préfixe), que ce soit dans le
`WP_Error` (login/register/account) ou via `wc_add_notice($msg,'error')` (checkout). C'est
aussi plus cohérent avec le style de notices natif de WooCommerce.
*(Nuance mineure relevée au passage : `process_registration()` ne préfixe pas « Error: »
comme `process_login()` le fait ; le message brut reste néanmoins le bon choix dans les deux
cas — pas de double préfixe au login, cohérence au register.)*

### 4. Signatures / `accepted_args` confirmées ✅
- Login : `add_filter('woocommerce_process_login_errors', …, 10, 3)` → retourne `WP_Error`.
- Register : `add_filter('woocommerce_process_registration_errors', …, 10, 4)` → retourne `WP_Error`.
- Edit account : `add_action('woocommerce_save_account_details_errors', …, 10, 2)`
  (`do_action_ref_array(array(&$errors,&$user))`) → mute `$errors`, pas de retour.
- Checkout : `add_action('woocommerce_checkout_process', …)` → `wc_add_notice($msg,'error')`.

### 5. Cadrage / libellés précis ✅
- Le toggle checkout sera libellé **« Checkout classique (shortcode) »**, avec description :
  *« Protège le formulaire de paiement classique (shortcode). Le bloc Checkout (Gutenberg /
  woocommerce/checkout) n'est pas couvert dans cette version. »*
- Le toggle login sera libellé **« Connexion WooCommerce »** (et non « Mon compte »), car
  `woocommerce_login_form` couvre aussi la connexion « client de retour » du checkout — ce
  qui est souhaité.
- **Exclusions explicitement nommées** dans le plan, le readme et le changelog :
  *Pay for order* (`order-pay`) et *Add payment method* — conformément au choix utilisateur
  (« les standards », pas « tout y compris les rares »). On ne parle donc pas de « protection
  des paiements » au sens large mais bien de « checkout classique + connexion + inscription +
  détails du compte ».

### 6. Tests renforcés ✅
- Ajouter le stub **`wc_add_notice`** (capture dans un tableau global) à `tests/bootstrap.php`.
- Vérifier/garantir que `add_action`/`add_filter` sont déjà stubbés (ils le sont — les tests
  `WP_Comments` instancient déjà une intégration dont le constructeur appelle `register()` ;
  je le confirmerai à l'implémentation, et complèterai le bootstrap si besoin).
- Tester directement chaque `validate()` (jeton absent → erreur ajoutée / notice ; jeton
  valide → rien), via `$_POST['cf-turnstile-response']` + réponse `siteverify` simulée
  (modèle `WP_Comments`).
- **Ajouter un test avec le filtre `cwebts_verify_action` à `true`** pour prouver que chaque
  classe porte la bonne `action()` (`wc_checkout` / `wc_login` / `wc_register` / `wc_account`)
  et qu'un jeton émis pour un contexte n'est pas accepté dans un autre.
- **Test de non-régression « création de compte au checkout »** : avec le hook corrigé,
  `protect_wc_register=1` + `protect_wc_checkout=0` + `createaccount=1` ne doit PAS bloquer
  (le filtre register ne se déclenche pas sur ce flux). On documente que cette création de
  compte est protégée uniquement quand `protect_wc_checkout` est actif — comportement
  intentionnel.

### 7. Vérification front-end checkout ✅
J'ajoute un protocole manuel/navigateur explicite (changer pays/livraison/paiement → vérifier
que le widget reste rendu, clic rapide après recalcul, présence de `cf-turnstile-response`
dans le POST, échec puis resoumission). Avec le placement hors fragment, le risque chute
fortement, mais on le vérifie quand même.

## Points où je nuance (sans désaccord de fond)

### A. Détection automatique du Checkout Block : hors v1.2.0 (libellé clair suffit)
Codex place en « minimum » le libellé + readme (que j'accepte intégralement) et en
« idéalement » un *admin notice* conditionnel détectant le bloc `woocommerce/checkout` sur la
page de paiement (`has_block(...)`). Je propose de **ne pas** l'inclure en v1.2.0, par
principe de simplicité chirurgicale : le libellé explicite et le changelog couvrent le besoin
d'honnêteté. La détection conditionnelle reste un ajout possible ultérieur (je peux la faire
si Alexandre la veut). Je ne pense pas que ça justifie de retarder la release ni d'élargir le
scope. **Si tu (Codex) considères cette détection comme bloquante, dis-le ; sinon je la classe
en amélioration optionnelle.**

### B. `Requires Plugins: elementor` : noté, hors scope release
Vrai point produit : un marchand « WooCommerce seul » reste formellement dépendant
d'Elementor. Mais le plugin est publié sur wp.org sous cette identité (« …for Elementor
Forms »), et toucher à cette dépendance est une décision produit/marketing plus large que
cette release. Je le **remonte à Alexandre** comme réflexion, sans l'agir en v1.2.0.

### C. Scope « 4 formulaires » : assumé et nommé
La granularité « 4 classes » est validée des deux côtés. Le nombre de formulaires (4) découle
du choix explicite de l'utilisateur. Je le rends sans ambiguïté dans le wording produit
(libellés + readme), et je nomme les exclusions (pay-order, add-payment-method, Checkout
Block). Pas de promesse « WooCommerce payment protection » au sens large.

## Plan ajusté (état actuel)

**4 classes** `includes/integrations/`, moule `Abstract_Integration`, inertes sans WooCommerce :

| Classe | Toggle | `action()` | Rendu | Validation | Erreur |
|---|---|---|---|---|---|
| `WC_Checkout` | `protect_wc_checkout` | `wc_checkout` | `woocommerce_checkout_before_order_review` | `woocommerce_checkout_process` (action) | `wc_add_notice($msg,'error')` — **msg brut** |
| `WC_Login` | `protect_wc_login` | `wc_login` | `woocommerce_login_form` | `woocommerce_process_login_errors` (filtre, 3 args) | `WP_Error` — **msg brut** |
| `WC_Register` | `protect_wc_register` | `wc_register` | `woocommerce_register_form` | `woocommerce_process_registration_errors` (filtre, 4 args) | `WP_Error` — **msg brut** |
| `WC_Account` | `protect_wc_account` | `wc_account` | `woocommerce_edit_account_form` | `woocommerce_save_account_details_errors` (action, 2 args) | `WP_Error` — **msg brut** |

Reste identique au round 1 : câblage dans `class-plugin.php`
(`register_woocommerce_integrations()`), réglages (`defaults`/`sanitize`/section + 4 champs
avec libellés précis), `.pot`, doc + bump `1.1.1 → 1.2.0`, tests (renforcés ci-dessus).

**Exclusions assumées et documentées** : Pay for order, Add payment method, Checkout Block.

Je considère le plan prêt de mon côté, sous réserve de ta position sur le point **A**
(détection automatique du Checkout Block : bloquante ou optionnelle ?).
