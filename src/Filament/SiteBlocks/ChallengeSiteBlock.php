<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\SiteBlocks;

use App\Application\Exceptions\NothingToDrawException;
use App\Application\SiteBlocks\Contracts\SiteBlockInterface;
use App\Domain\SiteBlocks\SiteBlockPlacement;
use Filament\Forms\Components\Select;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\ChallengeViewDTO;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ChallengeBoardInterface;
use Modules\Achievements\Infrastructure\Challenges\ChallengePresenter;

/**
 * A collective challenge and its progress bar (#12), for the footer, a popup or the link page. It
 * draws the challenge chosen in its settings while the site shows it, or the running one that ends
 * first; with none, nothing is drawn (title included). Only while the «Retos» switch is on. The
 * people who completed it are only counted here; `/logros` lists them.
 */
final readonly class ChallengeSiteBlock implements SiteBlockInterface
{
    public const string KEY = 'achievements.challenge';

    public function __construct(
        private AchievementsSettingsInterface $settings,
        private ChallengeBoardInterface $board,
        private ChallengePresenter $presenter,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) __('achievements::messages.challenges.block.label');
    }

    public function placements(): array
    {
        return [SiteBlockPlacement::Footer, SiteBlockPlacement::Popup, SiteBlockPlacement::LinkPage];
    }

    public function settingsSchema(): array
    {
        return [
            Select::make('challenge')
                ->label(__('achievements::messages.challenges.block.fields.challenge'))
                ->helperText(__('achievements::messages.challenges.block.helpers.challenge'))
                ->placeholder(__('achievements::messages.challenges.block.placeholder'))
                ->options(fn (): array => $this->board->blockOptions())
                ->native(false),
        ];
    }

    public function component(): string
    {
        return 'components/ChallengeBlock.vue';
    }

    public function isAvailable(): bool
    {
        return $this->settings->challengesEnabled();
    }

    public function props(array $settings, ?Authenticatable $user): array
    {
        $chosen = $settings['challenge'] ?? null;
        $view = $this->board->forBlock(is_string($chosen) ? $chosen : null, $user);

        if (! $view instanceof ChallengeViewDTO) {
            throw new NothingToDrawException;
        }

        return [
            'challenge' => $this->presenter->challenge($view),
            'url' => route('achievements.index'),
        ];
    }
}
