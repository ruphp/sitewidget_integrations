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

        $output .= $script;
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
