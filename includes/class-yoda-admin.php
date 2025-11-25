<?php
if (!defined('ABSPATH')) exit;

class Yoda_Admin {
  const OPT_GROUP = 'yoda_kako_opts_group';
  const OPT_KEY   = 'yoda_kako_opts';

  public function hooks(){
    if (is_admin()){
      add_action('admin_menu', [$this,'menu']);
      add_action('admin_init', [$this,'register_settings']);
      add_action('admin_post_yoda_kako_balance',  [$this,'handle_balance']);
      add_action('admin_post_yoda_kako_userinfo', [$this,'handle_userinfo']);
      add_action('admin_post_yoda_kako_transout', [$this,'handle_transout']);
      add_action('admin_post_yoda_kako_transqry', [$this,'handle_transqry']);
    }
  }

  public function menu(){
    add_menu_page(
      'Yoda Kako', 'Yoda', 'manage_options', 'yoda-kako', [$this,'render_page'], 'dashicons-controls-repeat', 56
    );
  }

  public function register_settings(){
    register_setting(self::OPT_GROUP, self::OPT_KEY, [
      'sanitize_callback' => function($opts){
        $out = [
          'app_id'  => isset($opts['app_id'])  ? sanitize_text_field($opts['app_id'])  : '',
          'app_key' => isset($opts['app_key']) ? sanitize_text_field($opts['app_key']) : '',
          'base'    => isset($opts['base'])    ? esc_url_raw($opts['base'])            : '',
          'mode'    => isset($opts['mode'])    ? sanitize_text_field($opts['mode'])    : '',
          // antifraude / regras
          'hold_minutes'   => isset($opts['hold_minutes'])   ? max(0, (int)$opts['hold_minutes']) : 0,
          'hold_gateways'  => isset($opts['hold_gateways'])  ? sanitize_text_field($opts['hold_gateways']) : 'mercado,woo-mercado,mp',
          'limit_daily'    => isset($opts['limit_daily'])    ? max(0, (int)$opts['limit_daily']) : 0,
          'limit_weekly'   => isset($opts['limit_weekly'])   ? max(0, (int)$opts['limit_weekly']) : 0,
          'block_kako_ids' => isset($opts['block_kako_ids']) ? wp_strip_all_tags($opts['block_kako_ids']) : '',
          'block_cpfs'     => isset($opts['block_cpfs'])     ? wp_strip_all_tags($opts['block_cpfs'])     : '',
          'allow_kako_ids' => isset($opts['allow_kako_ids']) ? wp_strip_all_tags($opts['allow_kako_ids']) : '',
          'allow_cpfs'     => isset($opts['allow_cpfs'])     ? wp_strip_all_tags($opts['allow_cpfs'])     : '',
          'webhook_secret' => isset($opts['webhook_secret']) ? sanitize_text_field($opts['webhook_secret']) : '',
        ];
        if (!in_array($out['mode'], ['sandbox','production','custom'], true)){
          $out['mode'] = 'sandbox';
        }
        if (!$out['webhook_secret']){ $out['webhook_secret'] = wp_generate_password(20, false, false); }
        return $out;
      }
    ]);
  }

