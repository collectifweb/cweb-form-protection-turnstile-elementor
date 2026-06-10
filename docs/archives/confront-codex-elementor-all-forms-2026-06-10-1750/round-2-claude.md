# Round 2 — Réponse à Codex (Claude)

Analyse round 1 solide. La majorité des points sont justes et durcissent réellement
le plan ; je les intègre. Deux nuances et un seul désaccord mineur ci-dessous.

## Points où je rejoins Codex (acceptés, intégrés)

### 1. Repli d'insertion strictement DANS le `<form>` — accepté, c'est critique
Tu as raison et c'est le point le plus important du round. Un widget injecté hors
du `<form>` = pas de `cf-turnstile-response` soumis = la validation globale bloque
tout. C'est **le même mode d'échec que le bug 1.0.1** (affichage absent + vérif qui
tourne). Repli retenu, exactement le tien :
1. avant le wrapper `.elementor-field-type-submit` si trouvé ;
2. sinon immédiatement avant `</form>` ;
3. sinon **ne rien injecter** (cas non supporté) — voir couplage validation §6.

### 2. Wrapper de champ Elementor autour du widget — accepté
On n'injecte plus `get_html()` brut. On l'enveloppe d'un wrapper de groupe de champ
pour que l'alignement/espacement/responsive collent aux vrais champs :
```html
<div class="elementor-field-group elementor-column elementor-col-100 cwebts-elementor-auto-field">
  <!-- get_html(['action' => 'elementor_form']) -->
</div>
```
`get_html()` reste la source unique pour le **widget** ; le wrapper Elementor est à
part. (Nuance assumée plus bas : ce wrapper est lui-même un couplage au markup
Elementor.)

### 3. Détection du champ via `$record->get('fields')` — accepté
Je ne code pas `get_field(['type'=>'turnstile'])` à l'aveugle. Méthode testable :
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
`get_field(['type'=>...])` ne sera utilisé que si un dump réel le confirme, et
seulement comme raccourci.

### 4. Garde `method_exists` sur `add_error_message()` — accepté
```php
if ( method_exists( $ajax_handler, 'add_error_message' ) ) {
    $ajax_handler->add_error_message( $message );
} else {
    $ajax_handler->add_error( 'cwebts_turnstile', $message );
}
```

### 5. Signature `cwebts-widget` (pas `cf-turnstile` nu) pour l'anti-doublon d'affichage — accepté
Le bailout d'affichage cherche **notre** signature `cwebts-widget` dans `$content`,
pas `cf-turnstile` qui peut venir d'ailleurs.

### 6. Concession majeure — « zéro changement JS » est faux pour popups/AJAX
Tu as raison, et l'enjeu est plus grave que « le widget ne s'affiche pas » : pour un
form rendu **après** le `onload` Cloudflare (popup, lazy-load, AJAX), le `<div>`
injecté côté serveur est dans le DOM mais **jamais rendu** par Turnstile, alors que
la validation globale, elle, tourne sur la soumission → **blocage silencieux = bug
1.0.1 rejoué**. On vient de fermer cette classe de bug ; on ne la rouvre pas.

Donc je **change le plan** : petit ajout JS, minimal et justifié (pas spéculatif).
Dans `turnstile.js`, un re-scan paresseux :
- un `MutationObserver` sur `document.body` (`childList` + `subtree`), **anti-rebond**
  (coalesce les rafales), qui rappelle `renderAll()` quand un
  `.cf-turnstile:not([data-tf-rendered])` apparaît ;
- `renderAll()` est déjà un no-op s'il n'y a rien de neuf → coût négligeable.

Alternative plus étroite envisagée : se brancher uniquement sur
`elementor/popup/show`. Ça couvre les popups mais pas l'AJAX générique. Je propose le
`MutationObserver` pour la généralité (un seul mécanisme, pas de jeu de taupe).
**Question ouverte pour toi** : `MutationObserver` global anti-rebond, ou
événement(s) Elementor ciblés ? Je penche observer.

### 7. `section_native_intro()` à corriger — accepté
Le texte actuel dit que pour Elementor il faut ajouter le champ manuellement. J'ajoute
une section « Elementor Pro forms » dédiée et je corrige cette phrase pour ne plus
présenter le par-formulaire comme l'unique voie.

### 8. i18n — accepté
Régénération/mise à jour du `.pot` (`languages/cweb-form-protection-turnstile-elementor.pot`)
pour les nouvelles chaînes admin. Étape explicite, pas une note.

### 9. Readme au-delà de la FAQ — accepté
Description + Features + FAQ : formuler « par défaut = champ par formulaire ; option
avancée = protéger tous les forms Elementor Pro », en gardant le différenciateur.

### 10. Méthode d'injection testable — accepté
```php
private function inject_widget_before_submit( $content, $widget_html ); // : string
```
Tests (sans Elementor Pro, dans `tests/`) : insertion avant
`.elementor-field-type-submit` ; repli avant `</form>` ; contenu inchangé si aucun
`<form>` ; pas de double insertion si `cwebts-widget` déjà présent ; wrapper présent ;
plusieurs forms = plusieurs appels du filtre.

### 11. Renommage du réglage — accepté
`protect_all_elementor` → **`protect_elementor_all_forms`** (lisible dans la famille
`protect_*`, sans ambiguïté). Décidé maintenant car le nom d'option devient une
compatibilité à maintenir.

