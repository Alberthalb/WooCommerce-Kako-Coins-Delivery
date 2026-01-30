<?php
if (!defined('ABSPATH')) exit;

class Yoda_Cashback {
  const OPT_KEY = 'yoda_cashback_opts';

  const CPT_TXN = 'yoda_cashback_txn';

  const META_USER_BALANCE = 'yoda_cashback_balance'; // int (moedas)

  const META_TXN_TYPE = '_yoda_type'; // earn|redeem
  const META_TXN_STATUS = '_yoda_status'; // earned|reversed|redeemed|failed
  const META_TXN_USER_ID = '_yoda_user_id';
  const META_TXN_ORDER_ID = '_yoda_order_id';
  const META_TXN_ORDER_REF = '_yoda_order_ref';
  const META_TXN_BASE_COINS = '_yoda_base_coins';
  const META_TXN_RATE = '_yoda_rate';
  const META_TXN_AMOUNT = '_yoda_amount'; // coins
  const META_TXN_REASON = '_yoda_reason';
  const META_TXN_CREATED_AT = '_yoda_created_at';
  const META_TXN_DONE_AT = '_yoda_done_at';

  const META_LAST_KAKO_ID = 'yoda_kako_id_last';

  const NONCE_REDEEM = 'yoda_cashback_redeem';

  public function hooks(){
    add_action('init', [$this,'register_cpt']);

    // Admin
    if (is_admin()){
      add_action('admin_menu', [$this,'admin_menu']);
      add_action('admin_init', [$this,'register_settings']);
      add_action('admin_post_yoda_cashback_reverse', [$this,'handle_reverse']);
      add_action('admin_post_yoda_cashback_mark_redeemed', [$this,'handle_mark_redeemed']);
    }

    // Eventos de entrega Kako (emitidos pela classe Yoda_Fulfillment)
    add_action('yoda_kako_delivery_delivered', [$this,'on_kako_delivered'], 10, 3);
    add_action('added_post_meta', [$this,'maybe_award_from_delivery_meta'], 10, 4);
    add_action('updated_post_meta', [$this,'maybe_award_from_delivery_meta'], 10, 4);
    add_action('added_post_meta', [$this,'maybe_reverse_from_delivery_meta'], 10, 4);
    add_action('updated_post_meta', [$this,'maybe_reverse_from_delivery_meta'], 10, 4);

    // Estorno ao cancelar/reembolsar
    add_action('woocommerce_order_status_changed', [$this,'maybe_reverse_on_status'], 10, 4);
    add_action('woocommerce_order_refunded', [$this,'on_order_refunded'], 10, 2);

    // Portal do cashback
    add_action('init', [$this,'register_account_endpoint']);
    add_filter('woocommerce_account_menu_items', [$this,'add_my_account_menu_item']);
    add_action('woocommerce_account_yoda-cashback_endpoint', [$this,'render_my_account_page']);
    add_shortcode('yoda_cashback_portal', [$this,'portal_shortcode']);
  }

  public static function on_activate(){
    flush_rewrite_rules();
  }

  public static function on_deactivate(){
    flush_rewrite_rules();
  }

  public static function get_opts(){
    $defaults = [
      'enabled' => 1,
      'rate' => 1.2, // %
      'min_redeem' => 5000, // coins
      'rounding' => 'floor', // floor|round
      'award_on' => 'delivered', // delivered
      'allow_guest' => 0,
      'eligible_roles' => 'customer',
      'min_order_coins' => 0,
    ];
    $o = get_option(self::OPT_KEY, []);
    if (!is_array($o)) $o = [];
    $o = array_merge($defaults, $o);
    $o['enabled'] = (int)!!$o['enabled'];
    $o['rate'] = (float)$o['rate'];
    $o['min_redeem'] = max(0, (int)$o['min_redeem']);
    $o['rounding'] = in_array($o['rounding'], ['floor','round'], true) ? $o['rounding'] : 'floor';
    $o['award_on'] = 'delivered';
    $o['allow_guest'] = (int)!!$o['allow_guest'];
    $o['eligible_roles'] = trim((string)$o['eligible_roles']);
    $o['min_order_coins'] = max(0, (int)$o['min_order_coins']);
    return $o;
  }

