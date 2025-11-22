<?php

namespace Espo\Modules\PrtgIntegration\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Modules\PrtgIntegration\Services\Prtg as PrtgService;

class Sync implements Action
{
    public function __construct(private PrtgService $prtgService)
    {
    }

    public function process(Request $request): Response
    {
        $id = $request->getRouteParam('id');
        $composer = new ResponseComposer();

        if (!$id) {
            return $composer->json([
                'success' => false,
                'message' => 'Missing id.'
            ], 400);
        }

        $result = $this->prtgService->syncById((string) $id);

        return $composer->json($result, $result['success'] ? 200 : 400);
    }
}
