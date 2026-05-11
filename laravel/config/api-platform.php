<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use ApiPlatform\Metadata\UrlGeneratorInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\Serializer\NameConverter\SnakeCaseToCamelCaseNameConverter;

return [
    'title' => 'bexlogs API',
    'description' => 'Read-only REST API exposing org-scoped bexlogs resources. Authenticate with a Sanctum Personal Access Token (Authorization: Bearer ...).',
    'version' => '1.0.0',
    'show_webby' => true,

    'routes' => [
        'domain' => null,
        // Global middleware applied to every API Platform route, including
        // the Swagger UI / OpenAPI JSON spec at /api/docs. `auth:sanctum`
        // accepts a personal access token (Authorization: Bearer ...) AND
        // falls back to the configured session guard for stateful requests
        // — so a logged-in operator can open /api/docs in the browser and
        // still get the Swagger UI sandbox, while CLI/curl clients hit it
        // with a token. Per-user throttle at 60 req/min keeps a leaked
        // token from being a free-for-all.
        'middleware' => ['auth:sanctum', 'throttle:60,1'],
    ],

    'resources' => [
        app_path('Models'),
        app_path('ApiResource'),
    ],

    'formats' => [
        'jsonld' => ['application/ld+json'],
        'json' => ['application/json'],
        // 'jsonapi' => ['application/vnd.api+json'],
        // 'csv' => ['text/csv'],
    ],

    'patch_formats' => [
        'json' => ['application/merge-patch+json'],
    ],

    // When true, 'required' validation rules are replaced with 'sometimes'
    // on PATCH operations, allowing partial updates without requiring all fields.
    'partial_patch_validation' => false,

    'docs_formats' => [
        'jsonld' => ['application/ld+json'],
        // 'jsonapi' => ['application/vnd.api+json'],
        'jsonopenapi' => ['application/vnd.openapi+json'],
        'html' => ['text/html'],
    ],

    'error_formats' => [
        'jsonproblem' => ['application/problem+json'],
    ],

    'defaults' => [
        'pagination_enabled' => true,
        'pagination_partial' => false,
        'pagination_client_enabled' => false,
        'pagination_client_items_per_page' => false,
        'pagination_client_partial' => false,
        'pagination_items_per_page' => 30,
        'pagination_maximum_items_per_page' => 30,
        'route_prefix' => '/api',
        'middleware' => [],
    ],

    'pagination' => [
        'page_parameter_name' => 'page',
        'enabled_parameter_name' => 'pagination',
        'items_per_page_parameter_name' => 'itemsPerPage',
        'partial_parameter_name' => 'partial',
    ],

    'graphql' => [
        'enabled' => false,
        'nesting_separator' => '__',
        'introspection' => ['enabled' => true],
        'max_query_complexity' => 500,
        'max_query_depth' => 200,
        // 'middleware' => null,
    ],

    'graphiql' => [
        // 'enabled' => true,
        // 'domain' => null,
        // 'middleware' => null,
    ],

    // set to null if you want to keep snake_case
    'name_converter' => SnakeCaseToCamelCaseNameConverter::class,

    'exception_to_status' => [
        AuthenticationException::class => 401,
        AuthorizationException::class => 403,
    ],

    'redoc' => [
        'enabled' => true,
    ],

    'scalar' => [
        'enabled' => true,
        'extra_configuration' => [],
    ],

    'swagger_ui' => [
        'enabled' => true,
        // Swagger UI's "Authorize" dialog. The Personal Access Token scheme
        // matches what the API itself accepts: a Sanctum-issued plaintext
        // token sent as `Authorization: Bearer <token>`. The token format
        // is opaque (Sanctum's "<id>|<plaintext>" string, not JWT) so we
        // deliberately omit `bearerFormat`.
        'http_auth' => [
            'Personal Access Token' => [
                'scheme' => 'bearer',
            ],
        ],
        // Persist the Authorize value across reloads so an operator who
        // pastes their token once doesn't have to repaste it every time
        // the docs page reloads.
        'persist_authorization' => true,
    ],

    // 'openapi' => [
    //     'tags' => [],
    // ],

    'url_generation_strategy' => UrlGeneratorInterface::ABS_PATH,

    'serializer' => [
        'hydra_prefix' => false,
        // 'datetime_format' => \DateTimeInterface::RFC3339,
    ],

    // we recommend using "file" or "acpu"
    'cache' => 'file',

    // MCP (Model Context Protocol) configuration
    'mcp' => [
        'enabled' => true,
    ],

    // install `api-platform/http-cache`
    // 'http_cache' => [
    //     'etag' => false,
    //     'max_age' => null,
    //     'shared_max_age' => null,
    //     'vary' => null,
    //     'public' => null,
    //     'stale_while_revalidate' => null,
    //     'stale_if_error' => null,
    //     'invalidation' => [
    //         'urls' => [],
    //         'scoped_clients' => [],
    //         'max_header_length' => 7500,
    //         'request_options' => [],
    //         'purger' => ApiPlatform\HttpCache\SouinPurger::class,
    //     ],
    // ],

    'error_handler' => [
        'extend_laravel_handler' => true,
    ],
];
