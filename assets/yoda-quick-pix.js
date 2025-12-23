(function(){
  function q(sel, ctx){ return (ctx||document).querySelector(sel); }
  function qa(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }

  function digits(v){ return String(v||'').replace(/\D+/g,''); }

  function getCookie(name){
    try{
      var escaped = String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      var m = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
      return m ? decodeURIComponent(m[1] || '') : '';
    }catch(e){ return ''; }
  }

  function openModal(html){
    closeModal();
    var overlay = document.createElement('div');
    overlay.className = 'yoda-qp-overlay';
    overlay.innerHTML = html;
    overlay.addEventListener('click', function(ev){
      if (ev.target === overlay) closeModal();
    });
    document.body.appendChild(overlay);
    document.documentElement.style.overflow = 'hidden';
    return overlay;
  }

  function closeModal(){
    var el = q('.yoda-qp-overlay');
    if (el) el.remove();
    document.documentElement.style.overflow = '';
  }

  function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
    });
  }

  function fetchJson(url, opts){
    return fetch(url, opts||{}).then(function(r){ return r.text(); }).then(function(t){
      try { return JSON.parse(t); } catch(e){ return { ok:false, data:{ msg: t || 'Erro inesperado' } }; }
    });
  }

  function getKakoId(){
    return getCookie('yoda_kako_id');
  }

  function loadKakoUser(restUrl, kakoId){
    return fetchJson(restUrl + '?kakoid=' + encodeURIComponent(kakoId), { method:'GET', credentials:'same-origin' })
      .then(function(r){
        if (r && r.ok && r.data) return r.data;
        return null;
      });
  }

  function formatCPF(cpf){
    var d = digits(cpf).slice(0,11);
    if (d.length !== 11) return d;
    return d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9,11);
  }

  function formatPhoneBR(v){
    var d = digits(v);
    if (d.startsWith('55')) d = d.slice(2);
    d = d.slice(0,11);
    if (d.length <= 2) return d;
    if (d.length <= 6) return '('+d.slice(0,2)+') '+d.slice(2);
    if (d.length <= 10) return '('+d.slice(0,2)+') '+d.slice(2,6)+'-'+d.slice(6);
    return '('+d.slice(0,2)+') '+d.slice(2,7)+'-'+d.slice(7);
  }

  function buildFormModal(cfg){
    return `
      <div class="yoda-qp-modal" role="dialog" aria-modal="true">
        <div class="yoda-qp-head">
          <p class="yoda-qp-title">${escapeHtml(cfg.title || 'Pagamento PIX')}</p>
          <button class="yoda-qp-close" type="button" aria-label="${escapeHtml(cfg.close || 'Fechar')}">✕</button>
        </div>
        <div class="yoda-qp-body">
          <div class="yoda-qp-grid">
            <div class="yoda-qp-field">
              <label>Nome completo</label>
              <input type="text" name="name" placeholder="Nome + Sobrenome" autocomplete="name" />
            </div>
            <div class="yoda-qp-row">
              <div class="yoda-qp-field">
                <label>CPF</label>
                <input type="text" name="cpf" placeholder="Somente números" inputmode="numeric" autocomplete="off" />
              </div>
              <div class="yoda-qp-field">
                <label>WhatsApp</label>
                <input type="text" name="whatsapp" placeholder="(DDD) número" inputmode="numeric" autocomplete="tel" />
              </div>
            </div>
          </div>

          <div class="yoda-qp-user" data-userbox>
            <img alt="" src="${escapeHtml(cfg.placeholder || '')}" />
            <div>
              <p class="name">Confirmando conta…</p>
              <p class="meta">Kako ID: ${escapeHtml(cfg.kakoid || '')}</p>
            </div>
          </div>

          <div class="yoda-qp-summary">
            <div class="line"><span>Pacote</span><strong>${escapeHtml(cfg.productName || '')}</strong></div>
            <div class="line"><span>Moedas</span><strong>${escapeHtml(cfg.coins || '')}</strong></div>
            ${cfg.price ? `<div class="line"><span>Total</span><strong>${escapeHtml(cfg.price)}</strong></div>` : ``}
          </div>

          <div class="yoda-qp-actions">
            <button class="btn" type="button" data-cancel>${escapeHtml(cfg.back || 'Voltar')}</button>
            <button class="btn primary" type="button" data-confirm>${escapeHtml(cfg.confirm || 'Confirmar pagamento')}</button>
          </div>

          <div class="yoda-qp-msg" data-msg style="display:none"></div>
        </div>
      </div>
    `;
  }

  function buildPixModal(cfg){
    return `
      <div class="yoda-qp-modal" role="dialog" aria-modal="true">
        <div class="yoda-qp-head">
          <p class="yoda-qp-title">Pagamento PIX</p>
          <button class="yoda-qp-close" type="button" aria-label="${escapeHtml(cfg.close || 'Fechar')}">✕</button>
        </div>
        <div class="yoda-qp-body">
          <div class="yoda-qp-pay">
            <div class="yoda-qp-summary">
              <div class="line"><span>Pedido</span><strong>#${escapeHtml(cfg.orderId || '')}</strong></div>
              <div class="line"><span>Total</span><strong>${escapeHtml(cfg.total || '')}</strong></div>
              <div class="line"><span>Moedas</span><strong>${escapeHtml(cfg.coins || '')}</strong></div>
              <div class="line"><span>Kako ID</span><strong>${escapeHtml(cfg.kakoid || '')}</strong></div>
            </div>
            <iframe class="yoda-qp-iframe" title="Pagamento PIX" src="${escapeHtml(cfg.payUrl || '')}"></iframe>
          </div>
        </div>
      </div>
    `;
  }

  function setMsg(root, text){
    var el = q('[data-msg]', root);
    if (!el) return;
    if (!text){
      el.style.display = 'none';
      el.textContent = '';
      return;
    }
    el.style.display = 'block';
    el.textContent = text;
  }

  function bindModalCommon(overlay){
    qa('.yoda-qp-close,[data-cancel]', overlay).forEach(function(b){
      b.addEventListener('click', function(){ closeModal(); });
    });
  }

  function persistFormIfChecked(root){
    try{
      var name = q('input[name="name"]', root).value || '';
      var cpf = q('input[name="cpf"]', root).value || '';
      var whatsapp = q('input[name="whatsapp"]', root).value || '';
      localStorage.setItem('yoda_qp_name', name);
      localStorage.setItem('yoda_qp_cpf', cpf);
      localStorage.setItem('yoda_qp_whatsapp', whatsapp);
    }catch(e){}
  }

  function loadPersisted(root){
    try{
      var name = localStorage.getItem('yoda_qp_name') || '';
      var cpf = localStorage.getItem('yoda_qp_cpf') || '';
      var whatsapp = localStorage.getItem('yoda_qp_whatsapp') || '';
      var nameEl = q('input[name="name"]', root); if (nameEl) nameEl.value = name;
      var cpfEl = q('input[name="cpf"]', root); if (cpfEl) cpfEl.value = cpf;
      var waEl = q('input[name="whatsapp"]', root); if (waEl) waEl.value = whatsapp;
    }catch(e){}
  }

  function attachMasks(root){
    var cpf = q('input[name="cpf"]', root);
    if (cpf) cpf.addEventListener('input', function(){ cpf.value = formatCPF(cpf.value); });
    var wa = q('input[name="whatsapp"]', root);
    if (wa) wa.addEventListener('input', function(){ wa.value = formatPhoneBR(wa.value); });
  }

  function openQuickPix(productId, productName, coins, price){
    var kakoId = getKakoId();
    if (!kakoId){
      try{ location.hash = '#verificar-id'; }catch(e){}
      return;
    }
    var cfg = {
      title: (window.YodaQuickPix && YodaQuickPix.texts && YodaQuickPix.texts.title) || 'Insira seus dados para pagamento',
      confirm: (window.YodaQuickPix && YodaQuickPix.texts && YodaQuickPix.texts.confirm) || 'Confirmar pagamento',
      close: (window.YodaQuickPix && YodaQuickPix.texts && YodaQuickPix.texts.close) || 'Fechar',
      back: 'Voltar',
      kakoid: kakoId,
      productName: productName || ('Produto #' + productId),
      coins: coins || '',
      price: price || '',
      placeholder: '',
    };
    var overlay = openModal(buildFormModal(cfg));
    bindModalCommon(overlay);
    var modal = q('.yoda-qp-modal', overlay);
    loadPersisted(modal);
    attachMasks(modal);

    // Load user info (avatar/nickname)
    var restKako = (window.YodaQuickPix && YodaQuickPix.restKakoUser) ? YodaQuickPix.restKakoUser : '';
    if (restKako){
      loadKakoUser(restKako, kakoId).then(function(u){
        if (!u) return;
        var box = q('[data-userbox]', modal);
        if (!box) return;
        q('img', box).src = u.avatar || '';
        q('.name', box).textContent = u.nickname ? ('Conta: ' + u.nickname) : 'Conta verificada';
        q('.meta', box).textContent = 'Kako ID: ' + kakoId;
      });
    }

    var btn = q('[data-confirm]', modal);
    btn.addEventListener('click', function(){
      setMsg(modal, '');
      var name = (q('input[name="name"]', modal).value || '').trim();
      var cpf = digits(q('input[name="cpf"]', modal).value || '');
      var whatsapp = digits(q('input[name="whatsapp"]', modal).value || '');

      if (name.length < 3) return setMsg(modal, 'Informe seu nome completo.');
      if (cpf.length !== 11) return setMsg(modal, 'CPF inválido.');
      if (whatsapp.length < 10) return setMsg(modal, 'WhatsApp inválido.');

      btn.disabled = true;
      btn.textContent = 'Gerando PIX…';

      var restQuick = (window.YodaQuickPix && YodaQuickPix.restQuickPix) ? YodaQuickPix.restQuickPix : '';
      if (!restQuick){
        setMsg(modal, 'Configuração ausente (REST).');
        btn.disabled = false;
        btn.textContent = cfg.confirm;
        return;
      }

      fetchJson(restQuick, {
        method: 'POST',
        headers: { 'Content-Type':'application/json' },
        body: JSON.stringify({
          product_id: productId,
          kakoid: kakoId,
          name: name,
          cpf: cpf,
          whatsapp: whatsapp
        }),
        credentials: 'same-origin'
      }).then(function(r){
        if (!(r && r.ok && r.data)){
          var msg = (r && r.message) ? r.message : ((r && r.data && r.data.msg) ? r.data.msg : 'Não foi possível gerar o PIX.');
          setMsg(modal, msg);
          return;
        }

        persistFormIfChecked(modal);

        var payUrl = r.data.pay_url || '';
        overlay.innerHTML = buildPixModal({
          close: cfg.close,
          payUrl: payUrl,
          orderId: r.data.order_id,
          total: r.data.total ? ('R$ ' + String(r.data.total)) : '',
          coins: (r.data.product && r.data.product.coins) ? String(r.data.product.coins) : (coins||''),
          kakoid: kakoId
        });
        bindModalCommon(overlay);
      }).finally(function(){
        btn.disabled = false;
        btn.textContent = cfg.confirm;
      });
    });
  }

  function findProductInfoFromClick(el){
    var pid = el.getAttribute('data-product-id') || el.getAttribute('data-pid') || '';
    var productId = parseInt(pid, 10) || 0;
    var productName = el.getAttribute('data-product-name') || '';
    var coins = el.getAttribute('data-coins') || '';
    var price = el.getAttribute('data-price') || '';

    // fallback: tenta extrair do href add-to-cart=123
    if (!productId){
      try{
        var href = el.getAttribute('href') || '';
        if (href){
          var u = new URL(href, window.location.origin);
          var atc = u.searchParams.get('add-to-cart') || '';
          productId = parseInt(atc, 10) || 0;
        }
      }catch(e){}
    }

    if (!productName){
      // tenta ler do card/loop
      var card = el.closest('.yoda-card') || el.closest('li.product') || el.closest('.product');
      if (card){
        var h = q('h2, h3, .woocommerce-loop-product__title', card);
        if (h) productName = (h.textContent || '').trim();
        if (!coins){
          var amt = q('.yoda-amount', card);
          if (amt) coins = digits(amt.textContent || '');
        }
      }
    }
    if (!price){
      var card2 = el.closest('.yoda-card') || el.closest('li.product') || el.closest('.product');
      if (card2){
        var p = q('.yoda-price, .price', card2);
        if (p) price = (p.textContent || '').trim();
      }
    }
    return { productId: productId, productName: productName, coins: coins, price: price };
  }

  function boot(){
    // Se o HTML veio do cache sem data-yoda-buy, tenta "marcar" links de add-to-cart automaticamente
    // (funciona tanto no shortcode quanto no loop de produtos).
    try{
      qa('a[href*="add-to-cart="]').forEach(function(a){
        if (a.getAttribute('data-yoda-buy') === '1') return;
        // só habilita o modal quando já existe KakoID (mantém comportamento atual quando não tem ID)
        if (!getKakoId()) return;
        a.setAttribute('data-yoda-buy', '1');
        // tenta preencher product-id pra evitar parsing extra no click
        try{
          var u = new URL(a.getAttribute('href'), window.location.origin);
          var pid = u.searchParams.get('add-to-cart') || '';
          if (pid) a.setAttribute('data-product-id', pid);
        }catch(e){}
      });
    }catch(e){}

    document.addEventListener('click', function(ev){
      // captura tanto data-yoda-buy quanto botões do loop (data-yoda-btn="buy")
      var a = ev.target.closest('[data-yoda-buy="1"], [data-yoda-btn="buy"]');
      if (!a) return;

      // Só intercepta se conseguirmos identificar product_id; caso contrário, mantém default
      ev.preventDefault();
      ev.stopPropagation();
      if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
      var info = findProductInfoFromClick(a);
      if (!info.productId) return;
      openQuickPix(info.productId, info.productName, info.coins, info.price);
    }, true);

    window.addEventListener('yoda:id:cleared', function(){
      closeModal();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
