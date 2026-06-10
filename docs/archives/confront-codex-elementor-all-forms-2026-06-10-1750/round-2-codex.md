# Round 2 — Contre-réponse Codex

## Points résolus depuis le round précédent

### Repli strictement dans le `<form>`

Validé. C'est exactement le durcissement attendu : insertion avant le wrapper submit, sinon avant `</form>`, sinon no-op. Ne rien injecter est préférable à injecter hors formulaire, parce qu'un widget hors `<form>` produit un affichage trompeur et une validation bloquante.

### Wrapper de champ Elementor

Validé. Le wrapper :

```html
<div class="elementor-field-group elementor-column elementor-col-100 cwebts-elementor-auto-field">
  <!-- get_html(['action' => 'elementor_form']) -->
</div>
```

est bien ce que je voulais : `Widget_Renderer::get_html()` reste la source unique du widget Turnstile, et le wrapper ne sert qu'à épouser la grille Elementor.

### Détection du champ via `$record->get('fields')`

Validé. C'est la bonne interprétation de ma critique. Ne pas coder `get_field(['type' => 'turnstile'])` sans dump réel. La méthode dédiée `record_has_turnstile_field()` est le bon endroit pour isoler cette hypothèse et la tester.

### `method_exists()` sur `add_error_message()`

Validé. Le fallback vers `add_error('cwebts_turnstile', ...)` évite un fatal sur une version Elementor Pro où l'API d'erreur globale diffère. L'UX est moins propre, mais le comportement reste sûr.

### Signature `cwebts-widget` pour l'anti-doublon d'affichage

Validé. On cherche notre signature, pas `cf-turnstile` nu. C'est important : `cf-turnstile` identifie Cloudflare, pas ce plugin.

### Méthode d'injection testable

Validé. `inject_widget_before_submit( $content, $widget_html )` est le bon découpage. Les tests proposés couvrent les vrais risques : point d'insertion, fallback dans le form, absence de form, anti-doublon, wrapper, et répétition par appel du filtre.

### i18n et readme élargi

Validé. Le `.pot` doit être mis à jour. Le readme doit être modifié dans la description, les features et la FAQ, pas seulement dans une phrase isolée, sinon le produit devient contradictoire.

### Renommage du réglage

Validé. `protect_elementor_all_forms` est meilleur que `protect_all_elementor`. Le nom est plus lisible dans la famille `protect_*` et il vaut mieux le stabiliser avant publication.

### Classe dédiée sans type-hints Elementor durs

Validé. `CWebTS\Integrations\Elementor_All_Forms` est préférable à une grosse méthode dans `Plugin`. Petite précision d'implémentation : même sans type-hint dur, le callback de rendu devrait rester défensif avant d'appeler le widget, par exemple vérifier que `$widget` est un objet et que `get_name` est callable. Ce n'est pas un désaccord, juste la même prudence que pour le reste de l'intégration.

### Gate de test Elementor Pro bloquant

Validé. Ce n'est pas une note de prudence, c'est une condition de livraison. Sans vraie installation Elementor Pro, on ne peut pas valider le hook de rendu, le HTML filtré, `get_name() === 'form'`, le contenu de `Form_Record`, l'erreur Ajax globale, le multi-step, les popups et plusieurs formulaires.

## Points encore en débat avec ma position actualisée

### Concession JS : `MutationObserver` global anti-rebond

Je tranche pour le `MutationObserver` global anti-rebond comme mécanisme principal.

Les événements Elementor ciblés, notamment `elementor/popup/show`, sont trop étroits comme garantie principale. Ils couvrent une famille de popups, mais pas les fragments AJAX génériques, les widgets ajoutés par d'autres mécanismes Elementor, ni les intégrations qui insèrent du contenu après le chargement de Cloudflare. L'observer regarde le fait important : un conteneur `.cf-turnstile` non rendu vient d'entrer dans le DOM.

Conditions d'implémentation :

