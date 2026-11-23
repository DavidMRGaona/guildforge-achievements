<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Recognitions\Recognition;
use Modules\Achievements\Domain\Recognitions\RecognitionType;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use Modules\Achievements\Infrastructure\Recognitions\RecognisablePeople;
use Modules\Achievements\Notifications\RecognitionReceivedNotification;

/**
 * Tells the receiver of one recognition (#11), after the commit. Nothing when it was deleted
 * meanwhile, recognitions were switched off, or the receiver is deactivated or anonymized.
 */
final class NotifyRecognitionReceivedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $recognitionId,
    ) {
        $this->afterCommit();
    }

    public function handle(RecognitionRepositoryInterface $recognitions, AchievementsSettingsInterface $settings, RecognisablePeople $people): void
    {
        $recognition = $recognitions->find($this->recognitionId);

        if (! $recognition instanceof Recognition || ! $settings->recognitionsEnabled()) {
            return;
        }

        $receiver = UserModel::query()->find($recognition->receiverId);
        $type = $recognitions->types()[$recognition->typeId] ?? null;

        if (! $receiver instanceof UserModel || $receiver->anonymized_at !== null || ! $type instanceof RecognitionType) {
            return;
        }

        $giverName = $people->named([$recognition->giverId], $receiver)[$recognition->giverId]['publicName'] ?? null;

        $receiver->notify(RecognitionReceivedNotification::for($giverName, $type->name));
    }
}
