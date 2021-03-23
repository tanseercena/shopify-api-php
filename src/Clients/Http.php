<?php

declare(strict_types=1);

namespace Shopify\Clients;

use CurlHandle;
use Shopify\Exception\HttpRequestException;

class Http
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_DELETE = 'DELETE';

    public const DATA_TYPE_JSON = 'application/json';
    public const DATA_TYPE_URL_ENCODED = 'application/x-www-form-urlencoded';

    private const RETRIABLE_STATUS_CODES = [429, 500];
    private const RETRY_DEFAULT_TIME = 1; // 1 second

    public function __construct(private string $domain)
    {
    }

    /**
     * Makes a GET request to this client's domain.
     *
     * @param string $path    The URL path to request
     * @param array  $headers Any extra headers to send along with the request
     * @param int|nulltries   How many times to attempt the request
     *
     * @return HttpResponse
     */
    public function get(string $path, array $headers = [], ?int $tries = null): HttpResponse
    {
        return $this->request(
            path: $path,
            method: self::METHOD_GET,
            headers: $headers,
            tries: $tries,
        );
    }

    /**
     * Makes a POST request to this client's domain.
     *
     * @param string       $path     The URL path to request
     * @param string|array $body     The body of the request
     * @param string       $dataType The data type to expect in the response
     * @param array        $headers  Any extra headers to send along with the request
     * @param int|null     $tries    How many times to attempt the request
     *
     * @return HttpResponse
     */
    public function post(
        string $path,
        string | array $body,
        string $dataType = self::DATA_TYPE_JSON,
        array $headers = [],
        ?int $tries = null
    ): HttpResponse {
        return $this->request(
            path: $path,
            method: self::METHOD_POST,
            dataType: $dataType,
            body: $body,
            headers: $headers,
            tries: $tries,
        );
    }

    /**
     * Makes a PUT request to this client's domain.
     *
     * @param string       $path     The URL path to request
     * @param string|array $body     The body of the request
     * @param string       $dataType The data type to expect in the response
     * @param array        $headers  Any extra headers to send along with the request
     * @param int|null     $tries    How many times to attempt the request
     *
     * @return HttpResponse
     */
    public function put(
        string $path,
        string | array $body,
        string $dataType = self::DATA_TYPE_JSON,
        array $headers = [],
        ?int $tries = null
    ): HttpResponse {
        return $this->request(
            path: $path,
            method: self::METHOD_PUT,
            dataType: $dataType,
            body: $body,
            headers: $headers,
            tries: $tries,
        );
    }

    /**
     * Makes a DELETE request to this client's domain.
     *
     * @param string $path    The URL path to request
     * @param array  $headers Any extra headers to send along with the request
     * @param int|nulltries   How many times to attempt the request
     *
     * @return HttpResponse
     */
    public function delete(string $path, array $headers = [], ?int $tries = null): HttpResponse
    {
        return $this->request(
            path: $path,
            method: self::METHOD_DELETE,
            headers: $headers,
            tries: $tries,
        );
    }

    /**
     * Returns the default amount of time to wait for between request retries.
     *
     * @return int
     */
    public function getDefaultRetrySeconds(): int
    {
        return self::RETRY_DEFAULT_TIME;
    }

    /**
     * Internally handles the logic for making requests.
     *
     * @param string   $path     The path to query
     * @param string   $method   The method to use
     * @param string   $dataType The data type of the request
     * @param string   $body     The request body to send
     * @param array    $headers  Any extra headers to send along with the request
     * @param int|null $tries    How many times to attempt the request
     *
     * @return HttpResponse
     */
    private function request(
        string $path,
        string $method,
        string $dataType = self::DATA_TYPE_JSON,
        string | array $body = null,
        array $headers = [],
        ?int $tries = null,
    ): HttpResponse {
        $maxTries = $tries ?? 1;
        $url = "{$this->domain}/$path";

        $ch = curl_init($url);
        $this->setCurlOption($ch, CURLOPT_RETURNTRANSFER, true);
        switch ($method) {
            case self::METHOD_POST:
                $this->setCurlOption($ch, CURLOPT_POST, true);
                break;
            case self::METHOD_PUT:
                $this->setCurlOption($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                break;
            case self::METHOD_DELETE:
                $this->setCurlOption($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
        }

        $version = require_once dirname(__FILE__) . '/../version.php';
        $userAgent = "Shopify Admin API Library for PHP v{$version}";
        if (isset($headers['User-Agent'])) {
            $userAgent = "{$headers['User-Agent']} | $userAgent";
            unset($headers['User-Agent']);
        }
        $this->setCurlOption($ch, CURLOPT_USERAGENT, $userAgent);

        if ($body) {
            if (is_string($body)) {
                $bodyString = $body;
            } else {
                switch ($dataType) {
                    case self::DATA_TYPE_JSON:
                        $bodyString = json_encode($body);
                        break;
                    case self::DATA_TYPE_URL_ENCODED:
                        $bodyString = http_build_query($body);
                        break;
                }
            }
            $this->setCurlOption($ch, CURLOPT_POSTFIELDS, $bodyString);

            $headers = array_merge(
                [
                    'Content-Type' => $dataType,
                    'Content-Length' => mb_strlen($bodyString),
                ],
                $headers,
            );
        }

        $headerOpts = [];
        foreach ($headers as $header => $headerValue) {
            $headerOpts[] = "{$header}: {$headerValue}";
        }
        $this->setCurlOption($ch, CURLOPT_HTTPHEADER, $headerOpts);

        $currentTries = 0;
        do {
            $currentTries++;

            $curlResponse = $this->sendCurlRequest($ch);
            if ($curlError = $this->getCurlError($ch)) {
                throw new HttpRequestException("HTTP request failed: $curlError");
            }

            $response = new HttpResponse();
            $response->statusCode = $curlResponse['statusCode'];
            $response->headers = $curlResponse['headers'];
            $response->body = '';

            if ($curlResponse['body']) {
                switch ($dataType) {
                    case self::DATA_TYPE_JSON:
                        $response->body = json_decode($curlResponse['body'], true);
                        break;
                    case self::DATA_TYPE_URL_ENCODED:
                        parse_str($curlResponse['body'], $response->body);
                        break;
                }
            }

            if (in_array($curlResponse['statusCode'], self::RETRIABLE_STATUS_CODES)) {
                $retryAfter = $curlResponse['headers']['Retry-After'] ?? $this->getDefaultRetrySeconds();
                usleep($retryAfter * 1000000);
            } else {
                break;
            }
        } while ($currentTries < $maxTries);

        return $response;
    }

    /**
     * Sets the given option in the cURL resource.
     *
     * @param CurlHandle $ch     The curl resource
     * @param int|null   $option The option to set
     * @param mixed      $value  The value for the option
     *
     * Note: this is only public so tests can override it.
     * @codeCoverageIgnore We can't test this method without making actual cURL requests
     */
    public function setCurlOption(CurlHandle &$ch, int $option, mixed $value)
    {
        if (defined('RUNNING_SHOPIFY_TESTS')) {
            throw new \Exception("Attempted to make a real HTTP request while running tests!");
        }

        curl_setopt($ch, $option, $value);
    }

    /**
     * Actually fires the cURL request.
     *
     * @param CurlHandle $ch The curl resource
     *
     * @return array
     *
     * Note: this is only public so tests can override it.
     * @codeCoverageIgnore We can't test this method without making actual cURL requests
     */
    public function sendCurlRequest(CurlHandle &$ch): ?array
    {
        if (defined('RUNNING_SHOPIFY_TESTS')) {
            throw new \Exception("Attempted to make a real HTTP request while running tests!");
        }

        $responseHeaders = [];
        $this->setCurlOption(
            $ch,
            CURLOPT_HEADERFUNCTION,
            function ($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) {
                    return $len;
                }

                $responseHeaders[strtolower(trim($header[0]))][] = trim($header[1]);

                return $len;
            }
        );

        $responseBody = curl_exec($ch);
        if (!$responseBody) {
            return null;
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [
            'statusCode' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }

    /**
     * Retrieves the last error from the cURL resource.
     *
     * @param CurlHandle $ch     The curl resource
     *
     * Note: this is only public so tests can override it.
     * @codeCoverageIgnore We can't test this method without making actual cURL requests
     */
    public function getCurlError(CurlHandle &$ch): ?string
    {
        if (defined('RUNNING_SHOPIFY_TESTS')) {
            throw new \Exception("Attempted to make a real HTTP request while running tests!");
        }

        return curl_error($ch);
    }
}
