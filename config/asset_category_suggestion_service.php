<?php

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/asset_master_service.php';

if (!function_exists('asset_category_suggestion_allowed_map')) {
    function asset_category_suggestion_allowed_map(array $categories): array
    {
        $map = [];
        foreach ($categories as $category) {
            $key = trim((string)($category['category_key'] ?? ''));
            $label = trim((string)($category['category_label'] ?? ''));
            if ($key === '') {
                continue;
            }

            $map[asset_master_lowercase($key)] = $key;
            if ($label !== '') {
                $map[asset_master_lowercase($label)] = $key;
            }
        }

        return $map;
    }
}

if (!function_exists('asset_category_suggestion_match_allowed')) {
    function asset_category_suggestion_match_allowed(string $value, array $categories): ?string
    {
        $normalized = asset_master_lowercase(trim($value));
        $normalized = trim($normalized, " \t\n\r\0\x0B\"'`.,:;![]{}()");
        $allowedMap = asset_category_suggestion_allowed_map($categories);

        return $allowedMap[$normalized] ?? null;
    }
}

if (!function_exists('asset_category_suggestion_response_text')) {
    function asset_category_suggestion_response_text(array $response): string
    {
        if (!empty($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }

        foreach ((array)($response['output'] ?? []) as $item) {
            foreach ((array)($item['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                    return trim($content['text']);
                }
            }
        }

        return '';
    }
}

if (!function_exists('asset_category_suggest_from_web')) {
    function asset_category_suggest_from_web(string $assetName, array $categories): ?string
    {
        $assetName = asset_master_normalize_text($assetName);
        if ($assetName === '' || !preg_match('/\p{L}/u', $assetName) || asset_master_text_length($assetName) > 255) {
            return null;
        }

        $config = Config::getInstance();
        $provider = asset_master_lowercase(trim((string)$config->get('ASSET_CATEGORY_SUGGESTION_PROVIDER')));
        $apiKey = trim((string)$config->get('ASSET_CATEGORY_SUGGESTION_API_KEY'));
        $model = trim((string)$config->get('ASSET_CATEGORY_SUGGESTION_MODEL'));
        $endpoint = trim((string)$config->get('ASSET_CATEGORY_SUGGESTION_ENDPOINT'));

        if ($provider !== 'openai' || $apiKey === '' || $model === '' || $endpoint === '' || !function_exists('curl_init')) {
            return null;
        }

        $allowedCategories = [];
        foreach ($categories as $category) {
            $key = trim((string)($category['category_key'] ?? ''));
            $label = trim((string)($category['category_label'] ?? ''));
            if ($key !== '' && $label !== '') {
                $allowedCategories[] = $key . ' = ' . $label;
            }
        }

        if (empty($allowedCategories)) {
            return null;
        }

        $payload = [
            'model' => $model,
            'store' => false,
            'tools' => [['type' => 'web_search']],
            'input' => 'Use web search only as assistance. Classify this company asset: "' . $assetName . '". '
                . 'Return only one exact allowed category key, with no extra words. Allowed categories: '
                . implode('; ', $allowedCategories) . '.',
        ];

        $curl = curl_init($endpoint);
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $rawResponse = curl_exec($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($rawResponse) || $statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $response = json_decode($rawResponse, true);
        if (!is_array($response)) {
            return null;
        }

        return asset_category_suggestion_match_allowed(asset_category_suggestion_response_text($response), $categories);
    }
}
