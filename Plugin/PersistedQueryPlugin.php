<?php

declare(strict_types=1);

namespace Danslo\Apq\Plugin;

use Danslo\Apq\Model\Cache\Type\Apq;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\GraphQl\Controller\GraphQl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\ScopeInterface;

class PersistedQueryPlugin
{
    private $scopeConfig;
    private $jsonSerializer;
    private $cache;
    private $httpResponse;
    private $jsonFactory;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $jsonSerializer,
        CacheInterface $cache,
        HttpResponse $httpResponse,
        JsonFactory $jsonFactory
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->jsonSerializer = $jsonSerializer;
        $this->cache = $cache;
        $this->httpResponse = $httpResponse;
        $this->jsonFactory = $jsonFactory;
    }

    public function aroundDispatch(GraphQl $subject, callable $proceed, RequestInterface $request): ResponseInterface
    {
        $data = $this->getDataFromRequest($request);

        $persistedQueryHash = $this->getQueryHashFromRequestData($data);
        if (isset($data['query']) || $persistedQueryHash === null) {
            return $proceed($request);
        }

        $query = $this->cache->load($this->getCacheKeyFromQueryHash($persistedQueryHash));
        if ($query === false) {
            $this->setPersistedQueryNotFoundResponse($request);
            return $this->httpResponse;
        }

        $this->setQueryOnRequest($request, $query);

        return $proceed($request);
    }

    public function afterDispatch(
        GraphQl $subject,
        ResponseInterface $result,
        RequestInterface $request
    ): ResponseInterface {
        $data = $this->getDataFromRequest($request);

        $persistedQueryHash = $this->getQueryHashFromRequestData($data);

        if (!isset($data['query'])) {
            return $result;
        }

        if ($persistedQueryHash && hash('sha256', $data['query']) !== $persistedQueryHash) {
            $result->setHttpResponseCode($request->isPost()
                ? $this->scopeConfig->getValue('apq/general/invalid_sha_http_code_post', ScopeInterface::SCOPE_STORES) ?? 500
                : $this->scopeConfig->getValue('apq/general/invalid_sha_http_code_get', ScopeInterface::SCOPE_STORES) ?? 400
            );
            $result->setBody('provided sha does not match query');
            return $result;
        }

        $persistedQueryHash = hash('sha256', $data['query']);

        $cacheKey = $this->getCacheKeyFromQueryHash($persistedQueryHash);
        if ($this->cache->load($cacheKey) === false) {
            $this->cache->save($data['query'], $cacheKey, [Apq::CACHE_TAG]);
        }

        return $result;
    }

    private function setPersistedQueryNotFoundResponse(RequestInterface $request): void
    {
        $jsonResult = $this->jsonFactory->create();

        // apollo-server responds with 400 for GET and 500 for POST when no query is found
        $jsonResult->setHttpResponseCode($request->isPost()
            ? $this->scopeConfig->getValue('apq/general/query_not_found_http_code_post', ScopeInterface::SCOPE_STORES) ?? 500
            : $this->scopeConfig->getValue('apq/general/query_not_found_http_code_get', ScopeInterface::SCOPE_STORES) ?? 400
        );
        $jsonResult->setData([
            'errors' => [
                [
                    'message' => 'PersistedQueryNotFound',
                    'extensions' => ['code' => 'PERSISTED_QUERY_NOT_FOUND']
                ]
            ]
        ]);

        $jsonResult->renderResult($this->httpResponse);
    }

    private function getDataFromRequest(RequestInterface $request): array
    {
        $data = [];

        if ($request->isPost()) {
            $data = $this->jsonSerializer->unserialize($request->getContent());
        } elseif ($request->isGet()) {
            $data = $request->getParams();
            $data['extensions'] = isset($data['extensions']) ?
                $this->jsonSerializer->unserialize($data['extensions']) : null;
        }

        return $data;
    }

    private function getCacheKeyFromQueryHash(string $queryHash): string
    {
        return Apq::TYPE_IDENTIFIER . '_' . $queryHash;
    }

    private function getQueryHashFromRequestData(array $requestData): ?string
    {
        return $requestData['extensions']['persistedQuery']['sha256Hash'] ?? null;
    }

    private function setQueryOnRequest(RequestInterface $request, string $query): void
    {
        if ($request->isPost()) {
            $data = $this->jsonSerializer->unserialize($request->getContent());
            $data['query'] = $query;
            $request->setContent($this->jsonSerializer->serialize($data));
        } elseif ($request->isGet()) {
            $params = $request->getParams();
            $params['query'] = $query;
            $request->setParams($params);
        }
    }
}
