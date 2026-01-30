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
- [ ] Gatilho de crédito: status de entrega `delivered` cria comissão **A liberar** (via meta `_yoda_delivery_status`).
- [ ] Antifraude: ao mudar `_yoda_delivery_status` para `needs_review/failed/cancelled`, comissão é estornada automaticamente.
- [ ] Forçar liberação (meta `_yoda_available_at` ou botão “Liberar agora”) → status **Liberada** + nota no pedido.
- [ ] Portal **Minha Conta → Revendedor** mostra link, KPIs e tabela.
- [ ] Validações de ref: código inválido limpa cookie/sessão; autoindicação (usuário logado = afiliado) é bloqueada quando `allow_self` está desativado.

### 2. Cashback (moedas)
- [ ] Ativar em **Yoda → Cashback** (1,2%, mínimo 5.000).
- [ ] Cliente compra produto com moedas.
- [ ] Gatilho de crédito: entrega confirmada (`_yoda_delivery_status = delivered`) credita saldo `yoda_cashback_balance` e extrato **Creditado**.
- [ ] Antifraude: ao mudar `_yoda_delivery_status` para `needs_review/failed/cancelled`, cashback do pedido é estornado e saldo ajustado.
- [ ] Portal **Minha Conta → Cashback** exibe saldo/extrato.
- [ ] Resgate ≥ 5.000: transout ok, movimentação `pending/redeemed`.
- [ ] Pedido `refunded/cancelled/failed` estorna cashback e ajusta saldo.

### 3. Sorteios
- [ ] Criar sorteio (status `open`, início/fim opcional, limite por usuário).
- [ ] Cliente logado inscreve em **Minha Conta → Sorteios** / `[yoda_raffles]`; entry criada e limite respeitado.
- [ ] Admin clica **Sortear vencedor**; meta de vencedor definida, status `drawn`.

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
