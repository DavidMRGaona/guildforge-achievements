<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Achievements\Application\DTOs\OwnRecognitionDTO;
use Modules\Achievements\Application\DTOs\RecognitionSubjectDTO;
use Modules\Achievements\Application\DTOs\RecognitionTypeViewDTO;
use Modules\Achievements\Application\Enums\GiveRecognitionOutcome;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\RecognitionsInterface;
use Modules\Achievements\Domain\Recognitions\RecognitionType;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use Modules\Achievements\Http\Requests\GiveRecognitionRequest;

/**
 * `/logros/reconocimientos` (#11), for the signed-in account (the routes' `auth`, and the core's
 * email verification on them): the sessions and campaigns it may still recognise people of, what it
 * received (with who gave it) and what it gave. 404 while recognitions are off.
 *
 * Services are resolved per call: the route keeps its controller, and the service is scoped.
 */
final class RecognitionsController extends Controller
{
    public function index(Request $request, RecognitionsInterface $recognitions, RecognitionRepositoryInterface $repository, AchievementsSettingsInterface $settings, SeoMetadataFactoryInterface $seo): Response
    {
        abort_unless($settings->recognitionsEnabled(), 404);
        $viewer = $request->user();
        abort_if($viewer === null, 404);

        $types = array_values(array_filter($repository->types(), static fn (RecognitionType $type): bool => $type->active));

        return Inertia::render('Achievements/Recognitions', [
            'types' => array_map(static fn (RecognitionType $type): array => RecognitionTypeViewDTO::of($type)->toArray(), $types),
            'subjects' => array_map(static fn (RecognitionSubjectDTO $subject): array => $subject->toArray(), $recognitions->openSubjects($viewer)),
            'received' => array_map(static fn (OwnRecognitionDTO $one): array => $one->toArray(), $recognitions->received($viewer)),
            'given' => array_map(static fn (OwnRecognitionDTO $one): array => $one->toArray(), $recognitions->given($viewer)),
            'windowDays' => $settings->recognitionsWindowDays(),
            'storeUrl' => route('achievements.recognitions.store', absolute: false),
            'seo' => $seo->forPage(new SeoPageDTO(
                title: __('achievements::messages.recognitions.page.title'),
                description: __('achievements::messages.recognitions.page.description'),
            ))->toArray(),
        ]);
    }

    public function store(GiveRecognitionRequest $request, RecognitionsInterface $recognitions, AchievementsSettingsInterface $settings): RedirectResponse
    {
        abort_unless($settings->recognitionsEnabled(), 404);
        $giver = $request->user();
        abort_if($giver === null, 404);

        $outcome = $recognitions->give(
            $giver,
            $request->string('sourceType')->toString(),
            $request->string('subjectId')->toString(),
            $request->string('receiver')->toString(),
            $request->string('type')->toString(),
        );

        return match ($outcome) {
            GiveRecognitionOutcome::Given => back(303)->with('success', __('achievements::messages.recognitions.page.given')),
            GiveRecognitionOutcome::AlreadyGiven => back(303)->with('info', __('achievements::messages.recognitions.page.already_given')),
            GiveRecognitionOutcome::Refused => back(303)->with('error', __('achievements::messages.recognitions.page.refused')),
        };
    }

    public function destroy(Request $request, string $recognition, RecognitionsInterface $recognitions, AchievementsSettingsInterface $settings): RedirectResponse
    {
        abort_unless($settings->recognitionsEnabled(), 404);
        $owner = $request->user();
        abort_if($owner === null, 404);

        // Somebody else's, or gone: the same 404, so an id never tells whose it is
        abort_unless($recognitions->deleteReceived($owner, $recognition), 404);

        return back(303)->with('success', __('achievements::messages.recognitions.page.deleted'));
    }
}
