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
    overlay.__yodaOnKeyDown = function(ev){
      if (ev && (ev.key === 'Escape' || ev.key === 'Esc')) closeModal();
    };
    document.addEventListener('keydown', overlay.__yodaOnKeyDown);
    document.body.appendChild(overlay);
    document.documentElement.style.overflow = 'hidden';
    try{
      var focusEl = q('.yoda-qp-close', overlay) || q('input,button,a', overlay);
      if (focusEl) focusEl.focus();
    }catch(e){}
    return overlay;
  }

  function closeModal(){
    var el = q('.yoda-qp-overlay');
    if (el){
      try{
        if (el.__yodaInterval) clearInterval(el.__yodaInterval);
      }catch(e){}
      try{
        if (el.__yodaOnKeyDown) document.removeEventListener('keydown', el.__yodaOnKeyDown);
      }catch(e){}
      el.remove();
    }
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
          <p class="yoda-qp-title">${escapeHtml(cfg.title || 'Pagamento via Pix')}</p>
          <p class="yoda-qp-subtitle">Confirme seus dados para gerar o Pix e liberar a entrega automática das moedas.</p>
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

          <div class="yoda-qp-steps" aria-label="Etapas">
            <span class="yoda-qp-step on" data-step="1">1. Dados</span>
            <span class="yoda-qp-step" data-step="2">2. Pagar</span>
            <span class="yoda-qp-step" data-step="3">3. Entrega</span>
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
    var userHtml = '';
    if (cfg.avatar || cfg.nickname || cfg.name){
      userHtml = `
        <div class="yoda-qp-user" style="margin:0" data-userbox>
          <img alt="" src="${escapeHtml(cfg.avatar || '')}" />
          <div>
            <p class="name">${escapeHtml(cfg.nickname ? ('Conta: ' + cfg.nickname) : 'Conta verificada')}</p>
            ${cfg.name ? `<p class="meta">${escapeHtml('Nome: ' + cfg.name)}</p>` : ``}
            <p class="meta">Kako ID: ${escapeHtml(cfg.kakoid || '')}</p>
          </div>
        </div>
      `;
    }
    return `
      <div class="yoda-qp-modal" role="dialog" aria-modal="true">
        <div class="yoda-qp-head">
          <p class="yoda-qp-title">Pagamento via Pix</p>
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
            ${userHtml}
            <p class="yoda-qp-live" data-live><span class="yoda-qp-dot"></span><span>Aguardando pagamento e entrega…</span></p>
            ${cfg.qrBase64 ? `
              <p class="yoda-qp-hint">Escaneie o QR Code ou copie e cole o código Pix.</p>
              <div class="yoda-qp-qr"><img alt="QR Code Pix" src="data:image/png;base64,${escapeHtml(cfg.qrBase64)}" /></div>
            ` : ``}
            ${cfg.qrCode ? `
              <div class="yoda-qp-code">
                <input type="text" readonly value="${escapeHtml(cfg.qrCode)}" />
                <button type="button" data-copy-pix>Copiar</button>
              </div>
            ` : ``}
            ${(!cfg.qrBase64 && !cfg.qrCode) ? `
              <iframe class="yoda-qp-iframe" title="Pagamento PIX" src="${escapeHtml(cfg.payUrl || '')}"></iframe>
            ` : ``}
          </div>
        </div>
      </div>
    `;
  }

  function buildDeliveredBox(cfg){
    var buyAgainUrl = '';
    try{
      buyAgainUrl = (window.YodaQuickPix && YodaQuickPix.buyAgainUrl) ? String(YodaQuickPix.buyAgainUrl) : '';
    }catch(e){ buyAgainUrl = ''; }
    return `
      <div class="yoda-qp-success yoda-qp-delivered">
        <h3>Moedas entregues com sucesso</h3>
        <p>Pagamento confirmado e moedas depositadas na conta informada.</p>
        ${cfg.orderRef ? `<div class="ref">Protocolo: ${escapeHtml(cfg.orderRef)}</div>` : ``}
        <div class="actions">
          ${buyAgainUrl ? `<a class="yoda-qp-success-btn" data-buy-again href="${escapeHtml(buyAgainUrl)}" target="_blank" rel="noopener noreferrer">Comprar novamente</a>` : ``}
        </div>
      </div>
    `;
  }

  function buildPaidBox(){
    var buyAgainUrl = '';
    try{
      buyAgainUrl = (window.YodaQuickPix && YodaQuickPix.buyAgainUrl) ? String(YodaQuickPix.buyAgainUrl) : '';
    }catch(e){ buyAgainUrl = ''; }
    return `
      <div class="yoda-qp-success yoda-qp-paid">
        <h3>Pagamento confirmado</h3>
        <p>Seu pagamento foi confirmado. A entrega acontece automaticamente (pode levar alguns segundos).</p>
        <div class="actions">
          ${buyAgainUrl ? `<a class="yoda-qp-success-btn" data-buy-again href="${escapeHtml(buyAgainUrl)}" target="_blank" rel="noopener noreferrer">Comprar novamente</a>` : ``}
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

  function formatExpires(expires){
    if (!expires) return '';
    try{
      var dt = new Date(expires);
      if (!isNaN(dt.getTime())) return dt.toLocaleString();
    }catch(e){}
    return String(expires);
  }

  function bindModalCommon(overlay){
    qa('.yoda-qp-close', overlay).forEach(function(b){
      try{ b.textContent = '×'; }catch(e){}
      b.addEventListener('click', function(){ closeModal(); });
    });
    qa('[data-cancel]', overlay).forEach(function(b){
      b.addEventListener('click', function(){ closeModal(); });
    });

    // Delegation: o botão pode ser inserido depois (quando o polling confirmar).
    overlay.addEventListener('click', function(ev){
      try{
        var t = ev && ev.target ? ev.target : null;
        if (!t) return;
        var a = (t.closest && t.closest('[data-buy-again]')) ? t.closest('[data-buy-again]') : null;
        if (!a || !overlay.contains(a)) return;

        var href = '';
        try{ href = String(a.getAttribute('href') || ''); }catch(e){ href = ''; }
        if (!href){
          try{ href = (window.YodaQuickPix && YodaQuickPix.buyAgainUrl) ? String(YodaQuickPix.buyAgainUrl) : ''; }catch(e){ href = ''; }
        }

        // Abre em nova aba e fecha o modal (evita ficar preso no hash no mobile).
        try{ if (ev && ev.preventDefault) ev.preventDefault(); }catch(e){}
        if (href){
          var w = null;
          try{ w = window.open(href, '_blank', 'noopener'); }catch(e){ w = null; }
          if (!w){
            try{ window.location.href = href; }catch(e){}
          }
        }
        closeModal();
      }catch(e){}
    }, true);

    function toast(text){
      try{
        var old = q('.yoda-qp-toast');
        if (old) old.remove();
        var t = document.createElement('div');
        t.className = 'yoda-qp-toast';
        t.textContent = text || 'Copiado';
        document.body.appendChild(t);
        setTimeout(function(){ try{ t.remove(); }catch(e){} }, 1400);
      }catch(e){}
    }

    function copyText(val){
      if (!val) return false;
      try{
        if (navigator.clipboard && navigator.clipboard.writeText){
          navigator.clipboard.writeText(val);
          return true;
        }
      }catch(e){}
      try{
        var tmp = document.createElement('textarea');
        tmp.value = val;
        tmp.style.position = 'fixed';
        tmp.style.left = '-9999px';
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        tmp.remove();
        return true;
      }catch(e){}
      return false;
    }

    // Compat: botao antigo (copia do input do codigo Pix)
    var copyBtn = q('[data-copy-pix]', overlay);
    if (copyBtn && !copyBtn.hasAttribute('data-copy-text')){
      copyBtn.addEventListener('click', function(){
        try{
          var input = copyBtn.parentElement ? q('input', copyBtn.parentElement) : null;
          var val = input ? input.value : '';
          if (!val) return;
          if (copyText(val)){
            copyBtn.textContent = 'Copiado';
            setTimeout(function(){ copyBtn.textContent = 'Copiar'; }, 1200);
            toast('Copiado');
          }
        }catch(e){}
      });
    }

    // Novo: qualquer botao/elemento com data-copy-text="..."
    qa('[data-copy-text]', overlay).forEach(function(btn){
      btn.addEventListener('click', function(){
        try{
          var val = btn.getAttribute('data-copy-text') || '';
          if (!val) return;
          if (copyText(val)){
            var oldTxt = btn.textContent;
            btn.textContent = 'Copiado';
            setTimeout(function(){ btn.textContent = oldTxt; }, 1200);
            toast('Copiado');
          }
        }catch(e){}
      });
    });

    // Melhorias visuais para o iframe do provedor (quando same-origin)
    try{
      var iframe = q('.yoda-qp-iframe', overlay);
      if (iframe){
        iframe.addEventListener('load', function(){
          try{
            var doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
            if (!doc) return;
            var style = doc.createElement('style');
            style.textContent = [
              'html,body{background:#fff!important}',
              'body{font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial!important}',
              'header,footer,#masthead,#colophon,.site-header,.site-footer,.elementor-location-header,.elementor-location-footer{display:none!important}',
              '.woocommerce-order, .woocommerce-notice{max-width:820px;margin:18px auto!important;padding:0 14px!important}',
              '.woocommerce-order-overview{background:#fff!important;border:1px solid rgba(15,16,32,.10)!important;border-radius:16px!important;padding:12px!important}'
            ].join('');
            doc.head && doc.head.appendChild(style);
          }catch(e){}
        });
      }
    }catch(e){}
  }

  function enhancePixModal(overlay, cfg){
    try{
      var head = q('.yoda-qp-head', overlay);
      if (head && !q('.yoda-qp-subtitle', head)){
        var title = q('.yoda-qp-title', head);
        if (title){
          var sub = document.createElement('p');
          sub.className = 'yoda-qp-subtitle';
          sub.textContent = 'Finalize o pagamento para liberar as moedas na conta informada.';
          title.insertAdjacentElement('afterend', sub);
        }
      }

      var body = q('.yoda-qp-body', overlay);
      if (body && !q('.yoda-qp-steps', body)){
        var steps = document.createElement('div');
        steps.className = 'yoda-qp-steps';
        steps.setAttribute('aria-label', 'Etapas');
        steps.innerHTML = '<span class=\"yoda-qp-step\" data-step=\"1\">1. Dados</span><span class=\"yoda-qp-step on\" data-step=\"2\">2. Pagar</span><span class=\"yoda-qp-step\" data-step=\"3\">3. Entrega</span>';
        body.insertBefore(steps, body.firstChild);
      }

      var pay = q('.yoda-qp-pay', overlay);
      if (!pay) return;

      // Organiza a tela em 2 colunas (resumo/status a esquerda, pagamento a direita).
      if (!q('.yoda-qp-paygrid', pay)){
        var summary = q('.yoda-qp-summary', pay);
        var user = q('[data-userbox]', pay);
        var live = q('[data-live]', pay);
        var hint = q('.yoda-qp-hint', pay);
        var qr = q('.yoda-qp-qr', pay);
        var code = q('.yoda-qp-code', pay);
        var iframe = q('.yoda-qp-iframe', pay);

        var grid = document.createElement('div');
        grid.className = 'yoda-qp-paygrid';
        var left = document.createElement('div');
        var right = document.createElement('div');

        if (summary) left.appendChild(summary);
        if (user) left.appendChild(user);

        if (live){
          var statusCard = document.createElement('div');
          statusCard.className = 'yoda-qp-card';
          statusCard.style.marginTop = '10px';
          statusCard.appendChild(live);

          var exp = formatExpires(cfg && cfg.expires ? cfg.expires : '');
          if (exp){
            var expEl = document.createElement('p');
            expEl.className = 'yoda-qp-hint';
            expEl.style.marginTop = '8px';
            expEl.innerHTML = 'Expira em: <strong>' + escapeHtml(exp) + '</strong>';
            statusCard.appendChild(expEl);
          }

          var tip = document.createElement('p');
          tip.className = 'yoda-qp-hint';
          tip.style.marginTop = '8px';
          tip.textContent = 'Após pagar, a entrega acontece automaticamente (pode levar alguns segundos).';
          statusCard.appendChild(tip);

          var proof = document.createElement('p');
          proof.className = 'yoda-qp-hint';
          proof.style.marginTop = '6px';
          proof.textContent = 'Não é necessário enviar o comprovante no WhatsApp, as moedas entram automaticamente em sua conta.';
          statusCard.appendChild(proof);
          left.appendChild(statusCard);
        }

        // botoes removidos por pedido

        var payCard = document.createElement('div');
        payCard.className = 'yoda-qp-card';
        if (!hint){
          hint = document.createElement('p');
          hint.className = 'yoda-qp-hint';
          hint.textContent = (qr || code)
            ? 'Abra o app do seu banco e escaneie o QR Code, ou copie e cole o codigo Pix.'
            : 'Use a pagina do provedor abaixo para concluir o pagamento.';
        }
        payCard.appendChild(hint);

        if (qr) payCard.appendChild(qr);
        if (code){
          // Deixa o botao mais claro e compativel com o novo copy handler.
          try{
            var inpt = q('input', code);
            var btn = q('button', code);
            if (btn && inpt && inpt.value){
              btn.textContent = 'Copiar codigo';
              btn.setAttribute('data-copy-text', inpt.value);
            }
          }catch(e){}
          payCard.appendChild(code);
        }

        if (iframe){
          var wrap = document.createElement('div');
          wrap.className = 'yoda-qp-iframewrap';
          wrap.appendChild(iframe);
          payCard.appendChild(wrap);
        }

        // Limpa o pay e remonta.
        while (pay.firstChild) pay.removeChild(pay.firstChild);
        right.appendChild(payCard);
        grid.appendChild(left);
        grid.appendChild(right);
        pay.appendChild(grid);
      }
    }catch(e){}
  }

  function startDeliveryPolling(overlay, cfg){
    try{
      var rest = (window.YodaQuickPix && YodaQuickPix.restQuickStatus) ? YodaQuickPix.restQuickStatus : '';
      if (!rest || !cfg.orderId || !cfg.orderKey) return;

      var tries = 0;
      var maxTries = 180; // ~15min se intervalo 5s
      var intervalMs = 5000;

      function tick(){
        tries++;
        if (tries > maxTries){
          try{
            var live = q('[data-live]', overlay);
            if (live) live.innerHTML = '<span class="yoda-qp-dot"></span><span>Pagamento ainda não confirmado. Você pode fechar e voltar depois.</span>';
          }catch(e){}
          clearInterval(overlay.__yodaInterval);
          overlay.__yodaInterval = null;
          return;
        }

        fetchJson(rest + '?order_id=' + encodeURIComponent(cfg.orderId) + '&key=' + encodeURIComponent(cfg.orderKey), {
          method: 'GET',
          credentials: 'same-origin'
        }).then(function(r){
          if (!(r && r.ok && r.data)) return;
          var delivered = !!r.data.delivered;
          var paid = (r.data.wc_status && r.data.wc_status !== 'pending');
          var live = q('[data-live]', overlay);
          if (delivered){
            if (live){
              live.innerHTML = '<span class="yoda-qp-dot done"></span><span>Entrega confirmada.</span>';
            }
            try{
              var s3 = q('[data-step=\"3\"]', overlay);
              if (s3) s3.classList.add('on');
            }catch(e){}
            var pay = q('.yoda-qp-pay', overlay);
            try{
              var paidBox = pay ? q('.yoda-qp-paid', pay) : null;
              if (paidBox) paidBox.remove();
            }catch(e){}
            if (pay && !q('.yoda-qp-delivered', pay)){
              var box = document.createElement('div');
              box.innerHTML = buildDeliveredBox({ orderRef: r.data.order_ref || '' });
              pay.insertBefore(box.firstElementChild, pay.firstChild);
              try{ window.dispatchEvent(new CustomEvent('yoda:delivery:delivered', { detail: r.data })); }catch(e){}
            }
            clearInterval(overlay.__yodaInterval);
            overlay.__yodaInterval = null;
          } else {
            if (live){
              var txt = 'Aguardando pagamento e entrega…';
              if (r.data.wc_status && r.data.wc_status !== 'pending') txt = 'Pagamento recebido, aguardando entrega…';
              live.innerHTML = '<span class="yoda-qp-dot"></span><span>' + escapeHtml(txt) + '</span>';
            }
            // Se o pagamento foi confirmado, mostra o CTA mesmo antes da entrega.
            if (paid){
              try{
                var pay = q('.yoda-qp-pay', overlay);
                if (pay && !q('.yoda-qp-paid', pay) && !q('.yoda-qp-delivered', pay)){
                  var box = document.createElement('div');
                  box.innerHTML = buildPaidBox();
                  pay.insertBefore(box.firstElementChild, pay.firstChild);
                  try{
                    var el = q('.yoda-qp-paid', pay);
                    if (el && el.scrollIntoView) el.scrollIntoView({ behavior:'smooth', block:'start' });
                  }catch(e){}
                }
              }catch(e){}
            }
          }

          // Ajustes visuais do status sem depender do texto do gateway.
          try{
            var dot = live ? q('.yoda-qp-dot', live) : null;
            if (dot){
              if (delivered){
                dot.classList.add('done');
                dot.classList.remove('ok');
              } else if (paid){
                dot.classList.add('ok');
              }
            }
            if (paid){
              var s2 = q('[data-step=\"2\"]', overlay);
              if (s2) s2.classList.add('on');
            }
          }catch(e){}

        }).catch(function(){});
      }

      overlay.__yodaInterval = setInterval(tick, intervalMs);
      tick();
    }catch(e){}
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
    var kakoUser = null;
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
        kakoUser = u;
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
        var pix = (r.data && r.data.pix) ? r.data.pix : {};
        overlay.innerHTML = buildPixModal({
          close: cfg.close,
          payUrl: payUrl,
          orderId: r.data.order_id,
          orderKey: r.data.order_key,
          total: r.data.total ? ('R$ ' + String(r.data.total)) : '',
          coins: (r.data.product && r.data.product.coins) ? String(r.data.product.coins) : (coins||''),
          kakoid: kakoId,
          name: name,
          avatar: (kakoUser && kakoUser.avatar) ? kakoUser.avatar : '',
          nickname: (kakoUser && kakoUser.nickname) ? kakoUser.nickname : '',
          qrBase64: pix.qr_base64 || '',
          qrCode: pix.qr_code || '',
          expires: pix.expires || ''
        });
        enhancePixModal(overlay, { payUrl: payUrl, expires: pix.expires || '' });
        bindModalCommon(overlay);
        startDeliveryPolling(overlay, { orderId: r.data.order_id, orderKey: r.data.order_key });
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
