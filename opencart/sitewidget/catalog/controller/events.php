<?php

namespace Opencart\Catalog\Controller\Extension\SiteWidget;

final class Events extends \Opencart\System\Engine\Controller
{
    /**
     * @param string $route
     * @param array<string, mixed> $data
     */
    public function injectFooter(&$route, &$data, &$output = null): void
    {
        if (!$this->config->get('module_sitewidget_status')) {
            return;
        }

        $publicKey = (int)$this->config->get('module_sitewidget_public_key');
        if ($publicKey <= 0 || !is_string($output)) {
            return;
        }

        $context = $this->userContext();
        $jsonContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $script = <<<HTML
<script>
window.SiteWidget = {
    apiUrl: 'https://sitewidget.ru/api',
    staticUrl: 'https://sitewidget.ru/static',
    customUrl: 'https://sitewidget.ru/custom',
    supportWsUrl: 'wss://sitewidget.ru/ws',
    publicKey: {$publicKey},
    _user: {$jsonContext}
};
(function () {
    var script = document.createElement('script');
    script.src = 'https://sitewidget.ru/static/lib.js';
    script.async = true;
    document.head.appendChild(script);
})();
</script>
HTML;

        if ((int)$this->config->get('module_sitewidget_sms_registration_enabled') === 1) {
            $script .= $this->registrationVerificationScript();
        }

        $output .= $script;
    }

    public function requestSmsCode(): void
    {
        $json = [];
        try {
            $this->assertSmsRequest();
            $this->assertRequestRateLimit();
            $this->load->model('extension/sitewidget/sms_delivery');
            $json = $this->model_extension_sitewidget_sms_delivery->requestCode(
                (string)($this->request->post['phone'] ?? '')
            );
        } catch (\Throwable $exception) {
            $json = ['error' => $exception->getMessage()];
        }

        $this->json($json);
    }

    public function verifySmsCode(): void
    {
        $json = [];
        try {
            $this->assertSmsRequest();
            $this->load->model('extension/sitewidget/sms_delivery');
            $json = $this->model_extension_sitewidget_sms_delivery->verifyCode(
                (string)($this->request->post['requestId'] ?? ''),
                (string)($this->request->post['code'] ?? '')
            );
        } catch (\Throwable $exception) {
            $json = ['error' => $exception->getMessage()];
        }

        $this->json($json);
    }

