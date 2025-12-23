<?php
if (!defined('ABSPATH')) exit;

class Yoda_User_Card {

  const COOKIE_KAKO_ID = 'yoda_kako_id';
  const VERIFY_TTL = 10 * MINUTE_IN_SECONDS;
  const RL_WINDOW = MINUTE_IN_SECONDS;
  const RL_MAX    = 60;

  public function hooks(){
    add_shortcode('yoda_kako_card', [$this,'shortcode']);
    add_shortcode('yoda_kako_logout', [$this,'logout_shortcode']);
    add_action('wp_enqueue_scripts', [$this,'assets']);

    add_action('wp_ajax_yoda_kako_card_verify',        [$this,'ajax_verify']);
    add_action('wp_ajax_nopriv_yoda_kako_card_verify', [$this,'ajax_verify']);

    // Endpoint REST (mais resiliente em sites com cache/CDN que quebram admin-ajax/nonce)
    add_action('rest_api_init', [$this, 'register_rest_routes']);
  }

  public function register_rest_routes(){
    register_rest_route('yoda/v1', '/ping', [
      'methods'  => ['GET'],
      'callback' => function(){ return new WP_REST_Response(['ok'=>true,'ping'=>'yoda-kako'], 200); },
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('yoda/v1', '/kako/userinfo', [
      // GET ajuda no teste via navegador; POST é usado pelo JS
      'methods'  => ['GET','POST','OPTIONS'],
      'callback' => [$this,'rest_userinfo'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function assets(){
    $css = "
    .yoda-kako-card{
      position:relative;
      display:grid;
      grid-template-columns:72px 1fr;
      gap:18px;
      align-items:center;
      padding:20px 22px;
      border-radius:18px;
      background:#efedf6;
      border:1px solid rgba(0,0,0,.06);
      box-shadow:0 18px 45px rgba(0,0,0,.10);
      overflow:hidden;
    }
    .yoda-kako-card:before{
      content:'';
      position:absolute; inset:-1px;
      background: radial-gradient(1200px 300px at -100px -200px, rgba(161,76,255,.18), transparent 40%),
                  radial-gradient(900px 240px at 120% 120%, rgba(93,166,56,.16), transparent 45%);
      pointer-events:none;
      border-radius:18px;
    }
    .yoda-kako-avatar{
      width:64px; height:64px; flex:0 0 64px;
      border-radius:50%;
      object-fit:cover; object-position:center;
      border:3px solid #fff;
      box-shadow:0 10px 26px rgba(0,0,0,.12);
      background:#f4f4f8;
    }
    .yoda-kako-main{min-width:0;}
    .yoda-kako-name{
      font-weight:900; font-size:22px; line-height:1.15; color:#111; margin:0 0 6px;
    }
    .yoda-kako-id{
      font-size:13px; color:#444; margin:0;
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
    .yoda-kako-actions .yoda-input{ flex:1 1 360px; min-width:220px; padding:14px 14px; border-radius:12px; border:1px solid rgba(0,0,0,.08); background:#fff; box-shadow:inset 0 1px 2px rgba(0,0,0,.05); font-size:14px; }
    .yoda-kako-actions .yoda-btn{ padding:14px 22px; border-radius:12px; border:0; background:#7a31ff; color:#fff; font-weight:900; cursor:pointer; box-shadow:0 10px 22px rgba(122,49,255,.22); }
    .yoda-kako-actions .yoda-btn:hover{ filter:brightness(.96) }
    .yoda-kako-remember{ display:flex; align-items:center; gap:8px; margin-top:10px; font-size:13px; color:#616161; user-select:none; }
    .yoda-kako-remember input{ width:16px; height:16px; }
    @media (max-width:560px){
      .yoda-kako-card{grid-template-columns:60px 1fr; padding:16px}
      .yoda-kako-avatar{width:54px;height:54px;flex-basis:54px}
      .yoda-kako-name{font-size:18px}
      .yoda-kako-actions{gap:8px}
      .yoda-kako-actions .yoda-input{min-width:0; flex:1 1 auto}
      .yoda-kako-actions .yoda-btn{width:100%}
    }
    ";
    wp_register_style('yoda-kako-card-inline', false);
    wp_enqueue_style('yoda-kako-card-inline');
    wp_add_inline_style('yoda-kako-card-inline', $css);

    // JS externo (mais robusto que inline em ambientes com cache/minify/builder)
    wp_enqueue_script('yoda-kako-card', plugins_url('../assets/yoda-kako-card.js', __FILE__), [], '1.0', true);
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

  private function rate_limit_check($bucket){
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'yoda_rl_'.sanitize_key($bucket).'_'.md5((string)$ip);
    $hits = (int) get_transient($key);
    $hits++;
    set_transient($key, $hits, self::RL_WINDOW);
    if ($hits > self::RL_MAX){
      return new WP_Error('rate_limited', 'Muitas tentativas. Aguarde um minuto e tente novamente.', ['status'=>429]);
    }
    return true;
  }

  private function userinfo_payload($kakoId){
    $kakoId = trim((string)$kakoId);
    if (!$kakoId){
      return new WP_Error('missing', 'ID não informado.', ['status'=>400]);
    }
    if (!$this->is_valid_kako_id($kakoId)){
      return new WP_Error('invalid', 'ID inválido. Use apenas letras, números, ponto, hífen ou underline (3 a 32 caracteres).', ['status'=>400]);
    }

    $ckey = 'yoda_kako_card_'.get_current_blog_id().'_'.md5(strtolower($kakoId));
    $cached = get_transient($ckey);
    if (is_array($cached) && !empty($cached['openId'])){
      return $cached;
    }

    list($appId,$appKey,$base) = $this->get_effective_creds();
    $client = new Yoda_Kako_Client($base, $appId, $appKey);
    $res    = $client->userinfo($kakoId);
    if (is_wp_error($res)){
      return new WP_Error('http', $res->get_error_message(), ['status'=>502]);
    }
    if (($res['json']['code'] ?? -1) !== 0){
      $msg = $res['json']['msg'] ?? 'Conta não encontrada.';
      return new WP_Error('not_found', $msg, ['status'=>404]);
    }

    $data = $res['json']['data'] ?? [];
    $payload = [
      'kakoId'   => $kakoId,
      'avatar'   => (string)($data['avatar']   ?? ''),
      'nickname' => (string)($data['nickname'] ?? ''),
      'openId'   => (string)($data['openId']   ?? ''),
    ];
    if (!$payload['nickname']) $payload['nickname'] = 'Usuário';
    if (!$payload['avatar'])   $payload['avatar']   = 'data:image/svg+xml;utf8,' . rawurlencode($this->placeholder_svg());
    if (!$payload['openId']){
      return new WP_Error('not_found', 'Conta não encontrada.', ['status'=>404]);
    }

    set_transient($ckey, $payload, self::VERIFY_TTL);
    return $payload;
  }

  public function rest_userinfo(WP_REST_Request $req){
    $rl = $this->rate_limit_check('kako_userinfo');
    if (is_wp_error($rl)) return $rl;

    $kakoId = $req->get_param('kakoid');
    if (!$kakoId){
      $body = json_decode($req->get_body(), true);
      if (is_array($body)) $kakoId = $body['kakoid'] ?? $body['kakoId'] ?? null;
    }
    $payload = $this->userinfo_payload($kakoId);
    if (is_wp_error($payload)) return $payload;

    $resp = new WP_REST_Response(['ok'=>true,'data'=>$payload], 200);
    // Evita qualquer cache (LiteSpeed/CDN/proxy) em respostas dinâmicas.
    $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $resp->header('Pragma', 'no-cache');
    return $resp;
  }

  public function shortcode($atts){
    $atts = shortcode_atts([
      'show_badge' => 'yes',
    ], $atts);

    // Importante: para evitar vazamento via cache de página (home), este shortcode não renderiza
    // conteúdo personalizado no HTML do servidor. Tudo é resolvido via JS + cookie (no browser).
    $instance = 'yoda-kako-card-'.uniqid();
    $rest = rest_url('yoda/v1/kako/userinfo');
    $showBadge = strtolower(trim((string)$atts['show_badge'])) !== 'no';

    $placeholder = 'data:image/svg+xml;utf8,' . rawurlencode($this->placeholder_svg());
    $placeholderAlert = 'data:image/svg+xml;utf8,' . rawurlencode($this->placeholder_svg('#ffdddd','#ffcccc','#9b1b1b'));

    ob_start(); ?>
      <div
        class="yoda-kako-card"
        id="verificar-id"
        data-yoda-kako-card="1"
        data-yoda-kako-instance="<?php echo esc_attr($instance); ?>"
        data-rest="<?php echo esc_url($rest); ?>"
        data-show-badge="<?php echo $showBadge ? '1' : '0'; ?>"
        data-placeholder="<?php echo esc_attr($placeholder); ?>"
        data-placeholder-alert="<?php echo esc_attr($placeholderAlert); ?>"
      >
        <img class="yoda-kako-avatar" src="<?php echo esc_attr($placeholder); ?>" alt="" />
        <div class="yoda-kako-main">
          <h3 class="yoda-kako-name">Conta Kako</h3>
          <p class="yoda-kako-id">Digite seu ID para exibir o cartão.</p>

          <form class="yoda-kako-actions" method="get" action="">
            <input type="text" name="kakoid" class="yoda-input" placeholder="Ex.: 10402704" autocomplete="off" />
            <button type="submit" class="yoda-btn">Verificar</button>
            <label class="yoda-kako-remember">
              <input type="checkbox" name="yoda_remember" value="1" />
              Lembrar meu ID para a próxima visita
            </label>
          </form>

          <div class="yoda-kako-badges">
            <span class="yoda-badge muted">Aguardando verificação</span>
          </div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  public function ajax_verify(){
    // Admin-ajax fallback (nonce pode expirar em páginas cacheadas).
    $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
    if ($nonce && !wp_verify_nonce($nonce, 'yoda_kako_card')){
      $rl = $this->rate_limit_check('kako_userinfo_ajax');
      if (is_wp_error($rl)){
        wp_send_json_error(['msg' => $rl->get_error_message()], $rl->get_error_data()['status'] ?? 429);
      }
    }

    $payload = $this->userinfo_payload($_POST['kakoid'] ?? '');
    if (is_wp_error($payload)){
      $status = (int)($payload->get_error_data()['status'] ?? 400);
      wp_send_json_error(['msg' => $payload->get_error_message()], $status);
    }
    wp_send_json_success($payload);
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
