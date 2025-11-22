<?php

namespace Espo\Modules\PrtgIntegration\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Services\Record;
use DateTimeImmutable;

class PrtgConfig extends Record
{
    /**
     * Validate connectivity against PRTG using provided credentials.
     *
     * @param array $data
     * @return array{success:bool,message:string,httpCode?:int}
     */
    public function testConnection(array $data): array
    {
        $endpoint = $this->normalizeEndpoint($data['endpoint'] ?? '');
        $username = trim($data['username'] ?? '');
        $passhash = trim($data['passhash'] ?? '');
        $verifyTls = array_key_exists('verifyTls', $data) ? (bool) $data['verifyTls'] : true;
        $timeout = (int) ($data['timeout'] ?? 15);
        $timeout = $timeout > 0 ? $timeout : 15;

        if (!$endpoint || !$username || !$passhash) {
            throw new BadRequest('Endpoint, username and passhash are required.');
        }

        $queryParams = [
            'content' => 'sensors',
            'count' => 1,
            'username' => $username,
            'passhash' => $passhash,
            'columns' => 'objid'
        ];

        $url = rtrim($endpoint, '/') . '/api/table.json?' . http_build_query($queryParams);

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_TIMEOUT => $timeout
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);

        curl_close($curl);

        if ($response === false) {
            $message = $curlError ?: 'Unknown cURL error';

            $result = [
                'success' => false,
                'message' => $message
            ];

            if (!empty($data['id'])) {
                $this->saveTestResult((string) $data['id'], $result);
            }

            return $result;
        }

        if ($httpCode >= 400 || $httpCode === 0) {
            $result = [
                'success' => false,
                'message' => "HTTP $httpCode",
                'httpCode' => $httpCode
            ];

            if (!empty($response)) {
                $snippet = substr(trim(strip_tags($response)), 0, 180);
                if ($snippet !== '') {
                    $result['message'] .= " - $snippet";
                }
            }

            if (!empty($data['id'])) {
                $this->saveTestResult((string) $data['id'], $result);
            }

            return $result;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result = [
                'success' => false,
                'message' => 'Invalid JSON received from PRTG'
            ];

            if (!empty($data['id'])) {
                $this->saveTestResult((string) $data['id'], $result);
            }

            return $result;
        }

        $result = [
            'success' => true,
            'message' => 'OK'
        ];

        if (!empty($data['id'])) {
            $this->saveTestResult((string) $data['id'], $result);
        }

        return $result;
    }

    /**
     * Persist the test result on the PrtgConfig record.
     */
    public function saveTestResult(string $id, array $result): void
    {
        $entity = $this->entityManager->getEntity('PrtgConfig', $id);

        if (!$entity) {
            return;
        }

        $entity->set('lastTestStatus', $result['success'] ? 'success' : 'failed');
        $entity->set('lastTestMessage', $this->cleanMessage($result['message'] ?? ''));
        $entity->set(
            'lastTestedAt',
            (new DateTimeImmutable())->format('Y-m-d H:i:s')
        );

        $this->entityManager->saveEntity($entity);
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }

        // Prepend https:// if missing scheme
        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        return $endpoint;
    }

    private function cleanMessage(string $message): string
    {
        $message = trim($message);

        if (strlen($message) > 250) {
            $message = substr($message, 0, 247) . '...';
        }

        return $message;
    }
}
