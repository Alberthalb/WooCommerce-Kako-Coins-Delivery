<?php
if (!defined('ABSPATH')) exit;

class Yoda_Shop_Buttons {

  public function hooks(){
    // Muda o HTML do link de add-to-cart nos cards do loop
    add_filter('woocommerce_loop_add_to_cart_link', [$this,'filter_loop_link'], 10, 3);
    // Enfileira o JS que troca os botões em tempo real
    add_action('wp_enqueue_scripts', [$this,'enqueue_js']);
  }

  public function filter_loop_link($link_html, $product, $args){
    // Tenta detectar o cookie do ID do Kako
    $has_id = !empty($_COOKIE['yoda_kako_id']);
    $label  = $has_id ? __('Recarregar','yoda') : __('Valide seu ID','yoda');

    // pega href original
    $orig = '';
    if (preg_match('/href="([^"]+)"/', $link_html, $m)){
      $orig = $m[1];
    }

    // injeta o data attr sempre, pra JS poder reverter quando o ID aparecer
    if (strpos($link_html, 'data-yoda-btn="buy"') === false){
      $link_html = preg_replace('/<a\s+/', '<a data-yoda-btn="buy" ', $link_html, 1);
    }
    if ($orig){
      if (strpos($link_html, 'data-orig-href=') === false){
        $link_html = preg_replace('/<a\s+/', '<a data-orig-href="'.esc_attr($orig).'" ', $link_html, 1);
      }
    }

    if (!$has_id){
      // força âncora para área do verificador
      $link_html = preg_replace('/href="[^"]*"/', 'href="#verificar-id"', $link_html, 1);
      // adiciona classe visual/estado
      $link_html = preg_replace('/class="/', 'class="yoda-need-id ', $link_html, 1);
    } else {
      // remove yoda-need-id se tiver
      $link_html = str_replace('yoda-need-id', '', $link_html);
      // remove AJAX para permitir redirect server-side ao checkout
      $link_html = str_replace('ajax_add_to_cart', '', $link_html);
      // remove atributos de AJAX comuns em temas
      $link_html = preg_replace('/\sdata-product_id="[^"]*"/i', '', $link_html);
      $link_html = preg_replace('/\sdata-quantity="[^"]*"/i', '', $link_html);
      // força quantity=1 no link add-to-cart existente
      if (preg_match('/href="([^"]+)"/', $link_html, $m)){
        $href = $m[1];
        if (strpos($href, 'add-to-cart=') !== false){
          if (strpos($href, 'quantity=') === false){
            $href .= (strpos($href, '?') === false ? '?' : '&') . 'quantity=1';
          } else {
            $href = preg_replace('/quantity=\d+/', 'quantity=1', $href);
          }
          $link_html = preg_replace('/href="[^"]*"/', 'href="'.esc_attr($href).'"', $link_html, 1);
        }
      }
    }

    // troca o texto interno do <a>...</a>
    $link_html = preg_replace('/>(.*?)<\/a>/', '>'.$label.'</a>', $link_html);

    return $link_html;
  }

