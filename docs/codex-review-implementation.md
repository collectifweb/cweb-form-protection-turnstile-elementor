# Revue implementation - Captcha Field for Turnstile

Verdict: le code runtime est globalement bon. Je n'ai pas trouve de bypass serveur evident, de hook WordPress/Elementor manifestement faux, ni de probleme d'echappement majeur. Le `Verifier` respecte l'essentiel de la matrice du plan et les tests locaux passent. Les vrais points a corriger avant une soumission/prod sont surtout le packaging WordPress.org et la robustesse du reset AJAX Elementor.

## BLOQUANT

### 1. Zip WordPress.org tel quel: le dossier `tests/` ne devrait pas etre embarque

`captcha-field-for-turnstile/tests/bootstrap.php` et `captcha-field-for-turnstile/tests/run-tests.php` sont des executables de test autonomes, sans garde `ABSPATH`/`WP_UNINSTALL_PLUGIN`. Le runner peut etre appele directement si le dossier est livre tel quel dans `wp-content/plugins/`.

Ce n'est pas un bug de protection Turnstile, mais c'est bloquant pour un zip WordPress.org propre: les "Common issues" WordPress.org listent explicitement les unit tests parmi les dossiers/fichiers non necessaires a retirer du package de production. Garder les tests dans GitHub est correct; ne pas les publier dans le build soumis a WordPress.org.

References:
- `captcha-field-for-turnstile/tests/bootstrap.php:13`
- `captcha-field-for-turnstile/tests/run-tests.php:11`

## IMPORTANT

### 1. `turnstile.js`: le reset utilise le DOM element au lieu du `widgetId`

Le code ignore la valeur retournee par `turnstile.render()` et appelle ensuite `window.turnstile.reset( el )`.

References:
- `captcha-field-for-turnstile/assets/js/turnstile.js:61`
- `captcha-field-for-turnstile/assets/js/turnstile.js:44`

La documentation Cloudflare montre `turnstile.render()` retournant un `widgetId`, puis `turnstile.reset(widgetId)`. Elle ne documente pas `reset(HTMLElement)`. Si l'API n'accepte pas l'element DOM, le `try/catch` masque l'echec et le reset AJAX ne fait rien. Impact concret: dans Elementor, apres une soumission AJAX echouee pour une autre validation de formulaire, une deuxieme soumission peut reutiliser un token deja consomme et echouer en `timeout-or-duplicate`.

A corriger avant prod: stocker le `widgetId` sur l'element (`data-tf-widget-id` ou propriete JS) et reset avec cet identifiant.

### 2. Reset Elementor trop large et fragile en multi-formulaires concurrents

Le plan demandait un reset apres toute reponse AJAX Elementor non-success. L'implementation capture le dernier submit `.elementor-form`, puis reset au prochain `ajaxComplete` global jQuery, sans verifier que la requete est celle du formulaire ni si la reponse est un echec.

References:
- `captcha-field-for-turnstile/assets/js/turnstile.js:84`
- `captcha-field-for-turnstile/assets/js/turnstile.js:97`

Ce n'est pas un bypass, mais c'est fragile:
- un AJAX sans rapport peut consommer `pendingForm`;
- deux formulaires Elementor soumis presque en meme temps se marchent dessus;
- le reset s'execute aussi apres succes, ce qui n'etait pas le contrat du plan.

Le comportement normal mono-formulaire devrait fonctionner, mais le cas "multi-formulaires" du plan n'est pas completement verrouille.

### 3. `Verifier`: un HTTP non-200 avec JSON `success:false` devient une erreur transport

`siteverify()` classe tout code HTTP different de 200 comme `__transport_error`, meme si Cloudflare renvoie un JSON valide avec `success:false`.

Reference:
- `captcha-field-for-turnstile/includes/class-verifier.php:144`

Avec `failure_mode = allow`, un non-200 semantique pourrait donc etre autorise. Cloudflare documente une reponse JSON avec `success` et `error-codes`; la logique la plus sure est de parser le JSON quand il existe et de traiter explicitement `success:false` comme un rejet, quel que soit le statut HTTP. Garder `failure_mode` uniquement pour `WP_Error`, timeout, body vide ou JSON invalide.

Ce n'est probablement pas exploitable dans le chemin nominal Cloudflare, mais c'est une deviation de la matrice stricte du plan.

### 4. Disclosure service tiers insuffisamment explicite sur les donnees envoyees

Le `readme.txt` declare bien Cloudflare comme service tiers, les URLs appelees, les ToS et la privacy policy.

Reference:
- `captcha-field-for-turnstile/readme.txt:39`

Mais il ne dit pas clairement que le token Turnstile et, par defaut, l'adresse IP visiteur (`remoteip`) sont envoyes a Cloudflare lors de la validation serveur. WordPress.org demande une documentation claire des services externes, des circonstances et des donnees envoyees. Pour eviter une demande de correction en review, ajouter une phrase explicite: navigateur charge `api.js`; au submit, le serveur envoie le token `cf-turnstile-response`, la cle secrete et `REMOTE_ADDR` valide a Cloudflare Siteverify.

