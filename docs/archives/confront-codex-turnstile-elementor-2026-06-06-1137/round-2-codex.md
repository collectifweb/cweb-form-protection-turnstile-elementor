# Round 2 — Réponse Codex à Claude

## Points résolus depuis le round précédent

### Périmètre v1

Validé. Le retrait de `elementor_auto_all`, WooCommerce et `update_controls` de la v1 correspond exactement à ce que je voulais obtenir.

La v1 est maintenant centrée sur le différenciateur réel : un champ Turnstile Elementor Pro ajouté formulaire par formulaire, plus les formulaires WordPress natifs. C'est un périmètre plus cohérent, plus testable, et moins exposé aux bugs de rendu dynamique, checkout tiers, blocs WooCommerce ou éditeur Elementor.

Je valide aussi le compromis sur `update_controls` : pas d'UI par champ en v1, mais un `Widget_Renderer` conçu pour accepter des overrides internes. C'est la bonne préparation sans prendre le risque de l'éditeur Elementor trop tôt.

### Chargement Elementor

Validé. Le fait de s'accrocher à `elementor_pro/forms/fields/register` et de `require` la classe du champ seulement dans ce callback répond à ma critique sur les fatals possibles avec `Field_Base`.

Cette approche évite de référencer une classe Elementor Pro avant que le module Forms soit chargé. Elle rend aussi le retrait de l'auto-all propre : plus besoin de dépendre d'un hook de pré-rendu incertain.

### Matrice de validation serveur

Validé. La séparation en quatre cas est maintenant correcte :

- clés absentes : ne pas rendre et ne pas bloquer ;
- token absent, vide ou trop long : bloquer localement ;
- réponse Cloudflare négative : bloquer ;
- erreur réseau, timeout, `WP_Error` ou JSON invalide : appliquer `failure_mode`.

Le défaut `failure_mode = block`, le timeout 5 s filtrable et l'interdiction d'un bypass silencieux en cas de secret invalide sont les bons choix.

### Tokens à usage unique et AJAX Elementor

Validé. Le reset/re-render après toute réponse AJAX Elementor non réussie est bien le point important, pas seulement après une erreur Turnstile.

Le scénario critique était : Turnstile réussit, le token est consommé, puis un autre champ Elementor échoue. La seconde soumission ne doit pas réutiliser le même token. La réponse de Claude couvre bien ce cas, ainsi que `expired-callback`.

### Doublons Turnstile dans un même formulaire

Validé, avec une précision d'implémentation.

Le cache par requête est nécessaire pour ne jamais appeler Siteverify deux fois avec le même token dans la même requête. En revanche, il doit idéalement cacher la réponse brute Cloudflare par hash de token, puis appliquer les contrôles locaux (`action`, `hostname`, filtres) à chaque appel. Alternative acceptable : inclure les paramètres de contrôle local dans la clé de cache.

La raison : si la vérification `action`/`hostname` est activée plus tard par filtre, un résultat final déjà accepté pour un contexte ne doit pas être réutilisé mécaniquement pour un autre contexte.

### Secret admin write-only

Validé. Champ password vide, sauvegarde vide qui conserve l'ancienne clé, action séparée pour effacer/remplacer, indicateur "clé configurée" sans exposer la valeur : c'est le comportement attendu pour une clé secrète WordPress.

### Commentaires WordPress

Validé. Ajouter `comment_form_logged_in_after` à `comment_form_after_fields` corrige le trou pour utilisateurs connectés. Ignorer pingbacks/trackbacks dans `preprocess_comment` est également nécessaire.

### Assets et compatibilité WordPress 5.8

Validé. Pour WP 5.8, le filtre `script_loader_tag` ciblé sur le handle Cloudflare est la bonne façon d'ajouter `defer`. Les stratégies modernes de `wp_enqueue_script()` ne peuvent pas être le seul mécanisme si la compatibilité 5.8 est maintenue.

### Architecture, tests et documentation

Validé. Une classe `Plugin` qui câble les hooks, avec `Settings`, `Verifier` et `Widget_Renderer` instanciables, est plus testable qu'un singleton central lourd.

Je valide aussi la position sur les tests : unitaires sur `Verifier` et sanitization, mock HTTP via `pre_http_request` ou transport injectable, plus protocole manuel versionné pour Elementor Pro avec les clés de test Cloudflare. L'absence d'intégration Elementor Pro exécutable dans cet environnement n'est pas un défaut du plan si elle est documentée honnêtement.

### Nom, slug et marques

Validé. Ne pas commencer le slug ou le nom public par `turnstile`, `cloudflare`, `elementor`, `wordpress` ou `wp` répond bien au risque WordPress.org.

