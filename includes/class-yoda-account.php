<?php
if (!defined('ABSPATH')) exit;

class Yoda_Account {

  // retrocompatibilidade: tentamos várias chaves para o ID do Kako
  private $kako_meta_keys = ['_yoda_kako_id','yoda_kako_id','_kako_id','kako_id'];

  /* ========================================================================
   * Hooks
   * ======================================================================== */
  public function hooks(){
    // Coluna extra em Minha Conta → Pedidos
    add_filter('woocommerce_account_orders_columns', [$this,'add_orders_column']);
    add_action('woocommerce_my_account_my_orders_column_yoda_status', [$this,'render_orders_column'], 10, 1);

    // Shortcode do portal (página pública)
    add_shortcode('yoda_kako_portal', [$this,'portal_shortcode']);
  }

  /* ========================================================================
   * Minha Conta → Pedidos (coluna "Entrega Kako")
   * ======================================================================== */
  public function add_orders_column($cols){
    $new = [];
    foreach($cols as $key=>$label){
      if ($key === 'order-total'){
        $new['yoda_status'] = 'Entrega Kako';
      }
      $new[$key] = $label;
    }
    if (!isset($new['yoda_status'])) $new['yoda_status'] = 'Entrega Kako';
    return $new;
  }

  public function render_orders_column($order){
    if (!($order instanceof WC_Order)) return;
    $status = get_post_meta($order->get_id(), Yoda_Fulfillment::META_DELIV_STAT, true);
    $map = [
      'delivered'     => '<span style="color:#0a7a0a;font-weight:600;">Entregue</span>',
      'queued'        => '<span style="color:#555;">Em fila / processando</span>',
      'failed'        => '<span style="color:#b00;">Falhou</span>',
      'needs_review'  => '<span style="color:#a60;">Aguardando revisão</span>',
      ''              => '<span style="color:#555;">Aguardando</span>',
      null            => '<span style="color:#555;">Aguardando</span>',
    ];
    echo isset($map[$status]) ? $map[$status] : esc_html($status);
  }

  /* ========================================================================
   * Portal sem senha (shortcode)
   *
   * Padrão: exige EMAIL + ID (mais seguro).
   * Para permitir “apenas ID”, no wp-config.php:
   *   define('YODA_ID_ONLY_PORTAL', true);
   * Para ver contadores de debug (apenas admin):
   *   define('YODA_PORTAL_DEBUG', true);
   * ======================================================================== */
  public function portal_shortcode($atts){
    $require_email = !defined('YODA_ID_ONLY_PORTAL') || !YODA_ID_ONLY_PORTAL;

    $html  = '<div class="yoda-portal">';

    // Aceita POST (nonce) e GET (útil com cache)
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $got_post = ($method === 'POST'
      && isset($_POST['_yoda_portal'])
      && wp_verify_nonce($_POST['_yoda_portal'], 'yoda_portal'));
    $got_post_nonce_fail = ($method === 'POST'
      && isset($_POST['_yoda_portal'])
      && !wp_verify_nonce($_POST['_yoda_portal'], 'yoda_portal'));

    $kakoId = '';
    $email  = '';

    if ($got_post){
      $kakoId = sanitize_text_field($_POST['kakoid'] ?? '');
      $email  = sanitize_email($_POST['email'] ?? '');
    } elseif (!empty($_GET['kakoid']) || !empty($_GET['email'])) {
      $kakoId = sanitize_text_field($_GET['kakoid'] ?? '');
      $email  = sanitize_email($_GET['email'] ?? '');
    }

    if ($got_post_nonce_fail){
      $html .= '<div class="woocommerce-error">Não foi possível validar o envio (nonce inválido). Se houver cache, tente novamente ou use a URL com parâmetros.</div>';
    }

    if ($kakoId && (!$require_email || ($require_email && $email))){
      $html .= $this->render_orders_list($kakoId, $email, $require_email);
    }

    // Formulário
    $action_attr = esc_url(add_query_arg([]));
    $html .= '<form method="post" action="'.$action_attr.'" class="yoda-portal-form" style="max-width:520px;margin:12px 0;">';
    $html .= wp_nonce_field('yoda_portal', '_yoda_portal', true, false);
    if ($require_email){
      $html .= '<p><label>Email</label><br><input type="email" name="email" class="input-text" required autocomplete="off"></p>';
    }
    $html .= '<p><label>ID/username do Kako</label><br><input type="text" name="kakoid" class="input-text" required placeholder="Ex.: 10402704" autocomplete="off"></p>';
    $html .= '<p><button type="submit" class="button">Ver minhas compras</button></p>';
    $html .= '</form>';

    if (defined('YODA_PORTAL_DEBUG') && YODA_PORTAL_DEBUG && current_user_can('manage_options')){
      $html .= '<p style="opacity:.7;margin-top:8px">[debug] Você também pode testar via GET: <code>?kakoid=10402704</code>.</p>';
    }

    $html .= '</div>';
    return $html;
  }

