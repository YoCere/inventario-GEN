<?php

namespace App\Events;

use App\Models\JournalEntry;
use Illuminate\Foundation\Events\Dispatchable;

class JournalEntryPosted
{
    use Dispatchable;

    public function __construct(public JournalEntry $entry)
    {
    }
}
