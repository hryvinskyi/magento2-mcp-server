<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Webapi\Metadata;

use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Model\Source\Webapi\Metadata\ServiceMetadataReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\InMemoryCacheFrontend;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Reflection\TypeProcessor;
use Magento\Webapi\Model\Config\ClassReflector;
use Magento\Webapi\Model\ServiceMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Method and type metadata in typed shapes, from the built service metadata or, when Magento cannot build it, from
 * the one service class asked about; a failed build is remembered for a while in the module's cache type
 */
class ServiceMetadataReaderTest extends TestCase
{
    private const SERVICE = 'Magento\Catalog\Api\ProductRepositoryInterface';

    /**
     * Metadata of getList as Magento's class reflector describes it
     *
     * @var array<string, mixed>
     */
    private const GET_LIST = [
        'documentation' => 'Get product list',
        'interface' => [
            'in' => [
                'parameters' => [
                    'searchCriteria' => [
                        'type' => 'FrameworkSearchCriteriaInterface',
                        'required' => true,
                        'documentation' => '',
                    ],
                    'storeId' => ['type' => 'int', 'required' => false, 'documentation' => 'Store', 'default' => null],
                    'raw' => ['type' => null, 'required' => false, 'documentation' => ''],
                ],
            ],
        ],
    ];

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * @var TypeProcessor
     */
    private TypeProcessor $typeProcessor;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->typeProcessor = new TypeProcessor();
        $this->typeProcessor->setTypesData([
            'FrameworkSearchCriteriaInterface' => [
                'documentation' => 'Search criteria interface.',
                'parameters' => [
                    'filterGroups' => [
                        'type' => 'FrameworkSearchFilterGroup[]',
                        'required' => true,
                        'documentation' => 'A list of filter groups.',
                    ],
                    'pageSize' => ['type' => 'int', 'required' => false, 'documentation' => 'Page size.'],
                ],
            ],
        ]);
    }

    public function testAMethodIsFoundByItsRealNameAmongTheAliases(): void
    {
        $serviceMetadata = $this->createStub(ServiceMetadata::class);
        $serviceMetadata->method('getServiceName')->willReturn('catalogProductRepositoryV1');
        $serviceMetadata->method('getServiceMetadata')->willReturn([
            'methods' => [
                'save' => ['method' => 'save', 'documentation' => 'Save', 'interface' => []],
                'listAlias' => ['method' => 'getList'] + self::GET_LIST,
            ],
        ]);

        $method = $this->reader($serviceMetadata, $this->createStub(ClassReflector::class))
            ->methodOf(self::SERVICE, 'getList', 'V1');

        self::assertSame(
            [
                'documentation' => 'Get product list',
                'parameters' => [
                    'searchCriteria' => [
                        'type' => 'FrameworkSearchCriteriaInterface',
                        'required' => true,
                        'documentation' => '',
                    ],
                    'storeId' => ['type' => 'int', 'required' => false, 'documentation' => 'Store', 'default' => null],
                    'raw' => ['type' => 'anyType', 'required' => false, 'documentation' => ''],
                ],
            ],
            $method
        );
        self::assertSame([], $this->logger->records);
    }

    public function testAMethodTheMetadataDoesNotHoldIsNull(): void
    {
        $serviceMetadata = $this->createStub(ServiceMetadata::class);
        $serviceMetadata->method('getServiceMetadata')
            ->willThrowException(new \RuntimeException('Requested service is not available'));

        self::assertNull(
            $this->reader($serviceMetadata, $this->createStub(ClassReflector::class))
                ->methodOf(self::SERVICE, 'getList', 'V1')
        );
    }

    public function testATypeHasItsFieldsInSnakeCaseWithoutARequiredFlag(): void
    {
        $reader = $this->reader($this->createStub(ServiceMetadata::class), $this->createStub(ClassReflector::class));

        self::assertSame(
            [
                'documentation' => 'Search criteria interface.',
                'parameters' => [
                    'filter_groups' => [
                        'type' => 'FrameworkSearchFilterGroup[]',
                        'documentation' => 'A list of filter groups.',
                    ],
                    'page_size' => ['type' => 'int', 'documentation' => 'Page size.'],
                ],
            ],
            $reader->typeOf('FrameworkSearchCriteriaInterface')
        );
        self::assertNull($reader->typeOf('CatalogDataProductInterface'));
    }

    public function testWhenTheMetadataCannotBeBuiltEachMethodIsReadFromItsClassAndTheFailureLoggedOnce(): void
    {
        $reader = $this->reader($this->failingMetadata(), $this->reflector(), new InMemoryCacheFrontend(false));

        $method = $reader->methodOf(self::SERVICE, 'getList', 'V1');
        $broken = $reader->methodOf('Acme\Shop\Api\BrokenInterface', 'register', 'V1');
        $reader->typeOf('FrameworkSearchCriteriaInterface');

        self::assertSame('Get product list', $method['documentation'] ?? null);
        self::assertNull($broken);
        self::assertCount(1, $this->logger->messages('warning'));
        self::assertStringContainsString('The "array" class', $this->logger->messages('warning')[0]);

        $reader->_resetState();
        $reader->methodOf(self::SERVICE, 'getList', 'V1');
        self::assertCount(2, $this->logger->messages('warning'), 'With the cache type off, each request tries again.');
    }

    public function testAFailedBuildIsRememberedForFiveMinutesUnderTheSchemaTag(): void
    {
        $builds = 0;
        $cache = new InMemoryCacheFrontend();
        $reader = $this->reader($this->failingMetadata($builds), $this->reflector(), $cache);

        $reader->methodOf(self::SERVICE, 'getList', 'V1');
        $reader->_resetState();
        $method = $reader->methodOf(self::SERVICE, 'getList', 'V1');

        self::assertSame(1, $builds, 'The next request reads by reflection without building again.');
        self::assertSame('Get product list', $method['documentation'] ?? null);
        self::assertCount(1, $this->logger->messages('warning'));
        self::assertCount(1, $cache->entries);
        foreach ($cache->entries as $entry) {
            self::assertSame([McpCache::SCHEMA_TAG], $entry['tags']);
            self::assertSame(ServiceMetadataReader::FAILED_BUILD_LIFETIME, $entry['lifeTime']);
            self::assertSame(300, $entry['lifeTime']);
        }

        $cache->clean();
        $reader->_resetState();
        $reader->methodOf(self::SERVICE, 'getList', 'V1');
        self::assertSame(2, $builds, 'Once the memory is cleaned, the build is tried again.');
    }

    public function testABuildThatWorksLeavesNothingInTheCache(): void
    {
        $cache = new InMemoryCacheFrontend();
        $serviceMetadata = $this->createStub(ServiceMetadata::class);
        $serviceMetadata->method('getServiceMetadata')->willReturn(['methods' => []]);

        $this->reader($serviceMetadata, $this->createStub(ClassReflector::class), $cache)
            ->methodOf(self::SERVICE, 'getList', 'V1');

        self::assertSame([], $cache->entries);
    }

    /**
     * Service metadata whose build for all services fails, counting the builds
     *
     * @param int $builds
     * @return ServiceMetadata
     */
    private function failingMetadata(int &$builds = 0): ServiceMetadata
    {
        $serviceMetadata = $this->createStub(ServiceMetadata::class);
        $serviceMetadata->method('getServicesConfig')->willReturnCallback(
            static function () use (&$builds): never {
                $builds++;
                throw new \LogicException('The "array" class doesn\'t exist');
            }
        );

        return $serviceMetadata;
    }

    /**
     * A class reflector that reads the product repository and fails on any other class
     *
     * @return ClassReflector
     */
    private function reflector(): ClassReflector
    {
        $reflector = $this->createStub(ClassReflector::class);
        $reflector->method('reflectClassMethods')->willReturnCallback(
            static fn (string $class, array $methods): array => $class === self::SERVICE
                ? ['getList' => self::GET_LIST]
                : throw new \LogicException('Unreadable docblock')
        );

        return $reflector;
    }

    /**
     * @param ServiceMetadata $serviceMetadata
     * @param ClassReflector $reflector
     * @param InMemoryCacheFrontend|null $cache
     * @return ServiceMetadataReader
     */
    private function reader(
        ServiceMetadata $serviceMetadata,
        ClassReflector $reflector,
        ?InMemoryCacheFrontend $cache = null
    ): ServiceMetadataReader {
        return new ServiceMetadataReader(
            $serviceMetadata,
            $this->typeProcessor,
            $reflector,
            $this->logger,
            $cache ?? new InMemoryCacheFrontend()
        );
    }
}
