<?php

namespace App\Livewire\Profile;

use App\Services\AvatarService;
use Livewire\Component;
use Livewire\WithFileUploads;

class AvatarUpload extends Component
{
    use WithFileUploads;

    public $photo;

    /** Upload starts as soon as a file is picked; save right away */
    public function updatedPhoto(AvatarService $service): void
    {
        $this->validate(
            ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192']],
            [
                'photo.image' => 'Bitte ein Bild auswählen (JPG, PNG oder WebP).',
                'photo.mimes' => 'Bitte ein Bild auswählen (JPG, PNG oder WebP).',
                'photo.max' => 'Das Bild ist zu groß (maximal 8 MB).',
            ],
        );

        $service->store(auth()->user(), $this->photo);
        $this->reset('photo');
        $this->redirectRoute('profile');
    }

    public function remove(AvatarService $service): void
    {
        $service->delete(auth()->user());
        $this->redirectRoute('profile');
    }

    public function render()
    {
        return view('livewire.profile.avatar-upload', ['user' => auth()->user()]);
    }
}
