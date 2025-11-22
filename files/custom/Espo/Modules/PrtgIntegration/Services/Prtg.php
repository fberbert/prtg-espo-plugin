<?php

namespace Espo\Modules\PrtgIntegration\Services;

use Espo\Services\Record;
use Espo\ORM\Entity;

class Prtg extends Record
{
    /**
     * When creating a Prtg record, auto-sync if sensorId is provided.
     */
    public function createEntity($data)
    {
        /** @var Entity $entity */
        $entity = parent::createEntity($data);

        $sensorId = trim((string) $entity->get('sensorId'));

        if ($sensorId !== '') {
            try {
                $this->syncEntity($entity, true);
            } catch (\Throwable $e) {
                // fail silently; user can click sync later
            }
        }

        return $entity;
    }

    /**
     * Sync a Prtg entity by ID.
     */
    public function syncById(string $id): array
    {
        $entity = $this->entityManager->getEntity('Prtg', $id);
        if (!$entity) {
            return [
                'success' => false,
                'message' => 'Registro não encontrado.'
            ];
        }

        return $this->syncEntity($entity, true);
    }

    /**
     * Core sync logic: pulls sensor info from PRTG API and updates fields.
     *
     * @param Entity $entity
     * @param bool   $doSave
     *
     * @return array{success:bool,message:string}
     */
    public function syncEntity(Entity $entity, bool $doSave = true): array
    {
        $sensorId = trim((string) $entity->get('sensorId'));
        if ($sensorId === '') {
            return [
                'success' => false,
                'message' => 'Sensor ID vazio.'
            ];
        }

        $config = $this->entityManager
            ->getRepository('PrtgConfig')
            ->where(['deleted' => false])
            ->findOne();

        if (!$config) {
            return [
                'success' => false,
                'message' => 'Configuração PRTG não encontrada.'
            ];
        }

        $endpoint = $this->normalizeEndpoint((string) $config->get('endpoint'));
        $username = trim((string) $config->get('username'));
        $passhash = trim((string) $config->get('passhash'));
        $verifyTls = (bool) $config->get('verifyTls');
        $timeout = (int) ($config->get('timeout') ?? 15);
        $timeout = $timeout > 0 ? $timeout : 15;

        if (!$endpoint || !$username || !$passhash) {
            return [
                'success' => false,
                'message' => 'Configuração incompleta (endpoint, usuário ou passhash).'
            ];
        }

        $url = rtrim($endpoint, '/') . '/api/table.json?' . http_build_query([
            'content' => 'sensors',
            'columns' => 'objid,name,probe,group,device,status,lastvalue,lastcheck,lastup,lastdown,uptime,downtime,interval,coverage,message,priority',
            'filter_objid' => $sensorId,
            'username' => $username,
            'passhash' => $passhash
        ]);

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
            return [
                'success' => false,
                'message' => $curlError ?: 'Erro cURL'
            ];
        }

