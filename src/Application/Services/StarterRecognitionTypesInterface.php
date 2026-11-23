<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

/**
 * The starter recognition types (#11): «Gran narración», «Buen compañero», «Ayuda a quien empieza»
 * and «Gran ambiente», each site's to edit, switch off or delete. Installing is idempotent: a type
 * of the set already there (by its starter key) is left as it is, edited or not.
 */
interface StarterRecognitionTypesInterface
{
    /**
     * Creates what is missing and returns the starter keys it created.
     *
     * @return list<string>
     */
    public function install(): array;
}
