<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;

/**
 * A hasher that counts its work, for tests about how much of it a code path
 * does. Hashes are transparent: "hash:" and the value.
 */
final class CountingHasher implements Hasher
{
    public int $makes = 0;
    public int $checks = 0;

    /**
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return ['algo' => 'counting'];
    }

    /**
     * @param array<mixed> $options
     */
    public function make($value, array $options = []): string
    {
        ++$this->makes;

        return 'hash:' . $value;
    }

    /**
     * @param array<mixed> $options
     */
    public function check($value, $hashedValue, array $options = []): bool
    {
        ++$this->checks;

        return $hashedValue === 'hash:' . $value;
    }

    /**
     * @param array<mixed> $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return false;
    }

    public function reset(): void
    {
        $this->makes = 0;
        $this->checks = 0;
    }
}
