<?php
if (!defined('ABSPATH')) exit;

class Yoda_Affiliates {
  const OPT_KEY = 'yoda_affiliates_opts';

  const COOKIE_KEY = 'yoda_affiliate';
  const SESSION_KEY = 'yoda_affiliate';

  const META_ORDER_AFFILIATE_ID   = '_yoda_affiliate_id';
  const META_ORDER_AFFILIATE_CODE = '_yoda_affiliate_code';
  const META_ORDER_COMMISSION_ID  = '_yoda_commission_id';

  const CPT_COMMISSION = 'yoda_commission';
  const CPT_PAYOUT     = 'yoda_aff_payout';

  const META_COMM_ORDER_ID      = '_yoda_order_id';
  const META_COMM_AFFILIATE_ID  = '_yoda_affiliate_id';
  const META_COMM_RATE          = '_yoda_rate';
  const META_COMM_BASE_AMOUNT   = '_yoda_base_amount';
  const META_COMM_AMOUNT        = '_yoda_amount';
  const META_COMM_STATUS        = '_yoda_status';
  const META_COMM_AVAILABLE_AT  = '_yoda_available_at';
  const META_COMM_RELEASED_AT   = '_yoda_released_at';
  const META_COMM_REVERSED_AT   = '_yoda_reversed_at';
  const META_COMM_REASON        = '_yoda_reason';
  const META_COMM_MODE          = '_yoda_mode'; // percent_currency|percent_coins|fixed

  const STATUS_HOLD      = 'a_liberar';
  const STATUS_RELEASED  = 'liberada';
  const STATUS_REVERSED  = 'estornada';

  // Payouts
  const META_PAYOUT_USER    = '_yoda_payout_user';
  const META_PAYOUT_AMOUNT  = '_yoda_payout_amount';
  const META_PAYOUT_STATUS  = '_yoda_payout_status'; // pending|paid|rejected
  const META_PAYOUT_REQUEST = '_yoda_payout_requested_at';
  const META_PAYOUT_PAID_AT = '_yoda_payout_paid_at';

  const CRON_RELEASE = 'yoda_affiliates_release_commissions';

  public function hooks(){
    // garante que a role exista mesmo em updates (sem reativar plugin)
    add_action('init', [$this,'ensure_runtime_setup'], 1);

    add_action('init', [$this,'register_cpt']);
    add_action('init', [$this,'register_payout_cpt']);
    add_action('init', [$this,'maybe_capture_affiliate_from_link'], 2);

    // My Account endpoint + shortcode do portal
    add_action('init', [$this,'register_account_endpoint']);
    add_filter('woocommerce_account_menu_items', [$this,'add_my_account_menu_item']);
    add_action('woocommerce_account_yoda-revendedor_endpoint', [$this,'render_my_account_page']);
    add_shortcode('yoda_affiliate_portal', [$this,'portal_shortcode']);
    add_action('admin_post_yoda_aff_request_payout', [$this,'handle_payout_request']);
    add_action('admin_post_nopriv_yoda_aff_request_payout', '__return_false');

    // Admin
    if (is_admin()){
      add_action('admin_menu', [$this,'admin_menu']);
      add_action('admin_init', [$this,'register_settings']);
      add_action('show_user_profile', [$this,'user_profile_fields']);
      add_action('edit_user_profile', [$this,'user_profile_fields']);
      add_action('personal_options_update', [$this,'save_user_profile_fields']);
      add_action('edit_user_profile_update', [$this,'save_user_profile_fields']);
      add_action('admin_post_yoda_aff_release', [$this,'handle_admin_release']);
      add_action('admin_post_yoda_aff_reverse', [$this,'handle_admin_reverse']);
      add_action('admin_post_yoda_aff_payout_paid', [$this,'handle_admin_payout_paid']);
      add_action('admin_post_yoda_aff_payout_reject', [$this,'handle_admin_payout_reject']);
    }

    // Checkout/order attribution
    add_action('woocommerce_checkout_create_order', [$this,'attach_affiliate_to_order'], 10, 2);
    add_action('woocommerce_admin_order_data_after_order_details', [$this,'admin_order_affiliate_box']);

    // Commission lifecycle
    add_action('woocommerce_order_status_changed', [$this,'on_order_status_changed'], 10, 4);
    add_action('woocommerce_order_refunded', [$this,'on_order_refunded'], 10, 2);
    add_action('added_post_meta', [$this,'maybe_create_from_delivery_meta'], 10, 4);
    add_action('updated_post_meta', [$this,'maybe_create_from_delivery_meta'], 10, 4);
    add_action('added_post_meta', [$this,'maybe_reverse_from_delivery_meta'], 10, 4);
    add_action('updated_post_meta', [$this,'maybe_reverse_from_delivery_meta'], 10, 4);

    // Cron: libera comissões
    add_action(self::CRON_RELEASE, [$this,'cron_release_commissions']);
  }

  public static function on_activate(){
    self::ensure_role();
    self::ensure_cron();
    flush_rewrite_rules();
  }

  public static function on_deactivate(){
    $ts = wp_next_scheduled(self::CRON_RELEASE);
    if ($ts){
      wp_unschedule_event($ts, self::CRON_RELEASE);
    }
    flush_rewrite_rules();
  }

  private static function ensure_role(){
    add_role('yoda_affiliate', 'Revendedor', [
      'read' => true,
    ]);
  }

  private static function ensure_cron(){
    if (!wp_next_scheduled(self::CRON_RELEASE)){
      wp_schedule_event(time() + 60, 'daily', self::CRON_RELEASE);
    }
  }

  public function ensure_runtime_setup(){
    // Role
    if (!get_role('yoda_affiliate')){
      self::ensure_role();
    }
    // Cron
    self::ensure_cron();
  }

  public static function get_opts(){
    $defaults = [
      'enabled' => 1,
      'param' => 'ref',
      'cookie_days' => 30,
      'commission_mode' => 'percent_currency', // percent_currency|percent_coins|fixed
      'default_rate' => 10.0, // % (currency or coins)
      'default_fixed' => 0.0, // valor fixo em R$
      'release_after_days' => 7,
      'base' => 'total', // total|subtotal
      'allow_self' => 0,
      'eligible_roles' => 'customer',
      'min_order_total' => 0,
      'payout_min' => 0,
      'payout_admin_email' => '',
    ];
    $o = get_option(self::OPT_KEY, []);
    if (!is_array($o)) $o = [];
    $o = array_merge($defaults, $o);
    $o['enabled'] = (int)!!$o['enabled'];
    $o['param'] = preg_replace('/[^a-zA-Z0-9_\\-]/', '', (string)$o['param']) ?: 'ref';
    $o['cookie_days'] = max(0, (int)$o['cookie_days']);
    $o['commission_mode'] = in_array($o['commission_mode'], ['percent_currency','percent_coins','fixed'], true) ? $o['commission_mode'] : 'percent_currency';
    $o['default_rate'] = (float)$o['default_rate'];
    $o['default_fixed'] = max(0, (float)$o['default_fixed']);
    $o['release_after_days'] = max(0, (int)$o['release_after_days']);
    $o['base'] = in_array($o['base'], ['total','subtotal'], true) ? $o['base'] : 'total';
    $o['allow_self'] = (int)!!$o['allow_self'];
    $o['eligible_roles'] = trim((string)$o['eligible_roles']);
    $o['min_order_total'] = max(0, (int)$o['min_order_total']);
    $o['payout_min'] = max(0, (float)$o['payout_min']);
    $o['payout_admin_email'] = sanitize_email($o['payout_admin_email']);
    return $o;
  }

