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
