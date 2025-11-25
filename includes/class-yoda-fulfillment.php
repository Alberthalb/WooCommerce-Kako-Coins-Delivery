<?php
if (!defined('ABSPATH')) exit;

class Yoda_Fulfillment {
  const META_KAKO_ID     = '_yoda_kako_id';          // já salvo no checkout
  const META_ORDER_REF   = '_yoda_order_ref';        // nosso orderId/idempotente
  const META_DELIV_STAT  = '_yoda_delivery_status';  // delivered|failed|queued|needs_review
  const META_HOLD_UNTIL  = '_yoda_hold_until';       // timestamp para segurar entrega

  public function hooks(){
    // Dispara quando o pagamento é marcado como completo
    add_action('woocommerce_payment_complete', [$this,'maybe_fulfill'], 10, 1);

    // Ação manual no pedido: “Reenviar Moedas”
    add_filter('woocommerce_order_actions', [$this,'add_order_action']);
    add_action('woocommerce_order_action_yoda_resend_delivery', [$this,'manual_resend']);

    // (opcional) também dispara se status mudar para processando/concluído manualmente
    add_action('woocommerce_order_status_processing', [$this,'maybe_fulfill_by_status'], 10, 2);
    add_action('woocommerce_order_status_completed',  [$this,'maybe_fulfill_by_status'], 10, 2);

    // Job agendado para retentar após hold
    add_action('yoda_fulfill_order', [$this,'maybe_fulfill'], 10, 1);

    // Se gateway mudar para refund/cancel, marcar revisão
    add_action('woocommerce_order_status_refunded',  [$this,'mark_chargeback_like'], 10, 2);
    add_action('woocommerce_order_status_cancelled', [$this,'mark_chargeback_like'], 10, 2);
  }

  public function add_order_action($actions){
    $actions['yoda_resend_delivery'] = __('Yoda: Reenviar Moedas (Kako)', 'yoda');
    return $actions;
  }

  public function manual_resend($order){
    if ($order instanceof WC_Order){
      $this->fulfill($order, true);
    }
  }

  public function maybe_fulfill($order_id){
    $order = wc_get_order($order_id);
    if (!$order) return;
    $this->fulfill($order, false);
  }

  // quando status vira processing/completed manualmente
  public function maybe_fulfill_by_status($order_id, $order){
    if ($order instanceof WC_Order){
      $this->fulfill($order, false);
    }
  }

  private function fulfill(WC_Order $order, $force){
    $opts = get_option(Yoda_Admin::OPT_KEY, []);
    $gateway = $order->get_payment_method();

    // 0) hold por gateway
    $holdMin  = (int)($opts['hold_minutes'] ?? 0);
    $patterns = array_filter(array_map('trim', explode(',', (string)($opts['hold_gateways'] ?? ''))));
    if ($holdMin > 0 && $gateway && $this->matches_any($gateway, $patterns)){
      $until = (int)get_post_meta($order->get_id(), self::META_HOLD_UNTIL, true);
      if (!$until){
        $until = time() + $holdMin*60;
        update_post_meta($order->get_id(), self::META_HOLD_UNTIL, $until);
        update_post_meta($order->get_id(), self::META_DELIV_STAT, 'queued');
        $order->add_order_note('Yoda Kako: segurando entrega por '.intval($holdMin).'min (gateway: '.$gateway.').');
      }
      if ($until > time() && !$force){
        if (!wp_next_scheduled('yoda_fulfill_order', [$order->get_id()])){
          wp_schedule_single_event($until + 5, 'yoda_fulfill_order', [$order->get_id()]);
        }
        return; // aguardando liberação
      }
    }
    // 1) idempotência: se já entregue e não for “force”, sai
    $already = get_post_meta($order->get_id(), self::META_DELIV_STAT, true);
    if ($already === 'delivered' && !$force){
      $order->add_order_note('Yoda Kako: já entregue (idempotência).');
      return;
    }

    // 2) obter ID/username do Kako (checkout)
    $kakoId = get_post_meta($order->get_id(), self::META_KAKO_ID, true);
    if (!$kakoId){
      $order->add_order_note('Yoda Kako: faltando ID/username do Kako no pedido.');
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      return;
    }

    // 3) calcular amount (moedas) a partir dos produtos
    $amount = Yoda_Product_Meta::get_order_coins_amount($order);
    if ($amount <= 0){
      $order->add_order_note('Yoda Kako: amount=0 (configure Moedas nos produtos).');
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      return;
    }

    // 3.1) limites por cliente (CPF/KakoID)
    $daily  = (int)($opts['limit_daily']  ?? 0);
    $weekly = (int)($opts['limit_weekly'] ?? 0);
    $cpf    = get_post_meta($order->get_id(), 'billing_cpf', true);
    $kakoId_for_limits = get_post_meta($order->get_id(), self::META_KAKO_ID, true);
    if ($daily>0 && $this->coins_sum_for($kakoId_for_limits, $cpf, DAY_IN_SECONDS) + $amount > $daily){
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      $order->add_order_note('Yoda Kako: limite diário excedido.');
      return;
    }
    if ($weekly>0 && $this->coins_sum_for($kakoId_for_limits, $cpf, 7*DAY_IN_SECONDS) + $amount > $weekly){
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      $order->add_order_note('Yoda Kako: limite semanal excedido.');
      return;
    }

    // 3.2) block/allow list
    $blkKako = $this->strlist_has($kakoId_for_limits, (string)($opts['block_kako_ids'] ?? ''));
    $blkCpf  = $this->strlist_has($cpf,     (string)($opts['block_cpfs'] ?? ''));
    $allKako = $this->strlist_enabled($opts['allow_kako_ids'] ?? '') ? $this->strlist_has($kakoId_for_limits, (string)$opts['allow_kako_ids']) : true;
    $allCpf  = $this->strlist_enabled($opts['allow_cpfs'] ?? '')     ? $this->strlist_has($cpf,     (string)$opts['allow_cpfs'])     : true;
    if (($blkKako || $blkCpf) || (!$allKako || !$allCpf)){
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      $order->add_order_note('Yoda Kako: bloqueado por regra (lista).');
      return;
    }

    // 4) gerar orderId (até 64 chars) consistente
    $orderRef = get_post_meta($order->get_id(), self::META_ORDER_REF, true);
    if (!$orderRef){
      // use prefixo com método de pagamento para auditoria
      $prefix   = 'woo';
      $paym     = $order->get_payment_method();
      if ($paym) $prefix = substr($paym, 0, 8);
      $orderRef = substr($prefix.'-'.$order->get_id(), 0, 64);
      update_post_meta($order->get_id(), self::META_ORDER_REF, $orderRef);
    }

    // 5) credenciais e cliente
    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);

