<?php
if (!defined('ABSPATH')) exit;

class Yoda_User_Card {

  const COOKIE_KAKO_ID = 'yoda_kako_id';

  public function hooks(){
    add_shortcode('yoda_kako_card', [$this,'shortcode']);
    add_shortcode('yoda_kako_logout', [$this,'logout_shortcode']);
    add_action('wp_enqueue_scripts', [$this,'assets']);
  }

  public function assets(){
    $css = "
    .yoda-kako-card{
      position:relative;
      display:flex;
      gap:16px;
      align-items:center;
      padding:18px;
      border-radius:18px;
      background:linear-gradient(105deg, rgba(245,237,255,.9) 0%, rgba(255,255,255,.95) 100%);
      border:1px solid rgba(122,49,255,.10);
      box-shadow:0 18px 45px rgba(122,49,255,.10), 0 3px 10px rgba(0,0,0,.03);
      overflow:hidden;
    }
    .yoda-kako-card:before{
      content:'';
      position:absolute; inset:-1px;
      background: radial-gradient(1200px 300px at -100px -200px, rgba(122,49,255,.15), transparent 40%),
                  radial-gradient(900px 240px at 120% 120%, rgba(244,166,255,.18), transparent 45%);
      pointer-events:none;
      border-radius:18px;
    }
    .yoda-kako-avatar{
      width:64px; height:64px; flex:0 0 64px;
      border-radius:50%;
      object-fit:cover; object-position:center;
      border:3px solid #fff;
      box-shadow:0 6px 18px rgba(122,49,255,.18);
      background:#f4f4f8;
    }
    .yoda-kako-main{min-width:0;}
    .yoda-kako-name{
      font-weight:800; font-size:20px; line-height:1.2; color:#1a1a1a; margin:0 0 2px;
    }
    .yoda-kako-id{
      font-size:13px; color:#616161; margin:0;
    }
    .yoda-kako-badges{
      display:flex; gap:8px; align-items:center; margin-top:8px;
      flex-wrap:wrap;
    }
    .yoda-badge{
      font-size:12px; font-weight:700; padding:6px 10px; border-radius:999px;
      background:#f2f2f6; color:#404040; border:1px solid rgba(0,0,0,.05);
    }
    .yoda-badge.ok{ background:#e9fff1; color:#0b7a2a; border-color:rgba(14,167,70,.18)}
    .yoda-badge.muted{background:#f7f7fb; color:#6a6a6a}
    .yoda-kako-card.alert{
      background:linear-gradient(105deg, #fff7f7, #fff);
      border-color:rgba(230,0,0,.14);
      box-shadow:0 10px 32px rgba(230,0,0,.06);
    }
    .yoda-kako-alert-title{
      margin:0 0 6px; font-weight:800; color:#9b1b1b; font-size:16px;
    }
    .yoda-kako-alert-text{
      margin:0; color:#7a2727; font-size:13px;
    }
    /* Logout button style (pill) */
    .yoda-kako-logout{ display:inline-flex; align-items:center; gap:8px; padding:12px 18px; border-radius:999px; background:#fff; color:#111; font-weight:800; text-decoration:none; border:1px solid rgba(0,0,0,.08); box-shadow:0 6px 20px rgba(0,0,0,.06); line-height:1.1; }
    .yoda-kako-logout:hover{ filter:brightness(.98); box-shadow:0 10px 28px rgba(0,0,0,.08); }
    .yoda-kako-logout .yk-ico{ font-size:16px; line-height:1; }
    .yoda-kako-logout .yk-label{ line-height:1; }
    .yoda-kako-logout{ position:relative; overflow:hidden; transition:transform .2s ease, box-shadow .2s ease; }
    .yoda-kako-logout.is-logging-out{ transform:scale(.98); }
    .yoda-kako-logout .yk-progress{ position:absolute; left:0; bottom:0; height:2px; width:0; background:linear-gradient(90deg,#7a31ff,#f4a6ff,#7a31ff); background-size:200% 100%; border-radius:0 0 999px 999px; }
    .yoda-kako-logout.is-logging-out .yk-progress{ animation:yoda-progress .9s ease-out forwards, yoda-shimmer .9s linear infinite; }
    .yoda-kako-logout.is-logging-out .yk-ico{ animation:yoda-spin .9s ease-in-out forwards; }
    .yk-cf{ position:absolute; top:50%; left:50%; width:6px; height:6px; transform:translate(-50%,-50%); background:var(--c,#7a31ff); border-radius:1px; opacity:.95; animation:yoda-confetti .9s ease-out forwards; pointer-events:none; }
    @keyframes yoda-progress{ to { width:100%; } }
    @keyframes yoda-shimmer{ 0%{ background-position:0% 50% } 100%{ background-position:200% 50% } }
    @keyframes yoda-spin{ 0%{ transform:rotate(0) scale(1) } 100%{ transform:rotate(360deg) scale(1.1) } }
    @keyframes yoda-confetti{ to { transform:translate(calc(-50% + var(--dx,0px)), calc(-50% + var(--dy,-60px))) rotate(540deg); opacity:0 } }
    .yoda-kako-actions{ display:flex; gap:10px; align-items:center; margin-top:10px; flex-wrap:wrap; }
    .yoda-kako-actions .yoda-input{ flex:1 1 220px; min-width:180px; padding:10px 12px; border-radius:10px; border:1px solid #e5e5ee; background:#fff; box-shadow:inset 0 1px 2px rgba(0,0,0,.04); font-size:14px; }
    .yoda-kako-actions .yoda-btn{ padding:10px 14px; border-radius:10px; border:1px solid rgba(122,49,255,.25); background:#7a31ff; color:#fff; font-weight:700; cursor:pointer; box-shadow:0 6px 16px rgba(122,49,255,.18); }
    .yoda-kako-actions .yoda-btn:hover{ filter:brightness(.96) }
    .yoda-kako-remember{ display:flex; align-items:center; gap:8px; margin-top:10px; font-size:13px; color:#616161; user-select:none; }
    .yoda-kako-remember input{ width:16px; height:16px; }
    @media (max-width:560px){
      .yoda-kako-card{padding:14px}
      .yoda-kako-avatar{width:56px;height:56px;flex-basis:56px}
      .yoda-kako-name{font-size:18px}
    }
    ";
    wp_register_style('yoda-kako-card-inline', false);
    wp_enqueue_style('yoda-kako-card-inline');
    wp_add_inline_style('yoda-kako-card-inline', $css);
  }

  private function get_effective_creds(){
    $opts   = get_option(Yoda_Admin::OPT_KEY, []);
    $appId  = (defined('KAKO_APP_ID')  && KAKO_APP_ID)  ? KAKO_APP_ID  : ($opts['app_id']  ?? '');
    $appKey = (defined('KAKO_APP_KEY') && KAKO_APP_KEY) ? KAKO_APP_KEY : ($opts['app_key'] ?? '');
    if (defined('KAKO_API_BASE') && KAKO_API_BASE) {
      $base = KAKO_API_BASE;
    } else {
      $base = $opts['base'] ?? '';
      if (!$base){
        $mode = $opts['mode'] ?? 'sandbox';
        $base = ($mode === 'production') ? 'https://api.kako.live' : 'https://api-test.kako.live';
      }
    }
    return [$appId,$appKey,$base];
  }

  private function is_valid_kako_id($kakoId){
    return is_string($kakoId) && preg_match('/^[A-Za-z0-9_.\\-]{3,32}$/', $kakoId);
  }

  private function clear_kako_cookie_server(){
    $name   = self::COOKIE_KAKO_ID;
    $expire = time() - 3600;
    $secure = is_ssl();
    $host   = parse_url(home_url('/'), PHP_URL_HOST);
    $cands  = [''];

    if ($host && !preg_match('/^\\d+\\.\\d+\\.\\d+\\.\\d+$/', $host) && $host !== 'localhost'){
      $parts = explode('.', $host);
      for ($i = 0; $i <= max(0, count($parts)-2); $i++){
        $slice = array_slice($parts, $i);
        if (count($slice) < 2) continue;
        $cands[] = '.' . implode('.', $slice);
      }
    }

    foreach (array_unique($cands) as $domain){
      if (PHP_VERSION_ID >= 70300){
        $opts = [
          'expires'  => $expire,
          'path'     => '/',
          'secure'   => $secure,
          'httponly' => false,
          'samesite' => 'Lax',
        ];
        if ($domain !== '') $opts['domain'] = $domain;
        @setcookie($name, '', $opts);
      } else {
        @setcookie($name, '', $expire, '/; samesite=Lax', $domain, $secure, false);
      }
    }
  }

  public function shortcode($atts){
    $atts = shortcode_atts([
      'kakoid' => '',
      'show_badge' => 'yes',
    ], $atts);

    $remember = isset($_GET['yoda_remember']) && (string)$_GET['yoda_remember'] === '1';
    $kakoId = trim((string)$atts['kakoid']);
    $source = '';

    if ($kakoId !== '') {
      $source = 'attr';
    } elseif (isset($_GET['kakoid']) && $_GET['kakoid'] !== '') {
      $kakoId = sanitize_text_field($_GET['kakoid']);
      $source = 'get';
    } elseif (isset($_COOKIE[self::COOKIE_KAKO_ID]) && $_COOKIE[self::COOKIE_KAKO_ID] !== '') {
      $kakoId = sanitize_text_field($_COOKIE[self::COOKIE_KAKO_ID]);
      $source = 'cookie';
    }

    if (!$kakoId){
      return $this->render_form_card([
        'mode' => 'neutral',
        'title' => 'Conta Kako',
        'text' => 'Digite seu ID para exibir o cartão.',
        'kakoId' => '',
        'remember' => false,
        'btn' => 'Verificar',
      ]);
    }

    if (!$this->is_valid_kako_id($kakoId)){
      if ($source === 'cookie') $this->clear_kako_cookie_server();
      return $this->render_form_card([
        'mode' => 'alert',
        'title' => 'ID inválido',
        'text' => 'Use apenas letras, números, ponto, hífen ou underline (3 a 32 caracteres).',
        'kakoId' => $kakoId,
        'remember' => $remember,
        'btn' => 'Tente novamente',
      ]);
    }

    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res    = $client->userinfo($kakoId);

    if (is_wp_error($res)){
      if ($source === 'cookie') $this->clear_kako_cookie_server();
      return $this->render_form_card([
        'mode' => 'alert',
        'title' => 'Não foi possível verificar o ID',
        'text' => esc_html($res->get_error_message()),
        'kakoId' => $kakoId,
        'remember' => $remember,
        'btn' => 'Tente novamente',
      ]);
    }

    if (($res['json']['code'] ?? -1) !== 0){
      if ($source === 'cookie') $this->clear_kako_cookie_server();
      $msg = $res['json']['msg'] ?? 'Conta não encontrada.';
      return $this->render_form_card([
        'mode' => 'alert',
        'title' => 'ID não encontrado',
        'text' => esc_html($msg).' — verifique se o número está correto.',
        'kakoId' => $kakoId,
        'remember' => $remember,
        'btn' => 'Tente novamente',
      ]);
    }

    $data     = $res['json']['data'] ?? [];
    $nickname = $data['nickname'] ?? '';
    $avatar   = $data['avatar']   ?? '';
    $verified = true;

    if (!$nickname) $nickname = 'Usuário';
    if (!$avatar)   $avatar   = 'data:image/svg+xml;utf8,' . rawurlencode($this->placeholder_svg());

    $show_badge = strtolower($atts['show_badge']) !== 'no';

    if ($source === 'get' && class_exists('Yoda_Packs')){
      $ttl = $remember ? 60*60*24*180 : DAY_IN_SECONDS;
      Yoda_Packs::set_kako_cookie($kakoId, $ttl);
    }

    $remembered = ($source === 'get') ? $remember : ($source === 'cookie');
    $maxAge = $remember ? 60*60*24*180 : 60*60*24;

    ob_start(); ?>
      <div class="yoda-kako-card" id="verificar-id">
        <img class="yoda-kako-avatar" src="<?php echo esc_url($avatar); ?>" alt="Avatar do usuário" loading="lazy" />
        <div class="yoda-kako-main">
          <h3 class="yoda-kako-name">Bem vindo (<?php echo esc_html($nickname); ?>)</h3>
          <p class="yoda-kako-id">ID: <?php echo esc_html($kakoId); ?></p>
          <div class="yoda-kako-badges">
            <?php if ($show_badge && $verified): ?>
              <span class="yoda-badge ok">Verificado</span>
            <?php endif; ?>
            <?php if ($remembered): ?>
              <span class="yoda-badge muted">ID salvo</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <script>
      (function(){
        try{
          var id  = <?php echo wp_json_encode($kakoId); ?>;
          var src = <?php echo wp_json_encode($source); ?>; // attr | get | cookie
          var remember = <?php echo $remember ? 'true' : 'false'; ?>;
          var maxAge = <?php echo (int)$maxAge; ?>;
          if (src === 'get'){
            var parts = [
              'yoda_kako_id=' + encodeURIComponent(id),
              'path=/',
              'max-age=' + maxAge,
              'samesite=lax'
            ];
            if (location.protocol === 'https:') parts.push('secure');
            document.cookie = parts.join('; ');
          }
          window.dispatchEvent(new CustomEvent('yoda:id:verified', { detail: { kakoId: id, remember: remember }}));
        }catch(e){ /* noop */ }
      })();
      </script>
    <?php
    return ob_get_clean();
  }

  private function render_form_card(array $data){
    $mode     = $data['mode'] ?? 'neutral'; // neutral | alert
    $title    = (string)($data['title'] ?? 'Conta Kako');
    $text     = (string)($data['text'] ?? '');
    $kakoId   = (string)($data['kakoId'] ?? '');
    $remember = !empty($data['remember']);
    $btn      = (string)($data['btn'] ?? 'Verificar');

    $baseUrl = remove_query_arg(['kakoid','yoda_remember']);
    $action  = esc_url($baseUrl);
    $checked = $remember ? 'checked' : '';
    $cardCls = $mode === 'alert' ? 'yoda-kako-card alert' : 'yoda-kako-card';
    $avatar  = $mode === 'alert'
      ? 'data:image/svg+xml;utf8,'.rawurlencode($this->placeholder_svg('#ffdddd','#ffcccc','#9b1b1b'))
      : 'data:image/svg+xml;utf8,'.rawurlencode($this->placeholder_svg());

    ob_start(); ?>
      <div class="<?php echo esc_attr($cardCls); ?>" id="verificar-id">
        <img class="yoda-kako-avatar" src="<?php echo esc_attr($avatar); ?>" alt="" />
        <div class="yoda-kako-main">
          <?php if ($mode === 'alert'): ?>
            <h3 class="yoda-kako-alert-title"><?php echo esc_html($title); ?></h3>
            <p class="yoda-kako-alert-text"><?php echo wp_kses_post($text); ?></p>
          <?php else: ?>
            <h3 class="yoda-kako-name"><?php echo esc_html($title); ?></h3>
            <p class="yoda-kako-id"><?php echo esc_html($text); ?></p>
          <?php endif; ?>

          <form class="yoda-kako-actions" method="get" action="<?php echo $action; ?>">
            <input type="text" name="kakoid" class="yoda-input" placeholder="Ex.: 10402704" autocomplete="off" value="<?php echo esc_attr($kakoId); ?>" />
            <button type="submit" class="yoda-btn"><?php echo esc_html($btn); ?></button>
            <?php
              foreach ($_GET as $k => $v){
                if ($k === 'kakoid' || $k === 'yoda_remember') continue;
                if (is_array($v)) continue;
                echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$v).'" />';
              }
            ?>
            <label class="yoda-kako-remember">
              <input type="checkbox" name="yoda_remember" value="1" <?php echo $checked; ?> />
              Lembrar meu ID para a próxima visita
            </label>
          </form>

          <div class="yoda-kako-badges">
            <span class="yoda-badge muted"><?php echo $mode === 'alert' ? 'Tente novamente' : 'Aguardando verificação'; ?></span>
          </div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  public function logout_shortcode($atts){
    $atts = shortcode_atts([
      'label'    => 'Sair da Conta',
      'redirect' => '',
      'class'    => '',
      'confirm'  => 'no',
      'icon'     => '',
    ], $atts);

    $id       = 'yoda-kako-logout-'.uniqid();
    $label    = $atts['label'];
    $redirect = esc_url_raw($atts['redirect']);
    $icon     = trim((string)$atts['icon']);
    $confirm  = strtolower(trim($atts['confirm'])) === 'yes';
    $classStr = trim((string)$atts['class']);
    $classes  = array_filter(array_map('sanitize_html_class', preg_split('/\\s+/', $classStr)));
    array_unshift($classes, 'yoda-kako-logout');
    $class    = implode(' ', array_unique($classes));

    ob_start(); ?>
      <a href="#" id="<?php echo esc_attr($id); ?>" class="<?php echo esc_attr($class); ?>" role="button">
        <?php if ($icon !== ''): ?><span class="yk-ico"><?php echo esc_html($icon); ?></span><?php endif; ?>
        <span class="yk-label"><?php echo esc_html($label); ?></span>
      </a>
      <script>
      (function(){
        function clearKakoCookie(){
          try{
            var name='yoda_kako_id';
            var base='; path=/; samesite=lax';
            var expPast='; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
            var max0='; Max-Age=0';
            var sec = (location.protocol === 'https:') ? '; secure' : '';
            document.cookie = name+'='+max0+base+sec;
            document.cookie = name+'='+expPast+base+sec;
            var host = location.hostname.split('.');
            for (var i=0;i<host.length-1;i++){
              var d = host.slice(i).join('.');
              document.cookie = name+'='+max0+base+'; domain=.'+d+sec;
              document.cookie = name+'='+expPast+base+'; domain=.'+d+sec;
            }
          }catch(e){/* noop */}
        }
        function makeConfetti(parent){
          var colors=['#7a31ff','#f4a6ff','#00d4ff','#ffd166','#06d6a0','#ef476f'];
          for(var i=0;i<16;i++){
            var s=document.createElement('span');
            s.className='yk-cf';
            var dx=((Math.random()*140)-70).toFixed(0)+'px';
            var dy=((Math.random()*-90)-30).toFixed(0)+'px';
            s.style.setProperty('--dx', dx);
            s.style.setProperty('--dy', dy);
            s.style.setProperty('--c', colors[Math.floor(Math.random()*colors.length)]);
            parent.appendChild(s);
            setTimeout(function(el){ return function(){ try{ el.remove(); }catch(e){} }; }(s), 1200);
          }
        }
        var btn = document.getElementById(<?php echo wp_json_encode($id); ?>);
        if (!btn) return;
        btn.addEventListener('click', function(ev){
          ev.preventDefault();
          if (btn.dataset.busy==='1') return;
          btn.dataset.busy='1';
          btn.classList.add('is-logging-out');
          (function(){ var p=document.createElement('span'); p.className='yk-progress'; btn.appendChild(p); })();
          makeConfetti(btn);
          try{ btn.querySelector('.yk-label').textContent='Saindo…'; }catch(e){}
          <?php if ($confirm): ?>
          if (!window.confirm('Deseja realmente sair desta conta?')) return;
          <?php endif; ?>
          clearKakoCookie();
          try { window.dispatchEvent(new CustomEvent('yoda:id:cleared')); } catch(e){}
          setTimeout(function(){
            var target = <?php echo $redirect ? wp_json_encode($redirect) : 'null'; ?>;
            if (target){ window.location.href = target; return; }
            try {
              var u = new URL(window.location.href);
              u.searchParams.delete('kakoid');
              u.searchParams.delete('yoda_remember');
              window.location.replace(u.toString());
            } catch(e) { window.location.reload(); }
          }, 950);
        });
      })();
      </script>
    <?php
    return ob_get_clean();
  }

  private function placeholder_svg($bg='#f2f2f6', $dot='#d6d6e3', $stroke='#b5b5c3'){
    return '
      <svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
        <defs>
          <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="'.$bg.'"/>
            <stop offset="100%" stop-color="#ffffff"/>
          </linearGradient>
        </defs>
        <rect x="0" y="0" width="128" height="128" rx="64" fill="url(#g)"/>
        <circle cx="64" cy="52" r="24" fill="'.$dot.'" stroke="'.$stroke.'" stroke-width="2"/>
        <rect x="28" y="88" width="72" height="18" rx="9" fill="'.$dot.'" stroke="'.$stroke.'" stroke-width="2"/>
      </svg>';
  }
}
