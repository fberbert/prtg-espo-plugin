<?php

namespace Espo\Modules\PrtgIntegration\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\PrtgIntegration\Services\PrtgConfig as PrtgConfigService;
use Espo\Core\Utils\Log;

class TestConnection implements Action
{
    public function __construct(
        private PrtgConfigService $prtgConfigService,
        private Log $log
    ) {}

    public function process(Request $request): Response
    {
        $data = $request->getParsedBody() ?? [];
        // Ensure array (Espo may parse JSON body as stdClass).
        $data = is_array($data) ? $data : (array) $data;
        $composer = new ResponseComposer();

        try {
            $result = $this->prtgConfigService->testConnection($data);
        } catch (BadRequest $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage()
            ];

            if (!empty($data['id'])) {
                $this->prtgConfigService->saveTestResult((string) $data['id'], $result);
            }

            return $composer->json($result, 400);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() ?: 'Unexpected error';
            $this->log->error('PRTG testConnection failure: ' . $msg);

            $result = [
                'success' => false,
                'message' => $msg
            ];

            if (!empty($data['id'])) {
                $this->prtgConfigService->saveTestResult((string) $data['id'], $result);
            }

            return $composer->json($result, 500);
        }

        if (!empty($data['id'])) {
            $this->prtgConfigService->saveTestResult((string) $data['id'], $result);
        }

        return $composer->json($result);
    }
}
