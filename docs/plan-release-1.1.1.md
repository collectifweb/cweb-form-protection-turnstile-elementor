# Plan : release 1.1.1 (maintenance — 2 correctifs)

## Contexte

Plugin WordPress **CWeb Form Protection with Turnstile for Elementor Forms**
(slug `cweb-form-protection-turnstile-elementor`, namespace `CWebTS`), publié sur
WordPress.org. Release de maintenance **1.1.1** regroupant deux correctifs
indépendants, validée par revue croisée Claude ↔ Codex (consensus atteint).

## Correctifs

### 1. Réponses aux commentaires bloquées en admin
Avec « Protéger les commentaires » actif, répondre à un commentaire depuis l'admin
(action AJAX `replyto-comment`) était rejeté : WordPress construit la réponse
côté serveur sans rendre de widget Turnstile, donc aucun jeton n'est envoyé, mais
`wp_new_comment()` exécute quand même `preprocess_comment`.

**Correction** (`includes/integrations/class-wp-comments.php::validate()`) : sortie
anticipée scopée au strict nécessaire — action AJAX `replyto-comment` **et**
capacité `moderate_comments` :

```php
$ajax_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
if ( wp_doing_ajax() && 'replyto-comment' === $ajax_action && current_user_can( 'moderate_comments' ) ) {
	return $commentdata;
}
```

Le formulaire de commentaire public continue d'afficher le widget et d'être
vérifié. Le handler core `replyto-comment` vérifie déjà son propre nonce.

### 2. Auto-retry du widget non borné
Sur un échec de rendu persistant (clé liée à un autre domaine, etc.), le widget se
réinitialisait en boucle (~0,5 s) → flot de 400 vers Cloudflare et clignotement.

**Correction** (`assets/js/turnstile.js`) : le widget rend avec `retry: 'never'`
— le plugin possède son budget de relance. `error-callback` réinitialise au plus
2 fois (récupération d'un hoquet réseau), puis s'arrête (aucun reset = aucun
re-challenge) ; Turnstile affiche alors son état d'erreur par défaut. Un challenge
réussi (`callback`) remet le compteur `el.__tfErrors` à 0, pour que des erreurs
espacées sur une longue session ne bloquent jamais un visiteur légitime.

## Points de vigilance

- **Sécurité du bypass commentaires** : ne jamais élargir au-delà de
  `replyto-comment` + `moderate_comments`. 6 tests verrouillent la frontière
  (public sans jeton bloqué ; autre action AJAX d'un modérateur toujours
  bloquée ; `replyto-comment` sans capacité toujours bloqué).
- **`retry: 'never'`** : la récupération réseau repose désormais uniquement sur
  les 2 resets manuels. Ne pas réintroduire de `return true`/`false` dans
  `error-callback` en croyant piloter l'auto-retry (comportement non documenté).
- **`.pot`** : régénérer proprement via `wp i18n make-pot` si l'outil est
  disponible au packaging. Sans lui, corriger les références de ligne à la main
  (aucun `msgid` n'a changé en 1.1.1).

## Vérifications

- `php -l` OK ; `node --check` OK ; suite `php tests/run-tests.php` : **73/73**.
- Testé manuellement sur staging (réponse admin OK, formulaire public protégé,
  plus de flot de 400 sur scénario clé/domaine incompatible).

## Packaging / déploiement

1. `cweb-form-protection-turnstile-elementor.php` : `Version: 1.1.1` +
   `CWEBTS_VERSION '1.1.1'`. `readme.txt` : `Stable tag: 1.1.1` + changelog +
   upgrade notice. `CHANGELOG.md` : `[1.1.1]`. `.pot` : `Project-Id-Version` 1.1.1.
2. Build `cweb-form-protection-turnstile-elementor-1.1.1.zip`.
3. `git commit` sur `main` + tag GitHub `v1.1.1` + release (zip en asset).
4. SVN WordPress.org : sync `.svn-wporg/trunk` depuis le dossier plugin, créer
   `.svn-wporg/tags/1.1.1`, `svn commit` (Stable tag 1.1.1 déjà dans `readme.txt`).

## Décisions explicitement écartées

- **Migrer de `preprocess_comment` vers un hook frontal** (`pre_comment_on_post`) :
  plus invasif, hors périmètre d'une 1.1.1. À reverser en backlog produit.
- **`retry: 'auto'` + cap sur la valeur de retour d'`error-callback`** : reposait
  sur un comportement Cloudflare non documenté ; remplacé par `retry: 'never'`.
- **Régénérer le `.pot` via `xgettext` brut** : divergerait du format
  `wp i18n make-pot` et créerait plus de bruit qu'il n'en corrige.
