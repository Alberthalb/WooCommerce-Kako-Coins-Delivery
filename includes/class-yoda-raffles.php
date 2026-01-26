<?php
if (!defined('ABSPATH')) exit;

class Yoda_Raffles {
  const CPT_RAFFLE = 'yoda_raffle';
  const CPT_ENTRY  = 'yoda_raffle_entry';

  const META_RAFFLE_STATUS    = '_yoda_status'; // draft|open|closed|drawn
  const META_RAFFLE_START_AT  = '_yoda_start_at'; // ts
  const META_RAFFLE_END_AT    = '_yoda_end_at'; // ts
  const META_RAFFLE_MAX_USER  = '_yoda_max_entries_per_user'; // int
  const META_RAFFLE_WINNER_ID = '_yoda_winner_entry_id'; // entry post id

  const META_ENTRY_RAFFLE_ID  = '_yoda_raffle_id';
  const META_ENTRY_USER_ID    = '_yoda_user_id';
  const META_ENTRY_CREATED_AT = '_yoda_created_at';

  const NONCE_JOIN = 'yoda_raffle_join';

  public function hooks(){
    add_action('init', [$this,'register_cpts']);

    // Portal (Minha Conta + shortcode)
    add_action('init', [$this,'register_account_endpoint']);
    add_filter('woocommerce_account_menu_items', [$this,'add_my_account_menu_item']);
    add_action('woocommerce_account_yoda-sorteios_endpoint', [$this,'render_my_account_page']);
    add_shortcode('yoda_raffles', [$this,'raffles_shortcode']);

    // Admin: metabox + salvar + ação de sortear
    if (is_admin()){
      add_action('add_meta_boxes', [$this,'add_meta_boxes']);
      add_action('save_post_'.self::CPT_RAFFLE, [$this,'save_raffle_meta'], 10, 2);
      add_action('admin_post_yoda_raffle_draw', [$this,'handle_draw']);
    }
  }

  public static function on_activate(){
    flush_rewrite_rules();
  }

  public static function on_deactivate(){
    flush_rewrite_rules();
  }

