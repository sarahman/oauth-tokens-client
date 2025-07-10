<?php

namespace Sarahman\OauthTokensClient;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class GuzzleResponse implements ResponseInterface
{
    /**
     * @var ResponseInterface
     */
    private $response;

    /**
     * @param int $status
     * @param array $headers
     * @param string|StreamInterface|null $body
     * @param string $version
     * @param string|null $reason
     */
    public function __construct($status = 200, array $headers = [], $body = null, $version = '1.1', $reason = null)
    {
        $this->response = $this->createResponse($status, $headers, $body, $version, $reason);
    }

    /**
     * Creates a Guzzle response instance based on available Guzzle version.
     *
     * @param int $status HTTP status code.
     * @param array $headers HTTP headers.
     * @param string|StreamInterface|null $body Response body.
     * @param string $version HTTP protocol version.
     * @param string|null $reason Reason phrase (optional).
     * @return ResponseInterface
     * @throws RuntimeException If no compatible Guzzle response class is found.
     */
    private function createResponse($status, array $headers, $body, $version, $reason = null)
    {
        // Check for Guzzle 7.x/6.x (PSR-7)
        if (class_exists('GuzzleHttp\\Psr7\\Response')) {
            return new \GuzzleHttp\Psr7\Response($status, $headers, $body, $version, $reason);
        }

        // Check for Guzzle 5.x/4.x (older versions)
        if (class_exists('GuzzleHttp\\Message\\Response')) {
            return new \GuzzleHttp\Message\Response($status, $headers, $body, ['protocol_version' => $version]);
        }

        throw new RuntimeException('No compatible Guzzle HTTP Response class found');
    }

    /**
     * Static factory method for easier instantiation
     */
    public static function create($status = 200, array $headers = [], $body = null, $version = '1.1', $reason = null)
    {
        return new static($status, $headers, $body, $version, $reason);
    }

    // Delegate all PSR-7 ResponseInterface methods to the wrapped response

    public function getStatusCode()
    {
        return $this->response->getStatusCode();
    }

    public function withStatus($code, $reasonPhrase = '')
    {
        $clone = clone $this;
        $clone->response = $this->response->withStatus($code, $reasonPhrase);

        return $clone;
    }

    public function getReasonPhrase()
    {
        return $this->response->getReasonPhrase();
    }

    // PSR-7 MessageInterface methods

    public function getProtocolVersion()
    {
        return $this->response->getProtocolVersion();
    }

    public function withProtocolVersion($version)
    {
        $clone = clone $this;
        $clone->response = $this->response->withProtocolVersion($version);

        return $clone;
    }

    public function getHeaders()
    {
        return $this->response->getHeaders();
    }

    public function hasHeader($name)
    {
        return $this->response->hasHeader($name);
    }

    public function getHeader($name)
    {
        return $this->response->getHeader($name);
    }

    public function getHeaderLine($name)
    {
        return $this->response->getHeaderLine($name);
    }

    public function withHeader($name, $value)
    {
        $clone = clone $this;
        $clone->response = $this->response->withHeader($name, $value);

        return $clone;
    }

    public function withAddedHeader($name, $value)
    {
        $clone = clone $this;
        $clone->response = $this->response->withAddedHeader($name, $value);

        return $clone;
    }

    public function withoutHeader($name)
    {
        $clone = clone $this;
        $clone->response = $this->response->withoutHeader($name);

        return $clone;
    }

    public function getBody()
    {
        return $this->response->getBody();
    }

    public function withBody(StreamInterface $body)
    {
        $clone = clone $this;
        $clone->response = $this->response->withBody($body);
        return $clone;
    }

    public function getUnderlyingResponse()
    {
        return $this->response;
    }

    public static function getGuzzleVersion()
    {
        if (class_exists('GuzzleHttp\\Psr7\\Response')) {
            return 'psr7';
        }

        if (class_exists('GuzzleHttp\\Message\\Response')) {
            return 'message';
        }

        return 'unknown';
    }
}
