<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Call;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\AnswerMapper;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class AnswerMapperTest extends TestCase
{
    private const ABILITY = 'graphql.query.products';

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testDataOnlyAnswersTheFieldsValue(): void
    {
        $value = $this->mapper()->map(self::ABILITY, 'products', [
            'data' => ['products' => ['total_count' => 3, 'instance' => 'store data']],
        ]);

        self::assertSame(['total_count' => 3, 'instance' => 'store data'], $value);
        self::assertSame([], $this->logger->records);
    }

    public function testANullValueWithoutErrorsIsNull(): void
    {
        self::assertNull($this->mapper()->map(self::ABILITY, 'cart', ['data' => ['cart' => null]]));
    }

    public function testErrorsWithoutDataAreAFailureCarryingTheirMessages(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The GraphQL query failed: Syntax Error: Expected Name, found }');

        $this->mapper()->map(self::ABILITY, 'products', [
            'errors' => [['message' => 'Syntax Error: Expected Name, found }']],
        ]);
    }

    public function testErrorsWithANullValueAreAFailureAndOnlyTheMessagesArePassedOn(): void
    {
        try {
            $this->mapper()->map(self::ABILITY, 'products', [
                'errors' => [
                    [
                        'message' => '\'search\' or \'filter\' input argument is required.',
                        'extensions' => ['category' => 'graphql-input', 'debugMessage' => 'secret trace'],
                    ],
                    ['extensions' => ['debugMessage' => 'another secret']],
                ],
                'data' => ['products' => null],
            ]);
            self::fail('The answer was mapped as a success.');
        } catch (LocalizedException $exception) {
            self::assertSame(
                'The GraphQL query failed: \'search\' or \'filter\' input argument is required. | (no message)',
                $exception->getMessage()
            );
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }

        $logged = $this->logger->records[0]['context']['errors'] ?? null;
        self::assertIsString($logged);
        self::assertStringContainsString('secret trace', $logged);
    }

    public function testErrorsWithAValueArePartialDataWithTheMessagesOnly(): void
    {
        $value = $this->mapper()->map(self::ABILITY, 'products', [
            'errors' => [['message' => 'Price is unavailable.', 'extensions' => ['debugMessage' => 'trace']]],
            'data' => ['products' => ['items' => [['sku' => 'S1', 'price' => null]]]],
        ]);

        self::assertSame(
            ['data' => ['items' => [['sku' => 'S1', 'price' => null]]], 'errors' => ['Price is unavailable.']],
            $value
        );
    }

    /**
     * @return AnswerMapper
     */
    private function mapper(): AnswerMapper
    {
        return new AnswerMapper($this->logger);
    }
}
