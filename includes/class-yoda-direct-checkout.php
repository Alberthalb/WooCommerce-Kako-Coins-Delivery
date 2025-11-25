<?php
if (!defined('ABSPATH')) exit;

class Yoda_Direct_Checkout {

  public function hooks(){
    // Mantém apenas 1 produto por compra (esvazia antes de adicionar)
    add_filter('woocommerce_add_to_cart_validation', [$this,'one_item_only'], 0, 3);
    // Após adicionar ao carrinho, vai direto para o checkout (alta prioridade)
    add_filter('woocommerce_add_to_cart_redirect',   [$this,'redirect_to_checkout'], 999);
    // Remove mensagem "foi adicionado ao carrinho"
    add_filter('wc_add_to_cart_message_html',        [$this,'suppress_message'], 999, 2);
    add_filter('wc_add_to_cart_message',             [$this,'suppress_message_legacy'], 999);
    // Bloqueia acesso ao carrinho, loja e páginas individuais de produto
    add_action('template_redirect',                  [$this,'redirect_cart_and_product']);
  }

  private function is_builder_preview(){
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (function_exists('is_customize_preview') && is_customize_preview()) return true;
    if (!empty($_GET['elementor-preview'])) return true; // Elementor front-end preview
    return false;
  }

  public function one_item_only($passed, $product_id, $quantity){
    if (function_exists('WC') && WC()->cart){
      // Esvazia o carrinho antes de adicionar o novo produto
      if (!WC()->cart->is_empty()){
        WC()->cart->empty_cart();
      }
    }
    // Garante quantidade 1 (será respeitado pelo link padrão)
    if (isset($_REQUEST['quantity'])) {
      $_REQUEST['quantity'] = 1;
    }
    return $passed;
  }

  public function redirect_to_checkout($url){
    if (function_exists('wc_get_checkout_url')){
      return wc_get_checkout_url();
    }
    return $url;
  }

  public function redirect_cart_and_product(){
    // Não interferir ao editar com construtores/preview
    if ($this->is_builder_preview()) return;
    // Redireciona carrinho -> checkout
    if (function_exists('is_cart') && is_cart()){
      wp_safe_redirect(wc_get_checkout_url());
      exit;
    }
    // Redireciona páginas de produto -> loja (ou home se loja não existir)
    if (function_exists('is_product') && is_product()){
      $dest = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : '';
      if (!$dest) $dest = home_url('/');
      wp_safe_redirect($dest);
      exit;
    }
    // Redireciona página da Loja -> Home (evita etapa intermediária)
    if (function_exists('is_shop') && is_shop()){
      wp_safe_redirect(home_url('/'));
      exit;
    }
  }

  public function suppress_message($message, $products = []){
    return '';
  }
  public function suppress_message_legacy($message){
    return '';
  }
}
