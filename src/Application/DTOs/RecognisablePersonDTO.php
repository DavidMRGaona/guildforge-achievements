<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

/**
 * Somebody the giver may recognise for a subject: never the account id, only what their profile
 * shows the giver (public name, address, avatar), and the type already given, if any.
 */
final readonly class RecognisablePersonDTO
{
    public function __construct(
        public string $handle,
        public string $publicName,
        public string $profileUrl,
        public ?string $avatarUrl,
        public ?string $givenTypeId,
    ) {}

    /**
     * @return array{handle: string, publicName: string, profileUrl: string, avatarUrl: string|null, givenTypeId: string|null}
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'publicName' => $this->publicName,
            'profileUrl' => $this->profileUrl,
            'avatarUrl' => $this->avatarUrl,
            'givenTypeId' => $this->givenTypeId,
        ];
    }
}
