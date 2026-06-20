# Round 1 - Analyse Codex

## 1. Ce que j'approuve

### Architecture generale

J'approuve l'idee de reutiliser `Abstract_Integration`. Le moule existant est bien adapte : un toggle, une action Turnstile, un rendu, une validation. Le constructeur gate deja sur `is_enabled()` (`toggle = 1` et cles configurees), `render_widget()` enfile le renderer, `get_token()` lit `cf-turnstile-response`, et `passes()` delegue au `Verifier`.

J'approuve aussi la granularite "petites classes par formulaire" plutot qu'une grosse classe WooCommerce. Les hooks, les formats d'erreur et les contextes ne sont pas les memes entre checkout, login, register et account. Quatre classes courtes sont plus testables et collent au style actuel (`WP_Login`, `WP_Register`, `WP_Lost_Password`, `WP_Comments`).

### Login WooCommerce

Le filtre `woocommerce_process_login_errors` est le bon point de validation pour le login WooCommerce classique. Dans la copie WooCommerce inspectee, il est appele dans `WC_Form_Handler::process_login()` apres verification du nonce `woocommerce-login`, avant `wp_signon()`, avec la signature :

```php
apply_filters( 'woocommerce_process_login_errors', $validation_error, $creds['user_login'], $creds['user_password'] );
```

Il faut donc brancher avec `accepted_args = 3` et retourner un `WP_Error`.

Je ne vois pas de double-verification problematique avec `WP_Login::authenticate` sur le formulaire WooCommerce standard. `WP_Login` ne s'applique que si le POST contient `log` ou `pwd`; le formulaire WooCommerce poste `username` et `password`. Ensuite `wp_signon()` passe bien par `authenticate`, mais la garde de `WP_Login` retourne tot. Pour provoquer une double validation, il faudrait un theme/plugin qui ajoute aussi des champs POST `log`/`pwd` au login WooCommerce. Ce n'est pas le comportement standard.

### Edit account

Le rendu sur `woocommerce_edit_account_form` est correct : ce hook est dans le `<form>` WooCommerce, avant nonce et bouton. La validation sur `woocommerce_save_account_details_errors` est aussi correcte. WooCommerce l'appelle avec :

```php
do_action_ref_array( 'woocommerce_save_account_details_errors', array( &$errors, &$user ) );
```

Il faut donc `add_action(..., 10, 2)`, muter le `WP_Error`, et ne pas attendre de valeur de retour.

### Checkout classique : validation serveur

`woocommerce_checkout_process` est un bon point de validation serveur pour le checkout shortcode/classique. Il est appele dans `WC_Checkout::process_checkout()` avant la creation de commande, et `wc_add_notice( $message, 'error' )` empeche la suite via le compteur de notices WooCommerce.

Le plan a aussi raison sur la serialisation principale du checkout : `checkout.js` poste `form.checkout.serialize()` vers `wc_checkout_params.checkout_url`. Si le widget est dans le formulaire et que le champ cache Turnstile existe au moment du submit, le token voyage bien dans le POST.

### Checkout Block hors scope

Exclure le Checkout Block en v1.2.0 est acceptable techniquement, a condition de le dire clairement. Les hooks proposes sont ceux du checkout shortcode/classique. Couvrir `wc/checkout` demanderait une integration Blocks/Store API differente ; ce n'est pas une extension naturelle des classes actuelles.

## 2. Ce que je desapprouve

### Je ne choisirais pas `woocommerce_review_order_before_submit` par defaut

Je desapprouve la recommandation actuelle de garder `woocommerce_review_order_before_submit` comme placement par defaut.

Ce hook est bien place juste avant le bouton, mais il est dans `templates/checkout/payment.php`, donc dans `.woocommerce-checkout-payment`. Or `WC_AJAX::update_order_review()` remplace le fragment `.woocommerce-checkout-payment` apres chaque refresh checkout. Le helper `assets/js/turnstile.js` sauve les widgets inseres tardivement via `MutationObserver`, mais avec un rescan debounce de 200 ms, puis un rendu Cloudflare qui n'est pas instantane.

Le risque de token vide est donc reel :

- le fragment payment peut etre remplace ;
- le nouveau `<div class="cf-turnstile">` peut exister sans champ cache `cf-turnstile-response` encore rendu ;
- l'utilisateur peut cliquer "Commander" dans cette fenetre ;
- `form.checkout.serialize()` part alors sans token, et `woocommerce_checkout_process` rejette.

Le blocage UI de WooCommerce pendant `update_order_review` reduit ce risque, mais ne l'annule pas. Le submit ne synchronise pas explicitement avec l'etat du widget Turnstile.

Pour v1.2.0, je recommande un placement stable hors fragment AJAX : `woocommerce_after_order_notes` parmi les deux options discutees, ou mieux `woocommerce_checkout_before_order_review` si on veut rester proche du recapitulatif sans etre dans `.woocommerce-checkout-payment`. Si on veut absolument garder le widget avant le bouton, il faut ajouter une mitigation JS explicite : reset/rendu immediat sur `updated_checkout`, desactivation du bouton tant que le widget n'a pas produit de token, et test navigateur sur changement de livraison/paiement + clic rapide.

