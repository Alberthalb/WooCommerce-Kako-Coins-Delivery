<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode [yoda_buy_coins]
 * - Mostra campo de verificação de ID (userinfo)
 * - Bloqueia cards até verificar
 * - Após verificar, mostra preço real e habilita comprar
 */
class Yoda_Packs {

  const COOKIE_KAKO_ID = 'yoda_kako_id';
  const COOKIE_TTL     = DAY_IN_SECONDS; // 24h
  const VERIFY_TTL     = 10 * MINUTE_IN_SECONDS;

  public function hooks(){
    add_shortcode('yoda_buy_coins', [$this,'shortcode']);
    add_action('wp_enqueue_scripts', [$this,'assets']);

    add_action('wp_ajax_yoda_verify_kako',        [$this,'ajax_verify']);
    add_action('wp_ajax_nopriv_yoda_verify_kako', [$this,'ajax_verify']);

    add_action('wp_ajax_yoda_verify_kako_status',        [$this,'ajax_verify_status']);
    add_action('wp_ajax_nopriv_yoda_verify_kako_status', [$this,'ajax_verify_status']);

    add_action('yoda_retry_verify', [$this,'retry_verify_bg'], 10, 1);
  }

  private static function is_valid_kako_id($kakoId){
    return is_string($kakoId) && preg_match('/^[A-Za-z0-9_\-\.]{3,32}$/', $kakoId);
  }

  public static function cache_key($kakoId){
    $kakoId = strtolower(trim((string)$kakoId));
    return 'yoda_kako_ui_'.get_current_blog_id().'_'.md5($kakoId);
  }

  private static function pending_key($kakoId){
    $kakoId = strtolower(trim((string)$kakoId));
    return 'yoda_kako_ui_pending_'.get_current_blog_id().'_'.md5($kakoId);
  }

  private static function setcookie_compat($name, $value, $expire, $domain, $secure){
    $domain = (string) $domain;
    $secure = (bool) $secure;

    if (PHP_VERSION_ID >= 70300) {
      $opts = [
        'expires'  => (int)$expire,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
      ];
      if ($domain !== '') $opts['domain'] = $domain;
      @setcookie($name, (string)$value, $opts);
      return;
    }

    // fallback (PHP < 7.3): hack do SameSite no path
    $path = '/; samesite=Lax';
    @setcookie($name, (string)$value, (int)$expire, $path, $domain, $secure, false);
  }