    private function registrationVerificationScript(): string
    {
        $this->session->data['sitewidget_sms_form_token'] = bin2hex(random_bytes(24));
        $formToken = (string)$this->session->data['sitewidget_sms_form_token'];
        $language = (string)$this->config->get('config_language');
        $requestUrl = html_entity_decode($this->url->link(
            'extension/sitewidget/events.requestSmsCode',
            'language=' . $language . '&sitewidget_token=' . rawurlencode($formToken)
        ), ENT_QUOTES, 'UTF-8');
        $verifyUrl = html_entity_decode($this->url->link(
            'extension/sitewidget/events.verifySmsCode',
            'language=' . $language . '&sitewidget_token=' . rawurlencode($formToken)
        ), ENT_QUOTES, 'UTF-8');
        $config = json_encode([
            'requestUrl' => $requestUrl,
            'verifyUrl' => $verifyUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return <<<HTML
<script>
(function (config) {
    var form = document.getElementById('form-register');
    if (!form) return;

    var phone = form.querySelector('[name="telephone"]');
    if (!phone) {
        var row = document.createElement('div');
        row.className = 'row mb-3 required';
        row.innerHTML = '<label for="input-telephone" class="col-sm-2 col-form-label">Номер телефона</label>'
            + '<div class="col-sm-10"><input type="tel" name="telephone" id="input-telephone" class="form-control" autocomplete="tel" required></div>';
        var account = form.querySelector('#account') || form;
        account.appendChild(row);
        phone = row.querySelector('input');
    }

    var wrap = document.createElement('div');
    wrap.className = 'row mb-3';
    wrap.innerHTML = '<div class="offset-sm-2 col-sm-10 d-grid gap-2">'
        + '<button type="button" class="btn btn-outline-primary" data-sitewidget-request>Получить код</button>'
        + '<div data-sitewidget-code hidden><label class="form-label">Код из SMS</label>'
        + '<input type="text" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="4">'
        + '<button type="button" class="btn btn-outline-primary mt-2" data-sitewidget-verify>Подтвердить</button></div>'
        + '<input type="hidden" name="sitewidget_phone_verification">'
        + '<div data-sitewidget-status aria-live="polite"></div></div>';
    phone.closest('.row, .mb-3').insertAdjacentElement('afterend', wrap);

    var requestButton = wrap.querySelector('[data-sitewidget-request]');
    var codeWrap = wrap.querySelector('[data-sitewidget-code]');
    var code = codeWrap.querySelector('input');
    var verifyButton = wrap.querySelector('[data-sitewidget-verify]');
    var token = wrap.querySelector('[name="sitewidget_phone_verification"]');
    var status = wrap.querySelector('[data-sitewidget-status]');
    var requestId = '';

    function setStatus(message, error) {
        status.textContent = message || '';
        status.className = error ? 'text-danger' : 'text-muted';
    }
    function send(url, payload, button) {
        button.disabled = true;
        setStatus('Отправляем запрос...', false);
        var body = new URLSearchParams(payload);
        return fetch(url, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error) throw new Error(data.error);
                return data;
            })
            .finally(function () { button.disabled = false; });
    }

    phone.addEventListener('input', function () {
        token.value = '';
        requestButton.hidden = false;
    });
    requestButton.addEventListener('click', function () {
        token.value = '';
        send(config.requestUrl, {phone: phone.value}, requestButton)
            .then(function (data) {
                requestId = data.requestId;
                codeWrap.hidden = false;
                code.focus();
                setStatus('Код передан на отправку. Введите его после получения SMS.', false);
            })
            .catch(function (error) { setStatus(error.message, true); });
    });
    verifyButton.addEventListener('click', function () {
        send(config.verifyUrl, {requestId: requestId, code: code.value}, verifyButton)
            .then(function (data) {
                token.value = data.verificationToken;
                phone.readOnly = true;
                requestButton.hidden = true;
                codeWrap.hidden = true;
                status.className = 'text-success';
                status.textContent = 'Телефон подтверждён.';
            })
            .catch(function (error) { setStatus(error.message, true); });
    });
})({$config});
</script>
HTML;
    }

    private function assertSmsRequest(): void
    {
        if ((int)$this->config->get('module_sitewidget_sms_registration_enabled') !== 1) {
            throw new \RuntimeException('Подтверждение телефона отключено');
        }
        $expected = (string)($this->session->data['sitewidget_sms_form_token'] ?? '');
        $actual = (string)($this->request->get['sitewidget_token'] ?? '');
        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new \RuntimeException('Обновите страницу и попробуйте ещё раз');
        }
    }

    private function assertRequestRateLimit(): void
    {
        $requests = $this->session->data['sitewidget_sms_requests'] ?? [];
        $requests = is_array($requests)
            ? array_values(array_filter($requests, static fn($time): bool => (int)$time > time() - 600))
            : [];
        if (count($requests) >= 5) {
            throw new \RuntimeException('Слишком много запросов. Попробуйте позже.');
        }
        $requests[] = time();
        $this->session->data['sitewidget_sms_requests'] = $requests;
    }

    private function json(array $data): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{id:null|int,role:null|array<int,int>,name:null|string,email:null|string}
     */
    private function userContext(): array
    {
        $context = [
            'id' => null,
            'role' => null,
            'name' => null,
            'email' => null,
        ];

        if (!$this->customer->isLogged()) {
            return $context;
        }

        if ((int)$this->config->get('module_sitewidget_send_user_id') === 1) {
            $context['id'] = (int)$this->customer->getId();
        }

        if ((int)$this->config->get('module_sitewidget_send_roles') === 1) {
            $context['role'] = [(int)$this->customer->getGroupId()];
        }

        if ((int)$this->config->get('module_sitewidget_send_email') === 1) {
            $context['email'] = (string)$this->customer->getEmail();
        }

        if ((int)$this->config->get('module_sitewidget_send_name') === 1) {
            $name = trim((string)$this->customer->getFirstName() . ' ' . (string)$this->customer->getLastName());
            $context['name'] = $name !== '' ? $name : null;
        }

        return $context;
    }
}
