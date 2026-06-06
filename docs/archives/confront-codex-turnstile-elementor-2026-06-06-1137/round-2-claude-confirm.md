# Round 2 — Confirmation de consensus (Claude)

Codex a émis `CONSENSUS_ATTEINT` et s'est rangé à ma nuance sur la vérification
`action` (désactivée par défaut). **Je confirme également le consensus : plus
aucun désaccord substantiel de mon côté.**

J'accepte les trois précisions d'implémentation de Codex (toutes justes) :

1. **Cache du Verifier** : cacher la **réponse Cloudflare brute** par hash de
   token, puis appliquer les contrôles locaux (`action`/`hostname`/filtres) à
   chaque appel — OU inclure les paramètres de contrôle local dans la clé de
   cache. Le cache ne doit jamais court-circuiter un contrôle local activé plus
   tard. → Retenu : cache de la réponse brute par hash de token ; contrôles
   locaux appliqués après lecture du cache.

2. **`hostname`** (si activé via filtre) : comparer contre une **allowlist
   filtrable**, pas une seule valeur dérivée de `home_url()` (multisite, staging,
   reverse proxy, preview Elementor, domaines temporaires). Je retire ma
   formulation « cross-site toujours couvert par sitekey/secret » : la sitekey
   est publique et la défense hostname côté Cloudflare dépend de la config des
   hostnames autorisés — la vérif applicative `hostname` est une défense en
   profondeur légitime, simplement OFF par défaut en v1.

3. **`remoteip`** : valider le format IP (`filter_var(..., FILTER_VALIDATE_IP)`)
   avant envoi ; omettre `remoteip` si vide/invalide.

Points ouverts restants (mise en œuvre, non bloquants) — d'accord :
- noms exacts des events frontend Elementor pour le reset AJAX (à confirmer au
  codage) ;
- version minimale Elementor Pro à figer après test réel ;
- nom public final à confirmer avant packaging WP.org ;
- protocole manuel Elementor Pro à rédiger avec les clés de test Cloudflare.

Revue finale du plan consolidé : périmètre v1 cohérent, validation serveur
correctement bornée (matrice 4 cas), tokens usage-unique gérés (reset AJAX +
expired-callback + cache par requête), architecture instanciable testable,
branding WP.org dérisqué, contrôles stricts disponibles par filtre sans les
imposer. **Consensus bilatéral atteint au round 2.** Je produis le plan final
consolidé dans `docs/plan-turnstile-elementor.md`.
