<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Achievements\Application\DTOs\LeaderboardEntryDTO;
use Modules\Achievements\Application\DTOs\LeaderboardPageDTO;
use Modules\Achievements\Application\Services\AchievementLeaderboardInterface;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\PointsCutoffInterface;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * `/logros/clasificacion` (#10): signed-in accounts that need not verify their email first (the
 * route's `auth`, and the core's verification middleware on it). 404 while points or the
 * leaderboard are off, and for a season that is not offered.
 *
 * `?temporada=<year>` shows one season (while seasons are on); without it, all time.
 */
final class LeaderboardController extends Controller
{
    /**
     * Resolved per call, never in the constructor: the route keeps its controller, and the points
     * service remembers what is secret for one request only.
     */
    public function __invoke(
        Request $request,
        AchievementLeaderboardInterface $leaderboard,
        AchievementPointsInterface $points,
        AchievementsSettingsInterface $settings,
        SeoMetadataFactoryInterface $seo,
        AchievementWarnings $warnings,
        PointsCutoffInterface $cutoff,
    ): Response {
        abort_unless($settings->leaderboardShown(), 404);

        $viewer = $request->user();
        abort_if($viewer === null, 404);

        $seasons = [];
        $season = null;

        try {
            // The seasons others see: a grant of today to an old season adds nothing before the night
            $cut = $cutoff->takenAt();
            $seasons = $settings->seasonsAndStreaksEnabled() && $cut instanceof DateTimeImmutable ? $points->seasons($cut) : [];
            $season = $this->season($request->query('temporada'), $seasons);
            $page = $leaderboard->page($viewer, $points->period($season), max(1, $request->integer('page', 1)));
        } catch (HttpExceptionInterface $notFound) {
            throw $notFound;
        } catch (Throwable $exception) {
            // Fails closed: nobody shown and nothing said about the viewer, never a 500
            $warnings->warn('leaderboard_unavailable', '[Achievements] The leaderboard could not be worked out; nobody is shown.', [
                'reason' => 'leaderboard_unavailable',
                'exception' => $exception::class,
            ]);
            $page = LeaderboardPageDTO::unavailable();
        }

        return Inertia::render('Achievements/Leaderboard', [
            'entries' => array_map(static fn (LeaderboardEntryDTO $entry): array => $entry->toArray(), $page->entries),
            'pagination' => [
                'page' => $page->page,
                'lastPage' => $page->lastPage,
                'total' => $page->total,
                'perPage' => AchievementLeaderboardInterface::PER_PAGE,
            ],
            'viewer' => [
                'entry' => $page->viewer?->toArray(),
                'status' => $page->viewerStatus?->value,
            ],
            'available' => $page->available,
            'season' => $season,
            'seasons' => $seasons,
            'currentSeason' => $points->currentSeason(),
            'showsLevels' => $settings->levelsShown(),
            'catalogueUrl' => route('achievements.index', absolute: false),
            'leaderboardUrl' => route('achievements.leaderboard', absolute: false),
            'seo' => $seo->forPage(new SeoPageDTO(
                title: __('achievements::messages.pages.leaderboard_title'),
                description: __('achievements::messages.pages.leaderboard_description'),
            ))->toArray(),
        ]);
    }

    /**
     * The season asked for, or null for all time; one that is not offered answers 404.
     *
     * @param  list<int>  $seasons
     */
    private function season(mixed $asked, array $seasons): ?int
    {
        if ($asked === null || $asked === '') {
            return null;
        }

        abort_unless(is_string($asked) && preg_match('/^\d{4}$/D', $asked) === 1, 404);
        abort_unless(in_array((int) $asked, $seasons, true), 404);

        return (int) $asked;
    }
}
