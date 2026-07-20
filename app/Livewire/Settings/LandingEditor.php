<?php

namespace App\Livewire\Settings;

use App\Shop\Models\LandingSection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class LandingEditor extends Component
{
    #[Computed]
    public function sections()
    {
        return LandingSection::ordered()->get();
    }

    public function render()
    {
        return view('livewire.settings.landing-editor');
    }
}