### `woocommerce_register_post` n'est pas le bon hook pour "inscription Mon compte"

Je desapprouve le hook de validation propose pour `WC_Register`.

`woocommerce_register_post` n'est pas limite au formulaire "Mon compte > Register". Il est declenche dans `wc_create_new_customer()`. Cette fonction est aussi appelee pendant le checkout quand un visiteur cree un compte (`WC_Checkout::process_customer()`).

Consequence possible :

- `protect_wc_register` active, `protect_wc_checkout` desactive : un checkout avec creation de compte peut etre bloque alors qu'aucun widget `wc_register` n'a ete rendu dans le checkout ;
- `protect_wc_register` et `protect_wc_checkout` actifs : la validation register peut reutiliser le token checkout. Aujourd'hui ca passe probablement parce que la verification locale de `action` est off par defaut, mais ca casse si le filtre `cwebts_verify_action` est active ;
- des creations client programmatiques via WooCommerce peuvent heriter d'une validation captcha non rendue.

Le meilleur point pour le formulaire d'inscription WooCommerce est `woocommerce_process_registration_errors`, appele dans `WC_Form_Handler::process_registration()` uniquement pour le POST `register` avec nonce `woocommerce-register`. Signature inspectee :

```php
apply_filters( 'woocommerce_process_registration_errors', $validation_error, $username, $password, $email );
```

Il faut donc un filtre `accepted_args = 4`, retourner le `WP_Error`, et rendre sur `woocommerce_register_form`. Si on conserve `woocommerce_register_post`, il faut au minimum une garde stricte sur `isset( $_POST['register'] )` et le nonce, mais ce serait moins propre que le filtre dedie.

La formulation "$validation_errors est passe par reference" est aussi imprecise pour `woocommerce_register_post` : WooCommerce passe un objet `WP_Error`, pas `do_action_ref_array`. L'objet est mutable, donc ca fonctionne techniquement, mais ce n'est pas une reference de parametre.

### Ne pas reutiliser aveuglement le prefixe "Error:" existant

Le plan dit que les messages peuvent reutiliser la chaine "Error:" existante. C'est dangereux pour WooCommerce.

Dans `process_login()`, WooCommerce fait deja :

```php
throw new Exception( '<strong>' . __( 'Error:', 'woocommerce' ) . '</strong> ' . $validation_error->get_error_message() );
```

Dans `process_registration()`, WooCommerce prefixe aussi les erreurs issues de `woocommerce_process_registration_errors`. Si la classe `WC_Login` ou `WC_Register` ajoute elle-meme `<strong>Error:</strong>`, l'utilisateur verra un double prefixe.

Recommandation : pour WooCommerce, ajouter le message brut configure (`get_error_message()`) dans le `WP_Error` ou dans `wc_add_notice()`, sauf contexte ou WooCommerce ne prefixe pas lui-meme. Pour `edit-account`, WooCommerce ajoute directement les messages du `WP_Error` en notices, donc le message brut est aussi plus coherent avec ses autres erreurs.

### Le plan de tests sous-estime les stubs necessaires

Les tests existent a la racine (`tests/bootstrap.php`, `tests/run-tests.php`), pas dans le dossier plugin. Ils ne stubbent pas actuellement tout ce que les nouvelles classes demanderont si on teste l'activation des hooks :

- `add_action()`;
- `add_filter()`;
- `wc_add_notice()`;
- probablement `wp_enqueue_script()` / `wp_register_script()` si le rendu est teste directement ;
- des methodes `WP_Error` supplementaires selon les assertions (`get_error_code()`, `get_error_messages()`).

Tester directement les methodes `validate()` est simple, mais tester le gating "la classe enregistre ses hooks seulement si toggle + cles" demande de capturer les hooks dans le bootstrap. Sinon l'instanciation avec toggle active fera fatal sur `add_action()` / `add_filter()`.

## 3. Ce qui manque

### Formulaires de paiement WooCommerce standards non couverts

Il manque au moins deux flux WooCommerce standards si l'objectif utilisateur est "proteger les formulaires de paiement", pas seulement le checkout panier :

1. **Pay for order** (`/checkout/order-pay/...`)  
   Template `checkout/form-pay.php`, formulaire `#order_review`, hook de rendu disponible `woocommerce_pay_order_before_submit`. Le traitement passe par `WC_Form_Handler::pay_action()`, pas par `woocommerce_checkout_process`.

2. **Add payment method** (`/my-account/add-payment-method/`)  
   Template `myaccount/form-add-payment-method.php`, formulaire `#add_payment_method`, hook de rendu `woocommerce_add_payment_method_form_bottom`. La validation serveur peut passer par le filtre `woocommerce_add_payment_method_form_is_valid` : ajouter une notice et retourner `false`.

