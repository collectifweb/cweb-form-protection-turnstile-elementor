# Scénario navigateur (assurance qualité)

28 étapes rejouées dans un vrai navigateur, avec les clés de test de Cloudflare :
connexion, inscription, mot de passe perdu et commentaires WordPress (avec et sans
jeton), réponse depuis l'administration, notes de l'éditeur de blocs, champ
Elementor Pro par formulaire **y compris une visite servie par le cache
d'Elementor et un modèle réutilisé affiché seul**, mode « tous les formulaires », les quatre formulaires WooCommerce
(dont une vraie commande), et une clé secrète « toujours refusé ».

Rien ici n'est livré avec l'extension (le zip et le SVN ne prennent que le
dossier `cweb-form-protection-turnstile-elementor/`).

## Fichiers

- `setup.php` : prépare le site (`wp eval-file`) : clés de test, toutes les
  protections actives, page Elementor avec un formulaire A (champ Turnstile) et
  un formulaire B (sans), un modèle Elementor protégé placé sur deux pages
  (`qa-template-a` après un autre formulaire protégé, `qa-template-c` seul), WooCommerce en commande classique (shortcode),
  paiement à la livraison, un produit virtuel, un client `qacustomer`.
- `qa-mail.php` : extension « mu » qui fait croire que les courriels partent
  (le conteneur n'a pas de serveur de courriel).
- `qa.cjs` : le scénario. `node qa.cjs <url> <dossier wp-env> <dossier de sortie>`.
  Variables : `PLAYWRIGHT_CORE` (chemin du module playwright-core) et
  `WP_ENV_BIN` (binaire wp-env).

## Recette

1. Un dossier par version de WordPress, avec ce `.wp-env.json` (adapter `core`,
   `phpVersion`, `port` et les chemins) :

   ```json
   {
     "core": "https://downloads.wordpress.org/release/wordpress-7.1.4.zip",
     "phpVersion": "8.3",
     "port": 8981,
     "testsEnvironment": false,
     "plugins": [
       "https://downloads.wordpress.org/plugin/elementor.4.4.0.zip",
       "https://downloads.wordpress.org/plugin/woocommerce.11.3.0.zip",
       "https://downloads.wordpress.org/plugin/plugin-check.2.2.0.zip"
     ],
     "mappings": {
       "wp-content/plugins/cweb-form-protection-turnstile-elementor": "<dépôt>/cweb-form-protection-turnstile-elementor",
       "wp-content/plugins/elementor-pro": "<copie d'Elementor Pro>"
     },
     "config": { "WP_DEBUG": true, "WP_DEBUG_LOG": true, "WP_DEBUG_DISPLAY": false }
   }
   ```

   Notre extension et Elementor Pro passent par `mappings`, jamais par `plugins` :
   wp-env tenterait de les activer avant Elementor et l'environnement serait détruit.

2. `wp-env start`, puis copier `qa-mail.php` dans
   `~/.wp-env/<env>/wordpress-<version>/wp-content/mu-plugins/` (le dossier
   `wordpress-<version>`, pas `WordPress`).
3. `docker cp setup.php <env>-cli-1:/tmp/setup.php`, puis
   `wp-env run cli -- wp plugin activate elementor-pro cweb-form-protection-turnstile-elementor`,
   `wp-env run cli -- wp eval-file /tmp/setup.php` et
   `wp-env run cli -- wp rewrite structure '/%postname%/' --hard`.
4. `node qa.cjs http://localhost:8981 <dossier wp-env> <sortie>`. Captures et
   `results.json` dans la sortie ; une capture par page ouverte en cas d'échec.
5. `wp-env run cli -- wp plugin check cweb-form-protection-turnstile-elementor`
   et relire `wordpress-<version>/wp-content/debug.log`.

Pièges connus : voir `tasks/lessons.md`.
