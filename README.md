# Yoda Kako Delivery (WooCommerce)

Plugin WordPress/WooCommerce para entregar moedas da Kako automaticamente apos pagamento, com verificacao de ID no checkout, antifraude e webhooks de chargeback.

## Recursos
- Campo obrigatorio de ID/username do Kako e CPF no checkout (Store API e blocks incluidos).
- Entrega automatica via API Kako (userinfo -> transout -> transqry fallback).
- Limites diarios/semanais, hold por gateway e listas de allow/block por ID e CPF.
- Portal sem senha para clientes consultarem entregas ([yoda_kako_portal]).
- Shortcodes de validacao e cards publicos ([yoda_buy_coins], [yoda_kako_card], [yoda_kako_logout]).
- Webhook do Mercado Pago para sinalizar chargeback/refund (marca pedido como needs_review).
- Logger opcional (JSONL em uploads/yoda-logs) com mascaramento de chaves sensiveis.

## Requisitos
- WordPress 6.x+, WooCommerce ativo.
- Chaves da API Kako (App ID e App Key).
- PHP com cURL ou streams habilitado.

## Instalacao
1) Coloque o plugin em `wp-content/plugins/yoda-kako-delivery`.
2) Ative no painel WordPress.
3) Em Yoda Kako (menu admin), preencha App ID e App Key e escolha ambiente (sandbox/prod).

## Configuracao principal
- **Credenciais**: defina em wp-config.php (recomendado) ou via tela admin. Constantes: `KAKO_APP_ID`, `KAKO_APP_KEY`, `KAKO_API_BASE` (opcional) e `YODA_FORCE_IPV4` se precisar forcar IPv4.
- **Ambiente**: sandbox (padrao) ou production; base custom aceita URL propria.
- **Antifraude/regras**:
  - `hold_minutes` + `hold_gateways`: atrasa entrega para gateways especificos.
  - `limit_daily` / `limit_weekly`: limite de moedas por ID/CPF.
  - `block_kako_ids`, `block_cpfs`, `allow_kako_ids`, `allow_cpfs`.
- **Webhook Mercado Pago**: use a URL exibida na tela (inclui chave secreta). Eventos de chargeback/refund marcam o pedido como `needs_review`.

## Checkout e entrega
- Checkout coleta `billing_kako_id` (regex `[A-Za-z0-9_.-]{3,32}`) e CPF (valida digitos). Armazena em meta `_yoda_kako_id` e `_billing_cpf`.
- Ao `payment_complete` ou status processing/completed: busca openId (userinfo), executa transout e grava status em `_yoda_delivery_status` (`delivered|failed|queued|needs_review`). OrderId padrao: `<gateway>-<order_id>` truncado a 64 chars.
- Email de confirmacao de entrega enviado ao cliente com protocolo e quantidade (classe `Yoda_Email`).

## Shortcodes
- `[yoda_buy_coins]`: grid de produtos com meta `_yoda_coins_amount`; exige validacao de KakoID via AJAX antes de liberar compra.
- `[yoda_kako_card]`: mostra avatar/nickname do ID Kako (cookie `yoda_kako_id` pode ser preenchido via GET `?kakoid=`).
- `[yoda_kako_logout]`: limpa cookie `yoda_kako_id`; atributos opcionais `redirect`, `label`, `confirm`, `icon`, `class`.
- `[yoda_kako_portal]`: lista pedidos e status de entrega por KakoID (e email, exceto se `YODA_ID_ONLY_PORTAL` for true). Debug opcional `YODA_PORTAL_DEBUG` para admins.

## Logs (opcional)
- Habilite com `define('YODA_LOGS', true);` em wp-config.php.
- Retencao padrao 14 dias (`YODA_LOGS_RETENTION_DAYS`).
- Arquivos em `wp-content/uploads/yoda-logs/yoda-YYYY-MM-DD.log` (JSON Lines). Chaves sensiveis sao mascaradas.

## Desenvolvimento
- Classe principal: `yoda-kako-delivery.php` carrega modulos.
- API client: `includes/class-yoda-kako-client.php` (assina corpo JSON; endpoints balance, userinfo, transout, transqry).
- Entrega: `includes/class-yoda-fulfillment.php`.
- Checkout e campos: `includes/class-yoda-checkout.php` (+ extras em `class-yoda-checkout-extras.php`).
- Shortcodes/UI: `class-yoda-packs.php`, `class-yoda-user-card.php`, `class-yoda-shop-buttons.php`, `class-yoda-direct-checkout.php`.
- Webhook MP: `class-yoda-webhooks.php`.

## Boas praticas de segredos
- Nunca commitar chaves ou `.env`. Valores efetivos devem vir do ambiente ou wp-config.php.
- `.gitignore` ja ignora env, logs, uploads e chaves.

## Roadmap sugerido
- Adicionar limitacao/rate-limit em chamadas publicas de userinfo (AJAX/shortcode).
- Corrigir lookup de webhook MP para aceitar external_reference nao numerico.
- Completar funcoes ausentes em `Yoda_Packs` (cache_key, retry_verify_bg) se forem usadas.
