<?php
if (!defined('ABSPATH')) exit;

class Yoda_Quick_Pix {

  const RL_WINDOW = MINUTE_IN_SECONDS;
  const RL_MAX    = 20;

  public function hooks(){
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
  }

  public function register_rest_routes(){
    register_rest_route('yoda/v1', '/quick/pix', [
      'methods'  => ['POST','OPTIONS'],
      'callback' => [$this, 'rest_create_pix'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function enqueue_assets(){
    if (is_admin()) return;
    if ((function_exists('is_customize_preview') && is_customize_preview()) || !empty($_GET['elementor-preview'])){
      return;
    }

    wp_enqueue_script('yoda-quick-pix', plugins_url('../assets/yoda-quick-pix.js', __FILE__), [], '1.0', true);
    wp_localize_script('yoda-quick-pix', 'YodaQuickPix', [
      'restQuickPix' => rest_url('yoda/v1/quick/pix'),
      'restKakoUser' => rest_url('yoda/v1/kako/userinfo'),
      'texts' => [
        'title' => 'Insira seus dados para pagamento',
        'confirm' => 'Confirmar pagamento',
        'close' => 'Fechar',
      ],
    ]);

    $css = "
    .yoda-qp-overlay{position:fixed; inset:0; background:rgba(0,0,0,.58); z-index:999999; display:flex; align-items:center; justify-content:center; padding:18px;}
    .yoda-qp-modal{width:min(640px, 96vw); height:min(92vh, 920px); background:#fff; border-radius:16px; box-shadow:0 30px 90px rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.16); display:flex; flex-direction:column; overflow:hidden;}
    .yoda-qp-head{display:flex; align-items:center; justify-content:space-between; padding:16px 16px 10px; border-bottom:1px solid #eee; flex:0 0 auto; background:#fff;}
    .yoda-qp-title{font-weight:900; font-size:14px; letter-spacing:.2px; margin:0;}
    .yoda-qp-close{appearance:none; border:0; background:transparent; font-size:18px; cursor:pointer; padding:6px 10px; border-radius:10px;}
    .yoda-qp-body{padding:16px; overflow:auto; -webkit-overflow-scrolling:touch; flex:1 1 auto;}
    .yoda-qp-grid{display:grid; gap:10px;}
    .yoda-qp-field label{display:block; font-size:12px; font-weight:800; color:#3a3a3a; margin-bottom:6px;}
    .yoda-qp-field input{width:100%; padding:11px 12px; border:1px solid #e6e6ee; border-radius:10px; background:#fff; font-size:14px;}
    .yoda-qp-row{display:grid; grid-template-columns:1fr 1fr; gap:10px;}
    @media (max-width:520px){.yoda-qp-row{grid-template-columns:1fr;}}
    .yoda-qp-summary{margin-top:10px; padding:12px; border-radius:12px; border:1px solid rgba(122,49,255,.16); background:rgba(122,49,255,.06);}
    .yoda-qp-summary .line{display:flex; justify-content:space-between; gap:10px; font-size:13px; padding:3px 0;}
    .yoda-qp-user{display:flex; gap:10px; align-items:center; margin-top:10px; padding:12px; border-radius:12px; border:1px solid #eee; background:#fafafa;}
    .yoda-qp-user img{width:44px; height:44px; border-radius:50%; object-fit:cover; background:#f1f1f4;}
    .yoda-qp-user .name{font-weight:900; font-size:14px; margin:0;}
    .yoda-qp-user .meta{font-size:12px; margin:0; color:#555;}
    .yoda-qp-actions{display:flex; gap:10px; margin-top:14px;}
    .yoda-qp-actions .btn{flex:1; padding:12px 14px; border-radius:12px; border:1px solid #e6e6ee; background:#fff; font-weight:900; cursor:pointer;}
    .yoda-qp-actions .btn.primary{background:#18a558; color:#fff; border-color:#18a558;}
    .yoda-qp-actions .btn:disabled{opacity:.6; cursor:not-allowed;}
    .yoda-qp-msg{margin-top:10px; font-size:13px; color:#b00;}
    .yoda-qp-pay{display:flex; flex-direction:column; gap:10px; height:100%;}
    .yoda-qp-pay .yoda-qp-summary{margin-top:0;}
    .yoda-qp-pay .yoda-qp-iframe{width:100%; flex:1 1 auto; min-height:420px; border:0; border-radius:12px; overflow:hidden;}
    .yoda-qp-qr{display:grid; place-items:center; padding:12px; border:1px solid rgba(161,76,255,.22); background:rgba(161,76,255,.06); border-radius:12px;}
    .yoda-qp-qr img{max-width:min(320px, 80vw); width:100%; height:auto; border-radius:10px; background:#fff;}
    .yoda-qp-code{display:flex; gap:10px; align-items:stretch;}
    .yoda-qp-code input{flex:1; padding:12px 12px; border-radius:10px; border:1px solid #e6e6ee; font-size:12px; background:#fff;}
    .yoda-qp-code button{padding:12px 14px; border-radius:10px; border:0; background:#111; color:#fff; font-weight:900; cursor:pointer;}
    .yoda-qp-hint{font-size:13px; color:#555; margin:0;}

    @media (max-width:520px){
      .yoda-qp-overlay{padding:0; align-items:stretch; justify-content:stretch;}
      .yoda-qp-modal{width:100vw; height:100vh; border-radius:0; border:0;}
      .yoda-qp-body{padding:14px;}
      .yoda-qp-actions{position:sticky; bottom:0; background:#fff; padding:10px 0 0;}
    }
    ";
    wp_register_style('yoda-quick-pix-inline', false);
    wp_enqueue_style('yoda-quick-pix-inline');
    wp_add_inline_style('yoda-quick-pix-inline', $css);

    // Mantém compatibilidade caso algum gateway ainda use iframe; mas a UI preferencial é QR/copia-e-cola.
    if (!empty($_GET['yoda_modal'])) {
      add_filter('show_admin_bar', '__return_false', 999);
      add_action('wp_head', function(){
        echo '<style id="yoda-quick-pix-clean">'.
          '#wpadminbar{display:none!important}'.
          'html{margin-top:0!important}'.
          'header,footer,#masthead,#colophon,.site-header,.site-footer,.elementor-location-header,.elementor-location-footer{display:none!important}'.
          'body{overflow:auto!important;background:#fff!important}'.
          'main, #main, .site-main{padding-top:0!important;margin-top:0!important}'.
          '</style>';
      }, 999);
    }
  }

  private function rate_limit_check(){
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'yoda_quickpix_rl_'.md5((string)$ip);
    $hits = (int) get_transient($key);
    $hits++;
    set_transient($key, $hits, self::RL_WINDOW);
    if ($hits > self::RL_MAX){
      return new WP_Error('rate_limited', 'Muitas tentativas. Aguarde um minuto e tente novamente.', ['status'=>429]);
    }
    return true;
  }

  private function is_valid_cpf($cpf){
    $cpf = preg_replace('/\\D+/', '', (string) $cpf);
    if (strlen($cpf) != 11) return false;
    if (preg_match('/^(\\d)\\1{10}$/', $cpf)) return false;
    for ($t=9; $t<11; $t++){
      $d = 0;
      for ($c=0; $c<$t; $c++){
        $d += intval($cpf[$c]) * (($t+1) - $c);
      }
      $d = ((10 * $d) % 11) % 10;
      if (intval($cpf[$t]) !== $d) return false;
    }
    return true;
  }

  private function pick_pix_gateway_id(){
    $id = apply_filters('yoda_pix_gateway_id', '');
    if (is_string($id) && $id !== '') return $id;

    if (!function_exists('WC') || !WC()->payment_gateways()) return '';
    $gws = WC()->payment_gateways()->get_available_payment_gateways();
    foreach ($gws as $gid => $gw){
      $key = strtolower((string)$gid);
      if (strpos($key, 'pix') !== false && (strpos($key, 'mercado') !== false || strpos($key, 'mp') !== false || strpos($key, 'mercadopago') !== false)){
        return (string)$gid;
      }
    }
    foreach ($gws as $gid => $gw){
      $key = strtolower((string)$gid);
      if (strpos($key, 'pix') !== false) return (string)$gid;
    }
    return '';
  }

  private function extract_pix_meta(WC_Order $order){
    $out = [
      'qr_base64' => '',
      'qr_code'   => '',
      'expires'   => '',
    ];

    // Mercado Pago (plugin oficial) costuma salvar esses metas:
    $qr64 = (string) $order->get_meta('mp_pix_qr_base64');
    $qrc  = (string) $order->get_meta('mp_pix_qr_code');
    $exp  = (string) $order->get_meta('checkout_pix_date_expiration');
    if (!$exp) $exp = (string) $order->get_meta('mp_pix_date_of_expiration');

    // fallback (caso o plugin use underscore)
    if (!$qr64) $qr64 = (string) $order->get_meta('_mp_pix_qr_base64');
    if (!$qrc)  $qrc  = (string) $order->get_meta('_mp_pix_qr_code');
    if (!$exp)  $exp  = (string) $order->get_meta('_checkout_pix_date_expiration');

    $out['qr_base64'] = trim($qr64);
    $out['qr_code']   = trim($qrc);
    $out['expires']   = trim($exp);
    return $out;
  }

  public function rest_create_pix(WP_REST_Request $req){
    $rl = $this->rate_limit_check();
    if (is_wp_error($rl)) return $rl;

    if (!function_exists('WC') || !function_exists('wc_get_product') || !function_exists('wc_create_order')){
      return new WP_Error('wc_missing', 'WooCommerce não disponível.', ['status'=>500]);
    }

    // Alguns gateways (ex.: Mercado Pago) dependem do carrinho/sessão mesmo com pedido já criado.
    // Em chamadas REST, WC()->cart pode ser null; carregamos explicitamente.
    if (function_exists('wc_load_cart')) {
      wc_load_cart();
    }
    if (!WC()->cart) {
      return new WP_Error('wc_cart_missing', 'Carrinho WooCommerce indisponível.', ['status'=>500]);
    }

    $payload = json_decode($req->get_body(), true);
    if (!is_array($payload)) $payload = [];

    $product_id = isset($payload['product_id']) ? (int)$payload['product_id'] : 0;
    $kakoId     = isset($payload['kakoid']) ? sanitize_text_field((string)$payload['kakoid']) : '';
    $fullName   = isset($payload['name']) ? sanitize_text_field((string)$payload['name']) : '';
    $cpf        = isset($payload['cpf']) ? preg_replace('/\\D+/', '', (string)$payload['cpf']) : '';
    $whatsapp   = isset($payload['whatsapp']) ? preg_replace('/\\D+/', '', (string)$payload['whatsapp']) : '';

    if ($product_id <= 0){
      return new WP_Error('bad_request', 'Produto inválido.', ['status'=>400]);
    }
    if (!$kakoId || !preg_match('/^[A-Za-z0-9_.\\-]{3,32}$/', $kakoId)){
      return new WP_Error('bad_request', 'Kako ID inválido.', ['status'=>400]);
    }
    if (!$fullName || strlen($fullName) < 3){
      return new WP_Error('bad_request', 'Informe seu nome.', ['status'=>400]);
    }
    if (!$cpf || !$this->is_valid_cpf($cpf)){
      return new WP_Error('bad_request', 'CPF inválido.', ['status'=>400]);
    }
    if (!$whatsapp || strlen($whatsapp) < 10){
      return new WP_Error('bad_request', 'WhatsApp inválido.', ['status'=>400]);
    }

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_purchasable()){
      return new WP_Error('bad_request', 'Produto indisponível.', ['status'=>400]);
    }

    $coins = (int) get_post_meta($product_id, '_yoda_coins_amount', true);
    if ($coins <= 0){
      return new WP_Error('bad_request', 'Produto não é um pacote de moedas.', ['status'=>400]);
    }

    $gw_id = $this->pick_pix_gateway_id();
    if (!$gw_id){
      return new WP_Error('gateway_missing', 'Gateway PIX não encontrado no WooCommerce.', ['status'=>500]);
    }

    $gateways = WC()->payment_gateways()->get_available_payment_gateways();
    $gateway = $gateways[$gw_id] ?? null;
    if (!$gateway){
      return new WP_Error('gateway_missing', 'Gateway PIX indisponível.', ['status'=>500]);
    }

    $parts = preg_split('/\\s+/', trim($fullName));
    $first = array_shift($parts);
    $last  = $parts ? implode(' ', $parts) : '.';

    $domain = parse_url(home_url('/'), PHP_URL_HOST) ?: 'site.local';
    $email  = '';
    if (function_exists('is_user_logged_in') && is_user_logged_in()){
      $u = wp_get_current_user();
      if ($u && !empty($u->user_email)) $email = (string) $u->user_email;
    }
    if (!$email){
      // Email neutro para pedidos guest (evita "cliente+timestamp@...").
      $email = 'comprador@'.$domain;
    }

    // Prepara um "carrinho temporário" para satisfazer gateways que consultam WC()->cart.
    $oldCart = [];
    try {
      $oldCart = WC()->cart->get_cart();
    } catch (Throwable $e) {
      $oldCart = [];
    }
    WC()->cart->empty_cart(true);
    WC()->cart->add_to_cart($product_id, 1);
    WC()->cart->calculate_totals();

    // Cria pedido
    $order = wc_create_order();
    $order->add_product($product, 1);
    $order->set_customer_id(0);
    $order->set_created_via('yoda_quick_pix');

    $order->set_billing_first_name($first);
    $order->set_billing_last_name($last);
    $order->set_billing_email($email);
    $order->set_billing_phone($whatsapp);

    $order->calculate_totals();

    update_post_meta($order->get_id(), '_yoda_kako_id', $kakoId);
    update_post_meta($order->get_id(), 'billing_cpf', $cpf);
    update_post_meta($order->get_id(), '_billing_cpf', $cpf);
    update_post_meta($order->get_id(), '_billing_whatsapp', $whatsapp);

    $order->set_payment_method($gateway);
    $order->set_payment_method_title($gateway->get_title());
    $order->save();

    // Processa pagamento (gera cobrança PIX)
    $result = null;
    try {
      if (WC()->session) {
        WC()->session->set('chosen_payment_method', $gw_id);
      }
      $result = $gateway->process_payment($order->get_id());
    } catch (Throwable $e){
      // restaura carrinho do usuário em caso de erro
      WC()->cart->empty_cart(true);
      foreach ((array)$oldCart as $item){
        if (empty($item['product_id']) || empty($item['quantity'])) continue;
        WC()->cart->add_to_cart((int)$item['product_id'], (int)$item['quantity'], (int)($item['variation_id'] ?? 0), (array)($item['variation'] ?? []));
      }
      return new WP_Error('gateway_error', 'Falha ao iniciar pagamento: '.$e->getMessage(), ['status'=>500]);
    }
    if (!is_array($result) || empty($result['redirect'])){
      WC()->cart->empty_cart(true);
      foreach ((array)$oldCart as $item){
        if (empty($item['product_id']) || empty($item['quantity'])) continue;
        WC()->cart->add_to_cart((int)$item['product_id'], (int)$item['quantity'], (int)($item['variation_id'] ?? 0), (array)($item['variation'] ?? []));
      }
      return new WP_Error('gateway_error', 'Gateway não retornou URL de pagamento.', ['status'=>500]);
    }

    $pay_url = add_query_arg('yoda_modal', '1', $result['redirect']);

    // Restaura carrinho anterior (não queremos deixar produto no carrinho porque o fluxo é por modal)
    WC()->cart->empty_cart(true);
    foreach ((array)$oldCart as $item){
      if (empty($item['product_id']) || empty($item['quantity'])) continue;
      WC()->cart->add_to_cart((int)$item['product_id'], (int)$item['quantity'], (int)($item['variation_id'] ?? 0), (array)($item['variation'] ?? []));
    }
    WC()->cart->calculate_totals();

    $pix = $this->extract_pix_meta($order);

    $resp = new WP_REST_Response([
      'ok' => true,
      'data' => [
        'order_id'   => $order->get_id(),
        'order_key'  => $order->get_order_key(),
        'pay_url'    => $pay_url,
        'total'      => (string) $order->get_total(),
        'currency'   => (string) $order->get_currency(),
        'pix'        => $pix,
        'product'    => [
          'id'    => $product_id,
          'name'  => $product->get_name(),
          'coins' => $coins,
          'price' => (string) $product->get_price(),
        ],
        'buyer' => [
          'name'     => $fullName,
          'cpf'      => $cpf,
          'whatsapp' => $whatsapp,
          'kakoid'   => $kakoId,
        ],
      ],
    ], 200);
    $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $resp->header('Pragma', 'no-cache');
    return $resp;
  }
}
