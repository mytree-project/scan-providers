<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanLocatorHints;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\OrdinalLocatorParseResult;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\OrdinalLocatorParser;
use PHPUnit\Framework\TestCase;

final class OrdinalLocatorParserTest extends TestCase
{
    private const UNIT = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/1833707';

    public function testItParsesAcceptedScanFragmentAndExplicitOrdinal(): void
    {
        $parser = new OrdinalLocatorParser();

        $fromFragment = $parser->parse(new ResolveScanRequest(
            new ScanResourceReference(self::UNIT . '#scan42'),
        ));
        self::assertSame(OrdinalLocatorParseResult::VALID, $fromFragment->status);
        self::assertSame(42, $fromFragment->ordinal);
        self::assertSame('#scan42', $fromFragment->matchedRaw);

        $fromHint = $parser->parse(new ResolveScanRequest(
            new ScanResourceReference(self::UNIT),
            new ScanLocatorHints(scanNumberRaw: '42'),
        ));
        self::assertSame(OrdinalLocatorParseResult::VALID, $fromHint->status);
        self::assertSame(42, $fromHint->ordinal);
        self::assertSame('42', $fromHint->matchedRaw);
    }

    public function testItRejectsMalformedOrNonPositiveOrdinalForms(): void
    {
        $parser = new OrdinalLocatorParser();

        foreach (['#scan0', '#scan-1', '#scan01', '#page42', '#'] as $fragment) {
            $result = $parser->parse(new ResolveScanRequest(
                new ScanResourceReference(self::UNIT . $fragment),
            ));
            self::assertSame(OrdinalLocatorParseResult::UNSUPPORTED, $result->status, $fragment);
            self::assertSame('unsupported_scan_fragment', $result->reason, $fragment);
        }

        $invalidHint = $parser->parse(new ResolveScanRequest(
            new ScanResourceReference(self::UNIT),
            new ScanLocatorHints(scanNumberRaw: 'scan42'),
        ));
        self::assertSame(OrdinalLocatorParseResult::UNSUPPORTED, $invalidHint->status);
        self::assertSame('invalid_scan_number_hint', $invalidHint->reason);
    }

    public function testItKeepsMissingAndConflictingRawHintsExplicit(): void
    {
        $parser = new OrdinalLocatorParser();

        $missing = $parser->parse(new ResolveScanRequest(new ScanResourceReference(self::UNIT)));
        self::assertSame(OrdinalLocatorParseResult::MISSING, $missing->status);
        self::assertSame('missing_scan_ordinal', $missing->reason);

        $conflict = $parser->parse(new ResolveScanRequest(
            new ScanResourceReference(self::UNIT . '#scan42'),
            new ScanLocatorHints(scanNumberRaw: '43'),
        ));
        self::assertSame(OrdinalLocatorParseResult::CONFLICT, $conflict->status);
        self::assertSame('conflicting_scan_ordinals', $conflict->reason);
        self::assertStringContainsString('42', implode(' ', $conflict->trace));
        self::assertStringContainsString('43', implode(' ', $conflict->trace));
    }
}
