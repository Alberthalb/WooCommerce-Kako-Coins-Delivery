<?php
/**
 * Plugin Name: Yoda Kako Delivery
 * Description: Configuração inicial + Health-Check (saldo) + campo "ID do Kako" no checkout do WooCommerce.
 * Version: 0.1.0
 * Author: Alberth Albuquerque
 * Author URI: https://github.com/Alberthalb
 */

if (!defined('ABSPATH')) exit;

// ———————————————————————————————————————————————————————————
// Includes das classes
// ———————————————————————————————————————————————————————————
require_once __DIR__.'/includes/class-yoda-admin.php';
require_once __DIR__.'/includes/class-yoda-kako-client.php';
require_once __DIR__.'/includes/class-yoda-checkout.php';

// ———————————————————————————————————————————————————————————
// Hooks principais
// ———————————————————————————————————————————————————————————
add_action('plugins_loaded', function(){
  // Admin (menu + settings + health-check)
  (new Yoda_Admin())->hooks();
  // Campo de checkout (WooCommerce)
  (new Yoda_Checkout())->hooks();
});

// ———————————————————————————————————————————————————————————
// Constantes/Defaults (podem ser definidas no wp-config.php)
// ———————————————————————————————————————————————————————————
if (!defined('KAKO_API_BASE')) define('KAKO_API_BASE', 'https://api-test.kako.live');
// Recomendado: definir via variáveis de ambiente no servidor
if (!defined('KAKO_APP_ID'))  define('KAKO_APP_ID',  getenv('KAKO_APP_ID') ?: '');
if (!defined('KAKO_APP_KEY')) define('KAKO_APP_KEY', getenv('KAKO_APP_KEY') ?: '');
