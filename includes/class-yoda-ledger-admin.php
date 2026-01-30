<?php
if (!defined('ABSPATH')) exit;

class Yoda_Ledger_Admin {
  public function hooks(){
    add_action('admin_menu', [$this,'menu']);
  }

  public function menu(){
    add_submenu_page(
      'yoda-kako',
      'Ledger',
      'Ledger',
      'manage_options',
      'yoda-ledger',
      [$this,'render_page']
    );
  }

  public function render_page(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . Yoda_Ledger::TABLE;

    $type     = isset($_GET['type'])     ? sanitize_text_field(wp_unslash($_GET['type']))     : '';
    $status   = isset($_GET['status'])   ? sanitize_text_field(wp_unslash($_GET['status']))   : '';
    $user     = isset($_GET['user'])     ? (int)($_GET['user']) : 0;
    $date_from= isset($_GET['from'])     ? sanitize_text_field(wp_unslash($_GET['from']))     : '';
    $date_to  = isset($_GET['to'])       ? sanitize_text_field(wp_unslash($_GET['to']))       : '';
    $limit  = 50;

    $where = '1=1';
    $args  = [];
    if ($type){
      $where .= ' AND type = %s';
      $args[] = $type;
    }
    if ($status){
      $where .= ' AND status = %s';
      $args[] = $status;
    }
    if ($user > 0){
      $where .= ' AND user_id = %d';
      $args[] = $user;
    }
    if ($date_from){
      $where .= ' AND created_at >= %s';
      $args[] = $date_from.' 00:00:00';
    }
    if ($date_to){
      $where .= ' AND created_at <= %s';
      $args[] = $date_to.' 23:59:59';
    }

    $sql = $wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d", array_merge($args, [$limit]));
    $rows = $wpdb->get_results($sql);

    if (isset($_GET['export']) && $_GET['export'] === 'csv'){
      $filename = 'yoda-ledger-'.date('Ymd-His').'.csv';
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename='.$filename);
      $out = fopen('php://output', 'w');
      fputcsv($out, ['id','created_at','type','status','ref_id','user_id','amount','meta']);
      foreach ((array)$rows as $r){
        fputcsv($out, [$r->id,$r->created_at,$r->type,$r->status,$r->ref_id,$r->user_id,$r->amount,$r->meta]);
      }
      exit;
    }
    ?>
    <div class="wrap">
      <h1>Ledger</h1>
      <form method="get" style="margin:12px 0;">
        <input type="hidden" name="page" value="yoda-ledger">
        <label>Tipo:
          <input type="text" name="type" value="<?php echo esc_attr($type); ?>" placeholder="cashback, affiliate, delivery, raffle_entry...">
        </label>
        <label style="margin-left:10px;">Status:
          <input type="text" name="status" value="<?php echo esc_attr($status); ?>" placeholder="delivered, earned, released...">
        </label>
        <label style="margin-left:10px;">Usuário (ID):
          <input type="number" name="user" value="<?php echo esc_attr($user ?: ''); ?>" style="width:90px;">
        </label>
        <label style="margin-left:10px;">De:
          <input type="date" name="from" value="<?php echo esc_attr($date_from); ?>">
        </label>
        <label style="margin-left:10px;">Até:
          <input type="date" name="to" value="<?php echo esc_attr($date_to); ?>">
        </label>
        <button class="button">Filtrar</button>
        <a class="button button-secondary" href="<?php echo esc_url(remove_query_arg(['type','status','user','from','to','export'])); ?>">Limpar</a>
        <a class="button button-primary" href="<?php echo esc_url(add_query_arg('export','csv')); ?>">Exportar CSV</a>
      </form>

      <table class="widefat striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Data</th>
            <th>Tipo</th>
            <th>Status</th>
            <th>Ref</th>
            <th>Usuário</th>
            <th>Valor</th>
            <th>Origem/Motivo</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8">Nenhum lançamento.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $meta = [];
              if (!empty($r->meta)){
                $decoded = json_decode($r->meta, true);
                if (is_array($decoded)) $meta = $decoded;
              }
              $reason = $meta['reason'] ?? '';
              $order_ref = $meta['order_ref'] ?? '';
              $source = [];
              if ($order_ref) $source[] = 'order_ref='.$order_ref;
              if ($reason)    $source[] = 'reason='.$reason;
              if (!$source && $meta){
                $source[] = wp_json_encode($meta);
              }
              $source_txt = $source ? implode(' | ', $source) : '-';
            ?>
            <tr>
              <td>#<?php echo esc_html($r->id); ?></td>
              <td><?php echo esc_html($r->created_at); ?></td>
              <td><?php echo esc_html($r->type); ?></td>
              <td><?php echo esc_html($r->status); ?></td>
              <td><?php echo esc_html($r->ref_id); ?></td>
              <td><?php echo $r->user_id ? '<a href="'.esc_url(get_edit_user_link((int)$r->user_id)).'">#'.(int)$r->user_id.'</a>' : '-'; ?></td>
              <td><?php echo esc_html($r->amount); ?></td>
              <td style="max-width:320px;white-space:pre-wrap;"><?php echo esc_html($source_txt); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
      <p style="opacity:.7;">Mostrando os últimos <?php echo esc_html($limit); ?> lançamentos. Filtros de tipo/status/usuário aplicados diretamente via SQL.</p>
    </div>
    <?php
  }
}
