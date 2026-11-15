<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Solaris Backend API'
)]
#[OA\Server(url: '/api')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum Personal Access Token'
)]
#[OA\OpenApi(openapi: '3.1.0')]
final class OpenApiSpec
{
}
