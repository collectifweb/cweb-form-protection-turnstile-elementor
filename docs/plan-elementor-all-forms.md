# Plan : v1.1.0 — Protéger tous les formulaires Elementor (option opt-in)

> Statut : validé par confrontation Claude × Codex (consensus bilatéral,
> 2026-06-10). Historique du débat :
> `docs/archives/confront-codex-elementor-all-forms-2026-06-10-1750/`.

## Contexte

Le plugin **CWeb Form Protection with Turnstile for Elementor Forms** protège
aujourd'hui les formulaires Elementor Pro via un **champ Turnstile que
l'utilisateur ajoute formulaire par formulaire** (comme le champ reCAPTCHA natif).
C'est volontaire et c'est le différenciateur produit.

Demande : ajouter une option **décochée par défaut** qui protège **tous** les
formulaires Elementor Pro d'un coup, sans avoir à poser le champ partout. Le
par-formulaire reste le défaut et le différenciateur ; le mode global est un
raccourci opt-in (déjà annoncé dans la FAQ du `readme.txt`).

Cible : **v1.1.0** (fonctionnalité mineure), indépendante du correctif 1.0.1.
Le plugin dépend d'Elementor Pro, **non installé dans ce dépôt** : toute la partie
API Elementor Pro doit être validée sur une vraie installation avant livraison
(voir « Gate de livraison »).

## Approche

Trois moitiés : un réglage, l'affichage (injection côté serveur), la vérification
(hook global). Le tout réutilise au maximum l'existant (`Widget_Renderer::get_html()`
comme source unique du balisage, `Verifier` pour la vérification, `turnstile.js`
pour le rendu).

### 1. Réglage

- Ajouter `'protect_elementor_all_forms' => 0` dans `Settings::defaults()`.
- Ajouter `'protect_elementor_all_forms'` à la boucle de bascules de
  `Settings::sanitize()` (normalisation `0/1`).
- Nouvelle section admin « Elementor Pro forms » avec **une** case : « Protéger
  automatiquement **tous** les formulaires Elementor Pro » + description rappelant
  que, sinon, on ajoute le champ Turnstile formulaire par formulaire.
- Corriger `Settings::section_native_intro()` : son texte actuel présente le champ
  par-formulaire comme l'unique voie Elementor — le reformuler pour ne pas
  contredire la nouvelle option.

### 2. Affichage — injection côté serveur

