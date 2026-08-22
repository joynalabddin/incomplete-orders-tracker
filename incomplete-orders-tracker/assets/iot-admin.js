(function($){
  'use strict';

  var config = window.iotAdmin || {};

  function responseMessage(response, fallback){
    if(response && response.data){
      if(typeof response.data === 'string') return response.data;
      if(response.data.message) return response.data.message;
    }
    return fallback;
  }

  function notify(message, type){
    var $toast = $('#iot-admin-toast');
    if(!$toast.length) $toast = $('<div id="iot-admin-toast" class="iot-toast" role="status" aria-live="polite"></div>').appendTo('body');
    $toast.removeClass('is-success is-error is-visible').addClass(type === 'error' ? 'is-error' : 'is-success').text(message || '').addClass('is-visible');
    window.clearTimeout(notify.timer);
    notify.timer = window.setTimeout(function(){ $toast.removeClass('is-visible'); }, 4200);
  }

  function updateCounters(incompleteDelta, completedDelta){
    var $incomplete = $('#iot-incomplete-count');
    var $completed = $('#iot-completed-count');
    if($incomplete.length) $incomplete.text(Math.max(0, (parseInt($incomplete.text(), 10) || 0) + incompleteDelta));
    if($completed.length) $completed.text(Math.max(0, (parseInt($completed.text(), 10) || 0) + completedDelta));
  }

  function ensureEmptyState(){
    var $tbody = $('.iot-table tbody');
    if($tbody.length && !$tbody.find('tr').length){
      $tbody.html('<tr><td class="iot-empty" colspan="8">No records found.</td></tr>');
    }
  }

  $(function(){
    var $form = $('#iot-settings-form');
    var nativeSettingsSubmit = false;

    $form.on('input change', ':input', function(){
      $('#iot-settings-save-status').text('Unsaved changes.');
    });

    $form.on('submit', function(event){
      if(nativeSettingsSubmit || !config.ajax_url || !config.nonce) return;
      event.preventDefault();

      var formElement = this;
      var $button = $('#iot-save-settings');
      var originalText = $button.text();
      $button.text('Saving…').prop('disabled', true);
      $('#iot-settings-save-status').text('Saving settings…');

      var data = {
        action: 'iot_save_settings',
        nonce: config.nonce,
        site_name: $form.find('[name="site_name"]').val(),
        site_url: $form.find('[name="site_url"]').val(),
        default_country_code: $form.find('[name="default_country_code"]').val(),
        capture_delay: $form.find('[name="capture_delay"]').val(),
        match_window_days: $form.find('[name="match_window_days"]').val(),
        retention_days: $form.find('[name="retention_days"]').val(),
        whatsapp_template: $form.find('[name="whatsapp_template"]').val(),
        email_subject: $form.find('[name="email_subject"]').val(),
        email_body: $form.find('[name="email_body"]').val()
      };

      $.ajax({url: config.ajax_url, method: 'POST', dataType: 'json', data: data})
        .done(function(response){
          if(response && response.success){
            var message = responseMessage(response, 'Settings saved successfully.');
            $('#iot-settings-save-status').text(message);
            notify(message, 'success');
            return;
          }
          var message = responseMessage(response, 'Settings could not be saved.');
          $('#iot-settings-save-status').text(message);
          notify(message, 'error');
        })
        .fail(function(xhr){
          var response = xhr && xhr.responseJSON;
          if(response && response.data && response.data.message){
            var message = response.data.message;
            $('#iot-settings-save-status').text(message);
            notify(message, 'error');
            return;
          }
          $('#iot-settings-save-status').text('Trying the safe save method…');
          nativeSettingsSubmit = true;
          window.setTimeout(function(){ formElement.submit(); }, 120);
        })
        .always(function(){ $button.text(originalText).prop('disabled', false); });
    });

    $(document).on('click', '.iot-mark-complete', function(event){
      event.preventDefault();
      var $button = $(this);
      var id = parseInt($button.data('id'), 10);
      if(!id || !window.confirm('Mark this incomplete order as completed?')) return;

      var originalHtml = $button.html();
      $button.html('<span class="iot-spinner" aria-hidden="true"></span>').prop('disabled', true);

      $.ajax({
        url: config.ajax_url,
        method: 'POST',
        dataType: 'json',
        data: {action: 'iot_mark_complete', nonce: config.nonce, id: id}
      }).done(function(response){
        if(response && response.success){
          var $row = $button.closest('tr');
          $row.find('.iot-badge-status').removeClass('iot-incomplete').addClass('iot-complete').text('Completed');
          $button.remove();
          updateCounters(-1, 1);
          notify('Order marked as completed.', 'success');
          return;
        }
        $button.html(originalHtml).prop('disabled', false);
        notify(responseMessage(response, 'The order could not be updated.'), 'error');
      }).fail(function(xhr){
        $button.html(originalHtml).prop('disabled', false);
        notify(responseMessage(xhr && xhr.responseJSON, 'The order could not be updated.'), 'error');
      });
    });

    $(document).on('click', '.iot-delete', function(event){
      event.preventDefault();
      var $button = $(this);
      var id = parseInt($button.data('id'), 10);
      if(!id || !window.confirm('Permanently delete this incomplete order?')) return;

      var $row = $button.closest('tr').addClass('is-busy');
      var wasIncomplete = $row.find('.iot-badge-status').hasClass('iot-incomplete');
      $.ajax({
        url: config.ajax_url,
        method: 'POST',
        dataType: 'json',
        data: {action: 'iot_delete_entry', nonce: config.nonce, id: id}
      }).done(function(response){
        if(response && response.success){
          $row.fadeOut(220, function(){ $(this).remove(); ensureEmptyState(); });
          updateCounters(wasIncomplete ? -1 : 0, wasIncomplete ? 0 : -1);
          notify('Entry deleted successfully.', 'success');
          return;
        }
        $row.removeClass('is-busy');
        notify(responseMessage(response, 'The entry could not be deleted.'), 'error');
      }).fail(function(xhr){
        $row.removeClass('is-busy');
        notify(responseMessage(xhr && xhr.responseJSON, 'The entry could not be deleted.'), 'error');
      });
    });
  });

})(jQuery);
