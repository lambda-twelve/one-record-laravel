<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Unit\Config;

use InvalidArgumentException;
use LambdaTwelve\OneRecord\Laravel\Config\ServerConfigFactory;
use LambdaTwelve\OneRecord\Spec\ApiVersion;
use LambdaTwelve\OneRecord\Spec\DataModelVersion;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerConfigFactory::class)]
final class ServerConfigFactoryTest extends TestCase
{
    public function testTranslatesTheConfigArrayIntoTheSdkConfig(): void
    {
        $config = ServerConfigFactory::fromArray([
            'base_url' => 'https://1r.example.com/',
            'base_path' => 'one-record/',
            'data_holder' => 'holder',
            'data_holder_type' => Cargo::Company,
            'api_versions' => '2.3.0',
            'data_model_versions' => ['3.2', '3.3'],
            'languages' => ['en-US', 'de-DE'],
            'max_body_bytes' => 2048,
            'embedded_depth' => 1,
            'bulk_logistics_events' => true,
        ]);

        self::assertSame('https://1r.example.com', $config->baseUrl);
        self::assertSame('/one-record', $config->basePath);
        self::assertSame('https://1r.example.com/one-record', $config->endpoint());
        self::assertSame('https://1r.example.com/one-record/logistics-objects/holder', $config->dataHolder->value);
        self::assertSame(Cargo::Company, $config->dataHolderType);
        self::assertSame([ApiVersion::V2_3_0], $config->apiVersions);
        self::assertSame([DataModelVersion::V3_3, DataModelVersion::V3_2], $config->dataModelVersions);
        self::assertSame(['en-US', 'de-DE'], $config->languages);
        self::assertSame(2048, $config->maxBodyBytes);
        self::assertSame(1, $config->embeddedDepth);
        self::assertTrue($config->bulkLogisticsEvents);
    }

    public function testDefaultsMatchTheSdkDefaults(): void
    {
        $config = ServerConfigFactory::fromArray(['base_url' => 'http://localhost:8080', 'base_path' => '', 'data_holder' => 'https://holder.example/org']);

        self::assertSame('http://localhost:8080', $config->baseUrl);
        self::assertSame('', $config->basePath);
        self::assertSame('https://holder.example/org', $config->dataHolder->value);
        self::assertSame(ApiVersion::allDescending(), $config->apiVersions);
        self::assertSame(['en-US'], $config->languages);
        self::assertSame(1_048_576, $config->maxBodyBytes);
        self::assertNull($config->dataHolderType);
        self::assertFalse($config->bulkLogisticsEvents);
    }

    public function testProblemsNameWhatIsMissingWithoutConstructingAnything(): void
    {
        $valid = ['base_url' => 'https://1r.test', 'base_path' => '/one-record', 'data_holder' => 'holder'];
        self::assertSame([], ServerConfigFactory::problems($valid));

        $problems = ServerConfigFactory::problems(['base_url' => 'https://1r.test', 'base_path' => '/one-record', 'data_holder' => null]);
        self::assertCount(1, $problems);
        self::assertStringContainsString('ONE_RECORD_DATA_HOLDER', $problems[0], 'this factory names the variable to set');

        $problems = ServerConfigFactory::problems(['base_url' => 'https://1r.test/app', 'data_holder' => 'holder', 'api_versions' => '9.9.9']);
        self::assertCount(2, $problems, 'every translation problem, not just the first');
        self::assertStringContainsString('base_path', $problems[0]);
        self::assertStringContainsString('9.9.9', $problems[1]);

        $problems = ServerConfigFactory::problems($valid + ['languages' => ['de-DE']]);
        self::assertCount(1, $problems);
        self::assertStringContainsString('en-US', $problems[0], 'what translates cleanly is judged by the SDK');
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testRefusesConfigurationTheSdkCouldNotUse(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ServerConfigFactory::fromArray($config + ['base_url' => 'https://1r.example.com', 'base_path' => '/one-record', 'data_holder' => 'holder']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'missing base url' => [['base_url' => null], 'base_url must be set'];
        yield 'base url with a path' => [['base_url' => 'https://1r.example.com/app'], 'scheme and host only'];
        yield 'base url without scheme' => [['base_url' => '1r.example.com'], 'http(s) URL'];
        yield 'missing data holder' => [['data_holder' => null], 'data_holder must be set'];
        yield 'unknown api version' => [['api_versions' => ['9.9.9']], 'does not know'];
        yield 'unknown data model version' => [['data_model_versions' => '1.0'], 'does not know'];
        yield 'languages without en-US' => [['languages' => ['de-DE']], 'en-US'];
    }
}
