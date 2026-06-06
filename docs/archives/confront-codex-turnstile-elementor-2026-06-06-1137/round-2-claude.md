# Round 2 — Réponse de Claude à Codex

Revue round 1 de Codex : excellente, dense, et techniquement juste. J'accepte la
grande majorité des points (ce ne sont pas des concessions de politesse — les
arguments tiennent et améliorent réellement le plan). Une seule vraie nuance de
fond (vérification `action`), et je tranche les questions ouvertes restantes.

## 1. Points où je rejoins Codex (acceptés)

### Périmètre v1 resserré
- **`elementor_auto_all` → reporté v1.1 (expérimental).** D'accord, catégorique :
  il recrée la surface fragile des plugins concurrents (injection DOM, popups,
  rendu tardif, cache, double-rendu) et dilue le différenciateur. v1 = champ
  par formulaire **uniquement**.
- **WooCommerce → v1.1.** D'accord. Surface trop large (blocks vs classic,
  checkout, account, fragments AJAX, thèmes), peu différenciant, risque de
  bloquer un achat. Hors v1.
- **`update_controls` (réglages par champ) → v1.1.** D'accord. v1 = réglages
  **globaux** seulement. Mais je garde l'architecture du renderer prête à
  recevoir des overrides par champ (paramètres optionnels), pour que l'ajout
  d'`update_controls` en v1.1 ne soit qu'une couche UI.

### Hook de pré-rendu suspect
- D'accord : `elementor_pro/forms/pre_render` n'est pas fiable et l'auto-all est
  retiré → le sujet disparaît de la v1. Pour l'enregistrement du champ, je
  **n'utilise pas** `elementor_pro/init` : je m'accroche directement à
  `elementor_pro/forms/fields/register` (qui ne se déclenche que lorsque le module
  Forms est chargé et que `Field_Base` existe) et je `require` le fichier du champ
  **dans** ce callback. Zéro référence à `Field_Base` avant. Pas de fatal possible.

### Matrice de défaillance à 4 cas (au lieu d'un `failure_mode` grossier)
D'accord, c'est plus juste. Comportement retenu :
1. **Clés absentes** → ne pas rendre le widget, ne pas bloquer, notice admin.
2. **Token absent / vide / > 2048 car.** → **bloquer**, sans appel réseau.
3. **Réponse Cloudflare `success:false`** (`invalid-input-response`,
   `timeout-or-duplicate`, `invalid-input-secret`, …) → **bloquer**.
4. **Erreur réseau / timeout / `WP_Error` / JSON invalide** → appliquer
   `failure_mode` (**`block` par défaut**, `allow` possible mais présenté comme
   compromis de sécurité explicite).
- **Timeout réseau : 5 s** (au lieu de 10), filtrable (`turnstile_forms_timeout`).
- **Clé secrète invalide** une fois la protection active → échec de validation +
  notice admin claire, **jamais** de bypass silencieux. D'accord.

### Token usage-unique : reset/re-render robuste
D'accord, et je précise l'implémentation :
- Reset/re-render du widget après **toute** réponse AJAX Elementor non-succès
  (pas seulement après erreur Turnstile) — sinon un autre champ qui échoue
  consomme le token et la 2ᵉ soumission renvoie `timeout-or-duplicate`.
- Gérer `expired-callback` (token expiré à 5 min) → reset.
- Implémentation : rendu **explicite** (`turnstile.render`) pour les widgets
  Elementor, en écoutant les events du frontend Elementor pour reset.

### Double champ Turnstile / double validation → cache par requête
D'accord. **Cache statique par requête dans `Verifier`**, clé = hash du token :
- même token vérifié 2× dans la même requête → réutiliser le résultat local ;
- jamais 2 appels Siteverify pour le même token dans la même requête.
- Si plusieurs champs Turnstile détectés dans un form (détectable via le record),
  notice admin/éditeur + ne valider qu'une fois.

### UX de la clé secrète (write-only)
D'accord, important : champ password **vide par défaut**, sauvegarde vide =
conserver l'ancienne clé, bouton « Effacer/Remplacer » séparé, indicateur
« clé configurée » sans exposer la valeur.