  /**
   * Define o cookie do KakoID de forma resiliente (host + subdomínios), legível por JS.
   * Usa SameSite=Lax para sobreviver a navegações comuns.
   */
  public static function set_kako_cookie($kakoId, $ttl = self::COOKIE_TTL){
    $kakoId = (string) $kakoId;
    if ($kakoId === '') return;

    $expire = time() + (int) $ttl;
    $secure = is_ssl();
    $host   = parse_url(home_url('/'), PHP_URL_HOST);
    $cands  = [];

    if ($host && !preg_match('/^\\d+\\.\\d+\\.\\d+\\.\\d+$/', $host) && $host !== 'localhost'){
      $parts = explode('.', $host);
      for ($i = 0; $i <= max(0, count($parts)-2); $i++){
        $slice = array_slice($parts, $i);
        if (count($slice) < 2) continue;
        $cands[] = '.' . implode('.', $slice);
      }
    }

    // 1) host atual
    self::setcookie_compat(self::COOKIE_KAKO_ID, $kakoId, $expire, '', $secure);
    // 2) domínios candidatos (www, apex etc.)
    foreach (array_unique($cands) as $d){
      self::setcookie_compat(self::COOKIE_KAKO_ID, $kakoId, $expire, $d, $secure);
    }
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

  public function assets(){
    if (!is_singular() && !is_front_page()) return;

    $css = "
    .yoda-box{background:#fff; border-radius:14px; padding:18px; box-shadow:0 10px 30px rgba(0,0,0,.06); margin:12px 0;}
    .yoda-row{display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px;}
    @media(max-width:900px){.yoda-row{grid-template-columns:repeat(2,minmax(0,1fr));}}
    .yoda-card{border:1px solid #eee; border-radius:14px; padding:22px; text-align:center; position:relative; background:#fafafa;}
    .yoda-card.locked{opacity:.55;}
    .yoda-card .yoda-amount{font-weight:700; font-size:20px; color:#111;}
    .yoda-card .yoda-price{margin-top:8px; font-weight:600; color:#7a31ff;}
    .yoda-card .yoda-cta{margin-top:10px; display:inline-block; padding:8px 14px; border-radius:10px; background:#7a31ff; color:#fff; text-decoration:none; font-weight:600;}
    .yoda-card .yoda-cta[disabled]{pointer-events:none; opacity:.6;}
    .yoda-lock{position:absolute; top:10px; right:12px; font-size:14px; color:#bbb;}
    .yoda-verify .yoda-button{background:linear-gradient(90deg,#f4a6ff,#7a31ff); border:none; color:#fff; font-weight:700; padding:12px 18px; border-radius:10px; cursor:pointer;}
    .yoda-verify input{width:100%; padding:12px 14px; border-radius:10px; border:1px solid #e5e5e5; background:#fff;}
    .yoda-help{font-size:13px; color:#777; margin-top:6px}
    ";
    wp_register_style('yoda-packs-inline', false);
    wp_enqueue_style('yoda-packs-inline');
    wp_add_inline_style('yoda-packs-inline', $css);

    wp_enqueue_script('yoda-packs', plugins_url('../assets/yoda-packs.js', __FILE__), ['jquery'], '1.1', true);
    wp_localize_script('yoda-packs', 'YodaPacks', [
      'ajax'  => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('yoda_verify'),
      'texts' => [
        'checking'  => 'Verificando…',
        'ok'        => 'Conta verificada!',
        'fail'      => 'ID inválido ou não encontrado.',
      ],
    ]);
  }

  public function shortcode($atts){
    $atts = shortcode_atts([
      'category' => '',
    ], $atts);

    $prefill_id = isset($_COOKIE[self::COOKIE_KAKO_ID]) ? sanitize_text_field($_COOKIE[self::COOKIE_KAKO_ID]) : '';

    $args = [
      'status'  => 'publish',
      'limit'   => 12,
      'orderby' => 'menu_order',
      'order'   => 'ASC',
      'meta_query' => [
        [
          'key'     => '_yoda_coins_amount',
          'compare' => 'EXISTS',
        ]
      ],
      'return'  => 'objects',
    ];
    if ($atts['category']){
      $args['category'] = array_map('trim', explode(',', $atts['category']));
    }
    $products = wc_get_products($args);

    ob_start();
    ?>
    <div class="yoda-box yoda-verify" id="yoda-verify-box">
      <h2 style="margin:0 0 8px; font-size:26px;">Verificar Conta</h2>
      <p class="yoda-help">Digite seu Kako ID para continuar com a compra</p>
      <form id="yoda-verify-form" autocomplete="off" style="display:grid; grid-template-columns:1fr auto; gap:10px;">
        <input type="text" name="kakoid" id="yoda-kakoid" value="<?php echo esc_attr($prefill_id); ?>" placeholder="Kako ID" />
        <button class="yoda-button" type="submit">Confirmar Conta</button>
      </form>
      <div id="yoda-verify-msg" class="yoda-help"></div>
    </div>

    <div class="yoda-box">
      <h3 style="margin:0 0 8px;">Pacotes de Moedas Disponíveis</h3>
      <p class="yoda-help">Verifique sua conta acima para comprar qualquer pacote</p>

      <div class="yoda-row" id="yoda-packs-grid" data-verified="<?php echo $prefill_id ? '1':'0'; ?>">
        <?php foreach ($products as $p):
          $amount = (int) get_post_meta($p->get_id(), '_yoda_coins_amount', true);
          $price  = wc_price($p->get_price());
          $is_verified = !empty($prefill_id);
        ?>
          <div class="yoda-card <?php echo $is_verified ? '' : 'locked'; ?>" data-pid="<?php echo esc_attr($p->get_id()); ?>">
            <span class="yoda-lock"><?php echo $is_verified ? '' : '&#128274;'; ?></span>
            <div class="yoda-amount"><?php echo number_format_i18n($amount, 0); ?></div>
            <?php if ($is_verified): ?>
              <div class="yoda-price"><?php echo wp_kses_post($price); ?></div>
              <a class="yoda-cta" href="<?php echo esc_url( add_query_arg(['add-to-cart'=>$p->get_id()]) ); ?>">Comprar</a>
            <?php else: ?>
              <div class="yoda-price" style="opacity:.0;">&nbsp;</div>
              <a class="yoda-cta" href="javascript:void(0)" disabled>Verificar ID</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (!$products): ?>
        <p class="yoda-help">Nenhum pacote configurado. Adicione produtos com meta <code>_yoda_coins_amount</code>.</p>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
  }

  /** AJAX: valida ID com a API da Kako; guarda cookie */
  public function ajax_verify(){
    check_ajax_referer('yoda_verify', 'nonce');

    $kakoId = isset($_POST['kakoid']) ? sanitize_text_field($_POST['kakoid']) : '';
    if (!$kakoId){
      wp_send_json_error(['msg' => 'ID não informado.']);
    }
    if (!self::is_valid_kako_id($kakoId)){
      wp_send_json_error(['msg' => 'ID inválido.']);
    }

    $ckey = self::cache_key($kakoId);
    $cached = get_transient($ckey);
    if (is_array($cached) && !empty($cached['openId'])){
      self::set_kako_cookie($kakoId, self::COOKIE_TTL);
      wp_send_json_success($cached);
    }

    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res    = $client->userinfo($kakoId);

    if (is_wp_error($res)){
      $msg = $res->get_error_message();
      if (stripos($msg, 'cURL error 28') !== false){
        if (!wp_next_scheduled('yoda_retry_verify', [$kakoId])){
          wp_schedule_single_event(time()+5, 'yoda_retry_verify', [$kakoId]);
        }
        set_transient(self::pending_key($kakoId), 1, 2*MINUTE_IN_SECONDS);
        wp_send_json_error(['msg'=>'Conexão instável. Verificando em segundo plano…', 'pending'=>true]);
      }
      wp_send_json_error(['msg' => $msg]);
    }

    $code = $res['json']['code'] ?? -1;
    if ($code !== 0){
      $msg = $res['json']['msg'] ?? 'Erro na verificação.';
      wp_send_json_error(['msg' => $msg]);
    }

    $data = $res['json']['data'] ?? [];
    $payload = [
      'kakoId'   => $kakoId,
      'avatar'   => $data['avatar']   ?? '',
      'nickname' => $data['nickname'] ?? '',
      'openId'   => $data['openId']   ?? '',
    ];

    if (!$payload['openId']){
      wp_send_json_error(['msg' => 'Conta não encontrada.']);
    }

    self::set_kako_cookie($kakoId, self::COOKIE_TTL);
    set_transient($ckey, $payload, self::VERIFY_TTL);
    delete_transient(self::pending_key($kakoId));
    wp_send_json_success($payload);
  }

  /** AJAX: polling para quando o verify foi agendado em background */
  public function ajax_verify_status(){
    check_ajax_referer('yoda_verify', 'nonce');

    $kakoId = isset($_POST['kakoid']) ? sanitize_text_field($_POST['kakoid']) : '';
    if (!$kakoId || !self::is_valid_kako_id($kakoId)){
      wp_send_json_error(['msg' => 'ID inválido.']);
    }

    $ckey = self::cache_key($kakoId);
    $cached = get_transient($ckey);
    if (is_array($cached) && !empty($cached['openId'])){
      self::set_kako_cookie($kakoId, self::COOKIE_TTL);
      delete_transient(self::pending_key($kakoId));
      wp_send_json_success($cached);
    }

    $pending = (bool) get_transient(self::pending_key($kakoId));
    if ($pending || wp_next_scheduled('yoda_retry_verify', [$kakoId])){
      wp_send_json_error(['msg' => 'Ainda verificando…', 'pending' => true]);
    }

    wp_send_json_error(['msg' => 'Não foi possível verificar agora. Tente novamente.']);
  }

  /** Cron: tenta aquecer o cache do userinfo (não consegue setar cookie) */
  public function retry_verify_bg($kakoId){
    $kakoId = sanitize_text_field((string)$kakoId);
    if (!$kakoId || !self::is_valid_kako_id($kakoId)) return;

    $ckey = self::cache_key($kakoId);
    if (get_transient($ckey)) return;

    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res    = $client->userinfo($kakoId);

    if (is_wp_error($res)) return;
    if (($res['json']['code'] ?? -1) !== 0) return;

    $data = $res['json']['data'] ?? [];
    $payload = [
      'kakoId'   => $kakoId,
      'avatar'   => $data['avatar']   ?? '',
      'nickname' => $data['nickname'] ?? '',
      'openId'   => $data['openId']   ?? '',
    ];
    if (empty($payload['openId'])) return;

    set_transient($ckey, $payload, self::VERIFY_TTL);
  }
}

