import $ from 'jquery';

$.entwine('ss', function($){
  $('.docs-shadow-root a').entwine({
    onclick: function(e) {
      console.log('WOAH');
    }
  });
});
