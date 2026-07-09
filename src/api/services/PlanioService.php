<?php
declare(strict_types=1);

namespace App\services;

use RuntimeException;

class PlanioService
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey
    ) {}

    public function status(): array
    {
        return $this->request('/users/current.json')['user'];
    }

    public function fetchIssue(int $id): array
    {
        return $this->request("/issues/{$id}.json")['issue'];
    }

    public function syncIssues(): array
    {
        $issues = [];
        $offset = 0;
        $limit  = 100;

        do {
            $page   = $this->request("/issues.json?assigned_to_id=me&status_id=open&limit=$limit&offset=$offset");
            $batch  = $page['issues'] ?? [];
            $issues = array_merge($issues, $batch);
            $offset += $limit;
        } while (count($batch) === $limit);

        return $issues;
    }

    private function request(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->apiKey . ':X',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Plan.io request to $path failed: $error");
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Plan.io API error $code for $path");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Plan.io returned an invalid JSON response for $path");
        }

        return $decoded;
    }
}
