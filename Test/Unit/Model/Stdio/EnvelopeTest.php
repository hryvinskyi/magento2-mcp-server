<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Stdio;

use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;
use Hryvinskyi\McpServer\Model\Stdio\Envelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The envelope line between a message process and the serving loop
 */
class EnvelopeTest extends TestCase
{
    public function testAnEnvelopeIsOneLineAndReadsBackAsWritten(): void
    {
        $envelope = new Envelope(new JsonEncoder());
        $body = "{\"text\":\"line\\nbreak and </tag> \u{00e9}\"}";

        $line = $envelope->encode($body, 'abababababababababababababababab');

        self::assertSame(1, substr_count($line, "\n"));
        self::assertStringEndsWith("\n", $line);
        self::assertSame(
            ['body' => $body, 'sessionId' => 'abababababababababababababababab', 'sessionLost' => false],
            $envelope->decode($line)
        );
    }

    public function testALostSessionIsCarriedAsABoolean(): void
    {
        $envelope = new Envelope(new JsonEncoder());

        $line = $envelope->encode('{"error":{}}', '', true);

        self::assertSame('{"body":"{\"error\":{}}","sessionId":"","sessionLost":true}' . "\n", $line);
        self::assertSame(
            ['body' => '{"error":{}}', 'sessionId' => '', 'sessionLost' => true],
            $envelope->decode($line)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notEnvelopes(): array
    {
        return [
            'empty' => [''],
            'not JSON' => ['Deprecated: something'],
            'a list' => ['["a","b"]'],
            'missing session' => ['{"body":"","sessionLost":false}'],
            'missing session lost' => ['{"body":"","sessionId":""}'],
            'session lost not a boolean' => ['{"body":"","sessionId":"","sessionLost":"yes"}'],
            'body not a string' => ['{"body":{"a":1},"sessionId":"","sessionLost":false}'],
            'an extra key' => ['{"body":"","sessionId":"","sessionLost":false,"x":""}'],
            'an extra key in place of one' => ['{"body":"","sessionId":"","x":false}'],
            'two lines of output' => [
                "{\"body\":\"\",\"sessionId\":\"\",\"sessionLost\":false}\n"
                . "{\"body\":\"\",\"sessionId\":\"\",\"sessionLost\":false}",
            ],
        ];
    }

    #[DataProvider('notEnvelopes')]
    public function testAnythingElseIsNoEnvelope(string $text): void
    {
        self::assertNull((new Envelope(new JsonEncoder()))->decode($text));
    }
}
