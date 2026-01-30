## Telas necessÃ¡rias

### 1) Admin â€“ ConfiguraÃ§Ãµes & RelatÃ³rios
- **Menu:** Yoda â†’ Revendedores / Cashback / Sorteios.
- **Revendedores (Config)**
  - Ativar/Desativar.
  - ParÃ¢metro de link (`ref`), duraÃ§Ã£o do cookie (dias).
  - ComissÃ£o padrÃ£o (%), liberaÃ§Ã£o apÃ³s N dias, base de cÃ¡lculo (total/subtotal), permitir auto-compra.
  - Elegibilidade: roles permitidas e pedido mÃ­nimo (total).
- **Revendedores (RelatÃ³rios)**
  - Lista de comissÃµes: pedido, afiliado, valor, status (a liberar/liberada/estornada), data de liberaÃ§Ã£o.
  - Filtros: perÃ­odo, status, afiliado.
  - AÃ§Ãµes: estornar, liberar manual, exportar CSV.
- **Afiliados (Resumo)**
  - Tabela com: ID, Nome, Email, Pedidos, Total vendido, ComissÃµes liberadas/pendentes, DisponÃ­vel para saque, Payouts pendentes/pagos.
  - BotÃ£o Exportar CSV (respeita filtros).
- **Cashback (Config)**
  - Ativar/Desativar.
  - Percentual (%), arredondamento, resgate mÃ­nimo (moedas).
  - Elegibilidade: roles permitidas e compra mÃ­nima (moedas) para crÃ©dito.
- **Cashback (RelatÃ³rios)**
  - Extrato de cashback: usuÃ¡rio, pedido, valor, status (creditado/estornado/resgatado/pendente), datas.
  - Filtros: perÃ­odo, status, usuÃ¡rio.
  - AÃ§Ãµes: estornar movimentaÃ§Ã£o, marcar como resgatado (manual), exportar CSV.
- **Ledger (Admin)**
  - Consulta rÃ¡pida de lanÃ§amentos (`wp_yoda_ledger`): filtros por tipo/status/usuÃ¡rio, Ãºltima pÃ¡gina (50 registros).
  - Status padronizados: `pending`, `available`, `paid`, `reversed`, `blocked`.
- **Sorteios (Admin)**
  - Criar/editar sorteio: tÃ­tulo/descriÃ§Ã£o, status (draft/open/closed/drawn), inÃ­cio/fim, mÃ¡x. inscriÃ§Ãµes por usuÃ¡rio.
  - BotÃµes â€œSortear vencedorâ€� e â€œEncerrar e sortearâ€�; exibir entry vencedora.

### 2) Portal do Afiliado
- Acesso: **Minha Conta â†’ Revendedor** ou `[yoda_affiliate_portal]`.
- Blocos:
  - Link pessoal com parÃ¢metro de referÃªncia.
  - Cards resumo: vendas atribuÃ­das, total vendido, comissÃµes liberadas.
  - Lista de pedidos indicados: mostra pedido, status do pedido, valor da comissÃ£o, status da comissÃ£o, data/liberaÃ§Ã£o.
  - Tabela de comissÃµes: pedido, valor, status (a liberar/liberada/estornada), data de liberaÃ§Ã£o.
  - Saque manual: mostra saldo disponÃ­vel/pendente/pago, formulÃ¡rio de solicitaÃ§Ã£o e histÃ³rico de saques.
  - (Futuro) filtro por perÃ­odo/status.
- Visibilidade: exibido para usuÃ¡rios com role `yoda_affiliate` e, para fins de suporte, tambÃ©m para administradores (`manage_options`).

### PapÃ©is
- **Admin**: WP nativo (`manage_options`), vÃª e gerencia tudo, inclusive portais para suporte.
- **Revendedor (Afiliado)**: role custom `yoda_affiliate` (criada na ativaÃ§Ã£o e revalidada em runtime).
- **Cliente**: role WooCommerce padrÃ£o `customer`.

### 3) Portal de Cashback (Cliente)
- Acesso: **Minha Conta â†’ Cashback** ou `[yoda_cashback_portal]`.
- Blocos:
  - Saldo disponÃ­vel + resgate mÃ­nimo.
  - Cards resumo: pendente, disponÃ­vel, resgatado, estornado (moedas).
  - FormulÃ¡rio de resgate: valor (moedas) e KakoID (prÃ©-preenche Ãºltimo usado).
  - Extrato: data, tipo (crÃ©dito/resgate), valor em moedas, status (creditado/pendente/estornado/resgatado/falhou).
  - Alertas no portal: novos créditos desde a última visita e status do último resgate (pendente/concluído/recusado/falha).
  - Admin pode aprovar/recusar/forçar processamento de resgates e registrar motivo.
  - Mensagens de erro/sucesso em linha.
## Notas adicionais
- Menu na Minha Conta Ã© exibido como **Carteira / Cashback**.
