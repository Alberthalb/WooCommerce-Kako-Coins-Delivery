(function($){
  // Helper: verifica cookie e destrava a UI caso já esteja verificado
  function ensureUnlockedFromCookie(){
    try{
      var m = document.cookie.match(/(?:^|; )yoda_kako_id=([^;]+)/);
      var hasId = !!(m && m[1]);
      if(!hasId) return;
      var $grid = $('#yoda-packs-grid');
      if(!$grid.length) return;
      if($grid.attr('data-verified') === '1') return;
      $grid.attr('data-verified','1');
      $grid.find('.yoda-card').removeClass('locked').each(function(){
        var $c = $(this);
        $c.find('.yoda-lock').remove();
        $c.find('.yoda-price').css('opacity', 1);
        var pid = $c.data('pid');
        $c.find('.yoda-cta').removeAttr('disabled').attr('href', '?add-to-cart='+pid).text('Comprar');
      });
    }catch(e){}
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
        // destrava grid
        var $grid = $('#yoda-packs-grid');
        $grid.attr('data-verified', '1');
        $grid.find('.yoda-card').removeClass('locked').each(function(){
          var $c = $(this);
          $c.find('.yoda-lock').remove();
          // revela preço e ativa CTA
          $c.find('.yoda-price').css('opacity', 1);
          var pid = $c.data('pid');
          $c.find('.yoda-cta').removeAttr('disabled').attr('href', '?add-to-cart='+pid).text('Comprar');
        });
      }else{
        var m = (r && r.data && r.data.msg) ? r.data.msg : YodaPacks.texts.fail;
        $msg.text(m);
        // Se o servidor sinalizou pendência (retentativa em segundo plano), faz polling do cookie
        if (r && r.data && r.data.pending){
          var tries = 0;
          var iv = setInterval(function(){
            tries++;
            ensureUnlockedFromCookie();
            var hasId = /(?:^|; )yoda_kako_id=/.test(document.cookie);
            if (hasId || tries>15){ clearInterval(iv); }
          }, 1200);
        }
      }
    }).fail(function(){
      $msg.text('Falha de comunicação. Tente novamente.');
    }).always(function(){
      $btn.prop('disabled', false).text('Confirmar Conta');
    });
  });

  // Quando a página carrega (e.g., retornando do checkout), garanta o estado destravado
  $(function(){ ensureUnlockedFromCookie(); });
})(jQuery);