- observer `document.body` en `childList + subtree`, mais coalescer les mutations avec un anti-rebond ;
- ne pas faire un scan lourd à chaque mutation : planifier un seul `renderAll()` par rafale, idéalement seulement quand un noeud ajouté est ou contient un `.cf-turnstile:not([data-tf-rendered])` ;
- garder `renderAll()` idempotent, comme aujourd'hui ;
- si `window.turnstile` n'est pas encore chargé, ne rien faire : le callback `cwebtsOnload` rendra ensuite les widgets présents ;
- un hook `elementor/popup/show` peut appeler le même scheduler en complément, mais il ne doit pas être le mécanisme de référence.

Recadrage important : le `MutationObserver` ne peut pas rendre un widget tardif si `turnstile.js` et l'API Cloudflare n'ont jamais été chargés sur la page. Donc le gate manuel doit inclure un cas popup/AJAX sur une page sans widget Turnstile initial. Si Elementor charge le fragment mais pas les assets enqueued pendant l'AJAX, il faudra soit élargir la stratégie d'enqueue quand `protect_elementor_all_forms` est actif, soit documenter explicitement cette limite. Je ne bloque pas le mécanisme du `MutationObserver`, mais je veux que ce cas soit testé, parce que c'est exactement la classe de bug silencieux qu'on veut éviter.

### Nuance A : le wrapper Elementor est un couplage

D'accord. Le wrapper est un couplage assumé, pas une abstraction stable garantie. Il est acceptable parce qu'il améliore fortement le placement visuel et parce qu'il est testé/documenté comme surface fragile avec l'ancrage submit.

### Nuance B : coexistence avec un autre plugin Turnstile

D'accord pour le sortir du périmètre. Supporter deux plugins Turnstile sur le même formulaire Elementor demanderait une vraie politique de coexistence autour du champ standard `cf-turnstile-response`, des site keys et de l'ordre des widgets. Ce n'est pas une correction locale.

La bonne position est donc :

- anti-doublon d'affichage sur `cwebts-widget`, pour éviter de dupliquer notre propre widget ;
- limite documentée : ne pas activer deux plugins Turnstile sur les mêmes formulaires Elementor ;
- pas de code défensif complexe pour un scénario que le produit ne promet pas.

### Désaccord : garde par hash de token dans le handler global

J'accepte ta position. Je n'ai pas de cas concret où le cache du `Verifier` ne suffirait pas pour le risque qui m'inquiétait : le double appel réseau à Cloudflare avec le même token.

Après relecture du code réel, `Verifier` cache la réponse brute de `siteverify` par hash de token, dans la requête PHP courante. Donc si deux chemins appellent `verify()` avec le même token, le second ne rappelle pas Cloudflare et ne peut pas déclencher un faux `timeout-or-duplicate`. Le cache est aussi placé au bon niveau : les contrôles locaux optionnels comme `expected_action` restent appliqués à chaque appel sur la réponse brute.

Le vrai rempart fonctionnel reste donc :

- côté affichage : ne pas injecter notre widget si `cwebts-widget` existe déjà ;
- côté validation globale : sortir si `record_has_turnstile_field()` détecte le champ Turnstile du formulaire.

Un garde par hash dans le handler global dupliquerait le rôle du `Verifier`. Pire, s'il est codé comme un simple "déjà vu donc return", il pourrait masquer l'ajout d'une erreur sur un second passage invalide. Si les tests réels montrent que `elementor_pro/forms/validation` se déclenche deux fois pour le même record, je préfère un garde minimal piloté par ce constat, plutôt orienté record/requête pour éviter les messages doublés. Je ne demande plus de garde par token hash dans le handler global.

## Evaluation globale : reste-t-il des désaccords ? Le plan est-il prêt ?

Il ne reste pas de désaccord d'architecture.

Le plan ajusté est prêt pour implémentation avec trois points à garder comme critères de sortie, pas comme détails :

- le `MutationObserver` est le choix principal pour le rendu tardif, avec test explicite d'un popup/AJAX sans widget initial pour vérifier que les assets sont bien chargés ;
- les tests unitaires doivent couvrir l'injection HTML et la détection du champ via record ;
- le gate Elementor Pro manuel reste bloquant avant livraison 1.1.0.

Avec ces précisions, je suis d'accord avec le plan final.

CONSENSUS_ATTEINT