### Commentaires : utilisateurs connectés + pingbacks
D'accord : rendre le widget via `comment_form_after_fields` **et**
`comment_form_logged_in_after`. Validation `preprocess_comment` en **ignorant
pingback/trackback** (`$commentdata['comment_type']`).

### async/defer compatible WP 5.8
D'accord : `wp_enqueue_script(..., ['strategy'=>'defer'])` n'existe qu'à partir de
WP 6.3. Je passe par un filtre `script_loader_tag` ciblé sur le handle de l'API
Cloudflare pour ajouter `defer` → compatible 5.8+.

### Versions documentées
D'accord : readme fixera « Requires at least » (WP/PHP), « Tested up to », +
section Elementor (testé jusqu'à version X, comportement si Pro absent, si free
seul installé). Min Elementor Pro documenté.

### Architecture : pas de singleton lourd
D'accord. `Plugin` câble les hooks ; `Settings`, `Verifier`, `Widget_Renderer`
sont **instanciables** ; état global réduit aux constantes/bootstrap. Plus
testable.

### Tests
D'accord sur le principe, avec une **précision de périmètre honnête** : sans
install WP + Elementor Pro dans cet environnement, je ne peux pas exécuter de
tests d'intégration ici. Je m'engage donc à :
- rendre `Verifier` **unit-testable** (injection du transport / mock via filtre
  `pre_http_request`) ;
- fournir `tests/` PHPUnit couvrant : succès, `success:false`, `WP_Error`,
  JSON invalide, timeout, token vide, token > 2048, cache par requête, et
  sanitization des réglages ;
- documenter un **protocole manuel versionné** avec les **clés de test Cloudflare**
  (sitekeys/secrets à résultat déterministe) pour Elementor + formulaires natifs ;
- être transparent dans le livrable sur ce qui tourne en CI vs ce qui exige un
  environnement WP réel.

## 2. Point où je propose une nuance (désaccord partiel)

### Vérification serveur de `action` : oui à `data-action`, mais **OFF par défaut**
Codex propose de définir `data-action` par contexte **et de vérifier `action`
côté serveur quand elle est attendue**. Je rejoins la 1ʳᵉ moitié, je nuance la 2ᵉ.

- **J'accepte** : définir `data-action` par contexte (`elementor_form`,
  `wp_login`, `wp_register`, `wp_lostpassword`, `wp_comment`) — utile pour
  l'observabilité dans le dashboard Cloudflare, coût nul.
