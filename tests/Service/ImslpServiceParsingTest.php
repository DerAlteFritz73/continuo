<?php

namespace App\Tests\Service;

use App\Service\ImslpService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ImslpServiceParsingTest extends TestCase
{
    private ImslpService $service;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $db = $this->createMock(Connection::class);
        $this->service = new ImslpService($em, $db);
    }

    /** @test */
    public function extractRismId_opac_rism_info_url(): void
    {
        $text = 'Staatsbibliothek zu Berlin (D-B): [https://opac.rism.info/metaopac/search.do?methodToCall=quickSearch&Kateg=0&Content=1001035801 D-B]';
        $result = $this->service->extractRismId($text);
        $this->assertEquals('1001035801', $result);
    }

    /** @test */
    public function extractRismId_rism_online_path(): void
    {
        $text = 'Based on RISM source rism.online/sources/220034045';
        $result = $this->service->extractRismId($text);
        $this->assertEquals('220034045', $result);
    }

    /** @test */
    public function extractRismId_rism_info_path(): void
    {
        $text = 'See also rism.info/sources/1001021198 for more info';
        $result = $this->service->extractRismId($text);
        $this->assertEquals('1001021198', $result);
    }

    /** @test */
    public function extractRismId_plain_text_rism_colon(): void
    {
        $text = 'RISM: 1001035801';
        $result = $this->service->extractRismId($text);
        $this->assertEquals('1001035801', $result);
    }

    /** @test */
    public function extractRismId_plain_text_rism_space(): void
    {
        $text = 'RISM 1001035801';
        $result = $this->service->extractRismId($text);
        $this->assertEquals('1001035801', $result);
    }

    /** @test */
    public function extractRismId_plain_text_rism_hash(): void
    {
        $text = 'See manuscript RISM#1001035801 in the library';
        $result = $this->service->extractRismId($text);
        $this->assertEquals('1001035801', $result);
    }

    /** @test */
    public function extractRismId_empty_string_returns_null(): void
    {
        $result = $this->service->extractRismId('');
        $this->assertNull($result);
    }

    /** @test */
    public function extractRismId_no_match_returns_null(): void
    {
        $text = 'This text contains no RISM reference';
        $result = $this->service->extractRismId($text);
        $this->assertNull($result);
    }

    /** @test */
    public function extractRismId_requires_7_or_more_digits(): void
    {
        $text = 'RISM: 123456'; // only 6 digits
        $result = $this->service->extractRismId($text);
        $this->assertNull($result);

        $text = 'RISM: 1234567'; // 7 digits - should match
        $result = $this->service->extractRismId($text);
        $this->assertEquals('1234567', $result);
    }

    /** @test */
    public function parseDurationSeconds_hours_and_minutes(): void
    {
        // Format: "1 hour 1 minute" or "1h 1m"
        $this->assertEquals(3660, $this->service->parseDurationSeconds('1 hour 1 minute'));
        $this->assertEquals(120, $this->service->parseDurationSeconds('2 minutes'));
        $this->assertEquals(3600, $this->service->parseDurationSeconds('1 h'));
    }

    /** @test */
    public function parseDurationSeconds_range_format(): void
    {
        // Range "10-15 minutes" averages to 12.5 minutes = 750 seconds
        $result = $this->service->parseDurationSeconds('10-15 minutes');
        $this->assertEquals(750, $result);
    }

    /** @test */
    public function parseDurationSeconds_with_approximation(): void
    {
        // "ca. 45 minutes" strips the approximation marker
        $this->assertEquals(2700, $this->service->parseDurationSeconds('ca. 45 minutes'));
        $this->assertEquals(2700, $this->service->parseDurationSeconds('approximately 45 min'));
    }

    /** @test */
    public function parseDurationSeconds_null_on_invalid(): void
    {
        $this->assertNull($this->service->parseDurationSeconds('invalid'));
        $this->assertNull($this->service->parseDurationSeconds(''));
        $this->assertNull($this->service->parseDurationSeconds('no numbers here'));
    }

    /** @test */
    public function parseFirstPerformance_date_with_location(): void
    {
        $result = $this->service->parseFirstPerformance('1724 in Leipzig, Germany');
        // Returns [$dateString, $locationString]
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertNotEmpty($result[0]); // date
        $this->assertNotEmpty($result[1]); // location
    }

    /** @test */
    public function parseFirstPerformance_date_only(): void
    {
        $result = $this->service->parseFirstPerformance('1750');
        $this->assertIsArray($result);
        // May have empty location
        $this->assertCount(2, $result);
    }

    /** @test */
    public function parseFirstPerformance_empty_returns_null_array(): void
    {
        $result = $this->service->parseFirstPerformance('');
        $this->assertIsArray($result);
        // Returns [null, null] not []
        $this->assertNull($result[0] ?? null);
        $this->assertNull($result[1] ?? null);
    }
}
