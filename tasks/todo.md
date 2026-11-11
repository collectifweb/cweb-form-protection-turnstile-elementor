# Rendez-vous de fin de mois — 30 octobre 2026 (lancé le 11 novembre)

Consigne : vérifier la compatibilité avec la dernière version de WordPress, faire
l'assurance qualité en local, préparer la publication pour qu'Alexandre n'ait
qu'un « oui » à donner. Rien ne part en ligne sans son accord.

## État de départ (relevé le 11 novembre)

- En ligne : v1.2.2, « Tested up to: 7.1 » (WordPress.org l'affiche comme 7.1.4).
- WordPress stable : 7.1.4. En préparation : 7.2 bêta 5 (RC1 le 17 novembre,
  sortie prévue le 8 décembre, d'après make.wordpress.org/core/7-2/).
- Elementor 4.4.0, WooCommerce 11.3.0, Plugin Check 2.2.0. Elementor Pro :
  seule la 4.2.1 est disponible sur le poste.

## Plan

- [x] Suite de tests + syntaxe sur PHP 7.4, 8.3 et 8.5 : 101/101 partout
- [x] Lecture des différences du cœur 7.1.4 → 7.2 bêta 5 (agent) : rien ne touche l'extension
- [x] Site WordPress 7.1.4 : scénario navigateur (26 étapes)
- [x] Corriger les défauts trouvés (ci-dessous), tests unitaires à jour (129)
- [x] Rejouer le scénario complet sur 7.1.4 et sur 7.2 bêta 5 (28/28 chacun)
- [x] Relecture indépendante du correctif (Codex, 2 passes) : constats 4 et 5 ci-dessous
- [x] Préparer la 1.2.3 (version, readme, CHANGELOG, .pot, zip, script SVN) et la
      commande unique de publication
- [x] Doc (STATUS, mémoire), commit local (pas de push avant le « oui »)

## Constats

1. **Champ Turnstile par formulaire cassé sur les pages en cache d'Elementor**
   (mesuré, Elementor 4.2.2 et 4.4.0) : 1re visite après vidage du cache = widget
   + scripts ; visites suivantes = case du widget vide, aucun script, envoi refusé.
   Cause : le cache d'Elementor réenfile les scripts mémorisés (`cwebts`,
   `cwebts-api`), mais l'extension ne les enregistre qu'en affichant le widget ;
   sur une page en cache, WordPress ignore des scripts qu'il ne connaît pas.
   Correctif : enregistrer (sans charger) les deux scripts sur `wp_enqueue_scripts`.
2. **Clés saisies ou changées après la mise en cache** (mesuré) : formulaire sans
   widget, envois refusés, jusqu'à 24 h. Correctif : la purge de la 1.2.2 se
   déclenche aussi quand change un réglage qui façonne le widget (clés, thème,
   taille, apparence, langue), plus seulement la case « tous les formulaires ».
3. **Plugin Check 2.1.0+ : 3 erreurs « OffloadedContent »** sur les chaînes qui
   contiennent `cloudflare.com` (règle élargie le 16 août à toutes les chaînes ;
   2.0.0 ne les signale pas sur le même code). Légitimes : lien vers le tableau de
   bord, adresse de vérification, script que Cloudflare interdit de relayer ou de
   mettre en cache. Correctif : `phpcs:ignore` justifié sur les 3 lignes.

4. **Formulaire protégé dans un modèle réutilisé** (relecture Codex, reproduit sur
   7.1.4) : page A = formulaire protégé puis modèle T via `[elementor-template]` ;
   page C = T seul. Après une visite de A, C affichait la case du widget sans
   aucun script. Le cache de T ne retient que les scripts que T ajoute à la file,
   et ils y étaient déjà. Correctif : les formulaires qui portent le champ (tous
   en mode « tous les formulaires ») sont exclus du cache d'éléments, via le filtre
   `elementor/element/is_dynamic_content`. Étape ajoutée au scénario ; sans le
   correctif, elle échoue seule (27/28).
5. **Pages déjà en cache au moment de la mise à jour** (2e relecture Codex,
   mesuré sur 7.1.4) : un modèle mis en cache par la 1.2.2 restait sans script
   avec le nouveau code, jusqu'à 24 h. Correctif : l'extension retient sa version
   (option `cwebts_version`) et vide une fois le cache d'éléments quand elle change.

À signaler sans y toucher (antérieur, hors sujet) : un commentaire publié par
XML-RPC (`wp.newComment`) passe par `preprocess_comment` et serait refusé quand la
protection des commentaires est active ; le commentaire de
`Widget_Renderer::filter_script_tag()` parle d'async alors que seul `defer` est posé.

Signalé par Codex, non traité : formulaire protégé inséré par AJAX sur une page
sans widget (mode par formulaire), caches de pages ou CDN qui n'écoutent pas la
purge d'Elementor, coût de la purge des CSS d'Elementor sur un gros site.

## Bilan

- Compatibilité : WordPress 7.1.4 et 7.2 bêta 5 sans changement de code requis
  par le cœur. « Tested up to » reste à 7.1 jusqu'à la RC de la 7.2.
- Quatre défauts corrigés, tous liés au cache d'éléments d'Elementor 4 (dont le
  mode par défaut de l'extension, cassé depuis au moins août), plus les
  annotations Plugin Check.
- Preuves : 129 tests sur PHP 7.4, 8.3 et 8.5 (14 défauts volontaires attrapés) ;
  scénario de 28 étapes sur 7.1.4 et 7.2 bêta 5, 0 erreur console, aucune ligne
  de l'extension dans `debug.log` ; Plugin Check 2.2.0 « No errors found ».
- Publication : sur le « oui » d'Alexandre, le 2026-11-11 à 13 h 42 (Toronto).
  GitHub (commit `a0541c6`, étiquette et release `v1.2.3`), SVN wp.org rév. 3796883.
  Vérifié : l'API WordPress.org annonce la 1.2.3, zip public identique au dépôt.
