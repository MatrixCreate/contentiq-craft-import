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
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function fetchExport(): array
    {
        $settings = ContentIQImporter::$plugin->getSettings();

        $url = rtrim(App::parseEnv($settings->contentiqUrl), '/');
        $key = App::parseEnv($settings->apiKey);

        if ($url === '' || $key === '') {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'ContentIQ API is not fully configured. Set URL and API key in plugin settings.',
            ];
        }

        $endpoint = "{$url}/api/v1/export";

        try {
            $response = Craft::createGuzzleClient()->request('GET', $endpoint, [
                RequestOptions::HEADERS => [
                    'Accept'        => 'application/json',
                    'Authorization' => "Bearer {$key}",
                ],
                RequestOptions::TIMEOUT         => 120,
                RequestOptions::CONNECT_TIMEOUT  => 10,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'ContentIQ returned invalid JSON: ' . json_last_error_msg(),
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
