<?php

/**
 * EduTracker shared API and environment configuration helper.
 * Provides centralized API URL resolution, environment variable access,
 * file view URL formatting, and unified cURL communication with Express.
 */

/**
 * Get the path to server/.env.
 */
function getEnvFilePath(): string
{
    return dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . "server"
        . DIRECTORY_SEPARATOR . ".env";
}

/**
 * Read and cache server/.env variables.
 */
function getEduTrackerEnv(): array
{
    static $env = null;

    if ($env !== null) {
        return $env;
    }

    $envPath = getEnvFilePath();

    if (!is_readable($envPath)) {
        $env = [];
        return $env;
    }

    $parsed = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    $env = is_array($parsed) ? $parsed : [];

    return $env;
}

/**
 * Get the configured API authentication key.
 * Checks system environment variables (Docker / Render) first,
 * then falls back to server/.env for local XAMPP.
 */
function getEduTrackerApiKey(): string
{
    $envVal = getenv("API_KEY");
    if ($envVal !== false && trim($envVal) !== "") {
        return trim($envVal);
    }
    if (!empty($_ENV["API_KEY"])) {
        return trim($_ENV["API_KEY"]);
    }
    if (!empty($_SERVER["API_KEY"])) {
        return trim($_SERVER["API_KEY"]);
    }

    $env = getEduTrackerEnv();
    return trim($env["API_KEY"] ?? "", " \t\n\r\0\x0B\"'");
}

/**
 * Get the base URL for internal Express API calls.
 * Checks system environment variables (API_BASE_URL) first,
 * then server/.env, and defaults to http://127.0.0.1:3000/api for local XAMPP.
 */
function getApiBaseUrl(): string
{
    static $baseUrl = null;

    if ($baseUrl !== null) {
        return $baseUrl;
    }

    $envVal = getenv("API_BASE_URL");
    if ($envVal !== false && trim($envVal) !== "") {
        $baseUrl = rtrim(trim($envVal), "/");
        return $baseUrl;
    }
    if (!empty($_ENV["API_BASE_URL"])) {
        $baseUrl = rtrim(trim($_ENV["API_BASE_URL"]), "/");
        return $baseUrl;
    }
    if (!empty($_SERVER["API_BASE_URL"])) {
        $baseUrl = rtrim(trim($_SERVER["API_BASE_URL"]), "/");
        return $baseUrl;
    }

    $env = getEduTrackerEnv();
    if (!empty($env["API_BASE_URL"])) {
        $baseUrl = rtrim(trim($env["API_BASE_URL"]), "/");
        return $baseUrl;
    }

    $baseUrl = "http://127.0.0.1:3000/api";
    return $baseUrl;
}

/**
 * Format a client-facing URL for viewing uploaded files (e.g. PDFs).
 * - Checks FILE_VIEW_BASE_URL from system environment variables (Render Node Service)
 *   or server/.env.
 * - Otherwise falls back to the Express host taken from API_BASE_URL.
 */
function getFileViewUrl(string $filePath): string
{
    $trimmed = trim($filePath);
    if ($trimmed === "") {
        return "";
    }

    $normalizedPath = "/" . ltrim($trimmed, "/");

    $viewBase = getenv("FILE_VIEW_BASE_URL");
    if ($viewBase === false || trim($viewBase) === "") {
        $viewBase = $_ENV["FILE_VIEW_BASE_URL"] ?? ($_SERVER["FILE_VIEW_BASE_URL"] ?? "");
    }
    if (!empty($viewBase)) {
        return rtrim(trim($viewBase), "/") . $normalizedPath;
    }

    $env = getEduTrackerEnv();
    if (!empty($env["FILE_VIEW_BASE_URL"])) {
        $base = rtrim(trim($env["FILE_VIEW_BASE_URL"]), "/");
        return $base . $normalizedPath;
    }

    // Fallback: the API base URL without its trailing "/api"
    // (e.g. http://127.0.0.1:3000/api -> http://127.0.0.1:3000).
    $apiRoot = preg_replace('#/api/?$#i', "", getApiBaseUrl());

    return rtrim($apiRoot, "/") . $normalizedPath;
}

/**
 * Call the Express API via cURL.
 * Supports relative endpoints (e.g. "/auth/login") or full URLs.
 * Supports GET, POST, PATCH, and DELETE methods.
 */
function callExpressAPI(
    string $urlOrEndpoint,
    array $data = [],
    string $method = "POST"
): array {
    if (!function_exists("curl_init")) {
        return [
            "success" => false,
            "message" => "PHP cURL is not enabled."
        ];
    }

    $apiKey = getEduTrackerApiKey();
    if ($apiKey === "") {
        return [
            "success" => false,
            "message" => "API_KEY is not configured (server/.env locally, Environment Variables on Render)."
        ];
    }

    $method = strtoupper($method);
    if (!in_array($method, ["GET", "POST", "PATCH", "DELETE"], true)) {
        return [
            "success" => false,
            "message" => "Unsupported API request method: " . $method
        ];
    }

    // Resolve relative endpoints against getApiBaseUrl()
    if (!preg_match('#^https?://#i', $urlOrEndpoint)) {
        $targetUrl = getApiBaseUrl() . "/" . ltrim($urlOrEndpoint, "/");
    } else {
        $targetUrl = $urlOrEndpoint;
    }

    $jsonData = json_encode($data);
    if ($jsonData === false) {
        return [
            "success" => false,
            "message" => "Unable to encode request data."
        ];
    }

    $ch = curl_init($targetUrl);
    if ($ch === false) {
        return [
            "success" => false,
            "message" => "Unable to initialize the API request."
        ];
    }

    $options = [
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
            "x-api-key: " . $apiKey
        ],
        CURLOPT_RETURNTRANSFER => true,
        // Render free services sleep; the first request can take ~50s.
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60
    ];

    if ($method === "POST") {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $jsonData;
    } elseif ($method === "PATCH") {
        $options[CURLOPT_CUSTOMREQUEST] = "PATCH";
        $options[CURLOPT_POSTFIELDS] = $jsonData;
    } elseif ($method === "DELETE") {
        $options[CURLOPT_CUSTOMREQUEST] = "DELETE";
        if ($data !== []) {
            $options[CURLOPT_POSTFIELDS] = $jsonData;
        }
    } else {
        $options[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        error_log("EduTracker API error: " . $error);
        curl_close($ch);

        return [
            "success" => false,
            "message" => "Cannot connect to the Express API. Check that Node.js is running."
        ];
    }

    curl_close($ch);

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return [
            "success" => false,
            "message" => "Invalid response from the Express API."
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            "success" => false,
            "message" => $result["message"] ?? ("The API request failed with HTTP " . $httpCode . ".")
        ];
    }

    return $result;
}
