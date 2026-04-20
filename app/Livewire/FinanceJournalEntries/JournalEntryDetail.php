<?php

namespace App\Livewire\FinanceJournalEntries;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\JournalEntry;

class JournalEntryDetail extends Component
{
    public ?JournalEntry $entry = null;

    #[On('view-journal-entry')]
    public function show(JournalEntry $entry): void
    {
        $this->entry = $entry->load(['lines.account', 'accountingPeriod', 'creator', 'postedBy']);
        $this->dispatch('open-modal', name: 'journal-entry-detail-modal');
    }

    public function render()
    {
        return view('livewire.finance-journal-entries.journal-entry-detail');
    }
}
