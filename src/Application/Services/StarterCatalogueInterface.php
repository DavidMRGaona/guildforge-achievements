<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

/**
 * The starter catalogue: a set of achievements each site can edit or delete. Installing it is
 * idempotent: an achievement of the set already in the catalogue (by its starter key) is left as it
 * is, edited or not; only the missing ones are created, so running it again brings back one that was
 * deleted. Only those whose kind has a source offered now start active.
 */
interface StarterCatalogueInterface
{
    /**
     * Creates what is missing and returns the starter keys it created.
     *
     * @return list<string>
     */
    public function install(): array;

    /**
     * Every starter key, in the order they are created.
     *
     * @return list<string>
     */
    public function keys(): array;
}
