<?php

declare(strict_types=1);

namespace Launix\PdfToData\Tests\Unit;

use Launix\PdfToData\NormalizedDocument;
use PHPUnit\Framework\TestCase;

final class NormalizedDocumentTest extends TestCase
{
    public function testItExportsStructuredPayload(): void
    {
        $document = new NormalizedDocument(
            ['page_count' => 2],
            [['page' => 1], ['page' => 2]],
            [['type' => 'text', 'text' => 'A']],
            '<html>body</html>'
        );

        self::assertSame(2, $document->meta()['page_count']);
        self::assertCount(2, $document->pages());
        self::assertSame('<html>body</html>', $document->html());
        self::assertSame('A', $document->toArray()['items'][0]['text']);
    }
}
