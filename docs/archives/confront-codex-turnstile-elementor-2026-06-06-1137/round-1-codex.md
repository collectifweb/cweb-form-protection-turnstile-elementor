# Revue technique Codex — round 1

## 1. Ce que j'approuve

### Le différenciateur produit est le bon

Le choix central, créer un vrai champ Elementor Pro `Field_Base` que l'utilisateur ajoute formulaire par formulaire, est solide. C'est précisément l'angle qui évite de refaire un plugin "Turnstile global sur toutes les intégrations" et qui donne une valeur nette face à `simple-cloudflare-turnstile`.

L'API Elementor confirme cette approche : un champ externe s'enregistre via `elementor_pro/forms/fields/register`, étend `\ElementorPro\Modules\Forms\Fields\Field_Base`, expose `get_type()`, `get_name()`, `render()`, `validation()` et éventuellement `update_controls()`. Les signatures décrites dans le plan sont globalement correctes.

### La validation côté serveur est non négociable et bien placée

Le wrapper `Verifier` autour de `wp_remote_post()` vers `https://challenges.cloudflare.com/turnstile/v0/siteverify` est la bonne frontière. Cloudflare impose une validation serveur : le widget seul ne protège rien, les tokens expirent après 5 minutes et sont à usage unique.

Le plan est aussi juste sur les paramètres Cloudflare : `secret` et `response` sont requis, `remoteip` est optionnel, la réponse est JSON avec `success` et `error-codes`.

### Le token par défaut `cf-turnstile-response` est le bon choix v1

Pour le champ Elementor, je valide le choix par défaut : lire `$_POST['cf-turnstile-response']`.

Cloudflare crée par défaut un champ caché nommé `cf-turnstile-response`, et Elementor soumet seulement les inputs du formulaire courant. Avec plusieurs formulaires sur une page, chaque soumission AJAX porte le token du formulaire soumis, pas ceux des autres formulaires.

Utiliser `data-response-field-name="form_fields[...]"` pour remplir `$field['value']` est plus "Elementor-pur", mais plus couplé aux détails internes du repeater de champs Elementor. Ce n'est pas nécessaire pour v1. Je le garderais comme option technique uniquement si un test réel prouve que certains contextes Elementor ne sérialisent pas correctement le champ global.

### Les clés non configurées ne doivent pas casser le site

La règle "clés absentes => ne pas rendre, ne pas bloquer, afficher une notice admin" est correcte. Un plugin anti-spam ne doit pas transformer une installation partiellement configurée en panne de formulaires.

Attention : cette règle ne doit pas s'appliquer aux clés invalides une fois la protection activée. Une clé secrète invalide doit produire un échec de validation et une notice admin claire, pas un bypass silencieux.

### L'absence de dépendance runtime est adaptée

Pas de Composer runtime, pas de SDK Cloudflare, pas de cURL direct : c'est le bon niveau pour WordPress.org. Le script externe Cloudflare est acceptable parce qu'il fait partie du service configuré par l'administrateur, mais il faudra le documenter explicitement dans le readme comme appel à un service tiers.

## 2. Ce que je désapprouve

### Je ne mettrais pas `elementor_auto_all` en v1

Je suis catégorique : le mode auto-all Elementor ne doit pas être dans la v1.

Il affaiblit le positionnement du plugin. Le différenciateur est le contrôle formulaire par formulaire via un champ Elementor. Un mode global recrée exactement la surface fragile des plugins existants : injection DOM, interactions avec popups, formulaires rendus tardivement, cache Elementor, double rendu, et validation globale difficile à expliquer.

Si ce mode est ajouté plus tard, il doit être v1.1 et expérimental. L'architecture la moins mauvaise serait :

- rendu frontend par JS explicite sur chaque `.elementor-form` non déjà marqué comme protégé ;
- insertion avant le bouton submit côté DOM, avec marqueur `data-turnstile-forms-auto`;
- rendu via `turnstile.render()` après chargement de l'API ;
- observation des popups/contenus dynamiques Elementor ;
- validation serveur globale via `elementor_pro/forms/validation`.

Je déconseille l'injection par filtre HTML. C'est cassant, difficile à tester, et sensible aux changements de markup Elementor.

Point important : le plan cite `elementor_pro/forms/pre_render`. Ce hook est suspect sous cette forme. Les docs officielles confirment `elementor_pro/forms/validation`, mais pas ce hook de pré-rendu. Les exemples de code Elementor Pro visibles publiquement utilisent plutôt `elementor-pro/forms/pre_render` avec un tiret après `elementor-pro`. Il faut vérifier dans la version Elementor Pro réellement ciblée avant d'écrire quoi que ce soit sur ce hook.

### Je ne mettrais pas WooCommerce en v1

