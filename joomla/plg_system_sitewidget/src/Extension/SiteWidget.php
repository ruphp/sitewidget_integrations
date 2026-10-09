<?php

namespace SiteWidget\Plugin\System\SiteWidget\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
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

    public function queueSmsCode(string $requestId, string $phone, string $text, ?\DateTimeInterface $expiresAt = null): array
    {
        $token = trim((string)$this->params->get('sms_api_token', ''));
        if ($token === '') {
            throw new \RuntimeException('SMS API-токен SiteWidget не настроен');
        }

        $payload = json_encode([
            'requestId' => $requestId,
            'phone' => $phone,
            'text' => $text,
            'expiresAt' => ($expiresAt ?? new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = HttpFactory::getHttp()->post(
            'https://sitewidget.ru/api/sms/v1/tasks',
            $payload,
            [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            5,
        );
        $data = json_decode((string)$response->body, true);
        if ((int)$response->code < 200 || (int)$response->code >= 300 || !is_array($data)) {
            throw new \RuntimeException(is_array($data)
                ? (string)($data['message'] ?? 'SiteWidget не принял SMS-задание')
                : 'SiteWidget вернул некорректный ответ');
        }

        return $data;
    }

    public function requestSmsCode(string $phone): array
    {
        $phone = $this->normalizePhone($phone);
        if ($phone === null) {
            throw new \InvalidArgumentException('Некорректный номер телефона');
        }

        $requestId = bin2hex(random_bytes(16));
        $code = (string)random_int(1000, 9999);
        $session = Factory::getApplication()->getSession();
        $session->set('sitewidget.sms.' . $requestId, [
            'phone' => $phone,
            'codeHash' => hash_hmac('sha256', $code, Factory::getConfig()->get('secret')),
            'attempts' => 0,
            'expiresAt' => time() + 300,
        ]);

        try {
            $this->queueSmsCode(
                $requestId,
                $phone,
                'Код подтверждения ' . Factory::getConfig()->get('sitename') . ': ' . $code,
                new \DateTimeImmutable('+5 minutes'),
            );
        } catch (\Throwable $exception) {
            $session->remove('sitewidget.sms.' . $requestId);
            throw $exception;
        }

        return ['requestId' => $requestId, 'expiresIn' => 300];
    }

    public function verifySmsCode(string $requestId, string $code): array
    {
        $session = Factory::getApplication()->getSession();
        $key = 'sitewidget.sms.' . $requestId;
        $data = $session->get($key);
        if (!is_array($data) || (int)($data['expiresAt'] ?? 0) < time()) {
            $session->remove($key);
            throw new \RuntimeException('Код истёк или не найден');
        }

        $attempts = (int)($data['attempts'] ?? 0) + 1;
        if ($attempts > 5) {
            $session->remove($key);
            throw new \RuntimeException('Превышено число попыток');
        }
        $actualHash = hash_hmac('sha256', $code, Factory::getConfig()->get('secret'));
        if (!hash_equals((string)$data['codeHash'], $actualHash)) {
            $data['attempts'] = $attempts;
            $session->set($key, $data);
            throw new \InvalidArgumentException('Неверный код');
        }

        $session->remove($key);
        return ['verified' => true, 'phone' => (string)$data['phone']];
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7' . substr($digits, 1);
        }
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+' . $digits : null;
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

        if ((int)$this->params->get('send_roles', 0) === 1) {
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
