<?php

declare(strict_types=1);

namespace Launix\PdfToData\Internal;

use Launix\PdfToData\Contract\ExtractorEngine;
use Launix\PdfToData\NormalizedDocument;
use RuntimeException;

final class XtractCliEngine implements ExtractorEngine
{
    public function __construct(
        private readonly string $phpBinary = PHP_BINARY,
        private readonly ?string $scriptPath = null
    ) {
    }

    public function extract(string $pdfBytes, string $filename, array $options = []): NormalizedDocument
    {
        $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pdf-to-data-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException(sprintf('Could not create temp directory: %s', $tmpDir));
        }

        try {
            $pdfPath = $tmpDir . DIRECTORY_SEPARATOR . ($filename !== '' ? basename($filename) : 'document.pdf');
            file_put_contents($pdfPath, $pdfBytes);

            $scriptPath = $this->scriptPath ?? dirname(__DIR__, 2) . '/src/Internal/xtract_legacy.php';
            $command = [
                $this->phpBinary !== '' ? $this->phpBinary : 'php',
                $scriptPath,
                $pdfPath,
            ];

            [$exitCode, $stdout, $stderr] = $this->runProcess($command, $tmpDir);
            if ($exitCode !== 0) {
                throw new RuntimeException(sprintf(
                    "xtract failed with exit code %d.\nSTDOUT:\n%s\nSTDERR:\n%s",
                    $exitCode,
                    trim($stdout),
                    trim($stderr)
                ));
            }

            $jsonPath = $tmpDir . DIRECTORY_SEPARATOR . 'out2.json';
            $htmlPath = $tmpDir . DIRECTORY_SEPARATOR . 'out2.html';
            if (!is_file($jsonPath)) {
                throw new RuntimeException('xtract did not create out2.json.');
            }

            $decoded = json_decode((string)file_get_contents($jsonPath), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('xtract produced invalid JSON.');
            }

            return new NormalizedDocument(
                is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [],
                is_array($decoded['pages'] ?? null) ? $decoded['pages'] : [],
                is_array($decoded['items'] ?? null) ? $decoded['items'] : [],
                is_file($htmlPath) ? (string)file_get_contents($htmlPath) : ''
            );
        } finally {
            $this->deleteDirectory($tmpDir);
        }
    }

    /**
     * @param list<string> $command
     * @return array{0:int,1:string,2:string}
     */
    private function runProcess(array $command, string $workingDirectory): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start xtract process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, (string)$stdout, (string)$stderr];
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
