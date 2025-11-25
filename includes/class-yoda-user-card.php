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
    // CSS: visual clean, “glass card”
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
    /* Logout button style (pill, similar to sample) */
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

  public function shortcode($atts){
    $atts = shortcode_atts([
      'kakoid' => '',     // você pode passar pelo shortcode
      'show_badge' => 'yes', // yes/no
    ], $atts);

    $kakoId = trim((string)$atts['kakoid']);
    $source = '';

    // Prioridade: shortcode > GET ?kakoid= > cookie
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
      // Sem ID — card “neutro”
      return $this->render_neutral();
    }

    // Creds efetivos
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

    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res    = $client->userinfo($kakoId);

    if (is_wp_error($res)){
      return $this->render_alert('Não foi possível verificar o ID', esc_html($res->get_error_message()));
    }
    if (($res['json']['code'] ?? -1) !== 0){
      $msg = $res['json']['msg'] ?? 'Conta não encontrada.';
      return $this->render_alert('ID não encontrado', esc_html($msg).' — verifique se o número está correto.');
    }

    $data     = $res['json']['data'] ?? [];
    $nickname = $data['nickname'] ?? '';
    $avatar   = $data['avatar']   ?? '';
    $verified = true; // se code=0, consideramos verificado

    if (!$nickname) $nickname = 'Usuário';
    if (!$avatar)   $avatar   = 'data:image/svg+xml;utf8,' . rawurlencode($this->placeholder_svg());

    $show_badge = strtolower($atts['show_badge']) !== 'no';

    // Seta cookie no servidor apenas se o ID veio explicitamente por GET (link compartilhado)
    if ($source === 'get' && class_exists('Yoda_Packs')){
      $ttl = 60*60*24*180; // 180 dias
      Yoda_Packs::set_kako_cookie($kakoId, $ttl);
    }

    ob_start(); ?>
      <div class="yoda-kako-card" id="verificar-id">
        <img class="yoda-kako-avatar" src="<?php echo esc_url($avatar); ?>" alt="Avatar do usuário" loading="lazy" />
        <div class="yoda-kako-main">
          <h3 class="yoda-kako-name">Bem Vindo (<?php echo esc_html($nickname); ?>)</h3>
          <p class="yoda-kako-id">ID: <?php echo esc_html($kakoId); ?></p>
          <div class="yoda-kako-badges">
            <?php if ($show_badge && $verified): ?>
              <span class="yoda-badge ok">✓ Verificado</span>
            <?php endif; ?>
            <!-- Espaço para futuras badges (nível, seguidores, etc) -->
          </div>
        </div>
      </div>
      <script>
      (function(){
        try{
          var id = <?php echo wp_json_encode($kakoId); ?>;
          var src = <?php echo wp_json_encode($source); ?>; // 'attr' | 'get' | 'cookie'
          var m = document.cookie.match(/(?:^|; )yoda_kako_id=([^;]+)/);
          var curr = m ? decodeURIComponent(m[1]) : '';
          // Só grava cookie automático se veio por GET (link compartilhado)
          if(src === 'get' && curr !== id){
            document.cookie = 'yoda_kako_id=' + encodeURIComponent(id) + '; path=/; max-age=' + (60*60*24*180);
          }
          window.dispatchEvent(new CustomEvent('yoda:id:verified', { detail: { kakoId: id }}));
        }catch(e){ /* noop */ }
      })();
      </script>
    <?php
    return ob_get_clean();
  }

  private function render_neutral(){
    ob_start(); ?>
      <div class="yoda-kako-card" id="verificar-id">
        <img class="yoda-kako-avatar" src="data:image/svg+xml;utf8,<?php echo rawurlencode($this->placeholder_svg()); ?>" alt="" />
        <div class="yoda-kako-main">
          <h3 class="yoda-kako-name">Conta Kako</h3>
          <p class="yoda-kako-id">Digite seu ID para exibir o cartão.</p>
          <form class="yoda-kako-actions" method="get" action="">
            <input type="text" name="kakoid" class="yoda-input" placeholder="Ex.: 10402704" autocomplete="off" />
            <button type="submit" class="yoda-btn">Verificar</button>
          </form>
          <div class="yoda-kako-badges">
            <span class="yoda-badge muted">Aguardando verificação</span>
          </div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  public function logout_shortcode($atts){
    $atts = shortcode_atts([
      'label'    => 'Sair da Conta',
      'redirect' => '',   // URL para onde redirecionar após limpar (opcional)
      'class'    => '',   // classes extras no botão (separadas por espaço)
      'confirm'  => 'no', // 'yes' para pedir confirmação
      'icon'     => '', // ícone opcional; ex.: 🏆
    ], $atts);

    $id       = 'yoda-kako-logout-'.uniqid();
    $label    = $atts['label'];
    $redirect = esc_url_raw($atts['redirect']);
    $icon     = trim((string)$atts['icon']);
    $confirm  = strtolower(trim($atts['confirm'])) === 'yes';
    $classStr = trim((string)$atts['class']);
    $classes  = array_filter(array_map('sanitize_html_class', preg_split('/\s+/', $classStr)));
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
            var base='; path=/';
            var expPast='; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
            var max0='; Max-Age=0';
            // Sem domínio
            document.cookie = name+'='+max0+base;
            document.cookie = name+'='+expPast+base;
            // Com domínio (subdomínios)
            var host = location.hostname.split('.');
            for (var i=0;i<host.length-1;i++){
              var d = host.slice(i).join('.');
              document.cookie = name+'='+max0+base+'; domain=.'+d;
              document.cookie = name+'='+expPast+base+'; domain=.'+d;
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
          // add progress bar
          (function(){ var p=document.createElement('span'); p.className='yk-progress'; btn.appendChild(p); })();
          // confetti burst
          makeConfetti(btn);
          // feedback textual
          try{ btn.querySelector('.yk-label').textContent='Saindo…'; }catch(e){}
          <?php if ($confirm): ?>
          if (!window.confirm('Deseja realmente sair desta conta?')) return;
          <?php endif; ?>
          // clear cookie now, navigate after animation
          clearKakoCookie();
          try { window.dispatchEvent(new CustomEvent('yoda:id:cleared')); } catch(e){}
          try { window.dispatchEvent(new CustomEvent('yoda:id:verified')); } catch(e){}
          setTimeout(function(){
            var target = <?php echo $redirect ? wp_json_encode($redirect) : 'null'; ?>;
            if (target){ window.location.href = target; return; }
            try {
              var u = new URL(window.location.href);
              u.searchParams.delete('kakoid');
              window.location.replace(u.toString());
            } catch(e) { window.location.reload(); }
          }, 950);
        });
      })();
      </script>
    <?php
    return ob_get_clean();
  }

  private function render_alert($title, $text){
    ob_start(); ?>
      <div class="yoda-kako-card alert">
        <img class="yoda-kako-avatar" src="data:image/svg+xml;utf8,<?php echo rawurlencode($this->placeholder_svg('#ffdddd','#ffcccc','#9b1b1b')); ?>" alt="" />
        <div class="yoda-kako-main">
          <h3 class="yoda-kako-alert-title"><?php echo esc_html($title); ?></h3>
          <p class="yoda-kako-alert-text"><?php echo wp_kses_post($text); ?></p>
          <div class="yoda-kako-badges">
            <span class="yoda-badge muted">Revise o ID informado</span>
          </div>
        </div>
      </div>
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