  /** Cálculo centralizado de cashback em moedas */
  public static function calc_amount($base_coins, $rate_percent, $rounding = 'floor'){
    $base_coins = (float)$base_coins;
    $rate_percent = (float)$rate_percent;
    if ($base_coins <= 0 || $rate_percent <= 0) return 0;
    $raw = ($base_coins * $rate_percent) / 100.0;
    if ($rounding === 'round'){
      return (int) round($raw);
    }
    return (int) floor($raw);
  }

  /* ========================================================================
   * CPT: extrato de cashback
   * ======================================================================== */
  public function register_cpt(){
    register_post_type(self::CPT_TXN, [
      'labels' => [
        'name' => 'Cashback (Extrato)',
        'singular_name' => 'Movimento de Cashback',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => 'yoda-kako',
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  /* ========================================================================
   * Admin UI
   * ======================================================================== */
  public function admin_menu(){
    add_submenu_page(
      'yoda-kako',
      'Cashback',
      'Cashback',
      'manage_options',
      'yoda-cashback',
      [$this,'admin_page']
    );
    add_submenu_page(
      'yoda-kako',
      'Cashback (Relatório)',
      'Cashback (Relatórios)',
      'manage_options',
      'yoda-cashback-report',
      [$this,'admin_report_page']
    );
  }

  public function register_settings(){
    register_setting('yoda_cashback_group', self::OPT_KEY, [
      'sanitize_callback' => function($opts){
        $out = [];
        $out['enabled'] = !empty($opts['enabled']) ? 1 : 0;
        $out['rate'] = (float)($opts['rate'] ?? 1.2);
        $out['min_redeem'] = max(0, (int)($opts['min_redeem'] ?? 5000));
        $rounding = (string)($opts['rounding'] ?? 'floor');
        $out['rounding'] = in_array($rounding, ['floor','round'], true) ? $rounding : 'floor';
        $out['eligible_roles'] = trim((string)($opts['eligible_roles'] ?? 'customer'));
        $out['min_order_coins'] = max(0, (int)($opts['min_order_coins'] ?? 0));
        return $out;
      }
    ]);
  }

  public function admin_page(){
    if (!current_user_can('manage_options')) return;
    $o = self::get_opts();
    ?>
    <div class="wrap">
      <h1>Cashback</h1>
      <form method="post" action="options.php">
        <?php settings_fields('yoda_cashback_group'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Ativar</th>
            <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[enabled]" value="1" <?php checked(1, $o['enabled']); ?>> Habilitar cashback</label></td>
          </tr>
          <tr>
            <th scope="row"><label>Percentual (%)</label></th>
            <td>
              <input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_KEY); ?>[rate]" value="<?php echo esc_attr((float)$o['rate']); ?>">
              <p class="description">Ex.: 1,2% → carregou 1.000.000 → resgata 12.000.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label>Resgate mínimo (moedas)</label></th>
            <td><input type="number" min="0" name="<?php echo esc_attr(self::OPT_KEY); ?>[min_redeem]" value="<?php echo esc_attr((int)$o['min_redeem']); ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label>Arredondamento</label></th>
            <td>
              <select name="<?php echo esc_attr(self::OPT_KEY); ?>[rounding]">
                <option value="floor" <?php selected($o['rounding'], 'floor'); ?>>Truncar (floor)</option>
                <option value="round" <?php selected($o['rounding'], 'round'); ?>>Arredondar (round)</option>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><label>Elegibilidade (roles)</label></th>
            <td>
              <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[eligible_roles]" value="<?php echo esc_attr($o['eligible_roles']); ?>" class="regular-text">
              <p class="description">Lista separada por vírgula. Ex.: <code>customer,subscriber</code>. Vazio = qualquer role logada.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label>Compra mínima (moedas)</label></th>
            <td>
              <input type="number" min="0" name="<?php echo esc_attr(self::OPT_KEY); ?>[min_order_coins]" value="<?php echo esc_attr((int)$o['min_order_coins']); ?>">
              <p class="description">Só credita cashback se o pedido entregar pelo menos este número de moedas.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Salvar'); ?>
      </form>
    </div>
    <?php
  }

  public function admin_report_page(){
    if (!current_user_can('manage_options')) return;
    $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $user_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;
    $date_from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
    $date_to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';

    $meta_query = [];
    if ($status){
      $meta_query[] = [
        'key' => self::META_TXN_STATUS,
        'value' => $status,
        'compare' => '=',
      ];
    }
    if ($user_id > 0){
      $meta_query[] = [
        'key' => self::META_TXN_USER_ID,
        'value' => (string)$user_id,
        'compare' => '=',
      ];
    }

    $q = new WP_Query([
      'post_type' => self::CPT_TXN,
      'post_status' => 'publish',
      'posts_per_page' => 50,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => $meta_query ?: null,
      'date_query' => $this->build_date_query($date_from, $date_to),
    ]);

    ?>
    <div class="wrap">
      <h1>Relatório de Cashback</h1>
      <form method="get" style="margin:12px 0;">
        <input type="hidden" name="page" value="yoda-cashback-report">
        <label>Status:
          <select name="status">
            <option value="">(todos)</option>
            <option value="earned" <?php selected($status, 'earned'); ?>>Creditado</option>
            <option value="pending" <?php selected($status, 'pending'); ?>>Pendente</option>
            <option value="redeemed" <?php selected($status, 'redeemed'); ?>>Resgatado</option>
            <option value="reversed" <?php selected($status, 'reversed'); ?>>Estornado</option>
            <option value="failed" <?php selected($status, 'failed'); ?>>Falhou</option>
          </select>
        </label>
        <label style="margin-left:10px;">Usuário (ID):
          <input type="number" name="user" value="<?php echo esc_attr($user_id ?: ''); ?>" style="width:90px;">
        </label>
        <label style="margin-left:10px;">De:
          <input type="date" name="from" value="<?php echo esc_attr($date_from); ?>">
        </label>
        <label style="margin-left:10px;">Até:
          <input type="date" name="to" value="<?php echo esc_attr($date_to); ?>">
        </label>
        <button class="button">Filtrar</button>
      </form>

      <table class="widefat striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Usuário</th>
            <th>Pedido</th>
            <th>Tipo</th>
            <th>Valor</th>
            <th>Status</th>
            <th>Data</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($q->posts)): ?>
          <tr><td colspan="8">Nenhuma movimentação encontrada.</td></tr>
        <?php else: ?>
          <?php foreach ($q->posts as $p): ?>
            <?php
              $tid = $p->ID;
              $uid = (int)get_post_meta($tid, self::META_TXN_USER_ID, true);
              $order_id = (int)get_post_meta($tid, self::META_TXN_ORDER_ID, true);
              $type = (string)get_post_meta($tid, self::META_TXN_TYPE, true);
              $status_row = (string)get_post_meta($tid, self::META_TXN_STATUS, true);
              $amount = (int)get_post_meta($tid, self::META_TXN_AMOUNT, true);
              $created = (int)get_post_meta($tid, self::META_TXN_CREATED_AT, true);
              $type_label = $type === 'redeem' ? 'Resgate' : 'Crédito';
              $status_label = $status_row;
              if ($status_row === 'earned') $status_label = 'Creditado';
              if ($status_row === 'pending') $status_label = 'Pendente';
              if ($status_row === 'redeemed') $status_label = 'Resgatado';
              if ($status_row === 'reversed') $status_label = 'Estornado';
              if ($status_row === 'failed') $status_label = 'Falhou';
              $reverse_url = wp_nonce_url(admin_url('admin-post.php?action=yoda_cashback_reverse&tid='.$tid), 'yoda_cashback_reverse_'.$tid);
              $mark_url    = wp_nonce_url(admin_url('admin-post.php?action=yoda_cashback_mark_redeemed&tid='.$tid), 'yoda_cashback_mark_'.$tid);
            ?>
            <tr>
              <td>#<?php echo esc_html($tid); ?></td>
              <td><?php echo $uid ? '<a href="'.esc_url(get_edit_user_link($uid)).'">#'.$uid.'</a>' : '-'; ?></td>
              <td><?php echo $order_id ? '<a href="'.esc_url(get_edit_post_link($order_id)).'">#'.$order_id.'</a>' : '-'; ?></td>
              <td><?php echo esc_html($type_label); ?></td>
              <td><?php echo esc_html(number_format_i18n($amount)); ?></td>
              <td><?php echo esc_html($status_label); ?></td>
              <td><?php echo $created ? esc_html(date_i18n('Y-m-d', $created)) : '-'; ?></td>
              <td>
                <?php if ($status_row !== 'reversed' && $status_row !== 'redeemed'): ?>
                  <a class="button button-secondary" href="<?php echo esc_url($reverse_url); ?>">Estornar</a>
                <?php endif; ?>
                <?php if ($status_row === 'pending'): ?>
                  <a class="button" href="<?php echo esc_url($mark_url); ?>">Marcar resgatado</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
  }

  private function build_date_query($from, $to){
    $dq = [];
    if ($from) $dq[] = ['after' => $from.' 00:00:00', 'inclusive' => true];
    if ($to)   $dq[] = ['before'=> $to.' 23:59:59', 'inclusive' => true];
    return $dq ?: null;
  }

  public function handle_reverse(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    $tid = isset($_GET['tid']) ? (int)$_GET['tid'] : 0;
    if (!$tid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'yoda_cashback_reverse_'.$tid)) wp_die('Nonce inválido');
    $status = (string)get_post_meta($tid, self::META_TXN_STATUS, true);
    if ($status !== 'reversed'){
      $user_id = (int)get_post_meta($tid, self::META_TXN_USER_ID, true);
      $amount = (int)get_post_meta($tid, self::META_TXN_AMOUNT, true);
      update_post_meta($tid, self::META_TXN_STATUS, 'reversed');
      update_post_meta($tid, self::META_TXN_DONE_AT, time());
      if ($user_id && $amount){
        $this->add_balance($user_id, -$amount);
      }
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=yoda-cashback-report'));
    exit;
  }

  public function handle_mark_redeemed(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    $tid = isset($_GET['tid']) ? (int)$_GET['tid'] : 0;
    if (!$tid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'yoda_cashback_mark_'.$tid)) wp_die('Nonce inválido');
    $status = (string)get_post_meta($tid, self::META_TXN_STATUS, true);
    if ($status === 'pending'){
      update_post_meta($tid, self::META_TXN_STATUS, 'redeemed');
      update_post_meta($tid, self::META_TXN_DONE_AT, time());
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=yoda-cashback-report'));
    exit;
  }

  /* ========================================================================
   * Regras: crédito e estorno
   * ======================================================================== */
  public function on_kako_delivered($order, $coins_amount, $order_ref){
    $opts = self::get_opts();
    if (!$opts['enabled']) return;
    if (!($order instanceof WC_Order)) return;

    $user_id = (int)$order->get_customer_id();
    if ($user_id <= 0){
      return; // cashback só para clientes logados/registrados
    }

    if (!$this->is_order_eligible($order, $opts, $coins_amount)){
      return;
    }

    $base = (int)$coins_amount;
    if ($base <= 0) return;

    // idempotência por pedido
    $existing = $this->find_txn_for_order($order->get_id(), 'earn');
    if ($existing) return;

    $rate = (float)$opts['rate'];
    if ($rate <= 0) return;

    $amount = self::calc_amount($base, $rate, $opts['rounding']);
    if ($amount <= 0) return;

    $kakoId = (string)get_post_meta($order->get_id(), Yoda_Fulfillment::META_KAKO_ID, true);
    if ($kakoId){
      update_user_meta($user_id, self::META_LAST_KAKO_ID, $kakoId);
    }

    $txn_id = $this->create_txn([
      'type' => 'earn',
      'status' => 'earned',
      'user_id' => $user_id,
      'order_id' => $order->get_id(),
      'order_ref' => (string)$order_ref,
      'base_coins' => $base,
      'rate' => $rate,
      'amount' => $amount,
      'reason' => '',
    ]);
    if (!$txn_id) return;

    $this->add_balance($user_id, $amount);
    $order->add_order_note(sprintf('Cashback: %d moedas (%.2f%%) creditadas no saldo do cliente.', $amount, $rate));
    if (class_exists('Yoda_Ledger')){
      Yoda_Ledger::log('cashback', $order->get_id(), $user_id, $amount, Yoda_Ledger::STATUS_AVAILABLE, [
        'txn_id' => $txn_id,
        'rate' => $rate,
        'base_coins' => $base,
      ]);
    }
  }

  public function maybe_award_from_delivery_meta($meta_id, $object_id, $meta_key, $meta_value){
    if (!class_exists('Yoda_Fulfillment')) return;
    if ($meta_key !== Yoda_Fulfillment::META_DELIV_STAT) return;
    if ((string)$meta_value !== 'delivered') return;

    $order = wc_get_order((int)$object_id);
    if (!$order) return;
    $amount = (int)Yoda_Product_Meta::get_order_coins_amount($order);
    $order_ref = (string)get_post_meta($order->get_id(), Yoda_Fulfillment::META_ORDER_REF, true);
    $this->on_kako_delivered($order, $amount, $order_ref);
  }

  public function maybe_reverse_from_delivery_meta($meta_id, $object_id, $meta_key, $meta_value){
    if (!class_exists('Yoda_Fulfillment')) return;
    if ($meta_key !== Yoda_Fulfillment::META_DELIV_STAT) return;
    $val = (string)$meta_value;
    if (!in_array($val, ['needs_review','failed','cancelled','canceled'], true)) return;
    $order = wc_get_order((int)$object_id);
    if (!$order) return;
    $this->reverse_for_order($order, 'delivery_'.$val);
  }

  public function maybe_reverse_on_status($order_id, $old_status, $new_status, $order){
    if (!($order instanceof WC_Order)) $order = wc_get_order($order_id);
    if (!$order) return;
    if (!in_array($new_status, ['cancelled','canceled','failed','refunded'], true)) return;
    $this->reverse_for_order($order, 'status_'.$new_status);
  }

  public function on_order_refunded($order_id, $refund_id){
    $order = wc_get_order($order_id);
    if (!$order) return;
    $this->reverse_for_order($order, 'refund_'.$refund_id);
  }

  private function reverse_for_order(WC_Order $order, $reason){
    $earn_txn_id = $this->find_txn_for_order($order->get_id(), 'earn');
    if (!$earn_txn_id) return;

    $status = (string)get_post_meta($earn_txn_id, self::META_TXN_STATUS, true);
    if ($status !== 'earned') return;

    $user_id = (int)get_post_meta($earn_txn_id, self::META_TXN_USER_ID, true);
    $amount = (int)get_post_meta($earn_txn_id, self::META_TXN_AMOUNT, true);

    update_post_meta($earn_txn_id, self::META_TXN_STATUS, 'reversed');
    update_post_meta($earn_txn_id, self::META_TXN_REASON, (string)$reason);
    update_post_meta($earn_txn_id, self::META_TXN_DONE_AT, time());

    if ($user_id > 0 && $amount !== 0){
      $this->add_balance($user_id, -$amount);
    }
    $order->add_order_note('Cashback: estornado por '.$reason.'.');
    if (class_exists('Yoda_Ledger')){
      Yoda_Ledger::log('cashback', $order->get_id(), $user_id, -$amount, Yoda_Ledger::STATUS_REVERSED, [
        'txn_id' => $earn_txn_id,
        'reason' => $reason,
      ]);
    }
  }

  /* ========================================================================
   * Resgate (redeem): envia moedas via Kako transout
   * ======================================================================== */
  public function register_account_endpoint(){
    add_rewrite_endpoint('yoda-cashback', EP_ROOT | EP_PAGES);
  }

  public function add_my_account_menu_item($items){
    if (!is_user_logged_in()) return $items;
    $opts = self::get_opts();
    if (!$opts['enabled']) return $items;

    $out = [];
    foreach ($items as $k => $label){
      $out[$k] = $label;
      if ($k === 'orders'){
        $out['yoda-cashback'] = 'Cashback';
      }
    }
    if (!isset($out['yoda-cashback'])) $out['yoda-cashback'] = 'Cashback';
    return $out;
  }

  public function render_my_account_page(){
    echo $this->render_portal();
  }

  public function portal_shortcode($atts){
    return $this->render_portal();
  }

  private function render_portal(){
    $opts = self::get_opts();
    if (!$opts['enabled']){
      return '<div class="woocommerce-info">Cashback desativado.</div>';
    }
    if (!is_user_logged_in()){
      return '<div class="woocommerce-info">Faça login para acessar seu cashback.</div>';
    }

    $user_id = get_current_user_id();
    $balance = $this->get_balance($user_id);
    $min = (int)$opts['min_redeem'];
    $last_kako = (string)get_user_meta($user_id, self::META_LAST_KAKO_ID, true);

    $msg = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['_yoda_cashback_nonce'])){
      $msg = $this->handle_redeem_post($user_id, $balance, $last_kako, $opts);
      $balance = $this->get_balance($user_id); // atualiza
    }

    $txns = $this->get_user_txns($user_id, 50);

    ob_start();
    ?>
    <div class="yoda-cashback-portal">
      <h2>Cashback</h2>

      <?php if ($msg): ?>
        <div class="woocommerce-info"><?php echo wp_kses_post($msg); ?></div>
      <?php endif; ?>

      <div style="margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:8px;">
        <div style="opacity:.7;">Saldo disponível</div>
        <div style="font-size:22px;font-weight:800;"><?php echo esc_html(number_format_i18n($balance)); ?> moedas</div>
        <div style="opacity:.7;margin-top:6px;">Resgate mínimo: <?php echo esc_html(number_format_i18n($min)); ?> moedas</div>
      </div>

      <h3>Resgatar</h3>
      <?php if ($balance < $min): ?>
        <div class="woocommerce-info">Você ainda não atingiu o mínimo para resgate.</div>
      <?php else: ?>
        <form method="post" style="max-width:520px;margin:12px 0;">
          <?php echo wp_nonce_field(self::NONCE_REDEEM, '_yoda_cashback_nonce', true, false); ?>
          <p>
            <label>Quantidade (moedas)</label><br>
            <input type="number" name="amount" class="input-text" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($balance); ?>" step="1" required>
          </p>
          <p>
            <label>ID/username do Kako (para receber)</label><br>
            <input type="text" name="kakoid" class="input-text" value="<?php echo esc_attr($last_kako); ?>" placeholder="Ex.: 99999999" required>
            <small style="opacity:.8;display:block;margin-top:4px;">Usamos o último ID do Kako usado nas suas compras, se disponível.</small>
          </p>
          <p><button type="submit" class="button">Resgatar agora</button></p>
        </form>
      <?php endif; ?>

      <h3>Extrato</h3>
      <?php if (empty($txns)): ?>
        <div class="woocommerce-info">Nenhuma movimentação ainda.</div>
      <?php else: ?>
        <table class="shop_table shop_table_responsive my_account_orders">
          <thead><tr>
            <th>Data</th>
            <th>Tipo</th>
            <th>Moedas</th>
            <th>Status</th>
          </tr></thead>
          <tbody>
            <?php foreach ($txns as $t): ?>
              <tr>
                <td><?php echo esc_html($t['date']); ?></td>
                <td><?php echo esc_html($t['type_label']); ?></td>
                <td><?php echo esc_html(number_format_i18n($t['amount'])); ?></td>
                <td><?php echo esc_html($t['status_label']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
  }

  private function handle_redeem_post($user_id, $balance, $last_kako, $opts){
    if (!wp_verify_nonce($_POST['_yoda_cashback_nonce'], self::NONCE_REDEEM)){
      return 'Não foi possível validar o envio (nonce inválido).';
    }

    $min = (int)$opts['min_redeem'];
    $amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
    $kakoId = sanitize_text_field(wp_unslash($_POST['kakoid'] ?? ''));
    $kakoId = trim($kakoId);

    if ($amount < $min) return 'Valor abaixo do mínimo de resgate.';
    if ($amount <= 0) return 'Informe um valor válido.';
    if ($amount > $balance) return 'Saldo insuficiente.';
    if ($kakoId === '' || !preg_match('/^[A-Za-z0-9_\\-\\.]{3,32}$/', $kakoId)) return 'KakoID inválido.';

    $lock_key = 'yoda_cashback_redeem_lock_'.$user_id;
    if (get_transient($lock_key)){
      return 'Aguarde alguns segundos e tente novamente.';
    }
    set_transient($lock_key, 1, 30);

    $txn_id = $this->create_txn([
      'type' => 'redeem',
      'status' => 'pending',
      'user_id' => $user_id,
      'order_id' => 0,
      'order_ref' => '',
      'base_coins' => 0,
      'rate' => 0,
      'amount' => -$amount,
      'reason' => '',
    ]);
    if (!$txn_id) return 'Não foi possível criar o resgate.';

    // Debita imediatamente para evitar duplo clique; se falhar, devolve.
    $this->add_balance($user_id, -$amount);

    $res = $this->send_kako_cashback($kakoId, $amount, 'cashback-'.$user_id.'-'.$txn_id);
    if ($res['ok']){
      update_post_meta($txn_id, self::META_TXN_STATUS, 'redeemed');
      update_post_meta($txn_id, self::META_TXN_DONE_AT, time());
      update_user_meta($user_id, self::META_LAST_KAKO_ID, $kakoId);
      if (class_exists('Yoda_Ledger')){
        Yoda_Ledger::log('cashback', 0, $user_id, -$amount, Yoda_Ledger::STATUS_PAID, [
          'txn_id' => $txn_id,
          'kakoid' => $kakoId,
        ]);
      }
      return 'Resgate realizado com sucesso.';
    }

    // Falhou: devolve saldo e marca
    $this->add_balance($user_id, $amount);
    update_post_meta($txn_id, self::META_TXN_STATUS, 'failed');
    update_post_meta($txn_id, self::META_TXN_REASON, (string)$res['msg']);
    update_post_meta($txn_id, self::META_TXN_DONE_AT, time());
    if (class_exists('Yoda_Ledger')){
      Yoda_Ledger::log('cashback', 0, $user_id, 0, Yoda_Ledger::STATUS_BLOCKED, [
        'txn_id' => $txn_id,
        'reason' => $res['msg'],
      ]);
    }
    return 'Falha ao resgatar: '.$res['msg'];
  }

  private function send_kako_cashback($kakoId, $amount, $orderId){
    if (!class_exists('Yoda_Kako_Client')) return ['ok'=>false,'msg'=>'Cliente Kako indisponível.'];
    list($appId,$appKey,$base) = $this->get_effective_creds();
    if (!$appId || !$appKey) return ['ok'=>false,'msg'=>'Credenciais Kako não configuradas.'];

    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $ui = $client->userinfo($kakoId);
    if (is_wp_error($ui)) return ['ok'=>false,'msg'=>'userinfo HTTP: '.$ui->get_error_message()];
    $openId = $ui['json']['data']['openId'] ?? '';
    if (!$openId) return ['ok'=>false,'msg'=>'Usuário sem openId.'];

    $to = $client->transout($openId, (int)$amount, (string)$orderId);
    if (is_wp_error($to)) return ['ok'=>false,'msg'=>'transout HTTP: '.$to->get_error_message()];
    $code = $to['json']['code'] ?? -1;
    $status = (int)($to['json']['data']['status'] ?? 0);
    $msg = (string)($to['json']['msg'] ?? '');
    if ($code === 0 && $status === 2) return ['ok'=>true,'msg'=>'ok'];

    return ['ok'=>false,'msg'=>($msg ?: ('code='.$code.' status='.$status))];
  }

  private function get_effective_creds(){
    $opts   = get_option(Yoda_Admin::OPT_KEY, []);
    $appId  = (defined('KAKO_APP_ID')  && KAKO_APP_ID)  ? KAKO_APP_ID  : ($opts['app_id']  ?? '');
    $appKey = (defined('KAKO_APP_KEY') && KAKO_APP_KEY) ? KAKO_APP_KEY : ($opts['app_key'] ?? '');
    if (defined('KAKO_API_BASE') && KAKO_API_BASE) {
      $base = KAKO_API_BASE;
    } else {
      $base = $opts['base'] ?? '';
      if (!$base){
        $mode = $opts['mode'] ?? 'sandbox';
        $base = ($mode === 'production') ? 'https://api.kako.live' : 'https://api-test.kako.live';
      }
    }
    return [$appId,$appKey,$base];
  }

  /* ========================================================================
   * Storage helpers
   * ======================================================================== */
  private function get_balance($user_id){
    return (int)get_user_meta($user_id, self::META_USER_BALANCE, true);
  }

  private function add_balance($user_id, $delta){
    $cur = (int)get_user_meta($user_id, self::META_USER_BALANCE, true);
    $new = $cur + (int)$delta;
    if ($new < 0) $new = 0;
    update_user_meta($user_id, self::META_USER_BALANCE, $new);
    return $new;
  }

  private function create_txn($data){
    $type = (string)($data['type'] ?? '');
    $status = (string)($data['status'] ?? '');
    $user_id = (int)($data['user_id'] ?? 0);
    $order_id = (int)($data['order_id'] ?? 0);
    $amount = (int)($data['amount'] ?? 0);
    $base_coins = (int)($data['base_coins'] ?? 0);
    $rate = (float)($data['rate'] ?? 0);
    $order_ref = (string)($data['order_ref'] ?? '');
    $reason = (string)($data['reason'] ?? '');

    $title = ($type === 'redeem')
      ? sprintf('Resgate — user #%d', $user_id)
      : sprintf('Crédito — pedido #%d', $order_id);

    $post_id = wp_insert_post([
      'post_type' => self::CPT_TXN,
      'post_status' => 'publish',
      'post_title' => $title,
    ], true);
    if (is_wp_error($post_id)) return 0;

    update_post_meta($post_id, self::META_TXN_TYPE, $type);
    update_post_meta($post_id, self::META_TXN_STATUS, $status);
    update_post_meta($post_id, self::META_TXN_USER_ID, $user_id);
    update_post_meta($post_id, self::META_TXN_ORDER_ID, $order_id);
    update_post_meta($post_id, self::META_TXN_ORDER_REF, $order_ref);
    update_post_meta($post_id, self::META_TXN_BASE_COINS, $base_coins);
    update_post_meta($post_id, self::META_TXN_RATE, $rate);
    update_post_meta($post_id, self::META_TXN_AMOUNT, $amount);
    update_post_meta($post_id, self::META_TXN_REASON, $reason);
    update_post_meta($post_id, self::META_TXN_CREATED_AT, time());

    return (int)$post_id;
  }

  private function find_txn_for_order($order_id, $type){
    $q = new WP_Query([
      'post_type' => self::CPT_TXN,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_TXN_ORDER_ID,
          'value' => (string)(int)$order_id,
          'compare' => '=',
        ],
        [
          'key' => self::META_TXN_TYPE,
          'value' => (string)$type,
          'compare' => '=',
        ],
      ],
    ]);
    return !empty($q->posts[0]) ? (int)$q->posts[0] : 0;
  }

