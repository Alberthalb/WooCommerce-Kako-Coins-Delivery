<?php
if (!defined('ABSPATH')) exit;

class Yoda_Email {
  public function hooks(){
    // nada por enquanto; usamos apenas helpers estáticos
  }

  public static function send_delivery_email(WC_Order $order, $amount, $orderRef){
    $to   = $order->get_billing_email();
    if (!$to) return;

    $subject = sprintf('Moedas entregues no Kako — Pedido #%s', $order->get_order_number());
    $kakoId  = get_post_meta($order->get_id(), '_yoda_kako_id', true);
    $body  = "Olá,\n\n";
    $body .= "Suas moedas foram entregues com sucesso no Kako.\n\n";
    $body .= "• Pedido: #".$order->get_order_number()."\n";
    $body .= "• ID do Kako: ".$kakoId."\n";
    $body .= "• Quantidade: ".$amount."\n";
    $body .= "• Protocolo: ".$orderRef."\n\n";
    $body .= "Obrigado por comprar com a gente!\n";

    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    wc_mail($to, $subject, $body, $headers);
  }
}
