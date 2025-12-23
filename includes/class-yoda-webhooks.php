<?php
if (!defined('ABSPATH')) exit;

class Yoda_Webhooks {
  public function hooks(){
    add_action('rest_api_init', function(){
      register_rest_route('yoda/v1', '/mp/webhook', [
        'methods'  => WP_REST_Server::ALLMETHODS,
        'callback' => [$this,'handle_mp'],
        'permission_callback' => '__return_true',
      ]);
    });
  }

  private function find_order_from_reference($ref){
    $ref = trim((string)$ref);
    if ($ref === '') return null;

    if (is_numeric($ref)){
      $order = wc_get_order((int)$ref);
      if ($order) return $order;
    }

    $orders = wc_get_orders([
      'limit'   => 1,
      'status'  => 'any',
      'type'    => 'shop_order',
      'return'  => 'objects',
      'meta_query' => [
        [
          'key'     => Yoda_Fulfillment::META_ORDER_REF,
          'value'   => $ref,
          'compare' => '=',
        ]
      ],
    ]);
    if (!empty($orders[0])) return $orders[0];

    // fallback: tenta extrair um ID numérico (ex.: mp-123) e valida por meta quando possível
    if (preg_match('/(\\d{1,10})\\s*$/', $ref, $m)){
      $order = wc_get_order((int)$m[1]);
      if ($order) return $order;
    }

    return null;
  }

  public function handle_mp(WP_REST_Request $req){
    $opts = get_option(Yoda_Admin::OPT_KEY, []);
    $secret = (string)($opts['webhook_secret'] ?? '');
    if (!$secret || $req->get_param('key') !== $secret){
      return new WP_REST_Response(['ok'=>false,'msg'=>'forbidden'], 403);
    }

    $orderId = $req->get_param('order_id') ?: $req->get_param('orderId') ?: $req->get_param('external_reference');
    $status  = strtolower((string)$req->get_param('status'));
    if (!$orderId){
      $body = json_decode($req->get_body(), true);
      if (is_array($body)){
        $orderId = $body['order_id'] ?? $body['orderId'] ?? $body['external_reference'] ?? null;
        $status  = strtolower((string)($body['status'] ?? $status));
      }
    }

    if (!$orderId){
      return new WP_REST_Response(['ok'=>false,'msg'=>'missing order_id'], 400);
    }

    $order = $this->find_order_from_reference($orderId);
    if (!$order){
      return new WP_REST_Response(['ok'=>false,'msg'=>'order not found'], 404);
    }

    $events_cbk = ['charged_back','chargeback','refunded','in_mediation','cancelled','canceled'];
    if (in_array($status, $events_cbk, true)){
      update_post_meta($order->get_id(), Yoda_Fulfillment::META_DELIV_STAT, 'needs_review');
      $order->add_order_note('Yoda Kako: webhook MP sinalizou evento: '.$status.'. Ref='.$orderId);
      return ['ok'=>true];
    }

    return ['ok'=>true,'ignored'=>true];
  }
}

