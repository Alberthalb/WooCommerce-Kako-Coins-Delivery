(function($){
  function getCookieKakoId(){
    try{
      var m = document.cookie.match(/(?:^|; )yoda_kako_id=([^;]+)/);
      return m ? decodeURIComponent(m[1] || '') : '';
    }catch(e){ return ''; }
  }

  function ensureUnlockedFromCookie(){
    var hasId = !!getCookieKakoId();
    if(!hasId) return false;
    var $grid = $('#yoda-packs-grid');
    if(!$grid.length) return true;
    if($grid.attr('data-verified') === '1') return true;
    unlockGrid();
    return true;
  }

  function unlockGrid(){
    var $grid = $('#yoda-packs-grid');
    $grid.attr('data-verified', '1');
    $grid.find('.yoda-card').removeClass('locked').each(function(){
      var $c = $(this);
      $c.find('.yoda-lock').remove();
      $c.find('.yoda-price').css('opacity', 1);
      var pid = $c.data('pid');
      var priceTxt = $.trim($c.find('.yoda-price').text() || '');
      $c.find('.yoda-cta')
        .removeAttr('disabled')
        .attr('href', '#yoda-quick-pix')
        .attr('data-yoda-buy', '1')
        .attr('data-product-id', pid)
        .attr('data-price', priceTxt)
        .text('Recarregar');
    });
    try{ window.dispatchEvent(new CustomEvent('yoda:id:verified')); }catch(e){}
  }

  function pollVerifyStatus(kakoId, onSuccess){
    var tries = 0;
    var inFlight = false;
    var iv = setInterval(function(){
      tries++;
      ensureUnlockedFromCookie();
      if (tries > 15){ clearInterval(iv); return; }
      if (inFlight) return;
      inFlight = true;
      $.post(YodaPacks.ajax, {
        action: 'yoda_verify_kako_status',
        nonce: YodaPacks.nonce,
        kakoid: kakoId
      }).done(function(r){
        if(r && r.success){
          onSuccess(r);
          clearInterval(iv);
        }
      }).always(function(){
        inFlight = false;
      });
    }, 1200);
  }

  $(document).on('submit', '#yoda-verify-form', function(e){
    e.preventDefault();
    var $form = $(this);
    var $btn  = $form.find('button');
    var $msg  = $('#yoda-verify-msg');
    var kakoId = $.trim($('#yoda-kakoid').val());

    if(!kakoId){
      $msg.text('Informe seu ID do Kako.');
      return;
    }

    $btn.prop('disabled', true).text(YodaPacks.texts.checking);
    $msg.text('');

    $.post(YodaPacks.ajax, {
      action: 'yoda_verify_kako',
      nonce: YodaPacks.nonce,
      kakoid: kakoId
    }).done(function(r){
      if(r && r.success){
        $msg.text(YodaPacks.texts.ok);
        unlockGrid();
      }else{
        var m = (r && r.data && r.data.msg) ? r.data.msg : YodaPacks.texts.fail;
        $msg.text(m);
        if (r && r.data && r.data.pending){
          pollVerifyStatus(kakoId, function(){
            $msg.text(YodaPacks.texts.ok);
            unlockGrid();
          });
        }
      }
    }).fail(function(){
      $msg.text('Falha de comunicação. Tente novamente.');
    }).always(function(){
      $btn.prop('disabled', false).text('Confirmar Conta');
    });
  });

  function isStandalone(){
    try{
      if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
    }catch(e){}
    try{
      if (window.navigator && window.navigator.standalone) return true; // iOS
    }catch(e){}
    return false;
  }

  function isIOS(){
    try{
      var ua = navigator.userAgent || '';
      if (/iPad|iPhone|iPod/i.test(ua)) return true;
      // iPadOS (Safari) pode se identificar como Mac
      if ((navigator.platform || '') === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1) return true;
    }catch(e){}
    return false;
  }

  function isMobile(){
    try{
      var ua = navigator.userAgent || '';
      if (/Android|iPhone|iPad|iPod/i.test(ua)) return true;
    }catch(e){}
    try{
      if (window.matchMedia && window.matchMedia('(max-width: 820px)').matches) return true;
    }catch(e){}
    return false;
  }

  function initA2HS(){
    var $bar = $('#yoda-a2hs');
    if (!$bar.length) return;

    try{
      var msg = (YodaPacks && YodaPacks.a2hs && YodaPacks.a2hs.msg) ? String(YodaPacks.a2hs.msg) : '';
      var btn = (YodaPacks && YodaPacks.a2hs && YodaPacks.a2hs.btn) ? String(YodaPacks.a2hs.btn) : '';
      if (msg) $bar.find('[data-yoda-a2hs-msg]').text(msg);
      if (btn) $bar.find('[data-yoda-a2hs]').text(btn);
    }catch(e){}

    if (!isMobile() || isStandalone()){
      $bar.hide();
      return;
    }

    var deferredPrompt = null;
    var $tip = $bar.find('[data-yoda-a2hs-tip]');

    function showTip(text){
      if (!$tip.length) return;
      $tip.text(text || '').toggle(!!text);
      if (text){
        try{ clearTimeout($tip.data('t')); }catch(e){}
        var t = setTimeout(function(){ try{ $tip.hide(); }catch(e){} }, 8000);
        try{ $tip.data('t', t); }catch(e){}
      }
    }

    function showBar(){
      try{ $bar.show(); }catch(e){}
    }

    window.addEventListener('beforeinstallprompt', function(e){
      try{ e.preventDefault(); }catch(err){}
      deferredPrompt = e;
      showBar();
    });

    window.addEventListener('appinstalled', function(){
      deferredPrompt = null;
      try{ $bar.hide(); }catch(e){}
    });

    // Mostra por padrão no mobile (iOS não dispara beforeinstallprompt)
    showBar();

    $bar.on('click', '[data-yoda-a2hs]', function(){
      if (deferredPrompt && deferredPrompt.prompt){
        try{
          deferredPrompt.prompt();
          deferredPrompt.userChoice && deferredPrompt.userChoice.finally && deferredPrompt.userChoice.finally(function(){
            deferredPrompt = null;
          });
        }catch(e){}
        return;
      }

      var tip = '';
      try{
        if (isIOS()){
          tip = (YodaPacks && YodaPacks.a2hs && YodaPacks.a2hs.tip_ios) ? String(YodaPacks.a2hs.tip_ios) : '';
        } else {
          tip = (YodaPacks && YodaPacks.a2hs && YodaPacks.a2hs.tip_other) ? String(YodaPacks.a2hs.tip_other) : '';
        }
      }catch(e){ tip = ''; }
      showTip(tip || 'Abra o menu do navegador e adicione à tela inicial.');
    });
  }

  $(function(){
    ensureUnlockedFromCookie();
    initA2HS();
  });

  // Se o usuário verificar via [yoda_kako_card], destrava os packs em tempo real.
  try{
    window.addEventListener('yoda:id:verified', function(){ ensureUnlockedFromCookie(); });
    window.addEventListener('yoda:id:cleared', function(){
      try{
        var $grid = $('#yoda-packs-grid');
        if(!$grid.length) return;
        $grid.attr('data-verified','0');
        $grid.find('.yoda-card').addClass('locked').each(function(){
          var $c = $(this);
          if (!$c.find('.yoda-lock').length) $c.prepend('<span class="yoda-lock">&#128274;</span>');
          $c.find('.yoda-price').css('opacity', .25);
          $c.find('.yoda-cta').attr('disabled','disabled').attr('href','javascript:void(0)').text('Verificar ID');
        });
      }catch(e){}
    });
  }catch(e){}
})(jQuery);
