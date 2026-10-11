<?php

namespace App\Livewire\Profile;

use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Settings extends Component
{
    public string $calendarView = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->calendarView = auth()->user()->calendarView();
    }

    public function updatedCalendarView(): void
    {
        $this->validate([
            'calendarView' => ['required', Rule::in(array_keys(User::CALENDAR_VIEWS))],
        ]);

        auth()->user()->update(['calendar_view' => $this->calendarView]);
        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.profile.settings', [
            'views' => User::CALENDAR_VIEWS,
        ]);
    }
}
