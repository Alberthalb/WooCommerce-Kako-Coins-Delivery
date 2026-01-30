## Plano de validação – Afiliados, Cashback (moedas) e Sorteios

> Status geral: **pendente de execução em ambiente WordPress real**. Checklist abaixo para ser marcado durante os testes.

### Pré-requisitos
- WP + WooCommerce ativos; plugin Yoda Kako Delivery ativo.
- Permalinks salvos (Configurações → Links permanentes → Salvar).
- Produtos com meta `_yoda_coins_amount` > 0.
- Credenciais Kako configuradas (sandbox ou produção).

### 1. Afiliados
- [ ] Criar usuário com role `Revendedor (yoda_affiliate)` e código definido no perfil.
- [ ] Em navegador anônimo, acessar a loja com `/?ref=CODIGO`.
- [ ] Realizar uma compra (checkout normal).
- [ ] Verificar no pedido (admin) metas `_yoda_affiliate_id/_code`.
- [ ] Validar modelo de comissão escolhido em **Yoda → Revendedores**: `% sobre valor`, `% sobre moedas entregues` ou `valor fixo`; conferir taxa/valor padrão, base (total/subtotal) e dias de liberação.
- [ ] Gatilho de crédito: status de entrega `delivered` cria comissão **A liberar** (via meta `_yoda_delivery_status`).
- [ ] Antifraude: ao mudar `_yoda_delivery_status` para `needs_review/failed/cancelled`, comissão é estornada automaticamente.
- [ ] Forçar liberação (meta `_yoda_available_at` ou botão “Liberar agora”) → status **Liberada** + nota no pedido.
- [ ] Portal **Minha Conta → Revendedor** mostra link, KPIs e tabela.
- [ ] Validações de ref: código inválido limpa cookie/sessão; autoindicação (usuário logado = afiliado) é bloqueada quando `allow_self` está desativado.
- [ ] Endpoint/menu “Revendedor” aparece no menu da Minha Conta para role `yoda_affiliate` e admin (teste visual).
- [ ] Filtro de status na lista de comissões (portal) funciona: A liberar / Liberada / Estornada.
- [ ] Saque: portal mostra saldo disponível/pending/pago, aceita solicitação até o limite e exibe histórico com status (pendente/pago/rejeitado).
- [ ] E-mails de saque: afiliado recebe e-mail ao solicitar (pendente), quando pago e quando rejeitado; admin recebe notificação (config ou admin_email).
- [ ] Filtros por data no portal do afiliado (yfrom/yto) afetam cards, comissões e saques exibidos.

### 2. Cashback (moedas)
- [ ] Ativar em **Yoda → Cashback** (1,2%, mínimo 5.000).
- [ ] Cliente compra produto com moedas.
- [ ] Gatilho de crédito: entrega confirmada (`_yoda_delivery_status = delivered`) credita saldo `yoda_cashback_balance` e extrato **Creditado**.
- [ ] Base de cálculo usa moedas efetivamente entregues (`_yoda_coins_delivered`), com arredondamento configurável (floor/round) em **Yoda → Cashback**.
- [ ] Antifraude: ao mudar `_yoda_delivery_status` para `needs_review/failed/cancelled`, cashback do pedido é estornado e saldo ajustado.
- [ ] Chargeback/refund/cancelled impedem resgate: movimentação vira `reversed` e saldo é debitado.
- [ ] Portal **Minha Conta → Cashback** exibe saldo/extrato.
- [ ] Resgate ≥ 5.000 (mínimo absoluto): bloqueia valores menores; transout ok, movimentação `pending/redeemed`.
- [ ] Admin consegue aprovar/recusar/forçar resgates pendentes e registrar motivo; saldo ajusta corretamente.
- [ ] Alertas no portal: avisar novos créditos e status dos resgates (pendente/concluído/recusado/falha).
- [ ] E-mails enviados para cliente ao creditar cashback, registrar pedido de resgate e ao pagar o resgate.
- [ ] Pedido `refunded/cancelled/failed` estorna cashback e ajusta saldo.

### 3. Sorteios
- [ ] Criar sorteio (status `open`, início/fim opcional, limite por usuário).
- [ ] Cliente logado inscreve em **Minha Conta → Sorteios** / `[yoda_raffles]`; entry criada e limite respeitado.
- [ ] Admin clica **Sortear vencedor** ou **Encerrar e sortear**; meta de vencedor definida, status `drawn` e campanha encerrada.
- [ ] Entrega elegível gera tickets automáticos conforme regra (pedido/valor em moedas), registrados no Ledger como `raffle_ticket`.
- [ ] Garantir bloqueio: nenhum ticket duplicado por pedido e limite por usuário respeitado (verificar entradas e ledger).
- [ ] Auditoria de sorteio: ao sortear, gravar admin, horário, entry vencedora e travar a campanha (status `drawn`).
- [ ] Pagar prêmio: transout para KakoID do vencedor, gravar recibo/orderRef e log `raffle_prize` no Ledger.

### 4. Relatórios
- [ ] **Yoda → Comissões (Revendedores)**: filtros por status/afiliado/período; ações liberar/estornar funcionam.
- [ ] **Yoda → Cashback (Relatórios)**: filtros por status/usuário/período; ações estornar / marcar resgatado funcionam.

### 5. Acessos/visibilidade
- Portal Afiliado: visível para role `yoda_affiliate` e admins.
- Portal Cashback: visível para usuários logados.
- Sorteios: visível para usuários logados; inscrição respeita limite por usuário.
- Papéis garantidos:
  - `yoda_affiliate` (revendedor) criado na ativação e verificado em runtime.
  - Admin (`manage_options`) vê tudo; Cliente é a role padrão `customer`.
## Notas adicionais
- Menu **Carteira/Cashback** disponível em Minha Conta para clientes logados; carrega o portal com saldo, resgate e extrato.
- Cards mostram saldos por status: pendente, disponível, resgatado e estornado (moedas).
- Saque de afiliado é manual: a solicitação cria `yoda_aff_payout` (pending) e e-mails; o admin muda para pago/rejeitado. Não há integração com gateway (ex.: Mercado Pago) — o pagamento real deve ser executado fora e depois marcado como pago no painel.
