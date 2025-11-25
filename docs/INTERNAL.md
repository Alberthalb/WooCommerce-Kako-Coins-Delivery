# Yoda Kako Delivery - Interno

Guia para manutencao, operacao e pontos de atencao do plugin.

## Arquitetura
- Bootstrap: `yoda-kako-delivery.php` carrega classes em `includes/`.
- API client: `class-yoda-kako-client.php` assina corpo JSON (`path`, `timestamp`, `appId`, `body`). Endpoints: `balance`, `userinfo`, `transout`, `transqry`.
- Entrega: `class-yoda-fulfillment.php` reage a `payment_complete` ou status processing/completed. Fluxo: calcula moedas (`Yoda_Product_Meta`), checa regras/limites, busca openId (userinfo), executa transout, grava meta `_yoda_delivery_status` e `_yoda_order_ref`. Reprocesso manual via acao de pedido.
- Checkout: `class-yoda-checkout.php` ajusta campos, valida CPF e KakoID, salva metas, suporta Store API (blocks).
- UI/shortcodes: `class-yoda-packs.php` (verificar ID + grid de produtos de moedas), `class-yoda-user-card.php` (card de perfil), `class-yoda-shop-buttons.php` (botao/overlay na loja), `class-yoda-direct-checkout.php` (1 item e redirect para checkout), `class-yoda-checkout-extras.php` (checkbox de termos).
- Webhooks: `class-yoda-webhooks.php` registra `yoda/v1/mp/webhook` para eventos de chargeback/refund do Mercado Pago.
- Logging: `class-yoda-logger.php` escreve JSONL em uploads/yoda-logs (mascara appKey, headers sensiveis).

## Operacao
- Credenciais: usar constantes no wp-config.php (`KAKO_APP_ID`, `KAKO_APP_KEY`, opcional `KAKO_API_BASE`). Evita salvar no banco.
- Ambiente: `mode` sandbox/prod; base custom aceita override. `YODA_FORCE_IPV4` pode ajudar em hosts com IPv6 problematico.
- Cron: `yoda_fulfill_order` para retentativa apos hold; `yoda_logs_prune` limpa logs.
- Regras antifraude: campos em Yoda Kako > Antifraude & Regras (hold por gateway, limites diario/semanal, block/allow lists).
- Webhook MP: URL exibida inclui `webhook_secret` salvo na opcao; chargeback/refund marca pedido como `needs_review`.

## Segurança e privacidade
- Evitar commits de `.env`, chaves ou logs (ja ignorados no .gitignore).
- userinfo/transout expostos via credenciais do site; reforcar protecao nas rotas publicas que chamam userinfo (AJAX/shortcode) com caching/rate-limit/recaptcha se houver abuso.
- Logger mascara appKey e assinaturas; reter logs pelo menor tempo necessario (`YODA_LOGS_RETENTION_DAYS`).
- Portal sem senha: por padrao exige email + KakoID. Para permitir apenas ID, definir `YODA_ID_ONLY_PORTAL` como true (menos seguro).

## Gaps conhecidos (avaliar em proximas sprints)
- `class-yoda-packs.php` referencia `cache_key()` e `retry_verify_bg()` inexistentes: implementar ou remover hooks.
- `class-yoda-kako-client.php`: filtro `yoda_kako_http_args` usa `$url` antes de definir (ajustar ordem).
- Webhook MP converte order_id para int; se `external_reference` for alfanumerico (ex.: `mp-123`), nao encontra pedido. Ideal: tentar por meta `_yoda_order_ref` antes de abortar.
- Protecao contra abuso nas rotas publicas de userinfo: adicionar rate-limit por IP, cache mais longo ou desafio (captcha) se necessario.

## Testes sugeridos (manuais ou automatizados)
- Checkout classico e blocks: valida cpf e kakoid; salva metas; preenche via GET ?kakoid= e cookie.
- Entrega automatica: pedido pago via gateway sem hold -> transout status 2 grava delivered e envia email.
- Hold por gateway: configurar hold_minutes + padrao de gateway; pedido fica queued ate liberar; cron executa.
- Limites diario/semanal e block/allow: pedido excedente marca `needs_review`.
- Webhook MP: chamada com key correta e status chargeback/refunded marca pedido.
- Shortcodes: [yoda_buy_coins] bloqueia sem validar ID; apos AJAX libera CTAs; [yoda_kako_card] mostra avatar/nickname; [yoda_kako_logout] limpa cookie.
- Logger: habilitar `YODA_LOGS=true` e confirmar mascaramento de appKey.
