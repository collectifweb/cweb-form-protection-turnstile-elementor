# Round 2 - Contre-reponse Codex

## Points resolus depuis le round precedent

### 1. Placement checkout

J'approuve le remplacement de `woocommerce_review_order_before_submit` par
`woocommerce_checkout_before_order_review`.

Cela correspond exactement a ma correction : le widget reste dans `form.checkout`, donc il
est bien serialise au submit, mais il est rendu avant `<div id="order_review">` et hors des
fragments remplaces par `update_order_review`. Dans la source WooCommerce locale verifiee
(10.6.1), les fragments AJAX concernent `.woocommerce-checkout-payment` et
`.woocommerce-checkout-review-order-table`; le hook choisi n'est pas dedans. C'est le bon
compromis pour v1.2.0 : stable, proche du recapitulatif, sans mitigation JS supplementaire.

### 2. Hook d'inscription WooCommerce

J'approuve le passage a `woocommerce_process_registration_errors` avec
`accepted_args = 4` et retour du `WP_Error`.

C'est bien le point dedie a `WC_Form_Handler::process_registration()`, donc au POST
`register` avec nonce `woocommerce-register`. Cela evite le defaut de
`woocommerce_register_post`, qui passe par `wc_create_new_customer()` et peut donc toucher
la creation de compte pendant checkout ou des creations programmatiques.

La consequence produit est correcte : la creation de compte pendant checkout est protegee
par `protect_wc_checkout`, pas par `protect_wc_register`.

### 3. Message brut sans prefixe `Error:`

J'approuve : les classes WooCommerce doivent ajouter seulement
`$this->settings->get_error_message()`, sans prefixe HTML.

Petit recadrage technique : dans la source WooCommerce locale verifiee, l'inscription finit
elle aussi par afficher le prefixe `Error:` via le traitement de `process_registration()`
(directement ou via le `catch`, selon le nombre d'erreurs). La nuance de Claude disant que
`process_registration()` ne prefixe pas comme `process_login()` n'est donc pas exacte pour
cette version. Cela ne change pas la decision : le message brut reste le bon choix partout
cote WooCommerce.

### 4. Libelles et exclusions

J'approuve les libelles ajustes :

- `Checkout classique (shortcode)` pour eviter toute promesse sur les Blocks.
- `Connexion WooCommerce` plutot que `Mon compte`, car le hook couvre aussi le login
  "client de retour" du checkout.
- Exclusions explicites de `order-pay`, `add-payment-method` et du Checkout Block.

Ces exclusions doivent apparaitre dans le libelle/description admin, le readme et le
changelog. Avec ce wording, le scope "4 formulaires" est honnete et ne promet pas une
protection globale de tous les paiements WooCommerce.

### 5. Tests renforces

J'approuve les tests supplementaires proposes : validation par classe, action Turnstile
verifiee avec `cwebts_verify_action = true`, non-regression sur creation de compte au
checkout, et protocole navigateur pour le checkout classique.

Recadrage important sur le bootstrap actuel : `add_action()` et `add_filter()` ne sont pas
encore stubbés dans `tests/bootstrap.php`. Les tests `WP_Comments` actuels n'ont pas prouve
l'enregistrement des hooks, car l'integration est instanciee avec son toggle desactive puis
`validate()` est appele directement. Si les nouveaux tests veulent verifier le gating et les
hooks enregistres, il faut ajouter des stubs/captures de hooks. Il faudra aussi completer le
stub `WP_Error` si les assertions utilisent `get_error_code()` ou `get_error_messages()`.

Ce n'est pas un desaccord sur le plan, seulement une correction d'implementation des tests.

## Points encore en debat

### A. Detection automatique du Checkout Block

Je ne considere pas la detection `has_block( 'woocommerce/checkout', ... )` + admin notice
comme bloquante pour le consensus v1.2.0.

Elle reste une bonne amelioration UX ulterieure, surtout pour reduire les mauvaises
configurations. Mais pour cette release, un libelle admin explicite, une description claire
et une mention readme/changelog suffisent a eviter une promesse trompeuse. Je l'accepte donc
comme optionnelle, hors v1.2.0.

### B. `Requires Plugins: elementor`

Acceptable de ne pas l'agir en v1.2.0.

Le point doit etre remonte a l'utilisateur comme decision produit : si WooCommerce devient un
axe autonome, garder une dependance formelle a Elementor limitera l'usage du plugin. Mais ce
n'est pas necessaire pour livrer correctement les quatre integrations WooCommerce demandees.

### C. Signatures et mecanismes d'erreur

Je ne vois plus d'erreur technique dans le plan ajuste :

- `woocommerce_process_login_errors` : filtre, 3 arguments, retourne `WP_Error`.
- `woocommerce_process_registration_errors` : filtre, 4 arguments, retourne `WP_Error`.
- `woocommerce_save_account_details_errors` : action, 2 arguments via `do_action_ref_array`,
  mute le `WP_Error`.
- `woocommerce_checkout_process` : action sans argument, erreur via `wc_add_notice( $msg, 'error' )`.
- Rendus : les quatre hooks de rendu sont dans les formulaires classiques vises.

Le choix de laisser les classes inertes sans WooCommerce reste acceptable : les hooks ne
s'executeront pas si WooCommerce est absent, et aucune dependance WooCommerce ne doit etre
appelee au chargement hors des callbacks concernes.

## Evaluation globale

Il ne reste pas de desaccord substantiel. Les deux corrections majeures ont bien ete
integrees, les libelles et exclusions cadrent correctement le produit, et les tests prevus
couvrent maintenant les risques importants.

Le plan est pret pour implementation v1.2.0, avec une seule vigilance pratique : completer
les stubs du runner de tests avant d'asserter l'enregistrement effectif des hooks.

CONSENSUS_ATTEINT
