
$(function(){

  $("input[type=url]").on("blur", function(){
    if(!$(this).val().match(/^https?:/)) {
      $(this).val("http://"+$(this).val());
    }
  });

});
