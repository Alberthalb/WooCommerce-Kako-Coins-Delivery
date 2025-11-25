<?php
if (!defined('ABSPATH')) exit;

class Yoda_Checkout_Extras {
  public function hooks(){
    add_action('woocommerce_checkout_after_customer_details', [$this,'render_terms']);
    add_action('woocommerce_checkout_process',               [$this,'validate_terms']);
    add_action('woocommerce_checkout_update_order_meta',     [$this,'save_terms']);
    add_action('woocommerce_admin_order_data_after_billing_address', [$this,'admin_terms']);
  }

  public function render_terms($checkout){
    echo '<div class="yoda-terms" style="margin:12px 0">';
    woocommerce_form_field('yoda_terms', [
      'type'        => 'checkbox',
      'label'       => __('Li e concordo com os termos de compra e entrega digital imediata.', 'yoda'),
      'required'    => true,
      'class'       => ['form-row-wide'],
    ], isset($_POST['yoda_terms']) ? (bool)$_POST['yoda_terms'] : false);
    echo '</div>';
  }

  public function validate_terms(){
    if (empty($_POST['yoda_terms'])){
      wc_add_notice(__('É necessário aceitar os termos para concluir a compra.', 'yoda'), 'error');
    }
  }

  public function save_terms($order_id){
    update_post_meta($order_id, '_yoda_terms', !empty($_POST['yoda_terms']) ? 'yes' : 'no');
  }

  public function admin_terms($order){
    $terms = get_post_meta($order->get_id(), '_yoda_terms', true);
    if ($terms === 'yes'){
      echo '<p><strong>'.esc_html__('Termos:', 'yoda').'</strong> '.esc_html__('aceitos', 'yoda').'</p>';
    }
  }
}

