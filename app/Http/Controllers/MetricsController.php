<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * MetricsController
 *
 * Exposes the Prometheus /metrics endpoint in text format.
 */
class MetricsController extends Controller
{
    public function __construct(private readonly CollectorRegistry $registry) {}

    public function show(): Response
    {
        $renderer = new RenderTextFormat;
        $result = $renderer->render($this->registry->getMetricFamilySamples());

        return response($result, 200)
            ->header('Content-Type', RenderTextFormat::MIME_TYPE);
    }
}
