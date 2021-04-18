<?php

declare(strict_types=1);

namespace Danslo\Aqp\Test\Integration;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\GraphQl\Controller\GraphQl;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\Request;
use PHPUnit\Framework\TestCase;

class PersistedQueryTest extends TestCase
{
    private const GOD_QUERY = '{__typename}';

    private $om;
    private $serializer;
    private $graphqlController;

    protected function setUp(): void
    {
        $this->om = ObjectManager::getInstance();
        $this->serializer = $this->om->create(Json::class);
        $this->graphqlController = $this->om->get(GraphQl::class);
        $this->om->get(TypeListInterface::class)->cleanType('apq');
    }

    private function createGetRequestWithPersistedQuery(string $query): Request
    {
        return $this->om->create(Request::class)->setParam(
            'extensions',
            $this->serializer->serialize(['persistedQuery' => ['sha256Hash' => hash('sha256', $query)]])
        );
    }

    private function createPostRequestWithPersistedQuery(string $query): Request
    {
        return $this->om->create(Request::class)
            ->setMethod('post')
            ->setContent(
                $this->serializer->serialize(['extensions' => ['persistedQuery' => ['sha256Hash' => $query]]])
            );
    }

    public function testNotFoundGetQueryReturnsCorrectHttpStatus()
    {
        $request = $this->createGetRequestWithPersistedQuery('def');
        $result = $this->graphqlController->dispatch($request);
        $this->assertEquals(400, $result->getHttpResponseCode());
    }

    public function testNotFoundPostQueryReturnsCorrectHttpStatus()
    {
        $request = $this->createPostRequestWithPersistedQuery('abc');
        $result = $this->graphqlController->dispatch($request);
        $this->assertEquals(500, $result->getHttpResponseCode());
    }

    public function testResponseForHashAndQueryThatDoNotMatch()
    {
        $request = $this->om->create(Request::class);
        $request->setParam(
            'extensions',
            $this->serializer->serialize(['persistedQuery' => ['sha256Hash' => 'foobar']])
        );
        $request->setParam('query', self::GOD_QUERY);

        $result = $this->graphqlController->dispatch($request);

        $this->assertEquals(400, $result->getHttpResponseCode());
        $this->assertEquals('provided sha does not match query', $result->getContent());
    }

    public function testQueryIsCached()
    {
        $request = $this->createGetRequestWithPersistedQuery(self::GOD_QUERY);
        $request->setParam('query', self::GOD_QUERY);
        $this->graphqlController->dispatch($request);

        $request = $this->createGetRequestWithPersistedQuery(self::GOD_QUERY);
        $result = $this->graphqlController->dispatch($request);
        $this->assertEquals(200, $result->getHttpResponseCode());
        $this->assertEquals('{"data":{"__typename":"Query"}}', $result->getBody());
    }
    
    /**
     * @magentoDataFixture Magento/Store/_files/second_store.php
     */
    public function testQueryIsNotCachedAcrossStoreViews()
    {
        $request = $this->createGetRequestWithPersistedQuery('def');
        $request->setHeaders(['store'=>'default']);
        $result = $this->graphqlController->dispatch($request);
        $this->assertEquals(400, $result->getHttpResponseCode());
        
        $request = $this->createGetRequestWithPersistedQuery('def');
        $request->setHeaders(['store'=>'fixture_second_store']);
        $result = $this->graphqlController->dispatch($request);
        $this->assertEquals(400, $result->getHttpResponseCode());
    }
}
