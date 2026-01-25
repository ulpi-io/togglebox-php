<?php

declare(strict_types=1);

namespace ToggleBox\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ToggleBox\Exceptions\NetworkException;

class HttpClient
{
    private Client $client;

    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        array $options = [],
    ) {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($apiKey) {
            $headers['X-API-Key'] = $apiKey;
        }

        $this->client = new Client(array_merge([
            'base_uri' => rtrim($baseUrl, '/'),
            'headers' => $headers,
            'timeout' => 30,
        ], $options));
    }

    /**
     * @throws NetworkException
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $response = $this->client->get($path, [
                'query' => $query,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new NetworkException(
                "Failed to fetch from {$path}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * @throws NetworkException
     */
    public function post(string $path, array $data = []): array
    {
        try {
            $response = $this->client->post($path, [
                'json' => $data,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new NetworkException(
                "Failed to post to {$path}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
