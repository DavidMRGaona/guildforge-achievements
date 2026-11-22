<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Application\Activity\DTOs\ActivitySubjectDTO;
use App\Application\Activity\Services\ActivityReaderInterface;
use App\Domain\Activity\ActivityRole;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\Achievements\Application\DTOs\BulkGrantCountDTO;
use Modules\Achievements\Application\DTOs\BulkGrantRequestDTO;
use Modules\Achievements\Application\Enums\BulkGrantOutcome;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\BulkGrantInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\ValueObjects\GrantReason;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Policies\AchievementPolicy;

/**
 * The grant actions of the panel, shared by the achievement page (who holds it) and the account page.
 * Each is authorized by AchievementPolicy, which Filament checks both to show it and again when it
 * runs; none is marked as reading, so a read-only impersonation refuses them all.
 */
final class GrantActions
{
    public const string GRANT = 'grantAchievement';

    public const string REVOKE = 'revokeGrant';

    public const string BULK = 'bulkGrant';

    private const int MAX_ACCOUNTS = 50;

    /**
     * Whether the bulk dialog says how many already hold the achievement or had it taken away
     * (previewLines()). Pending a product decision: false shows only how many took part.
     */
    private const bool PREVIEW_BREAKDOWN = true;

    /**
     * «Conceder», from an achievement: to one account found by its name, among those the person may
     * see (UserPolicy::view(), as on the account page).
     *
     * @param  Closure(): AchievementModel  $achievement
     */
    public static function grantToAccount(Closure $achievement): Action
    {
        return Action::make(self::GRANT)
            ->label(self::text('grant.label'))
            ->icon('heroicon-o-plus')
            ->authorize(static fn (): bool => Gate::allows('grant', $achievement()))
            ->modalHeading(self::text('grant.heading'))
            ->modalSubmitActionLabel(self::text('grant.submit'))
            ->schema([
                Select::make('user_id')
                    ->label(self::text('grant.account'))
                    ->helperText(self::text('grant.account_helper'))
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => self::accountsWithout($achievement(), $search))
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => self::visibleAccountName($value))
                    ->required()
                    ->rules([
                        static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($achievement): void {
                            if (! is_string($value) || ! self::mayReceive($achievement(), $value)) {
                                $fail(self::text('grant.not_granted'));
                            }
                        },
                    ]),
                ...self::grantFields(),
            ])
            ->action(static function (array $data) use ($achievement): void {
                self::grantByHand($achievement(), (string) $data['user_id'], $data);
            });
    }

    /**
     * «Conceder un logro», from an account: one of the achievements the signed-in person may grant
     * that the account does not hold yet.
     *
     * @param  Closure(): string  $userId
     */
    public static function grantAchievementTo(Closure $userId): Action
    {
        return Action::make(self::GRANT)
            ->label(self::text('grant.label'))
            ->icon('heroicon-o-plus')
            ->authorize(static fn (): bool => self::viewer() instanceof UserModel && AchievementPolicy::grantsAny(self::viewer()))
            ->modalHeading(self::text('grant.account_heading'))
            ->modalSubmitActionLabel(self::text('grant.submit'))
            ->schema([
                Select::make('achievement_id')
                    ->label(self::text('grant.achievement'))
                    ->helperText(self::text('grant.achievement_helper'))
                    ->options(static fn (): array => self::grantableFor($userId()))
                    ->searchable()
                    ->native(false)
                    ->required(),
                ...self::grantFields(),
            ])
            ->action(static function (array $data) use ($userId): void {
                $achievement = AchievementModel::query()->find((string) $data['achievement_id']);

                // The options already left it out; checked again, since the list was drawn earlier
                if (! $achievement instanceof AchievementModel || ! Gate::allows('grant', $achievement)) {
                    Notification::make()->title(self::text('grant.refused'))->danger()->send();

                    return;
                }

                self::grantByHand($achievement, $userId(), $data);
            });
    }

    /**
     * «Retirar», on a grant: dialog, a reason and a box that must be ticked.
     */
    public static function revoke(): Action
    {
        return Action::make(self::REVOKE)
            ->label(self::text('revoke.label'))
            ->icon('heroicon-o-minus-circle')
            ->color('danger')
            // Only on an account the person may see (UserPolicy::view()), as everywhere else
            ->authorize(static fn (AchievementGrantModel $record): bool => $record->achievement instanceof AchievementModel
                && Gate::allows('revoke', $record->achievement)
                && $record->user instanceof UserModel
                && Gate::allows('view', $record->user))
            ->modalHeading(self::text('revoke.heading'))
            ->modalDescription(self::text('revoke.description'))
            ->modalIcon('heroicon-o-minus-circle')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel(self::text('revoke.submit'))
            ->schema([
                Textarea::make('reason')
                    ->label(self::text('revoke.reason'))
                    ->helperText(self::text('revoke.reason_helper'))
                    ->required()
                    ->maxLength(GrantReason::MAX_LENGTH)
                    ->rows(2)
                    ->rules([
                        static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            if (! is_string($value) || GrantReason::normalise($value) === null) {
                                $fail(__('validation.required', ['attribute' => self::text('revoke.reason')]));
                            }
                        },
                    ]),
                Checkbox::make('confirmation')
                    ->label(self::text('revoke.confirmation'))
                    ->accepted()
                    ->required(),
            ])
            ->action(static function (AchievementGrantModel $record, array $data): void {
                $revoked = app(AchievementGranterInterface::class)->revoke((string) $record->id, (string) Auth::id(), (string) $data['reason']);

                $notification = Notification::make();
                $revoked instanceof AchievementGrant
                    ? $notification->title(self::text('revoke.done'))->success()
                    : $notification->title(self::text('revoke.gone'))->warning();
                $notification->send();
            });
    }

    /**
     * «Conceder en lote», from an achievement: to whoever held a role in one subject of an activity
     * source. Two steps (what, then the count and a box that must be ticked); only numbers are shown,
     * never who. Offered while the switch is on; the grant itself reads the settings strictly.
     *
     * @param  Closure(): AchievementModel  $achievement
     */
    public static function bulkGrant(Closure $achievement): Action
    {
        return Action::make(self::BULK)
            ->label(self::text('bulk.label'))
            ->icon('heroicon-o-user-group')
            ->color('gray')
            ->authorize(static fn (): bool => Gate::allows('grant', $achievement()))
            ->visible(static fn (): bool => app(AchievementsSettingsInterface::class)->bulkGrantsEnabled())
            ->modalHeading(self::text('bulk.heading'))
            ->modalDescription(self::text('bulk.description'))
            ->modalSubmitActionLabel(self::text('bulk.submit'))
            ->steps([
                Step::make(self::text('bulk.steps.subject'))
                    ->schema([
                        Select::make('source_type')
                            ->label(self::text('bulk.source'))
                            ->options(static fn (): array => self::sourceOptions())
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(static fn (Set $set): mixed => $set('subject_id', null)),
                        Select::make('subject_id')
                            ->label(self::text('bulk.subject'))
                            ->helperText(self::text('bulk.subject_helper'))
                            ->searchable()
                            ->options(static fn (Get $get): array => self::subjectOptions($get('source_type'), null))
                            ->getSearchResultsUsing(static fn (string $search, Get $get): array => self::subjectOptions($get('source_type'), $search))
                            ->getOptionLabelUsing(static fn (mixed $value, Get $get): ?string => self::subjectTitle($get('source_type'), $value))
                            ->required()
                            ->live()
                            ->rules([
                                static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (self::subjectTitle($get('source_type'), $value) === null) {
                                        $fail(self::text('bulk.refused.subject_not_visible'));
                                    }
                                },
                            ]),
                        Select::make('role')
                            ->label(self::text('bulk.role'))
                            ->options(AchievementRuleForm::roleOptions())
                            ->native(false)
                            ->required()
                            ->live(),
                    ]),
                Step::make(self::text('bulk.steps.confirm'))
                    ->schema([
                        Text::make(static fn (Get $get): string => self::previewText($achievement(), $get)),
                        Text::make(self::text('bulk.preview.privacy')),
                        Radio::make('notice')
                            ->label(self::text('bulk.notice'))
                            ->options([
                                'silent' => self::text('bulk.notices.silent'),
                                'notify' => self::text('bulk.notices.notify'),
                            ])
                            ->required(),
                        Textarea::make('reason')
                            ->label(self::text('bulk.reason'))
                            ->helperText(self::text('bulk.reason_helper'))
                            ->maxLength(GrantReason::MAX_LENGTH)
                            ->rows(2),
                        Checkbox::make('confirmation')
                            ->label(self::text('bulk.confirmation'))
                            ->accepted()
                            ->required(),
                    ]),
            ])
            ->action(static function (array $data) use ($achievement): void {
                $actor = self::viewer();
                $role = ActivityRole::tryFrom((string) ($data['role'] ?? ''));

                if (! $actor instanceof UserModel || ! $role instanceof ActivityRole) {
                    return;
                }

                $result = app(BulkGrantInterface::class)->grant(
                    new BulkGrantRequestDTO((string) $data['source_type'], (string) $data['subject_id'], $role, (string) $achievement()->id),
                    silent: ($data['notice'] ?? 'silent') !== 'notify',
                    reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
                    actor: $actor,
                );

                if ($result->outcome === BulkGrantOutcome::Partial) {
                    Notification::make()
                        ->title(trans_choice('achievements::messages.grants.bulk.partial', $result->granted, ['count' => $result->granted]))
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                if ($result->outcome !== BulkGrantOutcome::Granted) {
                    Notification::make()->title(self::refusal($result))->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(trans_choice('achievements::messages.grants.bulk.done', $result->granted, ['count' => $result->granted]))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<Textarea|Toggle>
     */
    private static function grantFields(): array
    {
        return [
            Textarea::make('reason')
                ->label(self::text('grant.reason'))
                ->helperText(self::text('grant.reason_helper'))
                ->maxLength(GrantReason::MAX_LENGTH)
                ->rows(2),
            Toggle::make('notify')
                ->label(self::text('grant.notify'))
                ->helperText(self::text('grant.notify_helper'))
                ->default(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function grantByHand(AchievementModel $achievement, string $userId, array $data): void
    {
        $granted = app(AchievementGranterInterface::class)->grant(new NewGrant(
            userId: $userId,
            achievementId: (string) $achievement->id,
            source: GrantSource::Manual,
            silent: ! (bool) ($data['notify'] ?? true),
            actorId: (string) Auth::id(),
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
        ));

        $notification = Notification::make();
        $granted instanceof AchievementGrant
            ? $notification->title(self::text('grant.done'))->success()
            : $notification->title(self::text('grant.not_granted'))->warning();
        $notification->send();
    }

    /**
     * Accounts whose name matches, that are not anonymized, do not hold the achievement and the person
     * may see.
     *
     * @return array<string, string>
     */
    private static function accountsWithout(AchievementModel $achievement, string $search): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $viewer = self::viewer();

        if (! $viewer instanceof UserModel) {
            return [];
        }

        $pattern = '%'.addcslashes(mb_strtolower($search), '!%_').'%';

        return UserModel::query()
            ->whereNull('anonymized_at')
            ->where(static function (Builder $query) use ($pattern): void {
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(display_name, '')) LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->whereNotIn('id', AchievementGrantModel::query()->select('user_id')->where('achievement_id', $achievement->id)->where('season_key', ''))
            ->orderBy('name')
            ->limit(self::MAX_ACCOUNTS)
            ->get(['id', 'name'])
            // The same check as the account page: only accounts the person may see (UserPolicy::view())
            ->filter(static fn (UserModel $user): bool => $viewer->can('view', $user))
            ->mapWithKeys(static fn (UserModel $user): array => [(string) $user->id => $user->name])
            ->all();
    }

    private static function mayReceive(AchievementModel $achievement, string $userId): bool
    {
        $viewer = self::viewer();
        $user = UserModel::query()->whereKey($userId)->whereNull('anonymized_at')->first();

        return $viewer instanceof UserModel
            && $user instanceof UserModel
            && $viewer->can('view', $user)
            && AchievementGrantModel::query()->where('achievement_id', $achievement->id)->where('user_id', strtolower($userId))->where('season_key', '')->doesntExist();
    }

    /**
     * Active achievements the signed-in person may grant that the account does not hold, by name.
     *
     * @return array<string, string>
     */
    private static function grantableFor(string $userId): array
    {
        $held = AchievementGrantModel::query()->where('user_id', strtolower($userId))->where('season_key', '')->pluck('achievement_id')->all();
        $options = [];

        foreach (AchievementModel::query()->where('is_active', true)->whereNotIn('id', $held)->orderBy('name')->get() as $achievement) {
            if (Gate::allows('grant', $achievement)) {
                $options[(string) $achievement->id] = $achievement->name;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function sourceOptions(): array
    {
        $options = [];

        foreach (app(ActivityReaderInterface::class)->sources() as $source) {
            $options[$source->type] = $source->label;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function subjectOptions(mixed $sourceType, ?string $search): array
    {
        $viewer = self::viewer();

        if (! is_string($sourceType) || $sourceType === '' || ! $viewer instanceof UserModel) {
            return [];
        }

        $options = [];

        foreach (app(BulkGrantInterface::class)->subjects($sourceType, $search, $viewer) as $subject) {
            $options[$subject->subjectId] = $subject->title;
        }

        return $options;
    }

    private static function subjectTitle(mixed $sourceType, mixed $subjectId): ?string
    {
        $viewer = self::viewer();

        if (! is_string($sourceType) || ! is_scalar($subjectId) || ! $viewer instanceof UserModel) {
            return null;
        }

        $subject = app(BulkGrantInterface::class)->subject($sourceType, (string) $subjectId, $viewer);

        return $subject instanceof ActivitySubjectDTO ? $subject->title : null;
    }

    /**
     * «Se concederá a 12 personas…»: numbers only, never who.
     */
    private static function previewText(AchievementModel $achievement, Get $get): string
    {
        $viewer = self::viewer();
        $sourceType = $get('source_type');
        $subjectId = $get('subject_id');
        $role = ActivityRole::tryFrom(is_string($get('role')) ? $get('role') : '');

        if (! $viewer instanceof UserModel || ! is_string($sourceType) || ! is_scalar($subjectId) || ! $role instanceof ActivityRole) {
            return '';
        }

        $preview = app(BulkGrantInterface::class)->preview(
            new BulkGrantRequestDTO($sourceType, (string) $subjectId, $role, (string) $achievement->id),
            $viewer,
        );

        if ($preview->outcome !== BulkGrantOutcome::Ready) {
            return self::refusal($preview);
        }

        return implode(' ', self::previewLines($preview, self::PREVIEW_BREAKDOWN));
    }

    /**
     * What the dialog says before granting, in numbers only. With the breakdown, how many get it,
     * how many already hold it and how many had it taken away; without it, only how many took part
     * with the role (so a subject with one participant never tells whether that person holds it).
     *
     * @return list<string>
     */
    public static function previewLines(BulkGrantCountDTO $preview, bool $breakdown): array
    {
        if (! $breakdown) {
            return [trans_choice('achievements::messages.grants.bulk.preview.total', $preview->people, ['count' => $preview->people])];
        }

        $lines = [trans_choice('achievements::messages.grants.bulk.preview.people', $preview->toGrant(), ['count' => $preview->toGrant()])];

        if ($preview->alreadyHeld > 0) {
            $lines[] = trans_choice('achievements::messages.grants.bulk.preview.held', $preview->alreadyHeld, ['count' => $preview->alreadyHeld]);
        }

        if ($preview->revoked > 0) {
            $lines[] = trans_choice('achievements::messages.grants.bulk.preview.revoked', $preview->revoked, ['count' => $preview->revoked]);
        }

        return $lines;
    }

    private static function refusal(BulkGrantCountDTO $result): string
    {
        return (string) __('achievements::messages.grants.bulk.refused.'.$result->outcome->value, [
            'count' => $result->people,
            'max' => BulkGrantInterface::MAX_PEOPLE,
        ]);
    }

    /**
     * The name of an account the signed-in person may see, or null.
     */
    private static function visibleAccountName(mixed $userId): ?string
    {
        $viewer = self::viewer();

        if (! is_string($userId) || ! $viewer instanceof UserModel) {
            return null;
        }

        $user = UserModel::query()->whereKey($userId)->first(['id', 'name']);

        return $user instanceof UserModel && $viewer->can('view', $user) ? $user->name : null;
    }

    private static function viewer(): ?UserModel
    {
        $viewer = Auth::user();

        return $viewer instanceof UserModel ? $viewer : null;
    }

    private static function text(string $key): string
    {
        return (string) __('achievements::messages.grants.'.$key);
    }
}
