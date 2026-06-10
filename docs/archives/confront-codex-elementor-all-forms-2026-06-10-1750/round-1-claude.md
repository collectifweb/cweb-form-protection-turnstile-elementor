# Round 1 — Plan initial (Claude)

## 1. Contexte

Plugin WordPress **CWeb Form Protection with Turnstile for Elementor Forms**
(dossier `cweb-form-protection-turnstile-elementor/`, namespace `CWebTS`, préfixe
`cwebts_`/`CWEBTS_`). Vient d'être approuvé sur WordPress.org. Version courante en
cours de déploiement SVN : 1.0.1.

**Différenciateur produit actuel :** un **champ Turnstile par formulaire** dans
Elementor Pro (comme le champ reCAPTCHA natif). L'utilisateur glisse le champ
dans les formulaires qu'il veut protéger ; les autres ne sont pas touchés. C'est
volontaire et c'est l'argument de vente.

**Demande :** ajouter une option **décochée par défaut** qui protège **tous** les
formulaires Elementor d'un coup, sans avoir à ajouter le champ partout. Le
par-formulaire reste le défaut et le différenciateur ; le « tous les forms » est
un raccourci opt-in. La FAQ du `readme.txt` le promet déjà (« an automatic "all
forms" mode may be added in a future version »).

Cible : **v1.1.0** (mineure, fonctionnalité), strictement séparée du correctif
1.0.1.

## 2. État du code existant (vérifié)

- **`includes/class-plugin.php`** — `register_elementor_field()` accroche
  `elementor_pro/forms/fields/register` et enregistre le champ. Les intégrations
  WP natives sont instanciées à part.
- **`includes/elementor/class-turnstile-field.php`** — le champ étend
  `ElementorPro\Modules\Forms\Fields\Field_Base`. `render()` appelle
  `$renderer->enqueue()` puis `$renderer->render(['action'=>'elementor_form'])`.
  `validation()` lit `$_POST['cf-turnstile-response']`, appelle
  `$verifier->verify($token, null, 'elementor_form')`, et sur échec
  `$ajax_handler->add_error($field['id'], $msg)`. Un garde statique
  `self::$validated` évite la double vérif si le form a deux champs Turnstile.
  **Ces deux méthodes ne sont appelées par Elementor que pour les forms qui
  contiennent le champ.**
- **`includes/class-widget-renderer.php`** — `get_html($overrides)` est la
  **source unique** du balisage : un `<div class="cf-turnstile cwebts-widget …"
  data-sitekey data-theme data-size data-appearance data-language [data-action]
  [id]>`, chaque attribut échappé via `esc_attr`. `enqueue()` enregistre le
  helper `turnstile.js` + l'API Cloudflare en rendu explicite
  (`render=explicit&onload=cwebtsOnload`), idempotent. Court-circuite si
  `! is_configured()`.
- **`assets/js/turnstile.js`** — `renderAll()` (exposé en `window.cwebtsOnload`)
  rend **tout** `.cf-turnstile:not([data-tf-rendered])` présent dans le DOM. La
  remise à zéro après échec AJAX écoute `submit` en capture sur **tout**
  `.elementor-form` et reset les widgets dedans. **Le JS est donc déjà agnostique
  au formulaire** — il ne sait pas si le widget vient du champ ou d'une
  injection.
- **`includes/class-settings.php`** — options dans `defaults()`, bascules
  `protect_login/register/lostpassword/comments` (0/1) assainies dans
  `sanitize()` via une boucle `empty()?0:1`. Sections de réglages ajoutées dans
  `register()`. `is_configured()` = les deux clés présentes.

## 3. Approche proposée

### 3a. Réglage (simple, peu discutable)

- Ajouter `'protect_all_elementor' => 0` dans `Settings::defaults()`.
- Ajouter `'protect_all_elementor'` à la boucle de bascules dans `sanitize()`.
- Nouvelle section de réglages `cwebts_elementor` (« Elementor Pro forms ») avec
  une seule case : « Protect **all** Elementor Pro forms » + une description qui
  rappelle que sinon on ajoute le champ par formulaire.
- Accesseur de confort optionnel `protect_all_elementor()` (bool), ou simple
  `get('protect_all_elementor')`.

### 3b. Vérification (la moitié facile et robuste)

Accrocher le hook **global** `elementor_pro/forms/validation`
(signature `($record, $ajax_handler)`) — il se déclenche à **chaque** soumission
de form Elementor, indépendamment des champs présents.

Logique du handler :
1. Bail si `! is_configured()` ou option `protect_all_elementor` à 0.
2. **Bail si le form contient déjà un champ Turnstile** (sinon double vérif avec
   le champ). Détection envisagée : `$record->get_field(['type' => 'turnstile'])`
   — **à confirmer** (signature exacte de `get_field`/`get_fields_by_type` sur
   `Form_Record`).
3. Lire `$_POST['cf-turnstile-response']` (même assainissement que le champ).
4. `$verifier->verify($token, null, 'elementor_form')`.
5. Sur échec : erreur **niveau formulaire** via
   `$ajax_handler->add_error_message($msg)` — **à confirmer** (l'API exacte pour
   une erreur non liée à un champ ; le champ, lui, utilise
   `add_error($field_id, $msg)`).

### 3c. Affichage — LE point à débattre

Le champ par-formulaire évite le problème du placement parce que l'utilisateur
pose le champ lui-même. En mode « tous les forms », il faut insérer le widget
dans chaque form automatiquement. Deux options.

**Option A — injection côté serveur (mon penchant).**
Filtrer le HTML rendu du widget Form via `elementor/widget/render_content`
(signature `($content, $widget)`) : si `$widget->get_name() === 'form'`, option
active, configuré, et que `$content` ne contient pas déjà `cf-turnstile`, alors :
- enqueue les scripts (`$renderer->enqueue()`),
- insérer le balisage de `get_html(['action'=>'elementor_form'])` **avant le
  bouton submit**, point d'ancrage `elementor-field-type-submit` (repli : avant
  `</form>` ou en fin de zone des champs).

