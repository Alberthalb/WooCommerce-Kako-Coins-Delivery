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
- `[yoda_affiliate_portal]`: portal exclusivo do revendedor (requer login e role `yoda_affiliate`).
- `[yoda_cashback_portal]`: portal do cashback (requer login).
- `[yoda_raffles]`: lista sorteios abertos e permite inscrição (requer login).

## Sistema de Revendedores (Afiliados)
- Ative e configure em **Yoda → Revendedores** (taxa padrão, base de cálculo, dias de liberação, cookie, parâmetro do link).
- Cada revendedor tem um **código** no perfil do usuário (`yoda_affiliate_code`) e (opcionalmente) uma **taxa personalizada** (`yoda_affiliate_rate`).
- Link de indicação: `/?ref=SEU-CODIGO` (o parâmetro `ref` é configurável). O sistema grava cookie/sessão e atribui o pedido automaticamente.
- O pedido guarda o revendedor em `_yoda_affiliate_id` e `_yoda_affiliate_code`.
- A comissão é criada quando o pedido entra em `processing` ou `completed` e fica como **a liberar**; após o prazo configurado, o cron diário marca como **liberada**.
- Se o pedido for `refunded/cancelled/failed`, a comissão é marcada como **estornada**.

## Sistema de Cashback (bonificações)
- Configure em **Yoda → Cashback**.
- Regra padrão: **1,2%** de cashback em moedas sobre o total de moedas entregue no pedido (ex.: 1.000.000 → 12.000).
- Cashback é creditado quando a entrega na Kako fica `delivered` e é lançado no extrato (`yoda_cashback_txn`).
- Base de cálculo = moedas efetivamente entregues (armazenadas em `_yoda_coins_delivered`).
- Resgate mínimo padrão: **5.000 moedas** (configurável, mínimo absoluto 5.000).
- Resgate faz um `transout` para o KakoID informado (pré-preenche com o último KakoID usado nas compras do cliente).
- Se o pedido for cancelado/reembolsado (`refunded/cancelled/failed`), o cashback daquele pedido é estornado.
- Chargeback/refund/cancelled também estornam movimentações pendentes/pagas associadas e retiram saldo.
- E-mails: cliente recebe quando cashback é creditado, quando solicita resgate e quando o resgate é pago.
- Sorteios: campanhas abertas podem gerar tickets automaticamente por pedido entregue ou a cada X moedas; entradas ficam em CPT `yoda_raffle_entry` e são logadas no Ledger como `raffle_ticket`.
- Regras rígidas: sem duplicar ticket por pedido e respeitando limite por usuário (lock por pedido + checagem de limite).
- Sorteio: ao sortear (ou encerrar e sortear) registra admin, horário, winner entry, trava campanha (`drawn`) e loga no Ledger (`raffle_draw`).
- Prêmio: transout automático para o vencedor (usando KakoID salvo), grava recibo/orderRef e log `raffle_prize` no Ledger.
- Resgates ficam **pendentes**: admin aprova/recusa/força processamento na tela **Yoda → Cashback (Relatórios)** com motivo; pendente debita saldo, recusa estorna, aprovação dispara o envio.

## Sistema de Sorteios
- Módulo inicial (regras ainda não definidas): cria **Sorteios** e **Inscrições** via CPTs.
- Admin: crie um post em **Yoda → Sorteios**, marque meta `_yoda_status=open` para abrir (e opcionalmente `_yoda_start_at` / `_yoda_end_at` como timestamps).
- Cliente: **Minha Conta → Sorteios** ou shortcode `[yoda_raffles]` para ver sorteios abertos e participar.
- Limite por usuário: meta `_yoda_max_entries_per_user` (padrão 1).

## Como configurar (admin)
1. **Credenciais Kako**: em **Yoda → Config**, informe App ID/Key e ambiente (sandbox/production). Opcional: defina constantes no `wp-config.php`.
2. **Afiliados**: em **Yoda → Revendedores**, ative, defina percentual padrão, dias de liberação, parâmetro `ref` e duração do cookie. Opcional: permitir/ bloquear autoindicação e mínimo de pedido.
3. **Cashback**: em **Yoda → Cashback**, ative, defina % (padrão 1,2%), arredondamento e resgate mínimo (mínimo absoluto 5.000). Ajuste elegibilidade (roles) e compra mínima em moedas.
4. **Sorteios**: em **Yoda → Sorteios**, crie a campanha, defina status (draft/open/closed/drawn), datas e limite por usuário. Use “Sortear vencedor” ou “Encerrar e sortear”.
5. **Ledger**: use **Yoda → Ledger** para consultas rápidas e export CSV.

