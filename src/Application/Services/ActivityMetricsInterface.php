<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

/**
 * What the activity sources offer now, by kind: which kinds have a source at all, and the
 * attributes and values a rule can filter by. Read through the core's ActivityReaderInterface,
 * once per request or queued job (bound "scoped").
 */
interface ActivityMetricsInterface
{
    /**
     * Kinds (MetricKinds values) with at least one source offered now.
     *
     * @return array<string, true>
     */
    public function kindsWithSource(): array;

    public function hasSource(string $kind): bool;

    /**
     * Whether a kind without a source now may get one back by itself: a source of it is registered
     * but cannot be offered (it failed to build or to give its label, two classes share its type),
     * or a registered class fails before its type can even be read. A kind nobody registers is not
     * pending: it waits for a module to be enabled.
     */
    public function isPending(string $kind): bool;

    /**
     * Kinds of the sources a module offers now.
     *
     * @return list<string>
     */
    public function kindsOfModule(string $module): array;

    /**
     * Whether a module registers any activity source, offered now or not.
     */
    public function moduleRegistersSources(string $module): bool;

    /**
     * The attributes the sources of a kind offer, key => label (the first source's label wins).
     *
     * @return array<string, string>
     */
    public function attributes(string $kind): array;

    /**
     * The values of one attribute over the sources of a kind, id => label.
     *
     * @return array<string, string>
     */
    public function attributeValues(string $kind, string $key): array;
}