  /* ========================================================================
   * CPT: Comissões
   * ======================================================================== */
  public function register_cpt(){
    register_post_type(self::CPT_COMMISSION, [
      'labels' => [
        'name' => 'Comissões (Revendedores)',
        'singular_name' => 'Comissão',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => 'yoda-kako',
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public function register_payout_cpt(){
    register_post_type(self::CPT_PAYOUT, [
      'labels' => [
        'name' => 'Payouts (Afiliados)',
        'singular_name' => 'Payout',
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
   * Admin: Settings + perfil do usuário (afiliado)
   * ======================================================================== */
  public function admin_menu(){
    add_submenu_page(
      'yoda-kako',
      'Revendedores',
      'Revendedores',
      'manage_options',
      'yoda-affiliates',
      [$this,'admin_page']
    );
    add_submenu_page(
      'yoda-kako',
      'Comissões (Relatório)',
      'Comissões (Revendedores)',
      'manage_options',
      'yoda-affiliates-report',
      [$this,'admin_report_page']
    );
    add_submenu_page(
      'yoda-kako',
      'Afiliados (Resumo)',
      'Afiliados (Resumo)',
      'manage_options',
      'yoda-affiliates-summary',
      [$this,'admin_summary_page']
    );
    add_submenu_page(
      'yoda-kako',
      'Payouts (Afiliados)',
      'Payouts (Afiliados)',
      'manage_options',
      'yoda-affiliates-payouts',
      [$this,'admin_payouts_page']
    );
  }

  public function register_settings(){
    register_setting('yoda_affiliates_group', self::OPT_KEY, [
      'sanitize_callback' => function($opts){
        $out = [];
        $out['enabled'] = !empty($opts['enabled']) ? 1 : 0;
        $out['param'] = preg_replace('/[^a-zA-Z0-9_\\-]/', '', (string)($opts['param'] ?? 'ref')) ?: 'ref';
        $out['cookie_days'] = max(0, (int)($opts['cookie_days'] ?? 30));
        $out['commission_mode'] = in_array(($opts['commission_mode'] ?? ''), ['percent_currency','percent_coins','fixed'], true) ? $opts['commission_mode'] : 'percent_currency';
        $out['default_rate'] = (float)($opts['default_rate'] ?? 10);
        $out['default_fixed'] = max(0, (float)($opts['default_fixed'] ?? 0));
        $out['release_after_days'] = max(0, (int)($opts['release_after_days'] ?? 7));
        $base = (string)($opts['base'] ?? 'total');
        $out['base'] = in_array($base, ['total','subtotal'], true) ? $base : 'total';
        $out['allow_self'] = !empty($opts['allow_self']) ? 1 : 0;
        $out['eligible_roles'] = trim((string)($opts['eligible_roles'] ?? 'customer'));
        $out['min_order_total'] = max(0, (int)($opts['min_order_total'] ?? 0));
        $out['payout_min'] = max(0, (float)($opts['payout_min'] ?? 0));
        $out['payout_admin_email'] = sanitize_email($opts['payout_admin_email'] ?? '');
        return $out;
      }
    ]);
  }

  public function admin_page(){
    if (!current_user_can('manage_options')) return;
    $o = self::get_opts();
    $param = $o['param'];
    ?>
    <div class="wrap">
      <h1>Revendedores (Afiliados)</h1>
      <form method="post" action="options.php">
        <?php settings_fields('yoda_affiliates_group'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Ativar</th>
            <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[enabled]" value="1" <?php checked(1, $o['enabled']); ?>> Habilitar sistema de revendedores</label></td>
          </tr>
          <tr>
            <th scope="row"><label>Parâmetro do link</label></th>
            <td>
              <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[param]" value="<?php echo esc_attr($param); ?>" class="regular-text">
              <p class="description">Ex.: <code>?<?php echo esc_html($param); ?>=SEU-CODIGO</code></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label>Cookie (dias)</label></th>
            <td><input type="number" min="0" name="<?php echo esc_attr(self::OPT_KEY); ?>[cookie_days]" value="<?php echo esc_attr((int)$o['cookie_days']); ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label>Modelo de comissão</label></th>
            <td>
              <select name="<?php echo esc_attr(self::OPT_KEY); ?>[commission_mode]">
                <option value="percent_currency" <?php selected($o['commission_mode'], 'percent_currency'); ?>>% sobre valor (R$)</option>
                <option value="percent_coins" <?php selected($o['commission_mode'], 'percent_coins'); ?>>% sobre moedas entregues</option>
                <option value="fixed" <?php selected($o['commission_mode'], 'fixed'); ?>>Valor fixo por pedido</option>
              </select>
              <p style="margin-top:6px;">
                <label>Taxa padrão (%, se modo percentual)</label><br>
                <input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_KEY); ?>[default_rate]" value="<?php echo esc_attr((float)$o['default_rate']); ?>">
              </p>
              <p style="margin-top:6px;">
                <label>Valor fixo padrão (R$)</label><br>
                <input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_KEY); ?>[default_fixed]" value="<?php echo esc_attr((float)$o['default_fixed']); ?>">
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label>Liberação após (dias)</label></th>
            <td><input type="number" min="0" name="<?php echo esc_attr(self::OPT_KEY); ?>[release_after_days]" value="<?php echo esc_attr((int)$o['release_after_days']); ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label>Base de cálculo</label></th>
            <td>
              <select name="<?php echo esc_attr(self::OPT_KEY); ?>[base]">
                <option value="total" <?php selected($o['base'], 'total'); ?>>Total do pedido</option>
                <option value="subtotal" <?php selected($o['base'], 'subtotal'); ?>>Subtotal (sem frete e taxas)</option>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row">Permitir auto-compra</th>
            <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[allow_self]" value="1" <?php checked(1, $o['allow_self']); ?>> Permitir que o próprio revendedor gere comissão em compras dele</label></td>
          </tr>
          <tr>
            <th scope="row"><label>Elegibilidade (roles)</label></th>
            <td>
              <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[eligible_roles]" value="<?php echo esc_attr($o['eligible_roles']); ?>" class="regular-text">
              <p class="description">Lista separada por vírgula. Ex.: <code>customer,subscriber</code>. Vazio = qualquer role logada.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label>Pedido mínimo (total)</label></th>
            <td>
              <input type="number" min="0" step="0.01" name="<?php echo esc_attr(self::OPT_KEY); ?>[min_order_total]" value="<?php echo esc_attr((float)$o['min_order_total']); ?>">
              <p class="description">Só gera comissão se o total do pedido for pelo menos este valor.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label>Valor mínimo de saque</label></th>
            <td>
              <input type="number" min="0" step="0.01" name="<?php echo esc_attr(self::OPT_KEY); ?>[payout_min]" value="<?php echo esc_attr((float)$o['payout_min']); ?>">
            </td>
          </tr>
          <tr>
            <th scope="row"><label>E-mail para notificação de saque</label></th>
            <td>
              <input type="email" name="<?php echo esc_attr(self::OPT_KEY); ?>[payout_admin_email]" value="<?php echo esc_attr($o['payout_admin_email']); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
              <p class="description">Se vazio, usa o e-mail do administrador do WordPress.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Salvar'); ?>
      </form>

      <hr>
      <h2>Como usar</h2>
      <ol>
        <li>Crie um usuário com o papel <strong>Revendedor</strong> (role: <code>yoda_affiliate</code>).</li>
        <li>No perfil do usuário, defina um <strong>Código do revendedor</strong> e (opcionalmente) uma taxa de comissão.</li>
        <li>Compartilhe o link: <code><?php echo esc_html(home_url('/')); ?>?<?php echo esc_html($param); ?>=CODIGO</code></li>
        <li>O sistema grava o revendedor no pedido e gera comissões automaticamente quando o pedido fica <em>processing</em> ou <em>completed</em>.</li>
      </ol>
    </div>
    <?php
  }

  public function admin_report_page(){
    if (!current_user_can('manage_options')) return;

    $status  = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $aff_id  = isset($_GET['affiliate']) ? (int)$_GET['affiliate'] : 0;
    $date_from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
    $date_to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';

    $meta_query = [];
    if ($status){
      $meta_query[] = [
        'key' => self::META_COMM_STATUS,
        'value' => $status,
        'compare' => '=',
      ];
    }
    if ($aff_id > 0){
      $meta_query[] = [
        'key' => self::META_COMM_AFFILIATE_ID,
        'value' => (string)$aff_id,
        'compare' => '=',
      ];
    }

    $export = isset($_GET['export']) && $_GET['export'] === 'csv';
    $q = new WP_Query([
      'post_type' => self::CPT_COMMISSION,
      'post_status' => 'publish',
      'posts_per_page' => 50,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => $meta_query ?: null,
      'date_query' => $this->build_date_query($date_from, $date_to),
    ]);

    if ($export){
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename=afiliados-comissoes.csv');
      $out = fopen('php://output', 'w');
      fputcsv($out, ['ID','Pedido','Afiliado','Valor','Status','Liberacao','OrderRef','Motivo']);
      foreach ((array)$q->posts as $p){
        $cid   = $p->ID;
        $order_id = (int)get_post_meta($cid, self::META_COMM_ORDER_ID, true);
        $affid = (int)get_post_meta($cid, self::META_COMM_AFFILIATE_ID, true);
        $amount = (float)get_post_meta($cid, self::META_COMM_AMOUNT, true);
        $stat = (string)get_post_meta($cid, self::META_COMM_STATUS, true);
        $avail = (int)get_post_meta($cid, self::META_COMM_AVAILABLE_AT, true);
        $rel   = (int)get_post_meta($cid, self::META_COMM_RELEASED_AT, true);
        $when = $rel ? date_i18n('Y-m-d', $rel) : ($avail ? date_i18n('Y-m-d', $avail) : '-');
        $order_ref = get_post_meta($order_id, Yoda_Fulfillment::META_ORDER_REF, true);
        $reason = get_post_meta($cid, self::META_COMM_REASON, true);
        fputcsv($out, [$cid, $order_id, $affid, $amount, $stat, $when, $order_ref, $reason]);
      }
      exit;
    }

    ?>
    <div class="wrap">
      <h1>Comissões de Revendedores</h1>
      <form method="get" style="margin:12px 0;">
        <input type="hidden" name="page" value="yoda-affiliates-report">
        <input type="hidden" name="post_type" value="yoda_commission">
        <label>Status:
          <select name="status">
            <option value="">(todos)</option>
            <option value="<?php echo esc_attr(self::STATUS_HOLD); ?>" <?php selected($status, self::STATUS_HOLD); ?>>A liberar</option>
            <option value="<?php echo esc_attr(self::STATUS_RELEASED); ?>" <?php selected($status, self::STATUS_RELEASED); ?>>Liberada</option>
            <option value="<?php echo esc_attr(self::STATUS_REVERSED); ?>" <?php selected($status, self::STATUS_REVERSED); ?>>Estornada</option>
          </select>
        </label>
        <label style="margin-left:10px;">Afiliado (ID):
          <input type="number" name="affiliate" value="<?php echo esc_attr($aff_id ?: ''); ?>" style="width:90px;">
        </label>
        <label style="margin-left:10px;">De:
          <input type="date" name="from" value="<?php echo esc_attr($date_from); ?>">
        </label>
        <label style="margin-left:10px;">Até:
          <input type="date" name="to" value="<?php echo esc_attr($date_to); ?>">
        </label>
        <button class="button">Filtrar</button>
        <a class="button button-primary" href="<?php echo esc_url(add_query_arg('export','csv')); ?>">Exportar CSV</a>
      </form>

      <table class="widefat striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Pedido</th>
            <th>Afiliado</th>
            <th>Valor</th>
            <th>Status</th>
            <th>Liberação</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($q->posts)): ?>
          <tr><td colspan="7">Nenhuma comissão encontrada.</td></tr>
        <?php else: ?>
          <?php foreach ($q->posts as $p): ?>
            <?php
              $cid   = $p->ID;
              $order_id = (int)get_post_meta($cid, self::META_COMM_ORDER_ID, true);
              $affid = (int)get_post_meta($cid, self::META_COMM_AFFILIATE_ID, true);
              $amount = (float)get_post_meta($cid, self::META_COMM_AMOUNT, true);
              $stat = (string)get_post_meta($cid, self::META_COMM_STATUS, true);
              $avail = (int)get_post_meta($cid, self::META_COMM_AVAILABLE_AT, true);
              $rel   = (int)get_post_meta($cid, self::META_COMM_RELEASED_AT, true);
              $status_label = $stat === self::STATUS_RELEASED ? 'Liberada' : ($stat === self::STATUS_REVERSED ? 'Estornada' : 'A liberar');
              $when = $rel ? date_i18n('Y-m-d', $rel) : ($avail ? date_i18n('Y-m-d', $avail) : '-');
              $release_url = wp_nonce_url(admin_url('admin-post.php?action=yoda_aff_release&cid='.$cid), 'yoda_aff_release_'.$cid);
              $reverse_url = wp_nonce_url(admin_url('admin-post.php?action=yoda_aff_reverse&cid='.$cid), 'yoda_aff_reverse_'.$cid);
            ?>
            <tr>
              <td>#<?php echo esc_html($cid); ?></td>
              <td><?php echo $order_id ? '<a href="'.esc_url(get_edit_post_link($order_id)).'">#'.$order_id.'</a>' : '-'; ?></td>
              <td><?php echo $affid ? '<a href="'.esc_url(get_edit_user_link($affid)).'">#'.$affid.'</a>' : '-'; ?></td>
              <td><?php echo wp_kses_post(wc_price($amount)); ?></td>
              <td><?php echo esc_html($status_label); ?></td>
              <td><?php echo esc_html($when); ?></td>
              <td>
                <?php if ($stat === self::STATUS_HOLD): ?>
                  <a class="button" href="<?php echo esc_url($release_url); ?>">Liberar agora</a>
                <?php endif; ?>
                <?php if ($stat !== self::STATUS_REVERSED): ?>
                  <a class="button button-secondary" href="<?php echo esc_url($reverse_url); ?>">Estornar</a>
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

  public function admin_summary_page(){
    if (!current_user_can('manage_options')) return;
    $export = isset($_GET['export']) && $_GET['export'] === 'csv';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $date_from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
    $date_to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';
    $paged  = max(1, (int)($_GET['paged'] ?? 1));
    $per_page = 50;
    $user_query_args = [
      'role'   => 'yoda_affiliate',
      'fields' => ['ID','display_name','user_email'],
      'number' => $per_page,
      'offset' => ($paged-1)*$per_page,
    ];
    if ($search){
      $user_query_args['search'] = '*'.$search.'*';
      $user_query_args['search_columns'] = ['user_login','user_email','display_name'];
    }
    $users = get_users($user_query_args);
    $total_users = (new WP_User_Query(array_merge($user_query_args, ['count_total'=>true,'number'=>0,'offset'=>0])))->get_total();
    $total_pages = max(1, (int)ceil($total_users / $per_page));

    $rows = [];
    foreach ($users as $u){
      $uid = $u->ID;
      $balance = $this->get_affiliate_balance_split($uid, $date_from, $date_to);
      $stats = $this->get_affiliate_stats($uid, $date_from, $date_to);
      $rows[] = [
        'id' => $uid,
        'name' => $u->display_name,
        'email' => $u->user_email,
        'orders' => $stats['orders_count'],
        'sold' => $stats['orders_total'],
        'released' => $balance['released'],
        'pending' => $balance['pending'],
        'available_payout' => $balance['available_payout'],
        'pending_payout' => $balance['pending_payout'],
        'paid_payout' => $balance['paid_payout'],
      ];
    }

    if ($export){
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename=afiliados-resumo.csv');
      $out = fopen('php://output', 'w');
      fputcsv($out, ['ID','Nome','Email','Pedidos','Total vendido','Comissões liberadas','Comissões pendentes','Disponível saque','Payouts pendentes','Payouts pagos']);
      foreach ($rows as $r){
        fputcsv($out, [
          $r['id'],
          $r['name'],
          $r['email'],
          $r['orders'],
          $r['sold'],
          $r['released'],
          $r['pending'],
          $r['available_payout'],
          $r['pending_payout'],
          $r['paid_payout'],
        ]);
      }
      exit;
    }

    ?>
    <div class="wrap">
      <h1>Afiliados (Resumo)</h1>
      <form method="get" style="margin:0 0 12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="page" value="yoda-affiliates-summary">
        <label>Buscar (nome/email):
          <input type="search" name="s" value="<?php echo esc_attr($search); ?>" />
        </label>
        <button class="button">Filtrar</button>
        <label>De:
          <input type="date" name="from" value="<?php echo esc_attr($date_from); ?>">
        </label>
        <label>Até:
          <input type="date" name="to" value="<?php echo esc_attr($date_to); ?>">
        </label>
        <a class="button button-secondary" href="<?php echo esc_url(remove_query_arg(['s','paged','export','from','to'])); ?>">Limpar</a>
        <a class="button button-primary" href="<?php echo esc_url(add_query_arg('export','csv')); ?>">Exportar CSV</a>
      </form>
      <p>Resumo de desempenho por afiliado. Página <?php echo esc_html($paged); ?> de <?php echo esc_html($total_pages); ?>.</p>
      <table class="widefat striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Nome</th>
            <th>Email</th>
            <th>Pedidos</th>
            <th>Total vendido</th>
            <th>Comissões liberadas</th>
            <th>Comissões pendentes</th>
            <th>Disponível saque</th>
            <th>Payouts pendentes</th>
            <th>Payouts pagos</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10">Nenhum afiliado.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo esc_html($r['id']); ?></td>
                <td><?php echo esc_html($r['name']); ?></td>
                <td><?php echo esc_html($r['email']); ?></td>
                <td><?php echo esc_html($r['orders']); ?></td>
                <td><?php echo wp_kses_post(wc_price($r['sold'])); ?></td>
                <td><?php echo wp_kses_post(wc_price($r['released'])); ?></td>
                <td><?php echo wp_kses_post(wc_price($r['pending'])); ?></td>
                <td><?php echo wp_kses_post(wc_price($r['available_payout'])); ?></td>
                <td><?php echo wp_kses_post(wc_price($r['pending_payout'])); ?></td>
                <td><?php echo wp_kses_post(wc_price($r['paid_payout'])); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      <?php if ($total_pages > 1): ?>
        <div class="tablenav">
          <div class="tablenav-pages">
            <?php
              $base = remove_query_arg('paged');
              $prev = $paged > 1 ? add_query_arg('paged', $paged-1, $base) : '';
              $next = $paged < $total_pages ? add_query_arg('paged', $paged+1, $base) : '';
            ?>
            <?php if ($prev): ?><a class="button" href="<?php echo esc_url($prev); ?>">&laquo; Anterior</a><?php endif; ?>
            <?php if ($next): ?><a class="button" href="<?php echo esc_url($next); ?>">Próxima &raquo;</a><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php
  }

  public function admin_payouts_page(){
    if (!current_user_can('manage_options')) return;
    $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $user_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;
    $date_from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
    $date_to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';

    $meta_query = [];
    if ($status){
      $meta_query[] = [
        'key' => self::META_PAYOUT_STATUS,
        'value' => $status,
        'compare' => '=',
      ];
    }
    if ($user_id > 0){
      $meta_query[] = [
        'key' => self::META_PAYOUT_USER,
        'value' => (string)$user_id,
        'compare' => '=',
      ];
    }

    $q = new WP_Query([
      'post_type' => self::CPT_PAYOUT,
      'post_status' => 'publish',
      'posts_per_page' => 50,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => $meta_query ?: null,
      'date_query' => $this->build_date_query($date_from, $date_to),
    ]);
    ?>
    <div class="wrap">
      <h1>Payouts de Afiliados</h1>
      <form method="get" style="margin:12px 0;">
        <input type="hidden" name="page" value="yoda-affiliates-payouts">
        <label>Status:
          <select name="status">
            <option value="">(todos)</option>
            <option value="pending" <?php selected($status, 'pending'); ?>>Pendente</option>
            <option value="paid" <?php selected($status, 'paid'); ?>>Pago</option>
            <option value="rejected" <?php selected($status, 'rejected'); ?>>Rejeitado</option>
          </select>
        </label>
        <label style="margin-left:10px;">Afiliado (ID):
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
            <th>Afiliado</th>
            <th>Valor</th>
            <th>Status</th>
            <th>Solicitado em</th>
            <th>Pago em</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($q->posts)): ?>
          <tr><td colspan="7">Nenhum payout.</td></tr>
        <?php else: ?>
          <?php foreach ($q->posts as $p): ?>
            <?php
              $pid = $p->ID;
              $uid = (int)get_post_meta($pid, self::META_PAYOUT_USER, true);
              $amount = (float)get_post_meta($pid, self::META_PAYOUT_AMOUNT, true);
              $pstatus = (string)get_post_meta($pid, self::META_PAYOUT_STATUS, true);
              $req = (int)get_post_meta($pid, self::META_PAYOUT_REQUEST, true);
              $paid = (int)get_post_meta($pid, self::META_PAYOUT_PAID_AT, true);
              $pay_url = wp_nonce_url(admin_url('admin-post.php?action=yoda_aff_payout_paid&pid='.$pid), 'yoda_aff_payout_paid_'.$pid);
              $rej_url = wp_nonce_url(admin_url('admin-post.php?action=yoda_aff_payout_reject&pid='.$pid), 'yoda_aff_payout_reject_'.$pid);
            ?>
            <tr>
              <td>#<?php echo esc_html($pid); ?></td>
              <td><?php echo $uid ? '<a href="'.esc_url(get_edit_user_link($uid)).'">#'.$uid.'</a>' : '-'; ?></td>
              <td><?php echo wp_kses_post(wc_price($amount)); ?></td>
              <td><?php echo esc_html($pstatus ?: 'pending'); ?></td>
              <td><?php echo $req ? esc_html(date_i18n('Y-m-d H:i', $req)) : '-'; ?></td>
              <td><?php echo $paid ? esc_html(date_i18n('Y-m-d H:i', $paid)) : '-'; ?></td>
              <td>
                <?php if ($pstatus === 'pending'): ?>
                  <a class="button" href="<?php echo esc_url($pay_url); ?>">Marcar pago</a>
                  <a class="button button-secondary" href="<?php echo esc_url($rej_url); ?>">Rejeitar</a>
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

  private function build_order_date_range($from, $to){
    $range = [];
    if ($from) $range['after'] = $from.' 00:00:00';
    if ($to)   $range['before'] = $to.' 23:59:59';
    if ($range){
      $range['inclusive'] = true;
      return $range;
    }
    return '';
  }

  public function handle_admin_release(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
    if (!$cid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'yoda_aff_release_'.$cid)) wp_die('Nonce inválido');
    $this->release_commission($cid);
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=yoda-affiliates-report'));
    exit;
  }

  public function handle_admin_reverse(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
    if (!$cid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'yoda_aff_reverse_'.$cid)) wp_die('Nonce inválido');
    update_post_meta($cid, self::META_COMM_STATUS, self::STATUS_REVERSED);
    update_post_meta($cid, self::META_COMM_REVERSED_AT, time());
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=yoda-affiliates-report'));
    exit;
  }

  private function get_or_create_affiliate_code($user_id){
    $code = (string)get_user_meta($user_id, 'yoda_affiliate_code', true);
    $code = trim($code);
    if ($code !== '' && preg_match('/^[a-zA-Z0-9\\-_]{4,32}$/', $code)){
      return $code;
    }
    $code = strtolower(wp_generate_password(10, false, false));
    $code = preg_replace('/[^a-z0-9]/', '', $code);
    if (strlen($code) < 6) $code .= (string)rand(100, 999);
    update_user_meta($user_id, 'yoda_affiliate_code', $code);
    return $code;
  }

  public function user_profile_fields($user){
    if (!($user instanceof WP_User)) return;
    if (!current_user_can('edit_user', $user->ID)) return;
    $roles = (array)$user->roles;
    $is_aff = in_array('yoda_affiliate', $roles, true);
    if (!$is_aff && !current_user_can('manage_options')) return;

    $code = $this->get_or_create_affiliate_code($user->ID);
    $rate = get_user_meta($user->ID, 'yoda_affiliate_rate', true);
    $rate = ($rate === '') ? '' : (float)$rate;
    ?>
    <h2>Revendedor (Afiliado)</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th><label for="yoda_affiliate_code">Código do revendedor</label></th>
        <td>
          <input type="text" name="yoda_affiliate_code" id="yoda_affiliate_code" value="<?php echo esc_attr($code); ?>" class="regular-text">
          <p class="description">Usado no link de indicação. Permitido: letras, números, <code>-</code> e <code>_</code> (4 a 32).</p>
        </td>
      </tr>
      <tr>
        <th><label for="yoda_affiliate_rate">Comissão (%)</label></th>
        <td>
          <input type="number" step="0.01" min="0" name="yoda_affiliate_rate" id="yoda_affiliate_rate" value="<?php echo esc_attr($rate); ?>">
          <p class="description">Vazio = usa a comissão padrão (configurações).</p>
        </td>
      </tr>
    </table>
    <?php
  }

  public function save_user_profile_fields($user_id){
    if (!current_user_can('edit_user', $user_id)) return;
    if (isset($_POST['yoda_affiliate_code'])){
      $code = sanitize_text_field(wp_unslash($_POST['yoda_affiliate_code']));
      $code = trim($code);
      if ($code === '' || !preg_match('/^[a-zA-Z0-9\\-_]{4,32}$/', $code)){
        // mantém o existente (ou gera)
        $this->get_or_create_affiliate_code($user_id);
      } else {
        update_user_meta($user_id, 'yoda_affiliate_code', $code);
      }
    }
    if (isset($_POST['yoda_affiliate_rate'])){
      $rate_raw = wp_unslash($_POST['yoda_affiliate_rate']);
      $rate_raw = trim((string)$rate_raw);
      if ($rate_raw === ''){
        delete_user_meta($user_id, 'yoda_affiliate_rate');
      } else {
        update_user_meta($user_id, 'yoda_affiliate_rate', (float)$rate_raw);
      }
    }
  }

  /* ========================================================================
   * Tracking: captura ?ref=CODE e persiste cookie/sessão
   * ======================================================================== */
  public function maybe_capture_affiliate_from_link(){
    $opts = self::get_opts();
    if (!$opts['enabled']) return;

    $param = $opts['param'];
    if (empty($_GET[$param])) return;

    $code = sanitize_text_field(wp_unslash($_GET[$param]));
    $code = trim($code);
    if ($code === '' || !preg_match('/^[a-zA-Z0-9\\-_]{4,32}$/', $code)){
      $this->clear_tracking();
      return;
    }

    $affiliate_id = $this->find_affiliate_id_by_code($code);
    if (!$affiliate_id){
      $this->clear_tracking();
      return;
    }

    // impede autoindicação se usuário logado for o próprio afiliado
    if (is_user_logged_in() && get_current_user_id() === (int)$affiliate_id && !$opts['allow_self']){
      $this->clear_tracking();
      return;
    }

    $payload = wp_json_encode([
      'id' => (int)$affiliate_id,
      'code' => $code,
      'ts' => time(),
    ]);

    $days = (int)$opts['cookie_days'];
    $expires = $days > 0 ? time() + ($days * DAY_IN_SECONDS) : 0;

    if (function_exists('wc_setcookie')) {
      wc_setcookie(self::COOKIE_KEY, $payload, $expires);
    } else {
      @setcookie(self::COOKIE_KEY, $payload, $expires, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
    }

    if (function_exists('WC') && WC() && WC()->session){
      WC()->session->set(self::SESSION_KEY, $payload);
    }
  }

  private function get_tracked_affiliate(){
    $opts = self::get_opts();
    if (!$opts['enabled']) return null;

    $payload = null;
    if (function_exists('WC') && WC() && WC()->session){
      $payload = WC()->session->get(self::SESSION_KEY);
    }
    if (!$payload && isset($_COOKIE[self::COOKIE_KEY])){
      $payload = wp_unslash($_COOKIE[self::COOKIE_KEY]);
    }

    if (!$payload) return null;
    $data = json_decode((string)$payload, true);
    if (!is_array($data)) return null;

    $affiliate_id = (int)($data['id'] ?? 0);
    $code = (string)($data['code'] ?? '');
    if ($affiliate_id <= 0 || $code === '') {
      $this->clear_tracking();
      return null;
    }
    if (is_user_logged_in() && get_current_user_id() === $affiliate_id && !$opts['allow_self']){
      $this->clear_tracking();
      return null;
    }
    return ['id'=>$affiliate_id,'code'=>$code];
  }

  private function find_affiliate_id_by_code($code){
    $code = trim((string)$code);
    if ($code === '') return 0;
    $users = get_users([
      'number' => 1,
      'fields' => 'ID',
      'meta_key' => 'yoda_affiliate_code',
      'meta_value' => $code,
      'meta_compare' => '=',
    ]);
    if (empty($users[0])) return 0;
    return (int)$users[0];
  }

  private function clear_tracking(){
    if (function_exists('WC') && WC() && WC()->session){
      WC()->session->set(self::SESSION_KEY, null);
    }
    @setcookie(self::COOKIE_KEY, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
  }

  /* ========================================================================
   * Checkout/order: grava afiliação no pedido
   * ======================================================================== */
  public function attach_affiliate_to_order($order, $data){
    if (!($order instanceof WC_Order)) return;
    $opts = self::get_opts();
    if (!$opts['enabled']) return;

    $tracked = $this->get_tracked_affiliate();
    if (!$tracked) return;

    $affiliate_id = (int)$tracked['id'];
    $code = (string)$tracked['code'];

    if (!$opts['allow_self'] && is_user_logged_in() && get_current_user_id() === $affiliate_id){
      return;
    }

    $order->update_meta_data(self::META_ORDER_AFFILIATE_ID, $affiliate_id);
    $order->update_meta_data(self::META_ORDER_AFFILIATE_CODE, $code);
  }

  public function admin_order_affiliate_box($order){
    if (!current_user_can('manage_woocommerce')) return;
    if (!($order instanceof WC_Order)) return;
    $aid = (int)get_post_meta($order->get_id(), self::META_ORDER_AFFILIATE_ID, true);
    if (!$aid) return;
    $code = (string)get_post_meta($order->get_id(), self::META_ORDER_AFFILIATE_CODE, true);
    $user = get_user_by('id', $aid);
    $name = $user ? ($user->display_name ?: $user->user_login) : ('#'.$aid);
    echo '<p><strong>Revendedor:</strong> '.esc_html($name).' ('.esc_html($code).')</p>';
  }

  /* ========================================================================
   * Comissões: criação, liberação, estorno
   * ======================================================================== */
  public function on_order_status_changed($order_id, $old_status, $new_status, $order){
    if (!($order instanceof WC_Order)) $order = wc_get_order($order_id);
    if (!$order) return;

    $aid = (int)get_post_meta($order->get_id(), self::META_ORDER_AFFILIATE_ID, true);
    if (!$aid) return;

    if (in_array($new_status, ['processing','completed'], true)){
      $this->maybe_create_commission_for_order($order);
      return;
    }

    if (in_array($new_status, ['cancelled','canceled','failed','refunded'], true)){
      $this->reverse_commission_for_order($order, 'status_'.$new_status);
      return;
    }
  }

  public function on_order_refunded($order_id, $refund_id){
    $order = wc_get_order($order_id);
    if (!$order) return;
    $this->reverse_commission_for_order($order, 'refund_'.$refund_id);
  }

  public function maybe_create_from_delivery_meta($meta_id, $object_id, $meta_key, $meta_value){
    if ($meta_key !== Yoda_Fulfillment::META_DELIV_STAT) return;
    if ((string)$meta_value !== 'delivered') return;
    $order = wc_get_order((int)$object_id);
    if (!$order) return;
    $this->maybe_create_commission_for_order($order);
  }

  public function maybe_reverse_from_delivery_meta($meta_id, $object_id, $meta_key, $meta_value){
    if ($meta_key !== Yoda_Fulfillment::META_DELIV_STAT) return;
    $val = (string)$meta_value;
    if (!in_array($val, ['needs_review','failed','cancelled','canceled'], true)) return;
    $order = wc_get_order((int)$object_id);
    if (!$order) return;
    $this->reverse_commission_for_order($order, 'delivery_'.$val);
  }

  private function maybe_create_commission_for_order(WC_Order $order){
    $commission_id = (int)get_post_meta($order->get_id(), self::META_ORDER_COMMISSION_ID, true);
    if ($commission_id && get_post($commission_id)) return $commission_id;

    $opts = self::get_opts();
    if (!$opts['enabled']) return 0;

    $aid = (int)get_post_meta($order->get_id(), self::META_ORDER_AFFILIATE_ID, true);
    $code = (string)get_post_meta($order->get_id(), self::META_ORDER_AFFILIATE_CODE, true);
    if (!$aid || !$code) return 0;

    if (!$opts['allow_self'] && (int)$order->get_customer_id() === $aid){
      return 0;
    }

    if (!$this->is_order_eligible($order, $opts)){
      return 0;
    }

    $mode = $opts['commission_mode'];
    $rate_meta = get_user_meta($aid, 'yoda_affiliate_rate', true);
    $rate = ($rate_meta === '' ? ($mode === 'fixed' ? (float)$opts['default_fixed'] : (float)$opts['default_rate']) : (float)$rate_meta);
    $base_amount = 0;
    $amount = 0;

    if ($mode === 'fixed'){
      $amount = max(0, $rate);
    } elseif ($mode === 'percent_coins'){
      $base_amount = $this->get_order_coins_amount($order);
      if ($base_amount > 0 && $rate > 0){
        $amount = self::calc_commission($base_amount, $rate);
      }
    } else { // percent_currency
      $base_amount = $this->get_commission_base_amount($order, $opts['base']);
      if ($base_amount > 0 && $rate > 0){
        $amount = self::calc_commission($base_amount, $rate);
      }
    }

    if ($amount <= 0) return 0;

    $available_at = time() + ((int)$opts['release_after_days'] * DAY_IN_SECONDS);

    $title = sprintf('Pedido #%s — %s', $order->get_order_number(), $code);
    $commission_id = wp_insert_post([
      'post_type' => self::CPT_COMMISSION,
      'post_status' => 'publish',
      'post_title' => $title,
    ], true);
    if (is_wp_error($commission_id)) return 0;

    update_post_meta($commission_id, self::META_COMM_ORDER_ID, $order->get_id());
    update_post_meta($commission_id, self::META_COMM_AFFILIATE_ID, $aid);
    update_post_meta($commission_id, self::META_COMM_RATE, $rate);
    update_post_meta($commission_id, self::META_COMM_BASE_AMOUNT, $base_amount);
    update_post_meta($commission_id, self::META_COMM_AMOUNT, $amount);
    update_post_meta($commission_id, self::META_COMM_MODE, $mode);
    update_post_meta($commission_id, self::META_COMM_STATUS, self::STATUS_HOLD);
    update_post_meta($commission_id, self::META_COMM_AVAILABLE_AT, $available_at);

    update_post_meta($order->get_id(), self::META_ORDER_COMMISSION_ID, $commission_id);
    if (class_exists('Yoda_Ledger')){
      Yoda_Ledger::log('affiliate', $order->get_id(), $aid, $amount, Yoda_Ledger::STATUS_PENDING, [
        'commission_id' => $commission_id,
        'rate' => $rate,
        'base_amount' => $base_amount,
        'code' => $code,
        'mode' => $mode,
      ]);
    }
    $note_mode = ($mode === 'fixed')
      ? sprintf('valor fixo de %s', wc_price($amount))
      : (($mode === 'percent_coins')
        ? sprintf('%.2f%% sobre %s moedas', $rate, number_format_i18n($base_amount))
        : sprintf('%.2f%% sobre %s', $rate, wc_price($base_amount)));
    $order->add_order_note(sprintf(
      'Revendedor %s gerou comissão (%s). Valor: %s. Libera em %s.',
      $code,
      $note_mode,
      wc_price($amount),
      date_i18n('Y-m-d', $available_at)
    ));

    return (int)$commission_id;
  }

  private function get_commission_base_amount(WC_Order $order, $base){
    if ($base === 'subtotal'){
      $subtotal = (float)$order->get_subtotal();
      $discount = (float)$order->get_discount_total();
      $subtotal = max(0, $subtotal - $discount);
      return $subtotal;
    }
    return (float)$order->get_total();
  }

  /** Cálculo centralizado de comissão em moeda */
  public static function calc_commission($base_amount, $rate_percent){
    $base_amount = (float)$base_amount;
    $rate_percent = (float)$rate_percent;
    if ($base_amount <= 0 || $rate_percent <= 0) return 0;
    return round(($base_amount * $rate_percent) / 100, wc_get_price_decimals());
  }

  private function get_order_coins_amount(WC_Order $order){
    $delivered = 0;
    if (class_exists('Yoda_Fulfillment')){
      $delivered = (int)get_post_meta($order->get_id(), Yoda_Fulfillment::META_COINS_DELIVERED, true);
    }
    if ($delivered > 0) return $delivered;
    if (class_exists('Yoda_Product_Meta') && method_exists('Yoda_Product_Meta', 'get_order_coins_amount')){
      return (int)Yoda_Product_Meta::get_order_coins_amount($order);
    }
    return 0;
  }

  private function reverse_commission_for_order(WC_Order $order, $reason){
    $commission_id = (int)get_post_meta($order->get_id(), self::META_ORDER_COMMISSION_ID, true);
    if (!$commission_id) return 0;
    if (!get_post($commission_id)) return 0;

    $status = (string)get_post_meta($commission_id, self::META_COMM_STATUS, true);
    if ($status === self::STATUS_REVERSED) return $commission_id;

    update_post_meta($commission_id, self::META_COMM_STATUS, self::STATUS_REVERSED);
    update_post_meta($commission_id, self::META_COMM_REVERSED_AT, time());
    update_post_meta($commission_id, self::META_COMM_REASON, (string)$reason);

    $order->add_order_note('Comissão do revendedor estornada. Motivo: '.$reason);
    if (class_exists('Yoda_Ledger')){
      Yoda_Ledger::log('affiliate', $order->get_id(), (int)get_post_meta($commission_id, self::META_COMM_AFFILIATE_ID, true), -(float)get_post_meta($commission_id, self::META_COMM_AMOUNT, true), Yoda_Ledger::STATUS_REVERSED, [
        'commission_id' => $commission_id,
        'reason' => $reason,
      ]);
    }
    return $commission_id;
  }

  public function cron_release_commissions(){
    $opts = self::get_opts();
    if (!$opts['enabled']) return;

    $now = time();
    $q = new WP_Query([
      'post_type' => self::CPT_COMMISSION,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_COMM_STATUS,
          'value' => self::STATUS_HOLD,
          'compare' => '=',
        ],
        [
          'key' => self::META_COMM_AVAILABLE_AT,
          'value' => $now,
          'compare' => '<=',
          'type' => 'NUMERIC',
        ],
      ],
    ]);

    if (empty($q->posts)) return;
    foreach ($q->posts as $commission_id){
      $this->release_commission((int)$commission_id);
    }
  }

  /* ========================================================================
   * Payouts (saque manual)
   * ======================================================================== */
  public function handle_payout_request(){
    if (!is_user_logged_in()) wp_die('Faça login');
    $user_id = get_current_user_id();
    check_admin_referer('yoda_aff_payout_request');
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    if ($amount <= 0){
      wp_safe_redirect(add_query_arg('ymsg', rawurlencode('Informe um valor válido.'), wp_get_referer() ?: home_url())); exit;
    }
    $balance = $this->get_affiliate_balance_split($user_id);
    $available = max(0, $balance['released'] - $balance['pending_payout'] - $balance['paid_payout']);
    $opts = self::get_opts();
    $min = (float)$opts['payout_min'];
    if ($amount < $min){
      wp_safe_redirect(add_query_arg('ymsg', rawurlencode('Valor mínimo de saque: '.wc_price($min)), wp_get_referer() ?: home_url())); exit;
    }
    if ($amount > $available){
      wp_safe_redirect(add_query_arg('ymsg', rawurlencode('Valor acima do disponível.'), wp_get_referer() ?: home_url())); exit;
    }
    $title = sprintf('Payout afiliado #%d - %s', $user_id, date_i18n('Y-m-d'));
    $pid = wp_insert_post([
      'post_type' => self::CPT_PAYOUT,
      'post_status' => 'publish',
      'post_title' => $title,
    ], true);
    if (is_wp_error($pid)){
      wp_safe_redirect(add_query_arg('ymsg', rawurlencode('Erro ao criar payout.'), wp_get_referer() ?: home_url())); exit;
    }
    update_post_meta($pid, self::META_PAYOUT_USER, $user_id);
    update_post_meta($pid, self::META_PAYOUT_AMOUNT, $amount);
    update_post_meta($pid, self::META_PAYOUT_STATUS, 'pending');
    update_post_meta($pid, self::META_PAYOUT_REQUEST, time());

    if (class_exists('Yoda_Ledger')){
      Yoda_Ledger::log('affiliate_payout', $pid, $user_id, -$amount, Yoda_Ledger::STATUS_PENDING, []);
    }

    $this->notify_affiliate_payout($user_id, $amount, 'pendente', $pid);
    $this->notify_admin_payout($user_id, $amount, 'pending', $pid);

    wp_safe_redirect(add_query_arg('ymsg', rawurlencode('Solicitação enviada!'), wp_get_referer() ?: home_url())); exit;
  }

  public function handle_admin_payout_paid(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
    if (!$pid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'yoda_aff_payout_paid_'.$pid)) wp_die('Nonce inválido');
    update_post_meta($pid, self::META_PAYOUT_STATUS, 'paid');
    update_post_meta($pid, self::META_PAYOUT_PAID_AT, time());
    $amount = (float)get_post_meta($pid, self::META_PAYOUT_AMOUNT, true);
    $uid = (int)get_post_meta($pid, self::META_PAYOUT_USER, true);
    if (class_exists('Yoda_Ledger')){
      Yoda_Ledger::log('affiliate_payout', $pid, $uid, -$amount, Yoda_Ledger::STATUS_PAID, []);
    }
    $this->notify_affiliate_payout($uid, $amount, 'pago', $pid);
    $this->notify_admin_payout($uid, $amount, 'paid', $pid);
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=yoda-affiliates-payouts'));
    exit;
  }

  public function handle_admin_payout_reject(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
    if (!$pid || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'yoda_aff_payout_reject_'.$pid)) wp_die('Nonce inválido');
    update_post_meta($pid, self::META_PAYOUT_STATUS, 'rejected');
    $amount = (float)get_post_meta($pid, self::META_PAYOUT_AMOUNT, true);
    $uid = (int)get_post_meta($pid, self::META_PAYOUT_USER, true);
    if (class_exists('Yoda_Ledger')){
      Yoda_Ledger::log('affiliate_payout', $pid, $uid, 0, Yoda_Ledger::STATUS_BLOCKED, []);
    }
    $this->notify_affiliate_payout($uid, $amount, 'rejeitado', $pid);
    $this->notify_admin_payout($uid, $amount, 'rejected', $pid);
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=yoda-affiliates-payouts'));
    exit;
  }

  private function release_commission($commission_id){
    $status = (string)get_post_meta($commission_id, self::META_COMM_STATUS, true);
    if ($status !== self::STATUS_HOLD) return false;

    update_post_meta($commission_id, self::META_COMM_STATUS, self::STATUS_RELEASED);
    update_post_meta($commission_id, self::META_COMM_RELEASED_AT, time());

    $order_id = (int)get_post_meta($commission_id, self::META_COMM_ORDER_ID, true);
    if ($order_id){
      $order = wc_get_order($order_id);
      if ($order){
        $order->add_order_note('Comissão do revendedor liberada automaticamente.');
      }
    }
    if (class_exists('Yoda_Ledger')){
      $aid = (int)get_post_meta($commission_id, self::META_COMM_AFFILIATE_ID, true);
      $amount = (float)get_post_meta($commission_id, self::META_COMM_AMOUNT, true);
      Yoda_Ledger::log('affiliate', $order_id, $aid, $amount, Yoda_Ledger::STATUS_PAID, [
        'commission_id' => $commission_id,
        'mode' => (string)get_post_meta($commission_id, self::META_COMM_MODE, true),
      ]);
    }
    return true;
  }

  /* ========================================================================
   * Portal do revendedor (Minha Conta + shortcode)
   * ======================================================================== */
  public function register_account_endpoint(){
    add_rewrite_endpoint('yoda-revendedor', EP_ROOT | EP_PAGES);
  }

  public function add_my_account_menu_item($items){
    $user = wp_get_current_user();
    if (!$user || !$user->ID) return $items;
    if (!in_array('yoda_affiliate', (array)$user->roles, true)) return $items;

    $out = [];
    foreach ($items as $k => $label){
      $out[$k] = $label;
      if ($k === 'orders'){
        $out['yoda-revendedor'] = 'Revendedor';
      }
    }
    if (!isset($out['yoda-revendedor'])){
      $out['yoda-revendedor'] = 'Revendedor';
    }
    return $out;
  }

  public function render_my_account_page(){
    if (!is_user_logged_in()){
      echo '<p>Faça login para acessar.</p>';
      return;
    }
    $user = wp_get_current_user();
    if ($this->is_affiliate_or_admin($user)){
      echo $this->render_affiliate_dashboard($user->ID);
    } else {
      echo '<p>Área exclusiva para revendedores.</p>';
    }
  }

  public function portal_shortcode($atts){
    if (!is_user_logged_in()){
      return '<div class="woocommerce-info">Faça login para acessar o portal do revendedor.</div>';
    }
    $user = wp_get_current_user();
    if ($this->is_affiliate_or_admin($user)){
      return $this->render_affiliate_dashboard($user->ID);
    }
    return '<div class="woocommerce-info">Área exclusiva para revendedores.</div>';
  }

  private function render_affiliate_dashboard($affiliate_id){
    $opts = self::get_opts();
    if (!$opts['enabled']){
      return '<div class="woocommerce-info">Sistema de revendedores desativado.</div>';
    }

    $code = $this->get_or_create_affiliate_code($affiliate_id);
    $link = add_query_arg([$opts['param'] => $code], home_url('/'));

    $date_from = isset($_GET['yfrom']) ? sanitize_text_field(wp_unslash($_GET['yfrom'])) : '';
    $date_to   = isset($_GET['yto'])   ? sanitize_text_field(wp_unslash($_GET['yto']))   : '';

    $stats = $this->get_affiliate_stats($affiliate_id, $date_from, $date_to);
    $balance = $this->get_affiliate_balance_split($affiliate_id, $date_from, $date_to);
    $commissions = $this->get_affiliate_commissions($affiliate_id, 50, $date_from, $date_to);
    $payouts = $this->get_affiliate_payouts($affiliate_id, 20, $date_from, $date_to);
    $available_payout = $balance['available_payout'];
    $msg = isset($_GET['ymsg']) ? sanitize_text_field(wp_unslash($_GET['ymsg'])) : '';

    ob_start();
    ?>
    <div class="yoda-affiliate-dashboard">
      <h2>Portal do Revendedor</h2>

      <div style="margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:8px;">
        <p style="margin:0 0 6px;"><strong>Seu link:</strong></p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <code id="yoda-aff-link" style="padding:4px 6px;display:inline-block;"><?php echo esc_html($link); ?></code>
          <button type="button" class="button" onclick="(function(){var t=document.getElementById('yoda-aff-link'); if(!t) return; navigator.clipboard.writeText(t.textContent||t.innerText||'').then(function(){alert('Link copiado!');}); })();">Copiar</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(4, minmax(0, 1fr));gap:12px;margin:12px 0;">
        <div style="padding:12px;border:1px solid #eee;border-radius:8px;">
          <div style="opacity:.7;">Vendas atribuídas</div>
          <div style="font-size:20px;font-weight:700;"><?php echo esc_html((int)$stats['orders_count']); ?></div>
        </div>
        <div style="padding:12px;border:1px solid #eee;border-radius:8px;">
          <div style="opacity:.7;">Total vendido</div>
          <div style="font-size:20px;font-weight:700;"><?php echo wp_kses_post(wc_price($stats['orders_total'])); ?></div>
        </div>
        <div style="padding:12px;border:1px solid #eee;border-radius:8px;">
          <div style="opacity:.7;">Comissões liberadas</div>
          <div style="font-size:20px;font-weight:700;"><?php echo wp_kses_post(wc_price($balance['released'])); ?></div>
        </div>
        <div style="padding:12px;border:1px solid #eee;border-radius:8px;">
          <div style="opacity:.7;">Comissões pendentes</div>
          <div style="font-size:20px;font-weight:700;"><?php echo wp_kses_post(wc_price($balance['pending'])); ?></div>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="woocommerce-info" style="margin:8px 0;"><?php echo esc_html($msg); ?></div>
      <?php endif; ?>

      <div style="padding:12px;border:1px solid #ddd;border-radius:8px;margin:12px 0;">
        <h3 style="margin-top:0;">Saque (payout)</h3>
        <p style="margin:0 0 8px;">Disponível para saque: <strong><?php echo wp_kses_post(wc_price($available_payout)); ?></strong> &nbsp;|&nbsp; Pendentes: <?php echo wp_kses_post(wc_price($balance['pending_payout'])); ?> &nbsp;|&nbsp; Pagos: <?php echo wp_kses_post(wc_price($balance['paid_payout'])); ?></p>
        <?php if ($available_payout <= 0): ?>
          <div class="woocommerce-info">Você ainda não tem saldo disponível para saque.</div>
        <?php else: ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0;">
            <?php wp_nonce_field('yoda_aff_payout_request'); ?>
            <input type="hidden" name="action" value="yoda_aff_request_payout">
            <label>Valor:
              <input type="number" name="amount" min="0.01" step="0.01" max="<?php echo esc_attr($available_payout); ?>" value="<?php echo esc_attr($available_payout); ?>" style="width:120px;">
            </label>
            <button class="button button-primary" type="submit">Solicitar saque</button>
          </form>
        <?php endif; ?>
      </div>

      <h3>Comissões</h3>
      <form method="get" style="margin:0 0 10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <?php
          $base_url = remove_query_arg(['ystatus']);
          $current_status = isset($_GET['ystatus']) ? sanitize_text_field(wp_unslash($_GET['ystatus'])) : '';
        ?>
        <label>Status:
          <select name="ystatus">
            <option value="">(todos)</option>
            <option value="<?php echo esc_attr(self::STATUS_HOLD); ?>" <?php selected($current_status, self::STATUS_HOLD); ?>>A liberar</option>
            <option value="<?php echo esc_attr(self::STATUS_RELEASED); ?>" <?php selected($current_status, self::STATUS_RELEASED); ?>>Liberada</option>
            <option value="<?php echo esc_attr(self::STATUS_REVERSED); ?>" <?php selected($current_status, self::STATUS_REVERSED); ?>>Estornada</option>
          </select>
        </label>
        <label>De:
          <input type="date" name="yfrom" value="<?php echo esc_attr($date_from); ?>">
        </label>
        <label>Até:
          <input type="date" name="yto" value="<?php echo esc_attr($date_to); ?>">
        </label>
        <button class="button">Filtrar</button>
        <a class="button button-secondary" href="<?php echo esc_url(remove_query_arg(['ystatus','yfrom','yto'])); ?>">Limpar filtro</a>
      </form>
      <?php if (empty($commissions)): ?>
        <div class="woocommerce-info">Nenhuma comissão encontrada ainda.</div>
      <?php else: ?>
        <table class="shop_table shop_table_responsive my_account_orders">
          <thead><tr>
            <th>Pedido</th>
            <th>Status pedido</th>
            <th>Valor comissão</th>
            <th>Status comissão</th>
            <th>Liberação</th>
          </tr></thead>
          <tbody>
          <?php foreach ($commissions as $c): ?>
            <tr>
              <td>#<?php echo esc_html($c['order_number']); ?></td>
              <td><?php echo esc_html($c['order_status']); ?></td>
              <td><?php echo wp_kses_post(wc_price($c['amount'])); ?></td>
              <td><?php echo esc_html($c['status_label']); ?></td>
              <td><?php echo esc_html($c['when']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <h3 style="margin-top:24px;">Solicitações de saque</h3>
      <?php if (empty($payouts)): ?>
        <div class="woocommerce-info">Nenhum saque solicitado ainda.</div>
      <?php else: ?>
        <table class="shop_table shop_table_responsive my_account_orders">
          <thead><tr>
            <th>ID</th>
            <th>Valor</th>
            <th>Status</th>
            <th>Solicitado</th>
            <th>Pago em</th>
          </tr></thead>
          <tbody>
          <?php foreach ($payouts as $p): ?>
            <tr>
              <td>#<?php echo esc_html($p['id']); ?></td>
              <td><?php echo wp_kses_post(wc_price($p['amount'])); ?></td>
              <td><?php echo esc_html($p['status_label']); ?></td>
              <td><?php echo esc_html($p['requested']); ?></td>
              <td><?php echo esc_html($p['paid_at']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
  }

  private function get_affiliate_stats($affiliate_id, $date_from = null, $date_to = null){
    $orders = wc_get_orders([
      'limit' => 200,
      'status' => ['processing','completed'],
      'type' => 'shop_order',
      'return' => 'objects',
      'meta_query' => [
        [
          'key' => self::META_ORDER_AFFILIATE_ID,
          'value' => (string)(int)$affiliate_id,
          'compare' => '=',
        ]
      ],
      'date_created' => $this->build_order_date_range($date_from, $date_to),
    ]);

    $count = 0;
    $total = 0.0;
    foreach ($orders as $o){
      $count++;
      $total += (float)$o->get_total();
    }

    $released_total = 0.0;
    $q = new WP_Query([
      'post_type' => self::CPT_COMMISSION,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_COMM_AFFILIATE_ID,
          'value' => (string)(int)$affiliate_id,
          'compare' => '=',
        ],
        [
          'key' => self::META_COMM_STATUS,
          'value' => self::STATUS_RELEASED,
          'compare' => '=',
        ],
      ],
    ]);
    foreach ((array)$q->posts as $cid){
      $released_total += (float)get_post_meta($cid, self::META_COMM_AMOUNT, true);
    }

    return [
      'orders_count' => $count,
      'orders_total' => $total,
      'released_total' => $released_total,
    ];
  }

  private function get_affiliate_commissions($affiliate_id, $limit, $date_from = null, $date_to = null){
    $status_filter = isset($_GET['ystatus']) ? sanitize_text_field(wp_unslash($_GET['ystatus'])) : '';
    $query_args = [
      'post_type' => self::CPT_COMMISSION,
      'post_status' => 'publish',
      'posts_per_page' => max(1, (int)$limit),
      'fields' => 'ids',
      'meta_key' => self::META_COMM_AVAILABLE_AT,
      'orderby' => 'meta_value_num',
      'order' => 'DESC',
      'meta_query' => [
        [
          'key' => self::META_COMM_AFFILIATE_ID,
          'value' => (string)(int)$affiliate_id,
          'compare' => '=',
        ]
      ],
      'date_query' => $this->build_date_query($date_from, $date_to),
    ];
    if ($status_filter){
      $query_args['meta_query'][] = [
        'key' => self::META_COMM_STATUS,
        'value' => $status_filter,
        'compare' => '=',
      ];
    }
    $q = new WP_Query($query_args);

    $out = [];
    foreach ((array)$q->posts as $cid){
      $order_id = (int)get_post_meta($cid, self::META_COMM_ORDER_ID, true);
      $order = $order_id ? wc_get_order($order_id) : null;
      $order_number = $order ? $order->get_order_number() : (string)$order_id;
      $order_status = $order ? wc_get_order_status_name($order->get_status()) : '-';
      $amount = (float)get_post_meta($cid, self::META_COMM_AMOUNT, true);
      $status = (string)get_post_meta($cid, self::META_COMM_STATUS, true);
      $avail = (int)get_post_meta($cid, self::META_COMM_AVAILABLE_AT, true);
      $released = (int)get_post_meta($cid, self::META_COMM_RELEASED_AT, true);
      $reversed = (int)get_post_meta($cid, self::META_COMM_REVERSED_AT, true);

      $label = $status;
      if ($status === self::STATUS_HOLD) $label = 'A liberar';
      if ($status === self::STATUS_RELEASED) $label = 'Liberada';
      if ($status === self::STATUS_REVERSED) $label = 'Estornada';

      $when = '-';
      if ($status === self::STATUS_HOLD && $avail) $when = date_i18n('Y-m-d', $avail);
      if ($status === self::STATUS_RELEASED && $released) $when = date_i18n('Y-m-d', $released);
      if ($status === self::STATUS_REVERSED && $reversed) $when = date_i18n('Y-m-d', $reversed);

      $out[] = [
        'commission_id' => (int)$cid,
        'order_id' => $order_id,
        'order_number' => $order_number,
        'order_status' => $order_status,
        'amount' => $amount,
        'status' => $status,
        'status_label' => $label,
        'when' => $when,
      ];
    }
    return $out;
  }

  private function get_affiliate_balance_split($affiliate_id, $date_from = null, $date_to = null){
    $pending = 0.0; $released = 0.0;
    $q = new WP_Query([
      'post_type' => self::CPT_COMMISSION,
      'post_status' => 'publish',
      'posts_per_page' => 500,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_COMM_AFFILIATE_ID,
          'value' => (string)(int)$affiliate_id,
          'compare' => '=',
        ]
      ],
      'date_query' => $this->build_date_query($date_from, $date_to),
    ]);
    foreach ((array)$q->posts as $cid){
      $status = (string)get_post_meta($cid, self::META_COMM_STATUS, true);
      $amount = (float)get_post_meta($cid, self::META_COMM_AMOUNT, true);
      if ($status === self::STATUS_RELEASED) $released += $amount;
      if ($status === self::STATUS_HOLD) $pending += $amount;
    }

    // Payouts
    $pending_payout = 0.0; $paid_payout = 0.0;
    $pq = new WP_Query([
      'post_type' => self::CPT_PAYOUT,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_PAYOUT_USER,
          'value' => (string)(int)$affiliate_id,
          'compare' => '=',
        ]
      ],
      'date_query' => $this->build_date_query($date_from, $date_to),
    ]);
    foreach ((array)$pq->posts as $pid){
      $pstatus = (string)get_post_meta($pid, self::META_PAYOUT_STATUS, true);
      $pamount = (float)get_post_meta($pid, self::META_PAYOUT_AMOUNT, true);
      if ($pstatus === 'pending') $pending_payout += $pamount;
      if ($pstatus === 'paid')    $paid_payout    += $pamount;
    }

