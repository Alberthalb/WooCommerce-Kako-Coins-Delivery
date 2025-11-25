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

  public function handle_mp(WP_REST_Request $req){
    $opts = get_option(Yoda_Admin::OPT_KEY, []);
    $secret = (string)($opts['webhook_secret'] ?? '');
    if (!$secret || $req->get_param('key') !== $secret){
      return new WP_REST_Response(['ok'=>false,'msg'=>'forbidden'], 403);
    }

    $orderId = $req->get_param('order_id') ?: $req->get_param('orderId') ?: $req->get_param('external_reference');
    $status  = strtolower((string)$req->get_param('status'));
    if (!$orderId){
      // tenta no corpo JSON
      $body = json_decode($req->get_body(), true);
      if (is_array($body)){
        $orderId = $body['order_id'] ?? $body['orderId'] ?? $body['external_reference'] ?? null;
        $status  = strtolower((string)($body['status'] ?? $status));
      }
    }
    if (!$orderId){
      return new WP_REST_Response(['ok'=>false,'msg'=>'missing order_id'], 400);
    }
    $order = wc_get_order((int)$orderId);
    if (!$order){
      return new WP_REST_Response(['ok'=>false,'msg'=>'order not found'], 404);
    }

    $events_cbk = ['charged_back','chargeback','refunded','in_mediation','cancelled','canceled'];
    if (in_array($status, $events_cbk, true)){
      update_post_meta($order->get_id(), Yoda_Fulfillment::META_DELIV_STAT, 'needs_review');
      $order->add_order_note('Yoda Kako: webhook MP sinalizou evento: '.$status.'.');
      return ['ok'=>true];
    }
    return ['ok'=>true,'ignored'=>true];
  }
}

