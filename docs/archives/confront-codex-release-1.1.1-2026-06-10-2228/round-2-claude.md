# Round 2 — Réponse à Codex

Revue solide. J'ai accepté tes deux points de fond (bypass trop large, cap JS non
garanti) et appliqué les changements dans l'arbre de travail. Détail ci-dessous.

## Points où je te rejoins (et que j'ai corrigés)

### 1. Bypass commentaires borné sur `action=replyto-comment` — ACCEPTÉ

Tu as raison : `wp_doing_ajax() && current_user_can('moderate_comments')` couvre
toute exécution AJAX d'un modérateur, pas seulement `replyto-comment`. Un
formulaire de commentaire AJAX frontal (thème/plugin) soumis par un modérateur
connecté sauterait aussi le contrôle. Impact faible (acteur de confiance), mais
mon commentaire « seulement le chemin cassé » était survendu. J'ai borné :

```php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Action read only to scope the bypass; the core replyto-comment handler verifies the nonce.
$ajax_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
if ( wp_doing_ajax() && 'replyto-comment' === $ajax_action && current_user_can( 'moderate_comments' ) ) {
	return $commentdata;
}
```

Pas de vérification de nonce ajoutée (comme tu le notes, le handler core
`wp_ajax_replyto_comment()` l'a déjà passée avant `wp_new_comment()`). Le
`phpcs:ignore` documente la lecture de `$_REQUEST['action']`, qui ne sert qu'à
restreindre le bypass.

### 2. Cap JS — passage à `retry: 'never'` + resets manuels bornés — ACCEPTÉ

C'est le point le plus important et tu as raison sur le fond. J'ai vérifié la
nuance : le clignotement observé à ~0,5 s **était** notre boucle `safeReset()`
(l'auto-retry Cloudflare est à 8 s par défaut via `retry-interval`, pas 0,5 s),
donc supprimer le `safeReset()` au cap supprimait bien le martèlement *observé*.
Mais ta critique tient pour le cas général : la valeur de retour de
`error-callback` n'est pas un contrat documenté pour stopper l'auto-retry. Sur un
échec **retryable** persistant, Turnstile pouvait continuer à retenter seul,
indépendamment de notre cap.

J'ai donc adopté ta proposition — le plugin possède désormais explicitement son
budget de relance :

```js
retry: 'never',
callback: function () {
	el.__tfErrors = 0;
},
// ...
'error-callback': function () {
	var n = ( el.__tfErrors || 0 ) + 1;
	el.__tfErrors = n;
	if ( n < 3 ) {
		safeReset( el );
	}
}
```

Avec `retry: 'never'`, seule l'appel explicite à `turnstile.reset()` re-tente le
challenge — donc 2 resets max, puis arrêt total. Déterministe, sans dépendre d'un
implicite de l'API. `turnstile.reset()` ré-amorce un challenge indépendamment de
`retry` (qui ne gouverne que l'auto-retry *après* échec), donc la récupération
réseau sur les 2 premières erreurs est préservée. Effet de bord positif : sans
`return true`, Turnstile affiche son état d'erreur par défaut après l'abandon — ce
qui répond aussi à ta remarque « UX silencieuse » (voir plus bas).

### 3. Commentaire « successful render » → « successful token/challenge » — ACCEPTÉ

Corrigé dans le code (`// A successful token proves the widget recovered`), dans
`CHANGELOG.md` et dans `readme.txt` (« a successful challenge clears the
counter »).

### 4. `.pot` — référence de ligne corrigée — ACCEPTÉ (avec nuance outillage)

Tu as raison : l'insertion décalait la référence de `Comment Submission Failure`.
Aucun `msgid` n'a changé (le correctif #1 n'ajoute que des commentaires PHP, le #2
que du JS sans chaîne traduisible), donc le **contenu traduisible** est inchangé.
Je n'ai pas `wp i18n make-pot` dans l'environnement (seulement `xgettext`/`msgcat`
bruts, dont la sortie divergerait du format de l'outil WP et créerait plus de
bruit que ça n'en corrige). J'ai donc corrigé l'unique annotation périmée à la
main : `class-wp-comments.php:63` → `:78`, et rafraîchi `POT-Creation-Date`. Si tu
considères que c'est insuffisant, le seul vrai « régénérer » propre passerait par
`wp i18n make-pot` au moment du packaging — à faire côté tooling de release.

### 5. Tests ciblés commentaires — AJOUTÉ

J'ai ajouté 6 tests dans `tests/run-tests.php` (+ stubs `wp_doing_ajax`,
`current_user_can`, `wp_die` qui lève une exception, `esc_html`, `esc_html__`,
`sanitize_key`, et chargement de `WP_Comments`/`Widget_Renderer` dans
`bootstrap.php`). Ils couvrent exactement ta liste et verrouillent la frontière de
sécurité du point 1 :

- `public comment without token is blocked` ✓
- `public comment with valid token passes` ✓
- `pingback is never blocked` ✓
- `moderator replyto-comment AJAX bypasses the check` ✓
- `moderator on another AJAX action is still blocked` ✓ (prouve le bornage)
- `replyto-comment without moderate_comments is still blocked` ✓

Suite complète : **73 run, 73 passed, 0 failed** ; `php -l` et `node --check` OK.

## Points que je traite sans changement de code (avec justification)

### UX après abandon — désormais traité

Avec `retry: 'never'` et l'abandon du `return true`, après le 2ᵉ reset infructueux
Turnstile affiche son **état d'erreur par défaut** : le widget n'est plus
« silencieux ». L'utilisateur voit l'échec et peut recharger. Pour une release de
maintenance dont l'objectif est de stopper le bruit réseau, c'est suffisant.

### Le nombre `3` — assumé

Arbitraire mais raisonnable, et maintenant **réellement garanti** par
`retry: 'never'` (c'est ta propre condition : « acceptable à condition que le code
possède vraiment le retry »). 2 récupérations puis arrêt.

### Boucle alternée succès/erreur — reconnu, non bloquant

Si un widget alterne succès et erreur, le compteur ne monte jamais. Je ne vends
pas le cap comme une protection contre ça : un token réussi prouve que le
formulaire peut fonctionner. Ce n'est pas le scénario du bug (échec **dur**
persistant).

### `preprocess_comment` vs hook frontal — débat moyen terme, hors 1.1.1

D'accord : `preprocess_comment` est large (REST, XML-RPC, importeurs…). Mais
maintenant que le bypass est borné à `replyto-comment`, je ne change pas de hook
dans une 1.1.1. Tu as toi-même indiqué ne pas bloquer sur ce point une fois le
bypass restreint. À reverser dans le backlog produit.

### `Tested up to: 7.0` — laissé volontairement

Le correctif ne touche pas la compatibilité WordPress ; `7.0` reste la valeur
testée pour la 1.1.0 et la version courante. Inchangé délibérément, pas oublié.

### Verbosité du changelog — choix de transparence

Je garde le détail (support/transparence). Tu as toi-même noté que ce n'est pas un
défaut technique.

## Packaging / déploiement (phase suivante, après consensus)

`.svn-wporg` est un **miroir SVN non commité** (pas un artefact Git). Le zip
`1.1.1`, la sync `trunk` et le tag `tags/1.1.1` sont produits à l'**étape de
déploiement**, pas dans le diff source. Checklist que j'exécuterai une fois le
consensus atteint :

1. Rebuild `cweb-form-protection-turnstile-elementor-1.1.1.zip` depuis le dossier
   plugin.
2. `git commit` + tag GitHub `v1.1.1` + release.
3. Sync `.svn-wporg/trunk` depuis le dossier plugin, créer `.svn-wporg/tags/1.1.1`,
   `svn commit` (Stable tag 1.1.1 déjà dans `readme.txt`).

## Plan ajusté (état actuel du diff)

- `class-wp-comments.php` : bypass borné `replyto-comment` + `moderate_comments`,
  commentaire ajusté, `phpcs:ignore` documenté.
- `assets/js/turnstile.js` : `retry: 'never'` + `callback` succès (reset compteur)
  + `error-callback` borné à 2 resets manuels puis arrêt.
- `tests/` : 6 tests + stubs (73/73).
- `cweb-form-protection-turnstile-elementor.php` : `Version`/`CWEBTS_VERSION` 1.1.1.
- `readme.txt` : Stable tag 1.1.1 + changelog (2 fixes) + upgrade notice.
- `CHANGELOG.md` : section `[1.1.1]` (wording retry:'never' / « challenge »).
- `.pot` : `Project-Id-Version` 1.1.1 + référence de ligne corrigée + date
  rafraîchie.

À mon sens il ne reste plus de désaccord substantiel. Reste à confirmer de ton
côté.
