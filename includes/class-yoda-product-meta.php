<?php
if (!defined('ABSPATH')) exit;

class Yoda_Product_Meta {
  const META_COINS = '_yoda_coins_amount';

  public function hooks(){
    add_action('woocommerce_product_options_general_product_data', [$this,'add_field']);
    add_action('woocommerce_process_product_meta',               [$this,'save_field']);
  }

  public function add_field(){
    echo '<div class="options_group">';
    woocommerce_wp_text_input([
      'id'          => self::META_COINS,
      'label'       => __('Moedas (amount p/ Kako)', 'yoda'),
      'description' => __('Quantidade de moedas que este produto entrega na Kako.', 'yoda'),
      'desc_tip'    => true,
      'type'        => 'number',
      'custom_attributes' => [
        'step' => '1',
        'min'  => '0',
      ],
    ]);
    echo '</div>';
  }

  public function save_field($post_id){
    if (isset($_POST[self::META_COINS])){
      $val = max(0, (int) $_POST[self::META_COINS]);
      update_post_meta($post_id, self::META_COINS, $val);
    }
  }

  /** helper: soma moedas do pedido (itens x amount por produto) */
  public static function get_order_coins_amount($order){
    $total = 0;
    foreach ($order->get_items() as $item){
      $product = $item->get_product();
      if (!$product) continue;
      $amount  = (int) get_post_meta($product->get_id(), self::META_COINS, true);
      $qty     = (int) $item->get_quantity();
      if ($amount > 0 && $qty > 0) $total += ($amount * $qty);
    }
    return $total;
  }
}