**Zéro changement JS** : le widget est dans le DOM initial, `cwebtsOnload` le
rend au chargement de l'API, et le reset post-AJAX marche déjà.
**Le même hook règle aussi le « quand charger les scripts »** (uniquement quand
un form Elementor est effectivement rendu sur la page).

Risque assumé : couplage au balisage interne d'Elementor (trouver le point
d'insertion du submit). Si la structure change, l'insertion peut mal tomber.

**Option B — injection JS.**
Charger un petit bout de JS qui parcourt chaque `.elementor-form` et insère le
widget (depuis une config localisée site_key + apparence, ou un fragment HTML
localisé) avant le submit, si pas déjà présent.
- Plus indépendant du HTML serveur d'Elementor.
- Mais : flash visuel possible (widget injecté après peinture), dépend du JS pour
  le placement, et il faut résoudre le « quand enqueue » autrement (détecter la
  présence d'un form côté serveur quand même, ou enqueue large).

**Mon choix : A.** Plus de réutilisation (un seul balisage via `get_html`),
moins de code, validation déjà côté serveur, et un seul hook gère affichage +
enqueue. Je veux que ce choix soit challengé : est-ce que
`elementor/widget/render_content` est bien le bon filtre, fiable pour le widget
Form, et est-ce que l'ancrage submit est assez stable ?

### 3d. Anti-doublon (jetons à usage unique — non négociable)

Les jetons Turnstile sont à usage unique : vérifier deux fois le même jeton =
le 2ᵉ appel échoue (`timeout-or-duplicate`) et bloque une soumission légitime.

Matrice (champ présent × option « tous les forms ») :

| Champ dans le form | Option ON | Affichage | Vérification |
|---|---|---|---|
| oui | non | champ (actuel) | champ (actuel) |
| oui | oui | injection **sautée** (déjà un `.cf-turnstile`) | globale **saute** (champ détecté) → champ gère |
| non | oui | injection | globale |
| non | non | rien | rien |

Filets de sécurité :
- À l'affichage : ne pas injecter si `cf-turnstile` déjà dans le `$content`.
- À la vérif : bail si le record contient déjà le champ Turnstile.
- Le **cache par-jeton du `Verifier`** (déjà existant) garantit que même si deux
  chemins vérifiaient le même jeton, Cloudflare n'est appelé qu'une fois et le
  résultat est cohérent — c'est le filet, pas la stratégie principale.

### 3e. Cas limites à tester en vrai

- Forms **multi-étapes** Elementor (le submit n'est pas au même endroit / étape).
- Forms en **popup** Elementor (rendus/affichés tardivement).
- Forms **chargés en AJAX** (le filtre `render_content` ne passe peut-être pas,
  ou passe dans un autre contexte).
- Plusieurs forms sur **une même page** (chaque form doit avoir son widget, et le
  reset post-AJAX FIFO existant doit toujours corréler — c'est déjà best-effort).

## 4. Étapes d'implémentation

1. **Settings** : `defaults()` + `sanitize()` + section/champ « Elementor Pro
   forms ». (Le plus simple, aucun risque.)
2. **Affichage (option A)** : nouvelle classe d'intégration
   `Integrations\Elementor_All_Forms` (ou méthode dans `Plugin`) qui accroche
   `elementor/widget/render_content`, enqueue + injecte via `get_html`.
3. **Vérification** : accrocher `elementor_pro/forms/validation`, bail si champ
   présent, vérifier, erreur niveau formulaire.
4. **Tests** : étendre `tests/` (settings : la nouvelle bascule s'assainit ;
   logique de bail si champ présent si testable hors WP).
5. **Doc** : readme.txt (FAQ « does it protect every form » à mettre à jour, la
   réponse actuelle dit « non » ; changelog 1.1.0), CHANGELOG.md, bump version 3
   endroits, rebuild zip, déploiement SVN tags/1.1.0.

## 5. Points sensibles / incertitudes assumées

- **Nom exact du filtre d'affichage** et `get_name()` du widget Form
  (`'form'` ?). Si `render_content` ne contient pas le markup attendu pour le
  Forms widget, l'option A tombe → bascule sur B.
- **API Elementor de validation** : signature de `elementor_pro/forms/validation`,
  méthode `Form_Record::get_field()` pour détecter le champ, et
  `Ajax_Handler::add_error_message()` pour l'erreur niveau formulaire. À vérifier
  contre la version d'Elementor Pro.
- **Stabilité de l'ancrage submit** (option A).
- **Faut-il un garde statique** comme le champ pour ne valider qu'une fois par
  soumission au niveau global ? (le hook global ne se déclenche en principe
  qu'une fois par form, mais à confirmer).
- **Le mode « tous les forms » charge l'API Cloudflare sur toute page ayant un
  form** — c'est l'effet voulu, mais ça élargit l'empreinte vs le par-formulaire.

## 6. Alternatives écartées

- **Tout en JS (option B) comme choix principal** : écarté faute de réutilisation
  (markup dupliqué côté JS) et pour le flash visuel ; gardé comme repli si le
  filtre serveur ne convient pas.
- **Forcer le champ dans tous les forms par programmation** (manipuler la
  définition des forms) : trop intrusif, fragile, casse l'idée « le champ est un
  choix de l'utilisateur ».
- **Un réglage par type de form** : sur-ingénierie pour une v1.1.0 ; une seule
  case suffit.
