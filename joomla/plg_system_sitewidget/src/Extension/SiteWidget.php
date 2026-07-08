<?php

namespace SiteWidget\Plugin\System\SiteWidget\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

final class SiteWidget extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = false;

    private CMSApplicationInterface $siteApp;

    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeCompileHead' => 'onBeforeCompileHead',
        ];
    }

    public function setApplication(CMSApplicationInterface $app): void
    {
        $this->siteApp = $app;
    }

    public function onBeforeCompileHead(): void
    {
        if (!$this->siteApp->isClient('site')) {
            return;
        }

        $publicKey = (int)$this->params->get('public_key', 0);
        if ($publicKey <= 0) {
            return;
        }

        $context = $this->userContext();
        $jsonContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $script = <<<JS
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
JS;

        Factory::getDocument()->addScriptDeclaration($script);
    }

    private function userContext(): array
    {
        $context = [
            'id' => null,
            'role' => null,
            'name' => null,
            'email' => null,
        ];

        $user = Factory::getApplication()->getIdentity();
        if ($user === null || (int)$user->id <= 0) {
            return $context;
        }

        if ((int)$this->params->get('send_user_id', 1) === 1) {
            $context['id'] = (int)$user->id;
        }

        if ((int)$this->params->get('send_roles', 1) === 1) {
            $context['role'] = array_values(array_map('intval', (array)$user->groups));
        }

        if ((int)$this->params->get('send_email', 1) === 1) {
            $context['email'] = (string)$user->email;
        }

        if ((int)$this->params->get('send_name', 1) === 1) {
            $context['name'] = (string)$user->name;
        }

        return $context;
    }
}