        if ($httpCode >= 400 || $httpCode === 0) {
            $snippet = substr(trim(strip_tags($response)), 0, 180);
            return [
                'success' => false,
                'message' => "HTTP $httpCode" . ($snippet ? " - $snippet" : '')
            ];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'JSON inválido retornado do PRTG.'
            ];
        }

        $items = $decoded['sensors'] ?? [];
        if (count($items) === 0) {
            return [
                'success' => false,
                'message' => 'Sensor não encontrado no PRTG.'
            ];
        }

        $row = $items[0];

        $entity->set('name', $row['name'] ?? $entity->get('name'));
        $entity->set('probe', $row['probe'] ?? null);
        $entity->set('group', $row['group'] ?? null);
        $entity->set('device', $row['device'] ?? null);
        $entity->set('status', $this->mapStatus($row['status'] ?? null, $row['status_raw'] ?? null));
        $entity->set('lastValue', $row['lastvalue'] ?? null);
        $entity->set('lastCheck', $this->toDateTime($row['lastcheck'] ?? null));
        $entity->set('lastUp', $this->toDateTime($row['lastup'] ?? null));
        $entity->set('lastDown', $this->toDateTime($row['lastdown'] ?? null));
        $entity->set('uptime', $this->toFloat($row['uptime'] ?? null));
        $entity->set('downtime', $this->toFloat($row['downtime'] ?? null));
        $entity->set('coverage', $this->toFloat($row['coverage'] ?? null));
        $entity->set('intervalSec', $this->toInt($row['interval'] ?? null));
        $entity->set('message', $this->cleanMessage($row['message'] ?? null));
        $entity->set('priority', $this->toInt($row['priority'] ?? null));
        $entity->set('rawDetails', json_encode($row));

        // Fetch charts and persist as data URIs (embedded images).
        $charts = $this->buildCharts(
            $endpoint,
            $username,
            $passhash,
            $sensorId,
            $verifyTls,
            $timeout
        );

        $entity->set('chart2h', $charts['h2'] ?? null);
        $entity->set('chart2d', $charts['d2'] ?? null);
        $entity->set('chart30d', $charts['d30'] ?? null);
        $entity->set('chart365d', $charts['d365'] ?? null);

        if ($doSave) {
            $this->entityManager->saveEntity($entity);
        }

        return [
            'success' => true,
            'message' => 'Sensor atualizado do PRTG.'
        ];
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if ($endpoint === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        return $endpoint;
    }

    private function toDateTime(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $clean = strip_tags($value);
        $clean = preg_replace('/\\[.*?\\]/', '', $clean);
        $clean = trim($clean);

        // PRTG returns date in dd/mm/YYYY HH:ii:ss or mm/dd/YYYY HH:ii:ss (localized).
        if (preg_match('/^(\\d{2})\\/(\\d{2})\\/(\\d{4})\\s+(\\d{2}:\\d{2}:\\d{2})/', $clean, $m)) {
            $format = 'd/m/Y H:i:s';
            $candidate = "$m[1]/$m[2]/$m[3] $m[4]";
            $dt = \DateTime::createFromFormat($format, $candidate);
            if ($dt) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $dt = \DateTime::createFromFormat('m/d/Y H:i:s', $clean)
            ?: \DateTime::createFromFormat('Y-m-d H:i:s', $clean);

        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    }

    private function toFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Handle strings with comma decimal and percentage.
        if (is_string($value)) {
            $normalized = str_replace(['%', ' '], '', $value);
            $normalized = str_replace(',', '.', $normalized);

            if (!is_numeric($normalized)) {
                return null;
            }

            return (float) $normalized;
        }

        return (float) $value;
    }

    private function toInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = is_string($value)
            ? preg_replace('/[^0-9\\-]/', '', $value)
            : $value;

        if ($normalized === '' || $normalized === null) {
            return null;
        }

        return (int) $normalized;
    }

    private function mapStatus(?string $status, $raw): ?string
    {
        $status = $status ? strtolower(trim($status)) : null;
        $rawInt = is_numeric($raw) ? (int) $raw : null;

        // Map numeric raw codes first (PRTG internal codes).
        if ($rawInt !== null) {
            return match ($rawInt) {
                3 => 'up',
                4 => 'warning',
                5 => 'down',
                6, 7 => 'paused',
                default => 'unknown',
            };
        }

        // Fallback to text mapping.
        return match ($status) {
            'up', 'ok' => 'up',
            'warning', 'unusual' => 'warning',
            'down' => 'down',
            'paused', 'paused by user', 'paused by dependency', 'paused until' => 'paused',
            default => $status,
        };
    }

    private function buildCharts(
        string $endpoint,
        string $username,
        string $passhash,
        string $sensorId,
        bool $verifyTls,
        int $timeout
    ): array {
        $timeZoneName = $this->config?->get('timeZone') ?: 'UTC';
        $tz = new \DateTimeZone($timeZoneName);
        $now = new \DateTimeImmutable('now', $tz);

        $charts = [
            'h2' => $this->buildChartUrl($endpoint, $username, $passhash, $sensorId, $now, '-2 hours', 300),
            'd2' => $this->buildChartUrl($endpoint, $username, $passhash, $sensorId, $now, '-48 hours', 3600),
            'd30' => $this->buildChartUrl($endpoint, $username, $passhash, $sensorId, $now, '-720 hours', 86400),
            'd365' => $this->buildChartUrl($endpoint, $username, $passhash, $sensorId, $now, '-8760 hours', 86400),
        ];

        $rendered = [];
        $fetchTimeout = max(60, $timeout);

        foreach ($charts as $key => $url) {
            $dataUri = $this->fetchChartImage($url, $verifyTls, $fetchTimeout);
            $rendered[$key] = sprintf(
                '<div class="text-center">%s</div>',
                $dataUri
                    ? '<img src="' . $dataUri . '" alt="PRTG ' . htmlspecialchars($key, ENT_QUOTES) . '" style="max-width:100%; border:1px solid #e5e7eb; border-radius:4px;" />'
                    : '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener">Abrir gráfico ' . htmlspecialchars($key, ENT_QUOTES) . '</a>'
            );
        }

        return $rendered;
    }

    private function buildChartUrl(
        string $endpoint,
        string $username,
        string $passhash,
        string $sensorId,
        \DateTimeImmutable $now,
        string $startModifier,
        int $avg
    ): string {
        $end = $now->format('Y-m-d-H-i-00');
        $start = $now->modify($startModifier)->format('Y-m-d-H-i-00');

        $base = rtrim($endpoint, '/') . '/chart.png?graphid=-1&width=620&height=220';

        $params = [
            'sdate' => $start,
            'edate' => $end,
            'avg' => $avg,
            'username' => $username,
            'passhash' => $passhash,
            'id' => $sensorId
        ];

        return $base . '&' . http_build_query($params);
    }

    private function fetchChartImage(string $url, bool $verifyTls, int $timeout): ?string
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);

        curl_close($curl);

        if ($response === false || $httpCode >= 400 || $httpCode === 0) {
            $snippet = substr(trim(strip_tags($response ?: '')), 0, 180);
            $this->logChartFailure($url, $httpCode, $curlError, $snippet);
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($response);
    }

    private function logChartFailure(string $url, int $httpCode, string $curlError, string $snippet): void
    {
        $line = sprintf(
            "[%s] code=%d curlError=%s snippet=%s url=%s\n",
            date('c'),
            $httpCode,
            $curlError ?: '-',
            $snippet ?: '-',
            $url
        );

        $appRoot = realpath(__DIR__ . '/../../../../');
        if ($appRoot) {
            $logFile = $appRoot . '/data/logs/prtg-charts.log';
            @file_put_contents($logFile, $line, FILE_APPEND);
        }
    }

    private function cleanMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $clean = strip_tags($message);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        return $clean;
    }
}
