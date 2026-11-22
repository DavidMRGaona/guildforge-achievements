<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Activity;

use App\Application\Activity\Services\ActivityReaderInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;

final class ReaderActivityMetrics implements ActivityMetricsInterface
{
    /** @var array<string, list<string>>|null kind => source types */
    private ?array $types = null;

    /** @var array<string, array<string, string>> */
    private array $attributes = [];

    /** @var array<string, array<string, string>> */
    private array $values = [];

    public function __construct(
        private readonly ActivityReaderInterface $reader,
    ) {}

    public function kindsWithSource(): array
    {
        return array_fill_keys(array_keys($this->types()), true);
    }

    public function hasSource(string $kind): bool
    {
        return isset($this->types()[$kind]);
    }

    public function attributes(string $kind): array
    {
        if (! isset($this->attributes[$kind])) {
            $attributes = [];

            foreach ($this->types()[$kind] ?? [] as $type) {
                foreach ($this->reader->attributes($type) as $attribute) {
                    $attributes[$attribute->key] ??= $attribute->label;
                }
            }

            $this->attributes[$kind] = $attributes;
        }

        return $this->attributes[$kind];
    }

    public function attributeValues(string $kind, string $key): array
    {
        $cacheKey = $kind."\0".$key;

        if (! isset($this->values[$cacheKey])) {
            $values = [];

            foreach ($this->types()[$kind] ?? [] as $type) {
                foreach ($this->reader->attributeValues($type, $key) as $value) {
                    $values[$value->id] ??= $value->label;
                }
            }

            asort($values, SORT_NATURAL | SORT_FLAG_CASE);
            $this->values[$cacheKey] = $values;
        }

        return $this->values[$cacheKey];
    }

    /**
     * @return array<string, list<string>>
     */
    private function types(): array
    {
        if ($this->types === null) {
            $this->types = [];

            foreach ($this->reader->sources() as $source) {
                $this->types[$source->kind->value][] = $source->type;
            }
        }

        return $this->types;
    }
}