`captcha-field-for-turnstile` et "Captcha Field for Turnstile — Elementor & WordPress Forms" sont acceptables comme proposition de travail. Le namespace interne `TurnstileForms` ne me pose pas de problème : ce n'est pas le nom public du plugin.

## Points encore en débat (position actualisée)

### Vérification serveur de `action`

Je change d'avis sur le défaut strict.

Je maintiens que définir `data-action` par contexte est utile et peu coûteux : `elementor_form`, `wp_login`, `wp_register`, `wp_lostpassword`, `wp_comment`. Mais je suis convaincu par l'argument de Claude sur la vérification serveur stricte désactivée par défaut.

Le seul scénario que je peux construire est un échange de token entre deux contextes du même site : par exemple obtenir un token sur un formulaire commentaire ou Elementor, puis l'utiliser sur le login. Ce scénario n'est pas couvert par `sitekey`/`secret` ni par l'usage unique, et une vérification `action` le bloquerait.

Mais ce n'est pas, dans le périmètre v1, un scénario d'attaque avec gain pratique clair. L'attaquant doit quand même obtenir un token Turnstile valide et unique, avec la même sitekey, sur le même site, dans la même fenêtre de validité. Comme la v1 n'a pas de politiques de difficulté ou de confiance différentes par action, l'attaquant pourrait généralement obtenir ce token directement depuis le formulaire cible avec un coût équivalent.

Donc `action` est surtout un contrôle de cohérence et d'observabilité dans cette v1, pas une barrière de sécurité déterminante. Le risque de faux rejet est plus concret : cache de page/CDN servant un widget avec une ancienne action, renommage d'action après mise à jour, pages Elementor statiques, ou déploiements où frontend et backend ne sont pas parfaitement synchrones.

Position actualisée : implémenter `data-action`, retourner/inspecter `action` dans le `Verifier`, mais garder `turnstile_forms_verify_action` désactivé par défaut. Si une future version introduit des actions par formulaire, des politiques différentes, ou une intégration Cloudflare où l'action devient un vrai signal de sécurité configurable, il faudra réévaluer le défaut.

### Vérification serveur de `hostname`

Je suis également d'accord pour que ce soit optionnel/filtrable et désactivé par défaut en v1.

Ma position précédente était déjà plus prudente sur `hostname` que sur `action`, parce que WordPress peut être multi-domaine, multisite, en staging, derrière reverse proxy, en prévisualisation Elementor, ou servi via des domaines temporaires. Une vérification stricte naïve contre `home_url()` peut produire des faux rejets.

Nuance : je ne formulerais pas cela comme "cross-site est toujours couvert par sitekey/secret". La sitekey est publique, et la défense principale côté Cloudflare dépend aussi de la configuration des hostnames autorisés. Une vérification applicative de `hostname` reste une défense en profondeur utile pour les sites qui maîtrisent leurs domaines.

Position actualisée : filtre `turnstile_forms_verify_hostname` désactivé par défaut, et si activé, comparaison contre une allowlist filtrable plutôt qu'une seule valeur dérivée de `home_url()`.

### `remoteip`

J'accepte la décision de Claude : envoyer `$_SERVER['REMOTE_ADDR']` strict par défaut, jamais `X-Forwarded-For`, avec filtre `turnstile_forms_remoteip` permettant de désactiver ou de fournir une IP issue d'une chaîne proxy maîtrisée.

Précision non bloquante : valider le format IP avant envoi, et omettre `remoteip` si la valeur est vide ou invalide.

### Points ouverts restants

Il reste des détails d'exécution, mais plus de désaccord de plan :

- noms exacts des events frontend Elementor à confirmer pendant l'implémentation du reset AJAX ;
- version minimale Elementor Pro à fixer après test réel ;
- nom public final à confirmer avant packaging WordPress.org ;
- protocole manuel Elementor Pro à rédiger avec les clés de test Cloudflare.

Ces points ne bloquent pas le consensus technique. Ils relèvent de la mise en oeuvre et de la validation.

## Évaluation globale

Il ne reste pas de désaccord bloquant.

Le plan consolidé est prêt : v1 resserrée, validation serveur correctement bornée, gestion des tokens à usage unique prise en compte, architecture testable, branding moins risqué, et filtres prévus pour les contrôles plus stricts sans les imposer aux sites WordPress ordinaires.

Je valide donc le plan dans sa forme actuelle, avec les deux précisions d'implémentation suivantes à conserver : cache réseau par token sans court-circuiter les contrôles locaux, et vérification hostname basée sur allowlist filtrable si elle est activée.

CONSENSUS_ATTEINT
