<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Challenges;

use Modules\Achievements\Application\DTOs\ChallengeCompleterDTO;
use Modules\Achievements\Application\DTOs\ChallengeViewDTO;
use Modules\Achievements\Infrastructure\Profiles\ShowcasePresenter;

/**
 * A challenge as the browser gets it, picked field by field: totals, the viewer's own contribution
 * and, for `/logros`, the visible people who completed it by public name. Never a rule, an account
 * id or which subjects anybody took part in.
 */
final readonly class ChallengePresenter
{
    public function __construct(
        private ShowcasePresenter $achievements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function challenge(ChallengeViewDTO $view): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'description' => $view->description,
            'state' => $view->state->value,
            'counted' => $view->counted,
            'progress' => $view->progress,
            'target' => $view->target,
            'unit' => $view->unit,
            'startsOn' => $view->startsOn,
            'endsOn' => $view->endsOn,
            'showsYear' => $view->showsYear,
            'ended' => $view->ended,
            'membersOnly' => $view->membersOnly,
            'reward' => $this->achievements->achievement($view->reward),
            'contributors' => $view->contributors,
            'people' => array_map(static fn (ChallengeCompleterDTO $person): array => [
                'name' => $person->publicName,
                'url' => $person->url,
                'avatarUrl' => $person->avatarUrl,
            ], $view->people),
            'contribution' => $view->contribution,
        ];
    }
}