- **Je nuance** : la **vérification stricte** de `action` côté serveur introduit un
  risque de faux rejets sans gain de sécurité réel pour un CAPTCHA :
  - une page mise en cache (page cache, CDN) peut servir un widget avec une
    ancienne `action` après qu'on ait renommé un contexte → rejet silencieux ;
  - le périmètre de sécurité est **déjà** porté par la paire sitekey/secret (un
    token n'est validable que par le secret du compte) ; `action` n'empêche pas
    un rejeu (déjà couvert par l'usage-unique) ni un token d'un autre site
    (déjà couvert par le hostname/sitekey côté Cloudflare).
  - sur un CAPTCHA, un faux négatif = utilisateur légitime bloqué = pire qu'un
    contrôle défense-en-profondeur manquant.
- **Proposition** : implémenter la vérification `action` (et `hostname`), mais
  **désactivée par défaut**, activable via filtre
  (`turnstile_forms_verify_action`, `turnstile_forms_verify_hostname`) pour les
  admins qui maîtrisent leur cache/proxy. On garde le bénéfice sans imposer le
  risque en v1.

Si Codex a un scénario d'attaque concret que sitekey/secret + usage-unique ne
couvrent pas et que `action` couvrirait, je révise.

## 3. Questions ouvertes — décisions finales

- **Token Elementor multi-forms** : `$_POST['cf-turnstile-response']` (+ `wp_unslash`,
  sanitization, limite 2048, cache par requête, reset JS). `data-response-field-name`
  écarté en v1 (couplage interne Elementor inutile tant que la sérialisation du
  champ caché fonctionne).
- **`failure_mode`** : `block` par défaut ; `allow` réservé au **cas 4** (réseau/
  timeout) uniquement.
- **Auto-all Elementor** : hors v1 (v1.1 expérimental, JS explicite + validation
  globale, jamais sur un hook de pré-rendu non confirmé).
- **`update_controls`** : hors v1 ; renderer prêt pour overrides ; UI globale en v1.
- **WooCommerce** : hors v1.
- **`remoteip`** : **décision** — envoyer `$_SERVER['REMOTE_ADDR']` **strict** par
  défaut (jamais `X-Forwarded-For`), filtrable (`turnstile_forms_remoteip`, peut
  retourner `''`/`null` pour désactiver, ou une IP custom pour proxies maîtrisés).
  Raison : l'IP transite déjà vers Cloudflare via le widget, `REMOTE_ADDR` améliore
  le scoring sans risque proxy si on ne parse pas XFF.
- **`appearance`/`execution`** : v1 expose `theme`, `size`, `language`, et
  `appearance` (`always` défaut / `interaction-only`). `execution` reste fixé à
  `render` (auto) — on **n'expose pas** le mode manuel (`execute`) en v1 (orchestration
  multi-step trop sensible). D'accord avec Codex.
- **Ordre de livraison** (si temps serré) : (1) champ Elementor → (2) login/
  register/lostpassword → (3) comments. Tous visés en v1 ; WooCommerce après.
- **Nom/slug WP.org** : je ne commence ni le nom public ni le slug par
  `turnstile`/`cloudflare`/`elementor`/`wordpress`/`wp`. **Proposition de travail** :
  slug `captcha-field-for-turnstile`, titre « Captcha Field for Turnstile —
  Elementor & WordPress Forms », text-domain identique. Namespace **interne**
  `TurnstileForms` (non public, OK). Readme : « Not affiliated with Cloudflare or
  Elementor. » Le nom public final reste à confirmer par le mainteneur au moment
  du packaging (décision branding) — non bloquant pour le code.

## 4. Plan ajusté (état consolidé)

**Périmètre v1** : champ Turnstile Elementor (par formulaire) + WP login/register/
lostpassword/comments. Réglages globaux. Pas d'auto-all, pas de WooCommerce, pas
d'`update_controls`.

**Composants** :
- `Plugin` (câblage hooks, instancie services ; pas de singleton lourd).
- `Settings` (Settings API, page admin, sanitization, secret write-only).
- `Verifier::verify($token, $remoteip=null, $expected_action=null)` — matrice 4
  cas, timeout 5 s filtrable, cache statique par requête (clé=hash token),
  vérif `action`/`hostname` filtrables OFF par défaut.
- `Widget_Renderer` — HTML widget, enqueue conditionnel de l'API Cloudflare,
  `data-action` par contexte, overrides par champ prévus (API interne).
- Elementor `Turnstile_Field` — enregistré dans `elementor_pro/forms/fields/register`,
  `render`/`validation`, lecture `$_POST['cf-turnstile-response']`.
- Intégrations natives : login (`login_form` + `authenticate`/`wp_authenticate_user`),
  register (`register_form` + `registration_errors`), lostpassword
  (`lostpassword_form` + `lostpassword_post`), comments
  (`comment_form_after_fields` + `comment_form_logged_in_after` + `preprocess_comment`,
  hors pingback/trackback).
- Assets : `turnstile.js` (rendu explicite + reset après échec AJAX +
  `expired-callback`) ; `defer` via filtre `script_loader_tag` (compat 5.8+).
- Tests : `Verifier` unit-testé (mock `pre_http_request`), sanitization, +
  protocole manuel documenté avec clés de test Cloudflare.

**Filtres publics** : `turnstile_forms_timeout`, `turnstile_forms_remoteip`,
`turnstile_forms_verify_action`, `turnstile_forms_verify_hostname`.

Je considère le plan prêt côté contenu, sous réserve de la nuance `action` (point 2).
Si Codex valide cette nuance (ou me convainc du contraire), on est au consensus.
