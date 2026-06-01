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

    public function testItCanRenderHtmlForACroppedDetailBox(): void
    {
        $document = new NormalizedDocument(
            [],
            [],
            [
                ['type' => 'text', 'text' => 'Visible', 'left' => 100.0, 'top' => 200.0, 'font_size' => 12.0],
                ['type' => 'line', 'left' => 110.0, 'top' => 220.0, 'width' => 30.0, 'height' => 1.0, 'color' => '#f00'],
                ['type' => 'text', 'text' => 'Hidden', 'left' => 10.0, 'top' => 20.0, 'font_size' => 12.0],
            ],
            ''
        );

        $html = $document->renderHtml(null, ['x' => 95, 'y' => 195, 'w' => 80, 'h' => 40]);

        self::assertStringContainsString('class="pdf-fragment"', $html);
        self::assertStringContainsString('width:80px;height:40px', $html);
        self::assertStringContainsString('Visible', $html);
        self::assertStringContainsString('left:5px;top:5px', $html);
        self::assertStringNotContainsString('Hidden', $html);
    }
}
