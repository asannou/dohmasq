<?php

use PHPUnit\Framework\TestCase;
use Asannou\DohMasq\DomainListGenerator;

class DomainListGeneratorTest extends TestCase
{
    private $sampleHostsContent = <<<'EOT'
# This is a comment
127.0.0.1 localhost
127.0.0.1 local.dev

0.0.0.0 ad.example.com
0.0.0.0 banner.example.org # some comment
0.0.0.0 double.click.net tracker.com
EOT;

    public function testParseHostsContent()
    {
        $dummyStream = fopen('php://memory', 'r');
        $generator = new DomainListGenerator([], $dummyStream);
        fclose($dummyStream);

        $result = $generator->parseHostsContent($this->sampleHostsContent);

        $expected = [
            'local.dev' => '127.0.0.1',
            'ad.example.com' => false,
            'banner.example.org' => false,
            'double.click.net' => false,
            'tracker.com' => false,
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGeneratePhpFileContent()
    {
        $sourceUrls = ['http://example.com/hosts', 'http://example.org/hosts'];
        $dummyStream = fopen('php://memory', 'r');
        $generator = new DomainListGenerator($sourceUrls, $dummyStream);
        fclose($dummyStream);

        $domains = [
            'ad.example.com' => false,
            'local.dev' => '127.0.0.1',
        ];

        $content = $generator->generatePhpFileContent($domains);

        $this->assertStringContainsString("'ad.example.com' => false", $content);
        $this->assertStringContainsString("'local.dev' => '127.0.0.1'", $content);
        $this->assertStringContainsString("return array (\n", $content);
        $this->assertStringContainsString("// - http://example.com/hosts", $content);
        $this->assertStringContainsString("// - http://example.org/hosts", $content);
    }

    public function testGeneratedFileContentAfterInclude()
    {
        $tempFile = tmpfile();
        $generator = new DomainListGenerator(['http://example.com/hosts'], $tempFile);
        $domains = [
            'ad.example.com' => false,
            'local.dev' => '127.0.0.1',
        ];

        $content = $generator->generatePhpFileContent($domains);
        fwrite($tempFile, $content);
        $meta_data = stream_get_meta_data($tempFile);
        $filename = $meta_data["uri"];

        // Include the generated file and verify its content
        $includedDomains = include $filename;

        fclose($tempFile); // This deletes the file

        $expected = [
            'ad.example.com' => false,
            'local.dev' => '127.0.0.1',
        ];

        $this->assertEquals($expected, $includedDomains);
    }

    public function testGenerate()
    {
        $sourceUrls = ['http://example.com/hosts'];
        $outputStream = tmpfile();

        // Create a mock for the DomainListGenerator class
        $mockGenerator = $this->getMockBuilder(DomainListGenerator::class)
            ->setConstructorArgs([$sourceUrls, $outputStream])
            ->onlyMethods(['fetchSourceContent', 'saveOutputFile'])
            ->getMock();

        // Configure the mock methods
        $mockGenerator->method('fetchSourceContent')
            ->willReturn($this->sampleHostsContent);

        $mockGenerator->method('saveOutputFile')
            ->willReturn(true);

        // Run the generate method
        $result = $mockGenerator->generate(false);
        $this->assertTrue($result);

        fclose($outputStream);
    }
}
