<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Activity;

use App\Application\Activity\Services\ActivityReaderInterface;
use App\Application\Activity\Services\ActivitySourceRegistryInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;

final class ReaderActivityMetrics implements ActivityMetricsInterface
{
    /** A type nobody offers: the registry says it is unavailable while some class cannot even be read */
    private const string PROBE_TYPE = 'achievements.unoffered-probe';

    /** @var array<string, list<string>>|null kind => source types */
    private ?array $types = null;

    /** @var array<string, list<string>>|null module => kinds */
    private ?array $modules = null;

    /** @var array<string, array<string, string>> */
    private array $attributes = [];

    /** @var array<string, array<string, string>> */
    private array $values = [];

    public function __construct(
        private readonly ActivityReaderInterface $reader,
        private readonly ActivitySourceRegistryInterface $registry,
        private readonly AchievementFactRepositoryInterface $facts,
    ) {}

    public function kindsWithSource(): array
    {
        return array_fill_keys(array_keys($this->types()), true);
    }

    public function hasSource(string $kind): bool
    {
        return isset($this->types()[$kind]);
    }

    public function isPending(string $kind): bool
    {
        if ($this->hasSource($kind)) {
            return false;
        }

        // Registered and built, yet left out of the reader's list (its label failed)
        foreach ($this->registry->all() as $registration) {
            if ($registration->kind->value === $kind) {
                return true;
            }
        }

        // A type that wrote facts of this kind before and is unavailable now (failing, duplicated)
        foreach ($this->facts->sourceTypesOfKind($kind) as $type) {
            if ($this->registry->isUnavailable($type)) {
                return true;
            }
        }

        return $this->registry->isUnavailable(self::PROBE_TYPE);
    }

    public function kindsOfModule(string $module): array
    {
        if ($this->modules === null) {
            $this->modules = [];

            foreach ($this->reader->sources() as $source) {
                if ($source->module !== null) {
                    $this->modules[$source->module][$source->kind->value] = $source->kind->value;
                }
            }

            $this->modules = array_map(array_values(...), $this->modules);
        }

        return $this->modules[$module] ?? [];
    }

    public function moduleRegistersSources(string $module): bool
    {
        foreach ($this->registry->all() as $registration) {
            if ($registration->module === $module) {
                return true;
            }
        }

        return $this->kindsOfModule($module) !== [] || $this->registry->isUnavailable(self::PROBE_TYPE);
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