WooCommerce doit sortir du périmètre v1.

Ce n'est pas le différenciateur, et la complexité réelle est sous-estimée. "WooCommerce login / register / lost password" paraît petit, mais le support WooCommerce entraîne vite :

- formulaires classiques vs blocs ;
- compte client, checkout, création de compte au checkout ;
- fragments AJAX et refreshs ;
- compatibilité thème ;
- plugins checkout tiers ;
- risque de bloquer un achat pour une erreur anti-spam.

Le plugin concurrent couvre déjà WooCommerce largement. Pour ce projet, la v1 doit réussir Elementor Pro Forms + WordPress natif. WooCommerce peut venir après stabilisation du coeur.

### Je ne ferais pas `update_controls` en v1 sauf test Elementor réel disponible

Je reporterais `update_controls` à v1.1.

Techniquement, l'API existe et la signature du plan est correcte. Mais ce n'est pas indispensable au différenciateur. Les réglages globaux `theme`, `size`, `appearance`, `language` suffisent pour lancer. Les contrôles par champ dans le repeater Elementor augmentent le risque de bug éditeur et nécessitent une vraie validation dans Elementor Pro.

Compromis propre : le renderer accepte déjà des overrides par champ dans son API interne, mais l'UI Elementor reste globale en v1. Quand on ajoute `update_controls`, on ajoute seulement `theme` et `size` avec valeur "hériter du global".

### Le défaut `failure_mode = block` est bon, mais le réglage est trop grossier

Le défaut doit être `block`.

Si un admin a explicitement activé Turnstile sur un formulaire, laisser passer quand la validation serveur ne peut pas être faite revient à désactiver la protection au moment le plus dangereux. Le mode `allow` peut exister pour les sites qui privilégient la disponibilité, mais il doit être présenté comme un compromis de sécurité clair.

En revanche, le plan doit distinguer quatre cas :

- clés absentes : ne pas rendre, ne pas bloquer ;
- token absent : bloquer ;
- réponse Cloudflare négative (`invalid-input-response`, `timeout-or-duplicate`, `invalid-input-secret`) : bloquer ;
- erreur réseau / timeout / `WP_Error` : appliquer `failure_mode`.

Le timeout de 10 secondes est trop long pour un formulaire utilisateur. Je partirais sur 5 secondes maximum, idéalement filtrable.

### Le slug proposé est risqué pour WordPress.org

`turnstile-forms` est un mauvais slug candidat pour WordPress.org parce qu'il commence par `turnstile`, qui est le nom du produit Cloudflare. Les guidelines WordPress.org interdisent l'usage d'une marque ou d'un nom de projet tiers comme terme unique ou initial du slug sauf représentation légale.

Le titre "Turnstile for Elementor & WordPress Forms" est aussi risqué : il commence par le nom du produit tiers et contient deux marques/projets tiers très visibles.

Je choisirais un nom original en premier, puis les marques après un connecteur descriptif. Exemples plus sûrs :

- `captcha-field-for-turnstile`
- `form-shield-for-turnstile`
- `captcha-field-for-turnstile-forms`

Titre possible : `Captcha Field for Turnstile and Elementor Forms`.

Le readme peut ensuite dire clairement : "Adds Cloudflare Turnstile support to Elementor Pro Forms and WordPress forms. Not affiliated with Cloudflare or Elementor."

## 3. Ce qui manque

### Gestion des tokens à usage unique dans Elementor AJAX

Le plan mentionne un reset après échec AJAX, mais il faut être plus précis.

Scénario critique : l'utilisateur résout Turnstile, soumet le formulaire, la validation Turnstile réussit, puis un autre champ Elementor échoue. Le token est déjà consommé par Siteverify. Si le widget n'est pas reset avant la seconde soumission, Cloudflare retournera `timeout-or-duplicate`.

Donc il faut reset / re-render le widget après toute réponse AJAX Elementor non réussie, pas seulement après une erreur Turnstile. Il faut aussi gérer `expired-callback`, car les tokens expirent après 5 minutes.

### Protection contre plusieurs champs Turnstile dans le même formulaire

Le plan dit "ignorer les doublons", mais il ne donne pas l'implémentation. C'est plus important qu'il n'y paraît.

Si deux champs Turnstile sont dans le même formulaire et que `validation()` s'exécute deux fois, le premier appel Siteverify consomme le token et le second peut échouer en `timeout-or-duplicate`.

Il faut au minimum un cache statique par requête dans le verifier ou dans la validation Elementor :

- même token soumis deux fois dans la même requête => réutiliser le résultat local ;
- ne jamais appeler Siteverify deux fois pour le même token dans la même requête ;
- afficher une notice éditeur/admin si plusieurs champs Turnstile sont détectés dans un même formulaire, si c'est détectable.

