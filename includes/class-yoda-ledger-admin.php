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

    $type   = isset($_GET['type'])   ? sanitize_text_field(wp_unslash($_GET['type']))   : '';
    $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $user   = isset($_GET['user'])   ? (int)($_GET['user']) : 0;
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

    $sql = $wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d", array_merge($args, [$limit]));
    $rows = $wpdb->get_results($sql);
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
        <button class="button">Filtrar</button>
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
            <th>Meta</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8">Nenhum lançamento.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>#<?php echo esc_html($r->id); ?></td>
              <td><?php echo esc_html($r->created_at); ?></td>
              <td><?php echo esc_html($r->type); ?></td>
              <td><?php echo esc_html($r->status); ?></td>
              <td><?php echo esc_html($r->ref_id); ?></td>
              <td><?php echo $r->user_id ? '<a href="'.esc_url(get_edit_user_link((int)$r->user_id)).'">#'.(int)$r->user_id.'</a>' : '-'; ?></td>
              <td><?php echo esc_html($r->amount); ?></td>
              <td style="max-width:320px;white-space:pre-wrap;"><?php echo esc_html($r->meta); ?></td>
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

