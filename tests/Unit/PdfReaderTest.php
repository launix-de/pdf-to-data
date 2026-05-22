<?php

declare(strict_types=1);

namespace Launix\PdfToData\Tests\Unit;

use Launix\PdfToData\Contract\ExtractorEngine;
use Launix\PdfToData\NormalizedDocument;
use Launix\PdfToData\PdfReader;
use PHPUnit\Framework\TestCase;

final class PdfReaderTest extends TestCase
{
    public function testItBuildsReaderFromStringAndDelegatesToEngine(): void
    {
        $engine = new class implements ExtractorEngine {
            public int $rawCalls = 0;
            public int $normalizedCalls = 0;

            public function extractRaw(string $pdfBytes, string $filename, array $options = []): NormalizedDocument
            {
                TestCase::assertSame('%PDF-test', $pdfBytes);
                TestCase::assertSame('sample.pdf', $filename);
                TestCase::assertSame([], $options);
                $this->rawCalls++;

                return new NormalizedDocument(
                    ['mode' => 'raw'],
                    [['page' => 1]],
                    [['type' => 'text', 'text' => 'raw']],
                    '<html>raw</html>'
                );
            }

            public function extract(string $pdfBytes, string $filename, array $options = []): NormalizedDocument
            {
                TestCase::assertSame('%PDF-test', $pdfBytes);
                TestCase::assertSame('sample.pdf', $filename);
                TestCase::assertSame(['mode' => 'test'], $options);
                $this->normalizedCalls++;

                return new NormalizedDocument(
                    ['stream_height' => 100],
                    [['page' => 1]],
                    [['type' => 'text', 'text' => 'hello']],
                    '<html></html>'
                );
            }
        };

        $reader = PdfReader::fromString('%PDF-test', 'sample.pdf', $engine);

        self::assertSame(1, $engine->rawCalls);
        self::assertSame(0, $engine->normalizedCalls);
        self::assertSame('raw', $reader->extractElements()[0]['text']);

        $doc = $reader->removeFooters(['mode' => 'test']);

        self::assertSame(1, $engine->normalizedCalls);
        self::assertSame(100, $doc->meta()['stream_height']);
        self::assertSame('hello', $reader->extractElements()[0]['text']);
        self::assertSame(['hello'], $reader->extractSalesDocument()['text']);
    }

    public function testItBuildsReaderFromStream(): void
    {
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, '%PDF-stream');
        rewind($stream);

        $engine = new class implements ExtractorEngine {
            public function extractRaw(string $pdfBytes, string $filename, array $options = []): NormalizedDocument
            {
                TestCase::assertSame('%PDF-stream', $pdfBytes);
                TestCase::assertSame('stream.pdf', $filename);

                return new NormalizedDocument([], [], [], '');
            }

            public function extract(string $pdfBytes, string $filename, array $options = []): NormalizedDocument
            {
                TestCase::assertSame('%PDF-stream', $pdfBytes);
                TestCase::assertSame('stream.pdf', $filename);

                return new NormalizedDocument([], [], [], '');
            }
        };

        $reader = PdfReader::fromStream($stream, 'stream.pdf', $engine);
        self::assertSame([], $reader->extractElements());
        fclose($stream);
    }
}
