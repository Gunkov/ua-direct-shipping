<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Common\Http;

use Gunkov\UAShipping\Common\Exception\HttpException;

final class HttpClient
{
    private const DEFAULT_TIMEOUT = 15;

    /**
     * POST JSON request. Returns decoded body as array.
     *
     * @throws HttpException on transport-level error or non-2xx response.
     */
    public function post_json(string $url, array $payload, array $args = []): array
    {
        $defaults = [
            'timeout'     => self::DEFAULT_TIMEOUT,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept'       => 'application/json',
            ],
            'body'        => wp_json_encode($payload),
        ];

        $args = array_replace_recursive($defaults, $args);

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            throw new HttpException('Transport error: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new HttpException(sprintf('HTTP %d: %s', $code, substr($body, 0, 200)));
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new HttpException('Invalid JSON response: ' . substr($body, 0, 200));
        }

        return $decoded;
    }
}