Nouvelle classe `CWebTS\Integrations\Elementor_All_Forms`, instanciée depuis une
méthode dédiée de `Plugin` (p. ex. `register_elementor_integrations()`).
**Aucun `use ElementorPro\…` ni type-hint dur** : on accroche des hooks qui ne se
déclenchent que si Elementor Pro est présent. Le callback reste défensif (vérifier
que `$widget` est un objet et que `get_name` est callable avant de l'appeler).

Hook : `add_filter( 'elementor/widget/render_content', …, 10, 2 )` — signature
`($content, $widget)`. Logique :

1. Bail si `! is_configured()` ou option `protect_elementor_all_forms` à 0.
2. Bail si `$widget->get_name() !== 'form'`.
3. Bail si `$content` contient déjà notre signature `cwebts-widget` (anti-doublon
   avec le champ par-formulaire — on cherche **notre** marque, pas `cf-turnstile`
   nu, qui peut venir d'ailleurs).
4. `$renderer->enqueue()` (voir §4 pour la stratégie complète d'enqueue).
5. Insérer le widget via une méthode pure et testable :
   `inject_widget_before_submit( $content, $widget_html ) : string`.

Le widget injecté = `get_html(['action' => 'elementor_form'])` **enveloppé d'un
wrapper de champ Elementor** pour épouser la grille (alignement, espacement,
responsive) :

```html
<div class="elementor-field-group elementor-column elementor-col-100 cwebts-elementor-auto-field">
  <!-- get_html(['action' => 'elementor_form']) -->
</div>
```

`get_html()` reste la **source unique** du markup du widget ; le wrapper Elementor
est à part.

**Point d'insertion (repli strictement DANS le `<form>`) :**
1. avant le wrapper `.elementor-field-type-submit` si trouvé ;
2. sinon immédiatement avant `</form>` ;
3. sinon **ne rien injecter** (cas non supporté).

Règle absolue : ne **jamais** injecter le widget hors du `<form>`. Un widget hors
formulaire ne soumet pas le champ caché `cf-turnstile-response` → la validation
globale bloquerait toutes les soumissions (affichage trompeur + blocage silencieux).
Ne rien injecter est toujours préférable à injecter au mauvais endroit.

### 3. Vérification — hook global

Hook : `add_action( 'elementor_pro/forms/validation', …, 10, 2 )` — signature
`($record, $ajax_handler)`. Se déclenche sur **chaque** soumission de form
Elementor, indépendamment des champs. Logique :

1. Bail si `! is_configured()` ou option à 0.
2. Bail si `record_has_turnstile_field( $record )` — le formulaire contient déjà le
   champ Turnstile, donc le chemin « champ » s'en charge (évite double vérif et
   double message d'erreur). Méthode testable :
   ```php
   private function record_has_turnstile_field( $record ) {
       $fields = is_callable( array( $record, 'get' ) ) ? (array) $record->get( 'fields' ) : array();
       foreach ( $fields as $field ) {
           if ( isset( $field['type'] ) && 'turnstile' === $field['type'] ) {
               return true;
           }
       }
       return false;
   }
   ```
   (`$record->get_field(['type'=>'turnstile'])` n'est utilisé comme raccourci que si
   un dump réel le confirme.)
3. Lire `$_POST['cf-turnstile-response']` (même assainissement que le champ :
   `sanitize_text_field( wp_unslash( … ) )`, avec le `phpcs:ignore` nonce existant —
   c'est un jeton captcha, pas une action gardée par nonce).
4. `$verifier->verify( $token, null, 'elementor_form' )`.
5. Sur échec, erreur **niveau formulaire**, avec garde de compatibilité :
   ```php
   if ( method_exists( $ajax_handler, 'add_error_message' ) ) {
       $ajax_handler->add_error_message( $message );
   } else {
       $ajax_handler->add_error( 'cwebts_turnstile', $message );
   }
   ```

Pas de garde anti-doublon par hash de token dans ce handler : le `Verifier` cache
déjà la réponse `siteverify` par hash de token pour la requête courante (un seul
appel réseau même si deux chemins vérifient le même jeton). Le vrai rempart est le
bailout `record_has_turnstile_field()`. Si — et seulement si — les tests réels
montrent que le hook se déclenche deux fois pour un même record, ajouter un garde
**minimal orienté record/requête** pour éviter les messages doublés (pas un garde
par token).

### 4. Chargement des scripts (enqueue)

Le helper `turnstile.js` + l'API Cloudflare sont enregistrés/enfilés par
`Widget_Renderer::enqueue()` (idempotent, en footer, rendu explicite via
`render=explicit&onload=cwebtsOnload`).

**Quand le mode global est actif et configuré, élargir l'enqueue au front-end**
(via `wp_enqueue_scripts`) au lieu de le coupler au seul `render_content`. Raison :
le `MutationObserver` (§5) ne peut rendre un form arrivé tard (popup/AJAX) que si
les assets sont **déjà** présents ; or une page sans form au rendu initial n'aurait
rien enfilé. Élargir ferme ce trou.

Coût assumé et documenté : sous le mode opt-in « tous les forms », l'API Cloudflare
(différée) se charge sur les pages front même sans widget initial. Le mode par
défaut (par-formulaire) garde son enqueue ciblé inchangé. `renderAll()`/`api.js` ne
rendent rien en l'absence de `.cf-turnstile` : le surcoût réel est une requête
différée mise en cache.

### 5. Rendu tardif — popups, lazy-load, AJAX

Petit ajout dans `assets/js/turnstile.js` : un `MutationObserver` sur
`document.body` (`childList + subtree`), **anti-rebond**, qui replanifie un seul
`renderAll()` par rafale quand un nœud ajouté **est ou contient** un
`.cf-turnstile:not([data-tf-rendered])`.

- `renderAll()` reste idempotent (no-op s'il n'y a rien de neuf).
- Si `window.turnstile` n'est pas encore chargé, ne rien faire : `cwebtsOnload`
  rendra les widgets présents quand l'API arrive.
- `elementor/popup/show` peut appeler le même planificateur en complément, mais
  l'observateur est le mécanisme de référence (les événements ciblés sont trop
  étroits : ils ratent les fragments AJAX génériques).

Sans ça, un form rendu après le `onload` Cloudflare aurait son `<div>` injecté mais
jamais rendu, pendant que la validation globale tourne → blocage silencieux. Cet
ajout, combiné à l'enqueue élargi (§4), ferme cette classe de bug.

## Étapes d'implémentation

1. **Réglage** : `defaults()` + `sanitize()` + section « Elementor Pro forms » +
   correction de `section_native_intro()`.
2. **Classe `Elementor_All_Forms`** : hook `render_content` (affichage),
   `inject_widget_before_submit()` (pure/testable), wrapper Elementor, enqueue.
3. **Enqueue élargi** sous le mode global (`wp_enqueue_scripts` quand option ON +
   configuré).
4. **Validation** : hook `elementor_pro/forms/validation`, `record_has_turnstile_field()`,
   vérif, erreur niveau form gardée par `method_exists`.
5. **JS** : `MutationObserver` anti-rebond dans `turnstile.js`.
6. **Tests** (dans `tests/`, sans Elementor Pro) :
   - `inject_widget_before_submit()` : insertion avant `.elementor-field-type-submit` ;
     repli avant `</form>` ; contenu inchangé si aucun `<form>` ; pas de double
     insertion si `cwebts-widget` présent ; wrapper présent ; plusieurs forms
     (plusieurs appels du filtre).
   - `record_has_turnstile_field()` : vrai si `type==='turnstile'`, faux sinon
     (avec un faux record/tableau).
   - `sanitize()` : la nouvelle bascule se normalise en `0/1`.
7. **i18n** : régénérer/mettre à jour
   `languages/cweb-form-protection-turnstile-elementor.pot` (nouvelles chaînes admin).
8. **Readme** : mettre à jour Description **et** Features **et** FAQ (« Does it
   protect every Elementor form automatically? ») pour présenter : par défaut =
   champ par formulaire ; option avancée = protection automatique de tous les forms
   Elementor Pro. Garder le différenciateur.
9. **Version/livraison** : bump **1.1.0** aux 3 endroits (en-tête, `CWEBTS_VERSION`,
   `Stable tag`) ; entrées changelog `readme.txt` **et** `CHANGELOG.md` ; rebuild du
   zip ; déploiement SVN `tags/1.1.0` (après que 1.0.1 soit en ligne).

## Gate de livraison (BLOQUANT — vraie installation Elementor Pro)

À valider avant 1.1.0, avec les clés de test Cloudflare
`1x00000000000000000000AA` / `1x0000000000000000000000000000000AA` :

- `elementor/widget/render_content` se déclenche sur le widget Form et
  `get_name() === 'form'` (test rapide : `error_log($widget->get_name())`).
- Le `$content` filtré contient bien le markup complet du form (dont le wrapper
  submit).
- `Form_Record::get('fields')` expose `type === 'turnstile'` pour notre champ.
- `Ajax_Handler::add_error_message()` existe et affiche bien l'erreur niveau form.
- Scénarios : formulaire normal ; **multi-étapes** (widget avant le submit final) ;
  **popup** ; **plusieurs forms sur une page** ; et surtout **page sans form initial
  + form chargé en popup/AJAX** (vérifier que les assets sont présents et que le
  widget se rend — c'est le cas le plus à risque de blocage silencieux).

## Points de vigilance

- **Couplage au markup Elementor** : le wrapper de champ (`elementor-col-100`, etc.)
  et l'ancrage `.elementor-field-type-submit` sont des conventions Elementor, pas
  des garanties stables. Surface de fragilité à surveiller à chaque montée de
  version d'Elementor Pro.
- **Blocage silencieux** : la combinaison « affichage manquant + validation qui
  tourne » est exactement le bug corrigé en 1.0.1. Les §4 (enqueue élargi) et §5
  (MutationObserver) existent pour l'éviter ; le gate manuel doit le prouver.
- **Empreinte** : le mode global charge l'API Cloudflare plus largement (assumé,
  documenté, limité au mode opt-in).

## Décisions explicitement écartées

- **Injection 100 % JS** (parcourir les `.elementor-form` et insérer le widget côté
  navigateur) : écartée comme voie principale — duplique le markup côté JS, flash
  visuel possible, déplace la fragilité dans le navigateur. L'injection serveur
  réutilise `get_html()` et un seul hook gère affichage + enqueue.
- **Garde anti-doublon par hash de token dans le handler global** : écarté, le cache
  par-jeton du `Verifier` couvre déjà ce risque ; un second garde le dupliquerait et
  pourrait masquer un message d'erreur légitime au second passage.
- **Support de deux plugins Turnstile sur le même formulaire** : hors périmètre
  (les deux utiliseraient le champ standard `cf-turnstile-response` avec des site
  keys différentes). Limite documentée : ne pas activer deux plugins Turnstile sur
  les mêmes formulaires Elementor.
- **Forcer le champ dans tous les forms par programmation** (manipuler la définition
  des forms) : trop intrusif et fragile.
- **Un réglage par type de form** : sur-ingénierie pour une v1.1.0 ; une seule case
  suffit.
