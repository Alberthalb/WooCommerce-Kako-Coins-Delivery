<?php
if (!defined('ABSPATH')) exit;

class Yoda_Checkout {

  public function hooks(){
    // Campos do checkout
    add_filter('woocommerce_checkout_fields', [$this,'customize_fields']);

    // Fallback: garante render do campo ID (se o tema não renderizar pelo esquema)
    add_action('woocommerce_after_checkout_billing_form', [$this,'render_field_manual']);

    // Validação e salvamento
    add_action('woocommerce_checkout_process',           [$this,'validate_field']);
    add_action('woocommerce_checkout_update_order_meta', [$this,'save_field']);

    // Prefill do ID a partir de GET/cookie
    add_filter('woocommerce_checkout_get_value', [$this,'prefill_checkout_value'], 10, 2);

    // Admin do pedido
    add_action('woocommerce_admin_order_data_after_billing_address', [$this,'admin_display_field']);

    // Store API (Checkout Blocks)
    add_action('woocommerce_store_api_checkout_update_order_from_request', [$this,'blocks_save_field'], 10, 2);

    // Máscara de CPF no front
    add_action('wp_enqueue_scripts', [$this,'enqueue_checkout_js']);
  }

  // Mantém apenas Nome, Sobrenome, E-mail, ID do Kako e CPF
  public function customize_fields($fields){
    if (!isset($fields['billing'])) $fields['billing'] = [];

    if (isset($fields['billing']['billing_first_name'])){
      $fields['billing']['billing_first_name']['label']    = 'Nome';
      $fields['billing']['billing_first_name']['required'] = true;
      $fields['billing']['billing_first_name']['priority'] = 1;
    }
    if (isset($fields['billing']['billing_last_name'])){
      $fields['billing']['billing_last_name']['label']    = 'Sobrenome';
      $fields['billing']['billing_last_name']['required'] = true;
      $fields['billing']['billing_last_name']['priority'] = 2;
    }
    if (isset($fields['billing']['billing_email'])){
      $fields['billing']['billing_email']['label']    = 'E-mail';
      $fields['billing']['billing_email']['required'] = true;
      $fields['billing']['billing_email']['priority'] = 3;
    }

    // Campo: ID/username do Kako
    $fields['billing']['billing_kako_id'] = [
      'type'        => 'text',
      'label'       => 'ID/username do Kako',
      'placeholder' => 'Ex.: 10402704',
      'required'    => true,
      'priority'    => 4,
      'class'       => ['form-row-wide'],
    ];

    // Campo: CPF
    $fields['billing']['billing_cpf'] = [
      'type'        => 'text',
      'label'       => 'CPF',
      'placeholder' => 'Somente números',
      'required'    => true,
      'priority'    => 5,
      'class'       => ['form-row-wide'],
    ];

    // Mantém somente os campos desejados
    $keep = ['billing_first_name','billing_last_name','billing_email','billing_kako_id','billing_cpf'];
    $newBilling = [];
    foreach ($keep as $k){ if (isset($fields['billing'][$k])) $newBilling[$k] = $fields['billing'][$k]; }
    $fields['billing'] = $newBilling;

    // Remove seções não utilizadas
    $fields['shipping'] = [];
    $fields['order']    = [];

    return $fields;
  }

  // Fallback de renderização do campo ID caso o tema não exiba pelo esquema
  public function render_field_manual($checkout){
    if (function_exists('WC') && WC()->checkout()){
      $bf = WC()->checkout()->get_checkout_fields('billing');
      if (isset($bf['billing_kako_id'])) return; // já vai ser renderizado pelo tema
    }
    $current = isset($_POST['billing_kako_id']) ? wc_clean(wp_unslash($_POST['billing_kako_id'])) : $checkout->get_value('billing_kako_id');
    echo '<div class="yoda-kako-field" style="margin:12px 0;">';
    woocommerce_form_field('billing_kako_id', [
      'type'        => 'text',
      'label'       => 'ID/username do Kako',
      'placeholder' => 'Ex.: 10402704',
      'required'    => true,
      'class'       => ['form-row-wide'],
    ], $current);
    echo '<small style="display:block;opacity:.8;margin-top:4px;">Dica: é o seu ID no Kako. Se tiver dúvida, entre em <em>Meu Perfil</em> no app.</small>';
    echo '</div>';
  }

  // Validação
  public function validate_field(){
    if (empty($_POST['billing_kako_id'])){
      wc_add_notice('Informe seu ID/username do Kako.', 'error');
      return;
    }
    $val = trim((string) $_POST['billing_kako_id']);
    if (!preg_match('/^[A-Za-z0-9_\-\.]{3,32}$/', $val)){
      wc_add_notice('ID do Kako inválido. Use apenas letras, números, ponto, hífen ou underline (3 a 32 caracteres).', 'error');
    }

    // CPF obrigatório e válido
    if (empty($_POST['billing_cpf'])){
      wc_add_notice('Informe seu CPF.', 'error');
    } else {
      $cpf = preg_replace('/\D+/', '', (string) $_POST['billing_cpf']);
      if (!$this->is_valid_cpf($cpf)){
        wc_add_notice('CPF inválido.', 'error');
      }
    }
  }