### Validation `hostname` et `action`

Cloudflare recommande de vérifier les champs additionnels quand ils sont utilisés. Le plan n'en parle pas.

Je définirais `data-action` par contexte :

- `elementor_form`
- `wp_login`
- `wp_register`
- `wp_lostpassword`
- `wp_comment`

Puis, côté serveur, vérifier `action` quand elle est attendue. Pour `hostname`, je mettrais une validation optionnelle/filtrable, car les sites WordPress peuvent être multi-domaines, en staging, derrière proxy ou en multisite.

### Tests automatisables avec les clés de test Cloudflare

Le plan dit que les scénarios manuels sont "impossibles sans WP installé ici". C'est trop faible pour un plugin destiné à WordPress.org.

Cloudflare fournit des sitekeys et secrets de test avec résultats prévisibles. Il faut prévoir :

- tests unitaires du `Verifier` avec `pre_http_request` pour simuler succès, échec, `WP_Error`, JSON invalide, timeout ;
- tests de sanitization des réglages ;
- test du comportement token vide / token trop long ;
- tests manuels documentés avec les clés de test Cloudflare ;
- si Elementor Pro n'est pas disponible en CI, au minimum un protocole manuel versionné pour Elementor Pro Forms.

### Limite de longueur du token

Cloudflare documente une longueur maximale de 2048 caractères. Le plan doit rejeter localement un token vide ou trop long avant l'appel réseau.

### Réglage de la clé secrète en admin

Le champ `secret_key` ne doit pas ré-afficher la vraie clé. Il faut prévoir le comportement exact :

- champ password vide par défaut ;
- sauvegarde vide = conserver l'ancienne clé ;
- action séparée pour remplacer ou effacer ;
- indication "clé configurée" sans exposer la valeur.

### Commentaires WordPress pour utilisateurs connectés

`comment_form_after_fields` ne suffit probablement pas pour tous les cas. Les champs "author/email/url" ne sont pas affichés aux utilisateurs connectés. Il faut ajouter un hook de rendu pour le cas connecté, typiquement `comment_form_logged_in_after` ou un emplacement commun proche du submit.

La validation via `preprocess_comment` est acceptable, mais il faut éviter de casser pingbacks/trackbacks et prévoir un message retour correct.

### Compatibilité WordPress 5.8 avec `async/defer`

Si le plugin supporte WordPress 5.8, le plan doit préciser comment il ajoute `async`/`defer`. Les arguments `strategy` modernes de `wp_enqueue_script()` ne couvrent pas toute cette plage de compatibilité. Il faudra probablement un filtre `script_loader_tag` ou accepter un chargement sans stratégie sur les anciennes versions.

### Version minimale Elementor Pro

Le plan dit "Elementor Pro requis", mais pas quelle version minimale. C'est un trou. Les docs actuelles montrent l'API cible, mais il faut fixer une version testée et documenter :

- Elementor tested up to ;
- Elementor Pro tested up to ;
- comportement si Elementor Pro absent ;
- comportement si Elementor free seul est installé.

## 4. Ce que je remettrais en question

### `remoteip` par défaut

Cloudflare rend `remoteip` optionnel. Je ne ferais pas confiance aux headers proxy par défaut. Deux options raisonnables :

- ne pas envoyer `remoteip` par défaut ;
- ou envoyer uniquement `$_SERVER['REMOTE_ADDR']`, avec filtre pour les sites qui ont une chaîne proxy maîtrisée.

Je penche pour `REMOTE_ADDR` strict ou omission complète. Je n'utiliserais jamais `X-Forwarded-For` sans configuration explicite de proxies de confiance.

### `appearance = always` et `size = flexible` comme défauts

`size = flexible` est défendable pour Elementor, car les formulaires sont très responsives. `appearance = always` est le choix le plus prévisible pour v1.

Je n'exposerais pas `execution = execute` en v1. Le mode manuel est utile pour formulaires multi-step, mais il impose une orchestration JS plus sensible. Pour v1, rester sur l'exécution automatique au rendu.

### Le scope WordPress natif

Les formulaires natifs sont utiles, mais ils ne doivent pas retarder le champ Elementor. Si le temps de développement devient serré, je livrerais :

1. Elementor Pro Forms field ;
2. WP login/register/lost password ;
3. comments ;
4. WooCommerce plus tard.

Le plugin doit gagner sur Elementor, pas sur la couverture maximale de formulaires.

### Singleton global

Le singleton est courant dans les plugins WordPress, mais je ne le rendrais pas central au point de rendre les tests pénibles. Pour un greenfield, je préférerais :

- une classe `Plugin` qui câble les hooks ;
- des services instanciables (`Settings`, `Verifier`, `Renderer`) ;
- peu ou pas d'état global, sauf constantes et helpers de bootstrap.

