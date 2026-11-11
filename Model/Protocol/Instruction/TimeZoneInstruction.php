<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Instruction;

use Hryvinskyi\McpServer\Model\Store\StoreTimeZonesInterface;

/**
 * Says that timestamps are UTC and which time zone the store runs in, so a day the user names is filtered on in UTC
 *
 * Magento keeps and returns timestamps such as `created_at` in UTC, while the user counts days in the store's own time
 * zone. Without this, a model filters "November 4" from midnight to midnight UTC and misses the evening orders of a
 * store west of UTC. When the store views run in different zones, each is named with its code.
 */
class TimeZoneInstruction implements InstructionInterface
{
    /**
     * @param StoreTimeZonesInterface $timeZones
     */
    public function __construct(
        private readonly StoreTimeZonesInterface $timeZones
    ) {
    }

    /**
     * @inheritDoc
     */
    public function text(): string
    {
        $zones = $this->timeZones->zones();
        $utc = 'Timestamps the abilities return and filter on, such as created_at and updated_at, are UTC.';
        $distinct = array_values(array_unique($zones));
        if ($distinct === []) {
            return $utc;
        }

        return $utc . ' ' . $this->zoneSentence($zones, $distinct)
            . ' Turn a day or period the user names into UTC bounds before you filter, and show times in store time.';
    }

    /**
     * The sentence that names the store's time zone, or the zone of each store view when they differ
     *
     * @param array<string,string> $zones
     * @param list<string> $distinct
     * @return string
     */
    private function zoneSentence(array $zones, array $distinct): string
    {
        if (count($distinct) === 1) {
            return sprintf('This store runs in the time zone %s.', $distinct[0]);
        }

        $named = [];
        foreach ($zones as $code => $zone) {
            $named[] = sprintf('%s: %s', $code, $zone);
        }

        return sprintf(
            'Its store views run in different time zones (%s); the store view of an order or a customer tells which '
            . 'applies.',
            implode(', ', $named)
        );
    }
}
