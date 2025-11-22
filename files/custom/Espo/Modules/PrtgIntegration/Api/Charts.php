<?php

namespace Espo\Modules\PrtgIntegration\Api;

use DateTimeImmutable;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

class Charts implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(Request $request): Response
    {
        $scope = $request->getRouteParam('scope');
        $id = $request->getRouteParam('id');
        $composer = new ResponseComposer();

        if (!$scope || !$id) {
            return $composer->json([
                'success' => false,
                'message' => 'Missing scope or id.'
            ], 400);
        }

        $config = $this->entityManager
            ->getRepository('PrtgConfig')
            ->where(['deleted' => false])
            ->findOne();

        if (!$config) {
            return $composer->json([
                'success' => false,
                'message' => 'PRTG config not found.'
            ], 404);
        }

        $entity = $this->entityManager->getEntity($scope, $id);

        if (!$entity) {
            throw new NotFound("Entity $scope not found.");
        }

        // For Prtg entity use its own sensorId; otherwise use configured field.
        if ($scope === 'Prtg') {
            $sensorField = 'sensorId';
        } else {
            $sensorField = $config->get('sensorField') ?: 'idSensor';
        }

        $sensorId = trim((string) $entity->get($sensorField));

        if ($sensorId === '') {
            return $composer->json([
                'success' => false,
                'message' => 'Sensor ID not provided.'
            ], 400);
        }

        $endpoint = $this->normalizeEndpoint((string) $config->get('endpoint'));
        $username = trim((string) $config->get('username'));
        $passhash = trim((string) $config->get('passhash'));
        $verifyTls = (bool) $config->get('verifyTls');
        $timeout = (int) ($config->get('timeout') ?? 15);
        $timeout = $timeout > 0 ? $timeout : 15;

        if (!$endpoint || !$username || !$passhash) {
            return $composer->json([
                'success' => false,
                'message' => 'PRTG credentials/config incomplete.'
            ], 400);
        }

        $now = new DateTimeImmutable();
        $chart2h = $this->buildChartUrl($endpoint, $username, $passhash, $sensorId, $now, '-2 hours', 300);
        $chart2d = $this->buildChartUrl($endpoint, $username, $passhash, $sensorId, $now, '-48 hours', 3600);
        $chart30d = $this->buildChartUrl($endpoint, $username, $passhash, $sensorId, $now, '-720 hours', 86400);

        // Fetch images server-side to bypass browser SSL trust/mixed-content issues.
        $charts = [
            'h2' => $chart2h,
            'd2' => $chart2d,
            'd30' => $chart30d
        ];

        $rendered = [];
        $failed = [];

        foreach ($charts as $key => $url) {
            $dataUri = $this->fetchChartImage($url, $verifyTls, $timeout);
            if ($dataUri !== null) {
                $rendered[$key] = $dataUri;
            } else {
                $failed[] = $key;
            }
        }

        if (count($rendered) === 0) {
            return $composer->json([
                'success' => false,
                'message' => 'Não foi possível carregar gráficos do PRTG (h2/d2/d30).'
            ], 502);
        }

        return $composer->json([
            'success' => true,
            'charts' => $rendered,
            'failed' => $failed
        ]);
    }

    private function buildChartUrl(
        string $endpoint,
        string $username,
        string $passhash,
        string $sensorId,
        DateTimeImmutable $now,
        string $startModifier,
        int $avg
    ): string {
        $end = $this->formatDate($now);
        $start = $this->formatDate($now->modify($startModifier));

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

    private function formatDate(DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d-H-i-00');
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

    private function fetchChartImage(string $url, bool $verifyTls, int $timeout): ?string
    {
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

        if ($response === false || $httpCode >= 400 || $httpCode === 0) {
            $snippet = $curlError ?: "HTTP $httpCode";
            $this->log->warning("PRTG chart fetch failed: {$snippet} ({$url})");
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($response);
    }
}
