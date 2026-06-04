<?php

namespace App\Tests\Controller;

use App\Controller\ImslpController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ImslpControllerParsingTest extends KernelTestCase
{
    private ImslpController $controller;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->controller = self::getContainer()->get(ImslpController::class);
    }

    /**
     * Test extractCatalogueNumber with various standard musicological prefixes.
     * This is a reflection test since the method is private; alternatively,
     * we test via the public rismWorkIncipits endpoint behavior.
     */
    public function testExtractCatalogueNumberPatterns(): void
    {
        $reflection = new \ReflectionClass(ImslpController::class);
        $method = $reflection->getMethod('extractCatalogueNumber');
        $method->setAccessible(true);

        // BWV (Bach)
        $this->assertEquals('BWV 1087', $method->invoke($this->controller, '14 Canons, BWV 1087'));
        // QV (Quantz)
        $this->assertEquals('QV 2:Anh.28', $method->invoke($this->controller, 'Trio Sonata in G major, QV 2:Anh.28'));
        // HWV (Handel)
        $this->assertEquals('HWV 6', $method->invoke($this->controller, 'Agrippina, HWV 6'));
        // TWV (Telemann)
        $this->assertEquals('TWV 40:2-13', $method->invoke($this->controller, '12 Fantasias for Flute without Bass, TWV 40:2-13'));
        // K. (Köchel, Mozart)
        $this->assertEquals('K. 331', $method->invoke($this->controller, 'Piano Sonata No. 8, K. 331'));
        // RV (Ryom-Verzeichnis, Vivaldi)
        $this->assertEquals('RV 297', $method->invoke($this->controller, 'Concerto, RV 297'));
        // Op. (Opus)
        $this->assertNotNull($method->invoke($this->controller, 'Symphony Op. 5 No. 2'));
        // WoO (Werke ohne Opuszahl, Beethoven)
        $this->assertEquals('WoO 57', $method->invoke($this->controller, 'Variations, WoO 57'));
        // D. (Deutsch, Schubert)
        $this->assertEquals('D 960', $method->invoke($this->controller, 'Piano Sonata, D 960'));
    }

    public function testExtractCatalogueNumberReturnsNullForNoMatch(): void
    {
        $reflection = new \ReflectionClass(ImslpController::class);
        $method = $reflection->getMethod('extractCatalogueNumber');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($this->controller, 'Some piece with no catalogue number'));
        $this->assertNull($method->invoke($this->controller, ''));
    }

    public function testSanitizeSvgRemovesScriptTags(): void
    {
        $reflection = new \ReflectionClass(ImslpController::class);
        $method = $reflection->getMethod('sanitizeSvg');
        $method->setAccessible(true);

        $malicious = '<svg><script>alert("xss")</script></svg>';
        $result = $method->invoke($this->controller, $malicious);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    public function testSanitizeSvgRemovesEventHandlers(): void
    {
        $reflection = new \ReflectionClass(ImslpController::class);
        $method = $reflection->getMethod('sanitizeSvg');
        $method->setAccessible(true);

        $malicious = '<svg onclick="alert(1)"><rect onmouseover="alert(2)" /><path onload="alert(3)" /></svg>';
        $result = $method->invoke($this->controller, $malicious);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
        $this->assertStringNotContainsString('onload', $result);
    }

    public function testSanitizeSvgPreservesValidSvgContent(): void
    {
        $reflection = new \ReflectionClass(ImslpController::class);
        $method = $reflection->getMethod('sanitizeSvg');
        $method->setAccessible(true);

        $valid = '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="blue" /></svg>';
        $result = $method->invoke($this->controller, $valid);
        $this->assertStringContainsString('svg', $result);
        $this->assertStringContainsString('circle', $result);
        $this->assertStringContainsString('fill="blue"', $result);
    }
}
