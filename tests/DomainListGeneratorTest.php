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
        $generator = new DomainListGenerator([], '');
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
        $generator = new DomainListGenerator($sourceUrls, '/tmp/domains.php');
        $domains = [
            'ad.example.com' => false,
            'local.dev' => '127.0.0.1',
        ];

        $content = $generator->generatePhpFileContent($domains);

        $this->assertStringContainsString("'ad.example.com' => false", $content);
        $this->assertStringContainsString("'local.dev' => '127.0.0.1'", $content);
        $this->assertStringContainsString('return array (', $content);
        $this->assertStringContainsString("// - http://example.com/hosts", $content);
        $this->assertStringContainsString("// - http://example.org/hosts", $content);
    }

    public function testGeneratedFileContentAfterInclude()
    {
        $generator = new DomainListGenerator(['http://example.com/hosts'], '/tmp/test-domains-include.php');
        $domains = [
            'ad.example.com' => false,
            'local.dev' => '127.0.0.1',
        ];

        $content = $generator->generatePhpFileContent($domains);
        file_put_contents('/tmp/test-domains-include.php', $content);

        // Include the generated file and verify its content
        $includedDomains = include '/tmp/test-domains-include.php';

        $expected = [
            'ad.example.com' => false,
            'local.dev' => '127.0.0.1',
        ];

        $this->assertEquals($expected, $includedDomains);

        // Clean up the generated file
        if (file_exists('/tmp/test-domains-include.php')) {
            unlink('/tmp/test-domains-include.php');
        }
    }

    public function testGenerate()
    {
        $sourceUrls = ['http://example.com/hosts'];
        $outputFile = '/tmp/test-domains.php';

        // Create a mock for the DomainListGenerator class
        $mockGenerator = $this->getMockBuilder(DomainListGenerator::class)
            ->setConstructorArgs([$sourceUrls, $outputFile])
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

        // Clean up the generated file if it exists
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
    }
}
