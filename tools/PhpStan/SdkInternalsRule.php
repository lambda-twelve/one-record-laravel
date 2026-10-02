<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Keeps this package thin: code under src/ may use the SDK's public surface
 * (wiring, SPI, model, API documents, auth) but never its server internals.
 * If something here needs an endpoint, the HTTP helpers, the router or the
 * change applier, the logic belongs in the SDK.
 *
 * @implements Rule<Name>
 */
final class SdkInternalsRule implements Rule
{
    private const array FORBIDDEN_PREFIXES = [
        'LambdaTwelve\\OneRecord\\Server\\Endpoint\\',
        'LambdaTwelve\\OneRecord\\Server\\Http\\',
        'LambdaTwelve\\OneRecord\\Server\\Router',
        'LambdaTwelve\\OneRecord\\Change\\ChangeApplier',
    ];

    public function getNodeType(): string
    {
        return Name::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_contains(str_replace('\\', '/', $scope->getFile()), '/src/')) {
            return [];
        }
        $name = $node->toString();
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if ($name === rtrim($prefix, '\\') || str_starts_with($name, $prefix)) {
                return [
                    RuleErrorBuilder::message(\sprintf('%s is an SDK server internal. Protocol logic belongs in lambda-twelve/one-record, not in the Laravel adapter.', $name))
                        ->identifier('oneRecordLaravel.sdkInternals')
                        ->build(),
                ];
            }
        }

        return [];
    }
}
