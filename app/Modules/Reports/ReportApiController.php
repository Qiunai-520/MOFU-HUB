<?php

declare(strict_types=1);

namespace Mofu\Modules\Reports;

use Mofu\Core\Http\ApiController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ReportApiController extends ApiController
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function store(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        $this->reports->submit((int) $user['id'], $this->input($request));

        return $this->ok($response, ['submitted' => true], [], 201);
    }
}