Je ne dis pas qu'il faut obligatoirement les inclure dans v1.2.0. Mais le plan doit les nommer explicitement comme exclus, ou renommer le scope en "checkout classique, login, inscription, details du compte". Sinon "paiement" donne une impression de couverture plus large qu'elle ne l'est.

### Signalement propre du Checkout Block

Le simple changelog ne suffit pas. Si l'admin active "WooCommerce checkout" alors que sa page checkout utilise le bloc `woocommerce/checkout`, le plugin donnerait une fausse impression de protection.

Minimum recommande :

- libelle ou description du champ : "Classic shortcode checkout only; WooCommerce Checkout Block is not protected in this version" ;
- readme/changelog avec la meme limite ;
- idealement un admin notice conditionnel quand `protect_wc_checkout` est actif et que la page checkout contient le bloc `woocommerce/checkout`.

### Interaction entre inscription checkout et toggle register

Il faut documenter et tester le cas "creation de compte pendant checkout". Avec le hook corrige (`woocommerce_process_registration_errors`), `protect_wc_register` ne protegera pas cette creation de compte ; elle sera protegee seulement si `protect_wc_checkout` est actif. C'est acceptable, mais il faut que ce soit intentionnel.

Si le hook initial `woocommerce_register_post` etait garde, il faudrait un test de non-regression obligatoire : checkout avec `createaccount=1`, `protect_wc_register=1`, `protect_wc_checkout=0` ne doit pas etre bloque par une validation qui n'a jamais rendu de widget.

### Couverture login plus large que "Mon compte"

Le rendu sur `woocommerce_login_form` couvre aussi le formulaire "Returning customer?" du checkout, car `templates/checkout/form-login.php` appelle `woocommerce_login_form()`, qui rend le template global avec le meme hook. Ce n'est pas un probleme ; c'est meme probablement souhaite. Mais la doc/toggle devrait dire "WooCommerce login forms" plutot que seulement "Mon compte", pour eviter une surprise.

### Tests front-end indispensables sur checkout

Les tests unitaires ne prouveront pas le point le plus risque : le cycle `update_order_review` + rendu Turnstile + clic submit.

Il faut au moins un protocole manuel ou navigateur pour :

- changer pays/livraison/paiement ;
- verifier que le widget reste rendu ;
- cliquer rapidement apres un refresh checkout ;
- verifier que le POST contient `cf-turnstile-response` ;
- verifier un echec Turnstile puis une resoumission.

Sans ca, le placement dans un fragment AJAX reste une hypothese.

## 4. Ce que je remettrais en question

### "4 classes" est bon, mais "4 formulaires" est peut-etre trop court

La granularite en classes est bonne. Le nombre de classes depend du scope produit. Si v1.2.0 promet seulement les 4 formulaires listes, alors 4 classes suffisent. Si le wording promet "WooCommerce payment protection", il faut probablement ajouter au moins `WC_Pay_Order` et `WC_Add_Payment_Method`, ou expliquer leur exclusion.

### Le toggle checkout doit-il couvrir seulement le checkout panier ?

Je remettrais en question le nom `protect_wc_checkout` si l'utilisateur final le lit comme "tous les paiements WooCommerce". Techniquement, il couvre le checkout shortcode panier. Il ne couvre ni `order-pay`, ni `add-payment-method`, ni le Checkout Block. Le libelle admin doit etre precis, par exemple "Classic checkout (shortcode)".

### `woocommerce_after_order_notes` est stable, mais pas parfait UX

Entre les deux options demandees, je choisis le placement stable (`woocommerce_after_order_notes`) pour eviter les faux rejets. Mais il faut reconnaitre le tradeoff : sur un widget visible, il peut etre loin du bouton.

Une alternative stable et possiblement meilleure est `woocommerce_checkout_before_order_review` : elle reste dans le formulaire, hors fragments remplaces par `update_order_review`, et plus proche du recapitulatif/paiement que les notes de commande. Je la prefererais a `woocommerce_after_order_notes` si le theme standard le rend proprement.

### Verification `action` optionnelle

Le `Verifier` ne verifie l'action Turnstile que si le filtre `cwebts_verify_action` retourne `true`. Donc les `action()` distinctes sont surtout informatives par defaut. Ce n'est pas une faute, mais les tests devraient inclure le mode `cwebts_verify_action = true`, parce que c'est ce mode qui revele les mauvais melanges de contexte, notamment `wc_register` vs `wc_checkout`.

### Dependances produit

Le plugin declare encore `Requires Plugins: elementor`. Ajouter des protections WooCommerce est compatible avec l'architecture actuelle, mais produit une extension qui reste formellement dependante d'Elementor meme pour un marchand qui voudrait seulement WooCommerce. Ce n'est pas bloquant pour v1.2.0, mais c'est a garder en tete si la couverture WooCommerce devient un axe produit autonome.
