<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Confirmation;

use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;

/**
 * The SHA-256 of a call's arguments written as canonical JSON, so two calls with the same arguments have the same
 * fingerprint however their keys were ordered
 *
 * The members of every object are sorted by name, at every depth; the elements of a list keep their order, since the
 * order of a list is part of its value. The canonical value is written by the server's one JSON encoder.
 */
class ArgumentsFingerprint
{
    private const ALGORITHM = 'sha256';

    /**
     * @param JsonEncoder $encoder
     */
    public function __construct(
        private readonly JsonEncoder $encoder
    ) {
    }

    /**
     * The fingerprint of the arguments, as 64 lowercase hexadecimal characters
     *
     * @param array<string,mixed> $arguments The arguments as decoded from the call, keyed by name
     * @return string
     */
    public function of(array $arguments): string
    {
        return hash(self::ALGORITHM, $this->encoder->encode($this->canonical($arguments)));
    }

    /**
     * The value with the members of every object sorted by name
     *
     * @param mixed $value
     * @return mixed
     */
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $canonical = array_map(fn (mixed $element): mixed => $this->canonical($element), $value);
        if (!array_is_list($canonical)) {
            ksort($canonical, SORT_STRING);
        }

        return $canonical;
    }
}
