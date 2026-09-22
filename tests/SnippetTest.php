<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Capture\Location;
use DevZone\LogMonitor\Capture\Snippet;
use DevZone\LogMonitor\Support\Redactor;
use PHPUnit\Framework\TestCase;

class SnippetTest extends TestCase
{
    /** @var string */
    private $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/log-monitor-snippet-' . getmypid();
        @mkdir($this->base . '/app', 0777, true);
        @mkdir($this->base . '/vendor/acme', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->base . '/*/*') as $file) {
            @unlink((string) $file);
        }
        foreach ((array) glob($this->base . '/*') as $dir) {
            @rmdir((string) $dir);
        }
        @rmdir($this->base);
    }

    private function snippet(): Snippet
    {
        return new Snippet(new Location($this->base), Redactor::fromConfig([]));
    }

    private function write(string $path, string $contents): string
    {
        file_put_contents($this->base . '/' . $path, $contents);

        return $this->base . '/' . $path;
    }

    public function testReadsTheLinesAroundTheOneThatThrew(): void
    {
        $file = $this->write('app/Job.php', "<?php\n\$a = 1;\n\$b = 2;\nthrow new \\RuntimeException('x');\n\$c = 3;\n\$d = 4;\n");

        $snippet = $this->snippet()->read($file, 4, 2);

        $this->assertSame(2, $snippet['start']);
        $this->assertSame(4, $snippet['line']);
        $this->assertSame(['$a = 1;', '$b = 2;', "throw new \\RuntimeException('x');", '$c = 3;', '$d = 4;'], $snippet['lines']);
        $this->assertSame("throw new \\RuntimeException('x');", $snippet['lines'][$snippet['line'] - $snippet['start']]);
    }

    public function testStopsAtTheStartAndEndOfTheFile(): void
    {
        $file = $this->write('app/Short.php', "<?php\n\$a = 1;\n");

        $snippet = $this->snippet()->read($file, 1, 5);

        $this->assertSame(1, $snippet['start']);
        $this->assertSame(['<?php', '$a = 1;'], $snippet['lines']);
        $this->assertNull($this->snippet()->read($file, 90, 3), 'a line past the end of the file');
    }

    public function testSecretsWrittenInTheCodeAreMasked(): void
    {
        $file = $this->write('app/Client.php', "<?php\n\$apiKey = 'sk_live_9times';\n\$config = ['password' => 'hunter2', 'host' => 'api.test'];\n\$name = 'Sara';\n");

        $lines = $this->snippet()->read($file, 3, 2)['lines'];

        $this->assertSame("\$apiKey = '[REDACTED]';", $lines[1]);
        $this->assertSame("\$config = ['password' => '[REDACTED]', 'host' => 'api.test'];", $lines[2]);
        $this->assertSame("\$name = 'Sara';", $lines[3], 'ordinary values are left alone');
    }

    public function testVendorCodeAndFilesOutsideTheProjectAreNotRead(): void
    {
        $vendor = $this->write('vendor/acme/Client.php', "<?php\nthrow new \\RuntimeException('x');\n");
        $outside = tempnam(sys_get_temp_dir(), 'lm') . '.php';
        file_put_contents($outside, "<?php\n\$secret = 1;\n");

        $this->assertNull($this->snippet()->read($vendor, 2, 2));
        $this->assertNull($this->snippet()->read($outside, 2, 2));
        $this->assertNull($this->snippet()->read($this->base . '/app/Missing.php', 2, 2));
        $this->assertNull($this->snippet()->read($this->write('app/notes.txt', "hello\n"), 1, 2), 'only PHP files');

        @unlink($outside);
    }

    public function testLongLinesAreCut(): void
    {
        $file = $this->write('app/Long.php', "<?php\n\$x = '" . str_repeat('y', 500) . "';\n");

        $line = $this->snippet()->read($file, 2, 1)['lines'][1];

        $this->assertSame(201, mb_strlen($line));
        $this->assertStringEndsWith('…', $line);
    }
}