  /* ========================================================================
   * Busca e renderização da lista — “busca ampla + filtro em PHP”
   * Compatível com HPOS. Sem coluna de Ações. Mensagens claras por status.
   * ======================================================================== */
  private function render_orders_list($kakoId, $email, $require_email){
    $kakoId = trim((string)$kakoId);
    $email  = trim(strtolower((string)$email));

    // 1) Buscar lote de pedidos recentes (HPOS-safe)
    $args_base = [
      'limit'   => 200,
      'orderby' => 'date',
      'order'   => 'DESC',
      'status'  => 'any',
      'type'    => 'shop_order',
      'return'  => 'objects',
    ];
    $all = wc_get_orders($args_base);

    // 2) Filtrar pelo ID do Kako nas possíveis meta_keys
    $matched = [];
    foreach ($all as $o){
      $found = false;
      foreach ($this->kako_meta_keys as $key){
        $val = get_post_meta($o->get_id(), $key, true);
        if (!$val) continue;
        if (trim((string)$val) === $kakoId || stripos((string)$val, $kakoId) !== false){
          $found = true; break;
        }
      }
      if ($found) $matched[] = $o;
    }

    // 3) Se exigir e-mail, filtra pelo e-mail do pedido
    if ($require_email && $matched){
      $matched = array_filter($matched, function($o) use ($email){
        return strtolower($o->get_billing_email()) === $email;
      });
    }

    // 4) Ordena por data (decrescente) e pagina (20 por página)
    usort($matched, function($a,$b){
      return strtotime($b->get_date_created()) <=> strtotime($a->get_date_created());
    });
    $per_page = 20;
    $page     = max(1, intval($_GET['ykp'] ?? 1));
    $total    = count($matched);
    $pages    = max(1, (int)ceil($total / $per_page));
    $slice    = array_slice($matched, ($page-1)*$per_page, $per_page);

    // Debug opcional
    if (defined('YODA_PORTAL_DEBUG') && YODA_PORTAL_DEBUG && current_user_can('manage_options')){
      echo '<p style="opacity:.7">[debug] lote='.$total.' | exibindo '.count($slice).' | página '.$page.'/'.$pages.'</p>';
    }

    if (!$slice){
      return '<div class="woocommerce-info">Nenhum pedido encontrado para este ID'.($require_email?' e e-mail':'').'.</div>';
    }

    // 5) Tabela (sem coluna Ações)
    $out  = '<table class="shop_table shop_table_responsive my_account_orders">';
    $out .= '<thead><tr>';
    $out .= '<th>Pedido</th><th>Data</th><th>Valor</th><th>Entrega Kako</th>';
    $out .= '</tr></thead><tbody>';

    foreach($slice as $order){
      /** @var WC_Order $order */
      $status     = get_post_meta($order->get_id(), Yoda_Fulfillment::META_DELIV_STAT, true);
      $amount     = Yoda_Product_Meta::get_order_coins_amount($order);
      $amount_txt = is_numeric($amount) ? intval($amount).' moedas' : '-';

      // Mensagens amigáveis por status
      switch ($status) {
        case 'delivered':
          $label = 'Entregue ('.$amount_txt.')';
          $color = '#0a7a0a';
          break;
        case 'failed':
          $label = 'Falhou ('.$amount_txt.')';
          $color = '#b00';
          break;
        case 'needs_review':
          $label = 'Aguardando revisão ('.$amount_txt.')';
          $color = '#a60';
          break;
        case 'queued':
          $label = 'Em fila / processando ('.$amount_txt.')';
          $color = '#555';
          break;
        default:
          // inclui null, '', status desconhecido
          $label = 'Aguardando ('.$amount_txt.')';
          $color = '#555';
      }

      $out .= '<tr>';
      $out .= '<td>#'.$order->get_order_number().'</td>';
      $out .= '<td>'.wc_format_datetime($order->get_date_created()).'</td>';
      $out .= '<td>'.wp_kses_post($order->get_formatted_order_total()).'</td>';
      $out .= '<td><span style="color:'.$color.'">'.$label.'</span></td>';
      $out .= '</tr>';
    }
    $out .= '</tbody></table>';

    // 6) Paginação simples
    if ($pages > 1){
      $base = remove_query_arg('ykp');
      $prev = $page > 1 ? add_query_arg('ykp', $page-1, $base) : '';
      $next = $page < $pages ? add_query_arg('ykp', $page+1, $base) : '';
      $out .= '<div class="yoda-pager" style="margin-top:12px;display:flex;gap:8px;align-items:center;">';
      if ($prev) $out .= '<a class="button" href="'.esc_url($prev).'">&larr; Anteriores</a>';
      $out .= '<span style="opacity:.7">Página '.$page.' de '.$pages.'</span>';
      if ($next) $out .= '<a class="button" href="'.esc_url($next).'">Próximos &rarr;</a>';
      $out .= '</div>';
    }

    return $out;
  }
}
