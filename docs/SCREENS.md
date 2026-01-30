## Telas necessárias

### 1) Admin – Configurações & Relatórios
- **Menu:** Yoda → Revendedores / Cashback / Sorteios.
- **Revendedores (Config)**
  - Ativar/Desativar.
  - Parâmetro de link (`ref`), duração do cookie (dias).
  - Comissão padrão (%), liberação após N dias, base de cálculo (total/subtotal), permitir auto-compra.
- **Revendedores (Relatórios)**
  - Lista de comissões: pedido, afiliado, valor, status (a liberar/liberada/estornada), data de liberação.
  - Filtros: período, status, afiliado.
  - Ações: estornar, liberar manual.
- **Cashback (Config)**
  - Ativar/Desativar.
  - Percentual (%), arredondamento, resgate mínimo (moedas).
- **Cashback (Relatórios)**
  - Extrato de cashback: usuário, pedido, valor, status (creditado/estornado/resgatado/pendente), datas.
  - Filtros: período, status, usuário.
  - Ações: estornar movimentação, marcar como resgatado (manual).
- **Sorteios (Admin)**
  - Criar/editar sorteio: título/descrição, status (draft/open/closed/drawn), início/fim, máx. inscrições por usuário.
  - Botão “Sortear vencedor” e exibir entry vencedora.

### 2) Portal do Afiliado
- Acesso: **Minha Conta → Revendedor** ou `[yoda_affiliate_portal]`.
- Blocos:
  - Link pessoal com parâmetro de referência.
  - Cards resumo: vendas atribuídas, total vendido, comissões liberadas.
  - Tabela de comissões: pedido, valor, status (a liberar/liberada/estornada), data de liberação.
  - (Futuro) filtro por período/status.
- Visibilidade: exibido para usuários com role `yoda_affiliate` e, para fins de suporte, também para administradores (`manage_options`).

### Papéis
- **Admin**: WP nativo (`manage_options`), vê e gerencia tudo, inclusive portais para suporte.
- **Revendedor (Afiliado)**: role custom `yoda_affiliate` (criada na ativação e revalidada em runtime).
- **Cliente**: role WooCommerce padrão `customer`.

### 3) Portal de Cashback (Cliente)
- Acesso: **Minha Conta → Cashback** ou `[yoda_cashback_portal]`.
- Blocos:
  - Saldo disponível + resgate mínimo.
  - Formulário de resgate: valor (moedas) e KakoID (pré-preenche último usado).
  - Extrato: data, tipo (crédito/resgate), valor em moedas, status (creditado/pendente/estornado/resgatado/falhou).
  - Mensagens de erro/sucesso em linha.
