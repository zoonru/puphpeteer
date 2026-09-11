<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

/**
 * [upstream-generated]
 * upstream-id: class:HTTPResponse
 * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/HTTPResponse.ts#L28 Upstream
 * [/upstream-generated]
 */
class HTTPResponse
{
    private string $url;
    private int $status;
    /** @var array<string, string> */
    private array $headers;
    /** @var array<string, mixed>|null */
    private ?array $securityDetails;

    /** @param array<string, mixed> $response @internal */
    public function __construct(array $response)
    {
        $this->url = (string) $response['url'];
        $this->status = (int) $response['status'];
        /** @var array<string, string> $headers */
        $headers = $response['headers'] ?? [];
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        /** @var array<string, mixed>|null $securityDetails */
        $securityDetails = $response['securityDetails'] ?? null;
        $this->securityDetails = $securityDetails;
    }

}