## Como usar (afiliado/cliente)
- **Afiliado (Revendedor)**: menu **Minha Conta → Revendedor** ou shortcode `[yoda_affiliate_portal]`. Lá vê link de indicação (`/?ref=CODIGO`), KPIs, pedidos indicados, comissões, solicita saque e acompanha status.
- **Cliente (Cashback)**: menu **Minha Conta → Carteira/Cashback** ou `[yoda_cashback_portal]`. Consulta saldo, solicita resgate (≥ 5.000 moedas), acompanha extrato e alertas de crédito/resgate.
- **Sorteios (Cliente)**: menu **Minha Conta → Sorteios** ou `[yoda_raffles]`. Lista campanhas abertas e permite inscrever-se respeitando o limite por usuário.

## FAQ (rápido)
- **Quando o cashback é creditado?** Ao `_yoda_delivery_status = delivered` com base nas moedas efetivamente entregues (`_yoda_coins_delivered`).
- **Chargeback/refund cancela cashback?** Sim. Movimentação vira `reversed` e o saldo é debitado.
- **Resgate mínimo?** 5.000 moedas (mínimo absoluto, configurável para cima).
- **Quem pode ver o portal do afiliado?** Role `yoda_affiliate` e administradores para suporte.
- **Como exportar dados?** Use os botões “Exportar CSV” em Comissões (Afiliados), Cashback (Relatórios) e Ledger.
- **Posso forçar pagamento de resgate?** Sim, no admin de Cashback: botão “Processar” ou “Forçar processamento”; recusar devolve saldo e registra motivo.

## Logs (opcional)
- Habilite com `define('YODA_LOGS', true);` em wp-config.php.
- Retencao padrao 14 dias (`YODA_LOGS_RETENTION_DAYS`).
- Arquivos em `wp-content/uploads/yoda-logs/yoda-YYYY-MM-DD.log` (JSON Lines). Chaves sensiveis sao mascaradas.

## Desenvolvimento
- Classe principal: `yoda-kako-delivery.php` carrega modulos.
- API client: `includes/class-yoda-kako-client.php` (assina corpo JSON; endpoints balance, userinfo, transout, transqry).
- Entrega: `includes/class-yoda-fulfillment.php`.
  - Hook: `yoda_kako_delivery_delivered` (disparado quando `_yoda_delivery_status` vira `delivered`, usado por cashback/afiliados).
  - Antifraude: se `_yoda_delivery_status` virar `needs_review/failed/cancelled`, cashback e comissões do pedido são estornados.
- Checkout e campos: `includes/class-yoda-checkout.php` (+ extras em `class-yoda-checkout-extras.php`).
- Shortcodes/UI: `class-yoda-packs.php`, `class-yoda-user-card.php`, `class-yoda-shop-buttons.php`, `class-yoda-direct-checkout.php`.
- Webhook MP: `class-yoda-webhooks.php`.
- Telas definidas: `docs/SCREENS.md`.
- Papéis:
  - **Admin**: permissões WordPress (`manage_options`), acesso total e visão dos portais para suporte.
  - **Revendedor (Afiliado)**: role custom `yoda_affiliate` (criada na ativação e garantida em runtime). Acesso ao portal do revendedor e comissões.
  - **Cliente**: role WooCommerce padrão `customer`, acesso a portais de cashback e sorteios.
- Configs-chave no admin:
  - Revendedores: comissão padrão, liberação após N dias, base, auto-compra, roles elegíveis, pedido mínimo.
  - Cashback: % cashback, arredondamento, resgate mínimo, roles elegíveis, compra mínima (moedas).
- Ledger: `includes/class-yoda-ledger.php` cria tabela `wp_yoda_ledger` para lançamentos de afiliado/cashback/sorteios.
  - Tela de consulta rápida em **Yoda → Ledger** (`class-yoda-ledger-admin.php`) com filtros simples.
- Saque de afiliado (manual): portal solicita payout (status `pending`), admin marca `paid` ou `rejected`; registros em `yoda_aff_payout` e no Ledger.

## Boas praticas de segredos
- Nunca commitar chaves ou `.env`. Valores efetivos devem vir do ambiente ou wp-config.php.
- `.gitignore` ja ignora env, logs, uploads e chaves.

## Roadmap sugerido
- Adicionar limitacao/rate-limit em chamadas publicas de userinfo (AJAX/shortcode).
- Corrigir lookup de webhook MP para aceitar external_reference nao numerico.
- Completar funcoes ausentes em `Yoda_Packs` (cache_key, retry_verify_bg) se forem usadas.
## Notas recentes
- Menu "Carteira/Cashback" aparece na Minha Conta (endpoint `yoda-cashback`) e carrega o portal de saldo/resgate/extrato.
