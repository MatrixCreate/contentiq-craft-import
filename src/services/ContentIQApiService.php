<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use matrixcreate\contentiqimporter\ContentIQImporter;
use yii\base\Component;

/**
 * Handles communication with the ContentIQ API.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class ContentIQApiService extends Component
{
    /**
     * Fetches the full project export from the ContentIQ API.
     *
     * Calls GET {contentiqUrl}/api/v1/export with Bearer token authentication.
     * The project is identified server-side from the API key.
     *
     * @param int|null $timeout Optional request timeout override (seconds) —
     *   the Sync screen uses a shorter one than the queue job for page-load
     *   responsiveness. `null` keeps the default (120s).
     * @param int|null $connectTimeout Optional connect timeout override
     *   (seconds). `null` keeps the default (10s).
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function fetchExport(?int $timeout = null, ?int $connectTimeout = null): array
    {
        $settings = ContentIQImporter::$plugin->getSettings();

        $url = rtrim(App::parseEnv($settings->contentiqUrl), '/');
        $key = App::parseEnv($settings->apiKey);

        if ($url === '' || $key === '') {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'ContentiQ API is not fully configured. Set URL and API key in plugin settings.',
            ];
        }

        return $this->_get("{$url}/api/v1/export", $key, $timeout, $connectTimeout);
    }

    /**
     * Fetches the standalone globals payload from the ContentIQ API.
     *
     * Calls GET {contentiqUrl}/api/v1/globals with Bearer token authentication.
     * Used for a globals-only refresh; the batch export envelope remains the
     * primary path (its `globals` key already arrives with fetchExport()).
     *
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function fetchGlobals(): array
    {
        $settings = ContentIQImporter::$plugin->getSettings();

        $url = rtrim(App::parseEnv($settings->contentiqUrl), '/');
        $key = App::parseEnv($settings->apiKey);

        if ($url === '' || $key === '') {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'ContentiQ API is not fully configured. Set URL and API key in plugin settings.',
            ];
        }

        return $this->_get("{$url}/api/v1/globals", $key);
    }

    /**
     * Acknowledges pages that were genuinely imported this run.
     *
     * Calls POST {contentiqUrl}/api/v1/pages/ack with Bearer token
     * authentication and body `{"page_ids": [...]}`. ContentiQ's export GETs
     * are read-only — this is the CMS's explicit signal that a page was
     * actually written, so ContentiQ can retire its own pending-import state.
     *
     * An empty `$pageIds` is a no-op (no request made) — callers don't need
     * to guard the call themselves. Any failure (non-2xx, network error,
     * malformed response, unconfigured API) is reported via `success`/`error`
     * but is always non-fatal to the caller — never throws.
     *
     * @param int[] $pageIds ContentiQ page ids (document.id) to acknowledge.
     * @return array{success: bool, acknowledged: array, skipped: array, error: string|null}
     */
    public function ackPages(array $pageIds): array
    {
        if (empty($pageIds)) {
            return ['success' => true, 'acknowledged' => [], 'skipped' => [], 'error' => null];
        }

        $settings = ContentIQImporter::$plugin->getSettings();

        $url = rtrim(App::parseEnv($settings->contentiqUrl), '/');
        $key = App::parseEnv($settings->apiKey);

        if ($url === '' || $key === '') {
            return [
                'success'      => false,
                'acknowledged' => [],
                'skipped'      => [],
                'error'        => 'ContentiQ API is not fully configured. Set URL and API key in plugin settings.',
            ];
        }

        try {
            $response = Craft::createGuzzleClient()->request('POST', "{$url}/api/v1/pages/ack", [
                RequestOptions::HEADERS => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$key}",
                ],
                RequestOptions::JSON             => ['page_ids' => array_values(array_map('intval', $pageIds))],
                RequestOptions::TIMEOUT          => 15,
                RequestOptions::CONNECT_TIMEOUT  => 10,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return [
                    'success'      => false,
                    'acknowledged' => [],
                    'skipped'      => [],
                    'error'        => 'ContentiQ ack returned invalid JSON.',
                ];
            }

            return [
                'success'      => true,
                'acknowledged' => $data['acknowledged'] ?? [],
                'skipped'      => $data['skipped'] ?? [],
                'error'        => null,
            ];
        } catch (GuzzleException $e) {
            Craft::error("ContentIQ ack request failed: {$e->getMessage()}", __METHOD__);

            return [
                'success'      => false,
                'acknowledged' => [],
                'skipped'      => [],
                'error'        => 'Ack request failed: ' . $e->getMessage(),
            ];
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Performs an authenticated GET request and decodes the JSON response.
     *
     * @param string $endpoint
     * @param string $key
     * @param int|null $timeout Optional request timeout override (seconds).
     * @param int|null $connectTimeout Optional connect timeout override (seconds).
     * @return array{success: bool, data: array|null, error: string|null}
     */
    private function _get(string $endpoint, string $key, ?int $timeout = null, ?int $connectTimeout = null): array
    {
        try {
            $response = Craft::createGuzzleClient()->request('GET', $endpoint, [
                RequestOptions::HEADERS => [
                    'Accept'        => 'application/json',
                    'Authorization' => "Bearer {$key}",
                ],
                RequestOptions::TIMEOUT         => $timeout ?? 120,
                RequestOptions::CONNECT_TIMEOUT => $connectTimeout ?? 10,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'ContentiQ returned invalid JSON: ' . json_last_error_msg(),
                ];
            }

            return [
                'success' => true,
                'data'    => $data,
                'error'   => null,
            ];
        } catch (GuzzleException $e) {
            Craft::error("ContentIQ API request failed: {$e->getMessage()}", __METHOD__);

            return [
                'success' => false,
                'data'    => null,
                'error'   => 'API request failed: ' . $e->getMessage(),
            ];
        }
    }
}
