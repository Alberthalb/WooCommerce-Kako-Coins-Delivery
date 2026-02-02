<?php
/**
 * Plugin Name: Yoda Kako Delivery
 * Description: Config inicial + Health-Check + campo ID do Kako + entrega automática WooCommerce.
 * Version: 0.2.0
 * Author: Alberth Albuquerque
 * Author URI: https://github.com/Alberthalb
 */

if (!defined('ABSPATH')) exit;

// Debug temporário do plugin (remova após diagnóstico)
if (!defined('YODA_PLUGIN_DEBUG_LOG')) {
  define('YODA_PLUGIN_DEBUG_LOG', plugin_dir_path(__FILE__) . 'yoda-debug.log');
  @ini_set('log_errors', 1);
  @ini_set('error_log', YODA_PLUGIN_DEBUG_LOG);
  register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
      error_log('[FATAL] '.$e['message'].' in '.$e['file'].':'.$e['line'].' | URI: '.($_SERVER['REQUEST_URI'] ?? 'cli'));
    }
  });
  set_exception_handler(function($ex){
    error_log('[EXCEPTION] '.$ex->getMessage().' in '.$ex->getFile().':'.$ex->getLine());
    throw $ex;
  });
}

// Includes das classes
require_once __DIR__.'/includes/class-yoda-admin.php';
require_once __DIR__.'/includes/class-yoda-kako-client.php';
require_once __DIR__.'/includes/class-yoda-checkout.php';
require_once __DIR__.'/includes/class-yoda-product-meta.php';
require_once __DIR__.'/includes/class-yoda-fulfillment.php';
require_once __DIR__.'/includes/class-yoda-email.php';
require_once __DIR__.'/includes/class-yoda-account.php';
require_once __DIR__.'/includes/class-yoda-user-card.php';
require_once __DIR__.'/includes/class-yoda-logger.php';
require_once __DIR__.'/includes/class-yoda-packs.php';
require_once __DIR__.'/includes/class-yoda-shop-buttons.php';
require_once __DIR__.'/includes/class-yoda-direct-checkout.php';
// (opt-out) Checkout style removed by request
require_once __DIR__.'/includes/class-yoda-checkout-extras.php';
require_once __DIR__.'/includes/class-yoda-webhooks.php';
require_once __DIR__.'/includes/class-yoda-quick-pix.php';
require_once __DIR__.'/includes/class-yoda-affiliates.php';
require_once __DIR__.'/includes/class-yoda-cashback.php';
require_once __DIR__.'/includes/class-yoda-ledger.php';
require_once __DIR__.'/includes/class-yoda-ledger-admin.php';
require_once __DIR__.'/includes/class-yoda-raffles.php';

// Hooks principais
add_action('plugins_loaded', function () {
  // Logs (se YODA_LOGS estiver habilitado)
  if (class_exists('Yoda_Logger')) {
    Yoda_Logger::hooks();
  }

  // Admin (menu + settings + health-check)
  (new Yoda_Admin())->hooks();

  // Campo de checkout (WooCommerce)
  (new Yoda_Checkout())->hooks();

  // Metadado de moedas por produto
  (new Yoda_Product_Meta())->hooks();

  // Entrega automática (userinfo → transout → transqry)
  (new Yoda_Fulfillment())->hooks();

  // E-mail “moedas entregues”
  (new Yoda_Email())->hooks();

  // Área do cliente (Minha Conta) + portal sem senha
  (new Yoda_Account())->hooks();

  // Card público de perfil (nickname/avatar/ID)
  (new Yoda_User_Card())->hooks();

  (new Yoda_Packs())->hooks();
  (new Yoda_Shop_Buttons())->hooks();
  (new Yoda_Direct_Checkout())->hooks();
  // (opt-out) Checkout style removed by request
  (new Yoda_Checkout_Extras())->hooks();
  (new Yoda_Webhooks())->hooks();
  (new Yoda_Quick_Pix())->hooks();
  (new Yoda_Affiliates())->hooks();
  (new Yoda_Cashback())->hooks();
  Yoda_Ledger::hooks();
  (new Yoda_Ledger_Admin())->hooks();
  (new Yoda_Raffles())->hooks();
});

// Link rápido na lista de plugins (restaura acesso fácil às telas de configuração)
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
  if (!is_array($links)) $links = [];
  if (current_user_can('manage_options')){
    $config_url = admin_url('admin.php?page=yoda-kako');
    array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url($config_url), 'Config'));
  }
  return $links;
});

register_activation_hook(__FILE__, function(){
  if (class_exists('Yoda_Affiliates')) {
    Yoda_Affiliates::on_activate();
  }
  if (class_exists('Yoda_Cashback')) {
    Yoda_Cashback::on_activate();
  }
  if (class_exists('Yoda_Ledger')) {
    Yoda_Ledger::maybe_create_table();
  }
  if (class_exists('Yoda_Raffles')) {
    Yoda_Raffles::on_activate();
  }
});

register_deactivation_hook(__FILE__, function(){
  if (class_exists('Yoda_Affiliates')) {
    Yoda_Affiliates::on_deactivate();
  }
  if (class_exists('Yoda_Cashback')) {
    Yoda_Cashback::on_deactivate();
  }
  if (class_exists('Yoda_Raffles')) {
    Yoda_Raffles::on_deactivate();
  }
});

// Opcional: forçar IPv4 em hosts com IPv6 problemático
add_action('http_api_curl', function($handle, $r, $url){
  if (defined('YODA_FORCE_IPV4') && YODA_FORCE_IPV4 && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')){
    @curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
  }
}, 10, 3);

// Constantes/Defaults (podem ser definidas no wp-config.php)
// Recomendado: definir via variáveis de ambiente no servidor
if (!defined('KAKO_APP_ID'))  define('KAKO_APP_ID',  getenv('KAKO_APP_ID') ?: '');
if (!defined('KAKO_APP_KEY')) define('KAKO_APP_KEY', getenv('KAKO_APP_KEY') ?: '');

// (Opcional) Defaults do logger – prefira definir no wp-config.php
// if (!defined('YODA_LOGS')) define('YODA_LOGS', false);
// if (!defined('YODA_LOGS_RETENTION_DAYS')) define('YODA_LOGS_RETENTION_DAYS', 14);
