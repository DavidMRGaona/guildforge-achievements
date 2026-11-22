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
     * Whether a kind without a source now may get one back by itself: a type that wrote facts of it
     * to the ledger is registered but unavailable (`ActivitySourceRegistryInterface::isUnavailable()`:
     * it fails to build, or two classes share it). A kind nobody registers, or one with no facts to
     * evaluate, is not pending: enabling a module evaluates it.
     */
    public function isPending(string $kind): bool;

    /**
     * Kinds of the sources a module offers now.
     *
     * @return list<string>
     */
    public function kindsOfModule(string $module): array;

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
