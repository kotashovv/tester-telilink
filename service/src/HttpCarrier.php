<?php
declare(strict_types=1);
namespace DailyPass;

final class HttpCarrier implements Carrier
{
    public function request(string $path, array $payload): array
    {
        $payload['applicationId'] = Config::secret('APPLICATION_ID', 'application_id');
        $payload['password'] = Config::secret('PLATFORM_PASSWORD', 'platform_password');
        $ch = curl_init(rtrim(getenv('PLATFORM_URL') ?: 'http://platform-mock:8081', '/') . $path);
        curl_setopt_array($ch, [CURLOPT_NOPROXY => 'localhost,127.0.0.1,service,platform-mock', CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $http !== 200) {
            throw new \RuntimeException('Carrier transport outcome unavailable');
        }
        try {
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Invalid carrier response');
        }
        if (!is_array($result) || !is_string($result['statusCode'] ?? null)) {
            throw new \RuntimeException('Invalid carrier response');
        }
        return $result;
    }
}