    // 6) userinfo → openId
    $ui = $client->userinfo($kakoId);
    if (is_wp_error($ui) || ($ui['json']['code'] ?? -1) !== 0){
      $msg = is_wp_error($ui) ? $ui->get_error_message() : ($ui['json']['msg'] ?? 'erro userinfo');
      $order->add_order_note('Yoda Kako: userinfo falhou — '.$msg);
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      return;
    }
    $openId = $ui['json']['data']['openId'] ?? '';
    if (!$openId){
      $order->add_order_note('Yoda Kako: userinfo sem openId.');
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      return;
    }

    // 7) transout
    $to = $client->transout($openId, $amount, $orderRef);
    if (is_wp_error($to)){
      $order->add_order_note('Yoda Kako: transout erro HTTP — '.$to->get_error_message());
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'failed');
      return;
    }

    $code   = $to['json']['code']           ?? -1;
    $status = $to['json']['data']['status'] ?? null;
    $msg    = $to['json']['msg']            ?? '';

    // 8) tratar respostas padrão (SUCESSO)
    if ($code === 0 && (int)$status === 2){
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'delivered');
      $order->add_order_note("Yoda Kako: entregue ✅ | amount={$amount} | orderId={$orderRef}");

      // envia e-mail ao cliente (AQUI VAI O TRECHO QUE VOCÊ CITOU)
      if (class_exists('Yoda_Email')) {
        Yoda_Email::send_delivery_email($order, $amount, $orderRef);
      }

      // opcional: marcar como concluído
      // $order->update_status('completed');
      return;
    }

    // códigos de negócio
    if ($code === 13002){ // orderId duplicado
      $qr = $client->transqry($orderRef);
      $qrStatus = $qr['json']['data']['status'] ?? 0;
      if ((int)$qrStatus === 2){
        update_post_meta($order->get_id(), self::META_DELIV_STAT, 'delivered');
        $order->add_order_note("Yoda Kako: confirmada via transqry (dup) ✅ | orderId={$orderRef}");

        // também envia e-mail quando confirmado via transqry
        if (class_exists('Yoda_Email')) {
          Yoda_Email::send_delivery_email($order, $amount, $orderRef);
        }
        return;
      }
    }

    if ($code === 13001){ // saldo insuficiente
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      $order->add_order_note("Yoda Kako: saldo insuficiente (13001). | amount={$amount}");
      return;
    }

    if ($code === 12003){ // usuário não existe
      update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
      $order->add_order_note("Yoda Kako: usuário não existe (12003). KakoID={$kakoId}");
      return;
    }

    // fallback
    update_post_meta($order->get_id(), self::META_DELIV_STAT, 'failed');
    $order->add_order_note("Yoda Kako: falha transout. code={$code} msg={$msg} status={$status}");
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

  private function matches_any($text, array $patterns){
    $text = strtolower((string)$text);
    foreach ($patterns as $p){ if ($p !== '' && strpos($text, strtolower($p)) !== false) return true; }
    return false;
  }

  private function strlist_enabled($txt){ return trim((string)$txt) !== ''; }
  private function strlist_has($needle, $list){
    $needle = trim((string)$needle);
    if ($needle === '') return false;
    $lines = preg_split('/\r?\n|,/', (string)$list);
    foreach ($lines as $l){ if (trim($l) !== '' && strcasecmp(trim($l), $needle) === 0) return true; }
    return false;
  }

  private function coins_sum_for($kakoId, $cpf, $interval){
    $after = gmdate('Y-m-d H:i:s', time() - (int)$interval);
    $args = [
      'status'  => ['processing','completed'],
      'limit'   => 200,
      'orderby' => 'date',
      'order'   => 'DESC',
      'return'  => 'objects',
      'date_created' => '>' . $after,
    ];
    $orders = wc_get_orders($args);
    $sum = 0;
    foreach ($orders as $o){
      $kid = get_post_meta($o->get_id(), self::META_KAKO_ID, true);
      $cp  = get_post_meta($o->get_id(), 'billing_cpf', true);
      if (($kakoId && $kid === $kakoId) || ($cpf && $cp === $cpf)){
        $sum += (int) Yoda_Product_Meta::get_order_coins_amount($o);
      }
    }
    return $sum;
  }

  public function mark_chargeback_like($order_id, $order){
    if (!($order instanceof WC_Order)) return;
    $order->add_order_note('Yoda Kako: pagamento reembolsado/cancelado. Avaliar bloqueio do cliente.');
    update_post_meta($order->get_id(), self::META_DELIV_STAT, 'needs_review');
  }
}