  private function get_effective_creds(){
    $opts   = get_option(self::OPT_KEY, []);
    $appId  = (defined('KAKO_APP_ID')  && KAKO_APP_ID)  ? KAKO_APP_ID  : ($opts['app_id']  ?? '');
    $appKey = (defined('KAKO_APP_KEY') && KAKO_APP_KEY) ? KAKO_APP_KEY : ($opts['app_key'] ?? '');
    // Base: prioridade = constante > base custom > modo (prod/sandbox)
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

  public function render_page(){
    if (!current_user_can('manage_options')) return;
    $msg = isset($_GET['yoda_msg']) ? wp_unslash($_GET['yoda_msg']) : '';
    $o = get_option(self::OPT_KEY, []);
    ?>
    <div class="wrap">
      <h1>Yoda Kako — Configuração & Health-Check</h1>
      <?php if ($msg): ?><div class="notice notice-info"><p><?php echo wp_kses_post($msg); ?></p></div><?php endif; ?>

      <form method="post" action="options.php">
        <?php settings_fields(self::OPT_GROUP); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="yoda_app_id">App ID</label></th>
            <td><input type="text" id="yoda_app_id" name="<?php echo self::OPT_KEY; ?>[app_id]" value="<?php echo esc_attr($o['app_id'] ?? ''); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th scope="row"><label for="yoda_app_key">App Key</label></th>
            <td><input type="text" id="yoda_app_key" name="<?php echo self::OPT_KEY; ?>[app_key]" value="<?php echo esc_attr($o['app_key'] ?? ''); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th scope="row">Ambiente</th>
            <td>
              <?php $mode = $o['mode'] ?? 'sandbox'; ?>
              <label><input type="radio" name="<?php echo self::OPT_KEY; ?>[mode]" value="sandbox" <?php checked($mode, 'sandbox'); ?>> Sandbox</label>&nbsp;&nbsp;
              <label><input type="radio" name="<?php echo self::OPT_KEY; ?>[mode]" value="production" <?php checked($mode, 'production'); ?>> Produção</label>&nbsp;&nbsp;
              <label><input type="radio" name="<?php echo self::OPT_KEY; ?>[mode]" value="custom" <?php checked($mode, 'custom'); ?>> Personalizado</label>
              <p class="description">Se "Personalizado", informe a URL da API abaixo. Se vazio, usamos o padrão do ambiente.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="yoda_base">API Base</label></th>
            <td><input type="url" id="yoda_base" name="<?php echo self::OPT_KEY; ?>[base]" value="<?php echo esc_attr($o['base'] ?? ''); ?>" placeholder="ex.: https://api-test.kako.live" class="regular-text"></td>
          </tr>
        </table>
        <?php submit_button('Salvar Credenciais'); ?>
      </form>

      <hr>
      <h2>Health-Check</h2>
      <p>Testes rápidos da API (balance, userinfo, transout, transqry).</p>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0;">
        <?php wp_nonce_field('yoda_kako_balance'); ?>
        <input type="hidden" name="action" value="yoda_kako_balance">
        <button class="button button-primary">Testar Balance()</button>
      </form>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0;">
        <?php wp_nonce_field('yoda_kako_userinfo'); ?>
        <input type="hidden" name="action" value="yoda_kako_userinfo">
        <input type="text" name="kakoid" placeholder="KakoID" required>
        <button class="button">Userinfo()</button>
      </form>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0;">
        <?php wp_nonce_field('yoda_kako_transout'); ?>
        <input type="hidden" name="action" value="yoda_kako_transout">
        <input type="text" name="openId" placeholder="openId" required>
        <input type="number" name="amount" placeholder="amount" required>
        <input type="text" name="orderId" placeholder="orderId" required>
        <button class="button">Transout()</button>
      </form>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0;">
        <?php wp_nonce_field('yoda_kako_transqry'); ?>
        <input type="hidden" name="action" value="yoda_kako_transqry">
        <input type="text" name="orderId" placeholder="orderId" required>
        <button class="button">Transqry()</button>
      </form>
      <hr>
      <h2>Antifraude & Regras</h2>
      <form method="post" action="options.php" style="margin-bottom:12px">
        <?php settings_fields(self::OPT_GROUP); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Delay (cartão)</th>
            <td>
              <input type="number" name="<?php echo self::OPT_KEY; ?>[hold_minutes]" value="<?php echo esc_attr($o['hold_minutes'] ?? 0); ?>" class="small-text"> minutos
              <p class="description">Atraso antes de entregar quando o gateway combinar com a lista abaixo.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Gateways alvo</th>
            <td>
              <input type="text" name="<?php echo self::OPT_KEY; ?>[hold_gateways]" value="<?php echo esc_attr($o['hold_gateways'] ?? 'mercado,woo-mercado,mp'); ?>" class="regular-text">
              <p class="description">Lista de termos (separados por vírgula). Ex.: mercado, mp, credit</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Limite diário (moedas)</th>
            <td><input type="number" name="<?php echo self::OPT_KEY; ?>[limit_daily]" value="<?php echo esc_attr($o['limit_daily'] ?? 0); ?>" class="small-text"> <span class="description">0 = desativado</span></td>
          </tr>
          <tr>
            <th scope="row">Limite semanal (moedas)</th>
            <td><input type="number" name="<?php echo self::OPT_KEY; ?>[limit_weekly]" value="<?php echo esc_attr($o['limit_weekly'] ?? 0); ?>" class="small-text"> <span class="description">0 = desativado</span></td>
          </tr>
          <tr>
            <th scope="row">Blocklist KakoID</th>
            <td><textarea name="<?php echo self::OPT_KEY; ?>[block_kako_ids]" rows="4" cols="60" placeholder="um por linha"><?php echo esc_textarea($o['block_kako_ids'] ?? ''); ?></textarea></td>
          </tr>
          <tr>
            <th scope="row">Blocklist CPF</th>
            <td><textarea name="<?php echo self::OPT_KEY; ?>[block_cpfs]" rows="4" cols="60" placeholder="somente dígitos, um por linha"><?php echo esc_textarea($o['block_cpfs'] ?? ''); ?></textarea></td>
          </tr>
          <tr>
            <th scope="row">Allowlist KakoID</th>
            <td><textarea name="<?php echo self::OPT_KEY; ?>[allow_kako_ids]" rows="3" cols="60" placeholder="opcional, um por linha"><?php echo esc_textarea($o['allow_kako_ids'] ?? ''); ?></textarea></td>
          </tr>
          <tr>
            <th scope="row">Allowlist CPF</th>
            <td><textarea name="<?php echo self::OPT_KEY; ?>[allow_cpfs]" rows="3" cols="60" placeholder="opcional, um por linha"><?php echo esc_textarea($o['allow_cpfs'] ?? ''); ?></textarea></td>
          </tr>
          <tr>
            <th scope="row">Webhook (MP)</th>
            <td>
              <?php $secret = $o['webhook_secret'] ?? ''; $url = add_query_arg(['key'=>$secret], rest_url('yoda/v1/mp/webhook')); ?>
              <code><?php echo esc_html($url); ?></code>
              <p class="description">Use esta URL no Mercado Pago para eventos de chargeback/refund. A URL inclui uma chave de verificação.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Salvar Regras'); ?>
      </form>
    </div>
    <?php
  }

  public function handle_balance(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    check_admin_referer('yoda_kako_balance');
    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res = $client->balance();
    $msg = $this->format_result($res, 'Balance');
    wp_safe_redirect(add_query_arg('yoda_msg', rawurlencode($msg), admin_url('admin.php?page=yoda-kako'))); exit;
  }

  public function handle_userinfo(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    check_admin_referer('yoda_kako_userinfo');
    $kakoId = isset($_POST['kakoid']) ? sanitize_text_field($_POST['kakoid']) : '';
    if (!$kakoId){ wp_safe_redirect(add_query_arg('yoda_msg', rawurlencode('Informe um KakoID'), admin_url('admin.php?page=yoda-kako'))); exit; }
    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res = $client->userinfo($kakoId);
    $msg = $this->format_result($res, 'Userinfo');
    wp_safe_redirect(add_query_arg('yoda_msg', rawurlencode($msg), admin_url('admin.php?page=yoda-kako'))); exit;
  }

  public function handle_transout(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    check_admin_referer('yoda_kako_transout');
    $openId  = isset($_POST['openId'])  ? sanitize_text_field($_POST['openId'])  : '';
    $amount  = isset($_POST['amount'])  ? (int) $_POST['amount']                 : 0;
    $orderId = isset($_POST['orderId']) ? sanitize_text_field($_POST['orderId']) : '';
    $orderId = substr($orderId, 0, 64);
    if (!$openId || $amount<=0 || !$orderId){
      $msg = 'Transout: preencha openId, amount (>0) e orderId.';
      wp_safe_redirect(add_query_arg('yoda_msg', rawurlencode($msg), admin_url('admin.php?page=yoda-kako'))); exit;
    }
    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res = $client->transout($openId, $amount, $orderId);
    $msg = $this->format_result($res, 'Transout');
    wp_safe_redirect(add_query_arg('yoda_msg', rawurlencode($msg), admin_url('admin.php?page=yoda-kako'))); exit;
  }

  public function handle_transqry(){
    if (!current_user_can('manage_options')) wp_die('Sem permissão');
    check_admin_referer('yoda_kako_transqry');
    $orderId = isset($_POST['orderId']) ? sanitize_text_field($_POST['orderId']) : '';
    $orderId = substr($orderId, 0, 64);
    if (!$orderId){ wp_safe_redirect(add_query_arg('yoda_msg', rawurlencode('Informe orderId'), admin_url('admin.php?page=yoda-kako'))); exit; }
    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res = $client->transqry($orderId);
    $msg = $this->format_result($res, 'Transqry');
    wp_safe_redirect(add_query_arg('yoda_msg', rawurlencode($msg), admin_url('admin.php?page=yoda-kako'))); exit;
  }

  private function format_result($res, $fn){
    if (is_wp_error($res)) return $fn.': erro HTTP: '.$res->get_error_message();
    $http = $res['http'] ?? 0; $json = $res['json'] ?? [];
    $code = $json['code'] ?? '-'; $msg = $json['msg'] ?? '';
    return sprintf('%s — HTTP %s | code=%s | msg=%s', $fn, $http, $code, $msg);
  }
}
?>