    $available = max(0, $released - $pending_payout - $paid_payout);
    return [
      'pending'        => $pending,
      'released'       => $released,
      'pending_payout' => $pending_payout,
      'paid_payout'    => $paid_payout,
      'available_payout' => $available,
    ];
  }

  private function get_affiliate_payouts($affiliate_id, $limit, $date_from = null, $date_to = null){
    $q = new WP_Query([
      'post_type' => self::CPT_PAYOUT,
      'post_status' => 'publish',
      'posts_per_page' => max(1, (int)$limit),
      'orderby' => 'date',
      'order' => 'DESC',
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_PAYOUT_USER,
          'value' => (string)(int)$affiliate_id,
          'compare' => '=',
        ]
      ],
      'date_query' => $this->build_date_query($date_from, $date_to),
    ]);
    $out = [];
    foreach ((array)$q->posts as $pid){
      $amount = (float)get_post_meta($pid, self::META_PAYOUT_AMOUNT, true);
      $status = (string)get_post_meta($pid, self::META_PAYOUT_STATUS, true);
      $req = (int)get_post_meta($pid, self::META_PAYOUT_REQUEST, true);
      $paid = (int)get_post_meta($pid, self::META_PAYOUT_PAID_AT, true);
      $status_label = $status ?: 'pending';
      if ($status === 'paid') $status_label = 'Pago';
      elseif ($status === 'rejected') $status_label = 'Rejeitado';
      elseif ($status === 'pending') $status_label = 'Pendente';
      $out[] = [
        'id' => (int)$pid,
        'amount' => $amount,
        'status' => $status ?: 'pending',
        'status_label' => $status_label,
        'requested' => $req ? date_i18n('Y-m-d H:i', $req) : '-',
        'paid_at' => $paid ? date_i18n('Y-m-d H:i', $paid) : '-',
      ];
    }
    return $out;
  }

  private function is_affiliate_or_admin($user){
    if (!$user || !($user instanceof WP_User)) return false;
    if (in_array('yoda_affiliate', (array)$user->roles, true)) return true;
    if (current_user_can('manage_options')) return true; // admins podem visualizar
    return false;
  }

  private function is_order_eligible(WC_Order $order, array $opts){
    $roles_ok = true;
    $allowed_roles = array_filter(array_map('trim', explode(',', (string)$opts['eligible_roles'])));
    if ($allowed_roles){
      $user = get_user_by('id', (int)$order->get_customer_id());
      $roles = $user ? (array)$user->roles : [];
      $roles_ok = (bool)array_intersect($roles, $allowed_roles);
    }
    $total_ok = ((float)$order->get_total()) >= (float)$opts['min_order_total'];
    return $roles_ok && $total_ok;
  }

  private function notify_admin_payout($user_id, $amount, $status, $payout_id){
    $opts = self::get_opts();
    $to = $opts['payout_admin_email'] ?: get_option('admin_email');
    if (!$to) return;
    $user = get_user_by('id', $user_id);
    $name = $user ? ($user->display_name ?: $user->user_login) : ('#'.$user_id);
    $subject = sprintf('[Yoda] Payout afiliado %s (#%d)', $status, $payout_id);
    $body = sprintf(
      "Status: %s\nAfiliado: %s (ID %d)\nValor: %s\nPayout ID: %d\n",
      $status,
      $name,
      $user_id,
      wc_price($amount),
      $payout_id
    );
    wp_mail($to, $subject, $body);
  }

  private function notify_affiliate_payout($user_id, $amount, $status, $payout_id){
    $user = get_user_by('id', $user_id);
    if (!$user || !is_email($user->user_email)) return;
    $subject = sprintf('[Yoda] Seu saque está %s (ID %d)', $status, $payout_id);
    $body = sprintf(
      "Status: %s\nValor: %s\nPayout ID: %d\n",
      $status,
      wc_price($amount),
      $payout_id
    );
    wp_mail($user->user_email, $subject, $body);
  }
}
