(function(){
  function qs(sel, ctx){ return (ctx||document).querySelector(sel); }

  function getCookie(name){
    try{
      // Escape seguro para RegExp sem depender de uma regex literal complexa (evita quebra por minificação)
      var escaped = String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      var m = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
      return m ? decodeURIComponent(m[1] || '') : '';
    }catch(e){ return ''; }
  }

  function setCookieKakoId(value, maxAge){
    try{
      var name = 'yoda_kako_id';
      var baseParts = [
        name + '=' + encodeURIComponent(value),
        'path=/',
        'max-age=' + (maxAge|0),
        'samesite=lax'
      ];
      if (location.protocol === 'https:') baseParts.push('secure');
      document.cookie = baseParts.join('; ');
      var host = location.hostname.split('.');
      for (var i=0;i<host.length-1;i++){
        var d = host.slice(i).join('.');
        document.cookie = baseParts.concat(['domain=.'+d]).join('; ');
      }
    }catch(e){}
  }

  function clearCookieKakoId(){
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
    }catch(e){}
  }

  function safeJsonFromText(txt){
    try { return JSON.parse(txt); } catch(e){ return null; }
  }

  function fetchRest(restUrl, kakoId){
    // GET (mais compatível), depois POST
    return fetch(restUrl + '?kakoid=' + encodeURIComponent(kakoId), { method:'GET', credentials:'same-origin' })
      .then(function(r){ return r.text(); })
      .then(function(txt){
        var j = safeJsonFromText(txt);
        if (j && j.ok && j.data) return j;
        return fetch(restUrl, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ kakoid: kakoId }),
          credentials:'same-origin'
        }).then(function(r){ return r.text(); }).then(function(t){ return safeJsonFromText(t) || { ok:false, data:{ msg:t||'Erro inesperado' } }; });
      });
  }

  function renderNeutral(card){
    card.classList.remove('alert');
    var avatar = card.getAttribute('data-placeholder') || '';
    qs('.yoda-kako-avatar', card).setAttribute('src', avatar);
    qs('.yoda-kako-name', card).textContent = 'Conta Kako';
    qs('.yoda-kako-id', card).textContent = 'Digite seu ID para exibir o cartão.';
    qs('.yoda-kako-badges', card).innerHTML = '<span class="yoda-badge muted">Aguardando verificação</span>';
  }

  function renderAlert(card, title, text){
    card.classList.add('alert');
    var avatar = card.getAttribute('data-placeholder-alert') || '';
    qs('.yoda-kako-avatar', card).setAttribute('src', avatar);
    qs('.yoda-kako-name', card).textContent = title || 'ID não encontrado';
    qs('.yoda-kako-id', card).textContent = text || 'Tente novamente.';
    qs('.yoda-kako-badges', card).innerHTML = '<span class="yoda-badge muted">Tente novamente</span>';
  }

  function renderVerified(card, payload, remembered){
    card.classList.remove('alert');
    qs('.yoda-kako-avatar', card).setAttribute('src', payload.avatar || (card.getAttribute('data-placeholder')||''));
    qs('.yoda-kako-name', card).textContent = 'Bem vindo (' + (payload.nickname || 'Usuário') + ')';
    qs('.yoda-kako-id', card).textContent = 'ID: ' + (payload.kakoId || '');

    var showBadge = (card.getAttribute('data-show-badge') || '1') === '1';
    var html = '';
    if (showBadge) html += '<span class="yoda-badge ok">Verificado</span>';
    if (remembered) html += '<span class="yoda-badge muted">ID salvo</span>';
    if (!html) html = '<span class="yoda-badge muted">Verificado</span>';
    qs('.yoda-kako-badges', card).innerHTML = html;
  }

  function initCard(card){
    var restUrl = card.getAttribute('data-rest') || '';
    if (!restUrl) return;

    var form = qs('form.yoda-kako-actions', card);
    var input = qs('input[name="kakoid"]', card);
    var remember = qs('input[name="yoda_remember"]', card);
    var button = qs('button[type="submit"]', card);

    function setBusy(isBusy){
      if (!button) return;
      button.disabled = !!isBusy;
      button.textContent = isBusy ? 'Verificando…' : 'Verificar';
    }

    function verifyAndRender(kakoId, rememberChoice, silent){
      setBusy(true);
      return fetchRest(restUrl, kakoId).then(function(r){
        if (r && r.ok && r.data){
          var ttl = rememberChoice ? (60*60*24*180) : (60*60*24);
          setCookieKakoId(kakoId, ttl);
          renderVerified(card, r.data, rememberChoice || !!getCookie('yoda_kako_id'));
          try{ window.dispatchEvent(new CustomEvent('yoda:id:verified', { detail: { kakoId: kakoId, remember: rememberChoice }})); }catch(e){}
          return true;
        }
        var msg = (r && r.data && r.data.msg) ? r.data.msg : 'ID inválido ou não encontrado.';
        if (!silent) renderAlert(card, 'ID não encontrado', msg);
        return false;
      }).catch(function(){
        if (!silent) renderAlert(card, 'Falha de comunicação', 'Tente novamente em alguns instantes.');
        return false;
      }).finally(function(){
        setBusy(false);
      });
    }

    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      var kakoId = (input && input.value ? input.value : '').trim();
      if (!kakoId){
        renderAlert(card, 'Informe seu ID', 'Digite seu Kako ID e tente novamente.');
        return;
      }
      verifyAndRender(kakoId, !!(remember && remember.checked), false);
    });

    window.addEventListener('yoda:id:cleared', function(){
      clearCookieKakoId();
      if (input) input.value = '';
      if (remember) remember.checked = false;
      renderNeutral(card);
    });

    // boot: URL ?kakoid= tem prioridade; depois cookie
    try{
      var u = new URL(window.location.href);
      var kakoFromUrl = u.searchParams.get('kakoid') || '';
      if (kakoFromUrl){
        if (input) input.value = kakoFromUrl;
        verifyAndRender(kakoFromUrl, false, true).then(function(ok){
          if (!ok) renderAlert(card, 'ID não encontrado', 'Verifique se o número está correto e tente novamente.');
        });
        return;
      }
    }catch(e){}

    var cookieId = getCookie('yoda_kako_id');
    if (cookieId){
      if (input && !input.value) input.value = cookieId;
      verifyAndRender(cookieId, false, true);
    }
  }

  function boot(){
    document.querySelectorAll('[data-yoda-kako-card=\"1\"]').forEach(initCard);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
