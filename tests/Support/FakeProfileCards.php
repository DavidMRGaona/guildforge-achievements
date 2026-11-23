<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Profiles\DTOs\ProfileCardDTO;
use App\Application\Profiles\Services\ProfileCardServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The core's profile cards as a test says they are: public name, whether the card shows the
 * membership badge and whether it is the viewer's own. Any other account has no card.
 */
final readonly class FakeProfileCards implements ProfileCardServiceInterface
{
    /**
     * @param  array<string, array{string, bool, bool}>  $cards  account id (lower case) => [public name, shows membership, is the viewer's own]
     */
    public function __construct(
        private array $cards,
    ) {}

    public function card(string $userId, ?Authenticatable $viewer): ?ProfileCardDTO
    {
        $card = $this->cards[strtolower($userId)] ?? null;

        if ($card === null) {
            return null;
        }

        [$name, $showsMembership, $isOwner] = $card;

        return new ProfileCardDTO(
            handle: strtolower($name),
            publicName: $name,
            url: 'https://example.test/miembros/'.rawurlencode(strtolower($name)),
            avatarUrl: null,
            bannerUrl: null,
            memberSince: null,
            showsMembership: $showsMembership,
            isOwner: $isOwner,
            stats: [],
        );
    }
}
