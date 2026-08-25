<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use TypechoPlugin\FeedEnhancer\Runtime\Settings;

final class SettingsTest extends TestCase
{
    public function testDefaultsPreserveTheDocumentedBehavior(): void
    {
        $settings = new Settings();

        self::assertFalse($settings->contentTruncationEnabled());
        self::assertSame(300, $settings->feedContentLength());
        self::assertSame('阅读全文', $settings->feedReadMoreText());
        self::assertTrue($settings->stylesheetEnabled());
        self::assertFalse($settings->safariXmlMime());
        self::assertTrue($settings->mediaEnabled());
        self::assertSame(['banner', 'cover', 'thumbnail'], $settings->mediaFieldNames());
    }

    public function testBooleanValuesAcceptOnlyExplicitEnabledRepresentations(): void
    {
        $settings = new Settings([
            'stylesheetEnabled' => '0',
            'safariXmlMime' => 1,
            'mediaEnabled' => true,
        ]);

        self::assertFalse($settings->stylesheetEnabled());
        self::assertTrue($settings->safariXmlMime());
        self::assertTrue($settings->mediaEnabled());
    }

    public function testContentTruncationAcceptsOnlyExplicitEnabledRepresentations(): void
    {
        foreach ([true, 1, '1'] as $enabled) {
            self::assertTrue((new Settings(['feedContentMode' => $enabled]))->contentTruncationEnabled());
        }

        foreach ([false, 0, '0', 2, 'true', null, []] as $disabled) {
            self::assertFalse((new Settings(['feedContentMode' => $disabled]))->contentTruncationEnabled());
        }
    }

    public function testFeedContentLengthAcceptsOnlyStrictIntegersWithinBounds(): void
    {
        foreach ([50, '50', 300, '300', 1000, '1000'] as $valid) {
            self::assertSame((int) $valid, (new Settings(['feedContentLength' => $valid]))->feedContentLength());
        }

        foreach ([49, '49', 1001, '1001', '300.0', ' 300 ', 300.0, true, null, []] as $invalid) {
            self::assertSame(300, (new Settings(['feedContentLength' => $invalid]))->feedContentLength());
        }
    }

    public function testFeedReadMoreTextIsTrimmedAndUnicodeAware(): void
    {
        self::assertSame(
            '继续阅读全文',
            (new Settings(['feedReadMoreText' => "\u{3000}继续阅读全文\u{00A0}"]))->feedReadMoreText()
        );
        self::assertSame(
            str_repeat('界', 100),
            (new Settings(['feedReadMoreText' => str_repeat('界', 100)]))->feedReadMoreText()
        );
        self::assertSame('继续 & 阅读', (new Settings(['feedReadMoreText' => '继续 & 阅读']))->feedReadMoreText());
    }

    public function testInvalidFeedReadMoreTextFallsBackToDefault(): void
    {
        $invalidValues = [
            '',
            '   ',
            str_repeat('界', 101),
            '<strong>继续阅读</strong>',
            '继续<br>阅读',
            '继续<阅读',
            "继续\n阅读",
            "\n继续阅读\n",
            "\t继续阅读",
            "继续\0阅读",
            "\u{00A0}\u{3000}",
            "\xC3\x28",
            123,
            [],
        ];

        foreach ($invalidValues as $invalid) {
            self::assertSame('阅读全文', (new Settings(['feedReadMoreText' => $invalid]))->feedReadMoreText());
        }
    }

    public function testFieldNamesAreTrimmedDeduplicatedValidatedAndLimited(): void
    {
        $values = [
            ' banner ',
            'cover',
            'banner',
            '',
            'bad-name',
            ['nested'],
            '_hero',
            'field1',
            'field2',
            'field3',
            'field4',
            'field5',
            'field6',
            'field7',
            'field8',
            'field9',
        ];

        self::assertSame(
            ['banner', 'cover', '_hero', 'field1', 'field2', 'field3', 'field4', 'field5', 'field6', 'field7'],
            Settings::normalizeFieldNames($values)
        );
        self::assertSame(
            ['banner', 'cover', 'thumbnail'],
            Settings::normalizeFieldNames(' banner,cover,banner,bad-name,thumbnail ')
        );
    }

    public function testExplicitEmptyFieldConfigurationDoesNotRestoreDefaults(): void
    {
        self::assertSame([], (new Settings(['mediaFieldNames' => '']))->mediaFieldNames());
        self::assertSame([], (new Settings(['mediaFieldNames' => []]))->mediaFieldNames());
    }
}
