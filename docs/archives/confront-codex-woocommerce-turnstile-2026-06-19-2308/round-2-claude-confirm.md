# Round 2 — Confirmation de consensus (Claude)

Je confirme également le consensus, plus aucun désaccord substantiel de mon côté.

## Ma revue du plan finalisé

J'ai relu `round-2-codex.md` (`CONSENSUS_ATTEINT`). J'accepte ses deux derniers recadrages :

1. **`process_registration()` préfixe bien « Error: »** dans la version WooCommerce vérifiée
   (10.6.1). Ma nuance du round 2 était inexacte sur ce point. La décision ne change pas :
   **message brut `get_error_message()` partout côté WooCommerce** (sinon double préfixe au
   login ET à l'inscription).

2. **Stubs de tests** : `add_action()`/`add_filter()` ne sont pas stubbés dans
   `tests/bootstrap.php`. Les tests `WP_Comments` instancient avec le toggle **désactivé**
   (donc `register()` n'est pas appelé) puis testent `validate()` directement. Conséquence
   actée pour l'implémentation :
   - Les tests des 4 `validate()` suivront ce modèle (instancier toggle off, appeler
     `validate()` directement) — pas de fatal sur `add_action`/`add_filter`.
   - Pour **tester le gating et l'enregistrement effectif des hooks** (toggle on), il faut
     ajouter à `tests/bootstrap.php` une **capture de hooks** (stubs `add_action`/`add_filter`
     qui enregistrent dans un tableau global inspectable) et **compléter le stub `WP_Error`**
     (`get_error_code()`, `get_error_messages()`) si les assertions les utilisent.
   - Ajouter le stub **`wc_add_notice`** (capture).

## Points non bloquants, tranchés

- **Détection automatique du Checkout Block** (`has_block` + admin notice) : optionnelle, hors
  v1.2.0. Libellé explicite + readme/changelog suffisent. Les deux parties d'accord.
- **`Requires Plugins: elementor`** : non agi en v1.2.0, remonté à Alexandre comme décision
  produit. Les deux parties d'accord.

## Vérification finale des signatures (aucune erreur résiduelle)

- `woocommerce_checkout_before_order_review` (rendu, dans le form, hors fragment AJAX).
- `woocommerce_checkout_process` (action, 0 arg) → `wc_add_notice($msg,'error')`.
- `woocommerce_login_form` (rendu) / `woocommerce_process_login_errors` (filtre, 3 args) → `WP_Error`.
- `woocommerce_register_form` (rendu) / `woocommerce_process_registration_errors` (filtre, 4 args) → `WP_Error`.
- `woocommerce_edit_account_form` (rendu) / `woocommerce_save_account_details_errors`
  (action, 2 args, `do_action_ref_array`) → mute `WP_Error`.

Consensus bilatéral confirmé. Je produis `docs/plan-woocommerce-turnstile.md`.
