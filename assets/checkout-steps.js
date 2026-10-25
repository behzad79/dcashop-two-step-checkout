/* global dcShopSteps, jQuery, wc_checkout_params */
(function ($) {
  'use strict';
  $(function () {
    if (typeof dcShopSteps === 'undefined') return;
    var cfg = dcShopSteps;
    var $form = $('form.checkout.woocommerce-checkout').first();
    var $customer = $form.find('#customer_details').first();
    var $billing = $customer.find('.woocommerce-billing-fields').first();
    var $review = $form.find('#order_review').first();
    var $customerCol = $customer.closest('.large-7.col');
    var $reviewCol = $review.closest('.large-5.col');
    if (!$form.length || !$customer.length || !$billing.length || !$review.length || !$customerCol.length || !$reviewCol.length || $customerCol[0] === $reviewCol[0]) return;

    var words = cfg.text || {}, $body = $('body'), page = 'shipping';
    var useUttamap = !!cfg.uttamap;
    // Show the exact same viewport mask while leaving the page for the other
    // checkout step. Do this only after confirmation, never on validation errors.
    function navigateTo(url) {
      if (!url) return;
      $body.addClass('dc-checkout-transitioning');
      window.location.assign(url);
    }

    var modalStageName = '', editorId = '', beforeEdit = null, busy = false, shippingBusy = false, refreshTimer = null;
    var addresses = Array.isArray(cfg.addresses) ? cfg.addresses.slice() : [];
    var primary = cfg.defaultAddress || null, selectedId = cfg.selected || '';
    var editRecipient = { recipient_type: 'self', recipient_first_name: '', recipient_last_name: '', recipient_phone: '' };
    var fields = ['billing_first_name','billing_last_name','billing_phone','billing_country','billing_state','billing_city','billing_address_1','billing_address_4','billing_address_3','billing_postcode','billing_map_lat_long'];
    var $status = $('<div class="dc-step-status" role="alert" aria-live="polite"></div>');
    var $next = $('<button type="button" class="button primary dc-next-step"></button>').text(words.next || 'ادامه فرآیند خرید');
    var $controls = $('<div class="dc-step-controls"></div>').append($status, $next);
    var $back = $('<button type="button" class="dc-go-back">آدرس ارسال</button>');
    var $addressBlock = $('<section class="dc-address-block" aria-label="آدرس ارسال"><div class="dc-address-heading"><h2>آدرس ارسال</h2><button type="button" class="dc-address-change">ویرایش آدرس</button></div><p class="dc-address-current"></p></section>');
    var $current = $addressBlock.find('.dc-address-current');
    var $shipTitle = $('<h2 class="dc-shipping-title">روش ارسال</h2>');
    var $fixed = $('<div class="dc-mobile-fixed-actions"><div class="dc-fixed-total"><small>جمع سبد خرید</small><strong></strong></div><button type="button" class="dc-mobile-primary"></button></div>');
    var $mobileNav = $('<nav class="dc-mobile-checkout-nav" aria-label="بازگشت به مرحله قبل"><button type="button" class="dc-mobile-nav-back" aria-label="بازگشت به صفحه اصلی"><svg class="dc-nav-arrow-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M5 12h14m-7-7 7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button><span class="dc-mobile-nav-text">سبد خرید</span></nav>');

    // The original checkout/review form, billing fields, shipping table and #payment remain mounted.
    // WooCommerce can continue to refresh its fragments and receive the original submit event.
    var $orderTable = $review.find('.woocommerce-checkout-review-order-table').first();
    var $summary = $('<aside class="dc-summary-panel"><h2>جزئیات سفارش</h2></aside>');
    $orderTable.appendTo($summary);
    var $coupon = $('<section class="dc-coupon-panel"><button type="button" class="dc-coupon-toggle" aria-expanded="false"><span class="dc-coupon-title">کد تخفیف</span><span class="dc-coupon-switch" aria-hidden="true"><span class="dc-coupon-knob"></span></span></button><div class="dc-coupon-body"><div class="dc-coupon-inline"><input type="text" class="dc-coupon-input" autocomplete="off" placeholder="کد تخفیف"><button type="button" class="dc-coupon-apply">اعمال کد</button></div><p class="dc-coupon-feedback" role="status" aria-live="polite"></p></div></section>');
    var $wallet = $('<div class="dc-wallet-panel"><table class="shop_table"><tbody></tbody></table></div>');
    var $paymentBox = $('<section class="dc-payment-main"><h2>روش پرداخت</h2></section>');
    $paymentBox.prepend($coupon); // Above the payment method title; stays mounted through checkout refreshes.
    $paymentBox.append($review.find('#payment').first());
    $review.prepend($paymentBox, $summary);
    var $cashback = $('<div class="dc-cashback-note" aria-live="polite"></div>');
    // Keep coupon/wallet inputs attached to the real checkout; park them outside
    // the AJAX-replaced table briefly so WooCommerce does not delete handlers.
    var $parking = $('<div class="dc-summary-parking" aria-hidden="true"></div>');
    $parking.append($wallet);
    var $payBelowSummary = $('<button type="button" class="dc-pay-below-summary">پرداخت و ثبت نهایی</button>');
    $summary.append($parking, $cashback, $controls, $payBelowSummary);
    $reviewCol.addClass('dc-payment-step-col');
    $customerCol.addClass('dc-customer-step-col');
    $customer.prepend($addressBlock);
    $customer.find('.my-custom-shipping-table').first().before($shipTitle);
    $reviewCol.prepend($back);
    $form.before($mobileNav, $('<div class="dc-mobile-nav-spacer" aria-hidden="true"></div>'));
    $body.append($fixed);
    $('.woocommerce-form-coupon-toggle,form.checkout_coupon').addClass('dc-coupon-outside');
    if (!cfg.couponsEnabled) $coupon.addClass('dc-coupon-disabled').hide();

    // Keep the titles consistent with YITH and with our mandatory address data.
    [['billing_address_4','پلاک'],['billing_address_3','واحد']].forEach(function(pair){
      var $label = $billing.find('#'+pair[0]+'_field > label').first();
      if (!$label.length) return;
      $label.text(pair[1]+' ').append($('<abbr class="required" title="الزامی">*</abbr>'));
    });
    function node(name) { return $form.find('[name="' + name + '"]').first(); }
    function get(name) { return String(node(name).val() || '').trim(); }
    function read() { var d = {}; fields.forEach(function (name) { d[name] = get(name); }); return d; }
    function setField(name, value) {
      var $n = node(name);
      if (!$n.length) return;
      $n.val(value == null ? '' : String(value));
      // A namespaced change only repaints Select2; it does NOT notify WooCommerce
      // country-to-state and Iranian city plugins. Dispatch the real change event.
      if ($n.is('select')) $n.trigger('change');
    }
    function clearAddress() {
      ['billing_state','billing_city','billing_address_1','billing_address_4','billing_address_3','billing_postcode','billing_map_lat_long'].forEach(function (key) { setField(key, ''); });
      if (!get('billing_country')) setField('billing_country', 'IR');
    }
    function apply(address) {
      if (!address) return;
      fields.forEach(function (name) { if (Object.prototype.hasOwnProperty.call(address, name)) setField(name, address[name]); });
      $(document.body).trigger('update_checkout');
      paintCurrent();
    }
    function addressById(id) {
      if (id === 'default') return primary;
      return addresses.filter(function (a) { return a && a.id === id; })[0] || null;
    }
    function readable(d) {
      if (!d) return '';
      var state = String(d.billing_state || '').trim(), city = String(d.billing_city || '').trim();
      var $o = node('billing_state').find('option').filter(function () { return this.value === state; }).first();
      if ($o.length) state = $o.text().trim();
      var street = String(d.billing_address_1 || '').trim();
      var norm = function (s) { return String(s || '').replace(/[،,]/g, ' ').replace(/\s+/g,' ').trim(); };
      var seen = norm(street), parts = [];
      if (state && seen.indexOf(norm(state)) !== 0) parts.push(state);
      var hasCity = city && (seen.indexOf(norm(city)) !== -1 || norm(street).indexOf(norm(state) + ' ' + norm(city)) === 0);
      if (city && !hasCity) parts.push(city);
      if (street) parts.push(street);
      if (d.billing_address_4) parts.push('پلاک '+String(d.billing_address_4).trim());
      if (d.billing_address_3) parts.push('واحد '+String(d.billing_address_3).trim());
      return parts.join('، ');
    }
    function ajax(action, data) {
      return $.ajax({url:cfg.ajaxUrl, method:'POST', dataType:'json', data:$.extend({action:action,nonce:cfg.nonce},data||{})});
    }
    function message(text, error) {
      $status.text(text || '').toggleClass('dc-has-error',!!error);
      $error.text(text || '').toggleClass('dc-has-error',!!error);
    }
    function clearMessage() { message('',false); }
    function setBusy(v) { busy=!!v; $modalFooter.find('button').prop('disabled', busy); }

    // Keep the real WooCommerce and YITH checkout fields in their original billing block.
    var $shade = $('<div class="dc-modal-shade" aria-hidden="true"></div>');
    var $header = $('<div class="dc-modal-header"><button type="button" class="dc-modal-close" aria-label="بستن">×</button><h2 class="dc-modal-title">افزودن آدرس</h2></div>');
    var $error = $('<p class="dc-modal-error" role="alert" aria-live="polite"></p>');
    var $intro = $('<p class="dc-modal-help dc-identity-help">نام و نام خانوادگی خود را وارد کنید.</p>');
    var $mapHint = $('<p class="dc-modal-help dc-map-hint">موقعیت خود را روی نقشه انتخاب کنید یا آدرس را دستی ثبت کنید.</p>');
    var $detailsHint = $('<p class="dc-modal-help dc-details-hint">استان، شهر، نشانی، پلاک و واحد را تکمیل کنید.</p>');
    var $confirm = $('<section class="dc-address-confirm">' +
      '<div class="dc-confirm-map-heading">موقعیت انتخابی</div><div class="dc-confirm-location"></div>' +
      '<div class="dc-confirm-label"><label for="dc-address-label-input">عنوان آدرس</label><input id="dc-address-label-input" type="text" maxlength="60" autocomplete="off" placeholder="مثلاً خانه، محل کار و ..."></div>' +
      '<div class="dc-confirm-recipient"><strong>سفارش‌های این آدرس را چه کسی تحویل می‌گیرد؟</strong>' +
      '<div class="dc-recipient-options"><label><input type="radio" name="dc_recipient_type" value="self" checked> تحویل به خودم</label>' +
      '<label><input type="radio" name="dc_recipient_type" value="other"> تحویل به شخص دیگر</label></div>' +
      '<p class="dc-recipient-self-note"></p><div class="dc-recipient-other-fields">' +
      '<label>نام <input type="text" class="dc-recipient-first" autocomplete="off"></label>' +
      '<label>نام خانوادگی <input type="text" class="dc-recipient-last" autocomplete="off"></label>' +
      '<label class="dc-recipient-phone-wrap">شماره موبایل <input type="tel" class="dc-recipient-phone" autocomplete="off" inputmode="tel"></label>' +
      '</div></div></section>');
    var $modalFooter = $('<div class="dc-modal-footer"><button type="button" class="dc-modal-main">ادامه</button><button type="button" class="dc-modal-back">بازگشت</button><button type="button" class="dc-modal-manual">ثبت آدرس دستی</button></div>');
    $body.append($shade);
    $billing.prepend($header,$intro,$mapHint,$detailsHint,$error);
    $billing.append($confirm,$modalFooter);
    var $picker = $('<section class="dc-address-picker" role="dialog" aria-modal="true" aria-label="آدرس‌های من"><div class="dc-picker-header"><h2>آدرس‌های من</h2><button type="button" class="dc-picker-close" aria-label="بستن">×</button></div><div class="dc-picker-list"></div><div class="dc-picker-footer"><button type="button" class="dc-picker-new">+ افزودن آدرس جدید</button></div></section>');
    var $deleteModal = $('<section class="dc-delete-modal" role="dialog" aria-modal="true" aria-label="حذف آدرس"><div class="dc-delete-modal-card"><div class="dc-delete-icon">🗑️</div><h3>حذف آدرس</h3><p class="dc-delete-message">آیا از حذف این آدرس مطمئن هستید؟ این عمل قابل بازگشت نیست.</p><div class="dc-delete-actions"><button type="button" class="dc-delete-confirm">حذف آدرس</button><button type="button" class="dc-delete-cancel">انصراف</button></div></div></section>');
    var deleteTarget = null;
    $body.append($picker, $deleteModal);
    $confirm.find('[name="dc_recipient_type"]').on('change', function () { $confirm.find('.dc-confirm-recipient').toggleClass('dc-recipient-other',this.value==='other'); });

    function paintCurrent() {
      var d = read(), has = !!d.billing_address_1;
      $current.text(has ? readable(d) : 'هنوز آدرس ارسال ثبت نشده است.');
      $current.toggleClass('dc-no-address',!has);
      $addressBlock.find('.dc-address-change').text(has ? 'ویرایش آدرس' : 'افزودن آدرس');
    }
    function paintPicker() {
      $picker.find('.dc-picker-limit-notice').remove();
      var $list = $picker.find('.dc-picker-list').empty(), list = [];
      if (primary && primary.billing_address_1) list.push(primary);
      addresses.forEach(function (a) { if (a && a.billing_address_1) list.push(a); });
      if (!list.length) $list.append($('<p class="dc-picker-empty">آدرسی ذخیره نشده است.</p>'));
      list.forEach(function (a) {
        var $card = $('<article class="dc-picker-card"></article>').toggleClass('dc-selected',a.id===selectedId);
        $('<h3></h3>').text(a.label || 'آدرس').appendTo($card);
        $('<p></p>').text(readable(a)).appendTo($card);
        var $actions = $('<div class="dc-picker-card-actions"></div>').appendTo($card);
        var $choose=$('<button type="button">انتخاب آدرس</button>').appendTo($actions);
        var $edit=$('<button type="button">ویرایش</button>').appendTo($actions);
        var $delete=$('<button type="button" class="dc-delete-address">حذف</button>').appendTo($actions);
        $choose.on('click',function(){ chooseAddress(a); });
        $edit.on('click',function(){ closePicker(); openEditor('details',a); });
        $delete.on('click',function(){ deleteAddress(a); });
        $list.append($card);
      });
    }
    function openPicker() { paintPicker(); $body.addClass('dc-picker-open dc-lock-scroll'); $shade.attr('aria-hidden','false'); }
    function closePicker() { $body.removeClass('dc-picker-open'); if(!$body.hasClass('dc-address-modal-open')&&!$body.hasClass('dc-delete-open')){$body.removeClass('dc-lock-scroll');$shade.attr('aria-hidden','true');} }
    function openDeleteConfirm(a) { if(!a) return; deleteTarget = a; $deleteModal.find('.dc-delete-message').text('آیا از حذف آدرس «'+(a.label||'انتخابی')+'» مطمئن هستید؟ این عمل قابل بازگشت نیست.'); $body.addClass('dc-delete-open dc-lock-scroll'); $shade.attr('aria-hidden','false'); }
    function closeDeleteConfirm() { deleteTarget = null; $body.removeClass('dc-delete-open'); if(!$body.hasClass('dc-address-modal-open')&&!$body.hasClass('dc-picker-open')){$body.removeClass('dc-lock-scroll');$shade.attr('aria-hidden','true');} }
    function chooseAddress(a) {
      if (!a) return;
      var commit=function(){ selectedId=a.id; apply(a); closePicker(); };
      if (!cfg.loggedIn) { commit(); return; }
      $picker.find('button').prop('disabled',true);
      ajax('dcashop_address_select',{id:a.id}).done(function(res){if(res&&res.success)commit();else window.alert(res&&res.data&&res.data.message||words.savingError);})
        .fail(function(){window.alert(words.savingError);}).always(function(){$picker.find('button').prop('disabled',false);});
    }
    function deleteAddress(a) {
      if (!cfg.loggedIn) { window.alert('برای حذف آدرس ذخیره‌شده باید وارد حساب شوید.'); return; }
      if (!a) return;
      deleteTarget = a;
      openDeleteConfirm(a);
    }
    function doDeleteAddress(a) {
      if (!a) return;
      $picker.find('button').prop('disabled',true);
      $deleteModal.find('button').prop('disabled',true);
      ajax('dcashop_address_delete',{id:a.id}).done(function(res){
        if (!res || !res.success) { window.alert(res&&res.data&&res.data.message||words.deleteError); return; }
        addresses = res.data.addresses || []; primary = res.data.defaultAddress || null;
        selectedId = res.data.selected || '';
        var next = addressById(selectedId);
        if (next) apply(next); else { clearAddress(); $(document.body).trigger('update_checkout'); paintCurrent(); }
        paintPicker();
        closeDeleteConfirm();
      }).fail(function(){window.alert(words.deleteError||'حذف آدرس انجام نشد.');}).always(function(){$picker.find('button').prop('disabled',false);$deleteModal.find('button').prop('disabled',false);});
    }
    function openEditor(stage, a) {
      if (page !== 'shipping') return;
      closePicker(); beforeEdit=read(); editorId=a&&a.id||'';
      editRecipient = {recipient_type:a&&a.recipient_type||'self', recipient_first_name:a&&a.recipient_first_name||'', recipient_last_name:a&&a.recipient_last_name||'', recipient_phone:a&&a.recipient_phone||''};
      if (a) apply(a); else clearAddress();
      clearMessage();
      $confirm.find('#dc-address-label-input').val(a&&a.label ? a.label : '');
      $confirm.find('input[name="dc_recipient_type"][value="'+editRecipient.recipient_type+'"]').prop('checked',true).trigger('change');
      $confirm.find('.dc-recipient-first').val(editRecipient.recipient_first_name);
      $confirm.find('.dc-recipient-last').val(editRecipient.recipient_last_name);
      $confirm.find('.dc-recipient-phone').val(editRecipient.recipient_phone);
      $body.addClass('dc-address-modal-open dc-lock-scroll'); $shade.attr('aria-hidden','false');
      switchModalStage(stage||'map');
    }
    function switchModalStage(stage) {
      modalStageName=stage;
      $billing.removeClass('dc-modal-identity dc-modal-map dc-modal-details dc-modal-confirm dc-editing-address dc-map-uttamap').addClass('dc-modal-'+stage).toggleClass('dc-editing-address', !!editorId).toggleClass('dc-has-coordinates', !!get('billing_map_lat_long')).toggleClass('dc-map-uttamap', stage==='map' && useUttamap); 
      var isEditing=!!editorId;
      var titles={identity:'مشخصات مشتری',map:'انتخاب موقعیت',details:isEditing?'ویرایش آدرس':'تکمیل آدرس',confirm:'تأیید اطلاعات آدرس'};
      $header.find('h2').text(titles[stage]);
      $header.find('.dc-modal-close').attr('aria-label',stage==='map' && useUttamap?'بازگشت به مرحله ارسال':'بستن');
      $modalFooter.find('.dc-modal-main').text(stage==='map'?'ثبت موقعیت مکانی':stage==='details'?'ادامه و تأیید آدرس':stage==='confirm'?(isEditing?'به‌روزرسانی آدرس':'ثبت آدرس'):'ادامه');
      $modalFooter.find('.dc-modal-back').toggle(stage==='details'||stage==='confirm');
      $modalFooter.find('.dc-modal-manual').toggle(stage==='map');
      $error.empty();
      if(stage==='confirm') {
        $confirm.find('.dc-confirm-location').text(readable(read())||'آدرس را تکمیل کنید.');
        $confirm.find('.dc-recipient-self-note').text('گیرنده: '+[get('billing_first_name'),get('billing_last_name')].filter(Boolean).join(' ')+' — '+get('billing_phone'));
      }
      if (stage==='map' && useUttamap) {
        window.setTimeout(function(){
          var activeMap = window.DCAShopUttamapMap;
          if (activeMap && typeof activeMap.show==='function') {
            activeMap.show(get('billing_map_lat_long'));
          }
        },150);
      } else if (!useUttamap && (stage==='map'||stage==='confirm')) {
        window.setTimeout(function(){window.dispatchEvent(new Event('resize'));},180);
      }
      $billing.scrollTop(0);
    }
    function dismissEditor(cancel) {
      if (busy) return;
      if (cancel && beforeEdit) apply(beforeEdit);
      beforeEdit=null; $body.removeClass('dc-address-modal-open dc-lock-scroll');
      $shade.attr('aria-hidden','true'); $error.empty(); modalStageName=''; paintCurrent();
    }
    function failModal(msg,$focus) { $error.text(msg).addClass('dc-has-error'); if($focus&&$focus.length)$focus.trigger('focus'); }
    function validIdentity() { if(!get('billing_first_name')){failModal('نام را وارد کنید.',node('billing_first_name'));return false;}if(!get('billing_last_name')){failModal('نام خانوادگی را وارد کنید.',node('billing_last_name'));return false;}return true; }
    function validDetails() {
      if(!validIdentity())return false;
      var needed=[['billing_state','استان'],['billing_city','شهر'],['billing_address_1','نشانی'],['billing_address_4','پلاک'],['billing_address_3','واحد']];
      for(var i=0;i<needed.length;i++)if(!get(needed[i][0])){failModal('لطفاً '+needed[i][1]+' را تکمیل کنید.',node(needed[i][0]));return false;}
      if(!get('billing_phone')){failModal('شماره موبایل ضروری است.',node('billing_phone'));return false;}
      return true;
    }
    function recipientData() {
      var type=$confirm.find('[name="dc_recipient_type"]:checked').val()||'self';
      return {recipient_type:type,recipient_first_name:$confirm.find('.dc-recipient-first').val().trim(),
        recipient_last_name:$confirm.find('.dc-recipient-last').val().trim(),recipient_phone:$confirm.find('.dc-recipient-phone').val().trim()};
    }
    function saveEditor() {
      if(busy||!validDetails())return;
      var a=read(),r=recipientData();
      if(r.recipient_type==='other'&&(!r.recipient_first_name||!r.recipient_last_name||!r.recipient_phone)){
        failModal('نام، نام خانوادگی و شماره موبایل تحویل‌گیرنده را کامل کنید.',$confirm.find('.dc-recipient-other-fields input').first());return;
      }
      a.id=editorId;a.label=$confirm.find('#dc-address-label-input').val().trim()||'آدرس جدید';
      $.extend(a,r);
      if(!cfg.loggedIn){a.id=a.id||'temporary';addresses.push(a);selectedId=a.id;dismissEditor(false);$(document.body).trigger('update_checkout');return;}
      setBusy(true);
      ajax('dcashop_address_save',{address:a}).done(function(res){
        if(res&&res.success&&res.data&&res.data.address){
          addresses=res.data.addresses||[];primary=res.data.defaultAddress||primary;selectedId=res.data.address.id;
          apply(res.data.address);setBusy(false);dismissEditor(false);
        }else failModal(res&&res.data&&res.data.message||words.savingError);
      }).fail(function(xhr){failModal(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message||words.savingError);}).always(function(){setBusy(false);});
    }
    function advanceEditor() {
      $error.empty();
      if(modalStageName==='identity'){if(validIdentity())switchModalStage('map');return;}
      if(modalStageName==='map'){
        if (useUttamap) {
          var activeMap = window.DCAShopUttamapMap;
          if (!activeMap || typeof activeMap.resolve !== 'function') {
            failModal('نقشه آماده نشده است؛ می‌توانید آدرس دستی وارد کنید.'); return;
          }
          setBusy(true);
          activeMap.resolve().then(function(place){
            if (!place || !Number.isFinite(Number(place.lat)) || !Number.isFinite(Number(place.lng))) {throw new Error('موقعیت انتخاب‌شده معتبر نیست.');}
            // WooCommerce/YITH stores an IR province code; Uttamap provides a Persian name.
            var normalized=function(v){return String(v||'').replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/\u200c/g,' ').replace(/\s+/g,' ').trim();};
            var stateName=normalized(place.state).replace(/^استان\s+/, '');
            var $state=node('billing_state');
            var $stateOption=$state.find('option').filter(function(){return normalized($(this).text())===stateName || normalized($(this).val())===stateName;}).first();
            if ($stateOption.length) {setField('billing_state',$stateOption.val());}
            else if (stateName && !$state.is('select')) {setField('billing_state',place.state);}
            // Iranian city selectors can be populated asynchronously after
            // province change. Only accept an exact city option from the map's
            // named localities; never guess from a county or fall back to Tehran.
            var cityName=normalized(place.city).replace(/^شهر\s+/, '');
            var stateCanonical=normalized(stateName);
            var hints=Array.isArray(place.city_candidates)?place.city_candidates:[];
            var cityNames=[];
            // A separately named village/settlement or first part of the full
            // address may be more precise than a provincial-centre fallback.
            hints.forEach(function(item){
              var name=normalized(item).replace(/^شهر\s+/, '');
              if(name && cityNames.indexOf(name)===-1)cityNames.push(name);
            });
            if(cityName && cityNames.indexOf(cityName)===-1)cityNames.push(cityName);
            // If API explicitly gave a different city, trust it ahead of loose
            // text hints, except when it is identical to province name.
            if(cityName && cityName!==stateCanonical){
              cityNames=cityNames.filter(function(s){return s!==cityName;});
              cityNames.unshift(cityName);
            }else{
              // For the Tehran province, a specific city from the full address
              // can be more precise than the generic provincial capital returned by the API.
              // Matching is still exact against that province's city options.
              cityNames=cityNames.filter(function(s){return s!==stateCanonical;})
                .concat(cityNames.filter(function(s){return s===stateCanonical;}));
            }
            setField('billing_city','');
            var cityResolved=false;
            function chooseCity(){
              var $city=node('billing_city');
              if(!$city.length || cityResolved || modalStageName!=='map' && modalStageName!=='details')return false;
              // Keep manual changes made after the form was shown.
              if(String($city.val()||'').trim())return false;
              if(!$city.is('select')){
                // Plain-text YITH city field: only an explicit city is safe.
                if(!cityName)return false;
                setField('billing_city',place.city);cityResolved=true;return true;
              }
              for(var k=0;k<cityNames.length;k++){
                var candidate=cityNames[k];
                var $option=$city.find('option').filter(function(){
                  return normalized($(this).text()).replace(/^شهر\s+/,'')===candidate ||
                         normalized($(this).val()).replace(/^شهر\s+/,'')===candidate;
                }).first();
                if($option.length){setField('billing_city',$option.val());cityResolved=true;return true;}
              }
              return false;
            }
            // The first attempt may precede the plugin rebuilding city options.
            chooseCity();
            [200,550,1000,1800,3000,4500].forEach(function(ms){window.setTimeout(chooseCity,ms);});
            window.setTimeout(function(){
              if(modalStageName==='details' && !get('billing_city')){
                failModal('موقعیت مکانی شما به‌طور دقیق شناسایی نشد؛ لطفاً شهر را از فهرست انتخاب کنید.',node('billing_city'));
              }
            },5200);
            setField('billing_address_1', place.address || '');
            // Postal code must be customer-entered; Uttamap reverse is not a validated postal-code source.
            setField('billing_postcode','');
            setField('billing_map_lat_long',String(place.lat)+','+String(place.lng));
            switchModalStage('details');
          }).catch(function(err){failModal(err&&err.message||'دریافت نشانی انجام نشد. دوباره امتحان کنید.');})
            .then(function(){setBusy(false);});
          return;
        }
        if(!get('billing_map_lat_long')){failModal('ابتدا موقعیت روی نقشه را مشخص کنید، یا «ثبت آدرس دستی» را بزنید.');return;}
        switchModalStage('details');return;
      }
      if(modalStageName==='details'){if(validDetails())switchModalStage('confirm');return;}
      if(modalStageName==='confirm')saveEditor();
    }
    $header.find('.dc-modal-close').on('click',function(){dismissEditor(true);});
    $modalFooter.find('.dc-modal-main').on('click',advanceEditor);
    $modalFooter.find('.dc-modal-back').on('click',function(){if(modalStageName==='details')switchModalStage('map');else if(modalStageName==='confirm')switchModalStage('details');});
    $modalFooter.find('.dc-modal-manual').on('click',function(){setField('billing_map_lat_long','');switchModalStage('details');node('billing_address_1').trigger('focus');});
    $picker.find('.dc-picker-close').on('click',closePicker);
    $deleteModal.find('.dc-delete-cancel').on('click', closeDeleteConfirm);
    $deleteModal.find('.dc-delete-confirm').on('click', function(){ doDeleteAddress(deleteTarget); });
    $picker.find('.dc-picker-new').on('click',function(){
      var count=addresses.length+(primary&&primary.billing_address_1?1:0);
      if(count >= (Number(cfg.maxAddresses)||5)){
        $picker.find('.dc-picker-limit-notice').remove();
        $('<p class="dc-picker-limit-notice" role="status">حداکثر ۵ آدرس، شامل آدرس اصلی، قابل ذخیره است. برای افزودن آدرس، ابتدا یکی از آدرس‌های قبلی را حذف کنید.</p>').insertBefore($picker.find('.dc-picker-footer'));
        return;
      }
      closePicker();openEditor('map',null);
    });
    $shade.on('click',function(){if($body.hasClass('dc-address-modal-open'))dismissEditor(true);else if($body.hasClass('dc-delete-open'))closeDeleteConfirm();else closePicker();});
    $(document).on('keydown.dcShopSteps',function(ev){if(ev.key==='Escape'){if($body.hasClass('dc-address-modal-open'))dismissEditor(true);else if($body.hasClass('dc-delete-open'))closeDeleteConfirm();else if($body.hasClass('dc-picker-open'))closePicker();}});
    $addressBlock.find('.dc-address-change').on('click',function(){
      var count=addresses.length+(primary&&primary.billing_address_1?1:0);
      if(count)openPicker();else openEditor(get('billing_first_name')&&get('billing_last_name')?'map':'identity',null);
    });

    // Place interactive payment controls between Shipping and Total in the actual
    // WooCommerce order table. WC replaces this table through update_checkout;
    // park controls before replacement and reattach after updated_checkout.
    function parkSummaryExtras() {
      // The coupon now lives outside WooCommerce AJAX fragments, above payment methods.
      $wallet.detach().appendTo($parking);
    }
    function placeSummaryExtras() {
      var $table = $summary.children('.woocommerce-checkout-review-order-table').first();
      var $foot = $table.children('tfoot');
      if (!$foot.length) return;
      var $row = $foot.children('tr.dc-summary-interactive-row').first();
      if (!$row.length) {
        $row = $('<tr class="dc-summary-interactive-row"><td colspan="2"><div class="dc-summary-interactive-content"></div></td></tr>');
      }
      var $shipping = $foot.children('tr.dc-review-shipping').last();
      var $total = $foot.children('tr.order-total').first();
      if ($shipping.length) $row.insertAfter($shipping);
      else if ($total.length) $row.insertBefore($total);
      else $foot.append($row);
      $row.find('.dc-summary-interactive-content').first().append($wallet);
      $row.toggleClass('dc-summary-has-extras', $wallet.hasClass('dc-has-wallet'));
    }

    function markWalletRows() {
      var $table=$summary.find('.woocommerce-checkout-review-order-table').first();
      // Never empty before looking for new rows: that removed existing wallet controls
      // when the checkout changed step or refreshed without a new cart fragment.
      var $fresh=$table.children('thead,tbody,tfoot').children('tr').filter(function(){
        return $(this).text().indexOf('کیف پول')!==-1 && $(this).find('input').length>0 && !$(this).hasClass('dc-summary-interactive-row');
      });
      if($fresh.length){
        $wallet.find('tbody').empty().append($fresh.addClass('dc-wallet-row'));
      }
      $wallet.toggleClass('dc-has-wallet', $wallet.find('tr').length>0);
      $summary.find('tr.dc-summary-interactive-row').toggleClass('dc-summary-has-extras',$wallet.hasClass('dc-has-wallet'));
    }
    function refreshCashback() {
      // The Wallet plugin owns this message. Copy only its DISPLAY TEXT to the
      // summary; leave all wallet controls and calculations in their source.
      var needle='با تکمیل خرید';
      var $candidates=$paymentBox.find('p,div,span').filter(function(){
        var $el=$(this), t=$el.text().replace(/\s+/g,' ').trim();
        return t.indexOf(needle)!==-1 && t.indexOf('کیف پول')!==-1 &&
          t.length<450 && !$el.find('button,input,select,textarea,iframe').length &&
          !$el.closest('.dc-cashback-note,.payment_box').length;
      });
      var $leaf=$candidates.filter(function(){
        return !$(this).find('p,div,span').filter(function(){
          return $(this).text().indexOf(needle)!==-1;
        }).length;
      }).first();
      if(!$leaf.length){
        // Existing rendered text may already be hidden from the previous refresh.
        return;
      }
      $cashback.text($leaf.text().replace(/\s+/g,' ').trim()).addClass('dc-has-cashback');
      $leaf.addClass('dc-cashback-original');
    }
    function decorateGateways() {
      $paymentBox.find('#payment ul.payment_methods > li').each(function(){
        var $li=$(this),$box=$li.children('.payment_box').first();
        if(!$li.children('label').length)return;
        var $title=$li.children('label').first();
        var $caption=$li.children('.dc-gateway-description');
        // For redirect gateways the payment_box is just a description. Keep
        // its text permanently visible even when Woo hides non-selected boxes.
        if($box.length && !$box.find('input,select,textarea,button,iframe,form').length){
          var txt=$box.text().replace(/\s+/g,' ').trim();
          if(txt && txt.length<350){
            if(!$caption.length){$caption=$('<p class="dc-gateway-description"></p>').insertAfter($title);}
            $caption.text(txt);
            $box.addClass('dc-gateway-description-source');
          }
        }
        if(!$caption.length && $title.text().indexOf('پاسارگاد')!==-1){
          $('<p class="dc-gateway-description"></p>')
            .text('پرداخت امن به وسیله کلیه کارت های عضو شتاب از طریق بانک پاسارگاد')
            .insertAfter($title);
        }
        $li.toggleClass('dc-has-gateway-description',!!$li.children('.dc-gateway-description').length);
      });
    }
    function shippingMethodSelected() {
      if(!cfg.shippingRequired)return true;
      var $m=$customer.find('.my-custom-shipping-table input[name^="shipping_method"]');
      if(!$m.length){message(words.noShipping,true);return false;}
      if(!$m.filter('[type="hidden"]').length&&!$m.filter(':checked').length){message(words.missingShipping,true);return false;}
      return true;
    }
    function validAddress() {
      if(!get('billing_first_name')||!get('billing_last_name')||!get('billing_phone')||!get('billing_state')||!get('billing_city')||!get('billing_address_1')||!get('billing_address_4')||!get('billing_address_3')){
        message('برای ادامه ابتدا یک آدرس کامل ثبت یا انتخاب کنید.',true);return false;
      }
      return true;
    }
    function syncTotal() {
      placeSummaryExtras();
      var $nativePay = $review.find('#payment #place_order').first();
      $payBelowSummary.prop('disabled', !$nativePay.length || $nativePay.prop('disabled'));
      var $sum=$summary.find('.order-total .woocommerce-Price-amount').last();
      var formattedTotal=$sum.length?$sum.text().replace(/\s+/g,' ').trim():'';
      $fixed.find('.dc-fixed-total strong').text(formattedTotal);
      if(page==='payment') {
        $fixed.find('.dc-mobile-primary').empty().append($('<span>پرداخت</span>'), $('<strong class="dc-mobile-pay-amount"></strong>').text(formattedTotal));
      }
      markWalletRows();
      refreshCashback();
      decorateGateways();
    }
    function setPage(stage) {
      page=stage;var paying=stage==='payment';
      $form.toggleClass('dc-show-payment',paying).toggleClass('dc-show-shipping',!paying);
      $body.toggleClass('dc-body-payment-step',paying).toggleClass('dc-body-shipping-step',!paying);
      $fixed.find('.dc-mobile-primary').text(paying?'پرداخت':words.next);
      $mobileNav.find('.dc-mobile-nav-text').text(paying?'آدرس ارسال':'سبد خرید');
      $mobileNav.find('.dc-mobile-nav-back').attr('aria-label',paying?'بازگشت به آدرس ارسال':'بازگشت به صفحه اصلی');
      syncTotal();
      window.setTimeout(function(){var t=Math.max(0,$form.offset().top-65);$('html,body').stop(true).animate({scrollTop:t},180);},20);
    }
    function setShippingSpinner(on) {
      $next.add($fixed.find('.dc-mobile-primary')).toggleClass('dc-action-loading', !!on).attr('aria-busy',on?'true':'false');
    }
    function finishRefresh() {shippingBusy=false;setShippingSpinner(false);$next.prop('disabled',false);$fixed.find('.dc-mobile-primary').prop('disabled',false);if(refreshTimer)window.clearTimeout(refreshTimer);refreshTimer=null;}
    function beginPayment() {
      if(shippingBusy)return;
      clearMessage();if(!validAddress()||!shippingMethodSelected())return;
      shippingBusy=true;setShippingSpinner(true);$next.prop('disabled',true);$fixed.find('.dc-mobile-primary').prop('disabled',true);
      $status.text('در حال بررسی آدرس و هزینه ارسال…');
      $(document.body).one('updated_checkout.dcShopStepsNext',function(){
        if(!validAddress()||!shippingMethodSelected()){finishRefresh();return;}
        $status.text('در حال ثبت اطلاعات ارسال…');
        ajax('dcashop_checkout_confirm_shipping',{fields:read()})
          .done(function(res){
            if(res&&res.success&&res.data&&res.data.paymentUrl){
              navigateTo(res.data.paymentUrl);
              return;
            }
            finishRefresh();message(res&&res.data&&res.data.message||'تأیید آدرس انجام نشد.',true);
          })
          .fail(function(xhr){finishRefresh();message(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message||'خطا در تأیید مرحله ارسال. دوباره تلاش کنید.',true);});
      });
      $(document.body).trigger('update_checkout');
      refreshTimer=window.setTimeout(function(){$(document.body).off('updated_checkout.dcShopStepsNext');finishRefresh();message(words.updateError,true);},15000);
    }
    $next.on('click',beginPayment);
    $back.on('click',function(){navigateTo(cfg.shippingUrl);});
    $mobileNav.find('.dc-mobile-nav-back').on('click',function(){
      if(page==='payment') {navigateTo(cfg.shippingUrl);}
      else {navigateTo(cfg.homeUrl);}
    });
    var paymentSpinnerTimer = null;
    function setPaymentSpinner(on) {
      $payBelowSummary.add($fixed.find('.dc-mobile-primary')).toggleClass('dc-action-loading',!!on).attr('aria-busy',on?'true':'false');
      if(paymentSpinnerTimer) window.clearTimeout(paymentSpinnerTimer);
      paymentSpinnerTimer = on ? window.setTimeout(function(){setPaymentSpinner(false);},20000) : null;
    }
    function submitNativePayment() {
      if (page !== 'payment') return;
      // Keep WooCommerce's real button inside #payment: gateways and validation
      // rely on it. Both presentation buttons forward the click to that button.
      var $real = $review.find('#payment #place_order').first();
      if ($real.length && !$real.prop('disabled') && !shippingBusy) {setPaymentSpinner(true);$real.trigger('click');}
    }
    $payBelowSummary.on('click', submitNativePayment);
    $fixed.find('.dc-mobile-primary').on('click',function(){
      if(page==='shipping'){beginPayment();return;}
      submitNativePayment();
    });
    $form[0].addEventListener('submit',function(ev){
      if($body.hasClass('dc-address-modal-open')){ev.preventDefault();ev.stopImmediatePropagation();advanceEditor();}
      else if(page==='shipping'){ev.preventDefault();ev.stopImmediatePropagation();beginPayment();}
    },true);
    $(document.body).on('checkout_error.dcShopSteps',function(){setPaymentSpinner(false);if($customer.find('.woocommerce-invalid').length){navigateTo(cfg.shippingUrl);}});
    $(window).on('pageshow.dcShopSteps',function(){setPaymentSpinner(false);});
    $(document.body).on('update_checkout.dcShopSteps',parkSummaryExtras);
    $(document.body).on('updated_checkout.dcShopSteps',syncTotal);
    $form.on('change','[name^="billing_"]',function(){if(!$body.hasClass('dc-address-modal-open'))paintCurrent();});

    // Treat the entire shipping-method card as the radio's hit area.
    // Delegate because WooCommerce replaces rate list markup on update_checkout.
    // Avoid interfering with native label/input interactions or embedded links.
    $customer.on('click.dcShopShippingCards',
      '.my-custom-shipping-table .woocommerce-shipping-methods li.shipping__list_item',
      function(event) {
        if ($(event.target).closest('input, label, a, button, select, textarea').length) return;
        var $radio = $(this).find('input[type="radio"][name^="shipping_method"]').first();
        if (!$radio.length || $radio.prop('disabled') || $radio.prop('checked')) return;
        $radio.prop('checked', true).trigger('change');
      }
    );

    // Make the complete bank gateway card clickable, including its description.
    // WC checkout refreshes payment fragments; delegation survives replacement.
    $paymentBox.on('click.dcShopGatewayCards', '#payment ul.payment_methods > li.wc_payment_method', function(event) {
      if ($(event.target).closest('input, label, a, button, select, textarea, iframe, [role="button"]').length) return;
      var $radio = $(this).children('input[type="radio"][name="payment_method"]').first();
      if (!$radio.length || $radio.prop('disabled') || $radio.prop('checked')) return;
      $radio.prop('checked', true).trigger('change');
    });

    $coupon.find('.dc-coupon-toggle').on('click', function(){
      var open = $coupon.hasClass('dc-open');
      $coupon.toggleClass('dc-open', !open);
      $(this).attr('aria-expanded', (!open) ? 'true' : 'false').attr('aria-pressed', (!open) ? 'true' : 'false');
    });

    // Coupon form stays separate from WooCommerce's original checkout form. This
    // non-form UI calls the standard Woo AJAX apply_coupon endpoint with Woo nonce.
    $coupon.find('.dc-coupon-apply').on('click',function(){
      var code=$coupon.find('.dc-coupon-input').val().trim();
      if(!code){$coupon.addClass('dc-open').find('.dc-coupon-toggle').attr('aria-expanded','true');$coupon.find('.dc-coupon-feedback').text('کد تخفیف را وارد کنید.');return;}
      if(!cfg.couponUrl){$coupon.addClass('dc-open').find('.dc-coupon-toggle').attr('aria-expanded','true');$coupon.find('.dc-coupon-feedback').text('امکان اعمال تخفیف در دسترس نیست.');return;}
      var $button=$(this).prop('disabled',true);
      $.ajax({type:'POST',url:cfg.couponUrl,data:{security:cfg.couponNonce,coupon_code:code}})
        .done(function(html){var $fragment=$('<div></div>').html(html);$coupon.addClass('dc-open').find('.dc-coupon-toggle').attr('aria-expanded','true');$coupon.find('.dc-coupon-feedback').text($fragment.text().replace(/\s+/g,' ').trim()||'کد تخفیف بررسی شد.');$(document.body).trigger('update_checkout');})
        .fail(function(){$coupon.addClass('dc-open').find('.dc-coupon-toggle').attr('aria-expanded','true');$coupon.find('.dc-coupon-feedback').text('خطا در بررسی کد تخفیف.');})
        .always(function(){$button.prop('disabled',false);});
    });

    $body.addClass('dc-checkout-steps-ready');
    if(!selectedId&&primary&&primary.billing_address_1)selectedId='default';
    paintCurrent();setPage(cfg.initialStep==='payment'?'payment':'shipping');
    if(page==='shipping'&&(!get('billing_first_name')||!get('billing_last_name')||!get('billing_address_1'))){
      window.setTimeout(function(){openEditor(get('billing_first_name')&&get('billing_last_name')?'map':'identity',null);},420);
    }
  });
})(jQuery);
