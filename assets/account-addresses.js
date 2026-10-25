/* My Account address book. Uses the checkout plugin's existing
   authenticated address AJAX endpoints, nonce, and user_meta records. */
(function ($) {
  'use strict';
  $(function () {
    var cfg = window.dcAccountBook || {};
    var $root = $('#dc-account-book');
    if (!$root.length || !cfg.ajaxUrl || !cfg.nonce) return;
    var all = (cfg.addresses || []).slice();
    var primary = cfg.defaultAddress || {};
    var current = null;
    var deleteId = '';
    var working = false;
    var $dialog = $root.find('.dc-account-book-dialog');
    var $confirm = $root.find('.dc-book-confirm');
    var $form = $('#dc-book-address-form');
    var $error = $root.find('.dc-book-form-error');
    var $notice = $root.find('.dc-account-book-notice');
    var $submit = $root.find('.dc-book-submit');
    var labels = ['billing_first_name','billing_last_name','billing_phone','billing_state',
      'billing_city','billing_address_1','billing_address_4','billing_address_3','billing_postcode',
      'recipient_type','recipient_first_name','recipient_last_name','recipient_phone','label'];

    function find(id) {
      if (id === 'default') return primary;
      for (var i = 0; i < all.length; i++) if (String(all[i].id) === id) return all[i];
      return null;
    }
    function field(name) { return $form.find('[name="' + name + '"]'); }
    function val(name) { return $.trim(String(field(name).val() || '')); }
    function set(name, value) { field(name).val(value == null ? '' : String(value)); }
    function showError(text) { $error.text(text || ''); }
    function busy(next) {
      working = !!next;
      $form.find('input,textarea,select,button').prop('disabled', working);
      $submit.text(working ? 'در حال ذخیره…' : 'ذخیره نشانی');
    }
    function updateRecipient() {
      var other = val('recipient_type') === 'other';
      $form.find('.dc-book-recipient-fields').prop('hidden', !other);
      $form.find('.dc-book-recipient-fields input').prop('required', other);
    }
    function openDialog(item) {
      if (working) return;
      current = item || null;
      $form[0].reset();
      labels.forEach(function (name) { set(name, current ? (current[name] || '') : ''); });
      if (!current) {
        set('label', '');
        set('billing_first_name', primary.billing_first_name || cfg.fallbackName.first || '');
        set('billing_last_name', primary.billing_last_name || cfg.fallbackName.last || '');
        set('billing_phone', primary.billing_phone || cfg.fallbackName.phone || '');
        set('recipient_type', 'self');
      }
      var showPerson = !!current || !val('billing_first_name') || !val('billing_last_name');
      $form.find('.dc-book-person-fields').prop('hidden', !showPerson);
      updateRecipient();
      $dialog.find('#dc-book-dialog-title').text(current ? 'ویرایش نشانی' : 'افزودن نشانی جدید');
      showError('');
      $dialog.prop('hidden', false);
      $('body').addClass('dc-account-book-locked');
      field('label').trigger('focus');
    }
    function closeDialog() {
      if (working) return;
      $dialog.prop('hidden', true);
      $('body').removeClass('dc-account-book-locked');
      current = null;
    }
    function send(action, payload, onSuccess, onError) {
      var req = $.extend({ action: action, nonce: cfg.nonce }, payload || {});
      return $.ajax({ url: cfg.ajaxUrl, method: 'POST', dataType: 'json', data: req })
        .done(function (response) {
          if (response && response.success) onSuccess(response.data || {});
          else {
            var msg = response && response.data && response.data.message ? response.data.message : 'عملیات انجام نشد. دوباره تلاش کنید.';
            onError(msg);
          }
        }).fail(function (xhr) {
          var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
          onError(msg || 'اتصال برقرار نشد. دوباره تلاش کنید.');
        });
    }

    $root.on('click', '.dc-account-book-add', function () {
      if ($(this).prop('disabled')) return;
      openDialog(null);
    });
    $root.on('click', '.dc-book-edit', function () {
      var id = String($(this).closest('.dc-account-book-card').data('id') || '');
      var item = find(id);
      if (item && id !== 'default') openDialog(item);
    });
    $root.on('click', '.dc-book-close, .dc-book-dialog-backdrop', function () {
      if ($(this).closest('.dc-book-confirm').length) return;
      closeDialog();
    });
    field('recipient_type').on('change', updateRecipient);
    $form.on('submit', function (event) {
      event.preventDefault();
      if (working) return;
      var address = {};
      labels.forEach(function (name) { address[name] = val(name); });
      address.id = current && current.id ? current.id : '';
      address.billing_country = current && current.billing_country ? current.billing_country : 'IR';
      address.billing_map_lat_long = current && current.billing_map_lat_long ? current.billing_map_lat_long : '';
      if (!address.billing_first_name || !address.billing_last_name) {
        $form.find('.dc-book-person-fields').prop('hidden', false);
        showError('نام و نام خانوادگی حساب شما ثبت نشده است؛ لطفاً آن‌ها را کامل کنید.');
        return;
      }
      if (!address.billing_state || !address.billing_city || !address.billing_address_1 ||
          !address.billing_address_4 || !address.billing_address_3) {
        showError('استان، شهر، نشانی، پلاک و واحد را کامل کنید.');
        return;
      }
      if (address.recipient_type === 'other' && (!address.recipient_first_name || !address.recipient_last_name || !address.recipient_phone)) {
        showError('نام، نام خانوادگی و موبایل تحویل‌گیرنده را کامل کنید.');
        return;
      }
      busy(true); showError('');
      send('dcashop_address_save', { address: address }, function () {
        // Server already saved to the SAME user_meta record used by checkout.
        window.location.reload();
      }, function (msg) {
        busy(false); showError(msg);
      });
    });
    $root.on('click', '.dc-book-select', function () {
      var $button = $(this), id = String($button.closest('.dc-account-book-card').data('id') || '');
      if (!id || working) return;
      working = true;
      $button.prop('disabled', true).text('در حال انتخاب…');
      send('dcashop_address_select', { id: id }, function () {
        window.location.reload();
      }, function (msg) {
        working = false;
        $button.prop('disabled', false).text('انتخاب برای خرید');
        $notice.text(msg);
      });
    });
    $root.on('click', '.dc-book-delete', function () {
      deleteId = String($(this).closest('.dc-account-book-card').data('id') || '');
      if (!deleteId || working) return;
      $confirm.find('.dc-book-confirm-error').text('');
      $confirm.prop('hidden', false);
      $('body').addClass('dc-account-book-locked');
      $confirm.find('.dc-book-confirm-cancel').trigger('focus');
    });
    $root.on('click', '.dc-book-confirm-cancel, .dc-book-confirm .dc-book-dialog-backdrop', function () {
      if (working) return;
      $confirm.prop('hidden', true);
      $('body').removeClass('dc-account-book-locked');
      deleteId = '';
    });
    $root.on('click', '.dc-book-confirm-delete', function () {
      if (!deleteId || working) return;
      working = true;
      var $button = $(this);
      $button.text('در حال حذف…').prop('disabled', true);
      send('dcashop_address_delete', { id: deleteId }, function () {
        window.location.reload();
      }, function (msg) {
        working = false;
        $button.text('حذف نشانی').prop('disabled', false);
        $confirm.find('.dc-book-confirm-error').text(msg);
      });
    });
    $(document).on('keydown.dcAccountBook', function (e) {
      if (e.key === 'Escape' && !working) {
        if (!$confirm.prop('hidden')) $root.find('.dc-book-confirm-cancel').trigger('click');
        else if (!$dialog.prop('hidden')) closeDialog();
      }
    });
  });
})(jQuery);
