## Correction insertion

La correction est bonne pour le bug bloqueur signale en R1. Le chemin prioritaire ne se contente plus de trouver `elementor-field-type-submit` : il remonte au `<div` d'insertion puis appelle `position_inside_form()`, qui exige un `<form` avant le point, aucun `</form>` entre ce `<form` et le point, et un `</form>` apres le point. Le scenario critique R1, avec un submit leurre avant le vrai `<form>`, ne peut donc plus injecter hors formulaire ; il tombe sur le fallback et insere avant le `</form>` du formulaire reel.

Les cas de bord demandes sont couverts de maniere acceptable pour du HTML Elementor normal : attributs et casse de `<form>`/`</form>` sont geres via `strripos()`/`stripos()`, un `<form>` sans fermeture devient no-op, et plusieurs formulaires bien formes ne creent pas d'injection hors `<form>` (l'ancre valide vise le formulaire qui contient le submit ; sinon le fallback vise le dernier formulaire ferme). Les formulaires imbriques restent du HTML invalide, mais le correctif ne degrade pas ce cas : il refuse l'ancre si le dernier `<form>` vu est deja ferme et retombe sur un point de fermeture.

Nuance non bloquante : le fallback ne parse pas le HTML ; un markup deja invalide avec un `</form>` orphelin apres un formulaire ferme pourrait encore tromper le test minimal "un `<form` existe avant le dernier `</form>`". Ce n'est pas un chemin realiste dans le rendu d'un widget Form Elementor et je ne le retiens pas comme changement requis.

Verification locale : `php tests/run-tests.php` passe en 67/67, incluant le nouveau test avec submit leurre avant le `<form>`. `php -l` passe sur tous les fichiers PHP du plugin et des tests.

## Livraison 1.1.0

Les artefacts de release sont coherents et suffisants pour publier une 1.1.0 WordPress.org : l'en-tete plugin et `CWEBTS_VERSION` sont en `1.1.0`, le `Stable tag` du `readme.txt` est en `1.1.0`, le readme documente le mode global, son comportement par defaut, l'impact sur le chargement front et l'Upgrade Notice, et `CHANGELOG.md` contient une entree 1.1.0 claire.

Le `.pot` contient la version 1.1.0, la nouvelle section Elementor, le nouveau toggle, les nouvelles descriptions admin, et l'ancienne intro native remplacee. Note de qualite : ce n'est pas une regeneration parfaite, car `POT-Creation-Date` et certaines references anciennes restent stales, mais les nouvelles chaines utiles de la release sont presentes avec des references correctes. Pas bloquant pour le deploiement.

## Verdict

Pas de nouveau probleme runtime ou release bloquant trouve dans ce round. Le correctif ferme le bug d'injection hors `<form>` pour le cas vise et les artefacts 1.1.0 sont deployables en l'etat.

REVIEW_APPROVED
