<?php

namespace SiteWidget\Plugin\System\SiteWidget\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

final class SmsVerificationField extends FormField
{
    protected $type = 'SmsVerification';

    protected function getLabel(): string
    {
        return '';
    }

    protected function getInput(): string
    {
        $endpoint = Route::_('index.php?option=com_ajax&plugin=sitewidgetSms&group=system&format=json', false);
        $csrfName = Session::getFormToken();
        $id = $this->id;

        $config = json_encode([
            'endpoint' => $endpoint,
            'csrfName' => $csrfName,
            'id' => $id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return <<<HTML
<div id="{$id}" class="sitewidget-sms-verification">
    <label for="{$id}-phone">Номер телефона</label>
    <input type="tel" name="jform[sitewidget_phone]" id="{$id}-phone" class="form-control" autocomplete="tel" required>
    <button type="button" id="{$id}-request" class="btn btn-secondary">Получить код</button>
    <div id="{$id}-code-wrap" hidden>
        <label for="{$id}-code">Код из SMS</label>
        <input type="text" id="{$id}-code" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="4">
        <button type="button" id="{$id}-verify" class="btn btn-secondary">Подтвердить</button>
    </div>
    <input type="hidden" name="jform[sitewidget_phone_verification]" id="{$id}-token">
    <p id="{$id}-status" aria-live="polite"></p>
</div>
<style>
.sitewidget-sms-verification { display: grid; gap: 10px; }
.sitewidget-sms-verification [hidden] { display: none !important; }
.sitewidget-sms-verification .is-error { color: #b32d2e; }
.sitewidget-sms-verification .is-success { color: #16752a; }
</style>
<script>
(function (config) {
    var phone = document.getElementById(config.id + '-phone');
    var requestButton = document.getElementById(config.id + '-request');
    var codeWrap = document.getElementById(config.id + '-code-wrap');
    var code = document.getElementById(config.id + '-code');
    var verifyButton = document.getElementById(config.id + '-verify');
    var token = document.getElementById(config.id + '-token');
    var status = document.getElementById(config.id + '-status');
    var requestId = '';

    function setStatus(message, type) {
        status.textContent = message || '';
        status.className = type ? 'is-' + type : '';
    }

    function send(payload, button) {
        var body = new URLSearchParams(payload);
        body.set(config.csrfName, '1');
        button.disabled = true;
        setStatus('Отправляем запрос...', '');
        return fetch(config.endpoint, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (response) { return response.json(); })
            .then(function (response) {
                if (!response.success) {
                    throw new Error(response.message || 'Не удалось выполнить запрос');
                }
                return Array.isArray(response.data) ? response.data[0] : response.data;
            })
            .finally(function () { button.disabled = false; });
    }

    requestButton.addEventListener('click', function () {
        token.value = '';
        send({smsAction: 'request', phone: phone.value}, requestButton)
            .then(function (data) {
                requestId = data.requestId;
                codeWrap.hidden = false;
                code.focus();
                setStatus('Код передан на отправку. Введите его после получения SMS.', '');
            })
            .catch(function (error) { setStatus(error.message, 'error'); });
    });

    verifyButton.addEventListener('click', function () {
        send({smsAction: 'verify', requestId: requestId, code: code.value}, verifyButton)
            .then(function (data) {
                token.value = data.verificationToken;
                phone.readOnly = true;
                requestButton.hidden = true;
                codeWrap.hidden = true;
                setStatus('Телефон подтверждён.', 'success');
            })
            .catch(function (error) { setStatus(error.message, 'error'); });
    });
})({$config});
</script>
HTML;
    }
}