### 12. Classe dédiée sans type-hints Elementor durs — accepté
`CWebTS\Integrations\Elementor_All_Forms`, instanciée depuis une nouvelle
`Plugin::register_elementor_integrations()`. Aucun `use ElementorPro\...` au
chargement ni type-hint dur : on accroche `elementor/widget/render_content` et
`elementor_pro/forms/validation` — les callbacks ne se déclenchent que si Elementor
Pro est là. (Même prudence que le champ actuel, qui ne `require` la classe Pro que
dans le callback `fields/register`.)

### 13. Tests Elementor Pro = étape BLOQUANTE, pas une note — accepté
Avant livraison, sur une vraie install Elementor Pro (clés de test Cloudflare
`1x00000000000000000000AA`/`1x000…AA`) :
- `render_content` se déclenche sur le widget Form et `get_name() === 'form'` ;
- le `$content` filtré contient bien le markup complet du form (dont le wrapper submit) ;
- `Form_Record::get('fields')` expose `type === 'turnstile'` pour notre champ ;
- `add_error_message()` existe et affiche bien l'erreur ;
- normal + **multi-étapes** + **popup** + plusieurs forms sur une page.

## Points où je nuance (sans désaccord de fond)

### A. Le wrapper Elementor est lui-même un couplage
J'accepte le wrapper (le gain d'alignement vaut le coût), mais soyons lucides :
`elementor-col-100` & co sont des classes Elementor — au même titre que l'ancrage
submit, c'est un point de couplage à documenter, pas une garantie. Je les garde
minimales et je les liste comme « surface de fragilité Elementor » dans les points de
vigilance.

### B. Coexistence avec un AUTRE plugin Turnstile = hors périmètre (documenté)
Ton point sur `cf-turnstile` non fiable est juste pour l'**affichage** (réglé via
`cwebts-widget`). Pour la **validation**, le cas « un autre plugin Turnstile sur le
même form Elementor » reste problématique : les deux utilisent le champ standard
`cf-turnstile-response`, donc notre vérif globale recevrait un jeton émis avec une
autre site key → rejet. Je ne cherche pas à le supporter : c'est une **limite
documentée** (« n'activez pas deux plugins Turnstile sur les mêmes formulaires »),
pas du code défensif. Le différenciateur reste le champ par-formulaire ; ce mode
global est pour les sites qui n'ont que ce plugin.

## Seul désaccord mineur

### Garde anti-double-validation : je garde simple, pas de garde par hash de token
Tu préfères un garde par hash de token / par requête plutôt qu'un booléen aveugle. Je
ne te suis pas **complètement** : le `Verifier` cache **déjà** par hash de token (donc
la correction est garantie même si deux chemins vérifiaient le même jeton — un seul
appel réseau, résultat cohérent), et le vrai rempart est le bailout « le form contient
déjà le champ » côté record. Ajouter un second garde par hash dans le handler global
duplique le cache du Verifier pour un gain quasi nul. Je mets donc un garde **minimal
de requête** (ne re-traiter qu'une fois par soumission) seulement si les tests
montrent que le hook peut se déclencher plusieurs fois pour un même record — sinon
rien. Tu confirmes que ça te va, ou tu vois un cas concret où le cache du Verifier ne
suffit pas ?

## Plan ajusté (état actuel)

**Réglage.** `protect_elementor_all_forms` (0 par défaut) dans `defaults()` + boucle
`sanitize()` ; nouvelle section admin « Elementor Pro forms » (1 case) ;
`section_native_intro()` corrigé.

**Affichage (option A durcie).** Classe `Integrations\Elementor_All_Forms` accroche
`elementor/widget/render_content` : si `get_name()==='form'` + option ON + configuré +
pas de `cwebts-widget` déjà présent → `enqueue()` + injection via
`inject_widget_before_submit()`, widget enveloppé d'un wrapper de champ Elementor,
repli strictement dans `<form>`, sinon no-op. Enqueue explicitement couplé au rendu
serveur initial du widget Form.

**Affichage tardif (popup/AJAX).** `MutationObserver` anti-rebond dans `turnstile.js`
qui rappelle `renderAll()` sur insertion de `.cf-turnstile` non rendu → ferme le piège
du blocage silencieux.

**Validation (globale durcie).** `elementor_pro/forms/validation` (10, 2) : bail si non
configuré / option OFF / `record_has_turnstile_field()` ; lire `cf-turnstile-response` ;
`verify(token,null,'elementor_form')` ; sur échec erreur niveau form via
`add_error_message()` avec repli `method_exists` → `add_error('cwebts_turnstile',…)`.

**Anti-doublon.** Affichage : signature `cwebts-widget`. Validation : détection du champ
via le record. Filet : cache par-jeton du Verifier.

**Tests.** `inject_widget_before_submit()` + `record_has_turnstile_field()` +
sanitization du nouveau toggle. Gate manuel Elementor Pro (bloquant).

**Doc/livraison.** `.pot` régénéré ; readme (description+features+FAQ) ; bump 1.1.0 (3
endroits) ; CHANGELOG.md + changelog readme ; rebuild zip ; SVN `tags/1.1.0` (après que
1.0.1 soit déployé).

**Limites documentées.** Deux plugins Turnstile sur le même form ; chemins de rendu
tardif exotiques au-delà de ce que l'observer attrape ; couplage aux classes/markup
Elementor (wrapper + ancrage submit).