  // Salva metas
  public function save_field($order_id){
    if (!empty($_POST['billing_kako_id'])){
      update_post_meta($order_id, '_yoda_kako_id', sanitize_text_field($_POST['billing_kako_id']));
    }
    if (!empty($_POST['billing_cpf'])){
      $cpf = preg_replace('/\D+/', '', (string) $_POST['billing_cpf']);
      update_post_meta($order_id, 'billing_cpf', $cpf);
      update_post_meta($order_id, '_billing_cpf', $cpf);
    }
  }

  // Prefill do ID no checkout
  public function prefill_checkout_value($value, $input){
    if ($input === 'billing_kako_id' && empty($value)){
      if (isset($_GET['kakoid']) && $_GET['kakoid'] !== ''){
        return sanitize_text_field($_GET['kakoid']);
      }
      if (isset($_COOKIE['yoda_kako_id']) && $_COOKIE['yoda_kako_id'] !== ''){
        return sanitize_text_field($_COOKIE['yoda_kako_id']);
      }
    }
    return $value;
  }

  // Admin: mostra campos
  public function admin_display_field($order){
    $kako = get_post_meta($order->get_id(), '_yoda_kako_id', true);
    if ($kako){ echo '<p><strong>ID/username do Kako:</strong> '.esc_html($kako).'</p>'; }
    $cpf  = get_post_meta($order->get_id(), 'billing_cpf', true);
    if ($cpf){  echo '<p><strong>CPF:</strong> '.esc_html($cpf).'</p>'; }
  }

  // Store API (Checkout Blocks)
  public function blocks_save_field($order, $request){
    if (!($order instanceof WC_Order)) return;

    // ID Kako
    $val = $request->get_param('billing_kako_id');
    if (!$val) {
      $extensions = (array) $request->get_param('extensions');
      if (isset($extensions['yoda_kako']['billing_kako_id'])) {
        $val = $extensions['yoda_kako']['billing_kako_id'];
      }
    }
    if ($val) {
      $val = sanitize_text_field($val);
      if (preg_match('/^[A-Za-z0-9_\-\.]{3,32}$/', $val)) {
        update_post_meta($order->get_id(), '_yoda_kako_id', $val);
      }
    }

    // CPF
    $cpf = $request->get_param('billing_cpf');
    if (!$cpf) {
      $extensions = (array) $request->get_param('extensions');
      if (isset($extensions['yoda_kako']['billing_cpf'])) {
        $cpf = $extensions['yoda_kako']['billing_cpf'];
      }
    }
    if ($cpf) {
      $cpf = preg_replace('/\D+/', '', (string) $cpf);
      if ($this->is_valid_cpf($cpf)){
        update_post_meta($order->get_id(), 'billing_cpf', $cpf);
        update_post_meta($order->get_id(), '_billing_cpf', $cpf);
      }
    }
  }

  // Valida CPF (dígitos verificadores)
  private function is_valid_cpf($cpf){
    $cpf = preg_replace('/\D+/', '', (string) $cpf);
    if (strlen($cpf) != 11) return false;
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
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

  // JS: máscara de CPF (mostra formatado; envia só dígitos)
  public function enqueue_checkout_js(){
    if (!function_exists('is_checkout') || !is_checkout()) return;
    $js = <<<JS
    (function(){
      function digits(v){ return (v||'').replace(/\D+/g,''); }
      function maskCPF(v){
        var d = digits(v).slice(0,11);
        var out='';
        if (d.length<=3) out=d;
        else if (d.length<=6) out=d.slice(0,3)+'.'+d.slice(3);
        else if (d.length<=9) out=d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6);
        else out=d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9,11);
        return out;
      }
      function apply(){
        var cpf = document.getElementById('billing_cpf');
        if (cpf){
          cpf.setAttribute('inputmode','numeric');
          cpf.setAttribute('autocomplete','off');
          cpf.addEventListener('input', function(){ cpf.value = maskCPF(cpf.value); });
          var form = cpf.closest('form');
          if (form){ form.addEventListener('submit', function(){ cpf.value = digits(cpf.value); }); }
        }
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply); else apply();
      document.addEventListener('checkout_error', apply);
    })();
    JS;
    wp_register_script('yoda-checkout-masks', false, [], null, true);
    wp_enqueue_script('yoda-checkout-masks');
    wp_add_inline_script('yoda-checkout-masks', $js);
  }
}

