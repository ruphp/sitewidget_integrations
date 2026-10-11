<?php

namespace SiteWidget\Plugin\System\SiteWidget\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

final class SiteWidget extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = false;

    private CMSApplicationInterface $siteApp;

    private ?string $pendingVerificationToken = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onAjaxSitewidgetSms' => 'onAjaxSitewidgetSms',
            'onUserBeforeSave' => 'onUserBeforeSave',
            'onUserAfterSave' => 'onUserAfterSave',
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
        $verificationToken = bin2hex(random_bytes(32));
        $session->set('sitewidget.sms.grant.' . hash('sha256', $verificationToken), [
            'phone' => (string)$data['phone'],
            'expiresAt' => time() + 600,
        ]);

        return [
            'verified' => true,
            'phone' => (string)$data['phone'],
            'verificationToken' => $verificationToken,
        ];
    }

    public function onContentPrepareForm(object $event): void
    {
        if (!$this->smsRegistrationEnabled()) {
            return;
        }

        $form = $event->getArgument('form') ?? $event->getArgument('subject');
        if (!$form instanceof \Joomla\CMS\Form\Form || $form->getName() !== 'com_users.registration') {
            return;
        }

        $form->load(new \SimpleXMLElement(
            '<form><fieldset name="sitewidget_sms" label="Подтверждение телефона" '
            . 'addfieldprefix="SiteWidget\\Plugin\\System\\SiteWidget\\Field">'
            . '<field name="sitewidget_sms" type="SmsVerification" />'
            . '</fieldset></form>'
        ));
    }

    public function onAjaxSitewidgetSms(object $event): void
    {
        if (!$this->smsRegistrationEnabled()) {
            throw new \RuntimeException('Подтверждение телефона отключено', 403);
        }
        if (!Session::checkToken('post')) {
            throw new \RuntimeException('Обновите страницу и попробуйте ещё раз', 403);
        }

        $input = $this->siteApp->getInput();
        $action = $input->post->getCmd('smsAction');
        if ($action === 'request') {
            $this->assertRequestRateLimit();
            $result = $this->requestSmsCode($input->post->getString('phone'));
        } elseif ($action === 'verify') {
            $result = $this->verifySmsCode(
                $input->post->getString('requestId'),
                $input->post->getString('code')
            );
        } else {
            throw new \InvalidArgumentException('Неизвестное действие', 422);
        }

        if (method_exists($event, 'addResult')) {
            $event->addResult($result);
        } else {
            $event->setArgument('result', array_merge((array)$event->getArgument('result', []), [$result]));
        }
    }

    public function onUserBeforeSave(object $event): void
    {
        if (!$this->smsRegistrationEnabled() || !$this->siteApp->isClient('site')) {
            return;
        }

        $isNew = (bool)$event->getArgument('isNew', false);
        $input = $this->siteApp->getInput();
        if (!$isNew || $input->getCmd('option') !== 'com_users' || $input->getCmd('task') !== 'registration.register') {
            return;
        }

        $formData = $input->post->get('jform', [], 'array');
        $phone = (string)($formData['sitewidget_phone'] ?? '');
        $token = (string)($formData['sitewidget_phone_verification'] ?? '');
        if (!$this->validVerificationGrant($phone, $token)) {
            throw new \RuntimeException('Подтвердите номер телефона кодом из SMS');
        }

        $this->pendingVerificationToken = $token;
    }

    public function onUserAfterSave(object $event): void
    {
        if ($this->pendingVerificationToken === null || !(bool)$event->getArgument('savingResult', false)) {
            return;
        }

        Factory::getApplication()->getSession()->remove(
            'sitewidget.sms.grant.' . hash('sha256', $this->pendingVerificationToken)
        );
        $this->pendingVerificationToken = null;
    }

    private function smsRegistrationEnabled(): bool
    {
        return (int)$this->params->get('sms_registration_enabled', 0) === 1
            && trim((string)$this->params->get('sms_api_token', '')) !== '';
    }

    private function validVerificationGrant(string $phone, string $token): bool
    {
        $phone = $this->normalizePhone($phone);
        if ($phone === null || $token === '') {
            return false;
        }

        $session = Factory::getApplication()->getSession();
        $key = 'sitewidget.sms.grant.' . hash('sha256', $token);
        $grant = $session->get($key);
        if (!is_array($grant) || (int)($grant['expiresAt'] ?? 0) < time()) {
            $session->remove($key);
            return false;
        }

        return hash_equals((string)($grant['phone'] ?? ''), $phone);
    }

    private function assertRequestRateLimit(): void
    {
        $session = Factory::getApplication()->getSession();
        $requests = $session->get('sitewidget.sms.requests', []);
        $requests = is_array($requests)
            ? array_values(array_filter($requests, static fn($time): bool => (int)$time > time() - 600))
            : [];
        if (count($requests) >= 5) {
            throw new \RuntimeException('Слишком много запросов. Попробуйте позже.', 429);
        }

        $requests[] = time();
        $session->set('sitewidget.sms.requests', $requests);
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
