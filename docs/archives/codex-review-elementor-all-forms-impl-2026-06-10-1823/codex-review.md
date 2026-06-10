## Conformite au plan

Le coeur de la fonctionnalite est majoritairement conforme au plan consensuel.

- Le reglage `protect_elementor_all_forms` est ajoute aux defaults, sanitise en `0/1`, et presente dans une section admin Elementor Pro dediee. Le texte de la section native ne presente plus le champ Elementor comme unique voie.
- La classe dediee `CWebTS\Integrations\Elementor_All_Forms` n'a pas de type-hint ni de `use` Elementor dur, et l'autoloader la charge correctement via `includes/integrations/class-elementor-all-forms.php`.
- L'affichage passe par `elementor/widget/render_content`, verifie defensivement `$widget->get_name() === 'form'`, utilise `Widget_Renderer::get_html()` comme source unique du widget, ajoute le wrapper de champ Elementor, et evite le doublon via `cwebts-widget`.
- L'enqueue elargi via `wp_enqueue_scripts` est present quand le mode global est actif/configure.
- La validation globale passe par `elementor_pro/forms/validation`, saute les formulaires qui exposent deja un champ `type === 'turnstile'` via `$record->get('fields')`, utilise `passes()`/`get_token()` de la classe parente, garde `add_error_message()` par `method_exists`, et n'ajoute pas de garde par hash de token.
- Le `MutationObserver` anti-rebond est present et replanifie `renderAll()` pour les widgets ajoutes tardivement.

Non-conformites restantes :

- `inject_widget_before_submit()` ne respecte pas strictement la regle absolue "ne jamais injecter hors du `<form>`" dans son chemin prioritaire. Il trouve la premiere occurrence de `elementor-field-type-submit`, puis injecte avant le dernier `<div` avant cette occurrence, sans verifier que ce `<div` est entre un `<form>` ouvrant et son `</form>` fermant.
- Les etapes de livraison du plan ne sont pas faites : `.pot` non regenere, `readme.txt` toujours en FAQ/description 1.0.1, `CHANGELOG.md` racine sans entree 1.1.0, et version/stable tag encore en 1.0.1. Ce n'est pas un bug runtime, mais ce n'est pas deployable comme livraison 1.1.0 en l'etat.

## Bugs & corrections

Changement requis : renforcer `Elementor_All_Forms::inject_widget_before_submit()`.

Le cas Elementor attendu fonctionne : dans le markup normal, `elementor-field-type-submit` est sur le `<div>` du groupe submit, donc `strrpos(..., '<div')` retombe bien sur le wrapper submit dans le formulaire.

Mais la methode n'est pas strictement sure. Si `elementor-field-type-submit` apparait avant le `<form>` reel, dans un wrapper, un template, un fragment de cache, ou un markup Elementor modifie, le code injecte avant ce `<div>` externe. Le widget s'affiche alors hors formulaire, `cf-turnstile-response` n'est pas soumis, et la validation globale bloque le formulaire. C'est exactement le mode d'echec que le plan voulait exclure.

Correction simple et sure : n'utiliser l'ancrage submit que si le point d'insertion est prouve dans un formulaire. Par exemple, verifier qu'il existe un `<form` avant `$div_pos`, qu'aucun `</form>` ne se trouve entre ce `<form` et `$div_pos`, et qu'un `</form>` existe apres `$div_pos`. Sinon, ignorer l'ancrage submit et utiliser le repli avant `</form>` ; si aucun `</form>` n'existe, no-op. Ajouter un test qui met `elementor-field-type-submit` hors du formulaire et verifie que l'injection retombe dans le `<form>` ou ne se fait pas.

Aucun autre bug runtime certain vu dans les fichiers lus. Les tests locaux passent : `php tests/run-tests.php` donne 65/65, et `php -l` passe sur les fichiers PHP modifies principaux.

## Securite & Plugin Check

Pas de probleme de securite identifie dans l'implementation runtime.

- `Widget_Renderer::get_html()` echappe chaque nom et valeur d'attribut ; le wrapper Elementor ajoute autour est du HTML statique.
- La lecture de `$_POST['cf-turnstile-response']` passe par `Abstract_Integration::get_token()`, avec assainissement `sanitize_text_field( wp_unslash(...) )` et commentaire `phpcs:ignore` nonce deja justifie pour un jeton captcha.
- Pas de sortie non echappee ajoutee dans les chemins admin ou front.
- Pas de fatal attendu si Elementor Pro est absent : la nouvelle classe ne reference pas de classe Elementor, et les hooks Elementor ne se declencheront pas hors Elementor Pro.

Point release non runtime : le `.pot` est stale et ne contient pas les nouvelles chaines admin. A corriger avant publication WordPress.org, avec les changements readme/changelog/version mentionnes plus haut.

## Risque blocage silencieux

Le risque principal "validation globale active mais widget absent" n'est pas resolvable proprement cote validation sans affaiblir la promesse "tous les formulaires sont proteges". Si le handler global sautait les soumissions sans token, il ouvrirait un contournement pour tout formulaire rendu hors chemin d'injection.

Avec l'option decochee par defaut, l'enqueue front elargi, et le `MutationObserver`, la limite restante est acceptable a condition de la documenter et de la valider sur une vraie installation Elementor Pro : purge de cache apres activation, formulaires en popup/AJAX, page sans formulaire initial puis formulaire charge tard, et formulaires multi-etapes. Le bug d'insertion hors `<form>` ci-dessus, lui, n'est pas une limite acceptable : il doit etre corrige car il cree precisement un affichage trompeur suivi d'un blocage.

## Verdict

Implementation proche du plan et saine cote securite, mais pas deployable en l'etat. Il faut corriger la preuve d'insertion strictement dans le `<form>` pour l'ancrage submit, puis terminer les artefacts de livraison 1.1.0 si cette revue couvre le deploiement WordPress.org.

REVIEW_CHANGES_REQUESTED