  public function register_cpts(){
    register_post_type(self::CPT_RAFFLE, [
      'labels' => [
        'name' => 'Sorteios',
        'singular_name' => 'Sorteio',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => 'yoda-kako',
      'supports' => ['title','editor'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);

    register_post_type(self::CPT_ENTRY, [
      'labels' => [
        'name' => 'Inscrições (Sorteios)',
        'singular_name' => 'Inscrição',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => 'edit.php?post_type='.self::CPT_RAFFLE,
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  /* ========================================================================
   * Admin: metabox + sorteio
   * ======================================================================== */
  public function add_meta_boxes(){
    add_meta_box(
      'yoda_raffle_settings',
      'Configurações do Sorteio',
      [$this,'render_raffle_metabox'],
      self::CPT_RAFFLE,
      'side',
      'high'
    );
  }

  public function render_raffle_metabox($post){
    if (!($post instanceof WP_Post)) return;
    $status = (string)get_post_meta($post->ID, self::META_RAFFLE_STATUS, true);
    if ($status === '') $status = 'draft';
    $start = (int)get_post_meta($post->ID, self::META_RAFFLE_START_AT, true);
    $end = (int)get_post_meta($post->ID, self::META_RAFFLE_END_AT, true);
    $max = (int)get_post_meta($post->ID, self::META_RAFFLE_MAX_USER, true);
    if ($max <= 0) $max = 1;
    $winner = (int)get_post_meta($post->ID, self::META_RAFFLE_WINNER_ID, true);
    $entries = $this->count_entries($post->ID);
    ?>
    <?php echo wp_nonce_field('yoda_raffle_meta', '_yoda_raffle_meta', true, false); ?>

    <p>
      <label><strong>Status</strong></label><br>
      <select name="yoda_raffle_status">
        <option value="draft" <?php selected($status, 'draft'); ?>>Rascunho</option>
        <option value="open" <?php selected($status, 'open'); ?>>Aberto</option>
        <option value="closed" <?php selected($status, 'closed'); ?>>Encerrado</option>
        <option value="drawn" <?php selected($status, 'drawn'); ?>>Sorteado</option>
      </select>
    </p>

    <p>
      <label><strong>Início</strong> (YYYY-MM-DD)</label><br>
      <input type="date" name="yoda_raffle_start" value="<?php echo esc_attr($start ? gmdate('Y-m-d', $start) : ''); ?>">
    </p>

    <p>
      <label><strong>Fim</strong> (YYYY-MM-DD)</label><br>
      <input type="date" name="yoda_raffle_end" value="<?php echo esc_attr($end ? gmdate('Y-m-d', $end) : ''); ?>">
    </p>

    <p>
      <label><strong>Máx. inscrições por usuário</strong></label><br>
      <input type="number" min="1" name="yoda_raffle_max" value="<?php echo esc_attr($max); ?>" style="width:100%;">
    </p>

    <p style="opacity:.8;margin:8px 0 0;">Inscrições: <strong><?php echo esc_html((int)$entries); ?></strong></p>
    <?php if ($winner): ?>
      <p style="opacity:.8;margin:6px 0 0;">Vencedor (entry): <strong>#<?php echo esc_html((int)$winner); ?></strong></p>
    <?php endif; ?>

    <hr>
    <?php if ($entries > 0): ?>
      <?php
        $draw_url = wp_nonce_url(
          admin_url('admin-post.php?action=yoda_raffle_draw&raffle_id='.$post->ID),
          'yoda_raffle_draw_'.$post->ID
        );
      ?>
      <p><a class="button button-secondary" href="<?php echo esc_url($draw_url); ?>">Sortear vencedor</a></p>
      <p class="description">Seleciona 1 inscrição aleatoriamente e marca como vencedor.</p>
    <?php else: ?>
      <p class="description">Sem inscrições ainda.</p>
    <?php endif; ?>
    <?php
  }

  public function save_raffle_meta($post_id, $post){
    if (!($post instanceof WP_Post)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['_yoda_raffle_meta']) || !wp_verify_nonce($_POST['_yoda_raffle_meta'], 'yoda_raffle_meta')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $status = sanitize_text_field(wp_unslash($_POST['yoda_raffle_status'] ?? 'draft'));
    if (!in_array($status, ['draft','open','closed','drawn'], true)) $status = 'draft';
    update_post_meta($post_id, self::META_RAFFLE_STATUS, $status);

    $start = sanitize_text_field(wp_unslash($_POST['yoda_raffle_start'] ?? ''));
    $end = sanitize_text_field(wp_unslash($_POST['yoda_raffle_end'] ?? ''));
    $start_ts = $start ? strtotime($start.' 00:00:00') : 0;
    $end_ts = $end ? strtotime($end.' 23:59:59') : 0;
    if ($start_ts) update_post_meta($post_id, self::META_RAFFLE_START_AT, $start_ts); else delete_post_meta($post_id, self::META_RAFFLE_START_AT);
    if ($end_ts) update_post_meta($post_id, self::META_RAFFLE_END_AT, $end_ts); else delete_post_meta($post_id, self::META_RAFFLE_END_AT);

    $max = isset($_POST['yoda_raffle_max']) ? (int)$_POST['yoda_raffle_max'] : 1;
    $max = max(1, $max);
    update_post_meta($post_id, self::META_RAFFLE_MAX_USER, $max);
  }

  public function handle_draw(){
    if (!current_user_can('edit_posts')) wp_die('Sem permissão');
    $raffle_id = isset($_GET['raffle_id']) ? (int)$_GET['raffle_id'] : 0;
    if ($raffle_id <= 0 || get_post_type($raffle_id) !== self::CPT_RAFFLE) wp_die('Sorteio inválido');
    check_admin_referer('yoda_raffle_draw_'.$raffle_id);

    $winner = $this->draw_winner($raffle_id);
    $msg = $winner ? 'Vencedor definido: entry #'.$winner : 'Não foi possível sortear (sem inscrições).';
    wp_safe_redirect(add_query_arg('yoda_msg', rawurlencode($msg), admin_url('post.php?post='.$raffle_id.'&action=edit')));
    exit;
  }

  private function draw_winner($raffle_id){
    $entries = $this->get_entry_ids($raffle_id);
    if (empty($entries)) return 0;
    $winner_entry = (int)$entries[array_rand($entries)];
    update_post_meta($raffle_id, self::META_RAFFLE_WINNER_ID, $winner_entry);
    update_post_meta($raffle_id, self::META_RAFFLE_STATUS, 'drawn');
    return $winner_entry;
  }

  private function get_entry_ids($raffle_id){
    $q = new WP_Query([
      'post_type' => self::CPT_ENTRY,
      'post_status' => 'publish',
      'posts_per_page' => 1000,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_ENTRY_RAFFLE_ID,
          'value' => (string)(int)$raffle_id,
          'compare' => '=',
        ],
      ],
    ]);
    return array_map('intval', (array)$q->posts);
  }

  private function count_entries($raffle_id){
    $q = new WP_Query([
      'post_type' => self::CPT_ENTRY,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_ENTRY_RAFFLE_ID,
          'value' => (string)(int)$raffle_id,
          'compare' => '=',
        ],
      ],
    ]);
    return (int)($q->found_posts ?? 0);
  }

  /* ========================================================================
   * Portal
   * ======================================================================== */
  public function register_account_endpoint(){
    add_rewrite_endpoint('yoda-sorteios', EP_ROOT | EP_PAGES);
  }

  public function add_my_account_menu_item($items){
    if (!is_user_logged_in()) return $items;
    $out = [];
    foreach ($items as $k => $label){
      $out[$k] = $label;
      if ($k === 'orders'){
        $out['yoda-sorteios'] = 'Sorteios';
      }
    }
    if (!isset($out['yoda-sorteios'])) $out['yoda-sorteios'] = 'Sorteios';
    return $out;
  }

  public function render_my_account_page(){
    echo $this->render_portal();
  }

  public function raffles_shortcode($atts){
    return $this->render_portal();
  }

  private function render_portal(){
    if (!is_user_logged_in()){
      return '<div class="woocommerce-info">Faça login para acessar os sorteios.</div>';
    }

    $msg = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['_yoda_raffle_join_nonce'])){
      $msg = $this->handle_join_post();
    }

    $raffles = $this->get_open_raffles(20);
    $user_id = get_current_user_id();

    ob_start();
    ?>
    <div class="yoda-raffles-portal">
      <h2>Sorteios</h2>

      <?php if ($msg): ?>
        <div class="woocommerce-info"><?php echo wp_kses_post($msg); ?></div>
      <?php endif; ?>

      <?php if (empty($raffles)): ?>
        <div class="woocommerce-info">Nenhum sorteio aberto no momento.</div>
      <?php else: ?>
        <?php foreach ($raffles as $r): ?>
          <?php
            $rid = (int)$r->ID;
            $max = (int)get_post_meta($rid, self::META_RAFFLE_MAX_USER, true);
            if ($max <= 0) $max = 1;
            $count = $this->count_user_entries($rid, $user_id);
            $can_join = $count < $max;
            $start = (int)get_post_meta($rid, self::META_RAFFLE_START_AT, true);
            $end = (int)get_post_meta($rid, self::META_RAFFLE_END_AT, true);
          ?>
          <div style="margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:8px;">
            <h3 style="margin:0 0 6px;"><?php echo esc_html(get_the_title($rid)); ?></h3>
            <div style="opacity:.8;margin-bottom:8px;">
              <?php if ($start): ?>Início: <?php echo esc_html(date_i18n('Y-m-d', $start)); ?>&nbsp;&nbsp;<?php endif; ?>
              <?php if ($end): ?>Fim: <?php echo esc_html(date_i18n('Y-m-d', $end)); ?><?php endif; ?>
            </div>
            <div class="yoda-raffle-desc" style="margin-bottom:10px;">
              <?php echo wp_kses_post(wpautop($r->post_content)); ?>
            </div>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
              <div style="opacity:.8;">Suas inscrições: <?php echo esc_html((int)$count); ?> / <?php echo esc_html((int)$max); ?></div>
              <?php if ($can_join): ?>
                <form method="post" style="margin:0;">
                  <?php echo wp_nonce_field(self::NONCE_JOIN, '_yoda_raffle_join_nonce', true, false); ?>
                  <input type="hidden" name="raffle_id" value="<?php echo esc_attr($rid); ?>">
                  <button type="submit" class="button">Participar</button>
                </form>
              <?php else: ?>
                <span style="opacity:.8;">Limite de inscrições atingido.</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
  }

  private function handle_join_post(){
    if (!wp_verify_nonce($_POST['_yoda_raffle_join_nonce'], self::NONCE_JOIN)){
      return 'Não foi possível validar o envio (nonce inválido).';
    }
    $raffle_id = isset($_POST['raffle_id']) ? (int)$_POST['raffle_id'] : 0;
    if ($raffle_id <= 0 || get_post_type($raffle_id) !== self::CPT_RAFFLE){
      return 'Sorteio inválido.';
    }

    $status = (string)get_post_meta($raffle_id, self::META_RAFFLE_STATUS, true);
    if ($status !== 'open'){
      return 'Este sorteio não está aberto.';
    }

    $start = (int)get_post_meta($raffle_id, self::META_RAFFLE_START_AT, true);
    $end = (int)get_post_meta($raffle_id, self::META_RAFFLE_END_AT, true);
    $now = time();
    if ($start && $now < $start) return 'Este sorteio ainda não começou.';
    if ($end && $now > $end) return 'Este sorteio já encerrou.';

    $user_id = get_current_user_id();
    if ($user_id <= 0) return 'Faça login para participar.';

    $max = (int)get_post_meta($raffle_id, self::META_RAFFLE_MAX_USER, true);
    if ($max <= 0) $max = 1;
    $count = $this->count_user_entries($raffle_id, $user_id);
    if ($count >= $max) return 'Você já atingiu o limite de inscrições neste sorteio.';

    $entry_id = $this->create_entry($raffle_id, $user_id);
    if (!$entry_id) return 'Não foi possível registrar sua inscrição.';

    return 'Inscrição registrada com sucesso.';
  }

  private function get_open_raffles($limit){
    $now = time();
    $q = new WP_Query([
      'post_type' => self::CPT_RAFFLE,
      'post_status' => 'publish',
      'posts_per_page' => max(1, (int)$limit),
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        [
          'key' => self::META_RAFFLE_STATUS,
          'value' => 'open',
          'compare' => '=',
        ],
      ],
    ]);

    $out = [];
    foreach ((array)$q->posts as $p){
      $rid = (int)$p->ID;
      $start = (int)get_post_meta($rid, self::META_RAFFLE_START_AT, true);
      $end = (int)get_post_meta($rid, self::META_RAFFLE_END_AT, true);
      if ($start && $now < $start) continue;
      if ($end && $now > $end) continue;
      $out[] = $p;
    }
    return $out;
  }

  private function create_entry($raffle_id, $user_id){
    $title = sprintf('Raffle #%d — user #%d', (int)$raffle_id, (int)$user_id);
    $entry_id = wp_insert_post([
      'post_type' => self::CPT_ENTRY,
      'post_status' => 'publish',
      'post_title' => $title,
    ], true);
    if (is_wp_error($entry_id)) return 0;

    update_post_meta($entry_id, self::META_ENTRY_RAFFLE_ID, (int)$raffle_id);
    update_post_meta($entry_id, self::META_ENTRY_USER_ID, (int)$user_id);
    update_post_meta($entry_id, self::META_ENTRY_CREATED_AT, time());
    return (int)$entry_id;
  }

  private function count_user_entries($raffle_id, $user_id){
    $q = new WP_Query([
      'post_type' => self::CPT_ENTRY,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => self::META_ENTRY_RAFFLE_ID,
          'value' => (string)(int)$raffle_id,
          'compare' => '=',
        ],
        [
          'key' => self::META_ENTRY_USER_ID,
          'value' => (string)(int)$user_id,
          'compare' => '=',
        ],
      ],
    ]);
    return (int)($q->found_posts ?? 0);
  }
}
