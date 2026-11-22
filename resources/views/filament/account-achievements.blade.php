{{-- Inline styles: the panel theme does not compile Tailwind classes of modules --}}
<div style="display: grid; gap: 1.5rem; margin-top: 1.5rem;">
    @livewire(\Modules\Achievements\Filament\Livewire\AccountAchievements::NAME, ['userId' => $userId], key('achievements-account-grants-'.$userId))
    @livewire(\Modules\Achievements\Filament\Livewire\AccountGrantLog::NAME, ['userId' => $userId], key('achievements-account-log-'.$userId))
</div>
