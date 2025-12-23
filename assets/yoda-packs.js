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
      $c.find('.yoda-cta').removeAttr('disabled').attr('href', '?add-to-cart='+pid).text('Comprar');
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

  $(function(){ ensureUnlockedFromCookie(); });

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
          $c.find('.yoda-price').css('opacity', 0);
          $c.find('.yoda-cta').attr('disabled','disabled').attr('href','javascript:void(0)').text('Verificar ID');
        });
      }catch(e){}
    });
  }catch(e){}
})(jQuery);