  private function get_user_txns($user_id, $limit){
    $q = new WP_Query([
      'post_type' => self::CPT_TXN,
      'post_status' => 'publish',
      'posts_per_page' => max(1, (int)$limit),
      'fields' => 'ids',
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        [
          'key' => self::META_TXN_USER_ID,
          'value' => (string)(int)$user_id,
          'compare' => '=',
        ],
      ],
    ]);
    $out = [];
    foreach ((array)$q->posts as $id){
      $type = (string)get_post_meta($id, self::META_TXN_TYPE, true);
      $status = (string)get_post_meta($id, self::META_TXN_STATUS, true);
      $amount = (int)get_post_meta($id, self::META_TXN_AMOUNT, true);
      $created = (int)get_post_meta($id, self::META_TXN_CREATED_AT, true);

      $type_label = ($type === 'redeem') ? 'Resgate' : 'Crédito';
      $status_label = $status;
      if ($status === 'earned') $status_label = 'Creditado';
      if ($status === 'pending') $status_label = 'Pendente';
      if ($status === 'reversed') $status_label = 'Estornado';
      if ($status === 'redeemed') $status_label = 'Resgatado';
      if ($status === 'failed') $status_label = 'Falhou';

      $out[] = [
        'id' => (int)$id,
        'date' => $created ? date_i18n('Y-m-d', $created) : '',
        'type' => $type,
        'type_label' => $type_label,
        'amount' => $amount,
        'status' => $status,
        'status_label' => $status_label,
      ];
    }
    return $out;
  }

  private function is_order_eligible(WC_Order $order, array $opts, $coins_amount){
    $roles_ok = true;
    $allowed_roles = array_filter(array_map('trim', explode(',', (string)$opts['eligible_roles'])));
    if ($allowed_roles){
      $user = get_user_by('id', (int)$order->get_customer_id());
      $roles = $user ? (array)$user->roles : [];
      $roles_ok = (bool)array_intersect($roles, $allowed_roles);
    }
    $coins_min_ok = ((int)$coins_amount) >= (int)$opts['min_order_coins'];
    return $roles_ok && $coins_min_ok;
  }
}
