# Round 3 — Confirmation de consensus (Claude)

**Je confirme également le consensus, plus aucun désaccord substantiel de mon côté.**

Codex a émis `CONSENSUS_ATTEINT` au round 2, a accepté ma position sur le garde
anti-doublon (le cache par-jeton du `Verifier` suffit, pas de second garde par hash
dans le handler global) et a tranché pour le `MutationObserver` global. Je valide.

## Le dernier point soulevé par Codex — et sa résolution

Codex relève (round 2, §MutationObserver) un angle mort réel : l'observateur ne peut
rendre un widget tardif que si `turnstile.js` **et** l'API Cloudflare ont déjà été
chargés sur la page. Or notre enqueue est couplé au rendu serveur du widget Form via
`render_content`. Donc sur une page **sans aucun form Elementor au rendu initial** qui
charge ensuite un form en **popup/AJAX**, les assets ne seraient pas présents → widget
injecté mais jamais rendu + validation globale qui tourne = **blocage silencieux**
(encore la classe de bug 1.0.1).

**Résolution retenue (je tranche, cohérent avec l'option « documenter ou élargir » de
Codex — je choisis élargir) :** quand `protect_elementor_all_forms` est **actif et
configuré**, on **élargit l'enqueue au front-end** (via `wp_enqueue_scripts`) plutôt
que de le coupler au seul `render_content`. Ainsi les assets sont toujours présents,
et le `MutationObserver` peut rendre n'importe quel form arrivé tard.

Coût assumé : l'API Cloudflare (différée, ~cache après 1ʳᵉ charge) se charge sur les
pages front même sans widget initial. C'est le **prix documenté du mode opt-in « tous
les forms »** — et ça ne remet pas en cause l'argument « léger » du mode par défaut
(par-formulaire), qui garde son enqueue ciblé. `renderAll()`/`api.js` ne rendent rien
s'il n'y a aucun `.cf-turnstile` : le surcoût réel est une requête différée.

Ce choix **ferme complètement** le trou du blocage silencieux, au lieu de le laisser en
limite documentée. Le cas « page sans form initial + form popup/AJAX » reste un **test
bloquant** du gate manuel Elementor Pro.

## Revue finale du plan consolidé

Architecture : opt-in (`protect_elementor_all_forms`, off par défaut) ; affichage par
injection serveur (option A) via `elementor/widget/render_content`, widget enveloppé
d'un wrapper de champ Elementor, repli strictement dans `<form>` ; rendu tardif couvert
par un `MutationObserver` anti-rebond + enqueue élargi sous le mode global ; validation
via `elementor_pro/forms/validation` avec bailout `record_has_turnstile_field()` et
erreur `add_error_message()` gardée par `method_exists` ; anti-doublon d'affichage sur
`cwebts-widget` ; cache par-jeton du `Verifier` comme filet ; classe dédiée sans
type-hints Elementor durs ; tests unitaires (injection + détection record + sanitization)
+ gate manuel Elementor Pro bloquant ; i18n + readme (description/features/FAQ) + bump
1.1.0 + zip + SVN tags/1.1.0.

Rien à rouvrir. Je passe à la rédaction du plan final autonome.