## Vérification des API

### Elementor

Confirmé :

- `elementor_pro/forms/fields/register` est le bon hook d'enregistrement des champs.
- Le registrar reçoit des instances de champs.
- Les champs étendent `\ElementorPro\Modules\Forms\Fields\Field_Base`.
- `get_type(): string`, `get_name(): string`, `render( $item, $item_index, $form ): void`, `validation( $field, $record, $ajax_handler ): void`, `update_controls( $widget ): void` correspondent à la documentation.
- `elementor_pro/forms/validation` existe pour une validation globale par formulaire soumis.

À vérifier avant implémentation :

- le hook de pré-rendu auto-all. La forme `elementor_pro/forms/pre_render` du plan n'est pas confirmée par la doc officielle consultée et semble probablement incorrecte pour Elementor Pro ; ne pas bâtir la v1 dessus.
- la disponibilité exacte de `elementor_pro/init`; les hooks officiels Elementor documentés côté core sont `elementor/loaded` et `elementor/init`. Le chargement du fichier qui étend `Field_Base` doit être différé jusqu'à un point où Elementor Pro Forms est réellement chargé, sinon fatal PHP possible.

### Cloudflare Turnstile

Confirmé :

- script officiel : `https://challenges.cloudflare.com/turnstile/v0/api.js`;
- rendu implicite possible avec `.cf-turnstile`;
- rendu explicite recommandé pour contenu dynamique ;
- champ caché automatique activé par défaut ;
- nom par défaut du champ caché : `cf-turnstile-response`;
- `data-response-field-name` permet de changer ce nom ;
- endpoint Siteverify : `POST https://challenges.cloudflare.com/turnstile/v0/siteverify`;
- paramètres requis : `secret`, `response`;
- paramètres optionnels : `remoteip`, `idempotency_key`;
- tokens valides 300 secondes et usage unique ;
- `timeout-or-duplicate` attendu après réutilisation ou expiration ;
- réponse avec `success`, `challenge_ts`, `hostname`, `error-codes`, `action`, `cdata`.

## Réponses directes aux questions ouvertes

### Token Elementor AJAX multi-forms

Choix recommandé v1 : `$_POST['cf-turnstile-response']`.

Ne pas utiliser `data-response-field-name` vers `form_fields[...]` par défaut. C'est plus couplé à Elementor et ne résout pas un problème réel tant que le champ caché Cloudflare est bien sérialisé avec le formulaire soumis.

À ajouter : `wp_unslash`, sanitization, limite 2048 caractères, cache de vérification par token dans la requête, reset JS après échec AJAX.

### `failure_mode` par défaut

Défaut : `block`.

Le mode `allow` peut exister, mais seulement pour les erreurs réseau/timeout, jamais pour token absent, token invalide ou secret invalide.

### Mode auto-all Elementor

Pas en v1. v1.1 au plus tôt.

Si ajouté : JS explicite + validation globale. Ne pas baser la v1 sur un hook de pré-rendu non confirmé.

### `update_controls`

Pas en v1 sauf accès à un vrai environnement Elementor Pro pour tester l'éditeur.

Préparer l'architecture pour des overrides, mais exposer seulement les réglages globaux au lancement.

### WooCommerce

Pas en v1.

Trop de surface, peu différenciant, risque de support élevé.

### Nom / slug WordPress.org

`turnstile-forms` est risqué. Ne pas commencer le slug ou le nom public par `cloudflare`, `turnstile`, `elementor`, `wordpress` ou `wp`.

Utiliser une marque/description originale d'abord, puis "for Turnstile" / "for Elementor Forms" dans un second temps.

## Sources vérifiées

- Elementor Developers — Add New Field : https://developers.elementor.com/docs/form-fields/add-new-field/
- Elementor Developers — Field Structure : https://developers.elementor.com/docs/form-fields/field-structure/
- Elementor Developers — Field Render : https://developers.elementor.com/docs/form-fields/field-render/
- Elementor Developers — Field Validation : https://developers.elementor.com/docs/form-fields/field-validation/
- Elementor Developers — Field Controls : https://developers.elementor.com/docs/form-fields/field-controls/
- Elementor Developers — Forms hooks : https://developers.elementor.com/docs/hooks/forms/
- Elementor Developers — Elementor Loaded / Init : https://developers.elementor.com/docs/hooks/elementor-loaded/ et https://developers.elementor.com/docs/hooks/elementor-init/
- Cloudflare Turnstile — Widget configurations : https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/
- Cloudflare Turnstile — Server-side validation : https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- Cloudflare Turnstile — Testing keys : https://developers.cloudflare.com/turnstile/troubleshooting/testing/
- WordPress.org — Detailed Plugin Guidelines : https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