  public function enqueue_js(){
    // Evita interferir no preview de builders (Elementor, Customizer)
    if ((function_exists('is_customize_preview') && is_customize_preview()) || !empty($_GET['elementor-preview'])){
      return;
    }
    $js = <<<JS
    (function(){
      function getKakoId(){
        var m = document.cookie.match(/(?:^|; )yoda_kako_id=([^;]+)/);
        return m ? decodeURIComponent(m[1]) : "";
      }
      function ensureOverlay(card){
        if (!card.querySelector('.yoda-lock-overlay')){
          var a = document.createElement('a');
          a.href = '#verificar-id';
          a.className = 'yoda-lock-overlay';
          a.innerHTML = '<span class="yoda-lock-ico">&#128274;</span><span class="yoda-lock-text">Verificar ID</span>';
          card.appendChild(a);
        }
      }
      function removeOverlay(card){
        var o = card.querySelector('.yoda-lock-overlay');
        if (o) o.remove();
      }
      function updateShopState(){
        var hasId = !!getKakoId();
        var root = document.documentElement; // <html>
        if (hasId) root.classList.remove('yoda-need-id'); else root.classList.add('yoda-need-id');

        // Botões
        document.querySelectorAll('[data-yoda-btn="buy"]').forEach(function(a){
          var orig = a.getAttribute('data-orig-href');
          if (hasId){
            a.textContent = "Recarregar";
            a.classList.remove('yoda-need-id');
            if (orig && a.getAttribute('href') === '#verificar-id'){
              a.setAttribute('href', orig);
            }
          }else{
            a.textContent = "Valide seu ID";
            a.classList.add('yoda-need-id');
            if (orig) a.setAttribute('href', '#verificar-id');
          }
        });

        // Blur/overlay nos cards de produto padrão do WooCommerce
        document.querySelectorAll('ul.products li.product').forEach(function(item){
          if (!hasId){
            item.classList.add('yoda-blurred');
            ensureOverlay(item);
          }else{
            item.classList.remove('yoda-blurred');
            removeOverlay(item);
          }
        });

        // Blur/overlay em quaisquer containers marcados manualmente
        document.querySelectorAll('.yoda-lock-blur').forEach(function(item){
          if (!hasId){
            item.classList.add('yoda-blurred');
            ensureOverlay(item);
          }else{
            item.classList.remove('yoda-blurred');
            removeOverlay(item);
          }
        });
      }

      document.addEventListener('DOMContentLoaded', updateShopState);
      window.addEventListener('yoda:id:verified', updateShopState);
      window.addEventListener('yoda:id:cleared', updateShopState);
    })();
    JS;

    wp_register_script('yoda-shop-buttons', false, [], null, true);
    wp_enqueue_script('yoda-shop-buttons');
    wp_add_inline_script('yoda-shop-buttons', $js);
    // Redireciona para o checkout quando add-to-cart via AJAX concluir
    $js_redirect = "(function(){var u='".esc_url( wc_get_checkout_url() )."'; if (window.jQuery){ jQuery(function($){ $(document.body).on('added_to_cart', function(){ window.location.href = u; }); }); }})();";
    wp_add_inline_script('yoda-shop-buttons', $js_redirect);
    // Reescreve links para a Home adicionando ?kakoid=ID no domínio principal
    $home = esc_url( home_url('/') );
    $home_js = wp_json_encode($home);
    $js_home = "(function(){var HOME=".$home_js.";function getKakoId(){var m=document.cookie.match(/(?:^|; )yoda_kako_id=([^;]+)/);return m?decodeURIComponent(m[1]):'';}function upgrade(){var id=getKakoId();if(!id)return;var U=new URL(HOME);U.searchParams.set('kakoid', id);document.querySelectorAll('a').forEach(function(a){try{var href=a.getAttribute('href')||'';if(href==='/'||href===''||href.indexOf(HOME)===0){a.href=U.toString();}}catch(e){}});}document.addEventListener('DOMContentLoaded', upgrade);window.addEventListener('yoda:id:verified', upgrade);})();";
    wp_add_inline_script('yoda-shop-buttons', $js_home);
    // CSS: blur e overlay nos cards
    $css = "
    html.yoda-need-id ul.products{position:relative}
    html.yoda-need-id ul.products li.product{position:relative;}
    html.yoda-need-id .yoda-lock-blur{position:relative;}
    .yoda-lock-overlay{display:none; position:absolute; inset:0; z-index:5; align-items:center; justify-content:center; gap:10px; 
      background:rgba(255,255,255,.55); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); border-radius:12px; text-decoration:none;}
    html.yoda-need-id .yoda-lock-overlay{display:flex}
    .yoda-lock-overlay .yoda-lock-ico{opacity:.6; font-size:22px}
    .yoda-lock-overlay .yoda-lock-text{font-weight:800; color:#ff4d4f}
    ";
    wp_register_style('yoda-shop-buttons', false);
    wp_enqueue_style('yoda-shop-buttons');
    wp_add_inline_style('yoda-shop-buttons', $css);
  }
}