### 5. `Tested up to` est trop ancien pour une soumission 2026

Le readme annonce `Tested up to: 6.5`, alors que la page officielle de telechargement WordPress propose actuellement WordPress 7.0.

Reference:
- `captcha-field-for-turnstile/readme.txt:5`

Ce n'est pas un bug runtime, mais c'est mauvais pour une soumission WordPress.org et pour la confiance utilisateur. Tester et mettre a jour au moins vers la version courante reellement testee.

### 6. Description du plugin trop longue pour l'en-tete

La description du header principal depasse largement la recommandation WordPress de 140 caracteres.

Reference:
- `captcha-field-for-turnstile/captcha-field-for-turnstile.php:5`

Pas bloquant fonctionnel, mais c'est un point de review facile a eviter. Raccourcir l'en-tete et garder le detail dans le readme.

## MINEUR

### 1. Garde `ABSPATH` absente dans les tests

Si les tests restent uniquement hors package, aucun probleme. S'ils sont embarques, c'est lie au bloquant packaging ci-dessus.

### 2. Pas de notice/detection de doublons Elementor

Le plan demandait de valider une seule fois et d'afficher une notice si plusieurs champs Turnstile sont detectes dans un formulaire Elementor. Le cache par requete evite le double appel Siteverify, mais il n'y a pas de detection/notices de doublons.

References:
- `captcha-field-for-turnstile/includes/elementor/class-turnstile-field.php:109`
- `captcha-field-for-turnstile/includes/class-verifier.php:117`

Impact faible: en echec, Elementor peut recevoir plusieurs erreurs, mais la protection reste correcte.

### 3. `error-callback` client non branche

Le commentaire PHP parle de callbacks expired/error, mais le JS ne configure que `expired-callback` et `timeout-callback`.

Reference:
- `captcha-field-for-turnstile/assets/js/turnstile.js:23`

Ce n'est pas critique car Turnstile a du retry automatique, mais un `error-callback` qui reset ou marque le widget aiderait sur les erreurs client persistantes.

### 4. Text domain non centralise en constante

Le plan mentionnait un text-domain constant; le code utilise partout le literal `captcha-field-for-turnstile`. WordPress.org n'exige pas la constante et le domaine est coherent avec le slug, donc c'est seulement une infidelite mineure au plan.

### 5. Licence semantiquement coherente, mais libelle a harmoniser

Le header utilise `GPL-2.0-or-later`; le readme utilise `GPLv2 or later`. C'est la meme licence en pratique. Pour eviter un nit WordPress.org sur les declarations de licence, utiliser le meme libelle dans les deux fichiers.

References:
- `captcha-field-for-turnstile/captcha-field-for-turnstile.php:11`
- `captcha-field-for-turnstile/readme.txt:8`

## Points valides

- Elementor: le hook `elementor_pro/forms/fields/register` et l'extension `ElementorPro\Modules\Forms\Fields\Field_Base` correspondent a la documentation officielle. Le fichier Elementor n'est charge que dans le callback, donc pas de fatal attendu si Elementor Pro est absent.
- WordPress natif: `login_form`, `authenticate`, `register_form`, `registration_errors`, `lostpassword_form`, `lostpassword_post`, `comment_form_after_fields`, `comment_form_logged_in_after` et `preprocess_comment` sont les bons points d'integration. Les signatures utilisees sont compatibles meme quand WordPress passe plus d'arguments.
- `Verifier`: token vide/oversize rejete localement; `success:false` rejete; transport/JSON invalide soumis a `failure_mode`; timeout par defaut 5s; cache par token; controles `action`/`hostname` apres cache et off par defaut; `remoteip` vient de `REMOTE_ADDR`, validee, jamais de `X-Forwarded-For`.
- Securite: sorties HTML echappees; entrees principales sanitisees; secret non rendu dans l'input admin; secret non envoye au navigateur; pas de log du token; `wp_remote_post()` utilise au lieu de cURL.
- Fidelite au plan: pas de WooCommerce, pas d'auto-all Elementor, pas de `update_controls`, toggles WordPress natifs off par defaut, `failure_mode` sur `block`, action/hostname off par defaut.

## Verification locale

- `php captcha-field-for-turnstile/tests/run-tests.php`: 33 tests, 33 passes.
- `php -l` sur tous les fichiers PHP du plugin: aucune erreur de syntaxe.

## Sources externes verifiees

- Elementor Add New Field: https://developers.elementor.com/docs/form-fields/add-new-field/index.html
- WordPress Plugin Header Requirements: https://developer.wordpress.org/plugins/plugin-basics/header-requirements/
- WordPress Plugin Readmes: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- WordPress Common Issues / external services / unneeded folders: https://developer.wordpress.org/plugins/wordpress-org/common-issues/
- WordPress Plugin Developer FAQ / trademarks: https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- WordPress Download / current release: https://wordpress.org/download/
- Cloudflare Turnstile server validation: https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- Cloudflare Turnstile client rendering: https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/
- Cloudflare Turnstile widget configuration: https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/
