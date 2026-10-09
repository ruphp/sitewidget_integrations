<?php

namespace Opencart\Catalog\Model\Extension\SiteWidget;

final class SmsDelivery extends \Opencart\System\Engine\Model
{
    public function requestCode(string $phone): array
    {
        $phone = $this->normalizePhone($phone);
        if ($phone === null) {
            throw new \InvalidArgumentException('Некорректный номер телефона');
        }

        $requestId = bin2hex(random_bytes(16));
        $code = (string)random_int(1000, 9999);
        $this->session->data['sitewidget_sms'][$requestId] = [
            'phone' => $phone,
            'codeHash' => hash_hmac('sha256', $code, (string)$this->config->get('module_sitewidget_sms_api_token')),
            'attempts' => 0,
            'expiresAt' => time() + 300,
        ];

        try {
            $this->queue(
                $requestId,
                $phone,
                'Код подтверждения ' . (string)$this->config->get('config_name') . ': ' . $code,
                new \DateTimeImmutable('+5 minutes'),
            );
        } catch (\Throwable $exception) {
            unset($this->session->data['sitewidget_sms'][$requestId]);
            throw $exception;
        }

        return ['requestId' => $requestId, 'expiresIn' => 300];
    }

    public function verifyCode(string $requestId, string $code): array
    {
        $data = $this->session->data['sitewidget_sms'][$requestId] ?? null;
        if (!is_array($data) || (int)($data['expiresAt'] ?? 0) < time()) {
            unset($this->session->data['sitewidget_sms'][$requestId]);
            throw new \RuntimeException('Код истёк или не найден');
        }

        $attempts = (int)($data['attempts'] ?? 0) + 1;
        if ($attempts > 5) {
            unset($this->session->data['sitewidget_sms'][$requestId]);
            throw new \RuntimeException('Превышено число попыток');
        }
        $actualHash = hash_hmac('sha256', $code, (string)$this->config->get('module_sitewidget_sms_api_token'));
        if (!hash_equals((string)$data['codeHash'], $actualHash)) {
            $data['attempts'] = $attempts;
            $this->session->data['sitewidget_sms'][$requestId] = $data;
            throw new \InvalidArgumentException('Неверный код');
        }

        unset($this->session->data['sitewidget_sms'][$requestId]);
        return ['verified' => true, 'phone' => (string)$data['phone']];
    }

    public function queue(string $requestId, string $phone, string $text, ?\DateTimeInterface $expiresAt = null): array
    {
        $token = trim((string)$this->config->get('module_sitewidget_sms_api_token'));
        if ($token === '') {
            throw new \RuntimeException('SMS API-токен SiteWidget не настроен');
        }

        $payload = json_encode([
            'requestId' => $requestId,
            'phone' => $phone,
            'text' => $text,
            'expiresAt' => ($expiresAt ?? new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $curl = curl_init('https://sitewidget.ru/api/sms/v1/tasks');
        if ($curl === false) {
            throw new \RuntimeException('Не удалось создать запрос SiteWidget');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false) {
            throw new \RuntimeException('SiteWidget недоступен: ' . $error);
        }

        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new \RuntimeException(is_array($data)
                ? (string)($data['message'] ?? 'SiteWidget не принял SMS-задание')
                : 'SiteWidget вернул некорректный ответ');
        }

        return $data;
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7' . substr($digits, 1);
        }
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+' . $digits : null;
    }
}
